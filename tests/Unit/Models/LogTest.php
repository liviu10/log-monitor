<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\Log;
use InvalidArgumentException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LogTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    private Log $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
        $this->model = new Log($this->dbMock);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_create_validates_and_inserts_log(): void
    {
        $this->dbMock->expects($this->once())
            ->method('create')
            ->with('logs', self::callback(function (array $params): bool {
                return ($params['app_id'] ?? 0) === 1
                    && ($params['level'] ?? '') === 'ERROR'
                    && ($params['message'] ?? '') === 'Disk full'
                    && ($params['context'] ?? '') === '{"disk":"\/dev\/sda1"}';
            }))
            ->willReturn(77);

        $id = $this->model->create(1, 'error', '  Disk full  ', ['disk' => '/dev/sda1']);
        self::assertSame(77, $id);
    }

    public function test_create_throws_on_invalid_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->model->create(0, '', '');
    }

    public function test_get_paginated_applies_filters_and_safe_sort(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 10, 'app_id' => 2, 'app_name' => 'Portal', 'level' => 'WARNING', 'message' => 'Slow response', 'context' => null, 'created_at' => '2026-11-20 12:00:00'],
        ]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                self::callback(function (string $sql): bool {
                    return str_contains($sql, 'AND l.app_id = ?')
                        && str_contains($sql, 'AND l.level = ?')
                        && str_contains($sql, 'MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)')
                        && str_contains($sql, 'ORDER BY l.id DESC');
                }),
                [2, 'WARNING', 'Slow*']
            )
            ->willReturn($stmt);

        $rows = $this->model->getPaginated(['app_id' => 2, 'level' => 'warning', 'search' => 'Slow'], 25, 0, 'bad_col', 'INVALID');
        self::assertCount(1, $rows);
        self::assertSame('Portal', $rows[0]['app_name']);
    }

    public function test_count_and_purge_helpers(): void
    {
        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn('15');

        $stmtDelete = $this->createMock(PDOStatement::class);
        $stmtDelete->method('rowCount')->willReturn(5);

        $stmtOpt = $this->createMock(PDOStatement::class);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtCount, $stmtDelete, $stmtOpt);

        self::assertSame(15, $this->model->count(['level' => 'ERROR']));
        self::assertSame(5, $this->model->deleteBeforeDate('2026-10-01 00:00:00'));
        self::assertTrue($this->model->optimize());
    }

    public function test_get_stats_and_dashboard_summaries(): void
    {
        $stmtStats = $this->createMock(PDOStatement::class);
        $stmtStats->method('fetch')->willReturn(['total' => 100, 'critical' => 10, 'warning' => 20]);
        $this->dbMock->method('query')->willReturn($stmtStats);

        $stats = $this->model->getStats();
        self::assertSame(100, $stats['total']);
        self::assertSame(10, $stats['critical']);
        self::assertSame(20, $stats['warning']);
        self::assertSame(70, $stats['info']);
        self::assertSame(90.0, $stats['success_rate']);

        $summaries = Log::dashboardSummaryLogs();
        self::assertCount(3, $summaries);
        self::assertSame(100, $summaries[0]['value']);
        self::assertSame('90%', $summaries[1]['value']);
        self::assertSame(10, $summaries[2]['value']);
    }
}
