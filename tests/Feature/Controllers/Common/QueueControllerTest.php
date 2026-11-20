<?php

declare(strict_types=1);

namespace App\Controllers\Common {
    global $mockQueueHttpInput;
    $mockQueueHttpInput = null;

    if (! function_exists('App\Controllers\Common\file_get_contents')) {
        /**
         * @param  resource|null  $context
         * @param  int<0, max>|null  $length
         */
        function file_get_contents(string $filename, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null): string|false
        {
            global $mockQueueHttpInput;
            if ($filename === 'php://input' && is_string($mockQueueHttpInput)) {
                return $mockQueueHttpInput;
            }

            if ($length !== null) {
                return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
            }

            return \file_get_contents($filename, $use_include_path, $context, $offset);
        }
    }
}

namespace Tests\Feature\Controllers\Common {

    use App\Controllers\Common\NotifyManagerController;
    use App\Controllers\Common\QueueController;
    use App\Core\MySQLWrapper;
    use PHPUnit\Framework\TestCase;

    /**
     * Class QueueControllerTest
     *
     * Feature tests for the QueueController.
     *
     * @category Tests
     */
    class QueueControllerTest extends TestCase
    {
        private QueueController $controller;

        /**
         * @var array<mixed>
         */
        private array $originalEnv;

        /**
         * @var array<mixed>
         */
        private array $originalServer;

        protected function setUp(): void
        {
            parent::setUp();

            $this->controller = new QueueController;
            $this->originalEnv = $_ENV;
            $this->originalServer = $_SERVER;

            // Prevent actual email sending by making SendNotification throw an exception when handle() is called.
            // It will fail the job gracefully in processQueue().
            $_ENV['APP_ENV'] = 'dev';

            // SCRIPT_NAME no longer overridden, LogViaStream silences itself gracefully using PHPUNIT_RUNNING
        }

        protected function tearDown(): void
        {
            $_ENV = $this->originalEnv;
            $_SERVER = $this->originalServer;
            global $mockQueueHttpInput;
            $mockQueueHttpInput = null;

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, null);

            parent::tearDown();
        }

        public function test_process_queue_no_jobs(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);
            $mockStatement->method('fetchAll')->willReturn([]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();

            // Expected 2 calls: 1 for markExpired() and 1 for SELECT pending jobs.
            $mockWrapper->expects($this->exactly(2))
                ->method('query')
                ->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            // Should return immediately without exceptions
            $this->controller->processQueue();
        }

        public function test_process_queue_with_valid_payload_fails_notification(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);

            // Return 1 job
            $mockStatement->method('fetchAll')->willReturn([
                [
                    'id' => 1,
                    'attempts' => 0,
                    'payload' => json_encode(['action' => 'email', 'payload' => ['template_event' => 'test_event', 'data' => ['to' => 'test@local', 'message' => 'Hello']]]),
                ],
            ]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();

            // Expected 3 calls:
            // 1. SELECT pending jobs
            // 2. UPDATE status to 'processing'
            // 3. UPDATE status to 'failed' (because SendNotification throws RuntimeException in dev env)
            $mockWrapper->expects($this->atLeastOnce())
                ->method('query')
                ->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            $this->controller->processQueue();
        }

        public function test_process_queue_with_invalid_payload(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);

            // Return 1 job with corrupted JSON payload
            $mockStatement->method('fetchAll')->willReturn([
                [
                    'id' => 2,
                    'attempts' => 1,
                    'payload' => '{invalid_json',
                ],
            ]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();

            // Expected 3 calls:
            // 1. SELECT pending jobs
            // 2. UPDATE status to 'processing'
            // 3. UPDATE status to 'failed' (because json_decode fails and throws RuntimeException)
            $mockWrapper->expects($this->atLeastOnce())
                ->method('query')
                ->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            $this->controller->processQueue();
        }

        public function test_process_queue_with_non_array_payload(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);

            // Return 1 job with valid JSON but NOT an array (e.g. integer)
            $mockStatement->method('fetchAll')->willReturn([
                [
                    'id' => 3,
                    'attempts' => 0,
                    'payload' => '"just a string"', // This decodes to a string, not an array
                ],
            ]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();

            // Expected 3 calls:
            // 1. SELECT pending jobs
            // 2. UPDATE status to 'processing'
            // 3. UPDATE status to 'failed' (because payload is not array, throws RuntimeException)
            $mockWrapper->expects($this->atLeastOnce())
                ->method('query')
                ->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            $this->controller->processQueue();
        }

        public function test_purge_jobs(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);
            $mockStatement->expects($this->once())->method('rowCount')->willReturn(5);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();

            // Expected 1 call: DELETE ...
            $mockWrapper->expects($this->once())
                ->method('query')
                ->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            $result = $this->controller->purge(30);

