<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SupportedLocales;
use PHPUnit\Framework\TestCase;

/**
 * Class SupportedLocalesTest
 *
 * @category Tests
 */
class SupportedLocalesTest extends TestCase
{
    public function test_enum_has_expected_values(): void
    {
        self::assertEquals('ro', SupportedLocales::RO->value);
        self::assertEquals('en', SupportedLocales::EN->value);
    }
}
