<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\App;
use InvalidArgumentException;
use RuntimeException;

class AppModelTest extends TestCase
{
    private App $appModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        
        // Truncate the table before running model tests to ensure a clean state
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function testCanCreateAndFindAppByApiKey(): void
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

    public function testCannotCreateAppWithEmptyNameOrKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->appModel->create('', 'key');
    }

    public function testCannotFindAppWithInvalidApiKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->appModel->findByApiKey('non-existent-api-key');
    }
}
