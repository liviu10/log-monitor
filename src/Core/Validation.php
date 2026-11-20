<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Validation Class
 *
 * Extensible engine for validating incoming data.
 * Internally generated messages exclusively use English keys passed to __().
 *
 * @category Utilities
 *
 * @version  1.4
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Validation
{
    /** @var array<string, array<int, string>> Errors collected during the current validation process. */
    private array $errors = [];

    /**
     * Class constructor. Promotes passed properties.
     *
     * @param  array<string, string>  $fieldNames  Aliases for fields used in rendering messages.
     */
    public function __construct(
        private array $fieldNames = []
    ) {}

    /**
     * Runs the set of rules over the payload passed as an argument.
     *
     * @param  array<string, array<int, string>>  $rules  Validation rules (e.g. ['email' => ['required', 'email']]).
     * @param  array<array-key, mixed>  $payload  Data received from HTTP request.
     * @return array<string, array<int, string>> Final array with errors structured by fields.
     */
    public function validate(array $rules, array $payload): array
    {
        $this->errors = [];

        foreach ($rules as $field => $constraints) {
            $value = $payload[$field] ?? null;
            $isEmpty = ($value === null || $value === '' || (is_array($value) && count($value) === 0));

            if ($isEmpty) {
                if (in_array('required', $constraints, true)) {
                    $this->errors[$field][] = 'required';
                }

                continue;
            }

            foreach ($constraints as $rule) {
                if ($rule === 'required') {
                    continue;
                }

                if ($rule === 'email') {
                    $rawVal = is_string($value) || is_numeric($value) || is_bool($value) ? (string) $value : '';
                    // One address only: comma/semicolon lists would turn a single form field into a mass-mailer.
                    if (str_contains($rawVal, ',') || str_contains($rawVal, ';') || count($this->validateEmail($rawVal)) > 0) {
                        $this->errors[$field][] = 'email';
                    }

                    continue;
                }

                $this->applyRule($field, $rule, $value);
            }
        }

        return $this->errors;
    }

    /**
     * Applies a specific rule using exhaustive matching via match expression.
     *
     * @param  string  $field  Name of verified field.
     * @param  string  $rule  Rule to validate.
     * @param  mixed  $value  Value subjected to check.
     */
    private function applyRule(string $field, string $rule, mixed $value): void
    {
        $isValid = match (true) {
            $rule === 'int' => is_numeric($value),
            $rule === 'string' => is_string($value),
            $rule === 'array' => is_array($value),
            $rule === 'date' => is_string($value) && $this->checkDate($value),
            str_starts_with($rule, 'min:') => $this->checkMin($rule, $value),
            str_starts_with($rule, 'max:') => $this->checkMax($rule, $value),
            str_starts_with($rule, 'in:') => in_array(is_scalar($value) ? (string) $value : '', explode(',', substr($rule, 3)), true),
            str_starts_with($rule, 'regex:') => is_string($value) && $this->checkRegex($rule, $value),
            default => true,
        };

        if (! $isValid) {
            $this->errors[$field][] = $rule;
        }
    }

    private function checkDate(string $value): bool
    {
        return strtotime($value) !== false;
    }

    private function checkRegex(string $rule, string $value): bool
    {
        try {
            return preg_match(substr($rule, 6), $value) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Checks if a value respects the set minimum limit.
     *
     * @param  string  $rule  Rule containing the minimum value (e.g., min:3).
     * @param  mixed  $value  Inspected value.
     * @return bool True if the value is greater than or equal to the required minimum.
     */
    private function checkMin(string $rule, mixed $value): bool
    {
        $min = (int) substr($rule, 4);
        if (is_array($value)) {
            $checkValue = count($value);
        } elseif (is_string($value)) {
            $checkValue = mb_strlen($value);
        } elseif (is_numeric($value)) {
            $checkValue = (float) $value;
        } else {
            $checkValue = 0;
        }

        return $checkValue >= $min;
    }

    /**
     * Checks if a value falls under the declared maximum limit.
     *
     * @param  string  $rule  Rule containing the maximum value (e.g., max:10).
     * @param  mixed  $value  Inspected value.
     * @return bool True if the value is less than or equal to the required maximum.
     */
    private function checkMax(string $rule, mixed $value): bool
    {
        $max = (int) substr($rule, 4);
        if (is_array($value)) {
            $checkValue = count($value);
        } elseif (is_string($value)) {
            $checkValue = mb_strlen($value);
        } elseif (is_numeric($value)) {
            $checkValue = (float) $value;
        } else {
            $checkValue = 0;
        }

        return $checkValue <= $max;
    }

    /**
     * Validates a list of comma-separated email addresses.
     * Performs format checks and DNS lookup (MX/A) queries for domains.
     *
     * @param  string  $emailsToVerify  The compound string of emails to verify.
     * @return array<int, string> Array with all addresses that failed the validation tests.
     */
    public function validateEmail(string $emailsToVerify): array
    {
        $invalidEmails = [];
        $emailList = array_map('trim', explode(',', $emailsToVerify));

        foreach ($emailList as $email) {
            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalidEmails[] = $email;

                continue;
            }

            $atPos = strrchr($email, '@');
            $domain = $atPos !== false ? substr($atPos, 1) : '';

            $isLocalDomain = str_ends_with(strtolower($domain), '.local') || $domain === 'localhost';

            if ($domain === '' || (! $isLocalDomain && ! checkdnsrr($domain, 'MX') && ! checkdnsrr($domain, 'A'))) {
                $invalidEmails[] = $email;
            }
        }

        return $invalidEmails;
    }

    /**
     * Returns the translated text corresponding to the errors found, using English keys.
     *
     * @param  string  $field  Technical field name.
     * @param  string  $rule  Violated rule.
     * @return string Interpreted error message, returned without diacritics.
     */
    public function messages(string $field, string $rule): string
    {
        $rawLabel = $this->fieldNames[$field] ?? ucfirst($field);
        $label = __($rawLabel);

        return match (true) {
            $rule === 'required' => __('The :field field is required.', ['field' => $label]),
            $rule === 'email' => __('The :field field must be a valid email address.', ['field' => $label]),
            $rule === 'int' => __('The :field field must be an integer.', ['field' => $label]),
            $rule === 'date' => __('The :field field is not a valid date.', ['field' => $label]),
            $rule === 'array' => __('The :field field must be an array.', ['field' => $label]),
            str_starts_with($rule, 'in:') => __('The selected :field is invalid.', ['field' => $label]),
            str_starts_with($rule, 'min:') => __('The :field field must be at least :min.', ['field' => $label, 'min' => substr($rule, 4)]),
            str_starts_with($rule, 'max:') => __('The :field field must not be greater than :max.', ['field' => $label, 'max' => substr($rule, 4)]),
            str_starts_with($rule, 'regex:') => __('The :field field format is invalid.', ['field' => $label]),
            default => __('The :field field is invalid.', ['field' => $label]),
        };
    }
}
