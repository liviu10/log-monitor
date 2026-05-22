<?php

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\DashboardController;

$controller = new DashboardController();
$controller->index();
