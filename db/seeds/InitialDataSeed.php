<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Initial Application Data Seeder
 *
 * Populates database with default roles, administrator/demo accounts, and notification templates.
 */
class InitialDataSeed extends AbstractSeed
{
    public function run(): void
    {
        // 1. Seed roles
        $rolesTable = $this->table('roles');

        $adminRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1");
        if (! is_array($adminRole)) {
            $rolesTable->insert([
                [
                    'name' => 'ADMIN',
                    'is_active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'name' => 'USER',
                    'is_active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ])->saveData();

            $adminRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1");
            $userRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'USER' LIMIT 1");
        } else {
            $userRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'USER' LIMIT 1");
        }

        $adminRoleId = is_array($adminRole) && isset($adminRole['id']) && is_scalar($adminRole['id']) ? (int) $adminRole['id'] : 0;
        $userRoleId = is_array($userRole) && isset($userRole['id']) && is_scalar($userRole['id']) ? (int) $userRole['id'] : 0;

        // 2. Seed users
        $usersTable = $this->table('users');
        if ($this->isProduction()) {
            $this->seedProductionAdmin($adminRoleId);
        } elseif (! is_array($this->fetchRow("SELECT 1 FROM users WHERE email = 'admin@logmonitor.local' LIMIT 1"))) {
            $usersTable->insert([
                [
                    'role_id' => $adminRoleId,
                    'first_name' => 'Admin',
                    'last_name' => 'LogMonitor',
                    'email' => 'admin@logmonitor.local',
                    'username' => 'admin',
                    'email_verified_at' => date('Y-m-d H:i:s'),
                    'password_hash' => password_hash('admin123', PASSWORD_ARGON2ID),
                    'status' => 'ACTIVE',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'role_id' => $userRoleId,
                    'first_name' => 'Viewer',
                    'last_name' => 'User',
                    'email' => 'user@logmonitor.local',
                    'username' => 'user',
                    'email_verified_at' => date('Y-m-d H:i:s'),
                    'password_hash' => password_hash('user123', PASSWORD_ARGON2ID),
                    'status' => 'ACTIVE',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ])->saveData();
        }

        // 3. Seed default notification templates
        $templates = [
            [
                'event_name' => 'user_welcome_credentials',
                'channel' => 'email',
                'language' => 'en',
                'subject' => 'Your LogMonitor Account Credentials',
                'body_html' => '<p>Hello {{ $first_name }} {{ $last_name }},</p><p>Your LogMonitor account has been created. Your temporary password is: <strong>{{ $temporary_password }}</strong></p><p><a href="{{ $login_link }}">Sign in here</a> and change your password immediately.</p>',
                'body_text' => "Hello {{ \$first_name }} {{ \$last_name }},\n\nYour LogMonitor account has been created.\nTemporary password: {{ \$temporary_password }}\nLogin: {{ \$login_link }}",
            ],
            [
                'event_name' => 'user_welcome_credentials',
                'channel' => 'email',
                'language' => 'ro',
                'subject' => 'Credentialele contului tau LogMonitor',
                'body_html' => '<p>Salut {{ $first_name }} {{ $last_name }},</p><p>Contul tau LogMonitor a fost creat. Parola temporara este: <strong>{{ $temporary_password }}</strong></p><p><a href="{{ $login_link }}">Autentifica-te aici</a> si schimba parola imediat.</p>',
                'body_text' => "Salut {{ \$first_name }} {{ \$last_name }},\n\nContul tau LogMonitor a fost creat.\nParola temporara: {{ \$temporary_password }}\nLogin: {{ \$login_link }}",
            ],
            [
                'event_name' => 'user_email_verification',
                'channel' => 'email',
                'language' => 'en',
                'subject' => 'Verify your LogMonitor email address',
                'body_html' => '<p>Please verify your email address by opening the link below:</p><p><a href="{{ $link }}">{{ $link }}</a></p>',
                'body_text' => 'Verify your email address: {{ $link }}',
            ],
            [
                'event_name' => 'user_email_verification',
                'channel' => 'email',
                'language' => 'ro',
                'subject' => 'Verifica adresa de email LogMonitor',
                'body_html' => '<p>Te rugam sa verifici adresa de email accesand linkul de mai jos:</p><p><a href="{{ $link }}">{{ $link }}</a></p>',
                'body_text' => 'Verifica adresa de email: {{ $link }}',
            ],
            [
                'event_name' => 'log_alert',
                'channel' => 'email',
                'language' => 'en',
                'subject' => '[{{ $level }}] Log Alert {{ $app_name }} - LogMonitor',
                'body_html' => '<h3>LogMonitor Alert</h3><p><strong>Application:</strong> {{ $app_name }}<br><strong>Level:</strong> {{ $level }}<br><strong>Date:</strong> {{ $logged_at }}</p><p><strong>Message:</strong><br>{{ $message }}</p>@if(!empty($context_pretty))<pre>{{ $context_pretty }}</pre>@endif',
                'body_text' => "Application: {{ \$app_name }}\nLevel: {{ \$level }}\nDate: {{ \$logged_at }}\nMessage: {{ \$message }}\n\n{{ \$context_pretty ?? '' }}",
            ],
            [
                'event_name' => 'log_alert',
                'channel' => 'email',
                'language' => 'ro',
                'subject' => '[{{ $level }}] Alerta Log {{ $app_name }} - LogMonitor',
                'body_html' => '<h3>Alerta LogMonitor</h3><p><strong>Aplicatie:</strong> {{ $app_name }}<br><strong>Nivel:</strong> {{ $level }}<br><strong>Data:</strong> {{ $logged_at }}</p><p><strong>Mesaj:</strong><br>{{ $message }}</p>@if(!empty($context_pretty))<pre>{{ $context_pretty }}</pre>@endif',
                'body_text' => "Aplicatie: {{ \$app_name }}\nNivel: {{ \$level }}\nData: {{ \$logged_at }}\nMesaj: {{ \$message }}\n\n{{ \$context_pretty ?? '' }}",
            ],
        ];

        $quote = fn (string $v): string => $this->getAdapter()->getConnection()->quote($v);
        $tplTable = $this->table('notification_templates');

        foreach ($templates as $tpl) {
            $exists = $this->fetchRow(sprintf(
                'SELECT id FROM notification_templates WHERE event_name = %s AND channel = %s AND language = %s LIMIT 1',
                $quote($tpl['event_name']),
                $quote($tpl['channel']),
                $quote($tpl['language'])
            ));

            if (! is_array($exists)) {
                $tplTable->insert([[
                    'event_name' => $tpl['event_name'],
                    'channel' => $tpl['channel'],
                    'language' => $tpl['language'],
                    'subject' => $tpl['subject'],
                    'body_html' => $tpl['body_html'],
                    'body_text' => $tpl['body_text'],
                    'is_active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]])->saveData();
            }
        }
    }

    private function isProduction(): bool
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return is_string($env) && in_array(strtolower($env), ['production', 'prod'], true);
    }

    private function seedProductionAdmin(int $adminRoleId): void
    {
        $rawEmail = $_ENV['ADMIN_EMAIL'] ?? getenv('ADMIN_EMAIL');
        $rawPassword = $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');

        $email = is_string($rawEmail) ? strtolower(trim(trim($rawEmail, "\"'"))) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = 'admin@logmonitor.local';
        }

        $password = is_string($rawPassword) ? trim($rawPassword) : '';

        if ($adminRoleId < 1) {
            $adminRole = $this->fetchRow("SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1");
            $adminRoleId = is_array($adminRole) && isset($adminRole['id']) && is_scalar($adminRole['id'])
                ? (int) $adminRole['id']
                : 1;
        }

        $existingAdmin = $this->fetchRow("SELECT u.id, u.email FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'ADMIN' ORDER BY u.id ASC LIMIT 1");
        $quote = fn (string $v): string => $this->getAdapter()->getConnection()->quote($v);

        if (is_array($existingAdmin) && isset($existingAdmin['id']) && is_scalar($existingAdmin['id'])) {
            $adminId = (int) $existingAdmin['id'];
            if (strlen($password) >= 14) {
                $hash = password_hash($password, PASSWORD_ARGON2ID);
                $this->execute(sprintf(
                    "UPDATE users SET password_hash = %s, status = 'ACTIVE' WHERE id = %d",
                    $quote($hash),
                    $adminId
                ));
                echo "[seed] Administrator password successfully updated from secrets/admin_password.\n";
            } else {
                echo "[seed] Administrator already exists (ID: {$adminId}).\n";
            }

            return;
        }

        if (strlen($password) < 14) {
            echo "[seed] Production administrator NOT created: secrets/admin_password must be at least 14 characters.\n";

            return;
        }

        $this->table('users')->insert([[
            'role_id' => $adminRoleId,
            'first_name' => 'Admin',
            'last_name' => 'LogMonitor',
            'email' => $email,
            'username' => 'admin',
            'email_verified_at' => date('Y-m-d H:i:s'),
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'status' => 'ACTIVE',
            'created_at' => date('Y-m-d H:i:s'),
        ]])->saveData();

        echo "[seed] Administrator created successfully ({$email}).\n";
    }
}
