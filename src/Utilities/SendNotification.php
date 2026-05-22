<?php

declare(strict_types=1);

namespace App\Utilities;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Clasa SendNotification
 *
 * Gestioneaza trimiterea notificarilor prin e-mail folosind PHPMailer.
 * Aceasta clasa gestioneaza procesul de construire si trimitere a e-mailurilor cu diverse optiuni, cum ar fi destinatari, atasamente, prioritate etc.
 *
 * @category Pachet
 * @package  App\Utilities
 * @version  1.1
 * @since    PHP 8.3.30
 * @author   Voica Liviu
 * @license  Proprietar
 */
class SendNotification
{
    /** @const string Numele fisierului de jurnal pentru evenimentele de trimitere a notificarilor. */
    private const NOTIFICATION_LOG_FILE_NAME = 'send_notification_log';

    /** @var bool Flag pentru a verifica daca notificarile pot fi trimise (dezactivate in mediul de dezvoltare). */
    private bool $canSendNotification = true;

    /**
     * Constructor SendNotification.
     *
     * Verifica daca mediul permite trimiterea notificarilor.
     * In mediul de dezvoltare, notificarile sunt dezactivate.
     */
    public function __construct()
    {
        // Verifica mediul si dezactiveaza notificarile in mediul 'dev' sau 'development'
        $appEnv = $_ENV['APP_ENV'] ?? 'dev';
        if ($appEnv === 'dev' || $appEnv === 'development') {
            LogViaCurl::send(
                'INFO',
                'Trimiterea notificarilor este permisa numai in PPT si PROD.',
                ['channel' => self::NOTIFICATION_LOG_FILE_NAME]
            );
            $this->canSendNotification = false; // Dezactiveaza trimiterea notificarilor in mediul de dezvoltare
        }
    }

