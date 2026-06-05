<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Controllers\AppController;
use App\Models\App;

/**
 * Subclassed controller to intercept redirect/render calls.
 */
class TestableAppController extends AppController
{
    public string $renderedView = '';

    public array $renderedData = [];

    /**
     * @return never
     */
    protected function redirect(string $url)
    {
        throw new HttpRedirectException($url);
    }

    protected function render(string $view, array $data = []): void
    {
        $this->renderedView = $view;
        $this->renderedData = $data;
    }
}

class AppControllerTest extends TestCase
{
    private App $appModel;

    private TestableAppController $appController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->appController = new TestableAppController();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        // Set authenticated user session
        $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];
    }

    public function testIndexRendersRegisteredApps(): void
    {
        $this->appModel->create('First App', 'key-1');
        $this->appModel->create('Second App', 'key-2');

        $this->appController->index();

        $this->assertEquals('apps/index', $this->appController->renderedView);
        $this->assertCount(2, $this->appController->renderedData['apps']);
    }

    public function testStoreCreatesNewAppWithUniqueApiKey(): void
    {
        try {
            $this->appController->store(['name' => 'Third App']);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $httpRedirectException) {
            $this->assertEquals('apps.php', $httpRedirectException->url);

            $apps = $this->appModel->getAll();
            $this->assertCount(1, $apps);
            $this->assertEquals('Third App', $apps[0]['name']);
            $this->assertNotEmpty($apps[0]['api_key']);
        }
    }

    public function testUpdateModifiesAppName(): void
    {
        $id = $this->appModel->create('Old Name', 'key-old');

        try {
            $this->appController->update(['id' => $id, 'name' => 'New Name']);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $httpRedirectException) {
            $this->assertEquals('apps.php', $httpRedirectException->url);

            $app = $this->appModel->findByApiKey('key-old');
            $this->assertEquals('New Name', $app['name']);
        }
    }

    public function testUpdateRegeneratesApiKey(): void
    {
        $id = $this->appModel->create('App Key Reg', 'key-original');

        try {
            $this->appController->update(['id' => $id], ['sub_action' => 'regenerate-key']);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $httpRedirectException) {
            $this->assertEquals('apps.php', $httpRedirectException->url);

            $apps = $this->appModel->getAll();
            $this->assertNotEquals('key-original', $apps[0]['api_key']);
            $this->assertNotEmpty($apps[0]['api_key']);
        }
    }

    public function testDeleteRemovesAppFromDatabase(): void
    {
        $id = $this->appModel->create('To Delete', 'key-delete');

        try {
            $this->appController->delete(['id' => $id]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $httpRedirectException) {
            $this->assertEquals('apps.php', $httpRedirectException->url);

            $apps = $this->appModel->getAll();
            $this->assertEmpty($apps);
        }
    }
}
