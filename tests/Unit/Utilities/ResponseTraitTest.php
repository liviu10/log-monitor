<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Exceptions\RedirectException;
use App\Utilities\ResponseTrait;
use PHPUnit\Framework\TestCase;

// Define the constant so redirect() throws instead of exit;
if (! defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

/**
 * Dummy class to expose protected trait methods.
 */
class ResponseTraitDummy
{
    use ResponseTrait {
        redirect as public;
        flash as public;
        redirectWithFlash as public;
        json as public;
    }
}

/**
 * Class ResponseTraitTest
 *
 * @category Tests
 */
class ResponseTraitTest extends TestCase
{
    private ResponseTraitDummy $dummy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dummy = new ResponseTraitDummy;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Tests that redirect throws RedirectException when PHPUNIT_RUNNING is defined.
     */
    public function test_redirect_throws_exception_when_phpunit_is_running(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('/target-url');

        $this->dummy->redirect('/target-url');
    }

    /**
     * Tests that flash() properly calls the global setFlash function.
     */
    public function test_flash_calls_global_function(): void
    {
        $this->dummy->flash('success', 'Great', 'Operation successful');

        self::assertArrayHasKey('app_flash', $_SESSION);
        $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
        self::assertEquals('success', $flash['type'] ?? '');
        self::assertEquals('Great', $flash['title'] ?? '');
        self::assertEquals('Operation successful', $flash['message'] ?? '');
    }

    /**
     * Tests that redirectWithFlash() sets the flash and then throws RedirectException.
     */
    public function test_redirect_with_flash_sets_flash_and_redirects(): void
    {
        try {
            $this->dummy->redirectWithFlash('/home', 'error', 'Oops', 'Something went wrong');
        } catch (RedirectException $e) {
            self::assertEquals('/home', $e->getUrl());
        }

        self::assertArrayHasKey('app_flash', $_SESSION);
        $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
        self::assertEquals('error', $flash['type'] ?? '');
        self::assertEquals('Oops', $flash['title'] ?? '');
        self::assertEquals('Something went wrong', $flash['message'] ?? '');
    }
}
