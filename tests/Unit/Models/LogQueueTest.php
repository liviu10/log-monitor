<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\LogQueue;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LogQueueTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_count_all_returns_integer(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn('42');
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT COUNT(*) FROM log_queue')
            ->willReturn($stmt);

        self::assertSame(42, LogQueue::countAll());
    }

    public function test_get_paginated_sanitizes_sort_and_direction(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 1, 'app_id' => 2, 'app_name' => 'AppA', 'payload_raw' => '{}', 'created_at' => '2026-11-20 10:00:00'],
        ]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(self::callback(function (string $sql): bool {
                return str_contains($sql, 'ORDER BY q.id DESC')
                    && str_contains($sql, 'LIMIT 10 OFFSET 0');
            }))
            ->willReturn($stmt);

        $rows = LogQueue::getPaginated(10, 0, 'invalid_col', 'DROP');
        self::assertCount(1, $rows);
        self::assertSame('AppA', $rows[0]['app_name']);
    }

    public function test_get_paginated_sorts_by_app_name_asc(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(self::stringContains('ORDER BY a.name ASC'))
            ->willReturn($stmt);

        self::assertSame([], LogQueue::getPaginated(25, 25, 'app_name', 'ASC'));
    }

    public function test_delete_by_id_and_purge_all(): void
    {
        $this->dbMock->expects($this->once())
            ->method('delete')
            ->with('log_queue', ['id' => 9])
            ->willReturn(1);

        self::assertSame(1, LogQueue::deleteById(9));

        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->expects($this->once())
            ->method('exec')
            ->with('TRUNCATE TABLE log_queue')
            ->willReturn(0);

        $this->dbMock->method('getConnection')->willReturn($pdoMock);
        LogQueue::purgeAll();
    }

    public function test_dashboard_summary_log_queue(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(7);
        $this->dbMock->method('query')->willReturn($stmt);

        $summary = LogQueue::dashboardSummaryLogQueue();
        self::assertSame(7, $summary['value']);
        self::assertSame('/admin/log-queue', $summary['url']);
    }
}
