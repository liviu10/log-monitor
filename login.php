<?php

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AuthController;

$controller = new AuthController();

match ($_SERVER['REQUEST_METHOD']) {
    'POST' => $controller->login(),
    default => match ($_GET['action'] ?? null) {
        'logout' => $controller->logout(),
        default => $controller->showLogin(),
    },
};
