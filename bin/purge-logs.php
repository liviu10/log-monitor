<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\LogController;
use App\Utilities\LogViaCurl;

/**
 * Maintenance Script: Archive to TXT and then purge from DB.
 * Usage: php bin/purge-logs.php [days]
 */

$days = isset($argv[1]) ? (int)$argv[1] : 30;
$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$backupFileName = "backup-logs-" . date('Y-m-d-His') . ".txt";
$backupPath = __DIR__ . '/../storage/backups/' . $backupFileName;

echo "--- Log Archiving & Purge Started ---\n";
echo "Retention policy: {$days} days (Cutoff: {$cutoffDate})\n";

try {
    $controller = new LogController();
    $result = $controller->purge($days, $backupPath);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);

} catch (\Throwable $e) {
    LogViaCurl::send('CRITICAL', 'FATAL ERROR in bin/purge-logs.php', [
        'location' => 'bin/purge-logs.php',
        'line' => __LINE__,
        'exception_message' => $e->getMessage(),
        'exception_file' => $e->getFile(),
        'exception_line' => $e->getLine(),
        'exception_trace' => $e->getTraceAsString(),
        'purge_days' => $days,
        'cutoff_date' => $cutoffDate,
        'backup_file' => $backupFileName
    ]);

    echo json_encode([
        'status' => 'error',
        'message' => 'A critical error occurred while running the maintenance script.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit(1);
}