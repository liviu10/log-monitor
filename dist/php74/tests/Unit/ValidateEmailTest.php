<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Utilities\ValidateEmail;

class ValidateEmailTest extends TestCase
{
    private object $validator;

    protected function setUp(): void
    {
        parent::setUp();
        // Create an anonymous class to test the trait
        $this->validator = new class {
            use ValidateEmail;
        };
    }

    public function testValidateEmailAcceptsValidEmailAddress(): void
    {
        $emails = 'test@gmail.com, test2@google.com';
        $invalid = $this->validator->validateEmail($emails);
        
        $this->assertEmpty($invalid);
    }

    public function testValidateEmailRejectsSyntacticallyInvalidEmails(): void
    {
        $emails = 'invalid-email, test@gmail.com, another-invalid@';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertCount(2, $invalid);
        $this->assertContains('invalid-email', $invalid);
        $this->assertContains('another-invalid@', $invalid);
    }

    public function testValidateEmailRejectsNonExistentDomainEmails(): void
    {
        $emails = 'test@nonexistent-domain-1234567890-test.xyz';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertCount(1, $invalid);
        $this->assertContains('test@nonexistent-domain-1234567890-test.xyz', $invalid);
    }

    public function testValidateEmailHandlesConsecutiveCommasAndEmptySpaces(): void
    {
        $emails = 'test@gmail.com,, ,test2@google.com';
        $invalid = $this->validator->validateEmail($emails);

        $this->assertEmpty($invalid);
    }
}
