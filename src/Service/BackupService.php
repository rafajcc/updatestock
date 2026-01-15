<?php

namespace Antigravity\UpdateStock\Service;

use Db;
use Context;

class BackupService
{
    private $moduleDir;
    const BACKUP_DIR = 'backups/';

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
        $tables = ['stock_available', 'stock', 'product', 'product_shop'];
        $content = '';
        $db = Db::getInstance();

        foreach ($tables as $table) {
            $tableName = _DB_PREFIX_ . $table;
            $content .= "DROP TABLE IF EXISTS `$tableName`;\n";
            $createTable = $db->getRow("SHOW CREATE TABLE `$tableName`");
            if ($createTable && isset($createTable['Create Table'])) {
                $content .= $createTable['Create Table'] . ";\n\n";
            }
            // Use unbuffered query logic (simplified here)
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

        $filename = 'backup_' . date('Ymd_His') . '.sql';
        return file_put_contents($this->getBackupDir() . $filename, $content) !== false;
    }

    public function restoreLatestBackup()
    {
        $dir = $this->getBackupDir();
        $files = glob($dir . 'backup_*.sql');

        if (!$files)
            return false;

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $latestBackup = $files[0];
        if (!file_exists($latestBackup))
            return false;

        $sql = file_get_contents($latestBackup);
        if (empty($sql))
            return false;

        $db = Db::getInstance();
        $queries = preg_split('/;\s*[\r\n]+/', $sql);

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!$db->execute($query))
                    return false;
            }
        }
        return true;
    }

    public function hasBackups()
    {
        $files = glob($this->getBackupDir() . 'backup_*.sql');
        return !empty($files);
    }
}
