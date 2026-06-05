<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Trait ValidateEmail
 *
 * Pune la dispozitie mecanisme refolosibile de validare sintactica si DNS pentru email-uri.
 *
 * @category Utilitare
 * @package  App\Utilities
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
trait ValidateEmail
{
    /**
     * Valideaza o lista de adrese de e-mail despartite prin separatorul virgula.
     * Realizeaza validari de format si interogari DNS (MX/A) pentru domenii.
     *
     * @param string $emailsToVerify String-ul compus cu adresele de verificat.
     * @return array Vector cu toate adresele care au picat testele de validare.
     */
    public function validateEmail(string $emailsToVerify): array
    {
        $invalidEmails = [];
        $emailList = array_map('trim', explode(',', $emailsToVerify));

        foreach ($emailList as $email) {
            if ($email === '') {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $email;
                continue;
            }

            $domain = (string) substr(strrchr($email, "@") ?: '', 1);
            if ($domain === '' || (!checkdnsrr($domain, "MX") && !checkdnsrr($domain, "A"))) {
                $invalidEmails[] = $email;
            }
        }

        return $invalidEmails;
    }
}