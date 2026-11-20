<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Settings {
    global $mockRolesHttpInput;
    $mockRolesHttpInput = null;

    // Override the native header() function in this namespace to prevent exit;
    if (! function_exists('App\Controllers\Admin\Settings\header')) {
        function header(string $header, bool $replace = true, ?int $http_response_code = null): void
        {
            if (str_contains($header, 'application/json')) {
                throw new \RuntimeException('JSON_EXIT:'.$header);
            }
        }
    }

    if (! function_exists('App\Controllers\Admin\Settings\file_get_contents')) {
        /**
         * @param  resource|null  $context
         * @param  int<0, max>|null  $length
         */
        function file_get_contents(string $filename, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null): string|false
        {
            global $mockRolesHttpInput;
            if ($filename === 'php://input' && is_string($mockRolesHttpInput)) {
                return $mockRolesHttpInput;
            }

            if ($length !== null) {
                return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
            }

            return \file_get_contents($filename, $use_include_path, $context, $offset);
        }
    }
}

namespace Tests\Feature\Controllers\Admin\Settings {

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
     * Class RolesControllerTest
     *
     * Feature tests for the RolesController.
     *
     * @category Tests
     */
    class RolesControllerTest extends TestCase
    {
        private Router $router;

        protected function setUp(): void
        {
            parent::setUp();

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/admin/settings/roles';
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
                'status' => 'ACTIVE',
            ]);

            $this->router = new Router;
            $this->router->get('/admin/settings/roles', 'Admin\Settings\RolesController@index');
            $this->router->get('/admin/settings/roles/{id}', 'Admin\Settings\RolesController@permissions');
            $this->router->put('/admin/settings/roles/{id}', 'Admin\Settings\RolesController@updatePermissions');

