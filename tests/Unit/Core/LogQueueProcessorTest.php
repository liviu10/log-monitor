<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Controllers\Common\NotificationController;
use App\Core\LogQueueProcessor;
use App\Core\MySQLWrapper;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LogQueueProcessorTest extends TestCase
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

    public function test_process_batch_inserts_logs_and_deletes_completed_queue_rows(): void
    {
        $stmtExpired = $this->createMock(PDOStatement::class);
        $stmtExpired->method('rowCount')->willReturn(0);

        $stmtJobs = $this->createMock(PDOStatement::class);
        $stmtJobs->method('fetchAll')->willReturn([
            [
                'id' => 11,
                'app_id' => 2,
                'payload_raw' => json_encode(['level' => 'INFO', 'message' => 'Deploy completed']),
                'attempts' => 0,
            ],
        ]);

        $stmtApp = $this->createMock(PDOStatement::class);
        $stmtApp->method('fetch')->willReturn(false);

        $this->dbMock->method('query')
            ->willReturnOnConsecutiveCalls($stmtExpired, $stmtJobs, $stmtApp);

        $stmtInsert = $this->createMock(PDOStatement::class);
        $stmtInsert->expects(self::once())
            ->method('execute')
            ->with([2, 'INFO', 'Deploy completed', null])
            ->willReturn(true);

        $stmtDelete = $this->createMock(PDOStatement::class);
        $stmtDelete->expects(self::once())
            ->method('execute')
            ->with([11])
            ->willReturn(true);

        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdoMock->expects(self::once())->method('commit')->willReturn(true);
        $pdoMock->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($stmtInsert, $stmtDelete): PDOStatement {
                if (str_starts_with($sql, 'INSERT INTO logs')) {
                    return $stmtInsert;
                }
                if (str_starts_with($sql, 'DELETE FROM log_queue WHERE id IN')) {
                    return $stmtDelete;
                }
                self::fail('Unexpected SQL prepared: '.$sql);
            });

        $this->dbMock->method('getConnection')->willReturn($pdoMock);

        $notifier = $this->createMock(NotificationController::class);
        $appsCache = [];

        $processed = LogQueueProcessor::processBatch($appsCache, $notifier);
        self::assertSame(1, $processed);
    }
}

