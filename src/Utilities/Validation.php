<?php

namespace App\Utilities;

/**
 * Clasa Validation
 *
 * Ofera un motor de validare flexibil pentru datele primite prin request-uri.
 * Suporta reguli de baza precum 'required', 'string', 'int', 'email', 'date', 'array', 'min', 'max', 'in' si 'regex'.
 * Permite personalizarea numelor campurilor pentru generarea unor mesaje de eroare mai prietenoase.
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

    /** @var array Tablou cu erorile colectate in urma validarii. */
    private array $errors = [];

    /**
     * Constructorul clasei Validation.
     * Utilizăm Constructor Property Promotion pentru maparea numelor câmpurilor.
     *
     * @param array $fieldNames Tablou de forma ['nume_camp' => 'Eticheta'].
     */
    public function __construct(
        private array $fieldNames = []
    ) {}

    /**
     * Valideaza un set de date (payload) pe baza unor reguli specificate.
     * 
     * @param array $rules   Regulile de validare.
     * @param array $payload Datele de validat.
     * @return array Tablou cu erori (gol daca validarea a reusit).
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
     * Aplică o regulă de validare specifică asupra unei valori.
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
     * Genereaza mesaje de eroare lizibile in limba romana pentru o regula esuata.
     */
    public function messages(string $field, string $rule): string
    {
        $label = $this->fieldNames[$field] ?? ucfirst($field);

        return match (true) {
            $rule === 'required' => "Campul $label este obligatoriu.",
            $rule === 'email' => "Campul $label nu este o adresa de email valida.",
            $rule === 'int' => "Campul $label trebuie sa fie un numar intreg.",
            $rule === 'date' => "Campul $label nu este o data valida.",
            $rule === 'array' => "Campul $label trebuie sa fie o lista.",
            str_starts_with($rule, 'in:') => "Selectia pentru $label este invalida.",
            str_starts_with($rule, 'min:') => "Campul $label este sub limita minima de " . substr($rule, 4) . ".",
            str_starts_with($rule, 'max:') => "Campul $label depaseste limita maxima de " . substr($rule, 4) . ".",
            str_starts_with($rule, 'regex:') => "Campul $label nu are un format valid.",
            default => "Campul $label este invalid.",
        };
    }
}
