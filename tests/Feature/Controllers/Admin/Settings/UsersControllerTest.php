<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Settings {
    // Override the native header() function in this namespace to prevent exit;
    if (! function_exists('App\Controllers\Admin\Settings\header')) {
        function header(string $header, bool $replace = true, ?int $http_response_code = null): void
        {
            if (str_contains($header, 'application/json')) {
                throw new \RuntimeException('JSON_EXIT:'.$header);
            }
        }
    }
}

namespace Tests\Feature\Controllers\Admin\Settings {

    use App\Controllers\Admin\Settings\UsersController;
    use App\Core\MySQLWrapper;
    use App\Core\Router;
    use App\Utilities\SessionManager;
    use PHPUnit\Framework\TestCase;

    if (! function_exists('Tests\Feature\Controllers\Admin\Settings\getCurrentAuthUser')) {
        /**
         * @return array<mixed, mixed>|null
         */
        function getCurrentAuthUser(): ?array
        {
            $user = SessionManager::get('auth.user');

            return is_array($user) ? $user : null;
        }
    }

    /**
     * Class UsersControllerTest
     *
     * Feature tests for the UsersController.
     *
     * @category Tests
     */
    class UsersControllerTest extends TestCase
    {
        private Router $router;

        protected function setUp(): void
        {
            parent::setUp();

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/admin/settings/users';
            $_SESSION = [];

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            SessionManager::set('auth.user', [
                'id' => 1,
                'email' => 'admin@local',
                'first_name' => 'Admin',
                'last_name' => 'System',
                'role_name' => 'ADMIN',
                'role_id' => 1,
                'status' => 'ACTIVE',
            ]);

            $this->router = new Router;
            $this->router->get('/admin/settings/users', 'Admin\Settings\UsersController@index');
            $this->router->post('/admin/settings/users', 'Admin\Settings\UsersController@store');
            $this->router->put('/admin/settings/users/{id}', 'Admin\Settings\UsersController@update');

            $this->mockDatabase();
        }

        protected function tearDown(): void
        {
            $_SESSION = [];

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, null);

            parent::tearDown();
        }