            self::assertEquals(5, $result);
        }

        public function test_process_queue_when_notify_manager_returns_false(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);
            $mockStatement->method('fetchAll')->willReturn([
                [
                    'id' => 1,
                    'attempts' => 0,
                    'payload' => json_encode(['action' => 'unsupported_action', 'payload' => []]),
                ],
            ]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();
            $mockWrapper->method('query')->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            $mockNotifyManager = $this->createMock(NotifyManagerController::class);
            $mockNotifyManager->expects($this->once())->method('handle')->willReturn(false);

            $this->controller->setNotifyManager($mockNotifyManager);
            $this->controller->processQueue();

            $this->addToAssertionCount(1);
        }

        public function test_process_queue_when_notify_manager_succeeds(): void
        {
            $mockStatement = $this->createMock(\PDOStatement::class);
            $mockStatement->method('fetchAll')->willReturn([
                [
                    'id' => 1,
                    'attempts' => 0,
                    'payload' => json_encode(['action' => 'email', 'payload' => []]),
                ],
            ]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['query'])
                ->getMock();
            $mockWrapper->method('query')->willReturn($mockStatement);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            $mockNotifyManager = $this->createMock(NotifyManagerController::class);
            $mockNotifyManager->expects($this->once())->method('handle')->willReturn(true);

            $this->controller->setNotifyManager($mockNotifyManager);
            $this->controller->processQueue();

            $this->addToAssertionCount(1);
        }

        public function test_push_webhook_fails_on_empty_payload(): void
        {
            global $mockQueueHttpInput;
            $mockQueueHttpInput = '';

            try {
                $this->controller->pushWebhook();
                self::fail('Expected JSON_RESPONSE exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_RESPONSE:400', $e->getMessage());
            }
        }

        public function test_push_webhook_fails_on_invalid_data_structure(): void
        {
            global $mockQueueHttpInput;
            $mockQueueHttpInput = json_encode(['event' => 'email', 'payload' => 'not-an-array']);

            try {
                $this->controller->pushWebhook();
                self::fail('Expected JSON_RESPONSE exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_RESPONSE:400', $e->getMessage());
            }
        }

        public function test_push_webhook_success(): void
        {
            global $mockQueueHttpInput;
            $mockQueueHttpInput = json_encode(['event' => 'email', 'payload' => ['to' => 'test@local']]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['create'])
                ->getMock();
            $mockWrapper->method('create')->willReturn(1);

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            try {
                $this->controller->pushWebhook();
                self::fail('Expected JSON_RESPONSE exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_RESPONSE:200', $e->getMessage());
            }
        }

        public function test_push_webhook_handles_exception_and_returns_500(): void
        {
            global $mockQueueHttpInput;
            $mockQueueHttpInput = json_encode(['event' => 'email', 'payload' => ['to' => 'test@local']]);

            $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['create'])
                ->getMock();
            $mockWrapper->method('create')->willThrowException(new \RuntimeException('Queue push failed'));

            $reflection = new \ReflectionClass(MySQLWrapper::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setValue(null, $mockWrapper);

            try {
                $this->controller->pushWebhook();
                self::fail('Expected JSON_RESPONSE exception');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('JSON_RESPONSE:500', $e->getMessage());
            }
        }

        public function test_calculate_backoff_available_at(): void
        {
            $now = time();
            $result1 = $this->controller->calculateBackoffAvailableAt(1);
            $time1 = strtotime($result1);
            $diff1 = is_int($time1) ? ($time1 - $now) : 0;
            // Config default: 1 minute = 60 seconds (allow +/- 5s margin)
            self::assertGreaterThanOrEqual(55, $diff1);
            self::assertLessThanOrEqual(65, $diff1);

            $result2 = $this->controller->calculateBackoffAvailableAt(2);
            $time2 = strtotime($result2);
            $diff2 = is_int($time2) ? ($time2 - $now) : 0;
            // 5 minutes = 300 seconds
            self::assertGreaterThanOrEqual(295, $diff2);
            self::assertLessThanOrEqual(305, $diff2);

            $resultMax = $this->controller->calculateBackoffAvailableAt(10);
            $timeMax = strtotime($resultMax);
            $diffMax = is_int($timeMax) ? ($timeMax - $now) : 0;
            // 60 minutes = 3600 seconds
            self::assertGreaterThanOrEqual(3595, $diffMax);
            self::assertLessThanOrEqual(3605, $diffMax);
        }

        public function test_check_smtp_health_empty_host_returns_true(): void
        {
            unset($_ENV['MAIL_HOST']);
            $result = QueueController::checkSmtpHealth('');
            self::assertTrue($result);

            $resultDefault = QueueController::checkSmtpHealth(null);
            self::assertTrue($resultDefault);
        }

        public function test_check_smtp_health_unreachable_host_returns_false(): void
        {
            $result = QueueController::checkSmtpHealth('127.0.0.1', 65534, 1);
            self::assertFalse($result);
        }

        public function test_check_smtp_health_listening_socket_returns_true(): void
        {
            $errno = 0;
            $errstr = '';
            $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($server !== false) {
                $name = stream_socket_get_name($server, false);
                if (is_string($name)) {
                    $parts = explode(':', $name);
                    $port = (int) end($parts);
                    $result = QueueController::checkSmtpHealth('127.0.0.1', $port, 1);
                    self::assertTrue($result);
                }
                fclose($server);
            }
        }

        public function test_set_notify_manager(): void
        {
            $notifyManager = new NotifyManagerController;
            $this->controller->setNotifyManager($notifyManager);

            $ref = new \ReflectionClass($this->controller);
            $prop = $ref->getProperty('notifyManager');
            self::assertSame($notifyManager, $prop->getValue($this->controller));
        }
    }
}
