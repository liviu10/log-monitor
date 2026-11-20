<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LogLevel Enum
 *
 * Defines allowed logging levels in the system, according to RFC 5424 standard.
 * Uses a native Backed Enum (string) for strict typing and automatic validation.
 *
 * @category Enum
 *
 * @version  1.3
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
enum LogLevel: string
{
    /** System is unusable. */
    case EMERGENCY = 'EMERGENCY';

    /** Trebuie actionat imediat. */
    case ALERT = 'ALERT';

    /** Conditii critice. */
    case CRITICAL = 'CRITICAL';

    /** Error conditions. */
    case ERROR = 'ERROR';

    /** Warning conditions. */
    case WARNING = 'WARNING';

    /** Conditie normala, dar semnificativa. */
    case NOTICE = 'NOTICE';

    /** Mesaje informationale. */
    case INFO = 'INFO';

    /** Debug messages. */
    case DEBUG = 'DEBUG';

    /**
     * Returns all raw string values of log levels.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Checks if a given string is a valid log level.
     *
     * @param  string  $level  The level to check.
     */
    public static function isValid(string $level): bool
    {
        return self::tryFrom(strtoupper(trim($level))) !== null;
    }
}
