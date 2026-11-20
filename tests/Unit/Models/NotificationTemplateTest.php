<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\NotificationTemplate;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class NotificationTemplateTest
 *
 * Unit tests for the NotificationTemplate model.
 *
 * @category Tests
 */
class NotificationTemplateTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        // Reset the singleton instance of MySQLWrapper
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, null);
    }

    public function test_find_active_returns_array_when_found(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'event_name' => 'test_event',
                'channel' => 'email',
                'subject' => 'Test Subject',
                'body_html' => '<p>Test Body</p>',
                'is_active' => 1,
            ],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = NotificationTemplate::findActive('test_event', 'email');

        self::assertIsArray($result);
        self::assertEquals('test_event', $result['event_name'] ?? '');
        self::assertEquals('Test Subject', $result['subject'] ?? '');
    }

    public function test_find_active_returns_null_when_not_found(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = NotificationTemplate::findActive('non_existent_event', 'email');

        self::assertNull($result);
    }

    public function test_find_active_falls_back_to_default_language(): void
    {
        $mockStatementTarget = $this->createMock(PDOStatement::class);
        $mockStatementTarget->method('fetchAll')->willReturn([]);

        $mockStatementFallback = $this->createMock(PDOStatement::class);
        $mockStatementFallback->method('fetchAll')->willReturn([
            [
                'id' => 2,
                'event_name' => 'test_event',
                'channel' => 'email',
                'language' => 'en',
                'subject' => 'Fallback Subject',
                'body_html' => '<p>Fallback Body</p>',
                'is_active' => 1,
            ],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($mockStatementTarget, $mockStatementFallback);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = NotificationTemplate::findActive('test_event', 'email', 'ro');

        self::assertIsArray($result);
        self::assertEquals('Fallback Subject', $result['subject'] ?? '');
    }

    public function test_get_default_language(): void
    {
        $default = NotificationTemplate::getDefaultLanguage();
        self::assertNotEmpty($default);
    }

    public function test_find_by_id_returns_array_when_found(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn([
            'id' => 5,
            'event_name' => 'welcome_user',
            'channel' => 'email',
            'subject' => 'Welcome',
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->with(self::stringContains('SELECT * FROM notification_templates WHERE id = ?'), [5])
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = NotificationTemplate::findById(5);

        self::assertIsArray($result);
        self::assertEquals(5, $result['id'] ?? 0);
        self::assertEquals('welcome_user', $result['event_name'] ?? '');
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(false);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = NotificationTemplate::findById(999);

        self::assertNull($result);
    }

    public function test_all_returns_list_of_templates(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            ['id' => 1, 'event_name' => 'alpha'],
            ['id' => 2, 'event_name' => 'beta'],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->with(self::stringContains('ORDER BY event_name ASC, channel ASC'))
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = NotificationTemplate::all();

        self::assertCount(2, $result);
        self::assertEquals('alpha', $result[0]['event_name'] ?? '');
    }

    public function test_exists_checks_with_and_without_except_id(): void
    {
        $mockStatementTrue = $this->createMock(PDOStatement::class);
        $mockStatementTrue->method('fetch')->willReturn(['id' => 1]);

        $mockStatementFalse = $this->createMock(PDOStatement::class);
        $mockStatementFalse->method('fetch')->willReturn(false);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($mockStatementTrue, $mockStatementFalse);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        self::assertTrue(NotificationTemplate::exists('event_a', 'email'));
        self::assertFalse(NotificationTemplate::exists('event_a', 'email', 'en', 1));
    }

    public function test_create_persists_template_with_timestamps(): void
    {
        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('create')
            ->with('notification_templates', self::callback(function (array $data) {
                return isset($data['event_name'], $data['created_at'], $data['updated_at'])
                    && $data['event_name'] === 'welcome';
            }))
            ->willReturn(10);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $id = NotificationTemplate::create(['event_name' => 'welcome']);
        self::assertEquals(10, $id);
    }

    public function test_create_preserves_provided_timestamps(): void
    {
        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('create')
            ->with('notification_templates', self::callback(function (array $data) {
                return $data['created_at'] === '2026-01-01 00:00:00'
                    && $data['updated_at'] === '2026-01-01 00:00:00';
            }))
            ->willReturn(11);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $id = NotificationTemplate::create([
            'event_name' => 'welcome',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        self::assertEquals(11, $id);
    }

    public function test_update_modifies_template_and_updates_timestamp(): void
    {
        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['update'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('update')
            ->with('notification_templates', self::callback(function (array $data) {
                return isset($data['updated_at']) && ($data['subject'] ?? '') === 'New Subject';
            }), ['id' => 7])
            ->willReturn(1);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $success = NotificationTemplate::update(7, ['subject' => 'New Subject']);
        self::assertTrue($success);
    }

    public function test_delete_removes_template(): void
    {
        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete'])
            ->getMock();

        $mockWrapper->expects($this->exactly(2))
            ->method('delete')
            ->with('notification_templates', ['id' => 3])
            ->willReturnOnConsecutiveCalls(1, 0);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        self::assertTrue(NotificationTemplate::delete(3));
        self::assertFalse(NotificationTemplate::delete(3));
    }
}
