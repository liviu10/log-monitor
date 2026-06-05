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
    private array $fieldNames = [];
    use ValidateEmail;

    /** @var array Erorile stranse in timpul procesului curent de validare. */
    private array $errors = [];

    /**
     * Constructor clasa. Promoveaza proprietatile transmise.
     *
     * @param array $fieldNames Aliasuri pentru campuri folosite in randarea mesajelor.
     */
    public function __construct(array $fieldNames = [])
    {
        $this->fieldNames = $fieldNames;
    }

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
    private function applyRule(string $field, string $rule, $value): void
    {
        switch (true) {
            case $rule === 'int':
                $isValid = is_numeric($value);
                break;
            case $rule === 'string':
                $isValid = is_string($value);
                break;
            case $rule === 'array':
                $isValid = is_array($value);
                break;
            case $rule === 'date':
                $isValid = strtotime((string)$value) !== false;
                break;
            case strncmp($rule, 'min:', strlen('min:')) === 0:
                $isValid = $this->checkMin($rule, $value);
                break;
            case strncmp($rule, 'max:', strlen('max:')) === 0:
                $isValid = $this->checkMax($rule, $value);
                break;
            case strncmp($rule, 'in:', strlen('in:')) === 0:
                $isValid = in_array((string)$value, explode(',', (string) substr($rule, 3)), true);
                break;
            case strncmp($rule, 'regex:', strlen('regex:')) === 0:
                $isValid = preg_match((string) substr($rule, 6), (string)$value) === 1;
                break;
            default:
                $isValid = true;
                break;
        }

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
    private function checkMin(string $rule, $value): bool
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
    private function checkMax(string $rule, $value): bool
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
        if ($rule === 'required') {
            return __('The :field field is required.', ['field' => $label]);
        }
        if ($rule === 'email') {
            return __('The :field field must be a valid email address.', ['field' => $label]);
        }
        if ($rule === 'int') {
            return __('The :field field must be an integer.', ['field' => $label]);
        }
        if ($rule === 'date') {
            return __('The :field field is not a valid date.', ['field' => $label]);
        }
        if ($rule === 'array') {
            return __('The :field field must be an array.', ['field' => $label]);
        }
        if (strncmp($rule, 'in:', strlen('in:')) === 0) {
            return __('The selected :field is invalid.', ['field' => $label]);
        }
        if (strncmp($rule, 'min:', strlen('min:')) === 0) {
            return __('The :field field must be at least :min.', ['field' => $label, 'min' => (string) substr($rule, 4)]);
        }
        if (strncmp($rule, 'max:', strlen('max:')) === 0) {
            return __('The :field field must not be greater than :max.', ['field' => $label, 'max' => (string) substr($rule, 4)]);
        }
        if (strncmp($rule, 'regex:', strlen('regex:')) === 0) {
            return __('The :field field format is invalid.', ['field' => $label]);
        }
        return __('The :field field is invalid.', ['field' => $label]);
    }
}