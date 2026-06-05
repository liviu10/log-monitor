<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\UserController;

$controller = new UserController();

try {
    switch ($_SERVER['REQUEST_METHOD'] ?? '') {
        case 'POST':
            switch ($_GET['action'] ?? null) {
                case 'delete':
                    $controller->delete($_POST);
                    break;
                case null:
                    $controller->store($_POST);
                    break;
                default:
                    throw new InvalidArgumentException(__('Invalid user action.'));
            }
            break;
        case 'GET':
            $controller->index();
            break;
        default:
            throw new RuntimeException(__('HTTP method not allowed for user management.'));
    }
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode(['error' => $throwable->getMessage()]);
    exit;
}