<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\Common\NotificationController;
use App\Models\LogQueue;
use Throwable;

/**
 * Processes buffered payloads from the `log_queue` table into `logs`, tracking completion,
 * exponential backoff retries, error reporting, and expiration.
 */
final class LogQueueProcessor
{
    /**
     * Recursively sanitizes the context array to mask confidential/sensitive data.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function sanitizeSensitivePayload(array $data): array
    {
        $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'cvv', 'api_key', 'key'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeSensitivePayload($value);
            } elseif (is_string($value)) {
                $lowerKey = strtolower((string) $key);
                if (in_array($lowerKey, $sensitiveKeys, true)) {
                    $data[$key] = '******';
                } elseif (json_validate($value)) {
                    try {
                        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $data[$key] = json_encode(self::sanitizeSensitivePayload($decoded), JSON_THROW_ON_ERROR);
                        }
                    } catch (Throwable) {
                        // Ignore errors if decoding fails
                    }
                }
            }
        }

        return $data;
    }

    public static function sanitizeLogMessage(string $message): string
    {
        return (string) preg_replace('/(password|pass|pwd|token|api_key)\s*=\s*[^\s&]+/ims', '$1=******', $message);
    }

    /**
     * Calculates future timestamp based on configured exponential backoff delays (in minutes).
     */
    public static function calculateBackoffAvailableAt(int $attempts): string
    {
        $delaysConfig = function_exists('config') ? config('app.queue.backoff_delays') : null;
        $delays = [1, 5, 20, 60];

        if (is_array($delaysConfig)) {
            $filtered = [];
            foreach ($delaysConfig as $val) {
                if (is_numeric($val)) {
                    $filtered[] = (int) $val;
                }
            }
            if ($filtered !== []) {
                $delays = $filtered;
            }
        }

        $index = max(0, min($attempts - 1, count($delays) - 1));
        $minutes = $delays[$index];

        return date('Y-m-d H:i:s', time() + ($minutes * 60));
    }

