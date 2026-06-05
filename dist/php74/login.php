<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AuthController;

$controller = new AuthController();

try {
    switch ($_SERVER['REQUEST_METHOD'] ?? '') {
        case 'POST':
            $controller->login($_POST);
            break;
        case 'GET':
            switch ($_GET['action'] ?? null) {
                case 'logout':
                    $controller->logout();
                    break;
                case null:
                    $controller->showLogin();
                    break;
                default:
                    throw new InvalidArgumentException(__('Unknown authentication action.'));
            }
            break;
        default:
            throw new RuntimeException(__('HTTP method not allowed for authentication.'));
    }
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode(['error' => $throwable->getMessage()]);
    exit;
}