<?php

declare(strict_types=1);

/**
 * User Roles Enum (Role)
 *
 * Defines possible user roles in Aegis.
 *
 * @category Enums
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */

namespace App\Enums;

enum Role: string
{
    case ADMIN = 'ADMIN';
    case USER = 'USER';
}
