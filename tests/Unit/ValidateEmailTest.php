<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\ValidateEmail;
use Tests\TestCase;

class ValidateEmailTest extends TestCase
{
    private object $validator;

    protected function setUp(): void
    {
        parent::setUp();
        // Create an anonymous class to test the trait
        $this->validator = new class
        {
            use ValidateEmail;
        };
    }

    public function test_validate_email_accepts_valid_email_address(): void
    {
        $emails = 'test@gmail.com, test2@google.com';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertEmpty($invalid);
    }

    public function test_validate_email_rejects_syntactically_invalid_emails(): void
    {
        $emails = 'invalid-email, test@gmail.com, another-invalid@';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertCount(2, $invalid);
        $this->assertContains('invalid-email', $invalid);
        $this->assertContains('another-invalid@', $invalid);
    }

    public function test_validate_email_rejects_non_existent_domain_emails(): void
    {
        $emails = 'test@nonexistent-domain-1234567890-test.xyz';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertCount(1, $invalid);
        $this->assertContains('test@nonexistent-domain-1234567890-test.xyz', $invalid);
    }

    public function test_validate_email_handles_consecutive_commas_and_empty_spaces(): void
    {
        $emails = 'test@gmail.com,, ,test2@google.com';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertEmpty($invalid);
    }
}
