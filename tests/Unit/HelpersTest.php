<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Initialize an empty session array for testing session-based helpers
        $_SESSION = [];
    }

    public function test_format_date_converts_sql_date_to_european_date(): void
    {
        $sqlDate = '2026-05-23';
        $expected = '23.05.2026';
        $this->assertEquals($expected, formatDate($sqlDate));
    }

    public function test_format_date_handles_invalid_date_by_using_current_time(): void
    {
        $invalidDate = 'not-a-date';
        $expected = date('d.m.Y');
        $this->assertEquals($expected, formatDate($invalidDate));
    }

    public function test_can_helper_always_returns_true(): void
    {
        $this->assertTrue(can('any_permission'));
    }

    public function test_csrf_token_generation_and_verification(): void
    {
        $this->assertArrayNotHasKey('csrf_token', $_SESSION);

        $token = generateCsrfToken();

        $this->assertNotEmpty($token);
        $this->assertArrayHasKey('csrf_token', $_SESSION);
        $this->assertEquals($token, $_SESSION['csrf_token']);

        // Verify correct token passes
        $this->assertTrue(verifyCsrfToken($token));

        // Verify incorrect token fails
        $this->assertFalse(verifyCsrfToken('incorrect-token'));

        // Verify null token fails
        $this->assertFalse(verifyCsrfToken(null));
    }

    public function test_translation_helper_returns_key_when_not_found(): void
    {
        $key = 'Non-existent key string';
        $this->assertEquals($key, __($key));
    }

    public function test_translation_helper_replaces_placeholders_and_escapes_them(): void
    {
        // We test with a dummy key. If the key is not in translations, it returns the key
        // but it should still replace placeholders.
        $key = 'Hello :name! Welcome to :app.';
        $expected = 'Hello John &amp; Doe! Welcome to LogMonitor.';

        $result = __($key, [
            'name' => 'John & Doe',
            'app' => 'LogMonitor',
        ]);

        $this->assertEquals($expected, $result);
    }
}
