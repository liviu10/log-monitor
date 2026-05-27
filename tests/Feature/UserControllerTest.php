<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Controllers\UserController;
use App\Models\User;

/**
 * Subclassed user controller to intercept redirect/render calls.
 */
class TestableUserController extends UserController
{
    public string $renderedView = '';
    public array $renderedData = [];

    protected function redirect(string $url): never
    {
        throw new HttpRedirectException($url);
    }

    protected function render(string $view, array $data = []): void
    {
        $this->renderedView = $view;
        $this->renderedData = $data;
    }
}

class UserControllerTest extends TestCase
{
    private User $userModel;
    private TestableUserController $userController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userModel = new User();
        $this->userController = new TestableUserController();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE users;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        // Authed user session - Use 999 to avoid clash with DB auto-increment ID 1
        $_SESSION['auth.user'] = ['id' => 999, 'username' => 'admin'];
    }

    public function testIndexRendersAdministrators(): void
    {
        $this->userModel->create('admin2', 'password_123');

        $this->userController->index();

        $this->assertEquals('users/index', $this->userController->renderedView);
        // There should be at least the created user
        $this->assertCount(1, $this->userController->renderedData['users']);
        $this->assertEquals('admin2', $this->userController->renderedData['users'][0]['username']);
    }

    public function testStoreCreatesNewAdmin(): void
    {
        try {
            $this->userController->store([
                'username' => 'new_admin',
                'password' => 'supersecretpassword'
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            
            $user = $this->userModel->findByUsername('new_admin');
            $this->assertNotNull($user);
            $this->assertEquals('new_admin', $user['username']);
        }
    }

    public function testDeleteSelfIsBlocked(): void
    {
        try {
            $this->userController->delete(['id' => 999]); // Same ID as current auth user
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            $this->assertEquals('danger', $_SESSION['app_flash']['type']);
            $this->assertEquals('You cannot delete your own account.', $_SESSION['app_flash']['message']);
        }
    }

    public function testDeleteOtherUserSucceeds(): void
    {
        $id = $this->userModel->create('other_user', 'password123');

        try {
            $this->userController->delete(['id' => $id]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            
            $user = $this->userModel->findByUsername('other_user');
            $this->assertNull($user);
        }
    }
}