    /**
     * Trimite un e-mail cu mesajul dat folosind PHPMailer.
     * Gestioneaza adaugarea destinatarilor, setarea subiectului si a corpului e-mailului, atasamentelor si trimiterea e-mailului.
     *
     * Daca este furnizata o cale de atasament, aceasta poate fi o singura cale de fisier sau un array de cai de fisiere.
     * In cazul unui array, toate fisierele din array vor fi atasate la e-mail.
     *
     * @param array{
     *   to: string,
     *   message: string,
     *   priority?: int,
     *   attachmentPath?: string|array<int, string>|null,
     *   from?: string|null,
     *   subject?: string|null
     * } $emailData Datele pentru e-mail.
     *
     * @return string|false Continutul .eml al email-ului in caz de succes, sau false in caz de eroare.
     */
    public function handle(array $emailData)
    {
        // Extrage datele din array-ul de intrare
        $to = $emailData['to'];
        $message = $emailData['message'];
        $priority = $emailData['priority'] ?? 3;
        $attachmentPath = $emailData['attachmentPath'] ?? null;
        $from = $emailData['from'] ?? null;
        $subject = $emailData['subject'] ?? null;

        // Daca trimiterea notificarilor este dezactivata, iesi din functie
        if (!$this->canSendNotification) {
            return false;
        }

        // Initializeaza instanta PHPMailer
        $mail = new PHPMailer(true);

        try {
            // Setari server pentru SMTP
            $mail->isSMTP();
            $mail->Host = (is_string($_ENV['SMTP_HOST'] ?? null) ? $_ENV['SMTP_HOST'] : '10.165.1.12'); // Adresa serverului SMTP
            $mail->Port = (is_int($_ENV['SMTP_PORT'] ?? null) ? $_ENV['SMTP_PORT'] : 587); // Port SMTP
            $mail->SMTPAuth = true;
            $mail->Username = (is_string($_ENV['SMTP_USERNAME'] ?? null) ? $_ENV['SMTP_USERNAME'] : '');
            $mail->Password = (is_string($_ENV['SMTP_PASSWORD'] ?? null) ? $_ENV['SMTP_PASSWORD'] : '');

            // Timeout pentru trimiterea mesajului si socket
            $mail->Timeout = 1800; // 30 minute

            /**
             * Configurarea optiunilor SMTPOptions.
             *
             * Se initializeaza cu un timeout pentru socket.
             * Daca variabilele de mediu pentru proxy (PROXY_HOST, PROXY_PORT) sunt definite,
             * se adauga configuratia pentru a ruta traficul SMTP printr-un proxy.
             * Aceasta este utila in mediile corporate cu politici stricte de retea.
             * Setarile 'verify_peer' si 'verify_peer_name' sunt dezactivate pentru
             * a evita erorile de validare a certificatelor SSL in spatele unui proxy.
             */
            $smtpOptions = [
                'socket' => [
                    'timeout' => 1800, // 30 minute
                ],
            ];

            // Verifica si adauga configuratia pentru proxy daca este definita in mediu
            if (!empty($_ENV['PROXY_HOST']) && !empty($_ENV['PROXY_PORT'])) {
                $proxyUrl = "tcp://{$_ENV['PROXY_HOST']}:{$_ENV['PROXY_PORT']}";
                
                // Optiunile de proxy sunt adaugate in contextul 'ssl'.
                // Chiar daca nu se foloseste SMTPSecure explicit, PHPMailer poate initia STARTTLS,
                // moment in care aceste setari de context vor fi utilizate.
                $smtpOptions['ssl'] = [
                    'proxy' => $proxyUrl,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];
            }

            $mail->SMTPOptions = $smtpOptions;

            // Informatii expeditor (De la si Raspunde la)
            $smtpFrom = (is_string($_ENV['SMTP_FROM'] ?? null) ? $_ENV['SMTP_FROM'] : 'noreply.aitpl@groupama.ro');
            $mail->setFrom($from === null ? $smtpFrom : $from);
            $mail->addReplyTo($smtpFrom);

            // Adauga destinatari
            $toAddresses = array_map('trim', explode(',', $to)); // Imparte destinatarii multipli
            foreach ($toAddresses as $address) {
                $mail->addAddress($address); // Adauga fiecare destinatar
            }

            // Adauga CC si BCC daca sunt disponibile in variabilele de mediu
            if (isset($_ENV['SMTP_CC']) && is_string($_ENV['SMTP_CC']) && $_ENV['SMTP_CC'] !== '') {
                $ccAddresses = array_map('trim', explode(',', $_ENV['SMTP_CC']));
                foreach ($ccAddresses as $cc) {
                    $mail->addCC($cc); // Adauga destinatar CC
                }
            }
            if (isset($_ENV['SMTP_BCC']) && is_string($_ENV['SMTP_BCC']) && $_ENV['SMTP_BCC'] !== '') {
                $bccAddresses = array_map('trim', explode(',', $_ENV['SMTP_BCC']));
                foreach ($bccAddresses as $bcc) {
                    $mail->addBCC($bcc); // Adauga destinatar BCC
                }
            }

            // Seteaza subiectul si corpul
            $mail->Subject = $subject === null ? sprintf(
                '%s - %s',
                (is_string($_ENV['APP_NAME'] ?? null) ? $_ENV['APP_NAME'] : 'AITPL Exchange Rate'),
                (is_string($_ENV['SMTP_SUBJECT'] ?? null) ? $_ENV['SMTP_SUBJECT'] : 'AITPL Notificare automata')
            ) : $subject;
            $mail->isHTML(true); // Seteaza e-mailul in format HTML
            $mail->Body = nl2br($message); // Seteaza corpul e-mailului

            // Seteaza prioritatea e-mailului
            $allowedPriorities = [1, 2, 3, 4, 5]; // Niveluri de prioritate valide
            if (!in_array($priority, $allowedPriorities, true)) {
                $priority = 3; // Implicit la prioritate normala daca este data o prioritate invalida
            }
            $mail->Priority = $priority;

            // Gestioneaza atasamentul daca este furnizat
            if ($attachmentPath !== null) {
                // Daca este un array, parcurge si ataseaza fiecare fisier
                $attachments = is_array($attachmentPath) ? $attachmentPath : [$attachmentPath];

                foreach ($attachments as $filePath) {
                    // Normalizeaza calea pentru a rezolva '..' si a verifica existenta
                    $normalizedPath = realpath($filePath);

                    // Verifica daca calea atasamentului este valida si lizibila
                    if ($normalizedPath === false || !is_readable($normalizedPath)) {
                        LogViaCurl::send(
                            'ERROR',
                            'Calea atasamentului nu exista sau nu este citibila.',
                            [
                                'location' => __METHOD__,
                                'file' => $filePath,
                                'channel' => self::NOTIFICATION_LOG_FILE_NAME
                            ]
                        );

                        return false;
                    }

                    // Verifica daca calea atasamentului este un director in loc de un fisier
                    if (is_dir($normalizedPath)) {
                        LogViaCurl::send(
                            'ERROR',
                            'Calea atasamentului indica un director, nu un fisier.',
                            [
                                'location' => __METHOD__,
                                'file' => $filePath,
                                'channel' => self::NOTIFICATION_LOG_FILE_NAME
                            ]
                        );

                        return false;
                    }

                    // Adauga atasamentul la e-mail
                    $mail->addAttachment($normalizedPath);
                }
            }

            $mail->send();

            $mail->preSend();
            $emlContent = $mail->getSentMIMEMessage();

            // Poti salva temporar pe server sau returna direct
            return $emlContent;
        } catch (\Throwable $e) {
            // Inregistreaza orice erori care apar in timpul procesului de trimitere a e-mailului
            LogViaCurl::send(
                'ERROR',
                'Nu s-a putut trimite e-mailul de notificare folosind PHPMailer.',
                [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'email_recipient' => $to,
                    'email_subject' => $subject,
                    'channel' => self::NOTIFICATION_LOG_FILE_NAME
                ]
            );

            return false;
        }
    }
}