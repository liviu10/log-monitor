<?php

namespace App\Enums;

/**
 * LogLevel Enum
 * 
 * Defines the log levels allowed in the system, according to the RFC 5424 standard.
 * We use a Native Backed Enum (string) for strict typing and automatic validation.
 *
 * @category Enum
 * @package  App\Enums
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
enum LogLevel: string
{
    /** System is unusable. */
    case EMERGENCY = 'EMERGENCY';

    /** Must take immediate action. */
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
     * Returns all raw (string) values of the log levels.
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
     * @param string $level The level to check.
     * @return bool
     */
    public static function isValid(string $level): bool
    {
        return self::tryFrom(strtoupper($level)) !== null;
    }
}
