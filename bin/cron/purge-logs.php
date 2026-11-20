<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config/bootstrap.php';

use App\Controllers\Admin\LogsController;
use App\Core\LogViaStream;
use App\Enums\LogLevel;

/**
 * Maintenance Script: Archive to TXT format and purge logs from database.
 * Execution: php bin/cron/purge-logs.php [days]
 */
$daysArgument = $argv[1] ?? '30';
if (! is_numeric($daysArgument) || (int) $daysArgument < 1) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid retention period specified. Must be a positive integer.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}

$days = (int) $daysArgument;
$timestamp = strtotime("-{$days} days");

if ($timestamp === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to calculate the logical cutoff date boundary.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}

$cutoffDate = date('Y-m-d H:i:s', $timestamp);
$backupFileName = 'backup-logs-'.date('Y-m-d-His').'.txt';
$backupPath = dirname(__DIR__, 2).'/storage/backups/'.$backupFileName;

echo "--- Log Archiving & Purge Started ---\n";
echo "Retention policy: {$days} days (Cutoff: {$cutoffDate})\n";

try {
    $controller = new LogsController;
    $result = $controller->purge($days, $backupPath);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
} catch (Throwable $e) {
    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
        LogViaStream::send(LogLevel::ERROR->value, 'Log purge execution failure event', [
            'location' => __FILE__,
            'line' => __LINE__,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'purge_days' => $days,
            'cutoff_date' => $cutoffDate,
            'backup_file_path' => $backupPath,
            'identifier' => 'Logs_Purge_Failure',
        ]);
    }

    echo json_encode([
        'status' => 'error',
        'message' => 'A critical error occurred while running the maintenance script.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    exit(1);
}
