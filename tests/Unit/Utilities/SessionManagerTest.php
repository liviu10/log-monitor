<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Class SessionManagerTest
 *
 * @category Tests
 */
class SessionManagerTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $originalSession;

    protected function setUp(): void
    {
        parent::setUp();

        // Save original session and mock an empty one for the test
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Restore original session
        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    public function test_set_adds_value_to_session(): void
    {
        SessionManager::set('test_key', 'test_value');

        self::assertArrayHasKey('test_key', $_SESSION);
        self::assertEquals('test_value', $_SESSION['test_key']);
    }

    public function test_get_retrieves_value_from_session(): void
    {
        $_SESSION['existing_key'] = 'existing_value';

        $result = SessionManager::get('existing_key');

        self::assertEquals('existing_value', $result);
    }

    public function test_get_returns_default_when_key_does_not_exist(): void
    {
        $result = SessionManager::get('missing_key', 'default_fallback');

        self::assertEquals('default_fallback', $result);
    }

    public function test_get_returns_null_default_when_key_does_not_exist(): void
    {
        $result = SessionManager::get('missing_key');

        self::assertNull($result);
    }

    public function test_has_returns_true_when_key_exists(): void
    {
        $_SESSION['active_key'] = 123;

        self::assertTrue(SessionManager::has('active_key'));
    }

    public function test_has_returns_false_when_key_does_not_exist(): void
    {
        self::assertFalse(SessionManager::has('inactive_key'));
    }

    public function test_remove_deletes_value_from_session(): void
    {
        $_SESSION['key_to_remove'] = 'will be deleted';

        SessionManager::remove('key_to_remove');

        self::assertArrayNotHasKey('key_to_remove', $_SESSION);
    }

    public function test_remove_does_nothing_if_key_does_not_exist(): void
    {
        // Should not throw any errors/warnings
        SessionManager::remove('non_existent_key');

        self::assertArrayNotHasKey('non_existent_key', $_SESSION);
    }

    public function test_clear_removes_all_values_from_session(): void
    {
        $_SESSION = [
            'key1' => 'val1',
            'key2' => 'val2',
            'key3' => 'val3',
        ];

        SessionManager::clear();

        self::assertEmpty($_SESSION);
    }

    /**
     * Test that critical system keys cannot be overwritten directly via SessionManager.
     */
    public function test_set_blocks_critical_system_keys(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot overwrite critical system session keys.');

        SessionManager::set('csrf_token', 'malicious_token');
    }
}
