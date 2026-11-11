<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\User;
use Tests\TestCase;

if (! class_exists('Tests\Feature\HttpRedirectException')) {
    class HttpRedirectException extends \Exception
    {
        public string $url;

        public function __construct(string $url)
        {
            parent::__construct('HTTP Redirect to: '.$url);
            $this->url = $url;
        }
    }
}

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
        $this->userModel = new User;
        $this->userController = new TestableUserController;

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE users;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        // Authed user session - Use 999 to avoid clash with DB auto-increment ID 1
        $_SESSION['auth.user'] = ['id' => 999, 'username' => 'admin'];
    }

    public function test_index_renders_administrators(): void
    {
        $this->userModel->create('admin2', 'password_123');

        $this->userController->index();

        $this->assertEquals('users/index', $this->userController->renderedView);
        // There should be at least the created user
        $this->assertCount(1, $this->userController->renderedData['users']);
        $this->assertEquals('admin2', $this->userController->renderedData['users'][0]['username']);
    }

    public function test_store_creates_new_admin(): void
    {
        try {
            $this->userController->store([
                'username' => 'new_admin',
                'password' => 'supersecretpassword',
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);

            $user = $this->userModel->findByUsername('new_admin');
            $this->assertNotNull($user);
            $this->assertEquals('new_admin', $user['username']);
        }
    }

    public function test_delete_self_is_blocked(): void
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

    public function test_delete_other_user_succeeds(): void
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

    public function test_store_fails_validation_with_empty_username(): void
    {
        try {
            $this->userController->store([
                'username' => '',
                'password' => 'supersecretpassword',
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            $this->assertArrayHasKey('errors', $_SESSION);
            $this->assertArrayHasKey('username', $_SESSION['errors']);
        }
    }

    public function test_store_fails_validation_with_short_password(): void
    {
        try {
            $this->userController->store([
                'username' => 'valid_user',
                'password' => '123',
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            $this->assertArrayHasKey('errors', $_SESSION);
            $this->assertArrayHasKey('password', $_SESSION['errors']);
        }
    }

    public function test_delete_with_no_id_does_nothing(): void
    {
        try {
            $this->userController->delete([]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
        }
    }

    public function test_change_password_succeeds_with_correct_credentials(): void
    {
        $userId = $this->userModel->create('admin_pwd', 'old_password123');
        $_SESSION['auth.user'] = ['id' => $userId, 'username' => 'admin_pwd'];

        try {
            $this->userController->changePassword([
                'current_password' => 'old_password123',
                'new_password' => 'new_password456',
                'confirm_password' => 'new_password456',
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            $user = $this->userModel->findById($userId);
            $this->assertNotNull($user);
            $this->assertTrue(password_verify('new_password456', (string) $user['password_hash']));
        }
    }

    public function test_change_password_fails_when_current_password_is_incorrect(): void
    {
        $userId = $this->userModel->create('admin_pwd2', 'actual_password123');
        $_SESSION['auth.user'] = ['id' => $userId, 'username' => 'admin_pwd2'];

        try {
            $this->userController->changePassword([
                'current_password' => 'wrong_password',
                'new_password' => 'new_password456',
                'confirm_password' => 'new_password456',
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
            $user = $this->userModel->findById($userId);
            $this->assertNotNull($user);
            $this->assertFalse(password_verify('new_password456', (string) $user['password_hash']));
        }
    }

    public function test_change_password_fails_when_confirm_password_does_not_match(): void
    {
        $userId = $this->userModel->create('admin_pwd3', 'actual_password123');
        $_SESSION['auth.user'] = ['id' => $userId, 'username' => 'admin_pwd3'];

        try {
            $this->userController->changePassword([
                'current_password' => 'actual_password123',
                'new_password' => 'new_password456',
                'confirm_password' => 'different_password789',
            ]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('users.php', $e->url);
        }
    }
}
