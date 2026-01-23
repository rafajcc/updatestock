<?php

namespace Module\UpdateStock\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Module\UpdateStock\Service\StockUpdateService;
use Module\UpdateStock\Service\BackupService;
use Module\UpdateStock\Service\LogsService;

class UpdateStockController extends FrameworkBundleAdminController
{
    private $stockUpdateService;
    private $backupService;

    public function __construct(StockUpdateService $stockUpdateService, BackupService $backupService)
    {
        parent::__construct();
        $this->stockUpdateService = $stockUpdateService;
        $this->backupService = $backupService;
    }

    public function indexAction(Request $request)
    {
        $backupAvailable = $this->backupService->hasBackups();
        $uploadDir = _PS_MODULE_DIR_ . '/updatestock/temp_files/';

        // Simple file listing for now (could be moved to service)
        $files = glob($uploadDir . '*.txt');
        $uploadedFiles = [];
        if ($files) {
            foreach ($files as $file) {
                $uploadedFiles[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }

        $reports = [];
        $messages = [];

        if ($request->isMethod('POST')) {
            if ($request->request->has('submitStockUpload')) {
                // Upload handling
                $uploadedFile = $request->files->get('stock_files');
                foreach ($request->files->get('stock_files', []) as $file) {
                    if ($file && $file->getClientOriginalExtension() === 'txt') {
                        $originalName = $file->getClientOriginalName();
                        $targetPath = $uploadDir . $originalName;

                        if (file_exists($targetPath)) {
                            // File exists, append timestamp
                            $info = pathinfo($originalName);
                            $name = $info['filename'];
                            $ext = $info['extension'];
                            $newName = $name . '_' . date('Ymd_His') . '.' . $ext;
                            $file->move($uploadDir, $newName);
                            LogsService::log('File uploaded (renamed from ' . $originalName . '): ' . $newName);
                        } else {
                            $file->move($uploadDir, $originalName);
                            LogsService::log('File uploaded: ' . $originalName);
                        }
                    }
                }
                $this->addFlash('success', 'Files uploaded successfully.');
                return $this->redirectToRoute('admin_updatestock_index');
            }

            if ($request->request->has('submitPreview')) {
                $selectedFiles = $request->request->get('selected_files');
                $scope = $request->request->get('inventory_scope', 'single');
                $totalInventory = (bool) $request->request->get('total_inventory');

                try {
                    $changes = $this->stockUpdateService->getInventoryChanges(
                        $selectedFiles,
                        $scope,
                        (int) $this->getContext()->shop->id,
                        $totalInventory
                    );

                    // Generate Preview Report
                    $previewReportFile = 'preview_' . date('Ymd_His') . '.csv';
                    $uploadDir = _PS_MODULE_DIR_ . 'updatestock/uploads/reports/';
                    if (!is_dir($uploadDir))
                        mkdir($uploadDir, 0755, true);

                    $fp = fopen($uploadDir . $previewReportFile, 'w');
                    fputcsv($fp, ['Type', 'EAN', 'ID Product', 'ID Attr', 'Name', 'Current Qty', 'New Qty']);

                    $stats = [
                        'updated' => count($changes['updated']),
                        'zeroed' => count($changes['zeroed']),
                        'unknown' => count($changes['unknown']),
                        'disabled' => count($changes['disabled'])
                    ];

                    foreach ($changes['updated'] as $item) {
                        fputcsv($fp, ['UPDATE', $item['ean'], $item['id_product'], $item['id_product_attribute'], $item['name'], $item['old_qty'], $item['new_qty']]);
                    }
                    foreach ($changes['zeroed'] as $item) {
                        fputcsv($fp, ['ZERO', '', $item['id_product'], $item['id_product_attribute'], $item['name'], $item['old_qty'], 0]);
                    }
                    foreach ($changes['unknown'] as $ean) {
                        fputcsv($fp, ['UNKNOWN', $ean, '', '', 'N/A', '-', '-']);
                    }
                    fclose($fp);

                    // Pass state back to view
                    return $this->render('@Modules/updatestock/templates/admin/inventory/index.html.twig', [
                        'uploaded_files' => $uploadedFiles,
                        'backup_available' => $backupAvailable,
                        'preview_mode' => true,
                        'preview_stats' => $stats,
                        'preview_report' => $previewReportFile,
                        // Preserved Params
                        'selected_files' => $selectedFiles,
                        'inventory_scope' => $scope,
                        'total_inventory' => $totalInventory,
                        'module_dir' => _MODULE_DIR_ . 'updatestock/'
                    ]);

                } catch (\Exception $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }

            if ($request->request->has('submitRunInventory')) {
                $selectedFiles = $request->request->get('confirmed_files');
                $scope = $request->request->get('inventory_scope', 'single');
                $totalInventory = (bool) $request->request->get('total_inventory');

                try {
                    $result = $this->stockUpdateService->processInventory(
                        $selectedFiles,
                        $scope,
                        (int) $this->getContext()->shop->id,
                        $totalInventory
                    );
                    $reports = $result['reports'];
                    if ($result['consistency']['critical_errors']) {
                        $this->addFlash('error', 'Critical consistency errors detected!');
                    } else {
                        $this->addFlash('success', 'Inventory Updated Successfully');
                    }
                } catch (\Exception $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }

            if ($request->request->has('submitRestoreBackup')) {
                $backupFile = $request->request->get('backup_filename');
                if ($backupFile) {
                    if ($this->backupService->restoreBackup($backupFile)) {
                        LogsService::log('Backup restored successfully: ' . $backupFile);
                        $this->addFlash('success', 'Backup ' . $backupFile . ' restored successfully');
                    } else {
                        LogsService::log('Failed to restore backup: ' . $backupFile, 'ERROR');
                        $this->addFlash('error', 'Failed to restore backup');
                    }
                }
            }

            if ($request->request->has('submitDeleteBackup')) {
                $backupFile = $request->request->get('backup_filename');
                if ($backupFile) {
                    if ($this->backupService->deleteBackup($backupFile)) {
                        LogsService::log('Backup deleted: ' . $backupFile);
                        $this->addFlash('success', 'Backup deleted');
                    }
                }
            }

            if ($request->request->has('submitApplyFixes')) {
                try {
                    $fixCount = $this->stockUpdateService->applyConsistencyFixes((int) $this->getContext()->shop->id);
                    $this->addFlash('success', "Consistency fixes applied successfully. ($fixCount fixes)");
                    // Refresh report logic could be here, but redirect is simpler
                    return $this->redirectToRoute('admin_updatestock_index');
                } catch (\Exception $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }

            if ($request->request->has('submitDeleteFiles')) {
                $filesToDelete = $request->request->get('selected_files');
                if ($filesToDelete) {
                    foreach ($filesToDelete as $f) {
                        if (file_exists($uploadDir . basename($f))) {
                            unlink($uploadDir . basename($f));
                            LogsService::log('File deleted: ' . basename($f));
                        }
                    }
                    $this->addFlash('success', 'Files deleted');
                    return $this->redirectToRoute('admin_updatestock_index');
                }
            }
        }

        return $this->render('@Modules/updatestock/templates/admin/inventory/index.html.twig', [
            'uploaded_files' => $uploadedFiles,
            'backup_available' => $backupAvailable,
            'available_backups' => $this->backupService->getAvailableBackups(),
            'reports_generated' => $reports,
            'module_dir' => _MODULE_DIR_ . 'updatestock/'
        ]);
    }
}
