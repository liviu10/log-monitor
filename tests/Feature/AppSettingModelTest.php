<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppSetting;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AppSettingModelTest extends TestCase
{
    private App $appModel;

    private AppSetting $settingModel;

    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App;
        $this->settingModel = new AppSetting;

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE app_settings;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $this->appId = $this->appModel->create('Test App Settings', 'settings-key-999');
    }

    public function test_can_save_and_get_setting(): void
    {
        $key = 'alert_email';
        $value = 'admin@example.com';

        // Create new setting
        $this->settingModel->saveSetting($this->appId, $key, $value);

        $setting = $this->settingModel->getSetting($this->appId, $key);
        $this->assertNotNull($setting);
        $this->assertEquals($value, $setting['value']);

        // Update setting
        $newValue = 'new_admin@example.com';
        $this->settingModel->saveSetting($this->appId, $key, $newValue);

        $updatedSetting = $this->settingModel->getSetting($this->appId, $key);
        $this->assertEquals($newValue, $updatedSetting['value']);
    }

    public function test_get_settings_for_app_returns_all_settings(): void
    {
        $this->settingModel->saveSetting($this->appId, 'setting_one', 'val1');
        $this->settingModel->saveSetting($this->appId, 'setting_two', 'val2');

        $allSettings = $this->settingModel->getSettingsForApp($this->appId);
        $this->assertCount(2, $allSettings);
    }

    public function test_save_setting_throws_exception_on_invalid_parameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settingModel->saveSetting(0, '', 'val');
    }

    public function test_update_setting(): void
    {
        $this->settingModel->saveSetting($this->appId, 'old_key', 'old_val');
        $this->settingModel->updateSetting($this->appId, 'old_key', ['key' => 'new_key', 'value' => 'new_val']);

        $setting = $this->settingModel->getSetting($this->appId, 'new_key');
        $this->assertNotNull($setting);
        $this->assertEquals('new_val', $setting['value']);
    }

    public function test_update_setting_throws_exception_on_duplicate_key(): void
    {
        $this->settingModel->saveSetting($this->appId, 'key1', 'val1');
        $this->settingModel->saveSetting($this->appId, 'key2', 'val2');

        $this->expectException(RuntimeException::class);
        $this->settingModel->updateSetting($this->appId, 'key1', ['key' => 'key2']);
    }

    public function test_update_setting_throws_exception_on_invalid_params(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settingModel->updateSetting(0, '', []);
    }

    public function test_delete_setting(): void
    {
        $this->settingModel->saveSetting($this->appId, 'to_delete', 'val');
        $this->settingModel->deleteSetting($this->appId, 'to_delete');

        $setting = $this->settingModel->getSetting($this->appId, 'to_delete');
        $this->assertNull($setting);
    }

    public function test_delete_setting_throws_exception_on_invalid_params(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settingModel->deleteSetting(0, '');
    }
}
