<?php

namespace App\Utilities;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Clasa SendNotification
 *
 * Gestioneaza trimiterea notificarilor prin e-mail folosind libraria PHPMailer.
 * Ofera suport pentru configurari SMTP din variabile de mediu, atasamente multiple, 
 * destinatari multipli (TO, CC, BCC) si setarea prioritatii mesajelor.
 * Include logica pentru dezactivarea trimiterii in mediul de dezvoltare.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class SendNotification
{
    /** @const string Numele fisierului de jurnal pentru evenimentele de notificare. */
    private const NOTIFICATION_LOG_FILE_NAME = 'send_notification_log';

    /** @var bool Flag care indica daca trimiterea notificarilor este activata in mediul curent. */
    private bool $canSendNotification = true;

    /**
     * Constructorul clasei SendNotification.
     * Initializeaza sistemul de logare si verifica mediul de executie (APP_ENV).
     *
     * @param mixed $log Sistemul de jurnalizare (optional).
     */
    public function __construct(
        private mixed $log = null
    ) {
        // Dezactivam trimiterea reala in mediu de dev pentru a evita spam-ul accidental
        if (($_ENV['APP_ENV'] ?? 'dev') === 'dev') {
            $msg = 'INFO : Trimiterea notificarilor este permisa numai in PPT si PROD.';
            if ($this->log) {
                $this->log->handle($msg, [], self::NOTIFICATION_LOG_FILE_NAME);
            } else {
                error_log($msg);
            }
            $this->canSendNotification = false;
        }
    }

    /**
     * Trimite o notificare prin e-mail catre unul sau mai multi destinatari.
     *
     * @param string      $to             Lista de e-mailuri destinatar (separate prin virgula).
     * @param string      $message        Continutul mesajului (suporta HTML).
     * @param int         $priority       Prioritatea e-mailului (1-5).
     * @param string|array|null $attachmentPath Calea/Caile catre fisierele atasate.
     * @param string|null $from           Adresa expeditorului.
     * @param string|null $subject        Subiectul e-mailului.
     * @return bool True daca e-mailul a fost trimis cu succes, false altfel.
     */
    public function handle(
        string $to,
        string $message,
        int $priority = 3,
        string|array|null $attachmentPath = null,
        ?string $from = null,
        ?string $subject = null,
    ): bool {
        if (!$this->canSendNotification) {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'localhost';
            $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['SMTP_USERNAME'] ?? '';
            $mail->Password = $_ENV['SMTP_PASSWORD'] ?? '';
            $mail->Timeout = 1800;
            $mail->SMTPOptions = ['socket' => ['timeout' => 1800]];

            $mail->setFrom($from ?? ($_ENV['SMTP_FROM'] ?? 'admin@example.com'));

            foreach (array_map('trim', explode(',', $to)) as $address) {
                $mail->addAddress($address);
            }

            if (isset($_ENV['SMTP_CC'])) {
                foreach (array_map('trim', explode(',', $_ENV['SMTP_CC'])) as $cc) {
                    $mail->addCC($cc);
                }
            }
            if (isset($_ENV['SMTP_BCC'])) {
                foreach (array_map('trim', explode(',', $_ENV['SMTP_BCC'])) as $bcc) {
                    $mail->addBCC($bcc);
                }
            }

            $mail->Subject = $subject ?? (($_ENV['APP_NAME'] ?? 'LogMonitor') . " - " . ($_ENV['SMTP_SUBJECT'] ?? 'Notification'));
            $mail->isHTML(true);
            $mail->Body = nl2br($message);
            $mail->Priority = in_array($priority, [1, 2, 3, 4, 5], true) ? $priority : 3;

            if ($attachmentPath !== null) {
                $attachments = is_array($attachmentPath) ? $attachmentPath : [$attachmentPath];
                foreach ($attachments as $filePath) {
                    if (!file_exists($filePath) || !is_readable($filePath) || is_dir($filePath)) {
                        $this->log?->handle('ERROR : Calea atasamentului nu este valida.', ['file' => $filePath], self::NOTIFICATION_LOG_FILE_NAME);
                        return false;
                    }
                    $mail->addAttachment($filePath);
                }
            }

            return $mail->send();
        } catch (PHPMailerException $e) {
            $errorMsg = 'ERROR : Nu s-a putut trimite e-mailul: ' . $e->getMessage();
            if ($this->log) {
                $this->log->handle($errorMsg, ['to' => $to], self::NOTIFICATION_LOG_FILE_NAME);
            } else {
                error_log($errorMsg);
            }
            return false;
        }
    }
}
