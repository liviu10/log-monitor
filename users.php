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
} catch (\Throwable $e) {
    if (class_exists('App\\Utilities\\LogViaStream')) {
        \App\Utilities\LogViaStream::send(\App\Enums\LogLevel::ERROR->value, 'User action execution failure', [
            'location' => __METHOD__,
            'line' => __LINE__,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'get_params' => $_GET,
            'post_params' => $_POST,
            'identifier' => 'User_Action_Failure'
        ]);
    }

    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}