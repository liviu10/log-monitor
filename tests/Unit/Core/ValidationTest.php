<?php

declare(strict_types=1);

namespace App\Core {
    if (! function_exists('App\Core\checkdnsrr')) {
        function checkdnsrr(string $hostname, string $type = 'MX'): bool
        {
            if ($hostname === 'valid.test' || $hostname === 'also.valid.test') {
                return true;
            }
            if ($hostname === 'invalid.test') {
                return false;
            }

            return \checkdnsrr($hostname, $type);
        }
    }
}

namespace Tests\Unit\Core {

    use App\Core\Validation;
    use PHPUnit\Framework\TestCase;

    if (! function_exists('Tests\Unit\Core\__')) {
        /**
         * @param  array<string, string|int|float>  $replace
         */
        function __(string $key, array $replace = []): string
        {
            return strtr($key, $replace);
        }
    }

    /**
     * Class ValidationTest
     *
     * @category Tests
     */
    class ValidationTest extends TestCase
    {
        private Validation $validation;

        protected function setUp(): void
        {
            parent::setUp();

            $this->validation = new Validation([
                'email' => 'Email Address',
                'password' => 'Password',
                'age' => 'Age',
            ]);
        }

        public function test_legitimate_input_validation(): void
        {
            $payload = [
                // Use a universally valid domain for testing or one that passes DNS
                'email' => 'test@valid.test',
                'password' => 'secure123',
                'age' => '25',
            ];

            $rules = [
                'email' => ['required', 'email'],
                'password' => ['required', 'min:6'],
                'age' => ['required', 'int'],
            ];

            $errors = $this->validation->validate($rules, $payload);
            self::assertEmpty($errors);
        }

        public function test_email_rule_rejects_address_lists(): void
        {
            $rules = ['email' => ['required', 'email']];

            $errors = $this->validation->validate($rules, ['email' => 'test@valid.test, victim@valid.test']);
            self::assertArrayHasKey('email', $errors);

            $errors = $this->validation->validate($rules, ['email' => 'test@valid.test;victim@valid.test']);
            self::assertArrayHasKey('email', $errors);
        }

        public function test_legitimate_input_validation_fails(): void
        {
            $payload = [
                'email' => 'invalid-email',
                'password' => 'abc',
                'age' => 'not-a-number',
            ];

            $rules = [
                'email' => ['required', 'email'],
                'password' => ['required', 'min:6'],
                'age' => ['required', 'int'],
            ];

            $errors = $this->validation->validate($rules, $payload);

            self::assertArrayHasKey('email', $errors);
            self::assertArrayHasKey('password', $errors);
            self::assertArrayHasKey('age', $errors);

            self::assertEquals('email', $errors['email'][0]);
            self::assertEquals('min:6', $errors['password'][0]);
            self::assertEquals('int', $errors['age'][0]);
        }

        public function test_invisible_character_email_validation(): void
        {
            // Email containing unicode zero-width space (\u{200B})
            $badEmail1 = "test\u{200B}@google.com";
            // Email containing control char (\x00)
            $badEmail2 = "test\x00@google.com";

            $payload = [
                'email1' => $badEmail1,
                'email2' => $badEmail2,
            ];

            $rules = [
                'email1' => ['required', 'email'],
                'email2' => ['required', 'email'],
            ];

            $errors = $this->validation->validate($rules, $payload);

            self::assertArrayHasKey('email1', $errors);
            self::assertArrayHasKey('email2', $errors);
        }

        public function test_json_validate_edge_cases(): void
        {
            $validJson = '{"name": "Aegis", "version": 8.4, "active": true}';
            $malformedJson = '{"name": "Aegis", "version": 8.4, "active": true'; // missing closing brace

            self::assertTrue(json_validate($validJson));
            self::assertFalse(json_validate($malformedJson));
        }

        // --- NEW TESTS ADDED FOR FULL COVERAGE ---

