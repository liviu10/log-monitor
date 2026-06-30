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
class LogLevel
{
    /** Sistemul este inutilizabil. */
    public const EMERGENCY = 'EMERGENCY';

    /** Trebuie actionat imediat. */
    public const ALERT = 'ALERT';

    /** Conditii critice. */
    public const CRITICAL = 'CRITICAL';

    /** Conditii de eroare. */
    public const ERROR = 'ERROR';

    /** Conditii de avertisment. */
    public const WARNING = 'WARNING';

    /** Conditie normala, dar semnificativa. */
    public const NOTICE = 'NOTICE';

    /** Mesaje informationale. */
    public const INFO = 'INFO';

    /** Mesaje de depanare (debug). */
    public const DEBUG = 'DEBUG';

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
     */
    public static function isValid(string $level): bool
    {
        return self::tryFrom(strtoupper(trim($level))) !== null;
    }

    /**
     * Returneaza toate cazurile enum-ului sub forma de obiecte cu proprietatile name si value.
     */
    public static function cases(): array
    {
        $reflection = new \ReflectionClass(self::class);
        $constants = $reflection->getConstants();
        $cases = [];
        foreach ($constants as $name => $value) {
            $cases[] = new class($name, $value) {
                /**
                 * Numele cazului.
                 */
                public string $name;

                /**
                 * Valoarea cazului.
                 */
                public string $value;

                /**
                 * Constructor caz.
                 */
                public function __construct(string $name, string $value) {
                    $this->name = $name;
                    $this->value = $value;
                }
            };
        }

        return $cases;
    }

    /**
     * Cauta si returneaza cazul corespunzator valorii transmise.
     *
     * @param string $value
     */
    public static function tryFrom($value): ?object
    {
        $reflection = new \ReflectionClass(self::class);
        $constants = $reflection->getConstants();
        if (in_array($value, $constants, true)) {
            return new class($value) {
                /**
                 * Valoarea cazului.
                 */
                public string $value;

                /**
                 * Constructor caz.
                 */
                public function __construct(string $value) {
                    $this->value = $value;
                }
            };
        }

        return null;
    }

    /**
     * Returneaza cazul corespunzator valorii transmise sau arunca o eroare.
     *
     * @throws \ValueError
     */
    public static function from(string $value): object
    {
        $result = self::tryFrom($value);
        if ($result === null) {
            throw new \ValueError(sprintf("Valoarea '%s' nu este valida pentru enum-ul ", $value) . self::class);
        }

        return $result;
    }
}