<?php

declare(strict_types=1);

// Dezactivam complet limitarea timpului de executie, desi executia trebuie sa fie sub 2-3 ms
set_time_limit(5);

// Incarcam configuratiile de baza ale aplicatiei
require_once __DIR__ . '/bootstrap.php';

use App\Utilities\MySQLWrapper;

// Determinam daca rulam in contextul unui worker FrankenPHP
$isFrankenPhpWorker = function_exists('frankenphp_handle_request');

$handler = function () {
    // Suport CORS: Permitem cererile de tip Preflight (OPTIONS) venite din browsere externe
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        // Am lasat doar X-API-KEY in lista de headere permise
        header('Access-Control-Allow-Headers: X-API-KEY, Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400'); // Cache la preflight pentru 24 ore
        http_response_code(204); // No Content
        return;
    }

    // Validam metoda HTTP: acceptam doar cereri de tip POST pentru ingestia de date
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['error' => __('Method Not Allowed. Use POST.')]);
        return;
    }

    // Adaugam header-ul de origine si pentru raspunsul request-ului de tip POST
    header('Access-Control-Allow-Origin: *');

    // Extragem EXCLUSIV API Key-ul din headere
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['X_API_KEY'] ?? null;

    if (!$apiKey) {
        // Fallback case-insensitive prin getallheaders daca functia este disponibila
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp($key, 'X-API-KEY') === 0) {
                        $apiKey = $value;
                        break;
                    }
                }
            }
        }
    }

    // Fail-Fast: Daca lipseste cheia API, respingem cererea direct
    if (!$apiKey || trim((string)$apiKey) === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => __('X-API-KEY header is missing or empty.')]);
        return;
    }

    // Validare interna: gasim ID-ul aplicatiei direct din baza de date
    try {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT id FROM apps WHERE api_key = ? LIMIT 1', [trim((string)$apiKey)]);
        $app = $stmt->fetch();

        if (!$app) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => __('Invalid or inactive client API Key.')]);
            return;
        }

        $appId = (int)$app['id'];
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => __('Database connection or query failed.')]);
        return;
    }

    // Preluam corpul brut al cererii POST ca string
    $rawPayload = file_get_contents('php://input');

    if ($rawPayload === false || trim($rawPayload) === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => __('Empty request body.')]);
        return;
    }

    // Inseram rapid payload-ul in tabela de coada log_queue fara validare sau decodare JSON
    try {
        $db->create('log_queue', [
            'app_id' => $appId,
            'payload_raw' => $rawPayload
        ]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => __('Failed to queue the log payload.')]);
        return;
    }

    // Returnam instant statusul 202 Accepted cu raspunsul standard JSON
    header('Content-Type: application/json');
    http_response_code(202);
    echo json_encode(['status' => 'queued']);
};

if ($isFrankenPhpWorker) {
    try {
        // Bucla de worker FrankenPHP
        $maxRequests = 500; // Pentru a preveni memory leaks
        for ($nbRequests = 0; $nbRequests < $maxRequests; ++$nbRequests) {
            $keepRunning = frankenphp_handle_request($handler);
            if (!$keepRunning) {
                break;
            }
        }
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'not in worker mode')) {
            // Daca nu suntem in mod worker (ex: request standard), executam direct handlerul
            $handler();
        } else {
            throw $e;
        }
    }
} else {
    // Rulare normala (ex: PHP-FPM sau CLI direct)
    $handler();
}