<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\AccessDeniedException;
use PHPUnit\Framework\TestCase;

/**
 * Class AccessDeniedExceptionTest
 *
 * @category Tests
 */
class AccessDeniedExceptionTest extends TestCase
{
    public function test_default_constructor_sets_correct_message_and_code(): void
    {
        $exception = new AccessDeniedException;

        self::assertEquals('Access Denied', $exception->getMessage());
        self::assertEquals(403, $exception->getCode());
    }

    public function test_custom_constructor_arguments_override_defaults(): void
    {
        $exception = new AccessDeniedException('Custom Error', 500);

        self::assertEquals('Custom Error', $exception->getMessage());
        self::assertEquals(500, $exception->getCode());
    }
}
