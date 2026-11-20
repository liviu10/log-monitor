<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * RedirectException Class
 *
 * Special exception used to capture and test redirects in the PHPUnit environment.
 *
 * @category Exception
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class RedirectException extends RuntimeException
{
    /**
     * Exception constructor.
     *
     * @param  string  $url  Destination URL for redirection.
     */
    public function __construct(
        private readonly string $url,
        int $code = 302,
        ?Throwable $previous = null
    ) {
        parent::__construct("Redirecting to: {$url}", $code, $previous);
    }

    /**
     * Returns the destination URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
