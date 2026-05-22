<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Utilities\MySQLWrapper;

/**
 * Script de mentenanta: Arhivare in TXT si apoi stergere din DB.
 * Utilizare: php bin/purge-logs.php [zile]
 */

$days = isset($argv[1]) ? (int)$argv[1] : 30;
$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$backupFileName = "backup-logs-" . date('Y-m-d-His') . ".txt";
$backupPath = __DIR__ . '/../storage/backups/' . $backupFileName;

echo "--- Log Archiving & Purge Started ---\n";
echo "Retention policy: {$days} days (Cutoff: {$cutoffDate})\n";

$db = MySQLWrapper::getInstance();

try {
    // 1. Verificam daca exista loguri de arhivat
    $countSql = "SELECT COUNT(*) as total FROM logs WHERE created_at < ?";
    $stmt = $db->query($countSql, [$cutoffDate]);
    $totalToArchive = $stmt->fetch(\PDO::FETCH_ASSOC)['total'];

    if ($totalToArchive == 0) {
        echo "No old logs found. Nothing to do.\n";
        exit(0);
    }

    echo "Found {$totalToArchive} logs to archive. Writing to {$backupFileName}...\n";

    // 2. Deschiem fisierul pentru scriere
    $fileHandle = fopen($backupPath, 'w');
    if (!$fileHandle) {
        throw new \Exception("Could not create backup file at {$backupPath}");
    }

    // Header fisier
    fwrite($fileHandle, "LOG MONITOR BACKUP - GENERATED AT " . date('Y-m-d H:i:s') . "\n");
    fwrite($fileHandle, str_repeat("=", 80) . "\n\n");

    // 3. Extragem si scriem in bucati (pentru a proteja memoria RAM)
    $offset = 0;
    $chunkSize = 1000;
    
    while ($offset < $totalToArchive) {
        $logsSql = "SELECT l.*, a.name as app_name 
                    FROM logs l 
                    JOIN apps a ON l.app_id = a.id 
                    WHERE l.created_at < ? 
                    ORDER BY l.created_at ASC 
                    LIMIT {$chunkSize} OFFSET {$offset}";
        
        $stmt = $db->query($logsSql, [$cutoffDate]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $line = sprintf(
                "[%s] [%s] [%s]: %s | Context: %s\n",
                $row['created_at'],
                $row['app_name'],
                $row['level'],
                $row['message'],
                $row['context'] ?? '{}'
            );
            fwrite($fileHandle, $line);
        }

        $offset += $chunkSize;
        echo "Processed {$offset}/{$totalToArchive}...\n";
    }

    fclose($fileHandle);
    echo "Backup completed successfully at {$backupPath}\n";

    // 4. Dupa ce backup-ul e gata, stergem din baza de date
    echo "Deleting archived logs from database...\n";
    $deleteSql = "DELETE FROM logs WHERE created_at < ?";
    $db->query($deleteSql, [$cutoffDate]);

    // 5. Optimizare tabela
    echo "Optimizing table 'logs' to reclaim space...\n";
    $db->query("OPTIMIZE TABLE logs");

    echo "--- Process Finished Successfully ---\n";

} catch (\Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    if (isset($fileHandle)) fclose($fileHandle);
    exit(1);
}
