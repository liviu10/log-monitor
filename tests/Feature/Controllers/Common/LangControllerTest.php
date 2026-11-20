<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Common;

use App\Core\Router;
use App\Exceptions\RedirectException;
use App\Utilities\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Class LangControllerTest
 *
 * Feature tests for the LangController.
 *
 * @category Tests
 */
class LangControllerTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SESSION = [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->router = new Router;
        $this->router->get('/change-lang', 'Common\LangController@change');

        config('::reset');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_SERVER['HTTP_REFERER'], $_GET['lang']);

        config('::reset');

        parent::tearDown();
    }

    public function test_change_language_to_en(): void
    {
        $_GET['lang'] = 'en';

        try {
            $this->router->dispatch('GET', '/change-lang');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            self::assertEquals('en', SessionManager::get('app_lang'));
            self::assertEquals('/', $e->getUrl());
        }
    }

    public function test_change_language_defaults_to_en(): void
    {
        try {
            $this->router->dispatch('GET', '/change-lang');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            self::assertEquals('en', SessionManager::get('app_lang'));
            self::assertEquals('/', $e->getUrl());
        }
    }

    public function test_change_language_to_ro(): void
    {
        $_GET['lang'] = 'ro';

        try {
            $this->router->dispatch('GET', '/change-lang');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            self::assertEquals('ro', SessionManager::get('app_lang'));
            self::assertEquals('/', $e->getUrl());
        }
    }

    public function test_change_language_ignores_invalid_value(): void
    {
        $_GET['lang'] = 'fr'; // invalid
        SessionManager::set('app_lang', 'ro');

        try {
            $this->router->dispatch('GET', '/change-lang');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            // Should stay 'ro'
            self::assertEquals('ro', SessionManager::get('app_lang'));
        }
    }

    public function test_redirects_to_referer_if_valid_domain(): void
    {
        $_GET['lang'] = 'en';

        // Mocking the referer on same host as APP_URL
        $appUrl = defined('APP_URL') && is_string(APP_URL) ? APP_URL : 'http://localhost';
        $parsedHost = parse_url($appUrl, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : 'localhost';

        $_SERVER['HTTP_REFERER'] = 'http://'.$host.'/some/page';
        config('::reset'); // Reset config so it re-reads $_SERVER

        try {
            $this->router->dispatch('GET', '/change-lang');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            self::assertEquals('http://'.$host.'/some/page', $e->getUrl());
        }
    }

    public function test_prevents_open_redirect(): void
    {
        $_GET['lang'] = 'en';
        $_SERVER['HTTP_REFERER'] = 'http://malicious.example.com/login';
        config('::reset');

        try {
            $this->router->dispatch('GET', '/change-lang');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            self::assertEquals('/', $e->getUrl());
        }
    }
}
