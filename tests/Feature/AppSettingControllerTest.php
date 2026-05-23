<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Controllers\AppSettingController;
use App\Models\App;
use App\Models\AppSetting;

/**
 * Subclassed controller to intercept JSON responses.
 */
class TestableAppSettingController extends AppSettingController
{
    protected function jsonResponse(array $data, int $status = 200): never
    {
        throw new HttpResponseException($data, $status);
    }
}

class AppSettingControllerTest extends TestCase
{
    private App $appModel;
    private AppSetting $settingModel;
    private TestableAppSettingController $controller;
    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->settingModel = new AppSetting();
        $this->controller = new TestableAppSettingController();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE app_settings;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];
        $this->appId = $this->appModel->create('Controller Settings App', 'key-ctrl');
    }

    public function testIndexReturnsSettingsJson(): void
    {
        $this->settingModel->saveSetting($this->appId, 'email', 'test@test.com');

        try {
            $this->controller->index(['app_id' => $this->appId]);
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $e) {
            $this->assertEquals(200, $e->statusCode);
            $this->assertTrue($e->data['success']);
            $this->assertCount(1, $e->data['settings']);
            $this->assertEquals('email', $e->data['settings'][0]['key']);
        }
    }

    public function testStoreSavesNewSetting(): void
    {
        $payload = [
            'app_id' => $this->appId,
            'key' => 'new_setting',
            'value' => 'some_value'
        ];

        try {
            $this->controller->store($payload);
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $e) {
            $this->assertEquals(200, $e->statusCode);
            $this->assertTrue($e->data['success']);
            $this->assertEquals('Setting saved successfully.', $e->data['message']);

            $setting = $this->settingModel->getSetting($this->appId, 'new_setting');
            $this->assertEquals('some_value', $setting['value']);
        }
    }

    public function testStoreFailsOnInvalidKeyFormat(): void
    {
        $payload = [
            'app_id' => $this->appId,
            'key' => 'invalid key spaces',
            'value' => 'some_value'
        ];

        try {
            $this->controller->store($payload);
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->statusCode);
            $this->assertFalse($e->data['success']);
        }
    }
}
