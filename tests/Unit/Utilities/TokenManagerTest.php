<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\TokenManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TokenManager class.
 */
class TokenManagerTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $originalSession;

    /**
     * Set up the test environment by ensuring $_SESSION is available and empty.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSession = $_SESSION ?? [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Initialize $_SESSION as an empty array to ensure a clean state for every test.
        $_SESSION = [];
    }

    /**
     * Clean up the test environment by clearing the session.
     */
    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        parent::tearDown();
    }

    // --- Generate CSRF Tests ---

    public function test_generate_csrf_generates_new_token_when_no_session_token_exists(): void
    {
        unset($_SESSION['csrf_token']);

        $token = TokenManager::generateCsrf();

        self::assertNotEmpty($token);

        $session = $GLOBALS['_SESSION'] ?? null;
        self::assertIsArray($session);
        self::assertArrayHasKey('csrf_token', $session);
        self::assertEquals($token, $session['csrf_token']);
    }

    public function test_generate_csrf_returns_existing_token_if_session_token_exists(): void
    {
        $existingToken = 'existing_secure_token_12345';
        $_SESSION['csrf_token'] = $existingToken;

        $token = TokenManager::generateCsrf();

        self::assertEquals($existingToken, $token);
        self::assertEquals($existingToken, $_SESSION['csrf_token']);
    }

    public function test_generate_csrf_regenerates_token_if_session_token_is_not_a_string(): void
    {
        $_SESSION['csrf_token'] = 123456789;

        $token = TokenManager::generateCsrf();

        self::assertNotEquals(123456789, $token);
        self::assertIsString($_SESSION['csrf_token']);
    }

    public function test_generate_csrf_token_has_correct_hexadecimal_format(): void
    {
        $token = TokenManager::generateCsrf();

        self::assertEquals(64, strlen($token));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function test_generate_csrf_tokens_are_unique_on_consecutive_calls(): void
    {
        $token1 = TokenManager::generateCsrf();
        $_SESSION['csrf_token'] = null;
        $token2 = TokenManager::generateCsrf();

        self::assertNotEquals($token1, $token2);
    }

    public function test_generate_csrf_starts_session_if_none_exists(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::assertSame(PHP_SESSION_NONE, session_status());

        $token = TokenManager::generateCsrf();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertNotEmpty($token);
    }

    // --- Verify CSRF Tests ---

    public function test_verify_csrf_returns_true_for_valid_token(): void
    {
        $validToken = 'secure-random-token-123';
        $_SESSION['csrf_token'] = $validToken;

        self::assertTrue(TokenManager::verifyCsrf($validToken));
    }

    public function test_verify_csrf_returns_false_for_invalid_token(): void
    {
        $_SESSION['csrf_token'] = 'secure-random-token-123';

        self::assertFalse(TokenManager::verifyCsrf('wrong-token'));
    }

    public function test_verify_csrf_returns_false_if_session_token_is_missing(): void
    {
        self::assertFalse(TokenManager::verifyCsrf('some-token'));
    }

    public function test_verify_csrf_returns_false_if_provided_token_is_null(): void
    {
        $_SESSION['csrf_token'] = 'secure-random-token-123';

        self::assertFalse(TokenManager::verifyCsrf(null));
    }

    public function test_verify_csrf_returns_false_if_session_token_is_not_string(): void
    {
        $_SESSION['csrf_token'] = ['array-token'];

        self::assertFalse(TokenManager::verifyCsrf('string-token'));
    }

    public function test_verify_csrf_starts_session_if_none_exists(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @TokenManager::verifyCsrf('dummy-token');

        self::assertEquals(PHP_SESSION_ACTIVE, session_status());
    }

    // --- Webhook Token Tests ---

    /**
     * Helper to generate a webhook token for testing.
     */
    private function generateValidWebhookToken(int $expirationTime, string $type = 'webhook', ?string $overrideKey = null): string
    {
        $rawKey = $overrideKey ?? ($_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!');
        $key = is_string($rawKey) && $rawKey !== '' ? $rawKey : 'logmonitor-secret-key-32-chars!!';
        $payload = json_encode(['type' => $type, 'expires_at' => $expirationTime]);
        if (! is_string($payload)) {
            $payload = '{"type":"webhook","expires_at":'.$expirationTime.'}';
        }

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($payload, 'aes-256-cbc', $key, 0, $iv);
        if (! is_string($encrypted)) {
            $encrypted = '';
        }

        $mac = hash_hmac('sha256', $iv.$encrypted, $key);

        return base64_encode($iv.$encrypted.$mac);
    }

    /**
     * Tests that consumeWebhookToken returns true for a valid token.
     */
    public function test_consume_webhook_token_returns_true_for_valid_token(): void
    {
        $token = $this->generateValidWebhookToken(time() + 3600);
        self::assertTrue(TokenManager::consumeWebhookToken($token));
    }

    /**
     * Tests that guest/admin API tokens (same format and key) are rejected.
     */
    public function test_consume_webhook_token_rejects_non_webhook_token_types(): void
    {
        self::assertFalse(TokenManager::consumeWebhookToken($this->generateValidWebhookToken(time() + 3600, 'guest')));
        self::assertFalse(TokenManager::consumeWebhookToken($this->generateValidWebhookToken(time() + 3600, 'admin')));
    }

    /**
     * Tests that consumeWebhookToken returns false for an expired token.
     */
    public function test_consume_webhook_token_returns_false_for_expired_token(): void
    {
        $token = $this->generateValidWebhookToken(time() - 3600);
        self::assertFalse(TokenManager::consumeWebhookToken($token));
    }

    /**
     * Tests that consumeWebhookToken returns false when MAC is invalid.
     */
    public function test_consume_webhook_token_returns_false_when_mac_is_invalid(): void
    {
        $token = $this->generateValidWebhookToken(time() + 3600);
        $decoded = base64_decode($token, true);
        if (! is_string($decoded)) {
            self::fail('Base64 decode failed.');
        }

        // Alter the MAC (last 64 characters) ensuring we actually change the character
        $lastChar = substr($decoded, -1);
        $newChar = $lastChar === '0' ? '1' : '0';
        $alteredDecoded = substr_replace($decoded, $newChar, -1, 1);
        $alteredToken = base64_encode($alteredDecoded);

        self::assertFalse(TokenManager::consumeWebhookToken($alteredToken));
    }

    /**
     * Tests that consumeWebhookToken returns false for malformed tokens.
     */
    public function test_consume_webhook_token_returns_false_for_malformed_token(): void
    {
        self::assertFalse(TokenManager::consumeWebhookToken('too-short-token'));
        self::assertFalse(TokenManager::consumeWebhookToken(base64_encode('not-enough-bytes-for-iv-and-mac-1234567890')));
    }

    public function test_consume_webhook_token_returns_false_for_empty_or_null(): void
    {
        self::assertFalse(TokenManager::consumeWebhookToken(null));
        self::assertFalse(TokenManager::consumeWebhookToken(''));
    }

    public function test_consume_webhook_token_fallback_key(): void
    {
        $origKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = '';
        if (function_exists('config')) {
            config('::reset');
        }

        try {
            $token = $this->generateValidWebhookToken(time() + 3600, 'webhook', 'logmonitor-secret-key-32-chars!!');
            self::assertTrue(TokenManager::consumeWebhookToken($token));
        } finally {
            if ($origKey !== null) {
                $_ENV['APP_KEY'] = $origKey;
            } else {
                unset($_ENV['APP_KEY']);
            }
            if (function_exists('config')) {
                config('::reset');
            }
        }
    }

    public function test_consume_webhook_token_missing_expires_at(): void
    {
        $rawKey = $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!';
        $key = is_string($rawKey) ? $rawKey : 'logmonitor-secret-key-32-chars!!';
        $payload = json_encode(['data' => 'no_expiration']);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt((string) $payload, 'aes-256-cbc', $key, 0, $iv);
        $mac = hash_hmac('sha256', $iv.$encrypted, $key);
        $token = base64_encode($iv.$encrypted.$mac);

        self::assertFalse(TokenManager::consumeWebhookToken($token));
    }

    public function test_consume_webhook_token_decrypt_failure(): void
    {
        $rawKey = $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!';
        $key = is_string($rawKey) ? $rawKey : 'logmonitor-secret-key-32-chars!!';
        $iv = random_bytes(16);
        $fakeEncrypted = str_repeat('x', 32);
        $mac = hash_hmac('sha256', $iv.$fakeEncrypted, $key);
        $token = base64_encode($iv.$fakeEncrypted.$mac);

        self::assertFalse(TokenManager::consumeWebhookToken($token));
    }
}
