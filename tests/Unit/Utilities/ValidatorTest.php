<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Validator;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ValidatorTest extends TestCase
{
    // Fluxul general

    public function test_valid_data_passes(): void
    {
        $data = ['email' => 'ion@example.com', 'varsta' => '30', 'extra' => 'ignorat'];
        $validator = new Validator($data, ['email' => 'required|email', 'varsta' => 'required|int|between:18,99']);

        $this->assertTrue($validator->passes());
        $this->assertFalse($validator->fails());
        $this->assertSame([], $validator->errors());
        $this->assertSame([], $validator->codes());
        $this->assertNull($validator->error('email'));
        $this->assertSame(['email' => 'ion@example.com', 'varsta' => '30'], $validator->validated());
    }

    public function test_invalid_data_produces_one_error_per_field(): void
    {
        $validator = new Validator(['email' => 'nu-e-email', 'nume' => ''], [
            'email' => 'required|email|min:50',
            'nume' => 'required|string',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(['email' => 'email', 'nume' => 'required'], $validator->codes());
        $this->assertSame([
            'email' => 'The email field must be a valid email address.',
            'nume' => 'The nume field is required.',
        ], $validator->errors());
        $this->assertSame('The nume field is required.', $validator->error('nume'));
    }

    public function test_validated_throws_with_fields_and_codes_without_values(): void
    {
        $validator = new Validator(['email' => 'secret@x', 'nume' => null], ['email' => 'email', 'nume' => 'required']);

        try {
            $validator->validated();
            $this->fail('Expected exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame("Validator::validated(): validation failed for 2 field(s): 'email' (email), 'nume' (required).", $e->getMessage());
        }
    }

    public function test_static_validate(): void
    {
        $this->assertSame(['id' => 5], Validator::validate(['id' => 5, 'x' => 1], ['id' => 'required|int']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Validator::validate(): validation failed for 1 field(s): 'id' (int).");

        Validator::validate(['id' => 'abc'], ['id' => 'required|int']);
    }

    public function test_optional_empty_field_is_not_checked(): void
    {
        $validator = new Validator(['telefon' => '', 'note' => null], [
            'telefon' => 'string|min:10',
            'note' => 'int',
            'lipsa' => 'email',
        ]);

        $this->assertTrue($validator->passes());
        // Campurile prezente raman in rezultat asa cum au venit; cele lipsa nu apar.
        $this->assertSame(['telefon' => '', 'note' => null], $validator->validated());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyProvider(): iterable
    {
        yield 'null' => [null];
        yield 'string gol' => [''];
        yield 'doar spatii' => [" \t "];
        yield 'array gol' => [[]];
    }

    #[DataProvider('emptyProvider')]
    public function test_required_rejects_empty(mixed $value): void
    {
        $this->assertSame(['x' => 'required'], (new Validator(['x' => $value], ['x' => 'required']))->codes());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notEmptyProvider(): iterable
    {
        yield 'zero int' => [0];
        yield 'zero string' => ['0'];
        yield 'false' => [false];
        yield 'array' => [[0]];
    }

    #[DataProvider('notEmptyProvider')]
    public function test_required_accepts_falsy_values(mixed $value): void
    {
        $this->assertTrue((new Validator(['x' => $value], ['x' => 'required']))->passes());
    }

    public function test_missing_required_field(): void
    {
        $this->assertSame(['x' => 'required'], (new Validator([], ['x' => 'required|email']))->codes());
    }

    public function test_rules_as_list(): void
    {
        $validator = new Validator(['x' => 'ab'], ['x' => ['required', 'string', 'min:3']]);

        $this->assertSame(['x' => 'min'], $validator->codes());
    }

    public function test_empty_rules_keep_field_in_validated(): void
    {
        $validator = new Validator(['a' => 'x', 'b' => [1]], ['a' => '', 'b' => []]);

        $this->assertSame(['a' => 'x', 'b' => [1]], $validator->validated());
    }

    public function test_required_and_type_are_checked_before_other_rules(): void
    {
        $validator = new Validator(['x' => 'abc', 'y' => null], ['x' => 'min:5|int', 'y' => 'email|required']);

        $this->assertSame(['x' => 'int', 'y' => 'required'], $validator->codes());
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $validator = new Validator(['x' => "ab\xE2"], ['x' => 'string']);

        $this->assertSame(['x' => 'encoding'], $validator->codes());
        $this->assertSame('The x field contains invalid characters.', $validator->error('x'));
    }

    // Tipuri

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function typeProvider(): iterable
    {
        yield 'string' => ['string', 'abc', true];
        yield 'string din int' => ['string', 5, false];
        yield 'int' => ['int', 12, true];
        yield 'int string' => ['int', '-12', true];
        yield 'int cu spatii' => ['int', ' 12 ', true];
        yield 'int float fara zecimale' => ['int', 3.0, true];
        yield 'int zecimal' => ['int', '1.5', false];
        yield 'int sufix' => ['int', '12abc', false];
        yield 'int zerouri in fata' => ['int', '012', false];
        yield 'int overflow' => ['int', '99999999999999999999', false];
        yield 'int bool' => ['int', true, false];
        yield 'numeric zecimal' => ['numeric', '1.50', true];
        yield 'numeric negativ' => ['numeric', '-3', true];
        yield 'numeric float' => ['numeric', 1.5, true];
        yield 'numeric virgula' => ['numeric', '1,5', false];
        yield 'numeric exponent' => ['numeric', '1e3', false];
        yield 'numeric hex' => ['numeric', '0x1A', false];
        yield 'numeric INF' => ['numeric', INF, false];
        yield 'bool true' => ['bool', true, true];
        yield 'bool 0' => ['bool', 0, true];
        yield 'bool da' => ['bool', 'DA', true];
        yield 'bool off' => ['bool', 'off', true];
        yield 'bool 2' => ['bool', 2, false];
        yield 'bool text' => ['bool', 'poate', false];
        yield 'array' => ['array', [1], true];
        yield 'array din string' => ['array', 'a', false];
    }

    #[DataProvider('typeProvider')]
    public function test_types(string $rule, mixed $value, bool $valid): void
    {
        $this->assertSame($valid, (new Validator(['x' => $value], ['x' => $rule]))->passes());
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function emailProvider(): iterable
    {
        yield 'simplu' => ['ion.popescu@example.com', true];
        yield 'subdomeniu' => ['a+b@mail.example.ro', true];
        yield 'fara @' => ['ion.example.com', false];
        yield 'fara domeniu' => ['ion@', false];
        yield 'spatiu' => ['ion @example.com', false];
        yield 'prea lung' => [str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.ro', false];
        yield 'nu e string' => [['a@b.ro'], false];
    }

    #[DataProvider('emailProvider')]
    public function test_email(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, (new Validator(['x' => $value], ['x' => 'email']))->passes());
    }

    // min, max, between

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function sizeProvider(): iterable
    {
        yield 'caractere UTF-8' => ['min:5', "Ia\u{0219}i!", true];
        yield 'caractere prea putine' => ['min:6', "Ia\u{0219}i!", false];
        yield 'max caractere' => ['max:3', 'abcd', false];
        yield 'string numeric fara tip = caractere' => ['max:2', '100', false];
        yield 'string numeric cu int = numar' => ['int|max:200', '100', true];
        yield 'numeric zecimal' => ['numeric|min:1.5', '1.49', false];
        yield 'numeric limita' => ['numeric|between:1.5,2', '2.00', true];
        yield 'int valoare' => ['min:10', 9, false];
        yield 'float valoare' => ['max:1', 1.5, false];
        yield 'array elemente' => ['between:2,3', [1, 2], true];
        yield 'array prea multe' => ['max:1', [1, 2], false];
        yield 'bool nu se poate masura' => ['min:0', true, false];
        yield 'negativ' => ['int|min:-5', '-6', false];
    }

    #[DataProvider('sizeProvider')]
    public function test_size_rules(string $rules, mixed $value, bool $valid): void
    {
        $this->assertSame($valid, (new Validator(['x' => $value], ['x' => $rules]))->passes());
    }

    public function test_size_messages_depend_on_the_value_kind(): void
    {
        $validator = new Validator(
            ['a' => 'ab', 'b' => '3', 'c' => [1], 'd' => 'abcdef', 'e' => 100, 'f' => [1, 2, 3]],
            ['a' => 'min:3', 'b' => 'int|min:5', 'c' => 'min:2', 'd' => 'between:1,5', 'e' => 'max:10', 'f' => 'max:2'],
        );

        $this->assertSame([
            'a' => 'The a field must be at least 3 characters.',
            'b' => 'The b field must be at least 5.',
            'c' => 'The c field must have at least 2 items.',
            'd' => 'The d field must be between 1 and 5 characters.',
            'e' => 'The e field must not be greater than 10.',
            'f' => 'The f field must not have more than 2 items.',
        ], $validator->errors());
        $this->assertSame(['a' => 'min', 'b' => 'min', 'c' => 'min', 'd' => 'between', 'e' => 'max', 'f' => 'max'], $validator->codes());
    }

    // in, date, regex, confirmed, same

    public function test_in(): void
    {
        $this->assertTrue((new Validator(['x' => 'PJ'], ['x' => 'in:PF,PJ']))->passes());
        $this->assertTrue((new Validator(['x' => 2], ['x' => 'in:1,2,3']))->passes());
        $this->assertFalse((new Validator(['x' => 'pj'], ['x' => 'in:PF,PJ']))->passes());
        $this->assertFalse((new Validator(['x' => true], ['x' => 'in:1']))->passes());
        $this->assertFalse((new Validator(['x' => ['PF']], ['x' => 'in:PF']))->passes());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function dateProvider(): iterable
    {
        yield 'implicit Y-m-d' => ['date', '2026-03-15', true];
        yield 'format RO' => ['date:d.m.Y', '15.03.2026', true];
        yield 'cu ora (format cu :)' => ['date:d.m.Y H:i', '15.03.2026 10:30', true];
        yield 'an bisect' => ['date:d.m.Y', '29.02.2024', true];
        yield '31 februarie' => ['date:d.m.Y', '31.02.2026', false];
        yield '29 februarie an nebisect' => ['date:d.m.Y', '29.02.2025', false];
        yield 'ora 25' => ['date:d.m.Y H:i', '15.03.2026 25:00', false];
        yield 'alt format' => ['date', '15.03.2026', false];
        yield 'an din 2 cifre' => ['date:d.m.Y', '15.03.26', false];
        yield 'text in plus' => ['date', '2026-03-15x', false];
    }

    #[DataProvider('dateProvider')]
    public function test_date(string $rule, string $value, bool $valid): void
    {
        $this->assertSame($valid, (new Validator(['x' => $value], ['x' => $rule]))->passes());
    }

    public function test_date_message_contains_format(): void
    {
        $validator = new Validator(['data_nasterii' => '2026-01-01'], ['data_nasterii' => 'date:d.m.Y']);

        $this->assertSame('The data nasterii field must be a valid date (d.m.Y).', $validator->error('data_nasterii'));
    }

    public function test_regex(): void
    {
        $this->assertTrue((new Validator(['x' => 'AB12'], ['x' => 'regex:/^[A-Z]{2}\d{2}$/']))->passes());
        $this->assertFalse((new Validator(['x' => 'ab12'], ['x' => 'regex:/^[A-Z]{2}\d{2}$/']))->passes());
        $this->assertFalse((new Validator(['x' => 12], ['x' => 'regex:/^\d+$/']))->passes());
    }

    public function test_regex_with_pipe_takes_the_rest_of_the_rules(): void
    {
        $rules = ['x' => 'required|string|regex:/^(PF|PJ)$/'];

        $this->assertTrue((new Validator(['x' => 'PJ'], $rules))->passes());
        $this->assertSame(['x' => 'regex'], (new Validator(['x' => 'SRL'], $rules))->codes());
        $this->assertSame(['x' => 'required'], (new Validator([], $rules))->codes());
    }

    public function test_confirmed(): void
    {
        $rules = ['parola' => 'required|confirmed'];

        $this->assertTrue((new Validator(['parola' => 'secret123', 'parola_confirmation' => 'secret123'], $rules))->passes());
        $this->assertSame(['parola' => 'confirmed'], (new Validator(['parola' => 'secret123', 'parola_confirmation' => 'secret124'], $rules))->codes());
        $this->assertSame(['parola' => 'confirmed'], (new Validator(['parola' => 'secret123'], $rules))->codes());
        $this->assertSame('The parola confirmation does not match.', (new Validator(['parola' => 'a'], $rules))->error('parola'));
    }

    public function test_same(): void
    {
        $rules = ['email2' => 'same:email'];

        $this->assertTrue((new Validator(['email' => 'a@b.ro', 'email2' => 'a@b.ro'], $rules))->passes());
        $this->assertFalse((new Validator(['email2' => 'a@b.ro'], $rules))->passes());

        $validator = new Validator(['email' => 'a@b.ro', 'email2' => 'c@d.ro'], $rules, labels: ['email' => 'adresa de email']);
        $this->assertSame('The email2 field must match adresa de email.', $validator->error('email2'));
    }

    // Reguli proprii

    public function test_custom_rule(): void
    {
        $isEven = static fn (mixed $value): bool => is_int($value) && $value % 2 === 0;
        $rules = ['x' => ['required', 'int', 'even' => $isEven]];

        $this->assertTrue((new Validator(['x' => 4], $rules))->passes());

        $validator = new Validator(['x' => 3], $rules);
        $this->assertSame(['x' => 'even'], $validator->codes());
        $this->assertSame('The x field is invalid.', $validator->error('x'));
    }

    public function test_custom_rule_receives_only_the_value(): void
    {
        $received = null;
        $rule = static function (mixed ...$args) use (&$received): bool {
            $received = $args;

            return true;
        };

        new Validator(['x' => 1, 'y' => 2], ['x' => ['check' => $rule]]);

        $this->assertSame([1], $received);
    }

    public function test_custom_rule_is_not_called_for_optional_empty_field(): void
    {
        $called = false;
        $rule = static function () use (&$called): bool {
            $called = true;

            return false;
        };

        $this->assertTrue((new Validator(['x' => ''], ['x' => ['check' => $rule]]))->passes());
        $this->assertFalse($called);
    }

    public function test_custom_rule_must_return_bool(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Validator: custom rule 'check' for field 'x' must return bool, got int.");

        new Validator(['x' => 1], ['x' => ['check' => static fn (): int => 1]]);
    }

    // Mesaje si etichete

    public function test_custom_messages_and_labels(): void
    {
        $validator = new Validator(
            ['nume' => '', 'email' => '', 'varsta' => '10', 'tip' => 'X', 'cod' => 'ab'],
            ['nume' => 'required', 'email' => 'required', 'varsta' => 'int|between:18,99', 'tip' => 'in:PF,PJ', 'cod' => 'string|max:1'],
            messages: [
                'required' => 'Campul :field este obligatoriu.',
                'email.required' => 'Introduceti adresa de email.',
                'between' => ':field trebuie sa fie intre :min si :max.',
                'in' => ':field poate fi: :values.',
                'max' => ':field are maxim :max caractere.',
            ],
            labels: ['nume' => 'Numele', 'varsta' => 'Varsta', 'tip' => 'Tipul'],
        );

        $this->assertSame([
            'nume' => 'Campul Numele este obligatoriu.',
            'email' => 'Introduceti adresa de email.',
            'varsta' => 'Varsta trebuie sa fie intre 18 si 99.',
            'tip' => 'Tipul poate fi: PF, PJ.',
            'cod' => 'cod are maxim 1 caractere.',
        ], $validator->errors());
    }

    public function test_messages_do_not_contain_values(): void
    {
        $data = ['email' => 'secret-value', 'x' => 'secret-value'];
        $validator = new Validator($data, ['email' => 'email', 'x' => 'in:a,b']);

        foreach ($validator->errors() as $message) {
            $this->assertStringNotContainsString('secret', $message);
        }
    }

    // Reguli scrise gresit

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidRulesProvider(): iterable
    {
        yield 'regula necunoscuta' => ['requird', "Validator: unknown rule 'requird' for field 'x'."];
        yield 'regula goala' => ['required||email', "Validator: unknown rule '' for field 'x'."];
        yield 'parametru in plus' => ['required:1', "Validator: rule 'required' for field 'x' does not take parameters."];
        yield 'min fara parametru' => ['min', "Validator: rule 'min' for field 'x' requires a parameter."];
        yield 'min gol' => ['min:', "Validator: rule 'min' for field 'x' requires a parameter."];
        yield 'min text' => ['min:abc', "Validator: rule 'min' for field 'x' expects a number."];
        yield 'between un numar' => ['between:1', "Validator: rule 'between' for field 'x' expects two numbers: min,max."];
        yield 'between inversat' => ['between:5,1', "Validator: rule 'between' for field 'x' has min greater than max."];
        yield 'in cu valoare goala' => ['in:a,,b', "Validator: rule 'in' for field 'x' has an empty value in the list."];
        yield 'same fara camp' => ['same', "Validator: rule 'same' for field 'x' requires a parameter."];
        yield 'date gol' => ['date:', "Validator: rule 'date' for field 'x' requires a parameter."];
        yield 'regula duplicata' => ['int|int', "Validator: duplicate rule 'int' for field 'x'."];
        yield 'tip gresit' => [5, "Validator: rules for field 'x' must be a string or an array, got int."];
        yield 'closure fara cod' => [[static fn (): bool => true], "Validator: custom rule for field 'x' needs a string key (the error code)."];
        yield 'closure cu nume rezervat' => [['email' => static fn (): bool => true], "Validator: custom rule 'email' for field 'x' uses the name of a built-in rule."];
        yield 'element care nu e regula' => [[5], "Validator: invalid rule for field 'x': expected a rule string or 'code' => Closure."];
        yield 'cheie text cu string' => [['a' => 'int'], "Validator: invalid rule for field 'x': expected a rule string or 'code' => Closure."];
    }

    #[DataProvider('invalidRulesProvider')]
    public function test_invalid_rules_are_rejected_before_validation(mixed $rules, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        /** @var array<string, string|array<array-key, string|Closure>> $all */
        $all = ['x' => $rules];
        new Validator([], $all);
    }

    public function test_invalid_field_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Validator: field names must be non-empty strings, got int.');

        $rules = ['123' => 'required']; // PHP transforma cheia in int
        new Validator([], $rules);
    }

    public function test_invalid_regex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Validator: invalid regex for field 'x': Compilation failed: missing closing parenthesis");

        new Validator(['x' => 'a'], ['x' => 'regex:/(abc/']);
    }

    public function test_invalid_regex_warning_is_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            new Validator([], ['x' => 'regex:/(abc/']);
        } catch (InvalidArgumentException) {
            // asteptat
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
