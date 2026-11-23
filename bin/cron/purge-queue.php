<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config/bootstrap.php';

use App\Controllers\Common\QueueController;
use App\Core\LogViaStream;
use App\Enums\LogLevel;
use App\Models\LogQueue;

$daysArgument = $argv[1] ?? '15';
if (! is_numeric($daysArgument) || (int) $daysArgument < 1) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid retention period specified. Must be a positive integer.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}

$days = (int) $daysArgument;

echo "--- LogMonitor Queue Maintenance Started ---\n";
echo "Retention policy: {$days} days\n";

try {
    $controller = new QueueController;
    $deletedCount = $controller->purge($days);
    $cutoffDate = date('Y-m-d H:i:s', time() - ($days * 86400));
    $deletedLogQueueCount = LogQueue::purgeProcessed($cutoffDate);

    echo json_encode([
        'status' => 'success',
        'message' => "Successfully purged {$deletedCount} notification queue jobs and {$deletedLogQueueCount} log queue jobs.",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
} catch (Throwable $e) {
    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
        LogViaStream::send(LogLevel::ERROR->value, 'Purge queue jobs failure event', [
            'location' => __FILE__,
            'line' => __LINE__,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'purge_days' => $days,
            'identifier' => 'Queue_Purge_Failure',
        ]);
    }

    echo json_encode([
        'status' => 'error',
        'message' => 'A critical error occurred while running the maintenance script.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    exit(1);
}
