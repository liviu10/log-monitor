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
        
        if ($exists === [] || $exists === false) {
            $users->insert([
                [
                    'username' => 'admin',
                    'password_hash' => password_hash('admin123', PASSWORD_BCRYPT),
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            ])->saveData();
        }

        // 2. Clean old test data to avoid duplicates
        $this->execute('SET FOREIGN_KEY_CHECKS=0;');
        $this->execute('TRUNCATE TABLE logs');
        $this->execute('TRUNCATE TABLE app_settings');
        $this->execute('TRUNCATE TABLE apps');
        $this->execute('SET FOREIGN_KEY_CHECKS=1;');

        // 3. Initial App
        $apps = $this->table('apps');
        $appId = 1;
        $apps->insert([
            [
                'id' => $appId,
                'name' => 'Main Website API',
                'api_key' => 'e7c6b541234567890abcdef1234567890',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ])->saveData();
        
        // 4. Initial App Settings
        $appSettings = $this->table('app_settings');
        $appSettings->insert([
            [
                'app_id' => $appId,
                'key' => 'notification_levels',
                'value' => 'EMERGENCY,ALERT,CRITICAL,ERROR,WARNING',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'app_id' => $appId,
                'key' => 'notification_channel',
                'value' => 'email',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ])->saveData();

        // 5. Initial Logs
        $logs = $this->table('logs');
        $logs->insert([
            [
                'app_id' => $appId,
                'level' => 'ERROR',
                'message' => 'Failed to process payment for Order #98765',
                'context' => json_encode([
                    'order_id' => 98765,
                    'user_id' => 442,
                    'gateway' => 'Stripe',
                    'error' => 'Card expired',
                    'trace' => '#0 /var/www/html/src/PaymentGateway.php(112): Stripe\Charge::create()'
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
            ],
            [
                'app_id' => $appId,
                'level' => 'WARNING',
                'message' => 'High CPU usage detected on Application Server',
                'context' => json_encode([
                    'cpu_load' => '92%',
                    'memory_usage' => '4.2GB',
                    'active_processes' => 156
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            ],
            [
                'app_id' => $appId,
                'level' => 'INFO',
                'message' => 'User login successful',
                'context' => json_encode([
                    'user_id' => 101,
                    'username' => 'johndoe',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-4 hours')),
            ],
            [
                'app_id' => $appId,
                'level' => 'CRITICAL',
                'message' => 'Database connection lost',
                'context' => json_encode([
                    'db_host' => 'log-monitor-db',
                    'port' => 3306,
                    'exception' => 'SQLSTATE[HY000] [2002] Connection refused'
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ],
            [
                'app_id' => $appId,
                'level' => 'NOTICE',
                'message' => 'New application registered: MobileApp-v2',
                'context' => json_encode([
                    'app_name' => 'MobileApp-v2',
                    'created_by' => 'admin'
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ]
        ])->saveData();
    }
}