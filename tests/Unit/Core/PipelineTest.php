<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Pipeline;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Dummy classes for testing the Pipeline

class DummyMiddleware1
{
    /**
     * @param  array<int, string>  $passable
     * @param  \Closure(array<int, string>): array<int, string>  $next
     * @return array<int, string>
     */
    public function handle(array $passable, \Closure $next): array
    {
        $passable[] = 'MW1_START';
        $passable = $next($passable);
        $passable[] = 'MW1_END';

        return $passable;
    }
}

class DummyMiddleware2
{
    /**
     * @param  array<int, string>  $passable
     * @param  \Closure(array<int, string>): array<int, string>  $next
     * @return array<int, string>
     */
    public function handle(array $passable, \Closure $next): array
    {
        $passable[] = 'MW2_START';
        $passable = $next($passable);
        $passable[] = 'MW2_END';

        return $passable;
    }
}

class DummyMiddlewareWithParam
{
    /**
     * @param  array<int, string>  $passable
     * @param  \Closure(array<int, string>): array<int, string>  $next
     * @return array<int, string>
     */
    public function handle(array $passable, \Closure $next, string $param): array
    {
        $passable[] = 'MW_PARAM_'.$param;

        return $next($passable);
    }
}

class DummyMiddlewareNoHandle
{
    // Missing handle() method
}

/**
 * Class PipelineTest
 *
 * @category Tests
 */
class PipelineTest extends TestCase
{
    public function test_pipeline_executes_middleware_in_order(): void
    {
        $pipeline = new Pipeline;

        $result = $pipeline
            ->send(['INITIAL'])
            ->through([
                DummyMiddleware1::class,
                DummyMiddleware2::class,
            ])
            ->then(function (array $passable) {
                $passable[] = 'DESTINATION';

                return $passable;
            });

        $expected = [
            'INITIAL',
            'MW1_START',
            'MW2_START',
            'DESTINATION',
            'MW2_END',
            'MW1_END',
        ];

        self::assertEquals($expected, $result);
    }

    public function test_pipeline_passes_parameters_to_middleware(): void
    {
        $pipeline = new Pipeline;

        $result = $pipeline
            ->send(['START'])
            ->through([
                DummyMiddlewareWithParam::class.':admin',
                DummyMiddlewareWithParam::class.':user',
            ])
            ->then(function (array $passable) {
                $passable[] = 'END';

                return $passable;
            });

        $expected = [
            'START',
            'MW_PARAM_admin',
            'MW_PARAM_user',
            'END',
        ];

        self::assertEquals($expected, $result);
    }

    public function test_pipeline_throws_exception_if_class_not_found(): void
    {
        $this->expectException(RuntimeException::class);

        $pipeline = new Pipeline;
        $pipeline
            ->send([])
            ->through(['NonExistentMiddlewareClass'])
            ->then(function ($passable) {
                return $passable;
            });
    }

    public function test_pipeline_throws_exception_if_handle_method_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Middleware '.DummyMiddlewareNoHandle::class.' must implement handle().');

        $pipeline = new Pipeline;
        $pipeline
            ->send([])
            ->through([DummyMiddlewareNoHandle::class])
            ->then(function ($passable) {
                return $passable;
            });
    }
}
