<?php

use Phinx\Migration\AbstractMigration;

class CreateLogsTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('logs');
        $table->addColumn('app_id', 'biginteger', ['signed' => false])
              ->addColumn('level', 'string', ['limit' => 20])
              ->addColumn('message', 'text')
              ->addColumn('context', 'json', ['null' => true])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addForeignKey('app_id', 'apps', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
              ->addIndex(['level'])
              ->addIndex(['created_at'])
              ->create();
    }
}
