<?php
declare(strict_types=1);
namespace Module\UpdateStock\Controller\Front;

use Module\UpdateStock\Service\LogsService;
use Module\UpdateStock\Service\TranslationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Exception;
use const false;

class LogsController extends AbstractController
{
    private const LOG_FILE_PATH = __DIR__ . '/../../../updatestock.log';

    private $translationService;

    public function __construct(TranslationService $translationService)
    {
        $this->translationService = $translationService;
    }

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
            return new JsonResponse(['error' => $this->translationService->translate('Internal error getting module logs')], 500);
        }
    }

    public function downloadLogsAction()
    {
        $logPath = self::LOG_FILE_PATH;

        if (!file_exists($logPath)) {
            http_response_code(404);
            exit($this->translationService->translate('Log file not found'));
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