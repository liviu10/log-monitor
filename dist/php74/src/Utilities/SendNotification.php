<?php

declare(strict_types=1);

namespace App\Utilities;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use App\Enums\LogLevel;
use App\Utilities\LogViaStream;

/**
 * Clasa SendNotification
 *
 * Gestioneaza expedierea email-urilor via PHPMailer cu suport pentru proxy corporate.
 * Toate exceptiile colecteaza detaliile complete si le trimit catre cURL.
 * Toate mesajele text destinate exceptiilor folosesc functia __().
 *
 * @category Pachete
 * @package  App\Utilities
 * @version  1.6
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class SendNotification
{
    /** @var bool $canSendNotification Permisiune de rulare in functie de mediu. */
    private bool $canSendNotification = true;

    /**
     * Constructor clasa. Dezactiveaza interactiunea cu serverul de mail in medii locale (DEV).
     */
    public function __construct()
    {
        $appEnv = $_ENV['APP_ENV'] ?? 'dev';
        if ($appEnv === 'dev' || $appEnv === 'development') {
            $this->canSendNotification = false;
        }
    }

    /**
     * Proceseaza datele si trimite email-ul conform configuratiei.
     *
     * @param array{
     * to: string,
     * message: string,
     * priority?: int,
     * attachmentPath?: string|array<int, string>|null,
     * from?: string|null,
     * subject?: string|null
     * } $emailData Setul complet de informatii pentru livrare.
     * @throws RuntimeException Cand datele obligatorii lipsesc sau expedierea esueaza.
     * @return string Continutul MIME complet (.eml) al mesajului transmis.
     */
    public function handle(array $emailData): string
    {
        if (!$this->canSendNotification) {
            throw new RuntimeException(__('Notification system is disabled in this runtime environment.'));
        }
        if (!isset($emailData['to'])) {
            throw new RuntimeException(__('The recipient field is required.'));
        }
        $to = $emailData['to'];
        if (!isset($emailData['message'])) {
            throw new RuntimeException(__('The message body field is required.'));
        }
        $message = $emailData['message'];
        $priority = $emailData['priority'] ?? 3;
        $attachmentPath = $emailData['attachmentPath'] ?? null;
        $from = $emailData['from'] ?? null;
        $subject = $emailData['subject'] ?? null;

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = is_string($_ENV['SMTP_HOST'] ?? null) ? $_ENV['SMTP_HOST'] : '127.0.0.1';
            $mail->Port = is_int($_ENV['SMTP_PORT'] ?? null) ? $_ENV['SMTP_PORT'] : 587;
            $mail->SMTPAuth = true;
            $mail->Username = is_string($_ENV['SMTP_USERNAME'] ?? null) ? $_ENV['SMTP_USERNAME'] : '';
            $mail->Password = is_string($_ENV['SMTP_PASSWORD'] ?? null) ? $_ENV['SMTP_PASSWORD'] : '';
            $mail->Timeout = 1800;

            $smtpOptions = [
                'socket' => [
                    'timeout' => 1800,
                ],
            ];

            if (!empty($_ENV['PROXY_HOST']) && !empty($_ENV['PROXY_PORT'])) {
                $proxyUrl = sprintf('tcp://%s:%s', $_ENV['PROXY_HOST'], $_ENV['PROXY_PORT']);
                $smtpOptions['ssl'] = [
                    'proxy' => $proxyUrl,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];
            }

            $mail->SMTPOptions = $smtpOptions;

            $smtpFrom = is_string($_ENV['SMTP_FROM'] ?? null) ? $_ENV['SMTP_FROM'] : 'noreply@localhost.com';
            $mail->setFrom($from ?? $smtpFrom);
            $mail->addReplyTo($smtpFrom);

            $toAddresses = array_map('trim', explode(',', $to));
            foreach ($toAddresses as $address) {
                if ($address !== '') {
                    $mail->addAddress($address);
                }
            }

            if (!empty($_ENV['SMTP_CC']) && is_string($_ENV['SMTP_CC'])) {
                $ccAddresses = array_map('trim', explode(',', $_ENV['SMTP_CC']));
                foreach ($ccAddresses as $cc) {
                    $mail->addCC($cc);
                }
            }

            if (!empty($_ENV['SMTP_BCC']) && is_string($_ENV['SMTP_BCC'])) {
                $bccAddresses = array_map('trim', explode(',', $_ENV['SMTP_BCC']));
                foreach ($bccAddresses as $bcc) {
                    $mail->addBCC($bcc);
                }
            }

            $mail->Subject = $subject ?? sprintf(
                '%s - %s',
                (is_string($_ENV['APP_NAME'] ?? null) ? $_ENV['APP_NAME'] : 'Log Monitor'),
                (is_string($_ENV['SMTP_SUBJECT'] ?? null) ? $_ENV['SMTP_SUBJECT'] : 'Automatic Notification')
            );

            $mail->isHTML(true);
            $mail->Body = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

            $mail->Priority = in_array($priority, [1, 2, 3, 4, 5], true) ? $priority : 3;

            if ($attachmentPath !== null) {
                $attachments = is_array($attachmentPath) ? $attachmentPath : [$attachmentPath];

                foreach ($attachments as $filePath) {
                    $normalizedPath = realpath($filePath);

                    if ($normalizedPath === false || !is_readable($normalizedPath) || is_dir($normalizedPath)) {
                        LogViaStream::send(LogLevel::ERROR, 'The provided attachment file is inaccessible or invalid.', [
                            'file' => $filePath,
                        ]);
                        throw new RuntimeException(__('The provided attachment file is inaccessible or invalid.'));
                    }

                    $mail->addAttachment($normalizedPath);
                }
            }

            $mail->send();
            $mail->preSend();

            return (string)$mail->getSentMIMEMessage();
        } catch (\Throwable $throwable) {
            LogViaStream::send(LogLevel::ERROR, 'Critical failure within PHPMailer distribution handler', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                'exception_trace' => $throwable->getTraceAsString(),
                'email_recipient' => $to,
                'identifier' => 'SendNotification_Handler_Failure'
            ]);

            throw new RuntimeException(__('A critical error occurred while sending the notification.'), 500, $throwable);
        }
    }
}