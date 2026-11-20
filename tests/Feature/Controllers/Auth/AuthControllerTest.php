<?php

declare(strict_types=1);

namespace App\Controllers\Auth {
    global $mockOpensslDecryptThrows;
    $mockOpensslDecryptThrows = false;

    if (! function_exists('App\Controllers\Auth\openssl_decrypt')) {
        function openssl_decrypt(string $data, string $cipher_algo, string $passphrase, int $options = 0, string $iv = '', ?string $tag = null, string $aad = ''): string|false
        {
            global $mockOpensslDecryptThrows;
            if ($mockOpensslDecryptThrows) {
                throw new \RuntimeException('Mock openssl failure');
            }

            return \openssl_decrypt($data, $cipher_algo, $passphrase, $options, $iv, $tag, $aad);
        }
    }
}

namespace Tests\Feature\Controllers\Auth {

    // Require InternalFileManagerTest to load the mocked move_uploaded_file for the App\Utilities namespace
    require_once dirname(__DIR__, 3).'/Unit/Utilities/InternalFileManagerTest.php';

    use App\Core\MySQLWrapper;
    use App\Core\Router;
    use App\Exceptions\RedirectException;
    use App\Utilities\LoginThrottle;
    use App\Utilities\SessionManager;
    use PHPUnit\Framework\TestCase;

    /**
     * Class AuthControllerTest
     *
     * Feature tests for the AuthController endpoints.
     *
     * @category Tests
     */
    class AuthControllerTest extends TestCase
    {
        private Router $router;

        protected function setUp(): void
        {
            parent::setUp();

            // Reset environment
            $_POST = [];
            $_GET = [];
            $_SESSION = [];
            $_FILES = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/';

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // Isolated, empty login-throttle state for every test.
            LoginThrottle::$directory = sys_get_temp_dir().'/idh-login-throttle-'.bin2hex(random_bytes(6));

            $this->router = new Router;
            $this->registerRoutes();
        }

        protected function tearDown(): void
        {
            $_POST = [];
            $_GET = [];
            $_SESSION = [];
            $_FILES = [];
            LoginThrottle::$directory = null;

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, null);

            parent::tearDown();
            global $mockOpensslDecryptThrows;
            $mockOpensslDecryptThrows = false;
        }

        private function registerRoutes(): void
        {
            $this->router->get('/login', 'Auth\AuthController@showLogin');
            $this->router->post('/login', 'Auth\AuthController@login');
            $this->router->get('/admin/settings/users/profile', 'Auth\AuthController@profile');
            $this->router->post('/admin/settings/users/profile', 'Auth\AuthController@updateProfile');
            $this->router->get('/verify-email', 'Auth\AuthController@showVerifyEmail');
            $this->router->post('/verify-email', 'Auth\AuthController@verifyEmail');
            $this->router->post('/logout', 'Auth\AuthController@logout');
        }

