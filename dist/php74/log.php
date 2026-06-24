<?php

declare(strict_types=1);

// Dezactivam complet limitarea timpului de executie, desi executia trebuie sa fie sub 2-3 ms
set_time_limit(5);

// Incarcam configuratiile de baza ale aplicatiei
require_once __DIR__ . '/bootstrap.php';

use App\Utilities\MySQLWrapper;

// Validam metoda HTTP: acceptam doar cereri de tip POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => __('Method Not Allowed. Use POST.')]);
    exit;
}

// Extragem API Key-ul specific aplicatiei client din headere
$appKey = $_SERVER['HTTP_X_APP_KEY'] ?? $_SERVER['X_APP_KEY'] ?? null;

// Fallback case-insensitive prin getallheaders daca functia este disponibila
if (!$appKey && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-APP-KEY') === 0 || strcasecmp($key, 'X-App-Key') === 0) {
                $appKey = $value;
                break;
            }
        }
    }
}

// Fail-Fast: Daca lipseste cheia aplicatiei client, respingem cererea direct
if (!$appKey || trim((string)$appKey) === '') {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => __('X-APP-KEY header is missing or empty.')]);
    exit;
}

// Validare secundara interna: gasim ID-ul aplicatiei direct din baza de date
try {
    $db = MySQLWrapper::getInstance();
    $stmt = $db->query('SELECT id FROM apps WHERE api_key = ? LIMIT 1', [trim((string)$appKey)]);
    $app = $stmt->fetch();

    if (!$app) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => __('Invalid or inactive client API Key.')]);
        exit;
    }

    $appId = (int)$app['id'];
} catch (\Throwable $throwable) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => __('Database connection or query failed.')]);
    exit;
}

// Preluam corpul brut al cererii POST ca string
$rawPayload = file_get_contents('php://input');

if ($rawPayload === false || trim($rawPayload) === '') {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => __('Empty request body.')]);
    exit;
}

// Inseram rapid payload-ul in tabela de coada log_queue fara validare sau decodare JSON
try {
    $db->create('log_queue', [
        'app_id' => $appId,
        'payload_raw' => $rawPayload
    ]);
} catch (\Throwable $throwable) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => __('Failed to queue the log payload.')]);
    exit;
}

// Returnam instant statusul 202 Accepted cu raspunsul standard JSON
header('Content-Type: application/json');
http_response_code(202);
echo json_encode(['status' => 'queued']);
exit;