<?php

namespace App\Utilities;

/**
 * Class Validation
 *
 * Provides a flexible validation engine for data received through requests.
 * Supports basic rules such as 'required', 'string', 'int', 'email', 'date', 'array', 'min', 'max', 'in', and 'regex'.
 * Allows customization of field names for generating more user-friendly error messages.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class Validation
{
    use ValidateEmail;

    /** @var array Array with collected validation errors. */
    private array $errors = [];

    /**
     * Constructor of the Validation class.
     * We use Constructor Property Promotion to map the field names.
     *
     * @param array $fieldNames Array of the form ['field_name' => 'Label'].
     */
    public function __construct(
        private array $fieldNames = []
    ) {}

    /**
     * Validates a set of data (payload) based on specified rules.
     *
     * @param array $rules   The validation rules.
     * @param array $payload The data to validate.
     * @return array Array with errors (empty if validation succeeded).
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
                if ($rule === 'required') continue;

                if ($rule === 'email') {
                    if (!empty($this->validateEmail((string)$value))) {
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
     * Applies a specific validation rule to a value.
     */
    private function applyRule(string $field, string $rule, mixed $value): void
    {
        $isValid = match (true) {
            $rule === 'int' => is_numeric($value),
            $rule === 'string' => is_string($value),
            $rule === 'array' => is_array($value),
            $rule === 'date' => strtotime((string)$value) !== false,
            str_starts_with($rule, 'min:') => $this->checkMin($rule, $value),
            str_starts_with($rule, 'max:') => $this->checkMax($rule, $value),
            str_starts_with($rule, 'in:') => in_array((string)$value, explode(',', substr($rule, 3)), true),
            str_starts_with($rule, 'regex:') => preg_match(substr($rule, 6), (string)$value),
            default => true,
        };

        if (!$isValid) {
            $this->errors[$field][] = $rule;
        }
    }

    private function checkMin(string $rule, mixed $value): bool
    {
        $min = (int)substr($rule, 4);
        $checkValue = is_array($value) ? count($value) : (is_numeric($value) ? $value : mb_strlen((string)$value));
        return $checkValue >= $min;
    }

    private function checkMax(string $rule, mixed $value): bool
    {
        $max = (int)substr($rule, 4);
        $checkValue = is_numeric($value) ? $value : mb_strlen((string)$value);
        return $checkValue <= $max;
    }

    /**
     * Generates human-readable error messages in Romanian for a failed rule.
     */
    public function messages(string $field, string $rule): string
    {
        $label = __($this->fieldNames[$field] ?? ucfirst($field));

        return match (true) {
            $rule === 'required' => __('Campul :field este obligatoriu.', ['field' => $label]),
            $rule === 'email' => __('Campul :field nu este o adresa de email valida.', ['field' => $label]),
            $rule === 'int' => __('Campul :field trebuie sa fie un numar intreg.', ['field' => $label]),
            $rule === 'date' => __('Campul :field nu este o data valida.', ['field' => $label]),
            $rule === 'array' => __('Campul :field trebuie sa fie o lista.', ['field' => $label]),
            str_starts_with($rule, 'in:') => __('Selectia pentru :field este invalida.', ['field' => $label]),
            str_starts_with($rule, 'min:') => __('Campul :field este sub limita minima de :min.', ['field' => $label, 'min' => substr($rule, 4)]),
            str_starts_with($rule, 'max:') => __('Campul :field depaseste limita maxima de :max.', ['field' => $label, 'max' => substr($rule, 4)]),
            str_starts_with($rule, 'regex:') => __('Campul :field nu are un format valid.', ['field' => $label]),
            default => __('Campul :field este invalid.', ['field' => $label]),
        };
    }
}
