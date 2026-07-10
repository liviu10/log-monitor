<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreateLogQueueTable extends AbstractMigration
{
    /**
     * Creates the log_queue table for the asynchronous message queue.
     */
    public function change(): void
    {
        $table = $this->table('log_queue', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('app_id', 'biginteger', ['signed' => false])
              ->addColumn('payload_raw', 'json')
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addForeignKey('app_id', 'apps', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
              ->addIndex(['created_at'])
              ->create();
    }
}
