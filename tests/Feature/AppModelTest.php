<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AppModelTest extends TestCase
{
    private App $appModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App;

        // Truncate the table before running model tests to ensure a clean state
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function test_can_create_and_find_app_by_api_key(): void
    {
        $name = 'Test Application';
        $apiKey = 'test-api-key-12345';

        $id = $this->appModel->create($name, $apiKey);
        $this->assertGreaterThan(0, $id);

        $app = $this->appModel->findByApiKey($apiKey);

        $this->assertEquals($id, $app['id']);
        $this->assertEquals($name, $app['name']);
        $this->assertEquals($apiKey, $app['api_key']);
    }

    public function test_cannot_create_app_with_empty_name_or_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->appModel->create('', 'key');
    }

    public function test_cannot_find_app_with_invalid_api_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->appModel->findByApiKey('non-existent-api-key');
    }

    public function test_get_all_apps(): void
    {
        $this->appModel->create('App 1', 'key-1');
        $this->appModel->create('App 2', 'key-2');

        $apps = $this->appModel->getAll();
        $this->assertCount(2, $apps);
    }

    public function test_update_app(): void
    {
        $id = $this->appModel->create('Old App Name', 'key-old');
        $this->appModel->update($id, ['name' => 'New App Name']);

        $app = $this->appModel->findByApiKey('key-old');
        $this->assertEquals('New App Name', $app['name']);
    }

    public function test_update_app_with_invalid_id_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->appModel->update(0, ['name' => 'New App Name']);
    }

    public function test_update_app_with_empty_data_throws_exception(): void
    {
        $id = $this->appModel->create('App', 'key');
        $this->expectException(InvalidArgumentException::class);
        $this->appModel->update($id, []);
    }

    public function test_delete_app(): void
    {
        $id = $this->appModel->create('App to delete', 'key-delete');
        $this->appModel->delete($id);

        $this->expectException(RuntimeException::class);
        $this->appModel->findByApiKey('key-delete');
    }

    public function test_delete_app_with_invalid_id_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->appModel->delete(-1);
    }
}
