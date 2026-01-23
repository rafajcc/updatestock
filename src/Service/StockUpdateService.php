<?php

namespace Module\UpdateStock\Service;

use Module\UpdateStock\Repository\StockRepository;
use Module\UpdateStock\Service\LogsService;
use Product;

class StockUpdateService
{
    private $stockRepository;
    private $backupService;
    private $consistencyService;
    private $moduleDir;

    public function __construct(
        StockRepository $stockRepository,
        BackupService $backupService,
        ConsistencyService $consistencyService,
        $moduleDir
    ) {
        $this->stockRepository = $stockRepository;
        $this->backupService = $backupService;
        $this->consistencyService = $consistencyService;
        $this->moduleDir = $moduleDir;
    }



    protected function parseInventoryFiles($files)
    {
        $uploadDir = $this->moduleDir . 'temp_files/';
        $totals = [];
        LogsService::log('Parsing files from: ' . $uploadDir);

        foreach ($files as $filename) {
            $path = $uploadDir . basename($filename);
            if (!file_exists($path)) {
                LogsService::log('File to parse not found: ' . $path, 'WARN');
                continue;
            }

            $handle = fopen($path, 'r');
            if ($handle) {
                $count = 0;
                $valid = 0;
                while (($line = fgets($handle)) !== false) {
                    $count++;
                    $line = trim($line);
                    if (empty($line))
                        continue;

                    $parts = explode(';', $line);
                    $ean = trim($parts[0]);

                    // Debug first few errors
                    if (empty($ean) || !is_numeric($ean)) {
                        if ($count <= 5) {
                            LogsService::log("Invalid line #$count in $filename: '$line' (EAN parsed as '$ean')", 'WARN');
                        }
                        continue;
                    }

                    if (!isset($totals[$ean]))
                        $totals[$ean] = 0;
                    $totals[$ean]++;
                    $valid++;
                }
                fclose($handle);
                LogsService::log("Parsed $filename: $count lines, $valid valid EANs.");
            } else {
                LogsService::log("Could not open file: $path", 'ERROR');
            }
        }
        return $totals;
    }

    public function getInventoryChanges($files, $scope, $shopId, $totalInventory)
    {
        $inventoryData = $this->parseInventoryFiles($files);
        if (empty($inventoryData)) {
            throw new \Exception('No valid data found in selected files.');
        }

        $report = ['updated' => [], 'unknown' => [], 'zeroed' => [], 'disabled' => []];
        $processedProducts = [];

        // 1. Calculate Updates
        foreach ($inventoryData as $ean => $newQty) {
            $result = $this->stockRepository->getProductByEan($ean);

            if (!$result) {
                $report['unknown'][] = $ean;
                continue;
            }

            $id_product = (int) $result['id_product'];
            $id_product_attribute = (int) $result['id_product_attribute'];
            $processedProducts[$id_product] = true;

            $currentStock = $this->stockRepository->getCurrentStock($id_product, $id_product_attribute, ($scope === 'single' ? $shopId : null));
            $oldQty = $currentStock ? (int) $currentStock['quantity'] : 0;
            $reserved = $currentStock ? (int) $currentStock['reserved_quantity'] : 0;
            $finalQty = $newQty - $reserved;

            // Only report if changed? Or report all? Usually report all found in file is good feedback.
            // But for preview, showing ONLY changes is better?
            // User request: "summary of changes to be applied".

            $productName = Product::getProductName($id_product, $id_product_attribute);
            $report['updated'][] = [
                'ean' => $ean,
                'name' => $productName,
                'old_qty' => $oldQty,
                'new_prev_qty' => $newQty, // physical
                'new_qty' => $finalQty, // quantity
                'id_product' => $id_product,
                'id_product_attribute' => $id_product_attribute,
                'reserved' => $reserved
            ];
        }

        // 2. Calculate Zeroes
        if ($totalInventory) {
            $ids = implode(',', array_keys($processedProducts));
            $rows = $this->stockRepository->getProductsNotIn($ids, ($scope === 'single' ? $shopId : null));

            if ($rows) {
                foreach ($rows as $row) {
                    $id_product = (int) $row['id_product'];
                    $id_pa = (int) $row['id_product_attribute'];
                    $newQty = 0 - (int) $row['reserved_quantity'];

                    if ($row['quantity'] != $newQty || $row['physical_quantity'] != 0) {
                        $productName = Product::getProductName($id_product, $id_pa);
                        $item = ['id_product' => $id_product, 'id_product_attribute' => $id_pa, 'name' => $productName, 'old_qty' => $row['quantity'], 'new_qty' => $newQty, 'reserved' => $row['reserved_quantity']];
                        $report['zeroed'][] = $item;

                        if ($id_pa == 0 && $newQty <= 0) {
                            $report['disabled'][] = ['id_product' => $id_product, 'name' => $productName];
                        }
                    }
                }
            }
        }

        return $report;
    }

