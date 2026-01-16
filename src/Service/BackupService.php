<?php

namespace Antigravity\UpdateStock\Service;

use Db;
use Context;
use Antigravity\UpdateStock\Service\LogsService;

class BackupService
{
    private $moduleDir;
    const BACKUP_DIR = 'backups/';
    const BACKUP_TABLES = ['stock_available', 'stock', 'product', 'product_shop'];

    public function __construct($moduleDir)
    {
        $this->moduleDir = $moduleDir;
    }

    public function getBackupDir()
    {
        $dir = $this->moduleDir . self::BACKUP_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . 'index.php', '');
        }
        return $dir;
    }

    public function createBackup()
    {
        $tables = self::BACKUP_TABLES;
        $content = '';
        $db = Db::getInstance();

        // Validate DB connection and tables exist
        foreach ($tables as $table) {
            $tableName = _DB_PREFIX_ . $table;
            // Basic sanity check: does table exist?
            $check = $db->executeS("SHOW TABLES LIKE '$tableName'");
            if (empty($check)) {
                // Warning: Table might not exist (e.g. ps_stock in newer PS versions without ASM). 
                // We shouldn't fail, just skip or log.
                continue;
            }

            $content .= "DROP TABLE IF EXISTS `$tableName`;\n";
            $createTable = $db->getRow("SHOW CREATE TABLE `$tableName`");
            if ($createTable && isset($createTable['Create Table'])) {
                $content .= $createTable['Create Table'] . ";\n\n";
            }

            // Dump Data
            $rows = $db->query("SELECT * FROM `$tableName`");
            while ($row = $db->nextRow($rows)) {
                $values = array_map(function ($value) {
                    if ($value === null)
                        return 'NULL';
                    return "'" . pSQL($value, true) . "'";
                }, $row);
                $content .= "INSERT INTO `$tableName` VALUES (" . implode(', ', $values) . ");\n";
            }
            $content .= "\n";
        }

        if (empty($content)) {
            return false;
        }

        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $fullPath = $this->getBackupDir() . $filename;

        $write = file_put_contents($fullPath, $content);
        if ($write === false || $write < 100) {
            // < 100 bytes is suspicious for a backup of these tables
            if (file_exists($fullPath))
                unlink($fullPath);
            return false;
        }

        return true;
    }

    public function getAvailableBackups()
    {
        $dir = $this->getBackupDir();
        $files = glob($dir . 'backup_*.sql');
        $backups = [];

        if ($files) {
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            foreach ($files as $file) {
                $backups[] = [
                    'filename' => basename($file),
                    'timestamp' => filemtime($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                    'size' => LogsService::getFileSize($file)
                ];
            }
        }
        return $backups;
    }

    public function restoreBackup($filename)
    {
        $dir = $this->getBackupDir();
        $file = $dir . basename($filename);

        if (!file_exists($file)) {
            return false;
        }

        $sql = file_get_contents($file);
        if (empty($sql) || strlen($sql) < 50)
            return false;

        $db = Db::getInstance();
        $timestamp = date('YmdHis');
        $renamedTables = [];

        // 1. Safe Rename: Move current tables to safe backup names
        foreach (self::BACKUP_TABLES as $table) {
            $tableName = _DB_PREFIX_ . $table;
            $backupName = $tableName . '_bak_' . $timestamp;

            // Check if table exists before renaming
            $exists = $db->executeS("SHOW TABLES LIKE '$tableName'");
            if (!empty($exists)) {
                if ($db->execute("RENAME TABLE `$tableName` TO `$backupName`")) {
                    $renamedTables[$tableName] = $backupName;
                } else {
                    // Fail immediately if we can't safe-keep current data
                    $this->rollbackRestore($renamedTables);
                    return false;
                }
            }
        }

        // 2. Execute Restore
        $queries = preg_split('/;\s*[\r\n]+/', $sql);
        $success = true;

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!$db->execute($query)) {
                    $success = false;
                    break;
                }
            }
        }

        // 3. Verify & Finalize
        if ($success) {
            // Drop the safe backups we made
            foreach ($renamedTables as $original => $backup) {
                $db->execute("DROP TABLE IF EXISTS `$backup`");
            }
            return true;
        } else {
            // Restore failure: Rollback!
            // Drop any partial tables created by the failed dump execution
            foreach (self::BACKUP_TABLES as $table) {
                $tableName = _DB_PREFIX_ . $table;
                $db->execute("DROP TABLE IF EXISTS `$tableName`");
            }
            // Restore from renamed
            $this->rollbackRestore($renamedTables);
            return false;
        }
    }

    private function rollbackRestore($renamedTables)
    {
        $db = Db::getInstance();
        foreach ($renamedTables as $original => $backup) {
            $db->execute("RENAME TABLE `$backup` TO `$original`");
        }
    }

    public function deleteBackup($filename)
    {
        $dir = $this->getBackupDir();
        $file = $dir . basename($filename);

        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    /**
     * @deprecated Use restoreBackup($filename) instead
     */
    public function restoreLatestBackup()
    {
        $backups = $this->getAvailableBackups();
        if (empty($backups)) {
            return false;
        }
        return $this->restoreBackup($backups[0]['filename']);
    }

    public function hasBackups()
    {
        $files = glob($this->getBackupDir() . 'backup_*.sql');
        return !empty($files);
    }
}
