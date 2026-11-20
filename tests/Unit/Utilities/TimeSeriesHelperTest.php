<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Core\MySQLWrapper;
use App\Utilities\TimeSeriesHelper;
use DateTime;
use InvalidArgumentException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class TimeSeriesHelperTest
 *
 * Unit tests for TimeSeriesHelper.
 */
class TimeSeriesHelperTest extends TestCase
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

    private function getStmtMock(mixed $fetchAllResult): PDOStatement&MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($fetchAllResult);

        return $stmt;
    }

    public function test_build_periods_returns_all_six_periods(): void
    {
        $dailyRows = [
            ['period_key' => '2026-10-24', 'count_val' => '15'],
            ['period_key' => '2026-10-20', 'count_val' => 5],
            ['period_key' => '', 'count_val' => 10], // empty key ignored
            'not_an_array',
        ];
        $monthlyRows = [
            ['period_key' => '2026-10', 'count_val' => '100'],
            ['period_key' => '2026-09', 'count_val' => 80],
            ['period_key' => '2026-08', 'count_val' => 70],
            ['period_key' => '2026-01', 'count_val' => 50],
            ['period_key' => '2025-12', 'count_val' => 60],
            ['period_key' => '2025-06', 'count_val' => 40],
        ];
        $yearlyRows = [
            ['period_key' => '2026', 'count_val' => '500'],
            ['period_key' => '2025', 'count_val' => 450],
            ['period_key' => '2024', 'count_val' => 300],
        ];

        $dailyStmt = $this->getStmtMock($dailyRows);
        $monthlyStmt = $this->getStmtMock($monthlyRows);
        $yearlyStmt = $this->getStmtMock($yearlyRows);

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

        $refDate = new DateTime('2026-10-24 15:30:00');
        $result = TimeSeriesHelper::buildPeriods('analytics_page_views', 'created_at', '1=1', [], $refDate, $this->dbMock);

        self::assertArrayHasKey('days', $result);
        self::assertArrayHasKey('months', $result);
        self::assertArrayHasKey('quarters', $result);
        self::assertArrayHasKey('semesters', $result);
        self::assertArrayHasKey('years', $result);
        self::assertArrayHasKey('ytd', $result);

        // 1. Days check (30 points)
        self::assertEquals(__('Days'), $result['days']['label']);
        self::assertCount(30, $result['days']['labels']);
        self::assertCount(30, $result['days']['data']);
        self::assertEquals('24 Oct', end($result['days']['labels']));
        self::assertEquals(15, end($result['days']['data']));

        // 2. Months check (12 points)
        self::assertEquals(__('Months'), $result['months']['label']);
        self::assertCount(12, $result['months']['labels']);
        self::assertCount(12, $result['months']['data']);
        self::assertEquals('Oct 2026', end($result['months']['labels']));
        self::assertEquals(100, end($result['months']['data']));

        // 3. Quarters check (8 points)
        self::assertEquals(__('Quarters'), $result['quarters']['label']);
        self::assertCount(8, $result['quarters']['labels']);
        self::assertCount(8, $result['quarters']['data']);
        self::assertEquals('Q4 2026', end($result['quarters']['labels']));
        // Q4 2026 contains month 10 (100)
        self::assertEquals(100, end($result['quarters']['data']));

        // 4. Semesters check (4 points)
        self::assertEquals(__('Semesters'), $result['semesters']['label']);
        self::assertCount(4, $result['semesters']['labels']);
        self::assertCount(4, $result['semesters']['data']);
        self::assertEquals('S2 2026', end($result['semesters']['labels']));
        // S2 2026 contains month 8 (70) + month 9 (80) + month 10 (100) = 250
        self::assertEquals(250, end($result['semesters']['data']));

        // 5. Years check (5 points)
        self::assertEquals(__('Years'), $result['years']['label']);
        self::assertCount(5, $result['years']['labels']);
        self::assertCount(5, $result['years']['data']);
        self::assertEquals('2026', end($result['years']['labels']));
        self::assertEquals(500, end($result['years']['data']));

        // 6. YTD check (10 points in Oct)
        self::assertEquals(__('YTD'), $result['ytd']['label']);
        self::assertCount(10, $result['ytd']['labels']);
        self::assertCount(10, $result['ytd']['data']);
        self::assertEquals('Oct 2026', end($result['ytd']['labels']));
        self::assertEquals(100, end($result['ytd']['data']));
    }

    public function test_build_periods_semester_one(): void
    {
        $stmt = $this->getStmtMock([]);

        $this->dbMock->expects($this->exactly(3))
            ->method('query')
            ->willReturn($stmt);

        // Reference date in Semester 1 (e.g. March 15)
        $refDate = new DateTime('2026-03-15 10:00:00');
        $result = TimeSeriesHelper::buildPeriods('users', 'created_at', 'status = ?', ['active'], $refDate);

        self::assertCount(4, $result['semesters']['labels']);
        self::assertEquals('S1 2026', end($result['semesters']['labels']));
        self::assertCount(3, $result['ytd']['labels']); // Jan, Feb, Mar
    }

    public function test_build_periods_with_null_ref_date(): void
    {
        $stmt = $this->getStmtMock([]);
        $this->dbMock->expects($this->exactly(3))
            ->method('query')
            ->willReturn($stmt);

        $resultDefault = TimeSeriesHelper::buildPeriods('users');
        self::assertCount(30, $resultDefault['days']['labels']);
        self::assertCount(12, $resultDefault['months']['labels']);
    }

    public function test_invalid_table_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name');

        TimeSeriesHelper::buildPeriods('users; DROP TABLE users;');
    }

    public function test_invalid_date_column_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date column name');

        TimeSeriesHelper::buildPeriods('users', 'created_at; DROP TABLE users;');
    }
}
