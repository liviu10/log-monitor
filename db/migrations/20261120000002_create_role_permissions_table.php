<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRolePermissionsTable extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('role_permissions')) {
            return;
        }

        $table = $this->table('role_permissions', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('role_id', 'biginteger', ['signed' => false])
            ->addColumn('permission_key', 'string', ['limit' => 255])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('group_name', 'string', ['limit' => 255])
            ->addColumn('value', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['role_id', 'permission_key'], ['unique' => true])
            ->addIndex(['permission_key'])
            ->addIndex(['group_name'])
            ->addIndex(['name'])
            ->addIndex(['value'])
            ->create();
    }
}
