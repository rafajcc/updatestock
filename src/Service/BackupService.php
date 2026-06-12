<?php

namespace Module\UpdateStock\Service;

use Db;
use Module\UpdateStock\Service\LogsService;

class BackupService
{
    private $moduleDir;

    const BACKUP_DIR = 'backups/';

    // Parent tables first so CREATE/INSERT order respects foreign keys during restore.
    const BACKUP_TABLES = ['product', 'product_shop', 'stock', 'stock_available'];

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
        @set_time_limit(0);

        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $fullPath = $this->getBackupDir() . $filename;

        LogsService::log('Backup started: ' . $filename);

        $fp = @fopen($fullPath, 'wb');
        if ($fp === false) {
            LogsService::log('Backup failed: cannot open file for writing: ' . $fullPath, 'ERROR', true);
            return false;
        }

        try {
            $db = Db::getInstance();
            $tablesDumped = 0;
            $totalRows = 0;

            $this->writeBackupLine($fp, '-- UpdateStock backup generated at ' . date('Y-m-d H:i:s'));
            $this->writeBackupLine($fp, 'SET NAMES utf8mb4;');
            $this->writeBackupLine($fp, 'SET FOREIGN_KEY_CHECKS=0;');
            $this->writeBackupLine($fp, '');

            foreach (self::BACKUP_TABLES as $table) {
                $tableName = _DB_PREFIX_ . $table;
                $quotedTable = $this->quoteIdentifier($tableName);

                $check = $db->executeS("SHOW TABLES LIKE '" . pSQL($tableName) . "'");
                if (empty($check)) {
                    LogsService::log("Backup skipped missing table: $tableName", 'WARN');
                    continue;
                }

                $createTable = $db->getRow('SHOW CREATE TABLE ' . $quotedTable);
                $createStatement = $this->getCreateTableStatement($createTable);
                if (!$createStatement) {
                    LogsService::log("Backup could not read CREATE TABLE for: $tableName", 'ERROR');
                    continue;
                }

                $this->writeBackupLine($fp, 'DROP TABLE IF EXISTS ' . $quotedTable . ';');
                $this->writeBackupLine($fp, $createStatement . ';');
                $this->writeBackupLine($fp, '');

                $result = $db->query('SELECT * FROM ' . $quotedTable);
                if ($result === false) {
                    LogsService::log(
                        "Backup query failed for $tableName: " . $db->getMsgError(),
                        'ERROR',
                        true
                    );
                    continue;
                }

                $rowCount = 0;
                while ($row = $db->nextRow($result)) {
                    $row = $this->normalizeRow($row);
                    if (empty($row)) {
                        continue;
                    }

                    $columns = array_keys($row);
                    $values = array_map([$this, 'sqlValue'], array_values($row));
                    $this->writeBackupLine(
                        $fp,
                        'INSERT INTO ' . $quotedTable . ' (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ');'
                    );
                    $rowCount++;
                }

                $this->writeBackupLine($fp, '');
                $tablesDumped++;
                $totalRows += $rowCount;
                LogsService::log("Backup table $tableName: $rowCount rows");
            }

            $this->writeBackupLine($fp, 'SET FOREIGN_KEY_CHECKS=1;');
            fclose($fp);
            $fp = null;

            if ($tablesDumped === 0) {
                LogsService::log('Backup aborted: no tables were dumped', 'ERROR', true);
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                return false;
            }

            $size = filesize($fullPath);
            if ($size === false || $size < 100) {
                LogsService::log('Backup write failed or file too small: ' . $fullPath, 'ERROR', true);
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                return false;
            }

            LogsService::log(sprintf(
                'Backup created: %s (%s, %d tables, %d rows)',
                $filename,
                LogsService::getFileSize($fullPath),
                $tablesDumped,
                $totalRows
            ), 'INFO', true);

