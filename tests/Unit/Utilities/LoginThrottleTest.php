<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\LoginThrottle;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LoginThrottle.
 *
 * @category Tests
 */
class LoginThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/idh-throttle-test-'.bin2hex(random_bytes(6));
        LoginThrottle::$directory = $this->dir;
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir.'/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
        LoginThrottle::$directory = null;
        parent::tearDown();
    }

    public function test_account_is_not_locked_below_the_limit(): void
    {
        for ($i = 1; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            LoginThrottle::hit('user@example.local');
        }

        self::assertFalse(LoginThrottle::tooManyAttempts('user@example.local'));
    }

    public function test_account_is_locked_at_the_limit_case_insensitively(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            LoginThrottle::hit('User@Example.local ');
        }

        self::assertTrue(LoginThrottle::tooManyAttempts('user@example.local'));
        self::assertFalse(LoginThrottle::tooManyAttempts('other@example.local'));
    }

    public function test_clear_resets_the_counter(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            LoginThrottle::hit('user@example.local');
        }
        LoginThrottle::clear('user@example.local');

        self::assertFalse(LoginThrottle::tooManyAttempts('user@example.local'));
    }

    public function test_expired_window_starts_a_new_count(): void
    {
        $file = $this->dir.'/login_'.hash('sha256', 'user@example.local').'.json';
        mkdir($this->dir, 0750, true);
        file_put_contents($file, (string) json_encode([
            'count' => LoginThrottle::MAX_ATTEMPTS,
            'first' => time() - LoginThrottle::WINDOW_SECONDS - 1,
        ]));

        self::assertFalse(LoginThrottle::tooManyAttempts('user@example.local'));

        LoginThrottle::hit('user@example.local');
        $state = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($state);
        self::assertSame(1, $state['count']);
    }

    public function test_no_email_address_is_stored_in_clear_text(): void
    {
        LoginThrottle::hit('secret@example.local');

        $files = glob($this->dir.'/*');
        if ($files !== false) {
            foreach ($files as $file) {
                self::assertStringNotContainsString('secret', $file);
                self::assertStringNotContainsString('secret', (string) file_get_contents($file));
            }
        }
    }
}
