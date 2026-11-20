<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\LogLevel;
use PHPUnit\Framework\TestCase;

/**
 * Class LogLevelTest
 *
 * @category Tests
 */
class LogLevelTest extends TestCase
{
    public function test_enum_has_expected_values(): void
    {
        self::assertEquals('EMERGENCY', LogLevel::EMERGENCY->value);
        self::assertEquals('ERROR', LogLevel::ERROR->value);
        self::assertEquals('INFO', LogLevel::INFO->value);
    }

    public function test_all_returns_array_of_all_values(): void
    {
        $all = LogLevel::all();
        self::assertContains('EMERGENCY', $all);
        self::assertContains('DEBUG', $all);
        self::assertCount(8, $all); // Assuming 8 levels are defined
    }

    public function test_is_valid_returns_true_for_valid_levels(): void
    {
        self::assertTrue(LogLevel::isValid('ERROR'));
        self::assertTrue(LogLevel::isValid('error')); // Should pass if we test trim & strtoupper
        self::assertTrue(LogLevel::isValid(' INFO ')); // Testing trim
    }

    public function test_is_valid_returns_false_for_invalid_levels(): void
    {
        self::assertFalse(LogLevel::isValid('UNKNOWN'));
        self::assertFalse(LogLevel::isValid(''));
    }
}
