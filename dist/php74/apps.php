<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppController;

$controller = new AppController();

try {
    switch ($_SERVER['REQUEST_METHOD'] ?? '') {
        case 'POST':
            switch ($_GET['action'] ?? null) {
                case 'delete':
                    $controller->delete($_POST);
                    break;
                case 'update':
                    $controller->update($_POST, $_GET);
                    break;
                case null:
                    $controller->store($_POST);
                    break;
                default:
                    throw new InvalidArgumentException(__('Invalid or unsupported POST action.'));
            }
            break;
        case 'GET':
            $controller->index();
            break;
        default:
            throw new RuntimeException(__('Unsupported HTTP method: ') . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN', ENT_QUOTES, 'UTF-8'));
    }
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode(['error' => $throwable->getMessage()]);
    exit;
}