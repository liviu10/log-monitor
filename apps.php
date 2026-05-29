<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppController;

$controller = new AppController();

// Test: 10,000 iterations to verify performance and database capability
try {
    $appModel = new \App\Models\App();
    $apps = $appModel->getAll();
    if (empty($apps)) {
        $appId = $appModel->create("Performance Test App", bin2hex(random_bytes(32)));
    } else {
        $appId = (int)$apps[0]['id'];
    }

    $logModel = new \App\Models\Log();
    $db = \App\Utilities\MySQLWrapper::getInstance()->getConnection();
    
    // Truncate logs first to keep the performance test clean and repeatable
    $db->exec('SET FOREIGN_KEY_CHECKS=0;');
    $db->exec('TRUNCATE TABLE logs;');
    $db->exec('SET FOREIGN_KEY_CHECKS=1;');

    $db->beginTransaction();
    for ($i = 1; $i <= 10000; $i++) {
        $level = match ($i % 4) {
            0 => 'INFO',
            1 => 'WARNING',
            2 => 'ERROR',
            default => 'CRITICAL'
        };

        $context = [
            'iteration' => $i,
            'type' => 'perf_test'
        ];

        // Add a realistic traceback for ERROR and CRITICAL levels
        if (in_array($level, ['ERROR', 'CRITICAL'], true)) {
            $context['error'] = 'Simulated exception message';
            $context['file'] = '/media/liviu/DEV/PROJECTS/log-monitor/apps.php';
            $context['line'] = 37;
            $context['trace'] = [
                "#1 /media/liviu/DEV/PROJECTS/log-monitor/src/Models/Log.php(43): App\\Utilities\\MySQLWrapper->query()",
                "#2 /media/liviu/DEV/PROJECTS/log-monitor/src/Controllers/DashboardController.php(52): App\\Models\\Log->getPaginated()",
                "#3 /media/liviu/DEV/PROJECTS/log-monitor/apps.php(56): App\\Controllers\\DashboardController->index()",
                "#4 {main}"
            ];
        }

        $logModel->create(
            $appId,
            $level,
            "Performance Test Log message #$i",
            $context
        );
    }
    $db->commit();
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Performance test failed: " . $e->getMessage());
}

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($_POST),
            'update' => $controller->update($_POST, $_GET),
            null     => $controller->store($_POST),
            default  => throw new InvalidArgumentException(__('Invalid or unsupported POST action.')),
        },
        'GET' => $controller->index(),
        default => throw new RuntimeException(__('Unsupported HTTP method: ') . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN', ENT_QUOTES, 'UTF-8')),
    };
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}