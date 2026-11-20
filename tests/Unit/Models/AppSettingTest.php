<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\AppSetting;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class AppSettingTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    private AppSetting $model;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::resetCache();
        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
        $this->model = new AppSetting($this->dbMock);
    }

    protected function tearDown(): void
    {
        AppSetting::resetCache();
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_get_settings_for_app_caches_results(): void
    {
        self::assertSame([], $this->model->getSettingsForApp(0));

        $this->dbMock->expects($this->once())
            ->method('read')
            ->with('app_settings', ['app_id' => 3])
            ->willReturn([['key' => 'notification_channel', 'value' => 'email']]);

        $first = $this->model->getSettingsForApp(3);
        $second = $this->model->getSettingsForApp(3);
        self::assertSame($first, $second);
        self::assertCount(1, $first);
    }

    public function test_save_setting_inserts_or_updates(): void
    {
        $this->dbMock->method('read')->willReturnOnConsecutiveCalls(
            [],
            [['id' => 1, 'app_id' => 3, 'key' => 'notification_levels', 'value' => 'error']]
        );

        $this->dbMock->expects($this->once())
            ->method('create')
            ->with('app_settings', self::callback(fn (array $d): bool => ($d['key'] ?? '') === 'notification_levels'))
            ->willReturn(1);

        $this->dbMock->expects($this->once())
            ->method('update')
            ->with('app_settings', self::callback(fn (array $d): bool => ($d['value'] ?? '') === 'error,critical'), ['app_id' => 3, 'key' => 'notification_levels'])
            ->willReturn(1);

        $this->model->saveSetting(3, 'notification_levels', 'error');
        $this->model->saveSetting(3, 'notification_levels', 'error,critical');
    }

    public function test_update_setting_rejects_duplicate_key(): void
    {
        $this->dbMock->method('read')->willReturn([['key' => 'existing_key', 'value' => '1']]);

        $this->expectException(RuntimeException::class);
        $this->model->updateSetting(3, 'old_key', ['key' => 'existing_key', 'value' => '2']);
    }

    public function test_delete_setting_throws_when_missing_or_invalid(): void
    {
        $this->dbMock->method('delete')->willReturn(0);
        try {
            $this->model->deleteSetting(3, 'missing');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertNotEmpty($e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->model->deleteSetting(0, '');
    }
}
