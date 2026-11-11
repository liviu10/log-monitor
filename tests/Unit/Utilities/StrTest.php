<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use UnexpectedValueException;

use function App\Utilities\e;

final class StrTest extends TestCase
{
    // "Situatia platilor in Iasi" cu diacritice: a cu caciula, t cu virgula, s cu virgula.
    private const string ROMANIAN = "Situa\u{021B}ia pl\u{0103}\u{021B}ilor \u{00EE}n Ia\u{0219}i";

    private const string INVALID_UTF8 = "abc\xC3\x28";

    // removeDiacritics / slug

    public function test_remove_diacritics_romanian_comma_and_cedilla(): void
    {
        $this->assertSame('Situatia platilor in Iasi', Str::removeDiacritics(self::ROMANIAN));
        $this->assertSame('sStT', Str::removeDiacritics("\u{015F}\u{015E}\u{0163}\u{0162}"), 'forma cu sedila');
        $this->assertSame('sStT', Str::removeDiacritics("\u{0219}\u{0218}\u{021B}\u{021A}"), 'forma cu virgula');
        $this->assertSame('AAI', Str::removeDiacritics("\u{0102}\u{00C2}\u{00CE}"));
    }

    public function test_remove_diacritics_european_letters(): void
    {
        $this->assertSame('Muller Strasse', Str::removeDiacritics("M\u{00FC}ller Stra\u{00DF}e"));
        $this->assertSame('Francois Lodz', Str::removeDiacritics("Fran\u{00E7}ois \u{0141}\u{00F3}d\u{017A}"));
        $this->assertSame('Erdos', Str::removeDiacritics("Erd\u{0151}s"));
    }

    public function test_remove_diacritics_keeps_other_characters(): void
    {
        $this->assertSame("abc 123 \u{0416} \u{20AC}", Str::removeDiacritics("abc 123 \u{0416} \u{20AC}"));
        $this->assertSame('', Str::removeDiacritics(''));
    }

    public function test_slug(): void
    {
        $this->assertSame('situatia-platilor-in-iasi', Str::slug(self::ROMANIAN));
        $this->assertSame('raport-2026-final', Str::slug('  Raport 2026 -- FINAL!!  '));
        $this->assertSame('raport_2026', Str::slug('Raport 2026', '_'));
        $this->assertSame('abc', Str::slug("abc \u{0416}\u{0416}"), 'caracterele fara echivalent ASCII dispar');
        $this->assertSame('', Str::slug('!!!'));
    }

    public function test_slug_rejects_empty_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Str::slug(): separator must not be empty.');

