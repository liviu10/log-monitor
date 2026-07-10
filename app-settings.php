<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use App\Controllers\AppSettingController;

$controller = new AppSettingController;

$inputData = $_POST;
$rawInput = file_get_contents('php://input');

if (! empty($rawInput)) {
    if (json_validate($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            $inputData = array_merge($inputData, $jsonData);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => __('Invalid JSON payload provided.')]);
        exit;
    }
}

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($inputData),
            'update' => $controller->update($inputData),
            null => $controller->store($inputData),
            default => throw new InvalidArgumentException(__('Invalid POST action for settings.')),
        },
        'GET' => $controller->index($_GET),
        default => throw new RuntimeException(__('Unsupported HTTP method for settings.')),
    };
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
