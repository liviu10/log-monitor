<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Validator Class
 *
 * Incoming data validation (forms, APIs) according to field-declared rules.
 * Invalid data does not throw an exception: it produces an error per field (stable code + message).
 * Incorrectly written rules (unknown rule, missing parameter, invalid regex) throw an
 * InvalidArgumentException upon construction, before anything is validated.
 *
 * Rules:
 *   required          field exists and is not empty (null, '', whitespace only, [])
 *   string, int, numeric, bool, array
 *                     data type; int/numeric/bool also accept form strings ("12", "1.5", "yes")
 *   email             valid email address (without DNS check)
 *   min:n, max:n, between:a,b
 *                     number (with int/numeric or int/float value), characters (string), or items (array)
 *   in:a,b,c          one of the listed values (exact string comparison)
 *   date, date:d.m.Y  real calendar date in the specified format (default Y-m-d)
 *   regex:/^.../      pattern matches; in pipe-separated syntax, must be the last rule and may contain "|"
 *   confirmed         the <name>_confirmation field has the same value
 *   same:field        another field has the same value
 *   'code' => Closure custom rule: fn (mixed $value): bool; 'code' is the error code
 *                     (for cross-field rules, pass data using 'use')
 *
 * Behavior:
 *   - an empty field without 'required' is not checked further (optional field);
 *   - check order: 'required', type rules, then remaining rules in declared order; first failed
 *     rule yields the field error (single error per field);
 *   - invalid UTF-8 strings are rejected with the 'encoding' code;
 *   - values are not converted: validated() returns input data as received (see Cast);
 *   - error messages do not expose the user input value.
 *
 * Usage:
 *   require_once 'Validator.php';
 *   use App\Utilities\Validator;
 *
 *   $validator = new Validator($_POST, [
 *       'email' => 'required|email|max:255',
 *       'varsta' => 'int|between:18,99',
 *       'parola' => 'required|string|min:8|confirmed',
 *       'tip' => 'required|in:PF,PJ',
 *       'data_nasterii' => 'required|date:d.m.Y',
 *       'cnp' => ['required', 'cnp' => RoValidator::cnp(...)],
 *   ], messages: ['required' => 'The :field field is required.', 'cnp' => 'Invalid CNP.'],
 *      labels: ['data_nasterii' => 'birth date']);
 *
 *   if ($validator->fails()) {
 *       $errors = $validator->errors();   // ['email' => 'The email field must be a valid email address.']
 *       $codes = $validator->codes();     // ['email' => 'email']
 *   }
 *
 *   $data = Validator::validate($payload, $rules); // validated data or InvalidArgumentException
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Validator
{
    /** @var array<string, string> Rules and their parameters: none, number, numbers2, list, format, pattern, field. */
    private const array RULES = [
        'required' => 'none',
        'string' => 'none',
        'int' => 'none',
        'numeric' => 'none',
        'bool' => 'none',
        'array' => 'none',
        'email' => 'none',
        'min' => 'number',
        'max' => 'number',
        'between' => 'numbers2',
        'in' => 'list',
        'date' => 'format',
        'regex' => 'pattern',
        'confirmed' => 'none',
        'same' => 'field',
    ];

    /** @var list<string> Type rules, checked immediately after required. */
    private const array TYPES = ['string', 'int', 'numeric', 'bool', 'array'];

    /** @var list<string> Accepted strings for bool rule (as in Cast::toBool). */
    private const array BOOLEANS = ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off', 'da', 'nu'];

    /** @var string Decimal number with dot, without exponent: 12, -3, 1.50. */
    private const string NUMERIC_PATTERN = '/^[+-]?\d+(\.\d+)?$/';

    private const string DEFAULT_DATE_FORMAT = 'Y-m-d';

    /** @var array<string, string> Default messages; min/max/between have variants based on value type. */
    private const array MESSAGES = [
        'required' => 'The :field field is required.',
        'encoding' => 'The :field field contains invalid characters.',
        'string' => 'The :field field must be text.',
        'int' => 'The :field field must be an integer.',
        'numeric' => 'The :field field must be a number.',
        'bool' => 'The :field field must be true or false.',
        'array' => 'The :field field must be a list.',
        'email' => 'The :field field must be a valid email address.',
        'min.number' => 'The :field field must be at least :min.',
        'min.string' => 'The :field field must be at least :min characters.',
        'min.array' => 'The :field field must have at least :min items.',
        'max.number' => 'The :field field must not be greater than :max.',
        'max.string' => 'The :field field must not be longer than :max characters.',
        'max.array' => 'The :field field must not have more than :max items.',
        'between.number' => 'The :field field must be between :min and :max.',
        'between.string' => 'The :field field must be between :min and :max characters.',
        'between.array' => 'The :field field must have between :min and :max items.',
        'in' => 'The selected :field is invalid.',
        'date' => 'The :field field must be a valid date (:format).',
        'regex' => 'The :field field format is invalid.',
        'confirmed' => 'The :field confirmation does not match.',
        'same' => 'The :field field must match :other.',
        'custom' => 'The :field field is invalid.',
    ];

    /** @var array<string, array<string, array{params: list<string>, callback: ?Closure}>> Rules per field in validation order. */
    private array $rules = [];

    /** @var array<string, string> Error code per field. */
    private array $codes = [];

    /** @var array<string, string> Error message per field. */
    private array $errors = [];

    /**
     * Validates input data immediately.
     *
     * @param  array<array-key, mixed>  $data  Input data (e.g. $_POST, decoded JSON).
     * @param  array<array-key, string|array<array-key, string|Closure>>  $rules  Rules per field: 'required|email' or list.
     * @param  array<string, string>  $messages  Custom messages by code ('required') or field and code ('email.required').
     *                                           Placeholders: :field, :min, :max, :values, :format, :other.
     * @param  array<string, string>  $labels  Field names in messages (default: key name with "_" -> space).
     *
     * @throws InvalidArgumentException If rules are written incorrectly.
     * @throws UnexpectedValueException If a custom rule does not return a boolean.
     */
    public function __construct(
        private readonly array $data,
        array $rules,
        private readonly array $messages = [],
        private readonly array $labels = [],
    ) {
        foreach ($rules as $field => $fieldRules) {
            // PHP transforma cheile numerice ('123') in int.
            if (! is_string($field) || $field === '') {
                throw new InvalidArgumentException('Validator: field names must be non-empty strings, got '.get_debug_type($field).'.');
            }

            $this->rules[$field] = $this->parseRules($field, $fieldRules);
        }

        foreach ($this->rules as $field => $fieldRules) {
            $this->check($field, $fieldRules);
        }
    }

    /**
     * Validates and returns the validated data.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, string|array<array-key, string|Closure>>  $rules
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException If data fails validation (fields and codes, without values)
     *                                  or if rules are written incorrectly.
     */
    public static function validate(array $data, array $rules): array
    {
        return (new self($data, $rules))->validatedFor('validate');
    }

    public function passes(): bool
    {
        return $this->codes === [];
    }

    public function fails(): bool
    {
        return $this->codes !== [];
    }

    /**
     * Error messages per field, in rule order.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Error codes per field (violated rule name, 'encoding', or custom rule code).
     * These are stable and can be translated within the application.
     *
     * @return array<string, string>
     */
    public function codes(): array
    {
        return $this->codes;
    }

    /**
     * Error message for a specific field or null if there is no error.
     */
    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Validated data: only fields with rules, with their original received values.
     * Optional fields absent from input data are also omitted from the result.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException If validation failed.
     */
    public function validated(): array
    {
        return $this->validatedFor('validated');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFor(string $method): array
    {
        if ($this->fails()) {
            $fields = [];
            foreach ($this->codes as $field => $code) {
                $fields[] = "'{$field}' ({$code})";
            }

            throw new InvalidArgumentException("Validator::{$method}(): validation failed for ".count($fields).' field(s): '.implode(', ', $fields).'.');
        }

        $validated = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, array{params: list<string>, callback: ?Closure}>  $rules
     *
     * @throws UnexpectedValueException If a custom rule does not return a boolean.
     */
    private function check(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;

        if ($this->isEmpty($value)) {
            if (isset($rules['required'])) {
                $this->fail($field, 'required', 'required', []);
            }

            return;
        }

        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $this->fail($field, 'encoding', 'encoding', []);

            return;
        }

        $numeric = isset($rules['int']) || isset($rules['numeric']);

        foreach ($rules as $name => $rule) {
            if ($rule['callback'] !== null) {
                // Doar valoarea: metode ca RoValidator::iban(...) au alti parametri dupa ea.
                $result = ($rule['callback'])($value);
                if (! is_bool($result)) {
                    throw new UnexpectedValueException("Validator: custom rule '{$name}' for field '{$field}' must return bool, got ".get_debug_type($result).'.');
                }

                if (! $result) {
                    $this->fail($field, $name, 'custom', []);

                    return;
                }

                continue;
            }

            $params = $rule['params'];
            $kind = in_array($name, ['min', 'max', 'between'], true) ? $this->sizeKind($value, $numeric) : null;

            if (! $this->passesRule($name, $params, $value, $field, $kind)) {
                $sized = in_array($name, ['min', 'max', 'between'], true);
                // O valoare care nu se poate masura (ex: bool) primeste mesajul numeric.
                $this->fail($field, $name, $sized ? $name.'.'.($kind ?? 'number') : $name, $params);

                return;
            }
        }
    }

    /**
     * @param  list<string>  $params
     * @param  'number'|'string'|'array'|null  $kind  Value type for min/max/between.
     */
    private function passesRule(string $name, array $params, mixed $value, string $field, ?string $kind): bool
    {
        return match ($name) {
            'required' => true,
            'string' => is_string($value),
            'int' => $this->isInt($value),
            'numeric' => $this->isNumeric($value),
            'bool' => is_bool($value) || $value === 0 || $value === 1
                || (is_string($value) && in_array(strtolower(trim($value)), self::BOOLEANS, true)),
            'array' => is_array($value),
            'email' => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min' => $kind !== null && $this->size($value, $kind) >= (float) $params[0],
            'max' => $kind !== null && $this->size($value, $kind) <= (float) $params[0],
            'between' => $kind !== null && $this->size($value, $kind) >= (float) $params[0] && $this->size($value, $kind) <= (float) $params[1],
            'in' => (is_string($value) || is_int($value)) && in_array((string) $value, $params, true),
            'date' => is_string($value) && $this->isDate($value, $params[0]),
            // Depasirea limitei de backtracking (false) inseamna valoare respinsa, nu eroare de server.
            'regex' => is_string($value) && preg_match($params[0], $value) === 1,
            'confirmed' => ($this->data[$field.'_confirmation'] ?? null) === $value,
            'same' => array_key_exists($params[0], $this->data) && $this->data[$params[0]] === $value,
            default => false,
        };
    }

    /**
     * @param  list<string>  $params
     */
    private function fail(string $field, string $code, string $messageKey, array $params): void
    {
        $label = $this->labels[$field] ?? str_replace('_', ' ', $field);
        $template = $this->messages["{$field}.{$code}"] ?? $this->messages[$code] ?? self::MESSAGES[$messageKey];

        $replace = [':field' => $label];
        $base = explode('.', $messageKey)[0];
        if ($base === 'min') {
            $replace[':min'] = $params[0];
        } elseif ($base === 'max') {
            $replace[':max'] = $params[0];
        } elseif ($base === 'between') {
            $replace[':min'] = $params[0];
            $replace[':max'] = $params[1];
        } elseif ($base === 'in') {
            $replace[':values'] = implode(', ', $params);
        } elseif ($base === 'date') {
            $replace[':format'] = $params[0];
        } elseif ($base === 'same') {
            $replace[':other'] = $this->labels[$params[0]] ?? str_replace('_', ' ', $params[0]);
        }

        $this->codes[$field] = $code;
        $this->errors[$field] = strtr($template, $replace);
    }

    /**
     * @return array<string, array{params: list<string>, callback: ?Closure}>
     *
     * @throws InvalidArgumentException
     */
    private function parseRules(string $field, mixed $rules): array
    {
        if (is_string($rules)) {
            $rules = $rules === '' ? [] : $this->splitPipe($rules);
        }

        if (! is_array($rules)) {
            throw new InvalidArgumentException("Validator: rules for field '{$field}' must be a string or an array, got ".get_debug_type($rules).'.');
        }

        $parsed = [];
        foreach ($rules as $key => $rule) {
            if ($rule instanceof Closure) {
                if (! is_string($key) || $key === '') {
                    throw new InvalidArgumentException("Validator: custom rule for field '{$field}' needs a string key (the error code).");
                }

                if (isset(self::RULES[$key]) || $key === 'encoding') {
                    throw new InvalidArgumentException("Validator: custom rule '{$key}' for field '{$field}' uses the name of a built-in rule.");
                }

                $parsed[$key] = ['params' => [], 'callback' => $rule];

                continue;
            }

            if (! is_int($key) || ! is_string($rule)) {
                throw new InvalidArgumentException("Validator: invalid rule for field '{$field}': expected a rule string or 'code' => Closure.");
            }

            [$name, $params] = $this->parseRule($field, $rule);
            if (isset($parsed[$name])) {
                throw new InvalidArgumentException("Validator: duplicate rule '{$name}' for field '{$field}'.");
            }

            $parsed[$name] = ['params' => $params, 'callback' => null];
        }

        // Ordinea verificarii: required, tipul, apoi restul in ordinea scrisa (uksort este stabil).
        uksort($parsed, static fn (string $a, string $b): int => self::priority($a) <=> self::priority($b));

        return $parsed;
    }

    /**
     * 'required|regex:/a|b/' -> ['required', 'regex:/a|b/']: regex captures the remainder.
     *
     * @return list<string>
     */
    private function splitPipe(string $rules): array
    {
        $list = [];
        while (! str_starts_with($rules, 'regex:') && ($pos = strpos($rules, '|')) !== false) {
            $list[] = substr($rules, 0, $pos);
            $rules = substr($rules, $pos + 1);
        }

        $list[] = $rules;

        return $list;
    }

    /**
     * @return array{string, list<string>}
     *
     * @throws InvalidArgumentException
     */
    private function parseRule(string $field, string $rule): array
    {
        $colon = strpos($rule, ':');
        $name = $colon === false ? $rule : substr($rule, 0, $colon);
        $param = $colon === false ? null : substr($rule, $colon + 1);

        $type = self::RULES[$name] ?? null;
        if ($type === null) {
            throw new InvalidArgumentException("Validator: unknown rule '{$name}' for field '{$field}'.");
        }

        $invalid = static fn (string $expected): InvalidArgumentException => new InvalidArgumentException("Validator: rule '{$name}' for field '{$field}' {$expected}.");

        if ($type === 'none') {
            if ($param !== null) {
                throw $invalid('does not take parameters');
            }

            return [$name, []];
        }

        if ($type === 'format' && $param === null) {
            return [$name, [self::DEFAULT_DATE_FORMAT]];
        }

        if ($param === null || $param === '') {
            throw $invalid('requires a parameter');
        }

        switch ($type) {
            case 'number':
                if (preg_match(self::NUMERIC_PATTERN, $param) !== 1) {
                    throw $invalid('expects a number');
                }

                return [$name, [$param]];

            case 'numbers2':
                $bounds = explode(',', $param);
                if (count($bounds) !== 2 || preg_match(self::NUMERIC_PATTERN, $bounds[0]) !== 1 || preg_match(self::NUMERIC_PATTERN, $bounds[1]) !== 1) {
                    throw $invalid('expects two numbers: min,max');
                }

                if ((float) $bounds[0] > (float) $bounds[1]) {
                    throw $invalid('has min greater than max');
                }

                return [$name, $bounds];

            case 'list':
                $values = explode(',', $param);
                if (in_array('', $values, true)) {
                    throw $invalid('has an empty value in the list');
                }

                return [$name, $values];

            case 'pattern':
                $this->assertPattern($field, $param);

                return [$name, [$param]];

            default: // format, field
                return [$name, [$param]];
        }
    }

    /**
     * @throws InvalidArgumentException If pattern does not compile (warning is prevented from reaching host handler).
     */
    private function assertPattern(string $field, string $pattern): void
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });

        try {
            $result = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if ($result === false || $warning !== null) {
            $reason = preg_replace('/^\w+\(\):\s*/', '', $warning ?? preg_last_error_msg()) ?? '';

            throw new InvalidArgumentException("Validator: invalid regex for field '{$field}': {$reason}.");
        }
    }

    private static function priority(string $name): int
    {
        if ($name === 'required') {
            return 0;
        }

        return in_array($name, self::TYPES, true) ? 1 : 2;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    private function isInt(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX;
        }

        // filter_var: respinge zerourile in fata si depasirile de PHP_INT_MAX.
        return is_string($value) && filter_var(trim($value), FILTER_VALIDATE_INT) !== false;
    }

    private function isNumeric(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && preg_match(self::NUMERIC_PATTERN, trim($value)) === 1;
    }

    /**
     * What min/max/between measures: numeric value (with int/numeric or for int/float), characters, or items.
     *
     * @return 'number'|'string'|'array'|null Null if the value cannot be measured.
     */
    private function sizeKind(mixed $value, bool $numeric): ?string
    {
        if ($numeric) {
            return $this->isNumeric($value) ? 'number' : null;
        }

        return match (true) {
            is_int($value), is_float($value) && is_finite($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => null,
        };
    }

    /**
     * @param  'number'|'string'|'array'  $kind
     */
    private function size(mixed $value, string $kind): float
    {
        return match ($kind) {
            'number' => is_int($value) || is_float($value) ? (float) $value : (is_string($value) ? (float) trim($value) : 0.0),
            'string' => is_string($value) ? (float) mb_strlen($value, 'UTF-8') : 0.0,
            'array' => is_array($value) ? (float) count($value) : 0.0,
        };
    }

    /**
     * Real date matching format exactly: rejects 31.02, 25:00, 2-digit years.
     */
    private function isDate(string $value, string $format): bool
    {
        // '!' pune la zero tot ce nu apare in format (altfel ora lipsa ar fi ora curenta).
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['error_count'] > 0 || $errors['warning_count'] > 0))) {
            return false;
        }

        return ! str_contains($format, 'Y') || (int) $date->format('Y') >= 1000;
    }
}
