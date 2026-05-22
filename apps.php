<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppController;

$controller = new AppController();

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($_POST),
            'update' => $controller->update($_POST, $_GET),
            null     => $controller->store($_POST),
            default  => throw new InvalidArgumentException(__('Invalid or unsupported POST action.')),
        },
        'GET' => $controller->index(),
        default => throw new RuntimeException(__('Unsupported HTTP method: ') . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN', ENT_QUOTES, 'UTF-8')),
    };
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}