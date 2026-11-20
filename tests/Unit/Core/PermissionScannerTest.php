<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\MySQLWrapper;
use App\Core\PermissionScanner;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class PermissionScannerTest
 *
 * @category Tests
 */
class PermissionScannerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(MySQLWrapper::class);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, null);

        parent::tearDown();
    }

    public function test_scan_returns_array_of_permissions(): void
    {
        $permissions = PermissionScanner::scan();

        // Assert the structure of at least the first permission if not empty
        if (count($permissions) > 0) {
            $first = $permissions[0];
            self::assertArrayHasKey('permission_key', $first);
            self::assertArrayHasKey('name', $first);
            self::assertArrayHasKey('group_name', $first);
        }
    }

    public function test_sync_to_database_creates_missing_permissions(): void
    {
        // First, get the actual permissions to know how many times things will be called
        $permissions = PermissionScanner::scan();
        $permissionsCount = count($permissions);

        $stmtRolesMock = $this->createMock(PDOStatement::class);
        $stmtRolesMock->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['id' => 2, 'name' => 'EDITOR'],
                ['id' => 3, 'name' => 'VIEWER'],
            ]);

        $stmtCheckMock = $this->createMock(PDOStatement::class);
        // We simulate that NONE of the permissions exist, so fetch() returns false
        $stmtCheckMock->expects($this->exactly($permissionsCount * 2))
            ->method('fetch')
            ->willReturn(false);

        $this->dbMock->expects($this->exactly(1 + ($permissionsCount * 2)))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use ($stmtRolesMock, $stmtCheckMock) {
                if (str_contains($sql, 'FROM roles')) {
                    return $stmtRolesMock;
                }

                if (str_contains($sql, 'FROM role_permissions')) {
                    return $stmtCheckMock;
                }

                return $this->createMock(PDOStatement::class);
            });

        // create should be called exactly once per role per permission
        $this->dbMock->expects($this->exactly($permissionsCount * 2))
            ->method('create')
            ->willReturnCallback(function (string $table, array $data) {
                self::assertEquals('role_permissions', $table);
                self::assertArrayHasKey('role_id', $data);
                self::assertArrayHasKey('permission_key', $data);
                self::assertEquals(0, $data['value']); // Security default must be false (0)

                return 1;
            });

        PermissionScanner::syncToDatabase();
    }

    public function test_sync_to_database_skips_existing_permissions(): void
    {
        $permissions = PermissionScanner::scan();
        $permissionsCount = count($permissions);

        $stmtRolesMock = $this->createMock(PDOStatement::class);
        $stmtRolesMock->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['id' => 2, 'name' => 'EDITOR'],
            ]);

        $stmtCheckMock = $this->createMock(PDOStatement::class);
        // We simulate that ALL permissions exist, so fetch() returns an array
        $stmtCheckMock->expects($this->exactly($permissionsCount))
            ->method('fetch')
            ->willReturn(['id' => 99]);

        $this->dbMock->expects($this->exactly(1 + $permissionsCount))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use ($stmtRolesMock, $stmtCheckMock) {
                if (str_contains($sql, 'FROM roles')) {
                    return $stmtRolesMock;
                }

                if (str_contains($sql, 'FROM role_permissions')) {
                    return $stmtCheckMock;
                }

                return $this->createMock(PDOStatement::class);
            });

        // create should never be called
        $this->dbMock->expects($this->never())->method('create');

        PermissionScanner::syncToDatabase();
    }

    public function test_get_class_name_from_file_returns_null_on_invalid_path(): void
    {
        $reflection = new ReflectionClass(PermissionScanner::class);
        $method = $reflection->getMethod('getClassNameFromFile');

        $result = $method->invoke(null, '/non/existent/path/file.php');
        self::assertNull($result);
    }
}