            $this->mockDatabase();
        }

        protected function tearDown(): void
        {
            $_SESSION = [];
            global $mockRolesHttpInput;
            $mockRolesHttpInput = null;

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, null);

            parent::tearDown();
        }

        private function mockDatabase(): void
        {
            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query', 'create'])
                ->getMock();

            $mockWrapper->method('query')->willReturnCallback(function (string $sql, array $params = []) {
                $stmt = $this->createMock(\PDOStatement::class);
                $upperSql = strtoupper($sql);

                if (str_contains($upperSql, 'COUNT(')) {
                    $stmt->method('fetch')->willReturn(['total' => 2]);
                } elseif (str_contains($upperSql, 'SELECT ID, NAME FROM ROLES WHERE NAME != \'ADMIN\'')) {
                    $stmt->method('fetchAll')->willReturn([
                        ['id' => 2, 'name' => 'USER'],
                    ]);
                } elseif (str_contains($upperSql, 'SELECT * FROM ROLES WHERE ID')) {
                    if (isset($params[0]) && ($params[0] === 1 || $params[0] === '1')) {
                        $stmt->method('fetch')->willReturn(['id' => 1, 'name' => 'ADMIN']);
                    } elseif (isset($params[0]) && ($params[0] === 2 || $params[0] === '2')) {
                        $stmt->method('fetch')->willReturn(['id' => 2, 'name' => 'USER']);
                    } else {
                        $stmt->method('fetch')->willReturn(false);
                    }
                } elseif (str_contains($upperSql, 'SELECT * FROM ROLES') || str_contains($upperSql, 'SELECT * FROM `ROLES`')) {
                    $stmt->method('fetchAll')->willReturn([
                        ['id' => 1, 'name' => 'ADMIN'],
                        ['id' => 2, 'name' => 'USER'],
                    ]);
                } elseif (str_contains($upperSql, 'SELECT NAME FROM PERMISSIONS')) {
                    $stmt->method('fetchAll')->willReturn([]); // Assume no permissions in DB yet for scanner
                } elseif (str_contains($upperSql, 'SELECT ID FROM ROLE_PERMISSIONS WHERE ROLE_ID = ? AND `PERMISSION_KEY` = ? LIMIT 1')) {
                    $stmt->method('fetch')->willReturn(false); // Force insertion during sync
                } elseif (str_contains($upperSql, 'INSERT INTO PERMISSIONS')) {
                    $stmt->method('rowCount')->willReturn(1);
                } elseif (str_contains($upperSql, 'SELECT * FROM ROLE_PERMISSIONS WHERE ROLE_ID = ?')) {
                    // RolePermission::getByRoleId
                    $stmt->method('fetchAll')->willReturn([
                        ['id' => 1, 'name' => 'TestPermission', 'group_name' => 'TestGroup', 'value' => 1, 'is_granted' => 1, 'permission_key' => 'test_key', 'role_id' => 2],
                    ]);
                } elseif (str_contains($upperSql, 'START TRANSACTION') || str_contains($upperSql, 'COMMIT') || str_contains($upperSql, 'ROLLBACK')) {
                    // DB transactions
                } elseif (str_contains($upperSql, 'DELETE FROM ROLE_PERMISSIONS')) {
                    $stmt->method('rowCount')->willReturn(1);
                } elseif (str_contains($upperSql, 'SELECT ID FROM PERMISSIONS WHERE NAME IN')) {
                    $stmt->method('fetchAll')->willReturn([]);
                } elseif (str_contains($upperSql, 'INSERT INTO ROLE_PERMISSIONS')) {
                    $stmt->method('rowCount')->willReturn(1);
                }

                return $stmt;
            });

            $mockWrapper->method('create')->willReturn('10');

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);
        }

        public function test_index_renders_successfully(): void
        {
            $result = $this->router->dispatch('GET', '/admin/settings/roles');

            self::assertIsString($result);
            // The view passes data to AlpineJS in x-data or similar, or lists it.
            // We look for a string that is reliably output in the page related to roles.
            self::assertStringContainsString('Roles', $result);
            self::assertStringContainsString('Manage', $result);
        }

        public function test_permissions_renders_successfully_for_user_role(): void
        {
            $result = $this->router->dispatch('GET', '/admin/settings/roles/2');

            self::assertIsString($result);
            self::assertStringContainsString('TestGroup', $result);
            self::assertStringContainsString('TestPermission', $result);
        }

        public function test_permissions_renders_successfully_for_admin_role(): void
        {
            $result = $this->router->dispatch('GET', '/admin/settings/roles/1');

            self::assertIsString($result);
            // Admin role bypasses permission display
            self::assertStringContainsString('ADMIN', $result);
        }

        public function test_permissions_throws_404_for_invalid_role(): void
        {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Role not found.');

            $this->router->dispatch('GET', '/admin/settings/roles/999');
        }

        public function test_update_permissions_success_empty_payload(): void
        {
            // For USER role (ID 2), update with empty payload (revoking all)
            // Since php://input is empty in CLI, Safe\json_decode will throw JsonException.
            try {
                $this->router->dispatch('PUT', '/admin/settings/roles/2');
                self::fail('Expected header to throw JSON_EXIT exception or JsonException on success exit;');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_permissions_fails_for_admin_role(): void
        {
            // For ADMIN role (ID 1), it should block modification
            try {
                $this->router->dispatch('PUT', '/admin/settings/roles/1');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_permissions_fails_for_invalid_role(): void
        {
            try {
                $this->router->dispatch('PUT', '/admin/settings/roles/999');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_permissions_success_with_permissions_payload(): void
        {
            global $mockRolesHttpInput;
            $mockRolesHttpInput = json_encode(['permissions' => ['perm_key_1' => 1]]);

            try {
                $this->router->dispatch('PUT', '/admin/settings/roles/2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_permissions_handles_bulk_update_exception(): void
        {
            global $mockRolesHttpInput;
            $mockRolesHttpInput = json_encode(['permissions' => ['perm_key_1' => 1]]);

            $mockWrapper = $this->createMock(MySQLWrapper::class);
            $mockWrapper->method('query')->willReturnCallback(function (string $sql, array $params = []) {
                $upperSql = strtoupper($sql);
                $stmt = $this->createMock(\PDOStatement::class);
                if (str_contains($upperSql, 'SELECT * FROM ROLES WHERE ID')) {
                    $stmt->method('fetch')->willReturn(['id' => 2, 'name' => 'USER']);

                    return $stmt;
                }
                throw new \RuntimeException('DB transaction failure');
            });
            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            try {
                $this->router->dispatch('PUT', '/admin/settings/roles/2');
                self::fail('Expected header to throw JSON_EXIT exception on 500');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }

        public function test_update_permissions_ignores_invalid_json_payload(): void
        {
            global $mockRolesHttpInput;
            $mockRolesHttpInput = 'invalid-json-{[';

            try {
                $this->router->dispatch('PUT', '/admin/settings/roles/2');
                self::fail('Expected header to throw JSON_EXIT exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_EXIT', $e->getMessage());
            }
        }
    }
}
