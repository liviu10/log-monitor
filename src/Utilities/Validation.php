<?php

declare(strict_types=1);

namespace App\Utilities;

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
class Validation
{
    use ValidateEmail;

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
            $isEmpty = ($value === null || $value === '' || (is_array($value) && empty($value)));

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
                    if (! empty($this->validateEmail($rawVal))) {
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
            $rule === 'date' => is_string($value) && strtotime($value) !== false,
            str_starts_with($rule, 'min:') => $this->checkMin($rule, $value),
            str_starts_with($rule, 'max:') => $this->checkMax($rule, $value),
            str_starts_with($rule, 'in:') => in_array(is_scalar($value) ? (string) $value : '', explode(',', substr($rule, 3)), true),
            str_starts_with($rule, 'regex:') => is_string($value) && (preg_match(substr($rule, 6), $value) === 1),
            default => true,
        };

        if (! $isValid) {
            $this->errors[$field][] = $rule;
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
