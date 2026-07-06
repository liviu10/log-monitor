<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreateAppSettingsTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('app_settings', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('app_id', 'biginteger', ['signed' => false])
              ->addColumn('key', 'string', ['limit' => 255])
              ->addColumn('value', 'text')
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['null' => true])
              ->addForeignKey('app_id', 'apps', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
              ->addIndex(['app_id', 'key'], ['unique' => true])
              ->create();
    }
}