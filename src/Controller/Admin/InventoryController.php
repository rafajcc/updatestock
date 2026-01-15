<?php

namespace Antigravity\UpdateStock\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Antigravity\UpdateStock\Service\StockUpdateService;
use Antigravity\UpdateStock\Service\BackupService;

class InventoryController extends FrameworkBundleAdminController
{
    private $stockUpdateService;
    private $backupService;

    public function __construct(StockUpdateService $stockUpdateService, BackupService $backupService)
    {
        parent::__construct();
        $this->stockUpdateService = $stockUpdateService;
        $this->backupService = $backupService;
    }

    public function index(Request $request)
    {
        $backupAvailable = $this->backupService->hasBackups();
        $uploadDir = _PS_MODULE_DIR_ . 'updatestock/uploads/';

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
                // For multiple files Symfony uses array structure differently, 
                // simplifying to raw $_FILES or proper Symfony loop requires looping the Request files bag.
                // Let's assume basic single file or use simple loop for simplicity in migration
                foreach ($request->files->get('stock_files', []) as $file) {
                    if ($file && $file->getClientOriginalExtension() === 'txt') {
                        $file->move($uploadDir, $file->getClientOriginalName());
                    }
                }
                $this->addFlash('success', 'Files uploaded successfully.');
                // Redirect to self to refresh list
                return $this->redirectToRoute('admin_updatestock_inventory');
            }

            if ($request->request->has('submitRunInventory')) {
                $selectedFiles = $request->request->get('selected_files');
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
                if ($this->backupService->restoreLatestBackup()) {
                    $this->addFlash('success', 'Backup restored successfully');
                } else {
                    $this->addFlash('error', 'Failed to restore backup');
                }
            }

            if ($request->request->has('submitDeleteFiles')) {
                $filesToDelete = $request->request->get('selected_files');
                if ($filesToDelete) {
                    foreach ($filesToDelete as $f) {
                        if (file_exists($uploadDir . basename($f)))
                            unlink($uploadDir . basename($f));
                    }
                    $this->addFlash('success', 'Files deleted');
                    return $this->redirectToRoute('admin_updatestock_inventory');
                }
            }
        }

        return $this->render('@Modules/updatestock/templates/admin/inventory/index.html.twig', [
            'uploaded_files' => $uploadedFiles,
            'backup_available' => $backupAvailable,
            'reports_generated' => $reports,
            'module_dir' => _MODULE_DIR_ . 'updatestock/'
        ]);
    }
}
