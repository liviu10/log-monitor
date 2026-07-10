<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Trait ValidateEmail
 *
 * Provides reusable mechanisms for syntax and DNS validation of emails.
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
trait ValidateEmail
{
    /**
     * Validates a list of comma-separated email addresses.
     * Performs format checks and DNS lookup (MX/A) queries for domains.
     *
     * @param  string  $emailsToVerify  The compound string of emails to verify.
     * @return array Array with all addresses that failed the validation tests.
     */
    public function validateEmail(string $emailsToVerify): array
    {
        $invalidEmails = [];
        $emailList = array_map('trim', explode(',', $emailsToVerify));

        foreach ($emailList as $email) {
            if ($email === '') {
                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $email;

                continue;
            }

            $domain = substr(strrchr($email, '@') ?: '', 1);
            if ($domain === '' || (! checkdnsrr($domain, 'MX') && ! checkdnsrr($domain, 'A'))) {
                $invalidEmails[] = $email;
            }
        }

        return $invalidEmails;
    }
}