        public function test_validation_ignores_empty_optional_fields(): void
        {
            $payload = [
                'optional_field' => '', // empty string
                'missing_field' => null, // not in array / null
            ];

            $rules = [
                'optional_field' => ['email', 'min:5'],
                'missing_field' => ['int', 'max:10'],
            ];

            $errors = $this->validation->validate($rules, $payload);
            self::assertEmpty($errors);
        }

        public function test_validation_rule_string_and_array(): void
        {
            $payload = [
                'my_string' => 'hello',
                'my_array' => [1, 2, 3],
                'bad_string' => 123, // not a string
                'bad_array' => '1,2,3', // not an array
            ];

            $rules = [
                'my_string' => ['string'],
                'my_array' => ['array'],
                'bad_string' => ['string'],
                'bad_array' => ['array'],
            ];

            $errors = $this->validation->validate($rules, $payload);

            self::assertArrayNotHasKey('my_string', $errors);
            self::assertArrayNotHasKey('my_array', $errors);
            self::assertArrayHasKey('bad_string', $errors);
            self::assertArrayHasKey('bad_array', $errors);
        }

        public function test_validation_rule_date(): void
        {
            $payload = [
                'good_date' => '2023-10-15',
                'bad_date' => 'not-a-date',
                'bad_type' => 20231015,
            ];

            $rules = [
                'good_date' => ['date'],
                'bad_date' => ['date'],
                'bad_type' => ['date'],
            ];

            $errors = $this->validation->validate($rules, $payload);

            self::assertArrayNotHasKey('good_date', $errors);
            self::assertArrayHasKey('bad_date', $errors);
            self::assertArrayHasKey('bad_type', $errors);
        }

        public function test_validation_rule_min_max_for_strings(): void
        {
            $payload = [
                'text' => 'abcde', // length 5
            ];

            $rules = [
                'text' => ['min:3', 'max:10'], // valid
            ];
            self::assertEmpty($this->validation->validate($rules, $payload));

            $rulesFail = [
                'text' => ['min:10', 'max:3'], // invalid
            ];
            $errors = $this->validation->validate($rulesFail, $payload);
            self::assertContains('min:10', $errors['text']);
            self::assertContains('max:3', $errors['text']);
        }

        public function test_validation_rule_min_max_for_arrays(): void
        {
            $payload = [
                'list' => [1, 2, 3], // count 3
            ];

            $rules = [
                'list' => ['min:2', 'max:4'], // valid
            ];
            self::assertEmpty($this->validation->validate($rules, $payload));

            $rulesFail = [
                'list' => ['min:5', 'max:1'], // invalid
            ];
            $errors = $this->validation->validate($rulesFail, $payload);
            self::assertContains('min:5', $errors['list']);
            self::assertContains('max:1', $errors['list']);
        }

        public function test_validation_rule_min_max_for_numbers(): void
        {
            $payload = [
                'num' => 15.5, // numeric value (must be passed as float/int, not string, because strings are checked by length)
            ];

            $rules = [
                'num' => ['min:10', 'max:20'], // valid
            ];
            self::assertEmpty($this->validation->validate($rules, $payload));

            $rulesFail = [
                'num' => ['min:20', 'max:10'], // invalid
            ];
            $errors = $this->validation->validate($rulesFail, $payload);
            self::assertContains('min:20', $errors['num']);
            self::assertContains('max:10', $errors['num']);
        }

        public function test_validation_rule_min_max_for_other_types(): void
        {
            $data = [
                'obj' => new \stdClass,
                'bool' => false,
            ];

            // For non-string, non-array, non-numeric, the length/value is considered 0.
            // min:1 should fail. max:-1 should fail.
            $rules = [
                'obj' => ['min:1', 'max:-1'],
                'bool' => ['min:1', 'max:-1'],
            ];

            $errors = $this->validation->validate($rules, $data);

            self::assertContains('min:1', $errors['obj']);
            self::assertContains('max:-1', $errors['obj']);

            self::assertContains('min:1', $errors['bool']);
            self::assertContains('max:-1', $errors['bool']);
        }

