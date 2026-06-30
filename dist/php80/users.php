<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\UserController;

$controller = new UserController();

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($_POST),
            null     => $controller->store($_POST),
            default  => throw new InvalidArgumentException(__('Invalid user action.')),
        },
        'GET' => $controller->index(),
        default => throw new RuntimeException(__('HTTP method not allowed for user management.')),
    };
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode(['error' => $throwable->getMessage()]);
    exit;
}