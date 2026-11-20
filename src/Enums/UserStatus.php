<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * User Status Enum (UserStatus)
 *
 * Defines possible states for a user account (ACTIVE or INACTIVE).
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
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    /**
     * Returns the friendly label of the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    /**
     * Static helper to get the corresponding label for a database value.
     */
    public static function getLabel(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $enum = self::tryFrom($value);

        return $enum !== null ? $enum->label() : $value;
    }
}
