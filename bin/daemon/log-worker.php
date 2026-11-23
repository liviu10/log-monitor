<?php

declare(strict_types=1);

set_time_limit(0);

require_once dirname(__DIR__, 2).'/config/bootstrap.php';

use App\Controllers\Common\NotificationController;
use App\Core\LogQueueProcessor;
use App\Core\LogViaStream;
use App\Enums\LogLevel;

/** @var array<int, array{data: array<string, mixed>|null, settings: array<string, mixed>, cached_at: int}> $appsCache */
$appsCache = [];
$lastHeartbeat = 0;
$notificationController = new NotificationController;

// @phpstan-ignore while.alwaysTrue
while (true) {
    $currentTime = time();
    if (($currentTime - $lastHeartbeat) >= 10) {
        try {
            $heartbeatFile = dirname(__DIR__, 2).'/storage/worker.heartbeat';
            file_put_contents($heartbeatFile, (string) $currentTime, LOCK_EX);
            $lastHeartbeat = $currentTime;
        } catch (Throwable) {
            // Defensive execution
        }
    }

    try {
        $processed = LogQueueProcessor::processBatch($appsCache, $notificationController);
        if ($processed === 0) {
            usleep(500000);
        }
    } catch (Throwable $e) {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            LogViaStream::send(LogLevel::ERROR->value, 'CLI Log Worker execution failure', [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'CLI_Log_Worker_Failure',
            ]);
        }

        try {
            $logDir = dirname(__DIR__, 2).'/storage/logs';
            if (! is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }

            $logFile = $logDir.'/worker_emergency.log';
            $errorMessage = sprintf(
                "[%s] Critical error encountered in CLI Log Worker: %s\nTrace:\n%s\n%s\n",
                date('Y-m-d H:i:s'),
                $e->getMessage(),
                $e->getTraceAsString(),
                str_repeat('-', 80)
            );
            @file_put_contents($logFile, $errorMessage, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            error_log('Log Worker emergency logging failure: '.$e->getMessage());
        }

        sleep(5);
    }
}