    /**
     * Processes one batch (up to 2000 rows) of queued logs inside a database transaction.
     *
     * @param  array<int, array{data: array<string, mixed>|null, settings: array<string, mixed>, cached_at: int}>  $appsCache
     */
    public static function processBatch(array &$appsCache = [], ?NotificationController $notificationController = null): int
    {
        $db = MySQLWrapper::getInstance();
        $pdo = $db->getConnection();
        $notifier = $notificationController ?? new NotificationController;

        LogQueue::markExpired();

        $maxAttemptsCfg = function_exists('config') ? config('app.queue.max_attempts') : null;
        $maxAttempts = is_numeric($maxAttemptsCfg) ? max(1, (int) $maxAttemptsCfg) : 5;

        try {
            $pdo->beginTransaction();

            $stmt = $db->query(
                "SELECT id, app_id, payload_raw, attempts
                 FROM log_queue
                 WHERE (status = 'pending' OR (status = 'failed' AND attempts < ?))
                   AND (available_at IS NULL OR available_at <= NOW())
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY id ASC
                 LIMIT 2000
                 FOR UPDATE SKIP LOCKED",
                [$maxAttempts]
            );
            $jobs = $stmt->fetchAll();

            if ($jobs === []) {
                $pdo->commit();

                return 0;
            }

            $idsCompleted = [];
            /** @var array<int, array{attempts: int, error: string}> $failedJobs */
            $failedJobs = [];
            $insertRows = [];
            $insertValues = [];
            /** @var array<int, array{id: int, attempts: int}> $claimedJobs */
            $claimedJobs = [];

            foreach ($jobs as $job) {
                if (! is_array($job) || ! isset($job['id'], $job['app_id'], $job['payload_raw'])) {
                    continue;
                }

                $jobIdVal = $job['id'];
                $appIdVal = $job['app_id'];
                $payloadRawVal = $job['payload_raw'];

                if ((! is_int($jobIdVal) && ! is_string($jobIdVal)) ||
                    (! is_int($appIdVal) && ! is_string($appIdVal)) ||
                    ! is_string($payloadRawVal)) {
                    continue;
                }

                $jobId = (int) $jobIdVal;
                $appId = (int) $appIdVal;
                $currentAttempts = isset($job['attempts']) && is_numeric($job['attempts']) ? (int) $job['attempts'] : 0;
                $nextAttempts = $currentAttempts + 1;
                $claimedJobs[] = ['id' => $jobId, 'attempts' => $nextAttempts];

                if (! json_validate($payloadRawVal)) {
                    $failedJobs[$jobId] = [
                        'attempts' => $nextAttempts,
                        'error' => 'Job payload is invalid JSON.',
                    ];

                    continue;
                }

                try {
                    $payload = json_decode($payloadRawVal, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    $payload = null;
                }

                if (! is_array($payload)) {
                    $failedJobs[$jobId] = [
                        'attempts' => $nextAttempts,
                        'error' => 'Decoded log payload must be a JSON object/array.',
                    ];

                    continue;
                }

                $levelVal = $payload['level'] ?? 'INFO';
                $messageVal = $payload['message'] ?? '';

                $level = strtoupper(trim(is_string($levelVal) ? $levelVal : 'INFO'));
                $message = self::sanitizeLogMessage(trim(is_string($messageVal) ? $messageVal : ''));
                if ($message === '') {
                    $failedJobs[$jobId] = [
                        'attempts' => $nextAttempts,
                        'error' => 'Log payload is missing a non-empty "message" field.',
                    ];

                    continue;
                }

                $context = isset($payload['context']) && is_array($payload['context'])
                    ? self::sanitizeSensitivePayload($payload['context'])
                    : [];

                $jsonContext = $context !== [] ? json_encode($context, JSON_THROW_ON_ERROR) : null;

                $insertRows[] = '(?, ?, ?, ?)';
                $insertValues[] = $appId;
                $insertValues[] = $level;
                $insertValues[] = $message;
                $insertValues[] = $jsonContext;
                $idsCompleted[] = $jobId;

                $now = time();
                if (! isset($appsCache[$appId]) || ($now - $appsCache[$appId]['cached_at']) > 10) {
                    $stmtApp = $db->query('SELECT * FROM apps WHERE id = ? LIMIT 1', [$appId]);
                    $fetchedApp = $stmtApp->fetch();
                    /** @var array<string, mixed>|null $appData */
                    $appData = is_array($fetchedApp) ? $fetchedApp : null;
                    $settings = [];
                    if (is_array($appData)) {
                        $stmtSettings = $db->query('SELECT `key`, `value` FROM app_settings WHERE app_id = ?', [$appId]);
                        foreach ($stmtSettings->fetchAll() as $row) {
                            if (is_array($row) && isset($row['key'], $row['value']) && is_string($row['key'])) {
                                $settings[$row['key']] = $row['value'];
                            }
                        }
                    }
                    $appsCache[$appId] = [
                        'data' => $appData,
                        'settings' => $settings,
                        'cached_at' => $now,
                    ];
                }

                $app = $appsCache[$appId]['data'];
                if (is_array($app)) {
                    $notifier->sendAlert($app, [
                        'level' => $level,
                        'message' => $message,
                        'context' => $context,
                    ], $appsCache[$appId]['settings']);
                }
            }

            if ($insertRows !== []) {
                $sqlInsert = 'INSERT INTO logs (app_id, level, message, context) VALUES '.implode(', ', $insertRows);
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute($insertValues);
            }

            if ($idsCompleted !== []) {
                $placeholders = implode(', ', array_fill(0, count($idsCompleted), '?'));
                $sqlComplete = "UPDATE log_queue
                                SET status = 'completed', attempts = attempts + 1, error_message = NULL, processed_at = NOW()
                                WHERE id IN ({$placeholders})";
                $stmtComplete = $pdo->prepare($sqlComplete);
                $stmtComplete->execute($idsCompleted);
            }

            foreach ($failedJobs as $failedId => $meta) {
                $nextAvailableAt = self::calculateBackoffAvailableAt($meta['attempts']);
                $stmtFail = $pdo->prepare(
                    "UPDATE log_queue
                     SET status = 'failed', attempts = ?, error_message = ?, available_at = ?, processed_at = NOW()
                     WHERE id = ?"
                );
                $stmtFail->execute([$meta['attempts'], $meta['error'], $nextAvailableAt, $failedId]);
            }

            $pdo->commit();

            return count($claimedJobs);
        } catch (Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Ignore rollback failure
            }

            throw $e;
        }
    }
}
