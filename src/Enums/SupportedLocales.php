<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum for languages supported by the Aegis application.
 *
 * @category Enums
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
enum SupportedLocales: string
{
    case RO = 'ro';
    case EN = 'en';
}
