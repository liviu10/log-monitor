<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\RolePermission;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Class RolePermissionTest
 *
 * Unit tests for the RolePermission Model.
 * Uses a mocked MySQLWrapper to avoid actual database dependency.
 *
 * @category Tests
 */
class RolePermissionTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    /** @var PDO&MockObject */
    private PDO $pdoMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $this->pdoMock = $this->createMock(PDO::class);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        parent::tearDown();
    }

    /**
     * @param  array<int|string, mixed>  $fetchAllResult
     */
    private function getFetchAllMock(array $fetchAllResult): PDOStatement&MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($fetchAllResult);

        return $stmt;
    }

    public function test_is_granted_returns_true_if_record_found(): void
    {
        $this->dbMock->expects($this->once())
            ->method('read')
            ->with('role_permissions', [
                'role_id' => 1,
                'permission_key' => 'admin.dashboard.index',
                'value' => 1,
            ])
            ->willReturn([['id' => 1]]); // returns array with 1 element

        self::assertTrue(RolePermission::isGranted(1, 'admin.dashboard.index'));
    }

    public function test_is_granted_returns_false_if_no_record_found(): void
    {
        $this->dbMock->expects($this->once())
            ->method('read')
            ->willReturn([]);

        self::assertFalse(RolePermission::isGranted(2, 'admin.settings.index'));
    }

    public function test_is_granted_returns_false_on_exception(): void
    {
        $this->dbMock->expects($this->once())
            ->method('read')
            ->willThrowException(new RuntimeException('Database connection lost'));

        self::assertFalse(RolePermission::isGranted(1, 'admin.dashboard.index'));
    }

    public function test_get_by_role_id_returns_array(): void
    {
        $expected = [
            ['id' => 1, 'role_id' => 5, 'permission_key' => 'access'],
            ['id' => 2, 'role_id' => 5, 'permission_key' => 'edit'],
        ];

        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                'SELECT * FROM role_permissions WHERE role_id = ? ORDER BY group_name ASC, permission_key ASC',
                [5]
            )
            ->willReturn($stmt);

        $result = RolePermission::getByRoleId(5);
        self::assertEquals($expected, $result);
    }

    public function test_update_bulk_executes_in_transaction_and_commits(): void
    {
        $this->dbMock->method('getConnection')->willReturn($this->pdoMock);

        $this->pdoMock->expects($this->once())->method('beginTransaction');

        // We have 2 permissions to update
        $permissions = [
            10 => true,
            15 => false,
        ];

        $updateCallCount = 0;

        $this->dbMock->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function (mixed $table, mixed $data, mixed $conditions) use (&$updateCallCount): int {
                self::assertEquals('role_permissions', $table);
                if (is_array($data) && is_array($conditions)) {
                    self::assertArrayHasKey('updated_at', $data);
                    self::assertEquals(3, $conditions['role_id']);

                    if ($updateCallCount === 0) {
                        self::assertEquals(1, $data['value']);
                        self::assertEquals(10, $conditions['id']);
                    } else {
                        self::assertEquals(0, $data['value']);
                        self::assertEquals(15, $conditions['id']);
                    }
                }

                $updateCallCount++;

                return 1;
            });

        $this->pdoMock->expects($this->once())->method('commit');
        $this->pdoMock->expects($this->never())->method('rollBack');

        RolePermission::updateBulk(3, $permissions);
    }

    public function test_update_bulk_rolls_back_and_throws_on_exception(): void
    {
        $this->dbMock->method('getConnection')->willReturn($this->pdoMock);

        $this->pdoMock->expects($this->once())->method('beginTransaction');

        $this->dbMock->expects($this->once())
            ->method('update')
            ->willThrowException(new RuntimeException('SQL Syntax Error'));

        $this->pdoMock->expects($this->never())->method('commit');
        $this->pdoMock->expects($this->once())->method('rollBack');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQL Syntax Error');

        RolePermission::updateBulk(3, [10 => true]);
    }
}
