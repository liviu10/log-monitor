<?php

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppSettingController;

$controller = new AppSettingController();

match ($_SERVER['REQUEST_METHOD']) {
    'POST' => match ($_GET['action'] ?? null) {
        'delete' => $controller->delete($_POST),
        'update' => $controller->update($_POST),
        default => $controller->store($_POST),
    },
    default => $controller->index($_GET),
};
