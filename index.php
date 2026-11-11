<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use App\Controllers\DashboardController;

$controller = new DashboardController;

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
if ($action === 'chart-data') {
    $controller->chartData($_GET);
} else {
    $controller->index($_GET);
}
