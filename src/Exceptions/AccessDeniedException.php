<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when a user tries to access a resource without permissions.
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
class AccessDeniedException extends Exception
{
    /**
     * Exception constructor.
     */
    public function __construct(string $message = 'Access Denied', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}
