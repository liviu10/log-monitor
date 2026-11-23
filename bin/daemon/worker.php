<?php

declare(strict_types=1);

/**
 * CLI Queue Worker
 *
 * Pulls and processes asynchronous jobs from the queue.
 *
 * @category CLI
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */

require_once dirname(dirname(__DIR__)).'/config/bootstrap.php';

use App\Controllers\Common\QueueController;
use App\Core\LogViaStream;
use App\Enums\LogLevel;

// Lock file location to prevent parallel runs
$lockFile = dirname(dirname(__DIR__)).'/storage/views/worker.lock';
$heartbeatFile = dirname(dirname(__DIR__)).'/storage/queue_worker.heartbeat';

try {
    $lockFp = fopen($lockFile, 'w');
    if ($lockFp === false) {
        exit(0);
    }
    flock($lockFp, LOCK_EX | LOCK_NB);
} catch (Throwable $e) {
    exit(0);
}

try {
    @file_put_contents($heartbeatFile, (string) time(), LOCK_EX);
    $controller = new QueueController;
    $controller->processQueue();
} catch (Throwable $e) {
    if (class_exists('App\Core\LogViaStream') && class_exists('App\Enums\LogLevel')) {
        LogViaStream::send(
            LogLevel::CRITICAL->value,
            'Fatal error in Queue Worker',
            [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'Queue_Worker_Fatal_Failure',
            ]
        );
    }
} finally {
    try {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    } catch (Throwable $e) {
        if (class_exists('App\Core\LogViaStream') && class_exists('App\Enums\LogLevel')) {
            LogViaStream::send(
                LogLevel::ERROR->value,
                'Unlock failure in Queue Worker',
                [
                    'location' => __FILE__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'Queue_Worker_Unlock_Failure',
                ]
            );
        }
    }
}
