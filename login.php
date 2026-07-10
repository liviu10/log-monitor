<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use App\Controllers\AuthController;
use App\Enums\LogLevel;
use App\Utilities\LogViaStream;

$controller = new AuthController;

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => $controller->login($_POST),
        'GET' => match ($_GET['action'] ?? null) {
            'logout' => $controller->logout(),
            null => $controller->showLogin(),
            default => throw new InvalidArgumentException(__('Unknown authentication action.')),
        },
        default => throw new RuntimeException(__('HTTP method not allowed for authentication.')),
    };
} catch (Throwable $e) {
    if (class_exists('App\\Utilities\\LogViaStream')) {
        LogViaStream::send(LogLevel::ERROR->value, 'Authentication action execution failure', [
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
            'identifier' => 'Auth_Action_Failure',
        ]);
    }

    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
