<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\LogViaStream;
use App\Enums\LogLevel;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

/**
 * SendNotification Class
 *
 * Manages sending emails via PHPMailer with corporate proxy support.
 * All exceptions collect full details and dispatch them via cURL.
 * All text messages intended for exceptions use the __() function.
 *
 * @category Packages
 *
 * @version  1.6
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class SendNotification
{
    /** @var bool Execution permission based on environment. */
    private bool $canSendNotification = true;

    /** @var PHPMailer|null Optional custom mailer for testing. */
    private ?PHPMailer $customMailer = null;

    /**
     * Class constructor. Disables email server interaction in local environments (DEV).
     *
     * @param  PHPMailer|null  $customMailer  Optional custom PHPMailer instance.
     */
    public function __construct(?PHPMailer $customMailer = null)
    {
        $this->customMailer = $customMailer;
        $appEnv = $_ENV['APP_ENV'] ?? 'dev';
        if ($appEnv === 'dev' || $appEnv === 'development') {
            $this->canSendNotification = false;
        }
    }

    /**
     * Processes data and sends the email according to the configuration.
     *
     * @param array{
     * to?: string,
     * message?: string,
     * priority?: int,
     * attachmentPath?: string|array<int, string>|null,
     * from?: string|null,
     * subject?: string|null,
     * isHtml?: bool,
     * altBody?: string|null
     * } $emailData Complete set of delivery information.
     * @return string Complete MIME content (.eml) of the transmitted message.
     *
     * @throws RuntimeException When required data is missing or transmission fails.
     */
    public function handle(array $emailData): string
    {
        if (! $this->canSendNotification) {
            throw new RuntimeException(__('Notification system is disabled in this runtime environment.'));
        }

        $to = $emailData['to'] ?? throw new RuntimeException(__('The recipient field is required.'));
        $message = $emailData['message'] ?? throw new RuntimeException(__('The message body field is required.'));
        $priority = $emailData['priority'] ?? 3;
        $attachmentPath = $emailData['attachmentPath'] ?? null;
        $from = $emailData['from'] ?? null;
        $subject = $emailData['subject'] ?? null;
        if ($attachmentPath !== null) {
            $attachments = is_array($attachmentPath) ? $attachmentPath : [$attachmentPath];

            foreach ($attachments as $filePath) {
                try {
                    $normalizedPath = realpath($filePath);
                    if (! is_string($normalizedPath) || ! is_readable($normalizedPath) || is_dir($normalizedPath)) {
                        throw new RuntimeException;
                    }
                } catch (Throwable) {
                    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                        LogViaStream::send(LogLevel::ERROR->value, 'The provided attachment file is inaccessible or invalid.', [
                            'file' => $filePath,
                        ]);
                    }
                    throw new RuntimeException(__('The provided attachment file is inaccessible or invalid.'));
                }
            }
        }
        $mail = $this->customMailer ?? new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = is_string($_ENV['SMTP_HOST'] ?? null) ? $_ENV['SMTP_HOST'] : '127.0.0.1';
            $mail->Port = is_numeric($_ENV['SMTP_PORT'] ?? null) ? (int) $_ENV['SMTP_PORT'] : 587; // $_ENV values are strings
            $mail->SMTPAuth = true;
            $mail->Username = is_string($_ENV['SMTP_USERNAME'] ?? null) ? $_ENV['SMTP_USERNAME'] : '';
            $mail->Password = is_string($_ENV['SMTP_PASSWORD'] ?? null) ? $_ENV['SMTP_PASSWORD'] : '';
            $mail->Timeout = 1800;

            $smtpOptions = [
                'socket' => [
                    'timeout' => 1800,
                ],
            ];

            if (isset($_ENV['PROXY_HOST'], $_ENV['PROXY_PORT']) && $_ENV['PROXY_HOST'] !== '' && $_ENV['PROXY_PORT'] !== '') {
                $proxyHost = $_ENV['PROXY_HOST'];
                $proxyPort = $_ENV['PROXY_PORT'];
                $proxyUrl = sprintf('tcp://%s:%s', is_string($proxyHost) ? $proxyHost : '', is_string($proxyPort) || is_int($proxyPort) ? (string) $proxyPort : '');
                $smtpOptions['ssl'] = [
                    'proxy' => $proxyUrl,
                    // TLS is verified even through the proxy (SMTP credentials travel in this session).
                    // Opt out only for a local intercepting proxy: SMTP_PROXY_INSECURE=true.
                    'verify_peer' => ($_ENV['SMTP_PROXY_INSECURE'] ?? '') !== 'true',
                    'verify_peer_name' => ($_ENV['SMTP_PROXY_INSECURE'] ?? '') !== 'true',
                    'allow_self_signed' => ($_ENV['SMTP_PROXY_INSECURE'] ?? '') === 'true',
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

            if (isset($_ENV['SMTP_CC']) && is_string($_ENV['SMTP_CC']) && $_ENV['SMTP_CC'] !== '') {
                $ccAddresses = array_map('trim', explode(',', $_ENV['SMTP_CC']));
                foreach ($ccAddresses as $cc) {
                    $mail->addCC($cc);
                }
            }
            if (isset($_ENV['SMTP_BCC']) && is_string($_ENV['SMTP_BCC']) && $_ENV['SMTP_BCC'] !== '') {
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
            $isHtml = $emailData['isHtml'] ?? false;
            $altBody = $emailData['altBody'] ?? null;

            if ($isHtml === true) {
                $mail->Body = $message;
                if (is_string($altBody) && trim($altBody) !== '') {
                    $mail->AltBody = $altBody;
                } else {
                    $mail->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $message) ?? $message));
                }
            } else {
                $mail->Body = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
                $mail->AltBody = $message;
            }

            $mail->Priority = in_array($priority, [1, 2, 3, 4, 5], true) ? $priority : 3;

            if ($attachmentPath !== null) {
                $attachments = is_array($attachmentPath) ? $attachmentPath : [$attachmentPath];

                foreach ($attachments as $filePath) {
                    try {
                        $normalizedPath = realpath($filePath);
                        if (is_string($normalizedPath)) {
                            $mail->addAttachment($normalizedPath);
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
            }

            $mail->send();
            $mail->preSend();

            return $mail->getSentMIMEMessage();
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Critical failure within PHPMailer distribution handler', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'email_recipient' => $to,
                    'identifier' => 'SendNotification_Handler_Failure',
                ]);
            }

            throw new RuntimeException(__('A critical error occurred while sending the notification.'), 500, $e);
        }
    }
}
