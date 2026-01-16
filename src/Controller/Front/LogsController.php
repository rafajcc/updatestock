<?php
declare(strict_types=1);
namespace Antigravity\UpdateStock\Controller\Front;

use Antigravity\UpdateStock\Service\LogsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Exception;
use const false;

class LogsController extends AbstractController
{

    private const LOG_FILE_PATH = __DIR__ . '/../../../updatestock.log';

    public function getLogsAction(Request $request)
    {
        try {
            $content = json_decode($request->getContent(), true);
            $lines = isset($content['lines']) ? (int) $content['lines'] : 30;
            $logFile = self::LOG_FILE_PATH;
            $log = LogsService::readLastLines($logFile, $lines);
            $fileSize = LogsService::getFileSize($logFile);
            return new JsonResponse([
                'log' => $log,
                'size' => $fileSize
            ]);
        } catch (Exception $e) {
            LogsService::log($e->getMessage());
            // TODO translate this 
            return new JsonResponse(['error' => 'Internal error getting module logs'], 500);
        }
    }

    public function downloadLogsAction()
    {
        $logPath = self::LOG_FILE_PATH;

        if (!file_exists($logPath)) {
            http_response_code(404);
            exit('Log file not found');
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="updatestock-log.txt"');
        readfile($logPath);
        exit;
    }

    public function clearLogsAction()
    {
        $logPath = self::LOG_FILE_PATH;

        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        http_response_code(200);
        exit;
    }
}