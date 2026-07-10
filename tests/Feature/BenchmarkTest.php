<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Utilities\MySQLWrapper;
use Tests\TestCase;

/**
 * @group benchmark
 */
class BenchmarkTest extends TestCase
{
    public function test_performance_benchmark(): void
    {
        $appModel = new \App\Models\App;
        $appId = $appModel->create('Benchmark App', 'benchmark-key-xyz');

        // 1. Queue Ingestion Benchmark (insert 50,000 items into log_queue)
        $numLogs = 50000;
        $payload = json_encode([
            'level' => 'INFO',
            'message' => '[Benchmark] Log de test generat automat pentru analiza de performanta',
            'context' => ['user_id' => rand(1, 1000), 'status' => 'active', 'gateway' => 'podman-rootless'],
        ]);

        $db = MySQLWrapper::getInstance();
        $pdo = $db->getConnection();

        // Clear existing logs & queue
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE log_queue; TRUNCATE TABLE logs; SET FOREIGN_KEY_CHECKS = 1;');

        echo "\n[Benchmark] Ingestie: se introduc {$numLogs} loguri in coada...\n";
        $startTime = microtime(true);

        // Perform bulk inserts of 5000 rows at a time for speed
        $chunkSize = 5000;
        for ($i = 0; $i < $numLogs; $i += $chunkSize) {
            $pdo->beginTransaction();
            $sql = 'INSERT INTO log_queue (app_id, payload_raw) VALUES ';
            $placeholders = [];
            $params = [];
            for ($j = 0; $j < $chunkSize && ($i + $j) < $numLogs; $j++) {
                $placeholders[] = '(?, ?)';
                $params[] = $appId;
                $params[] = $payload;
            }
            $sql .= implode(', ', $placeholders);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $pdo->commit();
        }

        $ingestDuration = microtime(true) - $startTime;
        $ingestRate = $numLogs / $ingestDuration;
        echo "[Benchmark] Ingestie finalizata in " . round($ingestDuration, 2) . " secunde (Rata: " . round($ingestRate, 1) . " loguri/s).\n";

        // 2. Queue Processing Benchmark (Worker simulation)
        echo "[Benchmark] Procesare: se proceseaza coada de {$numLogs} loguri...\n";
        $startTime = microtime(true);

        $processed = 0;
        $workerChunkSize = 2000;

        // Run the processing loop until queue is empty
        while (true) {
            $pdo->beginTransaction();

            $stmt = $db->query("SELECT id, app_id, payload_raw FROM log_queue ORDER BY id ASC LIMIT {$workerChunkSize} FOR UPDATE SKIP LOCKED");
            $jobs = $stmt->fetchAll();

            if (!$jobs) {
                $pdo->commit();
                break;
            }

            $idsToDelete = [];
            $insertValues = [];
            $placeholders = [];

            foreach ($jobs as $job) {
                $idsToDelete[] = $job['id'];
                $decoded = json_decode($job['payload_raw'], true) ?: [];

                $level = $decoded['level'] ?? 'INFO';
                $message = '[Benchmark] Log de test generat automat pentru analiza de performanta';
                $context = json_encode($decoded['context'] ?? []);

                $placeholders[] = '(?, ?, ?, ?)';
                $insertValues[] = $appId;
                $insertValues[] = $level;
                $insertValues[] = $message;
                $insertValues[] = $context;
            }

            // Bulk insert into logs
            $sql = 'INSERT INTO logs (app_id, level, message, context) VALUES ' . implode(', ', $placeholders);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertValues);

            // Delete from queue
            $deletePlaceholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM log_queue WHERE id IN ({$deletePlaceholders})");
            $stmt->execute($idsToDelete);

            $pdo->commit();
            $processed += count($jobs);
        }

        $processDuration = microtime(true) - $startTime;
        $processRate = $numLogs / $processDuration;
        echo "[Benchmark] Procesare finalizata in " . round($processDuration, 2) . " secunde (Rata: " . round($processRate, 1) . " loguri/s).\n";

        $this->assertEquals($numLogs, $processed);
    }
}
