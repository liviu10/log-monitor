<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpgradeUsersTableForRbac extends AbstractMigration
{
    public function up(): void
    {
        // Ensure ADMIN and USER roles exist before attaching FK
        $adminRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1");
        if (! is_array($adminRole)) {
            $this->table('roles')->insert([
                ['name' => 'ADMIN', 'is_active' => true, 'created_at' => date('Y-m-d H:i:s')],
                ['name' => 'USER', 'is_active' => true, 'created_at' => date('Y-m-d H:i:s')],
            ])->saveData();
            $adminRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1");
        }
        $adminRoleId = is_array($adminRole) && isset($adminRole['id']) && is_scalar($adminRole['id']) ? (int) $adminRole['id'] : 1;

        $table = $this->table('users');

        if (! $table->hasColumn('role_id')) {
            $table->addColumn('role_id', 'biginteger', ['signed' => false, 'default' => $adminRoleId, 'after' => 'id']);
        }
        if (! $table->hasColumn('first_name')) {
            $table->addColumn('first_name', 'string', ['limit' => 100, 'default' => 'Admin', 'after' => 'role_id']);
        }
        if (! $table->hasColumn('last_name')) {
            $table->addColumn('last_name', 'string', ['limit' => 100, 'default' => 'LogMonitor', 'after' => 'first_name']);
        }
        if (! $table->hasColumn('email')) {
            $table->addColumn('email', 'string', ['limit' => 150, 'null' => true, 'after' => 'last_name']);
        }
        if (! $table->hasColumn('email_verified_at')) {
            $table->addColumn('email_verified_at', 'timestamp', ['null' => true, 'after' => 'email']);
        }
        if (! $table->hasColumn('status')) {
            $table->addColumn('status', 'string', ['limit' => 50, 'default' => 'ACTIVE', 'after' => 'password_hash']);
        }
        if (! $table->hasColumn('profile_image')) {
            $table->addColumn('profile_image', 'string', ['limit' => 255, 'null' => true, 'after' => 'status']);
        }
        if ($table->hasColumn('username')) {
            $table->changeColumn('username', 'string', ['limit' => 50, 'null' => true]);
        }

        $table->update();

        // Backfill email for any legacy username-only users
        $this->execute("UPDATE users SET email = CONCAT(COALESCE(NULLIF(username, ''), CONCAT('user', id)), '@logmonitor.local') WHERE email IS NULL OR email = ''");
        $this->execute("UPDATE users SET role_id = {$adminRoleId} WHERE role_id = 0 OR role_id IS NULL");

        $table->changeColumn('email', 'string', ['limit' => 150, 'null' => false]);
        if (! $table->hasIndex(['email'])) {
            $table->addIndex(['email'], ['unique' => true]);
        }
        if (! $table->hasForeignKey('role_id')) {
            $table->addForeignKey('role_id', 'roles', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('users');
        if ($table->hasForeignKey('role_id')) {
            $table->dropForeignKey('role_id');
        }
        if ($table->hasIndex(['email'])) {
            $table->removeIndex(['email']);
        }
        foreach (['profile_image', 'status', 'email_verified_at', 'email', 'last_name', 'first_name', 'role_id'] as $col) {
            if ($table->hasColumn($col)) {
                $table->removeColumn($col);
            }
        }
        $table->update();
    }
}
