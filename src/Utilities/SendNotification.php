<?php

declare(strict_types=1);

namespace App\Utilities;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Clasa SendNotification
 *
 * Manages the sending of notifications via email using PHPMailer.
 * This class handles the process of building and sending emails with various options, such as recipients, attachments, priority, etc.
 *
 * @category Package
 * @package  App\Utilities
 * @version  1.1
 * @since    PHP 8.3.30
 * @author   Voica Liviu
 * @license  Proprietar
 */
class SendNotification
{
    /** @const string The name of the log file for notification sending events. */
    private const NOTIFICATION_LOG_FILE_NAME = 'send_notification_log';

    /** @var bool Flag to check if notifications can be sent (disabled in development environment). */
    private bool $canSendNotification = true;

    /**
     * SendNotification Class Constructor.
     *
     * Checks if the environment allows sending notifications.
     * In the development environment, notifications are disabled.
     */
    public function __construct()
    {
        // Checks the environment and disables notifications in the 'dev' or 'development' environment
        $appEnv = $_ENV['APP_ENV'] ?? 'dev';
        if ($appEnv === 'dev' || $appEnv === 'development') {
            LogViaCurl::send(
                'INFO',
                'Notification sending is only allowed in PPT and PROD.',
                ['channel' => self::NOTIFICATION_LOG_FILE_NAME]
            );
            $this->canSendNotification = false; // Disables notification sending in the development environment
        }
    }

