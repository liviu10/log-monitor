<?php

declare(strict_types=1);

namespace App\Controllers\Common;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Core\Queue;
use App\Enums\LogLevel;
use App\Models\QueueJob;
use Throwable;

/**
 * Notification Queue Controller
 *
 * Coordinates queue job processing, exponential backoff retries, maintenance, and health checks.
 *
 * @category Controller
 *
 * @version  2.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class QueueController extends BaseController
{
    protected ?NotifyManagerController $notifyManager = null;

    public function setNotifyManager(NotifyManagerController $notifyManager): void
    {
        $this->notifyManager = $notifyManager;
    }

    /**
     * Processes all pending and retryable jobs from the notification queue.
     */
    public function processQueue(): void
    {
        $jobs = QueueJob::getPendingAndRetryable();

        if (count($jobs) === 0) {
            return;
        }

        $notifyManager = $this->notifyManager ?? new NotifyManagerController;

        foreach ($jobs as $job) {
            $rawJobId = $job['id'] ?? null;
            $jobId = is_scalar($rawJobId) ? (int) $rawJobId : 0;
            $rawAttempts = $job['attempts'] ?? null;
            $attempts = (is_scalar($rawAttempts) ? (int) $rawAttempts : 0) + 1;

            // Mark job as processing
            QueueJob::updateStatus($jobId, [
                'status' => 'processing',
                'attempts' => $attempts,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            try {
                $rawPayload = $job['payload'] ?? '';
                $payloadStr = is_string($rawPayload) ? $rawPayload : '';
                $jobData = json_decode($payloadStr, true);

                if (! is_array($jobData)) {
                    $errorMessage = 'Job payload is invalid (not a JSON array).';
                    $nextAvailableAt = $this->calculateBackoffAvailableAt($attempts);

                    QueueJob::updateStatus($jobId, [
                        'status' => 'failed',
                        'error_message' => $errorMessage,
                        'available_at' => $nextAvailableAt,
                        'processed_at' => date('Y-m-d H:i:s'),
                    ]);
                    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                        LogViaStream::send(LogLevel::ERROR->value, "Error in queue job execution {$jobId}: {$errorMessage}", [
                            'location' => __METHOD__,
                            'line' => __LINE__,
                            'job_id' => $jobId,
                            'payload_string' => $payloadStr,
                            'identifier' => 'Queue_Job_Validation_Failure',
                        ]);
                    }

                    continue;
                }

                // Delegate execution to NotifyManager
                /** @var array<string, mixed> $jobData */
                $success = $notifyManager->handle($jobData);

                if (! $success) {
                    $errorMessage = 'NotifyManager failed to handle the job (see Stream Log for details).';
                    $nextAvailableAt = $this->calculateBackoffAvailableAt($attempts);

                    QueueJob::updateStatus($jobId, [
                        'status' => 'failed',
                        'error_message' => $errorMessage,
                        'available_at' => $nextAvailableAt,
                        'processed_at' => date('Y-m-d H:i:s'),
                    ]);
                    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                        LogViaStream::send(LogLevel::ERROR->value, "Error in queue job execution {$jobId}: {$errorMessage}", [
                            'location' => __METHOD__,
                            'line' => __LINE__,
                            'job_id' => $jobId,
                            'identifier' => 'Queue_Job_Execution_Failure',
                        ]);
                    }

                    continue;
                }

                // Job completed successfully
                QueueJob::updateStatus($jobId, [
                    'status' => 'completed',
                    'error_message' => null,
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

            } catch (Throwable $e) {
                // Log the real unexpected errors and mark the job as failed with backoff
                $nextAvailableAt = $this->calculateBackoffAvailableAt($attempts);

                QueueJob::updateStatus($jobId, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'available_at' => $nextAvailableAt,
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

                // Write the error to Stream Log
                if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                    LogViaStream::send(LogLevel::ERROR->value, "Error in queue job execution {$jobId}", [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'exception_trace' => $e->getTraceAsString(),
                        'identifier' => 'Queue_Job_Execution_Failure',
                    ]);
                }
            }
        }
    }

    /**
     * Calculates future timestamp based on configured exponential backoff delays.
     */
    public function calculateBackoffAvailableAt(int $attempts): string
    {
        $delaysConfig = config('app.queue.backoff_delays');
        $delays = [1, 5, 20, 60];

        if (is_array($delaysConfig)) {
            $filtered = [];
            foreach ($delaysConfig as $d) {
                if (is_int($d) && $d > 0) {
                    $filtered[] = $d;
                }
            }
            if ($filtered !== []) {
                $delays = $filtered;
            }
        }

        $index = min(max(0, $attempts - 1), count($delays) - 1);
        $delayMinutes = $delays[$index];

        return date('Y-m-d H:i:s', time() + ($delayMinutes * 60));
    }

    /**
     * Checks SMTP service availability before bulk retry.
     */
    public static function checkSmtpHealth(?string $host = null, int $port = 587, int $timeout = 2): bool
    {
        $targetHost = $host;
        if ($targetHost === null) {
            $envHost = $_ENV['MAIL_HOST'] ?? '';
            $targetHost = is_string($envHost) ? $envHost : '';
        }

        if ($targetHost === '') {
            return true;
        }

        $fp = @fsockopen($targetHost, $port, $errno, $errstr, (float) $timeout);
        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    /**
     * Purges processed jobs older than the specified number of days.
     *
     * @param  int  $days  Retention days.
     * @return int Number of deleted jobs.
     */
    public function purge(int $days): int
    {
        $timestamp = strtotime("-{$days} days");
        $cutoffDate = date('Y-m-d H:i:s', is_int($timestamp) ? $timestamp : time());

        return QueueJob::purge($cutoffDate);
    }

    /**
     * Internal endpoint for plugins to push jobs to the queue.
     * Expects a JSON payload containing the event and job payload.
     */
    public function pushWebhook(): void
    {
        try {
            $json = file_get_contents('php://input');
            if (! is_string($json) || $json === '') {
                $this->json(['status' => 'error', 'message' => 'Invalid payload format'], 400);
            }
            $data = json_decode($json, true);

            if (! is_array($data) || ! isset($data['event']) || ! is_string($data['event']) || ! isset($data['payload']) || ! is_array($data['payload'])) {
                $this->json(['status' => 'error', 'message' => 'Invalid payload format'], 400);
            }

            $action = $data['event'];
            /** @var array<string, mixed> $payload */
            $payload = $data['payload'];

            $jobData = [
                'action' => $action,
                'payload' => $payload,
            ];

            Queue::push('default', $jobData);

            $this->json(['status' => 'success'], 200);
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'JSON_RESPONSE:')) {
                throw $e;
            }

            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Webhook Queue Push Failed', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'Webhook_Queue_Push_Failure',
                ]);
            }
            $this->json(['status' => 'error'], 500);
        }
    }
}
