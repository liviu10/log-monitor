<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class InitialDataSeed extends AbstractSeed
{
    public function run(): void
    {
        // 1. Initial Admin User
        $users = $this->table('users');
        $exists = $this->fetchRow("SELECT 1 FROM users WHERE username = 'admin' LIMIT 1");
        
        if (empty($exists)) {
            $users->insert([
                [
                    'username' => 'admin',
                    'password_hash' => password_hash('admin123', PASSWORD_BCRYPT),
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            ])->saveData();
        }
    }
}