    public function processInventory($files, $scope, $shopId, $totalInventory)
    {
        // 1. Create Backup
        if (!$this->backupService->createBackup()) {
            throw new \Exception('Failed to create backup. Inventory execution aborted.');
        }

        // 2. Get Changes (Re-calculate to be safe)
        $changes = $this->getInventoryChanges($files, $scope, $shopId, $totalInventory);

        // 3. Apply Changes
        $this->applyChanges($changes, $scope, $shopId);
        LogsService::log(sprintf(
            'Stock update finished. Updates: %d, Zeroed: %d, Disabled: %d, Unknown: %d',
            count($changes['updated']),
            count($changes['zeroed']),
            count($changes['disabled']),
            count($changes['unknown'])
        ));

        // 4. Consistency Checks (Dry Run)
        $consistencyResults = $this->consistencyService->runTests($shopId, false);

        // 5. Generate Reports
        $reports = $this->generateReports($changes, $consistencyResults);

        return [
            'reports' => $reports,
            'consistency' => $consistencyResults
        ];
    }

    public function applyConsistencyFixes($shopId)
    {
        $results = $this->consistencyService->runTests($shopId, true);
        return count($results['fixes']);
    }

    protected function applyChanges($changes, $scope, $shopId)
    {
        // Apply Updates
        foreach ($changes['updated'] as $item) {
            if (isset($item['new_prev_qty'])) { // It's an update
                if ($scope === 'single') {
                    $this->stockRepository->updateStock($item['id_product'], $item['id_product_attribute'], $shopId, $item['new_qty'], $item['new_prev_qty'], $item['reserved']);
                } else {
                    $this->stockRepository->updateStockGlobal($item['id_product'], $item['id_product_attribute'], $item['new_qty'], $item['new_prev_qty']);
                }
            }
        }

        // Sync attributes zero
        $processedProducts = [];
        foreach ($changes['updated'] as $item)
            $processedProducts[$item['id_product']] = true;
        foreach (array_keys($processedProducts) as $id_p) {
            $this->updateProductAttributeZero($id_p, $scope, $shopId);
        }

        // Apply Zeroes
        foreach ($changes['zeroed'] as $item) {
            if ($scope === 'single') {
                $this->stockRepository->updateStock($item['id_product'], $item['id_product_attribute'], $shopId, $item['new_qty'], 0, $item['reserved']);
            } else {
                $this->stockRepository->updateStockGlobal($item['id_product'], $item['id_product_attribute'], $item['new_qty'], 0);
            }
        }

        // Apply Disabled
        foreach ($changes['disabled'] as $item) {
            $this->stockRepository->disableProduct($item['id_product'], ($scope === 'single' ? $shopId : null));
        }
    }

    protected function updateProductAttributeZero($id_product, $scope, $shopId)
    {
        $sums = $this->stockRepository->getProductAttributeSum($id_product, ($scope === 'single' ? $shopId : null));
        if ($sums && $sums['pq'] !== null) {
            $this->stockRepository->updateProductAttributeZero($id_product, $sums['q'], $sums['pq'], $sums['rq'], ($scope === 'single' ? $shopId : null));
        }
    }

    protected function generateReports($data, $consistencyResults = [])
    {
        $outputDir = $this->moduleDir . 'uploads/reports/';
        if (!is_dir($outputDir))
            mkdir($outputDir, 0755, true);
        $timestamp = date('Ymd_His');

        $reports = [];

        $fp = fopen($outputDir . 'inventory_log_' . $timestamp . '.csv', 'w');
        fputcsv($fp, ['EAN', 'Product Name', 'Quantity Before', 'Quantity After']);
        foreach ($data['updated'] as $row) {
            fputcsv($fp, [
                $row['ean'],
                $row['name'],
                $row['old_qty'],
                $row['new_prev_qty'] // Physical Quantity
            ]);
        }
        fclose($fp);
        $reports['log'] = 'inventory_log_' . $timestamp . '.csv';

        $fp = fopen($outputDir . 'zeroed_disabled_' . $timestamp . '.csv', 'w');
        fputcsv($fp, ['ID Product', 'Product Name', 'Status']);
        foreach ($data['zeroed'] as $row)
            fputcsv($fp, [$row['id_product'], $row['name'], 'Set to 0']);
        foreach ($data['disabled'] as $row)
            fputcsv($fp, [$row['id_product'], $row['name'], 'Disabled']);
        fclose($fp);
        $reports['zeroed'] = 'zeroed_disabled_' . $timestamp . '.csv';

        $fp = fopen($outputDir . 'unknown_eans_' . $timestamp . '.csv', 'w');
        fputcsv($fp, ['EAN']);
        foreach ($data['unknown'] as $ean)
            fputcsv($fp, [$ean]);
        fclose($fp);
        $reports['unknown'] = 'unknown_eans_' . $timestamp . '.csv';

        if (!empty($consistencyResults['inconsistencies'])) {
            $fp = fopen($outputDir . 'inconsistencies_' . $timestamp . '.csv', 'w');
            fputcsv($fp, ['Type', 'ID Product', 'ID Attr', 'Before', 'Corrected']);
            foreach ($consistencyResults['inconsistencies'] as $row)
                fputcsv($fp, [$row['type'], $row['id_product'], $row['id_product_attribute'], $row['value_before'], $row['value_corrected']]);
            fclose($fp);
            $reports['inconsistencies'] = 'inconsistencies_' . $timestamp . '.csv';
        }



        LogsService::log('Reports generated: ' . implode(', ', $reports));

        return $reports;
    }
}
