<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LogLevel Enum
 * * Defines the allowed logging levels in the system, according to RFC 5424.
 * A Native Backed Enum (string) is used for strict typing and automatic validation.
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
    /** The system is unusable. */
    case EMERGENCY = 'EMERGENCY';

    /** Action must be taken immediately. */
    case ALERT = 'ALERT';

    /** Critical conditions. */
    case CRITICAL = 'CRITICAL';

    /** Error conditions. */
    case ERROR = 'ERROR';

    /** Warning conditions. */
    case WARNING = 'WARNING';

    /** Normal but significant condition. */
    case NOTICE = 'NOTICE';

    /** Informational messages. */
    case INFO = 'INFO';

    /** Debug messages. */
    case DEBUG = 'DEBUG';

    /**
     * Returns all raw (string) values of the logging levels.
     *
     * * @return array<int, string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Verifies if a given string is a valid logging level.
     * Implements Fail Fast philosophy via defensive input processing.
     *
     * * @param string $level The level to check.
     */
    public static function isValid(string $level): bool
    {
        return self::tryFrom(strtoupper(trim($level))) !== null;
    }
}
