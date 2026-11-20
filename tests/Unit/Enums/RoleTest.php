<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

/**
 * Class RoleTest
 *
 * @category Tests
 */
class RoleTest extends TestCase
{
    public function test_enum_has_expected_values(): void
    {
        self::assertEquals('ADMIN', Role::ADMIN->value);
        self::assertEquals('USER', Role::USER->value);
    }
}