        Str::slug('abc', '');
    }

    // limit / words

    public function test_limit_counts_characters_not_bytes(): void
    {
        $this->assertSame("Situa\u{021B}ia...", Str::limit(self::ROMANIAN, 9));
        $this->assertSame(self::ROMANIAN, Str::limit(self::ROMANIAN, 25));
        $this->assertSame('Ana are mere', Str::limit('Ana are mere', 12));
    }

    public function test_limit_trims_trailing_space_and_custom_end(): void
    {
        $this->assertSame('Ana are...', Str::limit('Ana are mere', 8));
        $this->assertSame("Ana are\u{2026}", Str::limit('Ana are mere', 8, "\u{2026}"));
        $this->assertSame('...', Str::limit('Ana', 0));
    }

    public function test_limit_rejects_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Str::limit(): limit must be >= 0, got -1.');

        Str::limit('abc', -1);
    }

    public function test_words(): void
    {
        $this->assertSame('Ana are...', Str::words('Ana are mere si pere', 2));
        $this->assertSame('Ana are mere', Str::words('Ana are mere', 3));
        $this->assertSame('Ana are mere', Str::words('Ana are mere', 10));
        $this->assertSame("Situa\u{021B}ia pl\u{0103}\u{021B}ilor >", Str::words(self::ROMANIAN, 2, ' >'));
        $this->assertSame('', Str::words('', 3));
    }

    public function test_words_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::words('abc', 0);
    }

    // camel / studly / snake / kebab

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function caseProvider(): iterable
    {
        // input => camel, studly, snake, kebab
        yield 'snake' => ['user_name', 'userName', 'UserName', 'user_name', 'user-name'];
        yield 'kebab' => ['user-name', 'userName', 'UserName', 'user_name', 'user-name'];
        yield 'spatii' => ['  user   name ', 'userName', 'UserName', 'user_name', 'user-name'];
        yield 'camel' => ['userName', 'userName', 'UserName', 'user_name', 'user-name'];
        yield 'studly' => ['UserName', 'userName', 'UserName', 'user_name', 'user-name'];
        yield 'acronim' => ['XMLHttpRequest', 'xmlHttpRequest', 'XmlHttpRequest', 'xml_http_request', 'xml-http-request'];
        yield 'cifre' => ['address2Line', 'address2Line', 'Address2Line', 'address2_line', 'address2-line'];
        yield 'majuscule' => ['USER_ID', 'userId', 'UserId', 'user_id', 'user-id'];
        yield 'un cuvant' => ['id', 'id', 'Id', 'id', 'id'];
        yield 'gol' => ['', '', '', '', ''];
    }

    #[DataProvider('caseProvider')]
    public function test_case_conversions(string $input, string $camel, string $studly, string $snake, string $kebab): void
    {
        $this->assertSame($camel, Str::camel($input));
        $this->assertSame($studly, Str::studly($input));
        $this->assertSame($snake, Str::snake($input));
        $this->assertSame($kebab, Str::kebab($input));
    }

    public function test_case_conversions_with_diacritics(): void
    {
        $this->assertSame("\u{0219}tefan_cel_mare", Str::snake("\u{0218}tefanCelMare"));
        $this->assertSame("\u{0218}tefanCelMare", Str::studly("\u{0219}tefan cel mare"));
        $this->assertSame("\u{00EE}nceputNou", Str::camel("\u{00CE}nceput nou"));
        $this->assertSame('a.b.c', Str::snake('a b c', '.'));
    }

    // mask

    public function test_mask(): void
    {
        $this->assertSame('************1111', Str::mask('4111111111111111', '*', 0, -4));
        $this->assertSame('i**@exemplu.ro', Str::mask('ion@exemplu.ro', '*', 1, 2));
        $this->assertSame('RO49 AAAA **************', Str::mask('RO49 AAAA 1B31 0075 9384', '*', 10));
        $this->assertSame('abc***', Str::mask('abcdef', '*', -3));
        $this->assertSame('******', Str::mask('abcdef', '*', -10));
        $this->assertSame('abcdef', Str::mask('abcdef', '*', 10), 'index dupa final -> neschimbat');
        $this->assertSame("Ia\u{2022}\u{2022}", Str::mask("Ia\u{0219}i", "\u{2022}", 2), 'caractere UTF-8');
    }

    public function test_mask_rejects_multi_character_mask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Str::mask(): the mask must be exactly one character.');

        Str::mask('abc', '**', 0);
    }

    // random

    public function test_random(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', Str::random());
        $this->assertSame(40, strlen(Str::random(40)));
        $this->assertNotSame(Str::random(32), Str::random(32));
    }

    public function test_random_rejects_zero_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::random(0);
    }

    // between / before / after

    public function test_before_and_after(): void
    {
        $this->assertSame('raport', Str::before('raport.final.pdf', '.'));
        $this->assertSame('raport.final', Str::beforeLast('raport.final.pdf', '.'));
        $this->assertSame('final.pdf', Str::after('raport.final.pdf', '.'));
        $this->assertSame('pdf', Str::afterLast('raport.final.pdf', '.'));
        $this->assertSame('', Str::before('.pdf', '.'));
        $this->assertSame("\u{0219}i", Str::after("Ia\u{0219}i", 'Ia'));
    }

    public function test_between(): void
    {
        $this->assertSame('123', Str::between('[id:123] text', '[id:', ']'));
        $this->assertSame('b', Str::between('a(b)c(d)', '(', ')'));
        $this->assertSame('', Str::between('a()b', '(', ')'));
    }

    public function test_between_end_delimiter_must_follow_start(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Str::between(): ')' was not found in the input.");

        Str::between(') text (', '(', ')');
    }

    public function test_missing_delimiter_throws_without_input_in_message(): void
    {
        try {
            Str::after('date confidentiale', '#');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertSame("Str::after(): '#' was not found in the input.", $e->getMessage());
        }
    }

    public function test_empty_search_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Str::before(): search string must not be empty.');

        Str::before('abc', '');
    }

    // squish / pad / ucfirst

    public function test_squish(): void
    {
        $this->assertSame('Ana are mere', Str::squish("  Ana \t\n are\r\n   mere  "));
        $this->assertSame('Ana are mere', Str::squish("Ana\u{00A0}are\u{200B}mere\u{FEFF}"));
        $this->assertSame('', Str::squish("  \n "));
    }

    public function test_pad(): void
    {
        $this->assertSame('007', Str::padLeft('7', 3, '0'));
        $this->assertSame('7  ', Str::padRight('7', 3));
        $this->assertSame('abab7', Str::padLeft('7', 5, 'ab'));
        $this->assertSame("\u{0219}\u{0219}a", Str::padLeft('a', 3, "\u{0219}"), 'lungime in caractere');
        $this->assertSame("Ia\u{0219}i ", Str::padRight("Ia\u{0219}i", 5));
        $this->assertSame('12345', Str::padLeft('12345', 3, '0'), 'nu trunchiaza');
    }

    public function test_pad_rejects_empty_pad(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Str::padRight(): pad string must not be empty.');

        Str::padRight('a', 3, '');
    }

    public function test_ucfirst(): void
    {
        $this->assertSame("\u{0218}tefan", Str::ucfirst("\u{0219}tefan"));
        $this->assertSame("\u{00CE}nceput", Str::ucfirst("\u{00EE}nceput"));
        $this->assertSame('Abc', Str::ucfirst('abc'));
        $this->assertSame('', Str::ucfirst(''));
    }

    // e()

    public function test_e_escapes_html(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        $this->assertSame('O&#039;Brien &amp; co', e("O'Brien & co"));
        $this->assertSame('&amp;amp;', e('&amp;'), 'escaping dublu, intentionat');
        $this->assertSame(self::ROMANIAN, e(self::ROMANIAN));
    }

    public function test_e_accepts_scalars_null_and_stringable(): void
    {
        $this->assertSame('', e(null));
        $this->assertSame('42', e(42));
        $this->assertSame('1.5', e(1.5));

        $object = new class implements Stringable
        {
            public function __toString(): string
            {
                return '<b>';
            }
        };
        $this->assertSame('&lt;b&gt;', e($object));
        $this->assertSame('&lt;b&gt;', Str::e($object));
    }

    // UTF-8 invalid

    /**
     * @return iterable<string, array{callable(string): mixed, string}>
     */
    public static function invalidUtf8Provider(): iterable
    {
        yield 'slug' => [static fn (string $text): string => Str::slug($text), 'slug'];
        yield 'removeDiacritics' => [Str::removeDiacritics(...), 'removeDiacritics'];
        yield 'limit' => [static fn (string $text): string => Str::limit($text, 2), 'limit'];
        yield 'words' => [static fn (string $text): string => Str::words($text, 2), 'words'];
        yield 'camel' => [Str::camel(...), 'camel'];
        yield 'snake' => [static fn (string $text): string => Str::snake($text), 'snake'];
        yield 'kebab' => [Str::kebab(...), 'kebab'];
        yield 'mask' => [static fn (string $text): string => Str::mask($text, '*', 1), 'mask'];
        yield 'squish' => [Str::squish(...), 'squish'];
        yield 'padLeft' => [static fn (string $text): string => Str::padLeft($text, 10), 'padLeft'];
        yield 'ucfirst' => [Str::ucfirst(...), 'ucfirst'];
        yield 'e' => [e(...), 'e'];
    }

    #[DataProvider('invalidUtf8Provider')]
    public function test_invalid_utf8_is_rejected_without_content_in_message(callable $operation, string $method): void
    {
        try {
            $operation(self::INVALID_UTF8);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertSame("Str::{$method}(): input is not valid UTF-8 (length: 5 bytes).", $e->getMessage());
        }
    }

    public function test_no_warnings_reach_host_error_handler(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        });

        try {
            foreach ([self::INVALID_UTF8, '', self::ROMANIAN] as $text) {
                try {
                    Str::slug($text);
                    Str::snake($text);
                    Str::squish($text);
                    Str::mask($text, '*', 1);
                } catch (UnexpectedValueException) {
                }
            }
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }
}
