<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\MySQLWrapper;
use App\Core\Queue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class QueueTest
 *
 * Unit tests for the Queue Core class.
 * Mocks the MySQLWrapper to avoid actual DB inserts when QueueJob::push is called.
 *
 * @category Tests
 */
class QueueTest extends TestCase
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

    public function test_push_delegates_to_queue_job_and_returns_id(): void
    {
        // QueueJob::push will try to serialize the payload and insert into `jobs`
        $payload = ['email' => 'test@example.com', 'action' => 'send_invoice'];

        $this->dbMock->expects($this->once())
            ->method('create')
            ->with(
                'queue_jobs',
                self::callback(function (mixed $data) use ($payload): bool {
                    if (! is_array($data)) {
                        return false;
                    }

                    return ($data['queue'] ?? null) === 'emails'
                        && ($data['payload'] ?? null) === json_encode($payload)
                        && ($data['attempts'] ?? null) === 0
                        && isset($data['created_at']);
                })
            )
            ->willReturn(99);

        $resultId = Queue::push('emails', $payload);

        self::assertEquals(99, $resultId);
    }
}
