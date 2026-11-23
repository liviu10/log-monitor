<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpgradeLogQueueLifecycleColumns extends AbstractMigration
{
    /**
     * Adds status, attempts, retry scheduling, error details, and processed_at columns to log_queue.
     */
    public function change(): void
    {
        if (! $this->hasTable('log_queue')) {
            return;
        }

        $table = $this->table('log_queue');

        if (! $table->hasColumn('status')) {
            $table->addColumn('status', 'string', ['limit' => 50, 'default' => 'pending', 'after' => 'payload_raw']);
        }
        if (! $table->hasColumn('attempts')) {
            $table->addColumn('attempts', 'integer', ['default' => 0, 'after' => 'status']);
        }
        if (! $table->hasColumn('available_at')) {
            $table->addColumn('available_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'after' => 'attempts']);
        }
        if (! $table->hasColumn('expires_at')) {
            $table->addColumn('expires_at', 'timestamp', ['null' => true, 'default' => null, 'after' => 'available_at']);
        }
        if (! $table->hasColumn('error_message')) {
            $table->addColumn('error_message', 'text', ['null' => true, 'after' => 'expires_at']);
        }
        if (! $table->hasColumn('processed_at')) {
            $table->addColumn('processed_at', 'timestamp', ['null' => true, 'default' => null, 'after' => 'created_at']);
        }

        if (! $table->hasIndex(['status'])) {
            $table->addIndex(['status']);
        }
        if (! $table->hasIndex(['status', 'available_at'])) {
            $table->addIndex(['status', 'available_at']);
        }
        if (! $table->hasIndex(['expires_at'])) {
            $table->addIndex(['expires_at']);
        }

        $table->update();
    }
}
