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
            $this->jsonResponse(['status' => 'success', 'message' => 'Log recorded']);
        }

        $this->jsonResponse(['error' => 'Failed to store log'], 500);
    }
}
