<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

class AlterContextColumnSize extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('logs');
        $table->changeColumn('context', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => true])
              ->update();
    }
}
