<?php

namespace Module\UpdateStock\Service;

use Db;
use Module\UpdateStock\Service\LogsService;

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

    public function createBackup(array $changes = [], $scope = 'single', $shopId = null)
    {
        if (empty($changes)) {
            LogsService::log('Backup creation aborted: no calculated changes were provided.', 'ERROR');
            return false;
        }

        $payload = [
            'format' => 'updatestock_row_backup',
            'version' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'scope' => $scope,
            'shop_id' => $shopId ? (int) $shopId : null,
            'prefix' => _DB_PREFIX_,
            'tables' => [
                'stock_available' => [],
                'product' => [],
                'product_shop' => [],
            ],
        ];

        $payload['tables']['stock_available'] = $this->captureStockAvailableRows($changes, $scope, $shopId);
        $payload['tables']['product'] = $this->captureProductRows($changes);
        $payload['tables']['product_shop'] = $this->captureProductShopRows($changes, $scope, $shopId);

        $rowCount = 0;
        foreach ($payload['tables'] as $rows) {
            $rowCount += count($rows);
        }

        if ($rowCount === 0) {
            LogsService::log('Backup creation aborted: none of the affected rows could be captured.', 'ERROR');
            return false;
        }

        $content = json_encode($payload, JSON_PRETTY_PRINT);
        if ($content === false) {
            LogsService::log('Backup creation aborted: could not encode backup payload as JSON.', 'ERROR');
            return false;
        }

        $filename = 'backup_' . date('Ymd_His') . '.json';
        $fullPath = $this->getBackupDir() . $filename;

        $write = file_put_contents($fullPath, $content);
        if ($write === false || $write < 50) {
            if (file_exists($fullPath))
                unlink($fullPath);
            LogsService::log('Backup creation failed: could not write backup file ' . $fullPath, 'ERROR');
            return false;
        }

        LogsService::log('Row backup created: ' . $filename . ' (' . $rowCount . ' affected rows captured).');
        return true;
    }

    public function getAvailableBackups()
    {
        $dir = $this->getBackupDir();
        $files = glob($dir . 'backup_*.json') ?: [];
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
            LogsService::log('Restore failed: backup file not found: ' . basename($filename), 'ERROR');
            return false;
        }

        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') {
            LogsService::log('Restore failed: unsupported backup file type: ' . basename($filename), 'ERROR');
            return false;
        }

        $content = file_get_contents($file);
        if (empty($content) || strlen($content) < 50) {
            LogsService::log('Restore failed: backup file is empty or too small: ' . basename($filename), 'ERROR');
            return false;
        }

        $payload = json_decode($content, true);
        if (!is_array($payload) || !isset($payload['format']) || $payload['format'] !== 'updatestock_row_backup') {
            LogsService::log('Restore failed: invalid backup format in file ' . basename($filename), 'ERROR');
            return false;
        }

        try {
            $this->restoreRows($payload);
            LogsService::log('Row backup restored successfully: ' . basename($filename));
            return true;
        } catch (\Exception $e) {
            LogsService::log('Restore failed for ' . basename($filename) . ': ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    private function captureStockAvailableRows(array $changes, $scope, $shopId)
    {
        $rowsToCapture = [];
        foreach (['updated', 'zeroed'] as $section) {
            if (empty($changes[$section])) {
                continue;
            }
            foreach ($changes[$section] as $item) {
                $rowsToCapture[$item['id_product'] . ':' . $item['id_product_attribute']] = [
                    'id_product' => (int) $item['id_product'],
                    'id_product_attribute' => (int) $item['id_product_attribute'],
                ];
                $rowsToCapture[$item['id_product'] . ':0'] = [
                    'id_product' => (int) $item['id_product'],
                    'id_product_attribute' => 0,
                ];
            }
        }

        $rows = [];
        foreach ($rowsToCapture as $item) {
            foreach ($this->getStockRowsForBackup($item['id_product'], $item['id_product_attribute'], $scope, $shopId) as $row) {
                $rows[] = $this->backupRow($row);
            }
        }

        return $rows;
    }

    private function captureProductRows(array $changes)
    {
        $ids = $this->getDisabledProductIds($changes);
        if (empty($ids)) {
            return [];
        }

        $sql = 'SELECT id_product, active FROM `' . _DB_PREFIX_ . 'product`
                WHERE id_product IN (' . implode(',', $ids) . ')';

        return $this->backupRows(Db::getInstance()->executeS($sql));
    }

    private function captureProductShopRows(array $changes, $scope, $shopId)
    {
        $ids = $this->getDisabledProductIds($changes);
        if (empty($ids)) {
            return [];
        }

        $sql = 'SELECT id_product, id_shop, active FROM `' . _DB_PREFIX_ . 'product_shop`
                WHERE id_product IN (' . implode(',', $ids) . ')';
        if ($scope === 'single' && $shopId) {
            $sql .= ' AND id_shop = ' . (int) $shopId;
        }

        return $this->backupRows(Db::getInstance()->executeS($sql));
    }

    private function getStockRowsForBackup($idProduct, $idProductAttribute, $scope, $shopId)
    {
        $sql = 'SELECT id_stock_available, id_product, id_product_attribute, id_shop, id_shop_group,
                       quantity, physical_quantity, reserved_quantity, depends_on_stock, out_of_stock
                FROM `' . _DB_PREFIX_ . 'stock_available`
                WHERE id_product = ' . (int) $idProduct . '
                AND id_product_attribute = ' . (int) $idProductAttribute;
        if ($scope === 'single' && $shopId) {
            $sql .= ' AND id_shop = ' . (int) $shopId;
        }

        $rows = Db::getInstance()->executeS($sql);

        if (empty($rows) && $scope === 'single' && $shopId) {
            return [[
                '_exists' => false,
                'id_product' => (int) $idProduct,
                'id_product_attribute' => (int) $idProductAttribute,
                'id_shop' => (int) $shopId,
                'id_shop_group' => 0,
            ]];
        }

        return $rows ?: [];
    }

    private function getDisabledProductIds(array $changes)
    {
        if (empty($changes['disabled'])) {
            return [];
        }

        $ids = [];
        foreach ($changes['disabled'] as $item) {
            $ids[(int) $item['id_product']] = (int) $item['id_product'];
        }

        return array_values($ids);
    }

    private function backupRows($rows)
    {
        if (empty($rows)) {
            return [];
        }

        return array_map([$this, 'backupRow'], $rows);
    }

    private function backupRow(array $row)
    {
        if (!isset($row['_exists'])) {
            $row['_exists'] = true;
        }

        return $row;
    }

    private function restoreRows(array $payload)
    {
        if (!isset($payload['tables']) || !is_array($payload['tables'])) {
            throw new \Exception('missing tables section');
        }

        $db = Db::getInstance();
        $db->execute('START TRANSACTION');

        try {
            $this->restoreStockAvailableRows($payload['tables']['stock_available'] ?? []);
            $this->restoreProductRows($payload['tables']['product'] ?? []);
            $this->restoreProductShopRows($payload['tables']['product_shop'] ?? []);
            $db->execute('COMMIT');
        } catch (\Exception $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
    }

    private function restoreStockAvailableRows(array $rows)
    {
        foreach ($rows as $row) {
            $where = 'id_product = ' . (int) $row['id_product'] . '
                AND id_product_attribute = ' . (int) $row['id_product_attribute'] . '
                AND id_shop = ' . (int) $row['id_shop'] . '
                AND id_shop_group = ' . (int) $row['id_shop_group'];

            if (isset($row['_exists']) && !$row['_exists']) {
                $this->executeOrFail('DELETE FROM `' . _DB_PREFIX_ . 'stock_available` WHERE ' . $where, 'delete stock_available row that did not exist before backup');
                continue;
            }

            $this->executeOrFail(
                'UPDATE `' . _DB_PREFIX_ . 'stock_available`
                 SET quantity = ' . (int) $row['quantity'] . ',
                     physical_quantity = ' . (int) $row['physical_quantity'] . ',
                     reserved_quantity = ' . (int) $row['reserved_quantity'] . ',
                     depends_on_stock = ' . (int) $row['depends_on_stock'] . ',
                     out_of_stock = ' . (int) $row['out_of_stock'] . '
                 WHERE ' . $where,
                'restore stock_available row'
            );
        }
    }

    private function restoreProductRows(array $rows)
    {
        foreach ($rows as $row) {
            $this->executeOrFail(
                'UPDATE `' . _DB_PREFIX_ . 'product`
                 SET active = ' . (int) $row['active'] . '
                 WHERE id_product = ' . (int) $row['id_product'],
                'restore product active flag'
            );
        }
    }

    private function restoreProductShopRows(array $rows)
    {
        foreach ($rows as $row) {
            $this->executeOrFail(
                'UPDATE `' . _DB_PREFIX_ . 'product_shop`
                 SET active = ' . (int) $row['active'] . '
                 WHERE id_product = ' . (int) $row['id_product'] . '
                 AND id_shop = ' . (int) $row['id_shop'],
                'restore product_shop active flag'
            );
        }
    }

    private function executeOrFail($sql, $context)
    {
        LogsService::log('Restore query (' . $context . '): ' . $sql, 'DEBUG');
        if (!Db::getInstance()->execute($sql)) {
            throw new \Exception($context . ' failed');
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
        $files = glob($this->getBackupDir() . 'backup_*.json') ?: [];
        return !empty($files);
    }
}
