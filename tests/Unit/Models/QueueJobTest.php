<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\QueueJob;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Class QueueJobTest
 *
 * Unit tests for the QueueJob Model.
 * Uses a mocked MySQLWrapper to avoid actual database dependency.
 *
 * @category Tests
 */
class QueueJobTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock for MySQLWrapper
        $this->dbMock = $this->createMock(MySQLWrapper::class);

        // Inject the mock into the Singleton instance
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        // Reset the Singleton instance to avoid affecting other tests
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        parent::tearDown();
    }

    /**
     * Helper to create a PDOStatement mock for fetchAll().
     *
     * @param  array<int|string, mixed>  $fetchAllResult
     */
    private function getFetchAllMock(array $fetchAllResult): PDOStatement&MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($fetchAllResult);

        return $stmt;
    }

    /**
     * Helper to create a PDOStatement mock for rowCount().
     */
    private function getRowCountMock(int $count): PDOStatement&MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn($count);

        return $stmt;
    }

    public function test_push_creates_job_and_returns_id(): void
    {
        $payload = ['email' => 'test@example.com'];
        $queue = 'emails';

        $this->dbMock->expects(self::once())
            ->method('create')
            ->with('queue_jobs', self::callback(function (mixed $data) use ($queue, $payload): bool {
                return is_array($data)
                    && ($data['queue'] ?? null) === $queue
                    && ($data['payload'] ?? null) === json_encode($payload)
                    && ($data['status'] ?? null) === 'pending'
                    && ($data['attempts'] ?? null) === 0
                    && isset($data['created_at']);
            }))
            ->willReturn(10); // ID of the created row

        $result = QueueJob::push($queue, $payload);
        self::assertSame(10, $result);
    }

    public function test_push_with_available_and_expires_at(): void
    {
        $payload = ['msg' => 'delayed'];
        $availableAt = '2026-10-20 18:00:00';
        $expiresAt = '2026-10-21 18:00:00';

        $this->dbMock->expects(self::exactly(3))
            ->method('create')
            ->willReturn(12);

        $result1 = QueueJob::push('delayed_queue', $payload, $availableAt, $expiresAt);
        self::assertSame(12, $result1);

        $result2 = QueueJob::push('delayed_queue', $payload, $availableAt, null);
        self::assertSame(12, $result2);

        $result3 = QueueJob::push('delayed_queue', $payload, null, $expiresAt);
        self::assertSame(12, $result3);
    }

    public function test_push_with_expires_at_in_payload(): void
    {
        $payload = ['email' => 'test@example.com', 'expires_at' => '2026-10-25 12:00:00'];
        $this->dbMock->expects(self::once())
            ->method('create')
            ->with('queue_jobs', self::callback(function (mixed $data): bool {
                return is_array($data) && ($data['expires_at'] ?? null) === '2026-10-25 12:00:00';
            }))
            ->willReturn(15);

        $result = QueueJob::push('emails', $payload);
        self::assertSame(15, $result);
    }

    public function test_push_throws_exception_on_failure(): void
    {
        $this->dbMock->expects(self::once())
            ->method('create')
            ->willThrowException(new RuntimeException('Internal query execution error.', 500));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal query execution error.');

        QueueJob::push('emails', ['data' => 'test']);
    }

    public function test_find_by_id_returns_job_when_found(): void
    {
        $jobData = ['id' => 42, 'queue' => 'default', 'status' => 'pending'];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('fetch')->willReturn($jobData);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with('SELECT * FROM queue_jobs WHERE id = ? LIMIT 1', [42])
            ->willReturn($stmt);

        $result = QueueJob::findById(42);
        self::assertSame($jobData, $result);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('fetch')->willReturn(false);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with('SELECT * FROM queue_jobs WHERE id = ? LIMIT 1', [999])
            ->willReturn($stmt);

        $result = QueueJob::findById(999);
        self::assertNull($result);
    }

    public function test_mark_expired_updates_and_returns_count(): void
    {
        $stmt = $this->getRowCountMock(3);
        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("SET status = 'expired'"))
            ->willReturn($stmt);

        $count = QueueJob::markExpired();
        self::assertSame(3, $count);
    }

    public function test_get_pending_and_retryable_returns_array(): void
    {
        $expected = [
            ['id' => 1, 'queue' => 'emails', 'status' => 'pending'],
            ['id' => 2, 'queue' => 'notifications', 'status' => 'failed', 'attempts' => 1],
        ];

        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects(self::exactly(2))
            ->method('query')
            ->willReturn($stmt);

        $result = QueueJob::getPendingAndRetryable();
        self::assertSame($expected, $result);
    }

    public function test_get_pending_and_retryable_with_custom_max_attempts(): void
    {
        $expected = [
            ['id' => 5, 'queue' => 'emails', 'status' => 'pending'],
        ];

        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects(self::exactly(2))
            ->method('query')
            ->willReturn($stmt);

        $result = QueueJob::getPendingAndRetryable(3);
        self::assertSame($expected, $result);
    }

    public function test_get_failed_jobs_without_queue(): void
    {
        $expected = [['id' => 10, 'status' => 'failed', 'queue' => 'default']];
        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE status = 'failed' ORDER BY id DESC"), [])
            ->willReturn($stmt);

        $result = QueueJob::getFailedJobs();
        self::assertSame($expected, $result);
    }

    public function test_get_failed_jobs_with_specific_queue(): void
    {
        $expected = [['id' => 11, 'status' => 'failed', 'queue' => 'critical']];
        $stmt = $this->getFetchAllMock($expected);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE status = 'failed' AND queue = ? ORDER BY id DESC"), ['critical'])
            ->willReturn($stmt);

        $result = QueueJob::getFailedJobs('critical');
        self::assertSame($expected, $result);
    }

    public function test_retry_failed_single_job(): void
    {
        $stmt = $this->getRowCountMock(1);
        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains('WHERE id = ?'), [15])
            ->willReturn($stmt);

        $count = QueueJob::retryFailed(15);
        self::assertSame(1, $count);
    }

    public function test_retry_failed_all_jobs_with_queue(): void
    {
        $stmt = $this->getRowCountMock(4);
        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE status = 'failed' AND queue = ?"), ['high'])
            ->willReturn($stmt);

        $count = QueueJob::retryFailed(null, 'high');
        self::assertSame(4, $count);
    }

    public function test_retry_failed_single_with_include_expired(): void
    {
        $stmtSingle = $this->getRowCountMock(1);
        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE id = ? AND status IN ('failed', 'expired')"), [10])
            ->willReturn($stmtSingle);

        $count = QueueJob::retryFailed(10, null, true);
        self::assertSame(1, $count);
    }

    public function test_retry_failed_bulk_with_include_expired(): void
    {
        $stmtBulk = $this->getRowCountMock(3);
        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE status IN ('failed', 'expired')"))
            ->willReturn($stmtBulk);

        $count = QueueJob::retryFailed(null, null, true);
        self::assertSame(3, $count);
    }

    public function test_delete_job(): void
    {
        $stmt = $this->getRowCountMock(1);
        $this->dbMock->expects(self::once())
            ->method('query')
            ->with('DELETE FROM queue_jobs WHERE id = ?', [25])
            ->willReturn($stmt);

        $result = QueueJob::deleteJob(25);
        self::assertTrue($result);
    }

    public function test_get_stats(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())
            ->method('fetch')
            ->willReturn([
                'total' => 100,
                'pending' => 10,
                'processing' => 2,
                'completed' => 80,
                'failed' => 5,
                'expired' => 3,
            ]);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->willReturn($stmt);

        $stats = QueueJob::getStats();
        self::assertSame(100, $stats['total']);
        self::assertSame(10, $stats['pending']);
        self::assertSame(2, $stats['processing']);
        self::assertSame(80, $stats['completed']);
        self::assertSame(5, $stats['failed']);
        self::assertSame(3, $stats['expired']);
    }

    public function test_get_stats_empty_defaults(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())
            ->method('fetch')
            ->willReturn(false);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->willReturn($stmt);

        $stats = QueueJob::getStats();
        self::assertSame(0, $stats['total']);
        self::assertSame(0, $stats['pending']);
        self::assertSame(0, $stats['processing']);
        self::assertSame(0, $stats['completed']);
        self::assertSame(0, $stats['failed']);
        self::assertSame(0, $stats['expired']);
    }

    public function test_get_distinct_queues(): void
    {
        $stmt = $this->getFetchAllMock([
            ['queue' => 'critical'],
            ['queue' => 'default'],
            ['queue' => 'low'],
        ]);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with('SELECT DISTINCT queue FROM queue_jobs ORDER BY queue ASC')
            ->willReturn($stmt);

        $queues = QueueJob::getDistinctQueues();
        self::assertSame(['critical', 'default', 'low'], $queues);
    }

    public function test_get_paginated_filtered_jobs(): void
    {
        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->expects(self::once())->method('fetch')->willReturn(['total' => 1]);

        $queryStmt = $this->createMock(PDOStatement::class);
        $queryStmt->expects(self::once())->method('fetchAll')->willReturn([
            ['id' => 1, 'queue' => 'default', 'status' => 'pending'],
        ]);

        $this->dbMock->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($countStmt, $queryStmt);

        $result = QueueJob::getPaginatedFilteredJobs(
            filters: ['search' => 'test', 'status' => 'pending', 'queue' => 'default'],
            page: 1,
            perPage: 10,
            sortBy: 'id',
            sortDesc: false
        );

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
    }

    public function test_update_status_returns_boolean(): void
    {
        $this->dbMock->expects(self::once())
            ->method('update')
            ->with('queue_jobs', ['status' => 'completed'], ['id' => 5])
            ->willReturn(1);

        self::assertTrue(QueueJob::updateStatus(5, ['status' => 'completed']));
    }

    public function test_purge_deletes_jobs_and_returns_count(): void
    {
        $cutoffDate = '2023-10-10 12:00:00';
        $stmt = $this->getRowCountMock(7); // Simulating 7 deleted rows

        $expectedSql = "DELETE FROM queue_jobs 
             WHERE processed_at < ? 
               AND (status = 'completed' OR status = 'failed' OR status = 'expired')";

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with($expectedSql, [$cutoffDate])
            ->willReturn($stmt);

        $result = QueueJob::purge($cutoffDate);
        self::assertSame(7, $result);
    }
}
