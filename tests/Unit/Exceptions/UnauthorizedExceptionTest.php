<?php

declare(strict_types=1);

namespace App\Exceptions {
    if (! function_exists('App\Exceptions\__')) {
        function __(string $text): string
        {
            // Simple mock: just return the string with a prefix to verify it was called
            return 'Translated: '.$text;
        }
    }
}

namespace Tests\Unit\Exceptions {
    use App\Exceptions\UnauthorizedException;
    use Exception;
    use PHPUnit\Framework\TestCase;

    /**
     * Class UnauthorizedExceptionTest
     *
     * @category Tests
     */
    class UnauthorizedExceptionTest extends TestCase
    {
        public function test_default_constructor_uses_translation_and_sets_403(): void
        {
            $exception = new UnauthorizedException;

            self::assertEquals('Translated: Access Denied', $exception->getMessage());
            self::assertEquals(403, $exception->getCode());
        }

        public function test_custom_message_bypasses_translation(): void
        {
            $exception = new UnauthorizedException('Custom Error');

            // Should not have the "Translated: " prefix because it bypasses __() when not empty
            self::assertEquals('Custom Error', $exception->getMessage());
        }

        public function test_custom_code_and_previous_exception(): void
        {
            $previous = new Exception('Prev');
            $exception = new UnauthorizedException('Err', 401, $previous);

            self::assertEquals(401, $exception->getCode());
            self::assertSame($previous, $exception->getPrevious());
        }
    }
}
