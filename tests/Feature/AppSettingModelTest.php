<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\App;
use App\Models\AppSetting;
use InvalidArgumentException;

class AppSettingModelTest extends TestCase
{
    private App $appModel;
    private AppSetting $settingModel;
    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->settingModel = new AppSetting();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE app_settings;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $this->appId = $this->appModel->create('Test App Settings', 'settings-key-999');
    }

    public function testCanSaveAndGetSetting(): void
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

    public function testGetSettingsForAppReturnsAllSettings(): void
    {
        $this->settingModel->saveSetting($this->appId, 'setting_one', 'val1');
        $this->settingModel->saveSetting($this->appId, 'setting_two', 'val2');

        $allSettings = $this->settingModel->getSettingsForApp($this->appId);
        $this->assertCount(2, $allSettings);
    }

    public function testSaveSettingThrowsExceptionOnInvalidParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settingModel->saveSetting(0, '', 'val');
    }
}
