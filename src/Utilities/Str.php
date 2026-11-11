<?php

declare(strict_types=1);

namespace App\Utilities;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use UnexpectedValueException;

/**
 * Str Class
 *
 * UTF-8 text operations: slugs, diacritic removal, truncation, casing conversions
 * (camelCase, snake_case, etc.), masking, and substring extraction between delimiters.
 *
 * All methods operate on characters rather than bytes: limit('Iasi', 2) will not split a multibyte
 * Romanian character in half. Invalid UTF-8 text throws an exception (error message contains
 * only length, not raw text).
 *
 * Diacritics are removed using an internal translation table (Romanian, including comma/cedilla variants,
 * plus common characters across European languages), ensuring results are independent of the intl extension
 * or operating system (iconv //TRANSLIT behaves differently across Windows and Linux).
 *
 * This file also defines the e() helper function for HTML escaping.
 *
 * Usage:
 *   require_once 'Str.php';
 *   use App\Utilities\Str;
 *   use function App\Utilities\e;
 *
 *   $slug = Str::slug($title);                       // 'Situatia platilor - 2026' -> 'situatia-platilor-2026'
 *   $short = Str::limit($description, 100);
 *   $card = Str::mask($cardNumber, '*', 0, -4);      // '************1111'
 *   $column = Str::snake('createdAt');               // 'created_at'
 *   echo '<td>'.e($row['name']).'</td>';
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Str
{
    /** @var array<string, string> ASCII letter -> accented characters mapped to it. */
    private const array TRANSLITERATION = [
        'a' => "\u{00E0}\u{00E1}\u{00E2}\u{00E3}\u{00E4}\u{00E5}\u{0101}\u{0103}\u{0105}",
        'A' => "\u{00C0}\u{00C1}\u{00C2}\u{00C3}\u{00C4}\u{00C5}\u{0100}\u{0102}\u{0104}",
        'c' => "\u{00E7}\u{0107}\u{010D}",
        'C' => "\u{00C7}\u{0106}\u{010C}",
        'd' => "\u{010F}\u{0111}",
        'D' => "\u{010E}\u{0110}",
        'e' => "\u{00E8}\u{00E9}\u{00EA}\u{00EB}\u{0113}\u{0117}\u{0119}\u{011B}",
        'E' => "\u{00C8}\u{00C9}\u{00CA}\u{00CB}\u{0112}\u{0116}\u{0118}\u{011A}",
        'g' => "\u{011F}",
        'G' => "\u{011E}",
        'i' => "\u{00EC}\u{00ED}\u{00EE}\u{00EF}\u{012B}\u{012F}\u{0131}",
        'I' => "\u{00CC}\u{00CD}\u{00CE}\u{00CF}\u{012A}\u{012E}\u{0130}",
        'l' => "\u{013A}\u{013E}\u{0142}",
        'L' => "\u{0139}\u{013D}\u{0141}",
        'n' => "\u{00F1}\u{0144}\u{0148}",
        'N' => "\u{00D1}\u{0143}\u{0147}",
        'o' => "\u{00F2}\u{00F3}\u{00F4}\u{00F5}\u{00F6}\u{00F8}\u{014D}\u{0151}",
        'O' => "\u{00D2}\u{00D3}\u{00D4}\u{00D5}\u{00D6}\u{00D8}\u{014C}\u{0150}",
        'r' => "\u{0155}\u{0159}",
        'R' => "\u{0154}\u{0158}",
        's' => "\u{015B}\u{015F}\u{0161}\u{0219}",
        'S' => "\u{015A}\u{015E}\u{0160}\u{0218}",
        't' => "\u{0163}\u{0165}\u{021B}",
        'T' => "\u{0162}\u{0164}\u{021A}",
        'u' => "\u{00F9}\u{00FA}\u{00FB}\u{00FC}\u{016B}\u{016F}\u{0171}\u{0173}",
        'U' => "\u{00D9}\u{00DA}\u{00DB}\u{00DC}\u{016A}\u{016E}\u{0170}\u{0172}",
        'y' => "\u{00FD}\u{00FF}",
        'Y' => "\u{00DD}\u{0178}",
        'z' => "\u{017A}\u{017C}\u{017E}",
        'Z' => "\u{0179}\u{017B}\u{017D}",
        'ss' => "\u{00DF}",
        'ae' => "\u{00E6}",
        'AE' => "\u{00C6}",
        'oe' => "\u{0153}",
        'OE' => "\u{0152}",
    ];

    /** @var string Characters used by random(). */
    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** @var array<string, string>|null Character -> ASCII translation map, built on first use. */
    private static ?array $transliteration = null;

    private function __construct() {}

    /**
     * URL- and filename-safe slug: lowercase ASCII letters, digits, and separator.
     * Characters without an ASCII equivalent (e.g. Cyrillic) are removed.
     *
     * @throws InvalidArgumentException If separator is empty.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function slug(string $text, string $separator = '-'): string
    {
        self::assertUtf8('slug', $text);

        if ($separator === '') {
            throw new InvalidArgumentException('Str::slug(): separator must not be empty.');
        }

        $ascii = strtolower(self::removeDiacritics($text));
        $slug = self::replace('slug', '/[^a-z0-9]+/', $separator, $ascii);

        return trim($slug, $separator);
    }

    /**
     * Replaces accented letters with their ASCII equivalents (s with comma and s with cedilla -> s,
     * a with breve -> a, German ss -> ss). Other characters remain unchanged.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function removeDiacritics(string $text): string
    {
        self::assertUtf8('removeDiacritics', $text);

        if (self::$transliteration === null) {
            self::$transliteration = [];
            foreach (self::TRANSLITERATION as $ascii => $letters) {
                foreach (mb_str_split($letters) as $letter) {
                    self::$transliteration[$letter] = $ascii;
                }
            }
        }

        return strtr($text, self::$transliteration);
    }

    /**
     * Truncates string to $limit characters and appends $end if shortened.
     * $end is not included in the limit count.
     *
     * @throws InvalidArgumentException If $limit is negative.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function limit(string $text, int $limit, string $end = '...'): string
    {
        self::assertUtf8('limit', $text);

        if ($limit < 0) {
            throw new InvalidArgumentException("Str::limit(): limit must be >= 0, got {$limit}.");
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit)).$end;
    }

    /**
     * Retains the first $words words and appends $end if the string was shortened.
     *
     * @throws InvalidArgumentException If $words is less than 1.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function words(string $text, int $words, string $end = '...'): string
    {
        self::assertUtf8('words', $text);

        if ($words < 1) {
            throw new InvalidArgumentException("Str::words(): words must be >= 1, got {$words}.");
        }

        if (preg_match('/^\s*+(?:\S++\s*+){1,'.$words.'}/u', $text, $match) !== 1 || strlen($match[0]) === strlen($text)) {
            return $text;
        }

        return rtrim($match[0]).$end;
    }

    /**
     * 'user_name', 'user-name', 'User Name' -> 'userName'.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function camel(string $text): string
    {
        $studly = self::joinStudly(self::splitWords('camel', $text));

        return mb_strtolower(mb_substr($studly, 0, 1)).mb_substr($studly, 1);
    }

    /**
     * 'user_name', 'userName', 'user name' -> 'UserName'.
     * Acronyms are treated as words: 'XMLHttpRequest' -> 'XmlHttpRequest'.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function studly(string $text): string
    {
        return self::joinStudly(self::splitWords('studly', $text));
    }

    /**
     * 'userName', 'UserName', 'user name' -> 'user_name'; 'XMLHttpRequest' -> 'xml_http_request'.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function snake(string $text, string $delimiter = '_'): string
    {
        return mb_strtolower(implode($delimiter, self::splitWords('snake', $text)));
    }

    /**
     * 'userName', 'user_name' -> 'user-name'.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function kebab(string $text): string
    {
        return mb_strtolower(implode('-', self::splitWords('kebab', $text)));
    }

    /**
     * Replaces a portion of the string with $character, preserving length.
     * Negative $index counts from the end; null $length masks to the end; negative leaves trailing characters visible.
     *
     *   Str::mask('4111111111111111', '*', 0, -4)  -> '************1111'
     *
     *   Str::mask('ion@exemplu.ro', '*', 1, 2)     -> 'i**@exemplu.ro'
     *
     * @throws InvalidArgumentException If $character is not exactly one character.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function mask(string $text, string $character, int $index, ?int $length = null): string
    {
        self::assertUtf8('mask', $text);

        if (mb_strlen($character) !== 1 || ! mb_check_encoding($character, 'UTF-8')) {
            throw new InvalidArgumentException('Str::mask(): the mask must be exactly one character.');
        }

        $segment = mb_substr($text, $index, $length);
        if ($segment === '') {
            return $text;
        }

        $textLength = mb_strlen($text);
        $start = $index < 0 ? max(0, $textLength + $index) : $index;
        $segmentLength = mb_strlen($segment);

        return mb_substr($text, 0, $start).str_repeat($character, $segmentLength).mb_substr($text, $start + $segmentLength);
    }

    /**
     * Cryptographically secure random alphanumeric string (random_int).
     * For security tokens, Token::random() is recommended.
     *
     * @throws InvalidArgumentException If $length is less than 1.
     */
    public static function random(int $length = 16): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException("Str::random(): length must be >= 1, got {$length}.");
        }

        $max = strlen(self::ALPHABET) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= self::ALPHABET[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Extracts text between the first occurrence of $from and the next occurrence of $to.
     *
     * @throws InvalidArgumentException If a delimiter is empty.
     * @throws UnexpectedValueException If a delimiter is not found in the string.
     */
    public static function between(string $text, string $from, string $to): string
    {
        $rest = substr($text, self::position('between', $text, $from, false) + strlen($from));

        return substr($rest, 0, self::position('between', $rest, $to, false));
    }

    /**
     * Extracts text before the first occurrence of $search.
     *
     * @throws InvalidArgumentException If $search is empty.
     * @throws UnexpectedValueException If $search is not found in the string.
     */
    public static function before(string $text, string $search): string
    {
        return substr($text, 0, self::position('before', $text, $search, false));
    }

    /**
     * Extracts text before the last occurrence of $search ('report.final.pdf', '.' -> 'report.final').
     *
     * @throws InvalidArgumentException If $search is empty.
     * @throws UnexpectedValueException If $search is not found in the string.
     */
    public static function beforeLast(string $text, string $search): string
    {
        return substr($text, 0, self::position('beforeLast', $text, $search, true));
    }

    /**
     * Extracts text after the first occurrence of $search.
     *
     * @throws InvalidArgumentException If $search is empty.
     * @throws UnexpectedValueException If $search is not found in the string.
     */
    public static function after(string $text, string $search): string
    {
        return substr($text, self::position('after', $text, $search, false) + strlen($search));
    }

    /**
     * Extracts text after the last occurrence of $search ('report.final.pdf', '.' -> 'pdf').
     *
     * @throws InvalidArgumentException If $search is empty.
     * @throws UnexpectedValueException If $search is not found in the string.
     */
    public static function afterLast(string $text, string $search): string
    {
        return substr($text, self::position('afterLast', $text, $search, true) + strlen($search));
    }

    /**
     * Trims leading/trailing whitespace and reduces any whitespace sequence (including tabs, newlines,
     * non-breaking spaces, zero-width spaces) to a single space.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function squish(string $text): string
    {
        self::assertUtf8('squish', $text);

        return trim(self::replace('squish', '/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $text), ' ');
    }

    /**
     * Pads the string on the left up to $length characters ('7', 3, '0' -> '007').
     * Strings longer than $length remain untouched (not truncated).
     *
     * @throws InvalidArgumentException If $pad is empty.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function padLeft(string $text, int $length, string $pad = ' '): string
    {
        return self::padding('padLeft', $text, $length, $pad).$text;
    }

    /**
     * Pads the string on the right up to $length characters.
     * Strings longer than $length remain untouched (not truncated).
     *
     * @throws InvalidArgumentException If $pad is empty.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function padRight(string $text, int $length, string $pad = ' '): string
    {
        return $text.self::padding('padRight', $text, $length, $pad);
    }

    /**
     * Capitalizes first character, supporting multibyte and accented characters (native ucfirst() handles ASCII only).
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function ucfirst(string $text): string
    {
        self::assertUtf8('ucfirst', $text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Escapes text for HTML (content and attribute values, with single or double quotes).
     * null becomes an empty string.
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    public static function e(string|int|float|Stringable|null $value): string
    {
        $text = (string) $value;
        self::assertUtf8('e', $text);

        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Splits an identifier into words: 'XMLHttpRequest' -> ['XML', 'Http', 'Request'],
     * 'user_name-2' -> ['user', 'name', '2'].
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    private static function splitWords(string $method, string $text): array
    {
        self::assertUtf8($method, $text);

        // Granita litera mica/cifra -> litera mare ('userName'), apoi acronim -> cuvant ('XMLHttp').
        $text = self::replace($method, '/(?<=[\p{Ll}\p{N}])(?=\p{Lu})/u', ' ', $text);
        $text = self::replace($method, '/(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', ' ', $text);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            throw new RuntimeException("Str::{$method}(): ".preg_last_error_msg().'.');
        }

        return $words;
    }

    /**
     * @param  list<string>  $words
     */
    private static function joinStudly(array $words): string
    {
        return implode('', array_map(static fn (string $word): string => self::ucfirst(mb_strtolower($word)), $words));
    }

    /**
     * Byte offset of $search in text.
     *
     * @throws InvalidArgumentException If $search is empty.
     * @throws UnexpectedValueException If $search is not found in the string.
     */
    private static function position(string $method, string $text, string $search, bool $last): int
    {
        if ($search === '') {
            throw new InvalidArgumentException("Str::{$method}(): search string must not be empty.");
        }

        $position = $last ? strrpos($text, $search) : strpos($text, $search);

        return $position === false ? throw self::notFound($method, $search) : $position;
    }

    /**
     * Calculates padding characters needed to reach $length (empty string if already long enough).
     *
     * @throws InvalidArgumentException If $pad is empty.
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    private static function padding(string $method, string $text, int $length, string $pad): string
    {
        self::assertUtf8($method, $text);
        self::assertUtf8($method, $pad);

        if ($pad === '') {
            throw new InvalidArgumentException("Str::{$method}(): pad string must not be empty.");
        }

        $missing = $length - mb_strlen($text);
        if ($missing <= 0) {
            return '';
        }

        return mb_substr(str_repeat($pad, (int) ceil($missing / mb_strlen($pad))), 0, $missing);
    }

    /**
     * preg_replace converting errors to exceptions (patterns are static; only backtracking limit could fail).
     *
     * @throws RuntimeException If the regular expression fails at runtime.
     */
    private static function replace(string $method, string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text)
            ?? throw new RuntimeException("Str::{$method}(): ".preg_last_error_msg().'.');
    }

    /**
     * @throws UnexpectedValueException If text is not valid UTF-8.
     */
    private static function assertUtf8(string $method, string $text): void
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new UnexpectedValueException(sprintf('Str::%s(): input is not valid UTF-8 (length: %d bytes).', $method, strlen($text)));
        }
    }

    private static function notFound(string $method, string $search): UnexpectedValueException
    {
        return new UnexpectedValueException("Str::{$method}(): '{$search}' was not found in the input.");
    }
}

if (! function_exists(__NAMESPACE__.'\e')) {
    /**
     * HTML escaping: shortcut for Str::e().
     *
     * @throws UnexpectedValueException If string is not valid UTF-8.
     */
    function e(string|int|float|Stringable|null $value): string
    {
        return Str::e($value);
    }
}
