<?php

namespace App\Enums;

/**
 * Enum LogLevel
 * 
 * Definește nivelurile de logare permise în sistem, conform standardului RFC 5424.
 * Utilizăm un Native Backed Enum (string) pentru o tipizare strictă și validare automată.
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
    /** Sistemul este inutilizabil. */
    case EMERGENCY = 'EMERGENCY';

    /** Trebuie luată o măsură imediată. */
    case ALERT = 'ALERT';

    /** Condiții critice. */
    case CRITICAL = 'CRITICAL';

    /** Condiții de eroare. */
    case ERROR = 'ERROR';

    /** Condiții de avertizare. */
    case WARNING = 'WARNING';

    /** Condiție normală, dar semnificativă. */
    case NOTICE = 'NOTICE';

    /** Mesaje informative. */
    case INFO = 'INFO';

    /** Mesaje de depanare (debug). */
    case DEBUG = 'DEBUG';

    /**
     * Returnează toate valorile brute (string) ale nivelurilor de logare.
     * 
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Verifică dacă un anumit șir de caractere este un nivel de logare valid.
     * 
     * @param string $level Nivelul de verificat.
     * @return bool
     */
    public static function isValid(string $level): bool
    {
        return self::tryFrom(strtoupper($level)) !== null;
    }
}
