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

    public function testFormatDateConvertsSqlDateToEuropeanDate(): void
    {
        $sqlDate = '2026-05-23';
        $expected = '23.05.2026';
        $this->assertEquals($expected, formatDate($sqlDate));
    }

    public function testFormatDateHandlesInvalidDateByUsingCurrentTime(): void
    {
        $invalidDate = 'not-a-date';
        $expected = date('d.m.Y');
        $this->assertEquals($expected, formatDate($invalidDate));
    }

    public function testCanHelperAlwaysReturnsTrue(): void
    {
        $this->assertTrue(can('any_permission'));
    }

    public function testCsrfTokenGenerationAndVerification(): void
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

    public function testTranslationHelperReturnsKeyWhenNotFound(): void
    {
        $key = 'Non-existent key string';
        $this->assertEquals($key, __($key));
    }

    public function testTranslationHelperReplacesPlaceholdersAndEscapesThem(): void
    {
        // We test with a dummy key. If the key is not in translations, it returns the key
        // but it should still replace placeholders.
        $key = 'Hello :name! Welcome to :app.';
        $expected = 'Hello John &amp; Doe! Welcome to LogMonitor.';

        $result = __($key, [
            'name' => 'John & Doe',
            'app' => 'LogMonitor'
        ]);

        $this->assertEquals($expected, $result);
    }
}
