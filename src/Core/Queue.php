<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\QueueJob;

/**
 * Queue Utility
 *
 * Provides a simple interface for pushing jobs into the asynchronous queue.
 *
 * @category Core
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Queue
{
    /**
     * Adds a new job to the queue by calling the QueueJob model.
     *
     * @param  string  $queue  The queue name.
     * @param  array<string, mixed>  $payload  Job payload execution parameters.
     * @param  string|null  $availableAt  Timestamp when job becomes eligible for processing.
     * @param  string|null  $expiresAt  Timestamp when job expires (TTL).
     * @return string|int The job record ID.
     */
    public static function push(
        string $queue,
        array $payload,
        ?string $availableAt = null,
        ?string $expiresAt = null
    ): string|int {
        return QueueJob::push($queue, $payload, $availableAt, $expiresAt);
    }
}