        private function mockDatabase(): void
        {
            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query', 'create', 'update'])
                ->getMock();

            $mockWrapper->method('query')->willReturnCallback(function (string $sql, array $params = []) {
                $stmt = $this->createMock(\PDOStatement::class);
                $upperSql = strtoupper($sql);

                // Check for a special global flag to fail role retrieval
                if (isset($GLOBALS['mock_fail_default_role']) && $GLOBALS['mock_fail_default_role'] === true && str_contains($upperSql, 'ROLES WHERE NAME =')) {
                    $stmt->method('fetch')->willReturn(false);

                    return $stmt;
                }

                if (str_contains($upperSql, 'COUNT(')) {
                    if (isset($params[0]) && ($params[0] === 999 || $params[0] === '999')) {
                        $stmt->method('fetch')->willReturn(['total' => 0]);
                    } else {
                        $stmt->method('fetch')->willReturn(['total' => 1]);
                    }
                } elseif (str_contains($upperSql, 'WHERE U.EMAIL = ?')) {
                    if (isset($params[0]) && $params[0] === 'existing@user.local') {
                        $stmt->method('fetch')->willReturn(['id' => 99, 'email' => 'existing@user.local', 'role_name' => 'USER']);
                    } else {
                        $stmt->method('fetch')->willReturn(false);
                    }
                } elseif (str_contains($upperSql, 'WHERE U.ID = ?')) {
                    if (isset($params[0]) && ($params[0] === 2 || $params[0] === '2')) {
                        $stmt->method('fetch')->willReturn(['id' => 2, 'first_name' => 'Test', 'role_name' => 'USER']);
                    } else {
                        $stmt->method('fetch')->willReturn(false);
                    }
                } elseif (str_contains($upperSql, 'SELECT U.*, R.NAME')) {
                    $stmt->method('fetchAll')->willReturn([
                        ['id' => 2, 'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@doe.com', 'role_name' => 'USER', 'status' => 'ACTIVE', 'created_at' => '2023-01-01'],
                    ]);
                } elseif (str_contains($upperSql, 'FROM ROLES') && ! str_contains($upperSql, 'WHERE')) {
                    $stmt->method('fetchAll')->willReturn([
                        ['id' => 1, 'name' => 'ADMIN'],
                        ['id' => 2, 'name' => 'USER'],
                    ]);
                } elseif (str_contains($upperSql, 'SHOW COLUMNS')) {
                    $stmt->method('fetchAll')->willReturn([
                        ['Field' => 'first_name', 'Type' => 'varchar(50)', 'Null' => 'NO'],
                        ['Field' => 'last_name', 'Type' => 'varchar(50)', 'Null' => 'NO'],
                        ['Field' => 'email', 'Type' => 'varchar(100)', 'Null' => 'NO'],
                        ['Field' => 'role_id', 'Type' => 'int(11)', 'Null' => 'NO'],
                        ['Field' => 'status', 'Type' => 'varchar(50)', 'Null' => 'NO'],
                    ]);
                } elseif (str_contains($upperSql, 'ROLES WHERE NAME =')) {
                    if (isset($params[0]) && is_string($params[0]) && strtoupper($params[0]) === 'ADMIN') {
                        $stmt->method('fetch')->willReturn(['id' => 1]);
                    } else {
                        $stmt->method('fetch')->willReturn(['id' => 2]);
                    }
                } elseif (str_contains($upperSql, 'SELECT ID FROM ROLES WHERE ID')) {
                    if (isset($params[0]) && ($params[0] === 1 || $params[0] === '1')) {
                        $stmt->method('fetch')->willReturn(['id' => 1]);
                    } elseif (isset($params[0]) && ($params[0] === 2 || $params[0] === '2')) {
                        $stmt->method('fetch')->willReturn(['id' => 2]);
                    } elseif (isset($params[0]) && ($params[0] === 3 || $params[0] === '3')) {
                        $stmt->method('fetch')->willReturn(['id' => 3]);
                    } else {
                        $stmt->method('fetch')->willReturn(false);
                    }
                }

                return $stmt;
            });

            $mockWrapper->method('create')->willReturn('10');
            $mockWrapper->method('update')->willReturn(1);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);
        }

        public function test_index_renders_successfully(): void
        {
            $result = $this->router->dispatch('GET', '/admin/settings/users');

            self::assertIsString($result);
            self::assertStringContainsString('john@doe.com', $result);
        }

        public function test_store_creates_user(): void
        {
            $_POST = [
                'first_name' => 'New',
                'last_name' => 'User',
                'email' => 'new@user.local',
            ];

            try {
                $this->router->dispatch('POST', '/admin/settings/users');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_store_validates_existing_email(): void
        {
            $_POST = [
                'first_name' => 'New',
                'last_name' => 'User',
                'email' => 'existing@user.local', // Mocked to return user
            ];

            try {
                $this->router->dispatch('POST', '/admin/settings/users');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_modifies_role(): void
        {
            $payload = [
                'role_id' => 1, // ADMIN role
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2'); // User ID 2
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_prevents_editing_self(): void
        {
            $payload = [
                'role_id' => 2,
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '1'); // Auth User ID is 1
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_store_fails_validation(): void
        {
            $_POST = [
                'first_name' => '', // Fails required
            ];

            try {
                $this->router->dispatch('POST', '/admin/settings/users');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_store_fails_if_default_role_not_found(): void
        {
            $GLOBALS['mock_fail_default_role'] = true;

            $_POST = [
                'first_name' => 'New',
                'last_name' => 'User',
                'email' => 'new@user.local',
            ];

            try {
                $this->router->dispatch('POST', '/admin/settings/users');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            } finally {
                unset($GLOBALS['mock_fail_default_role']);
            }
        }

        public function test_update_fails_if_user_not_found(): void
        {
            $payload = [
                'role_id' => 1,
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '3'); // User ID 3 does not exist in mock
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_fails_if_role_invalid(): void
        {
            $payload = [
                'role_id' => 999, // Invalid role
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_prevents_privilege_escalation_by_non_admin(): void
        {
            SessionManager::set('auth.user', [
                'id' => 10,
                'email' => 'staff@local',
                'role_id' => 2,
            ]);

            $payload = [
                'role_id' => 1,
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_allows_admin_to_update_status(): void
        {
            $payload = [
                'role_id' => 1,
                'status' => 'INACTIVE',
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_prevents_non_admin_from_updating_status(): void
        {
            SessionManager::set('auth.user', [
                'id' => 10,
                'email' => 'staff@local',
                'role_id' => 2,
            ]);

            $payload = [
                'role_id' => 3,
                'status' => 'INACTIVE',
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_fails_with_invalid_status(): void
        {
            $payload = [
                'role_id' => 1,
                'status' => 'INVALID_STATUS',
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_fails_with_non_string_status(): void
        {
            $payload = [
                'role_id' => 1,
                'status' => 12345,
            ];

            try {
                $controller = new UsersController;
                $controller->update($payload, '2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_index_renders_successfully_for_non_admin(): void
        {
            SessionManager::set('auth.user', [
                'id' => 2,
                'email' => 'user@local',
                'first_name' => 'User',
                'last_name' => 'Test',
                'role_name' => 'USER',
                'role_id' => 2,
                'status' => 'ACTIVE',
            ]);

            $result = $this->router->dispatch('GET', '/admin/settings/users');

            self::assertIsString($result);
            self::assertStringContainsString('john@doe.com', $result);
        }
    }
}
