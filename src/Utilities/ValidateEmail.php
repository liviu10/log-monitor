<?php

namespace App\Utilities;

/**
 * Trait ValidateEmail
 *
 * Ofera functionalitati reutilizabile pentru validarea riguroasa a adreselor de e-mail.
 * Pe langa verificarea formatului standard, acesta verifica si existenta inregistrarilor DNS (MX sau A) 
 * pentru domeniul adresei de e-mail furnizate.
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
     * Valideaza o lista de adrese de e-mail (separate prin virgula).
     *
     * @param string $emailsToVerify Adresele de e-mail de verificat.
     * @return array Tablou continand adresele care au esuat la validare.
     */
    public function validateEmail(string $emailsToVerify): array
    {
        $invalidEmails = [];
        $emailList = array_map('trim', explode(',', $emailsToVerify));

        foreach ($emailList as $email) {
            // Verificare format standard
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $email;
                continue;
            }

            // Verificare existenta domeniu in DNS
            $domain = substr(strrchr($email, "@") ?: '', 1);
            if ($domain === '' || (!checkdnsrr($domain, "MX") && !checkdnsrr($domain, "A"))) {
                $invalidEmails[] = $email;
            }
        }

        return $invalidEmails;
    }
}