        /**
         * @param  array<string, mixed>|null  $userData
         */
        private function mockDatabaseForUser(?array $userData = null): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);
            if ($userData !== null) {
                $mockStatement->method('fetch')->willReturn($userData);
            } else {
                $mockStatement->method('fetch')->willReturn(false);
            }

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query', 'update', 'create'])
                ->getMock();

            $mockWrapper->method('query')->willReturn($mockStatement);
            $mockWrapper->method('update')->willReturn(1); // 1 row updated
            $mockWrapper->method('create')->willReturn(1); // Return fake ID

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);
        }

        public function test_show_login_renders_successfully(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET['inactivity'] = '1';

            $this->mockDatabaseForUser(null);

            $result = $this->router->dispatch('GET', '/login');

            self::assertIsString($result);
            self::assertStringContainsString('html', strtolower($result));
            self::assertStringContainsString('Click to fill', $result);
        }

        public function test_show_login_hides_quick_fill_in_production(): void
        {
            $previousEnv = $_ENV['APP_ENV'] ?? null;
            $_ENV['APP_ENV'] = 'production';
            config('::reset');

            try {
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $this->mockDatabaseForUser(null);

                $result = $this->router->dispatch('GET', '/login');

                self::assertIsString($result);
                self::assertStringContainsString('html', strtolower($result));
                self::assertStringNotContainsString('Click to fill', $result);
            } finally {
                if ($previousEnv !== null) {
                    $_ENV['APP_ENV'] = $previousEnv;
                } else {
                    unset($_ENV['APP_ENV']);
                }
                config('::reset');
            }
        }

        public function test_profile_renders_successfully(): void
        {
            $userData = [
                'id' => 10,
                'email' => 'john@test.local',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'role_name' => 'USER',
                'status' => 'ACTIVE',
            ];

            SessionManager::set('auth.user', $userData);
            $this->mockDatabaseForUser($userData);

            $_SERVER['REQUEST_METHOD'] = 'GET';

            try {
                $result = $this->router->dispatch('GET', '/admin/settings/users/profile');
                self::assertIsString($result);
            } catch (\Exception $e) {
                self::fail('Profile GET threw an exception: '.$e->getMessage());
            }
        }

        public function test_update_profile_redirects_if_not_authenticated(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/login', $e->getUrl());
            }
        }

        public function test_update_profile_success(): void
        {
            $this->mockDatabaseForUser([
                'id' => 10,
                'email' => 'john@test.local',
            ]);

            SessionManager::set('auth.user', [
                'id' => 10,
                'email' => 'john@test.local',
                'first_name' => 'Old',
                'last_name' => 'Name',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException on successful update.');
            } catch (RedirectException $e) {
                self::assertEquals('/admin/settings/users/profile', $e->getUrl());

                $sessionFlash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertNotEmpty($sessionFlash);

                $authUser = SessionManager::get('auth.user');
                $authUser = is_array($authUser) ? $authUser : [];
                self::assertEquals('John', $authUser['first_name'] ?? '');
                self::assertEquals('Doe', $authUser['last_name'] ?? '');
            }
        }

        public function test_update_profile_fails_password_mismatch(): void
        {
            SessionManager::set('auth.user', [
                'id' => 10,
                'email' => 'john@test.local',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';
            $_POST['password'] = 'new_password';
            $_POST['password_confirmation'] = 'different_password';

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/admin/settings/users/profile', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('error', $flash['type'] ?? '');
                self::assertEquals('Passwords do not match.', $flash['message'] ?? '');
            }
        }

        public function test_logout_clears_session(): void
        {
            SessionManager::set('auth.user', ['id' => 1]);

            $_SERVER['REQUEST_METHOD'] = 'POST';

            try {
                $this->router->dispatch('POST', '/logout');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/login', $e->getUrl());
                self::assertArrayNotHasKey('auth.user', $_SESSION);
            }
        }

        public function test_logout_after_inactivity_keeps_the_flag(): void
        {
            SessionManager::set('auth.user', ['id' => 1]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['inactivity'] = '1';

            try {
                $this->router->dispatch('POST', '/logout');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/login?inactivity=1', $e->getUrl());
            }
        }

        public function test_verify_email_invalid_token(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = 'invalid_base64_string_that_cannot_be_decrypted';

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/admin/settings/users/profile', $e->getUrl());

                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('error', $flash['type'] ?? '');
                self::assertStringContainsString('token is invalid', is_string($flash['message'] ?? null) ? $flash['message'] : '');
            }
        }

        public function test_login_fails_if_invalid_credentials(): void
        {
            $this->mockDatabaseForUser([
                'id' => 1,
                'email' => 'test@example.local',
                'password_hash' => password_hash('correct_password', PASSWORD_DEFAULT),
                'status' => 'ACTIVE',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['password'] = 'wrong_password';

            try {
                $this->router->dispatch('POST', '/login');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/login', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('error', $flash['type'] ?? '');
                self::assertEquals('Invalid email or password.', $flash['message'] ?? '');
            }
        }

        public function test_login_locks_account_after_too_many_failures(): void
        {
            for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
                LoginThrottle::hit('victim@example.local');
            }

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'victim@example.local';
            $_POST['password'] = 'whatever';

            try {
                $this->router->dispatch('POST', '/login');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/login', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('Too many failed attempts. Please try again later.', $flash['message'] ?? '');
            }
        }

        public function test_login_fails_if_inactive_account(): void
        {
            $this->mockDatabaseForUser([
                'id' => 1,
                'email' => 'test@example.local',
                'password_hash' => password_hash('correct_password', PASSWORD_DEFAULT),
                'status' => 'INACTIVE',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['password'] = 'correct_password';

            try {
                $this->router->dispatch('POST', '/login');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/login', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('error', $flash['type'] ?? '');
                self::assertEquals('Your account is inactive.', $flash['message'] ?? '');
            }
        }

        public function test_login_success_admin(): void
        {
            $this->mockDatabaseForUser([
                'id' => 1,
                'email' => 'admin@example.local',
                'password_hash' => password_hash('correct_password', PASSWORD_DEFAULT),
                'status' => 'ACTIVE',
                'role_name' => 'ADMIN',
                'role_id' => 1,
                'email_verified_at' => null, // Trigger sendVerificationEmail
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'admin@example.local';
            $_POST['password'] = 'correct_password';

            try {
                $this->router->dispatch('POST', '/login');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/admin', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('success', $flash['type'] ?? '');

                $authUser = SessionManager::get('auth.user');
                $authUser = is_array($authUser) ? $authUser : [];
                self::assertEquals('ADMIN', $authUser['role_name'] ?? '');
            }
        }

        public function test_login_success_user(): void
        {
            $this->mockDatabaseForUser([
                'id' => 2,
                'email' => 'user@example.local',
                'password_hash' => password_hash('correct_password', PASSWORD_DEFAULT),
                'status' => 'ACTIVE',
                'role_name' => 'USER',
                'role_id' => 2,
                'email_verified_at' => '2025-01-01 10:00:00',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'user@example.local';
            $_POST['password'] = 'correct_password';

            try {
                $this->router->dispatch('POST', '/login');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/admin/settings/users/profile', $e->getUrl());
            }
        }

        public function test_update_profile_fails_password_length(): void
        {
            SessionManager::set('auth.user', [
                'id' => 10,
                'email' => 'john@test.local',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';
            $_POST['password'] = '12345';
            $_POST['password_confirmation'] = '12345';

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/admin/settings/users/profile', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('error', $flash['type'] ?? '');
                self::assertEquals('Password must be at least 12 characters long.', $flash['message'] ?? '');
            }
        }

        public function test_update_profile_requires_current_password(): void
        {
            $this->mockDatabaseForUser([
                'id' => 10,
                'email' => 'john@test.local',
                'password_hash' => password_hash('old-password-123', PASSWORD_DEFAULT),
            ]);
            SessionManager::set('auth.user', ['id' => 10, 'email' => 'john@test.local']);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';
            $_POST['current_password'] = 'not-the-old-password';
            $_POST['password'] = 'brand-new-password-1';
            $_POST['password_confirmation'] = 'brand-new-password-1';

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('error', $flash['type'] ?? '');
                self::assertEquals('Current password is incorrect.', $flash['message'] ?? '');
            }
        }

        public function test_update_profile_success_with_password(): void
        {
            $this->mockDatabaseForUser([
                'id' => 10,
                'email' => 'john@test.local',
                'password_hash' => password_hash('old-password-123', PASSWORD_DEFAULT),
            ]);
            SessionManager::set('auth.user', [
                'id' => 10,
                'email' => 'john@test.local',
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';
            $_POST['current_password'] = 'old-password-123';
            $_POST['password'] = 'brand-new-password-1';
            $_POST['password_confirmation'] = 'brand-new-password-1';

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                self::assertEquals('/admin/settings/users/profile', $e->getUrl());
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('success', $flash['type'] ?? '');
            }
        }

        public function test_update_profile_with_image_upload_success(): void
        {
            $this->mockDatabaseForUser(['id' => 10, 'email' => 'john@test.local']);
            SessionManager::set('auth.user', ['id' => 10, 'email' => 'john@test.local']);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';

            // Use 'upload_test_' so the move_uploaded_file mock returns true
            $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test_');
            file_put_contents($tmpFile, "\x89PNG\r\n\x1a\n"); // Fake PNG signature

            $_FILES['profile_image'] = [
                'name' => 'test.png',
                'type' => 'image/png',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ];

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('Your profile has been updated successfully.', $flash['message'] ?? '');
                self::assertEquals('success', $flash['type'] ?? '');

                // Verify session was updated (lines 232-234)
                $authData = SessionManager::get('auth.user');
                $authData = is_array($authData) ? $authData : [];
                self::assertArrayHasKey('profile_image', $authData);
                self::assertStringContainsString('profile/', is_string($authData['profile_image'] ?? null) ? $authData['profile_image'] : '');
            } finally {
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        }

        public function test_update_profile_with_image_upload_fails_move(): void
        {
            $this->mockDatabaseForUser(['id' => 10, 'email' => 'john@test.local']);
            SessionManager::set('auth.user', ['id' => 10, 'email' => 'john@test.local']);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['first_name'] = 'John';
            $_POST['last_name'] = 'Doe';

            $tmpFile = tempnam(sys_get_temp_dir(), 'fail_move_');
            file_put_contents($tmpFile, "\x89PNG\r\n\x1a\n"); // Fake PNG signature

            $_FILES['profile_image'] = [
                'name' => 'test.png',
                'type' => 'image/png',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ];

            try {
                $this->router->dispatch('POST', '/admin/settings/users/profile');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('Could not upload profile image.', $flash['message'] ?? '');
                self::assertEquals('error', $flash['type'] ?? '');
            } finally {
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        }

        public function test_show_verify_email_renders(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET['token'] = 'dummy_token';

            $result = $this->router->dispatch('GET', '/verify-email');
            self::assertIsString($result);
            self::assertStringContainsString('dummy_token', $result);
        }

        /**
         * @param  array<string, mixed>  $payloadArray
         */
        private function generateFakeToken(array $payloadArray): string
        {
            $payload = json_encode($payloadArray);
            $payloadStr = is_string($payload) ? $payload : '{}';
            $rawKey = $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!';
            $key = is_string($rawKey) ? $rawKey : 'logmonitor-secret-key-32-chars!!';
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt($payloadStr, 'aes-256-cbc', $key, 0, $iv);
            $mac = hash_hmac('sha256', $iv.$encrypted, $key);

            return base64_encode($iv.$encrypted.$mac);
        }

        public function test_verify_email_expired_token(): void
        {
            $token = $this->generateFakeToken([
                'id' => 1,
                'email' => 'test@example.local',
                'expires_at' => time() - 3600, // Expired
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('The verification link has expired.', $flash['message'] ?? '');
            }
        }

        public function test_verify_email_mismatch_email(): void
        {
            $token = $this->generateFakeToken([
                'id' => 1,
                'email' => 'test@example.local',
                'expires_at' => time() + 3600,
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'wrong@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('The email address does not match the token.', $flash['message'] ?? '');
            }
        }

        public function test_verify_email_success(): void
        {
            $this->mockDatabaseForUser(null);
            SessionManager::set('auth.user', ['id' => 1]);

            $token = $this->generateFakeToken([
                'id' => 1,
                'email' => 'test@example.local',
                'expires_at' => time() + 3600,
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertEquals('Your email has been verified.', $flash['message'] ?? '');

                $sessionUser = SessionManager::get('auth.user');
                $sessionUser = is_array($sessionUser) ? $sessionUser : [];
                self::assertNotNull($sessionUser['email_verified_at'] ?? null);
            }
        }

        public function test_verify_email_short_token(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = base64_encode('short');

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertStringContainsString('token is invalid', is_string($flash['message'] ?? null) ? $flash['message'] : '');
            }
        }

        public function test_verify_email_not_array_payload(): void
        {
            $payload = '"not_an_array"';
            $rawKey = $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!';
            $key = is_string($rawKey) ? $rawKey : 'logmonitor-secret-key-32-chars!!';
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt($payload, 'aes-256-cbc', $key, 0, $iv);
            $mac = hash_hmac('sha256', $iv.$encrypted, $key);
            $token = base64_encode($iv.$encrypted.$mac);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertStringContainsString('token is invalid', is_string($flash['message'] ?? null) ? $flash['message'] : '');
            }
        }

        public function test_verify_email_invalid_hmac(): void
        {
            $payload = json_encode(['id' => 1, 'email' => 'test@example.local', 'expires_at' => time() + 3600]);
            $rawKey = $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!';
            $key = is_string($rawKey) ? $rawKey : 'logmonitor-secret-key-32-chars!!';
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt(is_string($payload) ? $payload : '{}', 'aes-256-cbc', $key, 0, $iv);
            $badMac = str_repeat('0', 64);
            $token = base64_encode($iv.$encrypted.$badMac);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertStringContainsString('token is invalid', is_string($flash['message'] ?? null) ? $flash['message'] : '');
            }
        }

        public function test_verify_email_empty_decrypted_string(): void
        {
            $rawKey = $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!';
            $key = is_string($rawKey) ? $rawKey : 'logmonitor-secret-key-32-chars!!';
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt('', 'aes-256-cbc', $key, 0, $iv);
            $mac = hash_hmac('sha256', $iv.$encrypted, $key);
            $token = base64_encode($iv.$encrypted.$mac);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertStringContainsString('token is invalid', is_string($flash['message'] ?? null) ? $flash['message'] : '');
            }
        }

        public function test_verify_email_catches_exception_in_decode(): void
        {
            global $mockOpensslDecryptThrows;
            $mockOpensslDecryptThrows = true;

            $token = $this->generateFakeToken([
                'id' => 1,
                'email' => 'test@example.local',
                'expires_at' => time() + 3600,
            ]);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['email'] = 'test@example.local';
            $_POST['token'] = $token;

            try {
                $this->router->dispatch('POST', '/verify-email');
                self::fail('Expected RedirectException');
            } catch (RedirectException $e) {
                $flash = isset($_SESSION['app_flash']) && is_array($_SESSION['app_flash']) ? $_SESSION['app_flash'] : [];
                self::assertStringContainsString('token is invalid', is_string($flash['message'] ?? null) ? $flash['message'] : '');
            } finally {
                $mockOpensslDecryptThrows = false;
            }
        }
    }
}
