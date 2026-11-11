<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (isset($_SESSION['auth.user']) && is_array($_SESSION['auth.user'])) {
    $_SESSION['auth.last_activity'] = time();
    $rawTimeout = $_ENV['SESSION_IDLE_TIMEOUT'] ?? $_ENV['APP_SESSION_IDLE_TIMEOUT'] ?? 1800;
    $idleTimeout = is_numeric($rawTimeout) ? (int) $rawTimeout : 1800;

    echo json_encode([
        'success' => true,
        'user' => $_SESSION['auth.user']['username'] ?? '',
        'time' => time(),
        'idleTimeout' => $idleTimeout,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(401);
echo json_encode([
    'success' => false,
    'error' => 'Unauthenticated',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
