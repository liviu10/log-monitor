<?php

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppSettingController;

$controller = new AppSettingController();

// Citim datele din request, oferind suport si pentru JSON payloads (util pentru multi-salvare/multi-actualizare)
$inputData = $_POST;
$rawInput = file_get_contents('php://input');

if (!empty($rawInput)) {
    $jsonData = json_decode($rawInput, true);
    if (is_array($jsonData)) {
        $inputData = array_merge($inputData, $jsonData);
    }
}

match ($_SERVER['REQUEST_METHOD']) {
    'POST' => match ($_GET['action'] ?? null) {
        'delete' => $controller->delete($inputData),
        'update' => $controller->update($inputData),
        default => $controller->store($inputData),
    },
    default => $controller->index($_GET),
};
