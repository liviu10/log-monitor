<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\RedirectException;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class RedirectExceptionTest
 *
 * @category Tests
 */
class RedirectExceptionTest extends TestCase
{
    public function test_constructor_builds_message_and_sets_default_code(): void
    {
        $exception = new RedirectException('/login');

        self::assertEquals('Redirecting to: /login', $exception->getMessage());
        self::assertEquals(302, $exception->getCode());
    }

    public function test_get_url_returns_correct_url(): void
    {
        $exception = new RedirectException('/dashboard');

        self::assertEquals('/dashboard', $exception->getUrl());
    }

    public function test_custom_code_and_previous_exception(): void
    {
        $previous = new Exception('Previous');
        $exception = new RedirectException('/error', 301, $previous);

        self::assertEquals(301, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
