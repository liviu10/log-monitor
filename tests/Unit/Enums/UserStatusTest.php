<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

/**
 * Class UserStatusTest
 *
 * @category Tests
 */
class UserStatusTest extends TestCase
{
    public function test_enum_has_expected_values(): void
    {
        self::assertEquals('ACTIVE', UserStatus::ACTIVE->value);
        self::assertEquals('INACTIVE', UserStatus::INACTIVE->value);
    }

    public function test_label_returns_correct_string(): void
    {
        self::assertEquals('Active', UserStatus::ACTIVE->label());
        self::assertEquals('Inactive', UserStatus::INACTIVE->label());
    }

    public function test_get_label_returns_correct_label_for_valid_value(): void
    {
        self::assertEquals('Active', UserStatus::getLabel('ACTIVE'));
        self::assertEquals('Inactive', UserStatus::getLabel('INACTIVE'));
    }

    public function test_get_label_returns_original_value_if_invalid(): void
    {
        self::assertEquals('UNKNOWN_STATUS', UserStatus::getLabel('UNKNOWN_STATUS'));
    }

    public function test_get_label_returns_empty_string_if_null(): void
    {
        self::assertEquals('', UserStatus::getLabel(null));
    }
}