        public function test_validation_rule_in(): void
        {
            $payload = [
                'role' => 'admin',
                'bad_role' => 'superadmin',
            ];

            $rules = [
                'role' => ['in:user,admin,manager'],
                'bad_role' => ['in:user,admin,manager'],
            ];

            $errors = $this->validation->validate($rules, $payload);
            self::assertArrayNotHasKey('role', $errors);
            self::assertArrayHasKey('bad_role', $errors);
            self::assertContains('in:user,admin,manager', $errors['bad_role']);
        }

        public function test_validation_rule_regex(): void
        {
            $payload = [
                'username' => 'user_123',
                'bad_username' => 'user-123!',
            ];

            $rules = [
                'username' => ['regex:/^[a-z0-9_]+$/'],
                'bad_username' => ['regex:/^[a-z0-9_]+$/'],
            ];

            $errors = $this->validation->validate($rules, $payload);
            self::assertArrayNotHasKey('username', $errors);
            self::assertArrayHasKey('bad_username', $errors);

            // Test invalid regex pattern (this emits a warning converted to ErrorException, caught by checkRegex)
            $payloadBadRegex = ['field' => 'test'];
            $rulesBadRegex = ['field' => ['regex:/[a-/']]; // Malformed regex

            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                $errorsBadRegex = $this->validation->validate($rulesBadRegex, $payloadBadRegex);
            } finally {
                restore_error_handler();
            }

            self::assertArrayHasKey('field', $errorsBadRegex);
        }

        public function test_messages_translations(): void
        {
            // Check message translation keys
            self::assertEquals('The Email Address field is required.', $this->validation->messages('email', 'required'));
            self::assertEquals('The Password field must be at least 6.', $this->validation->messages('password', 'min:6'));
            self::assertEquals('The Age field must not be greater than 100.', $this->validation->messages('age', 'max:100'));
            self::assertEquals('The Age field must be an integer.', $this->validation->messages('age', 'int'));
            self::assertEquals('The Unknown field is invalid.', $this->validation->messages('unknown', 'unknown_rule'));
            self::assertEquals('The Unknown field must be an array.', $this->validation->messages('unknown', 'array'));
            self::assertEquals('The Unknown field format is invalid.', $this->validation->messages('unknown', 'regex:/[a-z]/'));
            self::assertEquals('The selected Unknown is invalid.', $this->validation->messages('unknown', 'in:1,2'));
            self::assertEquals('The Unknown field is not a valid date.', $this->validation->messages('unknown', 'date'));
        }

        // --- NEW VALIDATE EMAIL TESTS ---

        public function test_validate_email_returns_empty_array_for_valid_emails(): void
        {
            $result = $this->validation->validateEmail('test@valid.test, another@also.valid.test');
            self::assertEmpty($result);
        }

        public function test_validate_email_returns_invalid_syntax_emails(): void
        {
            $result = $this->validation->validateEmail('invalid-email, test@, @domain.com');
            self::assertEquals(['invalid-email', 'test@', '@domain.com'], $result);
        }

        public function test_validate_email_returns_non_existent_domains(): void
        {
            $invalidDomain = 'test@invalid.test';
            $result = $this->validation->validateEmail($invalidDomain);
            self::assertEquals([$invalidDomain], $result);
        }

        public function test_validate_email_ignores_empty_strings(): void
        {
            $result = $this->validation->validateEmail('test@valid.test, ,   ,');
            self::assertEmpty($result);
        }

        public function test_validate_email_handles_mixed_inputs(): void
        {
            $input = 'user@valid.test, invalid, missing-mx@invalid.test,  ';
            $result = $this->validation->validateEmail($input);
            self::assertEquals(['invalid', 'missing-mx@invalid.test'], array_values($result));
        }
    }
}
