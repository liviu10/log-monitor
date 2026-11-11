<?php

declare(strict_types=1);

namespace App\Utilities;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Date Class
 *
 * Strict calendar dates, working days, and statutory public holidays in Romania.
 *
 * Pitfalls of native functions avoided:
 *   - strtotime() / date_create() return false on invalid values;
 *   - DateTime::createFromFormat() silently rolls over 31.02.2026 to 03.03.2026, and any time
 *     omitted from the format defaults to current time rather than 00:00;
 *   - 'Y' accepts '26' as year 0026;
 *   - date('F') formats months in English only, while setlocale() / strftime() depend on server environment
 *     (and strftime() is deprecated).
 *
 * All returned values are DateTimeImmutable instances. The default timezone is from php.ini;
 * date creation methods accept an explicit DateTimeZone.
 *
 * Statutory holidays match the Romanian Labor Code (art. 139), including effective start years
 * for each holiday; Easter and Pentecost follow the Orthodox calendar. If the law changes,
 * holidays() and @version must be updated.
 *
 * Error messages include the expected format rather than the provided input (which may be sensitive, like a date of birth).
 *
 * Usage:
 *   require_once 'Date.php';
 *   use App\Utilities\Date;
 *
 *   $date = Date::parse($_POST['data'], 'd.m.Y');          // 31.02.2026 -> throws exception
 *   $db = Date::roToDb('15.03.2026');                      // '2026-03-15'
 *   echo Date::format($date, 'j F Y');                     // '15 martie 2026'
 *   $deadline = Date::addWorkingDays(Date::today(), 10);   // skips weekends and public holidays
 *   $age = Date::age(Date::fromDb($row['birth_date']));
 *
 * @category Utilities
 *
 * @version  1.1
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Date
{
    public const string RO_DATE = 'd.m.Y';

    public const string RO_DATETIME = 'd.m.Y H:i';

    public const string DB_DATE = 'Y-m-d';

    public const string DB_DATETIME = 'Y-m-d H:i:s';

    /** @var list<string> Formats accepted by fromDb(). */
    private const array DB_FORMATS = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i:s.u'];

    /** @var array<int, string> */
    private const array MONTHS = [
        1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie',
        'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie',
    ];

    /** @var array<int, string> */
    private const array MONTHS_SHORT = [
        1 => 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec',
    ];

    /** @var array<int, string> Days of the week matching format('N') (1 = Monday). */
    private const array DAYS = [
        1 => 'luni', "mar\u{021B}i", 'miercuri', 'joi', 'vineri', "s\u{00E2}mb\u{0103}t\u{0103}", "duminic\u{0103}",
    ];

    /** @var array<int, string> */
    private const array DAYS_SHORT = [
        1 => 'lun', 'mar', 'mie', 'joi', 'vin', "s\u{00E2}m", 'dum',
    ];

    /** @var array<int, array<string, string>> Computed holidays keyed by year. */
    private static array $holidays = [];

    private function __construct() {}

    /**
     * Parses a date string matching an exact format. Omitted parts (e.g. time) default to 0 rather than current time.
     *
     * @throws InvalidArgumentException If the value is empty.
     * @throws UnexpectedValueException If the value does not match the format or is not a real calendar date (e.g. 31.02).
     */
    public static function parse(string $value, string $format = self::RO_DATE, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        return self::create('parse', $value, $format, $timezone);
    }

    /**
     * Same as parse(), but null and empty strings (e.g. unfilled form fields) return null.
     *
     * @throws UnexpectedValueException If the value does not match the format or is not a real calendar date.
     */
    public static function parseOrNull(?string $value, string $format = self::RO_DATE, ?DateTimeZone $timezone = null): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::create('parseOrNull', $value, $format, $timezone);
    }

    /**
     * Parses a date from a database DATE / DATETIME column: 'Y-m-d', 'Y-m-d H:i:s', or 'Y-m-d H:i:s.u'.
     * MySQL zero date ('0000-00-00') is rejected.
     *
     * @throws InvalidArgumentException If the value is empty.
     * @throws UnexpectedValueException If the value is not a valid date in database format.
     */
    public static function fromDb(string $value, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $format = match (strlen($value)) {
            10 => self::DB_FORMATS[0],
            19 => self::DB_FORMATS[1],
            default => self::DB_FORMATS[2],
        };

        return self::create('fromDb', $value, $format, $timezone);
    }

    /**
     * Formats a date for a database DATE column ('Y-m-d') or, with $withTime, DATETIME ('Y-m-d H:i:s').
     */
    public static function toDb(DateTimeInterface $date, bool $withTime = false): string
    {
        return $date->format($withTime ? self::DB_DATETIME : self::DB_DATE);
    }

    /**
     * Converts '15.03.2026' to '2026-03-15'.
     *
     * @throws InvalidArgumentException If the value is empty.
     * @throws UnexpectedValueException If the value is not a valid 'd.m.Y' date.
     */
    public static function roToDb(string $value): string
    {
        return self::create('roToDb', $value, self::RO_DATE, null)->format(self::DB_DATE);
    }

    /**
     * Converts '2026-03-15' or '2026-03-15 10:30:00' to '15.03.2026' (time is not preserved).
     *
     * @throws InvalidArgumentException If the value is empty.
     * @throws UnexpectedValueException If the value is not a valid date in database format.
     */
    public static function dbToRo(string $value): string
    {
        return self::fromDb($value)->format(self::RO_DATE);
    }

    /**
     * Same as DateTimeInterface::format(), with month and day names in Romanian:
     * F (martie), M (mar), l (luni), D (lun). Other characters retain their native format meaning.
     */
    public static function format(DateTimeInterface $date, string $format = self::RO_DATE): string
    {
        $result = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            if ($char === '\\') {
                $result .= $char.($format[++$i] ?? '');

                continue;
            }

            $name = match ($char) {
                'F' => self::MONTHS[(int) $date->format('n')],
                'M' => self::MONTHS_SHORT[(int) $date->format('n')],
                'l' => self::DAYS[(int) $date->format('N')],
                'D' => self::DAYS_SHORT[(int) $date->format('N')],
                default => null,
            };

            // Fiecare octet al numelui este escapat, ca sa nu fie interpretat ca format.
            $result .= $name === null ? $char : '\\'.implode('\\', str_split($name));
        }

        return $date->format($result);
    }

    /**
     * Returns today's date at 00:00:00.
     */
    public static function today(?DateTimeZone $timezone = null): DateTimeImmutable
    {
        return new DateTimeImmutable('today', $timezone);
    }

    /**
     * Returns current date and time.
     */
    public static function now(?DateTimeZone $timezone = null): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $timezone);
    }

    /**
     * Calendar days between $from and $to (negative if $to is before $from). Time is ignored:
     * from Monday 23:00 to Tuesday 01:00 counts as 1 day.
     */
    public static function diffInDays(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return intdiv(self::dayStart($to)->getTimestamp() - self::dayStart($from)->getTimestamp(), 86400);
    }

    /**
     * Full months between $from and $to (negative if $to is before $from). Time is ignored.
     */
    public static function diffInMonths(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $interval = self::dayStart($from)->diff(self::dayStart($to));
        $months = $interval->y * 12 + $interval->m;

        return $interval->invert === 1 ? -$months : $months;
    }

    /**
     * Full years between $from and $to (negative if $to is before $from). Time is ignored.
     */
    public static function diffInYears(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $interval = self::dayStart($from)->diff(self::dayStart($to));

        return $interval->invert === 1 ? -$interval->y : $interval->y;
    }

    /**
     * Calculates age in completed years as of $on (defaults to today in the birth date's timezone).
     *
     * @throws InvalidArgumentException If the birth date is after the reference date.
     */
    public static function age(DateTimeInterface $birthDate, ?DateTimeInterface $on = null): int
    {
        $on ??= self::today($birthDate->getTimezone());

        if (self::diffInDays($birthDate, $on) < 0) {
            throw new InvalidArgumentException('Date::age(): birth date is after the reference date.');
        }

        return self::diffInYears($birthDate, $on);
    }

    /**
     * Checks whether the date falls on a Saturday or Sunday.
     */
    public static function isWeekend(DateTimeInterface $date): bool
    {
        return (int) $date->format('N') >= 6;
    }

    /**
     * Checks whether the date is a statutory public holiday in Romania.
     *
     * @throws InvalidArgumentException If the year is before 2009 or after 2099.
     */
    public static function isHoliday(DateTimeInterface $date): bool
    {
        return array_key_exists($date->format(self::DB_DATE), self::holidays((int) $date->format('Y')));
    }

    /**
     * Checks whether the date is a working day (neither weekend nor public holiday).
     *
     * @throws InvalidArgumentException If the year is before 2009 or after 2099.
     */
    public static function isWorkingDay(DateTimeInterface $date): bool
    {
        return ! self::isWeekend($date) && ! self::isHoliday($date);
    }

    /**
     * Returns statutory public holidays in Romania (Labor Code, art. 139), sorted: ['2026-01-01' => 'Anul Nou', ...].
     * Supported years: 2009 - 2099 (the legal holiday schedule prior to 2009 was different).
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException If the year is outside the supported range.
     */
    public static function holidays(int $year): array
    {
        if ($year < 2009 || $year > 2099) {
            throw new InvalidArgumentException("Date::holidays(): year must be between 2009 and 2099, got {$year}.");
        }

        if (isset(self::$holidays[$year])) {
            return self::$holidays[$year];
        }

        $easter = self::orthodoxEaster($year);
        $fixed = static fn (int $month, int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day);
        $fromEaster = static fn (int $days): string => $easter->modify("{$days} days")->format(self::DB_DATE);

        // [anul de la care se aplica, ziua, denumirea]
        $rules = [
            [2009, $fixed(1, 1), 'Anul Nou'],
            [2009, $fixed(1, 2), 'Anul Nou'],
            [2024, $fixed(1, 6), 'Boboteaza'],
            [2024, $fixed(1, 7), 'Sfantul Ioan Botezatorul'],
            [2017, $fixed(1, 24), 'Ziua Unirii Principatelor Romane'],
            [2018, $fromEaster(-2), 'Vinerea Mare'],
            [2009, $fromEaster(0), 'Pastele'],
            [2009, $fromEaster(1), 'Pastele'],
            [2009, $fixed(5, 1), 'Ziua Muncii'],
            [2017, $fixed(6, 1), 'Ziua Copilului'],
            [2009, $fromEaster(49), 'Rusaliile'],
            [2009, $fromEaster(50), 'Rusaliile'],
            [2009, $fixed(8, 15), 'Adormirea Maicii Domnului'],
            [2012, $fixed(11, 30), 'Sfantul Andrei'],
            [2009, $fixed(12, 1), 'Ziua Nationala'],
            [2009, $fixed(12, 25), 'Craciunul'],
            [2009, $fixed(12, 26), 'Craciunul'],
        ];

        $list = [];
        foreach ($rules as [$since, $day, $name]) {
            if ($year >= $since) {
                // Doua sarbatori in aceeasi zi (ex: Pastele si Ziua Muncii in 2016).
                $list[$day] = isset($list[$day]) ? $list[$day].', '.$name : $name;
            }
        }

        ksort($list);

        return self::$holidays[$year] = $list;
    }

    /**
     * Computes Orthodox Easter Sunday (Meeus algorithm for Julian calendar converted to Gregorian).
     *
     * @throws InvalidArgumentException If the year is before 1583.
     */
    public static function orthodoxEaster(int $year, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        if ($year < 1583 || $year > 9999) {
            throw new InvalidArgumentException("Date::orthodoxEaster(): year must be between 1583 and 9999, got {$year}.");
        }

        $d = (19 * ($year % 19) + 15) % 30;
        $e = (2 * ($year % 4) + 4 * ($year % 7) - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = ($d + $e + 114) % 31 + 1;

        // Diferenta dintre calendarul iulian si cel gregorian (13 zile intre 1900 si 2099).
        $offset = intdiv($year, 100) - intdiv($year, 400) - 2;

        return (new DateTimeImmutable('now', $timezone))
            ->setDate($year, $month, $day)
            ->setTime(0, 0)
            ->modify("+{$offset} days");
    }

    /**
     * Adds $days working days to the date (negative to subtract). Time is preserved.
     * Passing 0 leaves the date unchanged even if it is not a working day.
     *
     * @throws InvalidArgumentException If the result falls outside the supported year range.
     */
    public static function addWorkingDays(DateTimeInterface $date, int $days): DateTimeImmutable
    {
        $current = self::immutable($date);
        $step = $days < 0 ? '-1 day' : '+1 day';

        for ($remaining = abs($days); $remaining > 0;) {
            $current = $current->modify($step);
            if (self::isWorkingDay($current)) {
                $remaining--;
            }
        }

        return $current;
    }

    /**
     * Returns the number of working days after $from up to and including $to (negative if $to is before $from).
     * For any n: workingDaysBetween($d, addWorkingDays($d, n)) === n.
     *
     * @throws InvalidArgumentException If the range falls outside supported years.
     */
    public static function workingDaysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $days = self::diffInDays($from, $to);
        $step = $days < 0 ? '-1 day' : '+1 day';
        $current = self::dayStart($from);
        $count = 0;

        // Inainte se numara ($from, $to]; inapoi [$to, $from), simetric cu addWorkingDays().
        for ($i = abs($days); $i > 0; $i--) {
            $current = $current->modify($step);
            if (self::isWorkingDay($current)) {
                $count++;
            }
        }

        return $days < 0 ? -$count : $count;
    }

    /**
     * Returns the first day of the month at 00:00:00.
     */
    public static function startOfMonth(DateTimeInterface $date): DateTimeImmutable
    {
        return self::immutable($date)
            ->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)
            ->setTime(0, 0);
    }

    /**
     * Returns the last day of the month at 23:59:59.
     */
    public static function endOfMonth(DateTimeInterface $date): DateTimeImmutable
    {
        return self::immutable($date)
            ->setDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('t'))
            ->setTime(23, 59, 59);
    }

    /**
     * Normalizes the calendar date to midnight UTC: day differences are unaffected by daylight saving transitions.
     */
    private static function dayStart(DateTimeInterface $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format(self::DB_DATE), new DateTimeZone('UTC'));
    }

    /**
     * @throws InvalidArgumentException If the value is empty.
     * @throws UnexpectedValueException If the value does not match the format or is not a real date.
     */
    /**
     * Returns an immutable copy (DateTimeInterface is only implemented by DateTime and DateTimeImmutable).
     */
    private static function immutable(DateTimeInterface $date): DateTimeImmutable
    {
        return $date instanceof DateTime ? DateTimeImmutable::createFromMutable($date) : $date;
    }

    private static function create(string $method, string $value, string $format, ?DateTimeZone $timezone): DateTimeImmutable
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Date::{$method}(): value is empty.");
        }

        // '!' pune la zero tot ce nu apare in format (altfel ora lipsa ar fi ora curenta).
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && $errors['error_count'] > 0)) {
            throw new UnexpectedValueException("Date::{$method}(): value does not match the format '{$format}'.");
        }

        // Avertismentele inseamna o data inexistenta corectata automat (31.02 -> 03.03, 25:00).
        if ($errors !== false && $errors['warning_count'] > 0) {
            throw new UnexpectedValueException("Date::{$method}(): value is not a valid calendar date or time (format '{$format}').");
        }

        if (str_contains($format, 'Y') && (int) $date->format('Y') < 1000) {
            throw new UnexpectedValueException("Date::{$method}(): the year must have 4 digits (format '{$format}').");
        }

        return $date;
    }
}
