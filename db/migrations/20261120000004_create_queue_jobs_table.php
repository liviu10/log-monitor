<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateQueueJobsTable extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('queue_jobs')) {
            return;
        }

        $table = $this->table('queue_jobs', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('queue', 'string', ['limit' => 100, 'default' => 'default'])
            ->addColumn('payload', 'text')
            ->addColumn('status', 'string', ['limit' => 50, 'default' => 'pending'])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('available_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('expires_at', 'timestamp', ['null' => true, 'default' => null])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('processed_at', 'timestamp', ['null' => true])
            ->addIndex(['status'])
            ->addIndex(['queue'])
            ->addIndex(['status', 'available_at'])
            ->addIndex(['expires_at'])
            ->create();
    }
}
