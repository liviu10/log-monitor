<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\QueueController;

$controller = new QueueController();

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($_POST),
            'purge'  => $controller->purge(),
            default  => throw new InvalidArgumentException(__('Invalid or unsupported POST action for queue.')),
        },
        'GET' => $controller->index($_GET),
        default => throw new RuntimeException(__('Unsupported HTTP method: ') . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN', ENT_QUOTES, 'UTF-8')),
    };
} catch (Throwable $e) {
    http_response_code(400);
    // Daca este o cerere normala, redirectionam inapoi cu flash error
    if (session_status() !== PHP_SESSION_NONE) {
        setFlash('danger', __('Error'), $e->getMessage());
        header('Location: queue.php');
    } else {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
