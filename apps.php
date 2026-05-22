<?php

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppController;

$controller = new AppController();

match ($_SERVER['REQUEST_METHOD']) {
    'POST' => match ($_GET['action'] ?? null) {
        'delete' => $controller->delete(),
        default => $controller->store(),
    },
    default => $controller->index(),
};