    /**
     * Sends an email with the given message using PHPMailer.
     * Manages the addition of recipients, setting the subject and body of the email, attachments and sending the email.
     *
     * If an attachment path is provided, it can be a single file path or an array of file paths.
     * In the case of an array, all files in the array will be attached to the email.
     *
     * @param array{
     *   to: string,
     *   message: string,
     *   priority?: int,
     *   attachmentPath?: string|array<int, string>|null,
     *   from?: string|null,
     *   subject?: string|null
     * } $emailData Data for the email.
     *
     * @return string|false The content of the .eml email in case of success, or false in case of error.
     */
    public function handle(array $emailData)
    {
        // Extracts data from the input array
        $to = $emailData['to'];
        $message = $emailData['message'];
        $priority = $emailData['priority'] ?? 3;
        $attachmentPath = $emailData['attachmentPath'] ?? null;
        $from = $emailData['from'] ?? null;
        $subject = $emailData['subject'] ?? null;

        // If notification sending is disabled, exit the function
        if (!$this->canSendNotification) {
            return false;
        }

        // Initializes the PHPMailer instance
        $mail = new PHPMailer(true);

        try {
            // SMTP server settings
            $mail->isSMTP();
            $mail->Host = (is_string($_ENV['SMTP_HOST'] ?? null) ? $_ENV['SMTP_HOST'] : '10.165.1.12'); // SMTP server address
            $mail->Port = (is_int($_ENV['SMTP_PORT'] ?? null) ? $_ENV['SMTP_PORT'] : 587); // SMTP port
            $mail->SMTPAuth = true;
            $mail->Username = (is_string($_ENV['SMTP_USERNAME'] ?? null) ? $_ENV['SMTP_USERNAME'] : '');
            $mail->Password = (is_string($_ENV['SMTP_PASSWORD'] ?? null) ? $_ENV['SMTP_PASSWORD'] : '');

            // Timeout for sending the message and socket
            $mail->Timeout = 1800; // 30 minutes

            /**
             * SMTPOptions configuration.
             *
             * Initialized with a socket timeout.
             * If environment variables for proxy (PROXY_HOST, PROXY_PORT) are defined,
             * the configuration to route SMTP traffic through a proxy is added.
             * This is useful in corporate environments with strict network policies.
             * 'verify_peer' and 'verify_peer_name' settings are disabled to
             * avoid SSL certificate validation errors behind a proxy.
             */
            $smtpOptions = [
                'socket' => [
                    'timeout' => 1800, // 30 minutes
                ],
            ];

            // Checks and adds proxy configuration if defined in the environment
            if (!empty($_ENV['PROXY_HOST']) && !empty($_ENV['PROXY_PORT'])) {
                $proxyUrl = "tcp://{$_ENV['PROXY_HOST']}:{$_ENV['PROXY_PORT']}";
                
                // Proxy options are added to the 'ssl' context.
                // Even if SMTPSecure is not used explicitly, PHPMailer can initiate STARTTLS,
                // at which point these context settings will be used.
                $smtpOptions['ssl'] = [
                    'proxy' => $proxyUrl,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];
            }

            $mail->SMTPOptions = $smtpOptions;

            // Sender information (From and Reply To)
            $smtpFrom = (is_string($_ENV['SMTP_FROM'] ?? null) ? $_ENV['SMTP_FROM'] : 'noreply.aitpl@groupama.ro');
            $mail->setFrom($from === null ? $smtpFrom : $from);
            $mail->addReplyTo($smtpFrom);

            // Adds recipients
            $toAddresses = array_map('trim', explode(',', $to)); // Splits multiple recipients
            foreach ($toAddresses as $address) {
                $mail->addAddress($address); // Adds each recipient
            }

            // Adds CC and BCC if available in environment variables
            if (isset($_ENV['SMTP_CC']) && is_string($_ENV['SMTP_CC']) && $_ENV['SMTP_CC'] !== '') {
                $ccAddresses = array_map('trim', explode(',', $_ENV['SMTP_CC']));
                foreach ($ccAddresses as $cc) {
                    $mail->addCC($cc); // Adds CC recipient
                }
            }
            if (isset($_ENV['SMTP_BCC']) && is_string($_ENV['SMTP_BCC']) && $_ENV['SMTP_BCC'] !== '') {
                $bccAddresses = array_map('trim', explode(',', $_ENV['SMTP_BCC']));
                foreach ($bccAddresses as $bcc) {
                    $mail->addBCC($bcc); // Adds BCC recipient
                }
            }

            // Sets the subject and body
            $mail->Subject = $subject === null ? sprintf(
                '%s - %s',
                (is_string($_ENV['APP_NAME'] ?? null) ? $_ENV['APP_NAME'] : 'AITPL Exchange Rate'),
                (is_string($_ENV['SMTP_SUBJECT'] ?? null) ? $_ENV['SMTP_SUBJECT'] : 'AITPL Notificare automata')
            ) : $subject;
            $mail->isHTML(true); // Sets the email to HTML format
            $mail->Body = nl2br($message); // Sets the body of the email

            // Sets the priority of the email
            $allowedPriorities = [1, 2, 3, 4, 5]; // Valid priority levels
            if (!in_array($priority, $allowedPriorities, true)) {
                $priority = 3; // Default to normal priority if an invalid priority is provided
            }
            $mail->Priority = $priority;

            // Handles the attachment if it is provided
            if ($attachmentPath !== null) {
                // If it is an array, it iterates and attaches each file
                $attachments = is_array($attachmentPath) ? $attachmentPath : [$attachmentPath];

                foreach ($attachments as $filePath) {
                    // Normalizes the path to resolve '..' and check for existence
                    $normalizedPath = realpath($filePath);

                    // Checks if the attachment path is valid and readable
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

                    // Checks if the attachment path points to a directory instead of a file
                    if (is_dir($normalizedPath)) {
                        LogViaCurl::send(
                            'ERROR',
                            'The attachment path points to a directory, not a file.',
                            [
                                'location' => __METHOD__,
                                'file' => $filePath,
                                'channel' => self::NOTIFICATION_LOG_FILE_NAME
                            ]
                        );

                        return false;
                    }

                    // Adds the attachment to the email
                    $mail->addAttachment($normalizedPath);
                }
            }

            $mail->send();

            $mail->preSend();
            $emlContent = $mail->getSentMIMEMessage();

            // Can be temporarily saved on the server or returned directly
            return $emlContent;
        } catch (\Throwable $e) {
            // Logs any errors that occur during the email sending process
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