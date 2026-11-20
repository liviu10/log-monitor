<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Enums\Role as RoleEnum;
use App\Models\Role;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Class RoleTest
 *
 * Unit tests for the Role Model.
 * Uses a mocked MySQLWrapper to avoid actual database dependency.
 *
 * @category Tests
 */
class RoleTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(MySQLWrapper::class);

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

    private function getFetchMock(mixed $fetchResult): PDOStatement&MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchResult);

        return $stmt;
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

    public function test_all_returns_roles_array(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'ADMIN'],
            ['id' => 2, 'name' => 'USER'],
        ];

        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT * FROM roles ORDER BY name ASC')
            ->willReturn($stmt);

        $result = Role::all();
        self::assertEquals($expected, $result);
    }

    public function test_exists_returns_true_when_found(): void
    {
        $stmt = $this->getFetchMock(['id' => 1]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT id FROM roles WHERE id = ? LIMIT 1', [1])
            ->willReturn($stmt);

        self::assertTrue(Role::exists(1));
    }

    public function test_exists_returns_false_when_not_found(): void
    {
        $stmt = $this->getFetchMock(false);
        $this->dbMock->method('query')->willReturn($stmt);

        self::assertFalse(Role::exists(999));
    }

    public function test_find_by_id_returns_array_when_found(): void
    {
        $expected = ['id' => 1, 'name' => 'ADMIN'];
        $stmt = $this->getFetchMock($expected);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT * FROM roles WHERE id = ? LIMIT 1', [1])
            ->willReturn($stmt);

        $result = Role::findById(1);
        self::assertEquals($expected, $result);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $stmt = $this->getFetchMock(false);
        $this->dbMock->method('query')->willReturn($stmt);

        self::assertNull(Role::findById(999));
    }

    public function test_delete_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);

        // We don't assert the exact message because it uses the __() translation helper
        // which might return the key or the translated string depending on test environment setup.

        Role::delete(1);
    }

    public function test_get_id_by_name_returns_integer_when_found(): void
    {
        $stmt = $this->getFetchMock(['id' => '2']); // Simulated PDO returning string for integer

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT id FROM roles WHERE name = ? LIMIT 1', [RoleEnum::USER->value])
            ->willReturn($stmt);

        self::assertSame(2, Role::getIdByName(RoleEnum::USER));
    }

    public function test_get_id_by_name_returns_null_when_not_found(): void
    {
        $stmt = $this->getFetchMock(false);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT id FROM roles WHERE name = ? LIMIT 1', [RoleEnum::ADMIN->value])
            ->willReturn($stmt);

        self::assertNull(Role::getIdByName(RoleEnum::ADMIN));
    }
}