            return true;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            LogsService::log('Backup exception: ' . $e->getMessage(), 'ERROR', true);
            return false;
        }
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
        @set_time_limit(0);

        $safeFilename = basename($filename);
        $file = $this->getBackupDir() . $safeFilename;

        LogsService::log('Restore started: ' . $safeFilename);

        if (!file_exists($file)) {
            LogsService::log('Restore failed: backup file not found at ' . $file, 'ERROR', true);
            return false;
        }

        $sql = file_get_contents($file);
        if ($sql === false || strlen($sql) < 50) {
            LogsService::log('Restore failed: backup file is empty or unreadable (' . $safeFilename . ')', 'ERROR', true);
            return false;
        }

        $db = Db::getInstance();
        $timestamp = date('YmdHis');
        $renamedTables = [];

        foreach (self::BACKUP_TABLES as $table) {
            $tableName = _DB_PREFIX_ . $table;
            $exists = $db->executeS("SHOW TABLES LIKE '" . pSQL($tableName) . "'");
            if (empty($exists)) {
                LogsService::log("Restore rename skipped missing live table: $tableName", 'WARN');
                continue;
            }

            $backupName = $tableName . '_bak_' . $timestamp;
            $renameSql = 'RENAME TABLE ' . $this->quoteIdentifier($tableName) . ' TO ' . $this->quoteIdentifier($backupName);
            if ($db->execute($renameSql)) {
                $renamedTables[$tableName] = $backupName;
                LogsService::log("Restore safety rename: $tableName -> $backupName");
            } else {
                LogsService::log(
                    "Restore failed during safety rename of $tableName: " . $db->getMsgError(),
                    'ERROR',
                    true
                );
                $this->rollbackRestore($renamedTables);
                return false;
            }
        }

        if (empty($renamedTables)) {
            LogsService::log('Restore failed: no live tables were renamed for rollback safety', 'ERROR', true);
            return false;
        }

        $queries = $this->splitSqlStatements($sql);
        LogsService::log('Restore executing ' . count($queries) . ' SQL statements from ' . $safeFilename);

        $success = true;

        if (!$db->execute('SET FOREIGN_KEY_CHECKS=0')) {
            LogsService::log('Restore failed enabling FOREIGN_KEY_CHECKS=0: ' . $db->getMsgError(), 'ERROR', true);
            $this->rollbackRestore($renamedTables);
            return false;
        }

        foreach ($queries as $index => $query) {
            if (!$db->execute($query)) {
                $success = false;
                LogsService::log(
                    'Restore SQL failed at statement #' . ($index + 1) . ': ' . $db->getMsgError() . ' | ' . $this->truncateForLog($query),
                    'ERROR',
                    true
                );
                break;
            }
        }

        $db->execute('SET FOREIGN_KEY_CHECKS=1');

        if ($success) {
            foreach ($renamedTables as $backup) {
                $db->execute('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($backup));
            }
            LogsService::log('Restore completed successfully: ' . $safeFilename, 'INFO', true);
            return true;
        }

        LogsService::log('Restore rolling back after SQL failure in ' . $safeFilename, 'WARN', true);

        foreach (self::BACKUP_TABLES as $table) {
            $tableName = _DB_PREFIX_ . $table;
            $db->execute('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName));
        }

        if (!$this->rollbackRestore($renamedTables)) {
            LogsService::log(
                'Restore rollback failed after SQL error. Manual intervention may be required for tables: ' . implode(', ', array_values($renamedTables)),
                'CRITICAL',
                true
            );
            return false;
        }

        LogsService::log('Restore rollback completed for ' . $safeFilename, 'WARN', true);
        return false;
    }

    private function rollbackRestore(array $renamedTables)
    {
        $db = Db::getInstance();
        $db->execute('SET FOREIGN_KEY_CHECKS=0');
        $ok = true;

        foreach ($renamedTables as $original => $backup) {
            $renameSql = 'RENAME TABLE ' . $this->quoteIdentifier($backup) . ' TO ' . $this->quoteIdentifier($original);
            if (!$db->execute($renameSql)) {
                LogsService::log(
                    "Restore rollback failed renaming $backup back to $original: " . $db->getMsgError(),
                    'CRITICAL',
                    true
                );
                $ok = false;
            }
        }

        $db->execute('SET FOREIGN_KEY_CHECKS=1');
        return $ok;
    }

    public function deleteBackup($filename)
    {
        $file = $this->getBackupDir() . basename($filename);

        if (file_exists($file)) {
            $deleted = unlink($file);
            if ($deleted) {
                LogsService::log('Backup deleted: ' . basename($filename));
            }
            return $deleted;
        }

        LogsService::log('Backup delete failed: file not found ' . basename($filename), 'WARN');
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

    private function writeBackupLine($fp, $line)
    {
        if (fwrite($fp, $line . "\n") === false) {
            throw new \RuntimeException('Failed writing backup file');
        }
    }

    private function quoteIdentifier($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function getCreateTableStatement($createTableRow)
    {
        if (!is_array($createTableRow)) {
            return null;
        }

        foreach ($createTableRow as $key => $value) {
            if (stripos((string) $key, 'create') !== false && stripos((string) $key, 'table') !== false) {
                return rtrim((string) $value, " \t\n\r\0\x0B;");
            }
        }

        return null;
    }

    private function normalizeRow(array $row)
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function sqlValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . pSQL($value, true) . "'";
    }

    private function splitSqlStatements($sql)
    {
        $statements = [];
        $current = '';

        foreach (preg_split('/\R/', $sql) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                continue;
            }

            $current .= $line . "\n";
            if (preg_match('/;\s*$/', $trimmed)) {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
            }
        }

        $remaining = trim($current);
        if ($remaining !== '') {
            $statements[] = $remaining;
        }

        return $statements;
    }

    private function truncateForLog($query, $maxLength = 300)
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        if (strlen($query) <= $maxLength) {
            return $query;
        }

        return substr($query, 0, $maxLength) . '...';
    }
}
