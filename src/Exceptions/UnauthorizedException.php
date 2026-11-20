<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * UnauthorizedException Class
 *
 * Exception thrown when a user tries to access a resource without authorization.
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
class UnauthorizedException extends RuntimeException
{
    /**
     * Exception constructor. Sets the default code to 403.
     */
    public function __construct(string $message = '', int $code = 403, ?Throwable $previous = null)
    {
        if ($message === '') {
            $message = __('Access Denied');
        }
        parent::__construct($message, $code, $previous);
    }
}
