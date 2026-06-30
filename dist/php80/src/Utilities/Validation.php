<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Clasa Validation
 *
 * Motor extensibil pentru validarea datelor intrate.
 * Mesajele generate intern folosesc in mod exclusiv chei in engleza pasate catre __().
 *
 * @category Utilitare
 * @package  App\Utilities
 * @version  1.4
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class Validation
{
    use ValidateEmail;

    /** @var array Erorile stranse in timpul procesului curent de validare. */
    private array $errors = [];

    /**
     * Constructor clasa. Promoveaza proprietatile transmise.
     *
     * @param array $fieldNames Aliasuri pentru campuri folosite in randarea mesajelor.
     */
    public function __construct(
        private array $fieldNames = []
    ) {}

    /**
     * Ruleaza setul de reguli peste payload-ul primit ca argument.
     *
     * @param array $rules Regulile de validare (ex: ['email' => ['required', 'email']]).
     * @param array $payload Datele primite din cererea HTTP.
     * @return array Vectorul final cu erori structurate pe campuri.
     */
    public function validate(array $rules, array $payload): array
    {
        $this->errors = [];

        foreach ($rules as $field => $constraints) {
            $value = $payload[$field] ?? null;
            $isEmpty = (in_array($value, [null, '', []], true));

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
                    if ($this->validateEmail((string)$value) !== []) {
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
     * Aplica o regula specifica utilizand potrivirea exhaustiva prin expresia match.
     *
     * @param string $field Numele campului verificat.
     * @param string $rule Regula de validat.
     * @param mixed $value Valoarea supusa verificarii.
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
            str_starts_with($rule, 'regex:') => (preg_match(substr($rule, 6), (string)$value) === 1),
            default => true,
        };

        if (!$isValid) {
            $this->errors[$field][] = $rule;
        }
    }

    /**
     * Verifica daca o valoare respecta limita minima setata.
     *
     * @param string $rule Regula continand valoarea de minim (ex: min:3).
     * @param mixed $value Valoarea inspectata.
     * @return bool True daca valoarea este mai mare sau egala cu minimul impus.
     */
    private function checkMin(string $rule, mixed $value): bool
    {
        $min = (int)substr($rule, 4);
        $checkValue = is_array($value) ? count($value) : (is_numeric($value) ? (float)$value : mb_strlen((string)$value));
        return $checkValue >= $min;
    }

    /**
     * Verifica daca o valoare se incadreaza sub limita maxima declarata.
     *
     * @param string $rule Regula continand valoarea de maxim (ex: max:10).
     * @param mixed $value Valoarea inspectata.
     * @return bool True daca valoarea este mai mica sau egala cu maximul impus.
     */
    private function checkMax(string $rule, mixed $value): bool
    {
        $max = (int)substr($rule, 4);
        $checkValue = is_numeric($value) ? (float)$value : mb_strlen((string)$value);
        return $checkValue <= $max;
    }

    /**
     * Intoarce textul tradus corespunzator erorilor gasite folosind chei in engleza.
     *
     * @param string $field Denumirea tehnica a campului.
     * @param string $rule Regula incalcata.
     * @return string Mesajul de eroare interpretat si returnat fara diacritice.
     */
    public function messages(string $field, string $rule): string
    {
        $label = __($this->fieldNames[$field] ?? ucfirst($field));

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