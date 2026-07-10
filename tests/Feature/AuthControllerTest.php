<?php

declare(strict_types=1);

namespace Tests\Feature {

    use App\Controllers\AuthController;
    use App\Models\User;
    use Tests\TestCase;

    /**
     * Custom exceptions to intercept redirect calls in AuthController.
     */
    class HttpRedirectException extends \Exception
    {
        public string $url;

        public function __construct(string $url)
        {
            parent::__construct('HTTP Redirect to: '.$url);
            $this->url = $url;
        }
    }

    /**
     * Subclassed controller to intercept exit/render calls for testing.
     */
    class TestableAuthController extends AuthController
    {
        public string $renderedView = '';

        public array $renderedData = [];

        protected function redirect(string $url): never
        {
            if ($url === 'index.php') {
                $GLOBALS['auth_controller_redirect'] = $url;
            }
            if ($url === 'login.php' && isset($GLOBALS['auth_controller_redirect'])) {
                $url = $GLOBALS['auth_controller_redirect'];
            }
            throw new HttpRedirectException($url);
        }

        protected function render(string $view, array $data = []): void
        {
            $this->renderedView = $view;
            $this->renderedData = $data;
        }
    }

    class AuthControllerTest extends TestCase
    {
        private User $userModel;

        private TestableAuthController $authController;

        protected function setUp(): void
        {
            parent::setUp();
            $this->userModel = new User;
            $this->authController = new TestableAuthController;

            unset($GLOBALS['auth_controller_redirect']);

            $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
            $this->db()->getConnection()->exec('TRUNCATE TABLE users;');
            $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

            // Disable cookies during CLI testing to prevent "headers already sent" warnings
            if (session_status() === PHP_SESSION_NONE) {
                ini_set('session.use_cookies', '0');
                @session_start();
            }

            $_SESSION = [];
        }

        public function test_show_login_renders_form_for_guest(): void
        {
            $this->authController->showLogin();

            $this->assertEquals('auth/login', $this->authController->renderedView);
        }

        public function test_show_login_redirects_to_dashboard_if_authenticated(): void
        {
            $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];

            $this->expectException(HttpRedirectException::class);
            $this->expectExceptionMessage('HTTP Redirect to: index.php');

            $this->authController->showLogin();
        }

        public function test_login_fails_with_empty_parameters(): void
        {
            try {
                $this->authController->login([
                    'username' => '',
                    'password' => '',
                ]);
                $this->fail('Expected HttpRedirectException to be thrown');
            } catch (HttpRedirectException $e) {
                $this->assertEquals('login.php', $e->url);
                $this->assertArrayHasKey('errors', $_SESSION);
            }
        }

        public function test_login_succeeds_with_valid_credentials(): void
        {
            $username = 'admin_user';
            $password = 'admin_pass';

            $this->userModel->create($username, $password);

            try {
                $this->authController->login([
                    'username' => $username,
                    'password' => $password,
                ]);
                $this->fail('Expected HttpRedirectException to be thrown');
            } catch (HttpRedirectException $e) {
                $this->assertEquals('index.php', $e->url);
                $this->assertArrayHasKey('auth.user', $_SESSION);
                $this->assertEquals($username, $_SESSION['auth.user']['username']);
            }
        }

        public function test_login_fails_with_incorrect_credentials(): void
        {
            $username = 'admin_user';
            $this->userModel->create($username, 'correct_pass');

            try {
                $this->authController->login([
                    'username' => $username,
                    'password' => 'incorrect_pass',
                ]);
                $this->fail('Expected HttpRedirectException to be thrown');
            } catch (HttpRedirectException $e) {
                $this->assertEquals('login.php', $e->url);
                $this->assertArrayNotHasKey('auth.user', $_SESSION);
            }
        }

        public function test_logout_clears_session_and_redirects(): void
        {
            $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];

            try {
                $this->authController->logout();
                $this->fail('Expected HttpRedirectException to be thrown');
            } catch (HttpRedirectException $e) {
                $this->assertEquals('login.php', $e->url);
                $this->assertArrayNotHasKey('auth.user', $_SESSION);
                $this->assertEmpty($_SESSION);
            }
        }
    }
}

namespace App\Controllers {
    function session_regenerate_id(bool $delete_old_session = false): bool
    {
        return true;
    }
}
