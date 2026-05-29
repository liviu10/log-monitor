<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Clasa LogViaStream (Adaptata din LogViaCurl)
 *
 * Permite trimiterea securizata a logurilor catre o componenta centralizata prin stream-uri native PHP.
 * Implementeaza protectie la bucle, urmarire avansata si inregistrare automata ca error handler.
 *
 * @category Utilities
 * @package  App\Utilities
 * @version  2.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class LogViaStream
{
    /** @var bool $isLogging Indicator de stare pentru evitarea buclelor infinite la erori de DB. */
    private static bool $isLogging = false;

    /**
     * Inregistreaza automat clasa ca handler global pentru erori și excepții.
     * Trebuie apelata o singura data in bootstrap-ul aplicatiei (ex: index.php).
     */
    public static function registerHandlers(): void
    {
        // Interceptare erori native PHP (Warnings, Notices etc.)
        set_error_handler(static function (int $severity, string $message, string $file, int $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $levels = [
                E_ERROR             => 'ERROR',
                E_WARNING           => 'WARNING',
                E_PARSE             => 'CRITICAL',
                E_NOTICE            => 'INFO',
                E_USER_ERROR        => 'ERROR',
                E_USER_WARNING      => 'WARNING',
                E_USER_NOTICE       => 'INFO',
                E_RECOVERABLE_ERROR => 'ERROR',
                E_DEPRECATED        => 'INFO',
                E_USER_DEPRECATED   => 'INFO'
            ];

            $level = $levels[$severity] ?? 'ERROR';
            
            self::send($level, $message, [
                'file' => $file,
                'line' => $line,
                'type' => 'PHP Native Error'
            ]);

            return false; // Permite PHP-ului sa isi continue logica interna secundara
        });

        // Interceptare exceptii netratate (Inclusiv PDO/MySQL si Oracle SQL cu filtrare avansata)
        set_exception_handler(static function (\Throwable $exception) {
            $context = [
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
                'code'  => $exception->getCode(),
                'trace' => substr($exception->getTraceAsString(), 0, 1000)
            ];

            $level = 'CRITICAL';
            $message = $exception->getMessage();

            // Verificam daca este o eroare de infrastructura de baza de date (PDO sau OCI)
            if ($exception instanceof \PDOException || str_contains(get_class($exception), 'OCI')) {
                $context['type'] = 'Database Infrastructure Error';

                // Scenariul 1: Mascare pentru erori specifice Oracle (Versiuni Noi si Vechi)
                if (str_contains($message, 'ORA-01017') || str_contains($message, 'logon denied')) {
                    $message = preg_replace('/for user\s+[\'"][^\'"]+[\'"]/i', "for user '******'", $message);
                    $message = "Database Failure (Oracle Auth): " . $message;
                } 
                
                // Scenariul 2: Mascare generala pentru Connection Strings, DSN si TNS (MySQL, MariaDB, Oracle)
                // Prinde: user=XYZ, password=XYZ, host=XYZ, port=XYZ, sid=XYZ etc.
                $patterns = [
                    '/(user|username|uid|pwd|password|pass|host|port|sid|service_name)=\s*[^\s;()"\']+/i'
                ];
                
                $message = preg_replace($patterns, '$1=******', $message);

                // Scenariul 3: Curatare suplimentara in caz ca URL-ul de conexiune contine credentiale inline
                $message = preg_replace('/(:?\/\/)[^:]+:[^@]+@/i', '$1******:******@', $message);

                if (!str_contains($message, 'Database Failure')) {
                    $message = "Database Failure: " . $message;
                }
            } else {
                $context['type'] = 'Standard Application Exception';
                $message = "Exception: " . $message;
            }

            self::send($level, $message, $context);
        });

        // Interceptare erori fatale la închiderea scriptului (Out of memory, Compile errors etc.)
        register_shutdown_function(static function () {
            $error = error_get_last();
            $bufferContent = '';

            // Gestionare si logare buffer: Extragem tot ce apucase PHP sa randeze inainte de crash
            while (ob_get_level() > 0) {
                // Preluam continutul buffer-ului curent si il inchidem
                $content = ob_get_clean();
                if ($content !== false) {
                    $bufferContent = $content . $bufferContent;
                }
            }
            
            // Daca scriptul se inchide din cauza unui crash fatal pe care handlerele de mai sus nu l-au putut opri
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::send('CRITICAL', "Fatal Shutdown Error: " . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => 'PHP Fatal Shutdown',
                    // Atasam continutul buffer-ului in contextul logului (limitat la primele 2000 de caractere ca sa nu umplem DB-ul)
                    'captured_output_buffer' => substr($bufferContent, 0, 2000)
                ]);
            } elseif ($bufferContent !== '') {
                // Daca nu avem o eroare fatala de PHP, dar scriptul s-a terminat brusc lasand buffer deschis (ex: un exit; sau die; neprevazut)
                self::send('WARNING', "Script terminated unexpectedly with unrendered output buffer.", [
                    'type' => 'Orphaned Output Buffer',
                    'captured_output_buffer' => substr($bufferContent, 0, 2000)
                ]);
            }
        });
    }

    /**
     * Expediaza o inregistrare de log catre endpoint-ul configurat folosind wrapper-ul HTTP nativ.
     * Protejeaza executia impotriva buclelor infinite in cazul esecului de rețea.
     *
     * @param string $level Nivelul de severitate (ex: INFO, ERROR, DEBUG).
     * @param string $message Mesajul principal al logului.
     * @param array $context Informatii suplimentare de context.
     * @return bool True daca transmisia s-a finalizat cu status HTTP 200.
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        if (self::$isLogging) {
            return false;
        }

        self::$isLogging = true;

        try {
            // Extragem si validam cheia API direct
            $apiKey = $_ENV['LOG_API_KEY'] ?? null;
            if ($apiKey === null || trim((string)$apiKey) === '') {
                return false;
            }

            // Extragem si validam URL-ul
            $url = $_ENV['LOG_SERVER_URL'] ?? null;
            if ($url === null || trim((string)$url) === '') {
                return false;
            }

            $payload = json_encode([
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context
            ], JSON_THROW_ON_ERROR);

            $options = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => [
                        'Content-Type: application/json',
                        'X-API-KEY: ' . $apiKey,
                        'User-Agent: LogMonitor-Internal/1.0'
                    ],
                    'content' => $payload,
                    'ignore_errors' => true,
                    'timeout' => 2.0
                ]
            ];

            $streamContext = stream_context_create($options);
            
            // Dezactivam temporar raportarea erorilor doar pentru acest request,
            // prevenind re-intrarea in error handler daca serverul central este oprit.
            $oldErrorReporting = error_reporting(0);
            try {
                $result = file_get_contents($url, false, $streamContext);
            } finally {
                // Restauram instant comportamentul initial al aplicatiei
                error_reporting($oldErrorReporting);
            }

            if ($result === false || !isset($http_response_header)) {
                error_log("LogMonitor Alert: Central logging server at {$url} is unreachable or timed out.");
                return false;
            }

            return str_contains($http_response_header[0], '200');

        } catch (\Throwable $e) {
            error_log(json_encode([
                'error' => 'Critical failure inside Stream logging transmission',
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'LogViaStream_Transmission_Failure'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return false;
        } finally {
            self::$isLogging = false;
        }
    }
}