<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreateAppsTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('apps', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('name', 'string', ['limit' => 100])
              ->addColumn('api_key', 'string', ['limit' => 64])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['null' => true])
              ->addIndex(['api_key'], ['unique' => true])
              ->create();
    }
}
