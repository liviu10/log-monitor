<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LogLevel Enum
 * * Defineste nivelurile de logare permise in sistem, conform standardului RFC 5424.
 * Se utilizeaza un Native Backed Enum (string) pentru tipizare stricta si validare automata.
 *
 * @category Enum
 * @package  App\Enums
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
enum LogLevel: string
{
    /** Sistemul este inutilizabil. */
    case EMERGENCY = 'EMERGENCY';

    /** Trebuie actionat imediat. */
    case ALERT = 'ALERT';

    /** Conditii critice. */
    case CRITICAL = 'CRITICAL';

    /** Conditii de eroare. */
    case ERROR = 'ERROR';

    /** Conditii de avertisment. */
    case WARNING = 'WARNING';

    /** Conditie normala, dar semnificativa. */
    case NOTICE = 'NOTICE';

    /** Mesaje informationale. */
    case INFO = 'INFO';

    /** Mesaje de depanare (debug). */
    case DEBUG = 'DEBUG';

    /**
     * Returneaza toate valorile brute (string) ale nivelurilor de logare.
     * * @return array<int, string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Verifica daca un string dat este un nivel de logare valid.
     * Implementeaza filozofia Fail Fast prin procesarea defensiva a inputului.
     * * @param string $level Nivelul care trebuie verificat.
     * @return bool
     */
    public static function isValid(string $level): bool
    {
        return self::tryFrom(strtoupper(trim($level))) !== null;
    }
}