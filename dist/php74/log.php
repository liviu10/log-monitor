<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\LogController;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
    exit;
}

(new LogController())->store();