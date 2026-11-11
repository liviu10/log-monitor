<?php

declare(strict_types=1);

// Router pentru serverul integrat PHP (php -S), folosit de testele web (Response, Session, Csrf).
// Fiecare cale este un scenariu; rezultatul se verifica din header-ele si corpul raspunsului.

require_once dirname(__DIR__, 4).'/vendor/autoload.php';

use App\Utilities\Csrf;
use App\Utilities\ErrorHandler;
use App\Utilities\SecurityHeaders;
use App\Utilities\Session;

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

/**
 * Porneste sesiunea intr-un director temporar.
 *
 * @param  array<string, mixed>  $options
 */
function startTestSession(array $options = []): void
{
    $directory = sys_get_temp_dir().'/web-router-sessions';
    if (! is_dir($directory)) {
        mkdir($directory);
    }

    Session::start(['savePath' => $directory, ...$options]);
}

$session = startTestSession(...);

$json = static function (mixed $data): void {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
};

// Clasa si mesajul exceptiei aruncate de $operation.
$error = static function (Closure $operation): string {
    try {
        $operation();
    } catch (Throwable $e) {
        return $e::class.': '.$e->getMessage();
    }

    return 'no exception';
};

$scenarios = [
    // Session

    '/session/set' => static function () use ($session, $json): void {
        $session(['name' => 'TESTSESSID']);
        Session::set('user_id', 5);
        Session::set('lista', ['a' => 1]);
        $json(['id' => Session::id()]);
    },
    '/session/get' => static function () use ($session, $json): void {
        $session(['name' => 'TESTSESSID', 'idleTimeout' => 60]);
        $json([
            'id' => Session::id(),
            'all' => Session::all(),
            'has' => Session::has('user_id'),
            'default' => Session::get('lipsa', 'implicit'),
            'flashed' => Session::flashed('status'),
            'old' => Session::old('nume', ''),
            'expired' => Session::expired(),
        ]);
    },
    '/session/missing' => static function () use ($session, $error): void {
        $session(['name' => 'TESTSESSID']);
        echo $error(static fn (): mixed => Session::get('lipsa'));
    },
    '/session/pull' => static function () use ($session, $json): void {
        $session(['name' => 'TESTSESSID']);
        $json(['pulled' => Session::pull('user_id'), 'after' => Session::has('user_id'), 'default' => Session::pull('lipsa', 0)]);
    },
    '/session/clear' => static function () use ($session, $json): void {
        $session(['name' => 'TESTSESSID']);
        Session::flash('status', 'pastrat');
        Session::clear();
        $json(['all' => Session::all()]);
    },
    '/session/regenerate' => static function () use ($session, $json): void {
        $session(['name' => 'TESTSESSID']);
        $old = Session::id();
        Session::regenerate();
        $json(['old' => $old, 'new' => Session::id(), 'user_id' => Session::get('user_id')]);
    },
    '/session/destroy' => static function () use ($session): void {
        $session(['name' => 'TESTSESSID']);
        Session::destroy();
        echo Session::isStarted() ? 'active' : 'destroyed';
    },
    '/session/flash' => static function () use ($session): void {
        $session(['name' => 'TESTSESSID']);
        Session::flash('status', 'Salvat');
        Session::flashInput(['nume' => 'Ion']);
        echo 'ok';
    },
    '/session/age' => static function () use ($session): void {
        $session(['name' => 'TESTSESSID']);
        $meta = $_SESSION['__session'] ?? [];
        if (is_array($meta)) {
            $meta['lastActivity'] = time() - 120;
            $_SESSION['__session'] = $meta;
        }
        echo 'ok';
    },
    '/session/options' => static function () use ($session): void {
        $session(['name' => 'OPTSESSID', 'lifetime' => 3600, 'sameSite' => 'Strict', 'path' => '/app']);
        echo 'ok';
    },
    '/session/secure' => static function () use ($session): void {
        $session(['name' => 'SECSESSID', 'secure' => true, 'sameSite' => 'None']);
        echo 'ok';
    },
    '/session/invalid' => static function () use ($session, $json, $error): void {
        $json([
            'not_started' => $error(static fn (): mixed => Session::get('x')),
            'unknown_option' => $error(static fn () => $session(['nume' => 'x'])),
            'samesite' => $error(static fn () => $session(['sameSite' => 'lax'])),
            'none_insecure' => $error(static fn () => $session(['sameSite' => 'None'])),
            'name' => $error(static fn () => $session(['name' => '1abc'])),
            'idle' => $error(static fn () => $session(['idleTimeout' => 0])),
            'save_path' => $error(static fn () => Session::start(['savePath' => __DIR__.'/lipsa'])),
            'type' => $error(static fn () => $session(['lifetime' => '3600'])),
        ]);
    },
    '/session/unstorable' => static function () use ($session, $json, $error): void {
        $session(['name' => 'TESTSESSID']);
        $json([
            'closure' => $error(static fn () => Session::set('f', static fn (): int => 1)),
            'nested' => $error(static fn () => Session::set('f', ['a' => [fopen('php://memory', 'r')]])),
            'reserved' => $error(static fn () => Session::set('__session', 1)),
            'stored' => Session::has('f'),
        ]);
    },
    '/session/headers-sent' => static function () use ($session, $error): void {
        echo 'output';
        echo '|'.$error(static fn () => $session());
    },

    // SecurityHeaders

    '/security-headers/send' => static function (): void {
        SecurityHeaders::send(['frameOptions' => 'SAMEORIGIN']);
        echo '<script nonce="'.SecurityHeaders::nonce().'"></script>';
    },
    '/security-headers/headers-sent' => static function () use ($error): void {
        echo 'output';
        echo '|'.$error(static fn () => SecurityHeaders::send());
    },

    // ErrorHandler (exceptiile ajung neprinse la handler-ul inregistrat)

    '/error/production' => static function (): void {
        ErrorHandler::register();
        ob_start(); // ca output_buffering din php.ini al serverelor reale
        echo 'pagina inceputa';
        throw new RuntimeException('detaliu intern');
    },
    '/error/debug' => static function (): void {
        ErrorHandler::register(debug: true);
        throw new RuntimeException('detaliu intern');
    },
    '/error/warning' => static function (): void {
        ErrorHandler::register();
        file_get_contents(__DIR__.'/fisier-inexistent.txt');
        echo 'nu ajunge aici';
    },
    '/error/renderer' => static function (): void {
        ErrorHandler::register(renderer: static function (Throwable $e, bool $debug): void {
            echo 'pagina proprie: '.$e::class.', debug='.($debug ? 'yes' : 'no');
        });
        throw new RuntimeException('x');
    },
    '/error/renderer-fails' => static function (): void {
        ErrorHandler::register(renderer: static function (): void {
            throw new LogicException('renderer stricat');
        });
        throw new RuntimeException('x');
    },
    '/error/fatal' => static function (): void {
        ini_set('memory_limit', '32M');
        ErrorHandler::register();
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data[] = str_repeat('x', 1024 * 1024);
        }

        echo count($data);
    },

    // Csrf

    '/csrf/token' => static function () use ($session, $json): void {
        $session(['name' => 'CSRFSESSID']);
        $json(['token' => Csrf::token(), 'again' => Csrf::token(), 'field' => Csrf::field(), 'custom' => Csrf::field('csrf')]);
    },
    '/csrf/check' => static function () use ($session): void {
        $session(['name' => 'CSRFSESSID']);
        try {
            Csrf::check();
            echo 'ok';
        } catch (UnexpectedValueException $e) {
            echo $e->getCode().': '.$e->getMessage();
        }
    },
    '/csrf/check-field' => static function () use ($session): void {
        $session(['name' => 'CSRFSESSID']);
        Csrf::check(field: 'csrf');
        echo 'ok';
    },
    '/csrf/regenerate' => static function () use ($session, $json): void {
        $session(['name' => 'CSRFSESSID']);
        $old = Csrf::token();
        $json(['old' => $old, 'new' => Csrf::regenerate(), 'old_valid' => Csrf::verify($old)]);
    },
    '/csrf/invalid' => static function () use ($session, $json, $error): void {
        $before = $error(static fn (): string => Csrf::token());
        $session(['name' => 'CSRFSESSID']);
        $json([
            'no_session' => $before,
            'field_name' => $error(static fn (): string => Csrf::field('a"b')),
            'verify_array' => Csrf::verify(['x']),
            'verify_empty' => Csrf::verify(''),
        ]);
    },
];

if (! isset($scenarios[$path])) {
    http_response_code(404);
    echo 'unknown scenario';

    return true;
}

$scenarios[$path]();

return true;
