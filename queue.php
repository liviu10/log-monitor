<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use App\Controllers\QueueController;
use App\Enums\LogLevel;
use App\Utilities\LogViaStream;

$controller = new QueueController;

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($_POST),
            'purge' => $controller->purge(),
            default => throw new InvalidArgumentException(__('Invalid or unsupported POST action for queue.')),
        },
        'GET' => $controller->index($_GET),
        default => throw new RuntimeException(__('Unsupported HTTP method: ').htmlspecialchars(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'UNKNOWN', ENT_QUOTES, 'UTF-8')),
    };
} catch (Throwable $e) {
    if (class_exists('App\\Utilities\\LogViaStream')) {
        LogViaStream::send(LogLevel::ERROR->value, 'Queue action execution failure', [
            'location' => __FILE__,
            'line' => __LINE__,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'get_params' => $_GET,
            'post_params' => $_POST,
            'identifier' => 'Queue_Action_Failure',
        ]);
    }

    http_response_code(400);
    // If it is a normal request, redirect back with a flash error
    if (session_status() !== PHP_SESSION_NONE) {
        setFlash('danger', __('Error'), $e->getMessage());
        header('Location: queue.php');
    } else {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
