<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Middleware Execution Pipeline (Pipeline)
 *
 * Implements the Pipeline pattern to sequentially run middleware on an HTTP request.
 *
 * @category Core
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Pipeline
{
    /** @var mixed Obiectul/datele pasate prin pipeline (ex: Request/Route). */
    private mixed $passable;

    /** @var array<int, string> List of middleware classes that must be executed. */
    private array $pipes = [];

    /**
     * Sets the object passed through the pipeline.
     *
     * @param  mixed  $passable  The object to process.
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Sets the collection of middleware classes through which the object passes.
     *
     * @param  array<int, string>  $pipes  List of middleware classes.
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * Executes the pipeline chain and returns the final result processed by the destination.
     *
     * @param  Closure  $destination  Callback called at pipeline completion.
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $destination
        );

        /** @var Closure $pipeline */
        return $pipeline($this->passable);
    }

    /**
     * Creeaza closure-ul ce incapsuleaza apelul fiecarui middleware.
     */
    private function carry(): Closure
    {
        return function (Closure $stack, string $pipe) {
            return function (mixed $passable) use ($stack, $pipe) {
                $parts = explode(':', $pipe);
                $pipeClass = $parts[0];
                $parameter = $parts[1] ?? null;

                if (! class_exists($pipeClass)) {
                    throw new RuntimeException(__('Middleware class :class not found.', ['class' => $pipeClass]), 500);
                }

                $instance = new $pipeClass;

                if (! method_exists($instance, 'handle')) {
                    throw new RuntimeException("Middleware {$pipeClass} must implement handle().");
                }

                if ($parameter !== null) {
                    return $instance->handle($passable, $stack, $parameter);
                }

                return $instance->handle($passable, $stack);
            };
        };
    }
}
