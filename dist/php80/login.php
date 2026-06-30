<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AuthController;

$controller = new AuthController();

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => $controller->login($_POST),
        'GET' => match ($_GET['action'] ?? null) {
            'logout' => $controller->logout(),
            null     => $controller->showLogin(),
            default  => throw new InvalidArgumentException(__('Unknown authentication action.')),
        },
        default => throw new RuntimeException(__('HTTP method not allowed for authentication.')),
    };
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode(['error' => $throwable->getMessage()]);
    exit;
}