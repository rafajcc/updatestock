<?php

namespace Module\UpdateStock\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Module\UpdateStock\Service\StockUpdateService;
use Module\UpdateStock\Service\BackupService;
use Module\UpdateStock\Service\LogsService;
use Module\UpdateStock\Service\TranslationService;

class UpdateStockController extends FrameworkBundleAdminController
{
    private $stockUpdateService;
    private $backupService;
    private $translationService;

    public function __construct(StockUpdateService $stockUpdateService, BackupService $backupService, TranslationService $translationService)
    {
        parent::__construct();
        $this->stockUpdateService = $stockUpdateService;
        $this->backupService = $backupService;
        $this->translationService = $translationService;
    }

    public function indexAction(Request $request)
    {
        $backupAvailable = $this->backupService->hasBackups();
        $module = \Module::getInstanceByName('updatestock');
        $moduleVersion = $module ? $module->version : '';
        $uploadDir = _PS_MODULE_DIR_ . '/updatestock/temp_files/';
        $reportsDir = _PS_MODULE_DIR_ . 'updatestock/uploads/reports/';

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
            if ($request->request->has('submitCancel')) {
                return $this->redirectToRoute('admin_updatestock_index');
            }

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
                $this->addFlash('success', $this->translationService->translate('Files uploaded successfully.'));
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
                    fputcsv($fp, ['Type', 'EAN', 'ID Product', 'ID Attr', 'Name', 'Current Qty', 'New Qty', 'Times Scanned']);

                    $stats = [
                        'updated' => count($changes['updated']),
                        'zeroed' => count($changes['zeroed']),
                        'unknown' => count($changes['unknown']),
                        'disabled' => count($changes['disabled'])
                    ];

                    foreach ($changes['updated'] as $item) {
                        fputcsv($fp, ['UPDATE', $item['ean'], $item['id_product'], $item['id_product_attribute'], $item['name'], $item['old_qty'], $item['new_qty'], $item['new_prev_qty']]);
                    }
                    foreach ($changes['zeroed'] as $item) {
                        fputcsv($fp, ['ZERO', $item['ean'], $item['id_product'], $item['id_product_attribute'], $item['name'], $item['old_qty'], 0, 0]);
                    }
                    foreach ($changes['disabled'] as $item) {
                        fputcsv($fp, ['DISABLE', $item['ean'], $item['id_product'], 0, $item['name'], $item['old_qty'], $item['new_qty'], '']);
                    }
                    foreach ($changes['unknown'] as $item) {
                        fputcsv($fp, ['UNKNOWN', $item['ean'], '', '', 'N/A', '-', '-', $item['count']]);
                    }
                    fclose($fp);

                    // Pass state back to view
                    return $this->render('@Modules/updatestock/templates/admin/inventory/index.html.twig', [
                        'uploaded_files' => $uploadedFiles,
                        'backup_available' => $backupAvailable,
                        'available_backups' => $this->backupService->getAvailableBackups(),
                        'available_reports' => $this->getAvailableReports($reportsDir),
                        'preview_mode' => true,
                        'preview_stats' => $stats,
                        'preview_report' => $previewReportFile,
                        // Preserved Params
                        'selected_files' => $selectedFiles,
                        'inventory_scope' => $scope,
                        'total_inventory' => $totalInventory,
                        'module_dir' => _MODULE_DIR_ . 'updatestock/',
                        'module_version' => $moduleVersion,
                        't' => $this->translationService,
                    ]);

                } catch (\Exception $e) {
                    LogsService::log('Preview failed: ' . $e->getMessage(), 'ERROR');
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
                        $this->addFlash('error', $this->translationService->translate('Critical consistency errors detected!'));
                    } else {
                        $this->addFlash('success', $this->translationService->translate('Inventory Updated Successfully'));
                    }
                } catch (\Exception $e) {
                    LogsService::log('Inventory execution failed: ' . $e->getMessage(), 'ERROR');
                    $this->addFlash('error', $e->getMessage());
                }
            }

            if ($request->request->has('submitRestoreBackup')) {
                $backupFile = $request->request->get('backup_filename');
                if ($backupFile) {
                    if ($this->backupService->restoreBackup($backupFile)) {
                        LogsService::log('Backup restored successfully: ' . $backupFile);
                        $this->addFlash('success', $this->translationService->translate('Backup %backup% restored successfully', ['backup' => $backupFile]));
                    } else {
                        LogsService::log('Failed to restore backup: ' . $backupFile, 'ERROR');
                        $this->addFlash('error', $this->translationService->translate('Failed to restore backup'));
                    }
                }
            }

            if ($request->request->has('submitDeleteBackup')) {
                $backupFile = $request->request->get('backup_filename');
                if ($backupFile) {
                    if ($this->backupService->deleteBackup($backupFile)) {
                        LogsService::log('Backup deleted: ' . $backupFile);
                        $this->addFlash('success', $this->translationService->translate('Backup deleted'));
                    }
                }
            }

            if ($request->request->has('submitDeleteSelectedBackups')) {
                $backupFiles = $request->request->get('selected_backups', []);
                if (!empty($backupFiles)) {
                    $count = 0;
                    foreach ($backupFiles as $bf) {
                        if ($this->backupService->deleteBackup($bf)) {
                            $count++;
                        }
                    }
                    LogsService::log("$count backups deleted (bulk)");
                    $this->addFlash('success', $this->translationService->translate('%count% backup(s) deleted', ['count' => $count]));
                    return $this->redirectToRoute('admin_updatestock_index');
                }
            }

            if ($request->request->has('submitDeleteReport')) {
                $reportFile = $request->request->get('report_filename');
                if ($reportFile) {
                    if ($this->deleteReport($reportsDir, $reportFile)) {
                        LogsService::log('Report deleted: ' . basename($reportFile));
                        $this->addFlash('success', $this->translationService->translate('Report deleted'));
                    } else {
                        LogsService::log('Failed to delete report: ' . basename($reportFile), 'ERROR');
                        $this->addFlash('error', $this->translationService->translate('Failed to delete report'));
                    }
                }
            }

            if ($request->request->has('submitDeleteSelectedReports')) {
                $reportFiles = $request->request->get('selected_reports', []);
                if (!empty($reportFiles)) {
                    $count = 0;
                    foreach ($reportFiles as $rf) {
                        if ($this->deleteReport($reportsDir, $rf)) {
                            $count++;
                        }
                    }
                    LogsService::log("$count reports deleted (bulk)");
                    $this->addFlash('success', $this->translationService->translate('%count% report(s) deleted', ['count' => $count]));
                    return $this->redirectToRoute('admin_updatestock_index');
                }
            }

            if ($request->request->has('submitApplyFixes')) {
                try {
                    $fixCount = $this->stockUpdateService->applyConsistencyFixes((int) $this->getContext()->shop->id);
                    $this->addFlash('success', $this->translationService->translate('Consistency fixes applied successfully. (%count% fixes)', ['count' => $fixCount]));
                    // Refresh report logic could be here, but redirect is simpler
                    return $this->redirectToRoute('admin_updatestock_index');
                } catch (\Exception $e) {
                    LogsService::log('Consistency fixes failed: ' . $e->getMessage(), 'ERROR');
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
                    $this->addFlash('success', $this->translationService->translate('Files deleted'));
                    return $this->redirectToRoute('admin_updatestock_index');
                }
            }
        }

        return $this->render('@Modules/updatestock/templates/admin/inventory/index.html.twig', [
            'uploaded_files' => $uploadedFiles,
            'backup_available' => $backupAvailable,
            'available_backups' => $this->backupService->getAvailableBackups(),
            'available_reports' => $this->getAvailableReports($reportsDir),
            'reports_generated' => $reports,
            'module_dir' => _MODULE_DIR_ . 'updatestock/',
            'module_version' => $moduleVersion,
            't' => $this->translationService,
        ]);
    }

    private function getAvailableReports($reportsDir)
    {
        $files = glob($reportsDir . '*.csv') ?: [];
        $reports = [];

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        foreach ($files as $file) {
            $reports[] = [
                'filename' => basename($file),
                'type' => $this->getReportType(basename($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'timestamp' => filemtime($file),
                'size' => LogsService::getFileSize($file),
            ];
        }

        return $reports;
    }

    private function getReportType($filename)
    {
        if (strpos($filename, 'preview_') === 0) {
            return 'Preview';
        }
        if (strpos($filename, 'inventory_log_') === 0) {
            return 'Inventory Log';
        }
        if (strpos($filename, 'zeroed_disabled_') === 0) {
            return 'Zeroed/Disabled';
        }
        if (strpos($filename, 'unknown_eans_') === 0) {
            return 'Unknown EANs';
        }
        if (strpos($filename, 'inconsistencies_') === 0) {
            return 'Inconsistencies';
        }

        return 'Report';
    }

    private function deleteReport($reportsDir, $filename)
    {
        $filename = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.csv$/', $filename)) {
            return false;
        }

        $file = $reportsDir . $filename;
        if (!file_exists($file)) {
            return false;
        }

        return unlink($file);
    }

    public function viewReportAction($filename)
    {
        $filename = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.csv$/', $filename)) {
            return new Response($this->translationService->translate('Invalid filename'), 400);
        }

        $path = _PS_MODULE_DIR_ . 'updatestock/uploads/reports/' . $filename;
        if (!file_exists($path)) {
            return new Response($this->translationService->translate('File not found'), 404);
        }

        $content = file_get_contents($path);

        $response = new Response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);

        return $response;
    }

    public function deleteSingleUploadedFileAction($filename)
    {
        $uploadDir = _PS_MODULE_DIR_ . '/updatestock/temp_files/';
        $file = $uploadDir . basename($filename);
        if (file_exists($file)) {
            unlink($file);
            LogsService::log('File deleted: ' . basename($filename));
            $this->addFlash('success', $this->translationService->translate('File deleted'));
        }
        return $this->redirectToRoute('admin_updatestock_index');
    }

    public function deleteSingleReportAction($filename)
    {
        $reportsDir = _PS_MODULE_DIR_ . 'updatestock/uploads/reports/';
        if ($this->deleteReport($reportsDir, $filename)) {
            LogsService::log('Report deleted: ' . basename($filename));
            $this->addFlash('success', $this->translationService->translate('Report deleted'));
        } else {
            LogsService::log('Failed to delete report: ' . basename($filename), 'ERROR');
            $this->addFlash('error', $this->translationService->translate('Failed to delete report'));
        }
        return $this->redirectToRoute('admin_updatestock_index');
    }

    public function deleteSingleBackupAction($filename)
    {
        if ($this->backupService->deleteBackup($filename)) {
            LogsService::log('Backup deleted: ' . basename($filename));
            $this->addFlash('success', $this->translationService->translate('Backup deleted'));
        }
        return $this->redirectToRoute('admin_updatestock_index');
    }

    public function restoreSingleBackupAction($filename)
    {
        if ($this->backupService->restoreBackup($filename)) {
            LogsService::log('Backup restored: ' . basename($filename));
            $this->addFlash('success', $this->translationService->translate('Backup %backup% restored successfully', ['backup' => basename($filename)]));
        } else {
            LogsService::log('Failed to restore backup: ' . basename($filename), 'ERROR');
            $this->addFlash('error', $this->translationService->translate('Failed to restore backup'));
        }
        return $this->redirectToRoute('admin_updatestock_index');
    }
}
