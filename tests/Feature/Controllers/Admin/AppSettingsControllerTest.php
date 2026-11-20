<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\AppSettingsController;
use App\Core\MySQLWrapper;
use App\Models\AppSetting;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class AppSettingsControllerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::resetCache();
        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        AppSetting::resetCache();
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_index_rejects_invalid_app_id(): void
    {
        $controller = new AppSettingsController;
        try {
            $controller->index('0');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:400:', $e->getMessage());
        }
    }

    public function test_index_returns_settings_json(): void
    {
        $this->dbMock->method('read')
            ->with('app_settings', ['app_id' => 2])
            ->willReturn([['key' => 'notification_channel', 'value' => 'email']]);

        $controller = new AppSettingsController;
        try {
            $controller->index('2');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:200:', $e->getMessage());
            $decoded = json_decode(substr($e->getMessage(), strlen('JSON_RESPONSE:200:')), true);
            self::assertIsArray($decoded);
            self::assertTrue($decoded['success'] ?? false);
            self::assertCount(1, is_array($decoded['settings'] ?? null) ? $decoded['settings'] : []);
        }
    }

    public function test_store_bulk_settings_validates_and_saves(): void
    {
        $this->dbMock->method('read')->willReturn([]);
        $this->dbMock->expects($this->once())->method('create')->willReturn(1);

        $controller = new AppSettingsController;
        try {
            $controller->store([
                'settings' => [
                    ['key' => 'notification_levels', 'value' => 'error,critical'],
                ],
            ], '2');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:200:', $e->getMessage());
        }
    }

    public function test_store_bulk_settings_returns_422_on_invalid_key(): void
    {
        $controller = new AppSettingsController;
        try {
            $controller->store([
                'settings' => [
                    ['key' => 'invalid key!', 'value' => 'test'],
                ],
            ], '2');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:422:', $e->getMessage());
        }
    }

    public function test_update_and_delete_setting_endpoints(): void
    {
        $this->dbMock->method('read')->willReturn([]);
        $this->dbMock->expects($this->once())->method('update')->willReturn(1);
        $this->dbMock->expects($this->once())->method('delete')->willReturn(1);

        $controller = new AppSettingsController;

        try {
            $controller->update(['old_key' => 'k1', 'key' => 'k2', 'value' => 'v2'], '2');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:200:', $e->getMessage());
        }

        try {
            $controller->delete('2');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:400:', $e->getMessage());
        }

        $_POST['key'] = 'k2';
        try {
            $controller->delete('2');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:200:', $e->getMessage());
        } finally {
            unset($_POST['key']);
        }
    }
}
