<?php

namespace App\Utilities;

/**
 * Trait ValidateEmail
 *
 * Offers reusable functionality for rigorous email address validation.
 * Besides checking the standard format, it also verifies the existence of DNS records (MX or A)
 * for the domain of the provided email address.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
trait ValidateEmail
{
    /**
     * Validates a list of email addresses (separated by comma).
     *
     * @param string $emailsToVerify The email addresses to validate.
     * @return array Array containing the addresses that failed validation.
     */
    public function validateEmail(string $emailsToVerify): array
    {
        $invalidEmails = [];
        $emailList = array_map('trim', explode(',', $emailsToVerify));

        foreach ($emailList as $email) {
            // Standard format verification
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $email;
                continue;
            }

            // Domain existence verification in DNS
            $domain = substr(strrchr($email, "@") ?: '', 1);
            if ($domain === '' || (!checkdnsrr($domain, "MX") && !checkdnsrr($domain, "A"))) {
                $invalidEmails[] = $email;
            }
        }

        return $invalidEmails;
    }
}
