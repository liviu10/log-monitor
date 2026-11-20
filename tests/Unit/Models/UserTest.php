<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\User;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class UserTest
 *
 * Unit tests for the User Model.
 * Uses a mocked MySQLWrapper to avoid actual database dependency.
 *
 * @category Tests
 */
class UserTest extends TestCase
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

    public function test_find_by_email_returns_array_when_found(): void
    {
        $expected = ['id' => 1, 'email' => 'test@example.com', 'role_name' => 'ADMIN'];
        $stmt = $this->getFetchMock($expected);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                'SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? LIMIT 1',
                ['test@example.com']
            )
            ->willReturn($stmt);

        $result = User::findByEmail('test@example.com');
        self::assertEquals($expected, $result);
    }

    public function test_find_by_email_returns_null_when_not_found(): void
    {
        $stmt = $this->getFetchMock(false);
        $this->dbMock->method('query')->willReturn($stmt);

        self::assertNull(User::findByEmail('notfound@example.com'));
    }

    public function test_update_profile_returns_boolean(): void
    {
        $this->dbMock->expects($this->once())
            ->method('update')
            ->with(
                'users',
                self::callback(function (mixed $data): bool {
                    return is_array($data)
                        && ($data['first_name'] ?? null) === 'John'
                        && ($data['last_name'] ?? null) === 'Doe'
                        && ($data['email'] ?? null) === 'john@example.com'
                        && isset($data['updated_at']);
                }),
                ['id' => 5]
            )
            ->willReturn(1); // 1 row updated

        $result = User::updateProfile(5, [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        self::assertTrue($result);
    }

    public function test_update_email_verified_at_handles_empty_string_as_null(): void
    {
        $this->dbMock->expects($this->once())
            ->method('update')
            ->with(
                'users',
                self::callback(function (mixed $data): bool {
                    return is_array($data)
                        && array_key_exists('email_verified_at', $data)
                        && $data['email_verified_at'] === null
                        && isset($data['updated_at']);
                }),
                ['id' => 10]
            )
            ->willReturn(1);

        self::assertTrue(User::updateEmailVerifiedAt(10, ''));
    }

    public function test_update_email_verified_at_handles_valid_date(): void
    {
        $validDate = '2023-11-11 10:00:00';

        $this->dbMock->expects($this->once())
            ->method('update')
            ->with(
                'users',
                self::callback(function (mixed $data) use ($validDate): bool {
                    return is_array($data)
                        && ($data['email_verified_at'] ?? null) === $validDate
                        && isset($data['updated_at']);
                }),
                ['id' => 15]
            )
            ->willReturn(1);

        self::assertTrue(User::updateEmailVerifiedAt(15, $validDate));
    }

    public function test_get_all_with_roles_returns_array(): void
    {
        $expected = [
            ['id' => 2, 'email' => 'user1@example.com', 'role_name' => 'USER'],
            ['id' => 1, 'email' => 'admin@example.com', 'role_name' => 'ADMIN'],
        ];

        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC')
            ->willReturn($stmt);

        $result = User::getAllWithRoles();
        self::assertEquals($expected, $result);
    }

    public function test_find_by_id_returns_array_when_found(): void
    {
        $expected = ['id' => 7, 'email' => 'find@example.com', 'role_name' => 'EDITOR'];
        $stmt = $this->getFetchMock($expected);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                'SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? LIMIT 1',
                [7]
            )
            ->willReturn($stmt);

        $result = User::findById(7);
        self::assertEquals($expected, $result);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $stmt = $this->getFetchMock(false);
        $this->dbMock->method('query')->willReturn($stmt);

        self::assertNull(User::findById(999));
    }

    public function test_create_adds_created_at_and_returns_id(): void
    {
        $this->dbMock->expects($this->once())
            ->method('create')
            ->with('users', self::callback(function (mixed $data): bool {
                return is_array($data) && ($data['email'] ?? null) === 'new@example.com' && isset($data['created_at']);
            }))
            ->willReturn(50);

        $result = User::create(['email' => 'new@example.com']);
        self::assertEquals(50, $result);
    }

    public function test_update_user_adds_updated_at_and_returns_boolean(): void
    {
        $this->dbMock->expects($this->once())
            ->method('update')
            ->with('users', self::callback(function (mixed $data): bool {
                return is_array($data) && ($data['status'] ?? null) === 'active' && isset($data['updated_at']);
            }), ['id' => 20])
            ->willReturn(1);

        self::assertTrue(User::updateUser(20, ['status' => 'active']));
    }

    public function test_dashboard_summary_users_returns_formatted_array_excluding_admins(): void
    {
        $stmt = $this->getFetchMock(['total' => '25']);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with("SELECT COUNT(u.id) as total FROM users u JOIN roles r ON u.role_id = r.id WHERE UPPER(r.name) NOT IN ('ADMIN', 'ADMINISTRATOR')")
            ->willReturn($stmt);

        $result = User::dashboardSummaryUsers();
        self::assertArrayHasKey('title', $result);
        self::assertEquals(25, $result['value']);
        self::assertEquals('fa-solid fa-users', $result['icon']);
        self::assertEquals('primary', $result['color']);
        self::assertEquals('/admin/settings/users', $result['url']);
    }

    public function test_dashboard_chart_users_returns_doughnut_dataset_excluding_admins(): void
    {
        $rows = [
            ['role_name' => 'USER', 'count' => '15'],
            ['role_name' => 'EDITOR', 'count' => '5'],
        ];
        $stmt = $this->getFetchAllMock($rows);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with("SELECT r.name as role_name, COUNT(u.id) as count FROM roles r LEFT JOIN users u ON r.id = u.role_id WHERE UPPER(r.name) NOT IN ('ADMIN', 'ADMINISTRATOR') GROUP BY r.id, r.name")
            ->willReturn($stmt);

        $result = User::dashboardChartUsers();
        self::assertEquals('chart_users_by_role', $result['id']);
        self::assertEquals('doughnut', $result['type']);
        self::assertIsArray($result['labels']);
        self::assertIsArray($result['datasets']);
        self::assertCount(1, $result['datasets']);

        $firstDataset = $result['datasets'][0] ?? [];
        self::assertIsArray($firstDataset);
        self::assertEquals([15, 5], $firstDataset['data'] ?? []);
    }

    public function test_dashboard_chart_users_trend_returns_line_dataset_with_periods(): void
    {
        $todayStr = date('Y-m-d');
        $thisMonthStr = date('Y-m');
        $thisYearStr = date('Y');

        $dailyStmt = $this->getFetchAllMock([
            ['period_key' => $todayStr, 'count_val' => '8'],
        ]);
        $monthlyStmt = $this->getFetchAllMock([
            ['period_key' => $thisMonthStr, 'count_val' => '35'],
        ]);
        $yearlyStmt = $this->getFetchAllMock([
            ['period_key' => $thisYearStr, 'count_val' => '120'],
        ]);

        $this->dbMock->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql) use ($dailyStmt, $monthlyStmt, $yearlyStmt) {
                if (str_contains($sql, 'DATE(')) {
                    return $dailyStmt;
                }
                if (str_contains($sql, 'DATE_FORMAT(')) {
                    return $monthlyStmt;
                }

                return $yearlyStmt;
            });

        $result = User::dashboardChartUsersTrend();

        self::assertEquals('chart_users_registration_trend', $result['id']);
        self::assertEquals(__('Users Registration Trend'), $result['title']);
        self::assertEquals('line', $result['type']);
        self::assertEquals('months', $result['activePeriod']);
        self::assertIsArray($result['periods']);
        self::assertCount(6, $result['periods']);

        self::assertIsArray($result['labels']);
        self::assertCount(12, $result['labels']);
        self::assertIsArray($result['datasets']);
        self::assertCount(1, $result['datasets']);

        $dataset = $result['datasets'][0] ?? [];
        self::assertIsArray($dataset);
        $dataArray = $dataset['data'] ?? [];
        self::assertIsArray($dataArray);
        self::assertCount(12, $dataArray);
        self::assertEquals(35, end($dataArray));
    }
}
