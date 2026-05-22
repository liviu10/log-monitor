<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\LogController;
use App\Utilities\LogViaCurl;

/**
 * Script de Mentenanta: Arhivare in format TXT si curatare (purge) din baza de date.
 * Executie: php bin/purge-logs.php [zile]
 *
 * @category Maintenance
 * @package  Bin
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

// Validarea argumentelor din linia de comanda si aplicarea filozofiei Fail Fast
$daysArgument = $argv[1] ?? '30';
if (!is_numeric($daysArgument) || (int)$daysArgument < 1) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid retention period specified. Must be a positive integer.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

$days = (int)$daysArgument;
$timestamp = strtotime("-{$days} days");

if ($timestamp === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to calculate the logical cutoff date boundary.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

$cutoffDate = date('Y-m-d H:i:s', $timestamp);
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
    // Structura obligatorie de logare in caz de exceptie
    LogViaCurl::send('ERROR', 'Query execution failure event', [
        'location' => __METHOD__,
        'line' => __LINE__,
        'exception_message' => $e->getMessage(),
        'exception_file' => $e->getFile(),
        'exception_line' => $e->getLine(),
        'exception_trace' => $e->getTraceAsString(),
        'sql_statement' => 'CLI Maintenance Purge Execution Failure',
        'sql_parameters' => [
            'purge_days' => $days,
            'cutoff_date' => $cutoffDate,
            'backup_file_path' => $backupPath
        ],
        'identifier' => 'MySQLWrapper_Query_Failure'
    ]);

    echo json_encode([
        'status' => 'error',
        'message' => 'A critical error occurred while running the maintenance script.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit(1);
}