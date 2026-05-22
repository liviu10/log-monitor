<?php

namespace App\Controllers;

use App\Models\App;
use App\Models\Log;
use App\Enums\LogLevel;
use App\Utilities\Validation;

/**
 * Clasa LogController
 *
 * Responsabila pentru gestionarea cererilor de tip API pentru inregistrarea logurilor.
 * Asigura securitatea prin verificarea User-Agent-ului (pentru a preveni logging-ul din browser), 
 * validarea header-elor Content-Type si a cheilor API unice per aplicatie.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class LogController extends BaseController
{
    /**
     * Proceseaza si stocheaza o noua intrare de log primita prin POST.
     * 
     * Aceasta metoda efectueaza urmatoarele verificari:
     * - Blocheaza cererile provenite din browsere web (securitate server-to-server).
     * - Impune formatul JSON pentru payload.
     * - Valideaza cheia API furnizata in header.
     * - Valideaza structura si nivelul logului conform enumerarii LogLevel.
     */
    public function store(): never
    {
        // 1. Verificare User-Agent (Blocam browserele pentru securitate)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browserSignatures = ['Mozilla', 'Chrome', 'Safari', 'Edge', 'Opera', 'Firefox'];
        
        foreach ($browserSignatures as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                $this->jsonResponse([
                    'error' => 'Security Violation',
                    'message' => 'Frontend logging is disabled. Please send logs from your server-side code (cURL, Guzzle, etc.) to keep your API keys secure.',
                ], 403);
            }
        }

        // 2. Verificare Content-Type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (!str_contains($contentType, 'application/json')) {
            $this->jsonResponse(['error' => 'Content-Type must be application/json'], 415);
        }

        // 3. Verificare API Key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        
        if (!$apiKey) {
            $this->jsonResponse(['error' => 'X-API-KEY header is missing'], 401);
        }

        $appModel = new App();
        $app = $appModel->findByApiKey($apiKey);

        if (!$app) {
            $this->jsonResponse(['error' => 'Invalid or inactive API Key'], 403);
        }

        // 4. Decodificare si validare JSON
        $payload = json_decode(file_get_contents('php://input'), true);

        if (!$payload) {
            $this->jsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        $validator = new Validation([
            'level' => 'nivel log',
            'message' => 'mesaj',
            'context' => 'context',
        ]);

        $errors = $validator->validate([
            'level' => ['required', 'string', 'in:' . implode(',', LogLevel::all())],
            'message' => ['required', 'string'],
            'context' => ['array'],
        ], $payload);

        if (!empty($errors)) {
            $errorMessages = [];
            foreach ($errors as $field => $rules) {
                foreach ($rules as $rule) {
                    $errorMessages[] = $validator->messages($field, $rule);
                }
            }
            $this->jsonResponse(['errors' => $errorMessages], 422);
        }

        // 5. Inregistrare log in baza de date
        $logModel = new Log();
        $result = $logModel->create(
            $app['id'],
            $payload['level'],
            $payload['message'],
            $payload['context'] ?? null,
        );

        if ($result) {
            // Trimitem notificari prin intermediul NotificationController
            $notificationController = new NotificationController();
            $notificationController->sendAlert($app, [
                'level' => $payload['level'],
                'message' => $payload['message'],
                'context' => $payload['context'] ?? null,
            ]);

            $this->jsonResponse(['status' => 'success', 'message' => 'Log recorded']);
        }

        $this->jsonResponse(['error' => 'Failed to store log'], 500);
    }

    /**
     * Purgeaza si arhiveaza logurile mai vechi de un numar de zile.
     *
     * @param int    $days       Numarul de zile pentru retentie.
     * @param string $backupPath Calea absoluta a fisierului de backup.
     * @return array{
     *   status: string,
     *   message: string,
     *   archived_count: int,
     *   deleted_count: int|false
     * }
     */
    public function purge(int $days, string $backupPath): array
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $logModel = new Log();

        $totalToArchive = $logModel->countBeforeDate($cutoffDate);

        if ($totalToArchive === 0) {
            return [
                'status' => 'success',
                'message' => 'Nu exista loguri vechi de arhivat.',
                'archived_count' => 0,
                'deleted_count' => 0
            ];
        }

        // Cream directorul de backup daca nu exista
        $dir = dirname($backupPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileHandle = fopen($backupPath, 'w');
        if (!$fileHandle) {
            throw new \RuntimeException("Nu s-a putut crea fisierul de backup la calea: {$backupPath}");
        }

        fwrite($fileHandle, "LOG MONITOR BACKUP - GENERATED AT " . date('Y-m-d H:i:s') . "\n");
        fwrite($fileHandle, str_repeat("=", 80) . "\n\n");

        $offset = 0;
        $chunkSize = 1000;

        while ($offset < $totalToArchive) {
            $rows = $logModel->getBeforeDate($cutoffDate, $chunkSize, $offset);

            foreach ($rows as $row) {
                $line = sprintf(
                    "[%s] [%s] [%s]: %s | Context: %s\n",
                    $row['created_at'],
                    $row['app_name'],
                    $row['level'],
                    $row['message'],
                    $row['context'] ?? '{}'
                );
                fwrite($fileHandle, $line);
            }

            $offset += count($rows);
            if (empty($rows)) {
                break;
            }
        }

        fclose($fileHandle);

        // Stergem logurile vechi din baza de date
        $deletedCount = $logModel->deleteBeforeDate($cutoffDate);

        // Optimizam tabela logs
        $logModel->optimize();

        return [
            'status' => 'success',
            'message' => 'Procesul de arhivare si curatare s-a finalizat cu succes.',
            'archived_count' => $offset,
            'deleted_count' => $deletedCount
        ];
    }
}
