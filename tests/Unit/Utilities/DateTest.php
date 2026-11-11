<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Date;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class DateTest extends TestCase
{
    // parse

    public function test_parse_sets_unspecified_fields_to_zero(): void
    {
        $date = Date::parse('15.03.2026');

        $this->assertSame('2026-03-15 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15 10:30:00', Date::parse('15.03.2026 10:30', Date::RO_DATETIME)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01', Date::parse('1.3.2026')->format('Y-m-d'), 'd si m accepta o cifra');
    }

    public function test_parse_uses_given_timezone(): void
    {
        $date = Date::parse('15.03.2026', Date::RO_DATE, new DateTimeZone('Europe/Bucharest'));

        $this->assertSame('Europe/Bucharest', $date->getTimezone()->getName());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidDateProvider(): iterable
    {
        yield '31 februarie' => ['31.02.2026', Date::RO_DATE, 'is not a valid calendar date or time'];
        yield '29 februarie, an nebisect' => ['29.02.2026', Date::RO_DATE, 'is not a valid calendar date or time'];
        yield 'luna 13' => ['15.13.2026', Date::RO_DATE, 'is not a valid calendar date or time'];
        yield 'ora 25' => ['15.03.2026 25:00', Date::RO_DATETIME, 'is not a valid calendar date or time'];
        yield 'an din doua cifre' => ['15.03.26', Date::RO_DATE, 'the year must have 4 digits'];
        yield 'alt separator' => ['15/03/2026', Date::RO_DATE, "does not match the format 'd.m.Y'"];
        yield 'text in plus' => ['15.03.2026 abc', Date::RO_DATE, "does not match the format 'd.m.Y'"];
        yield 'format DB' => ['2026-03-15', Date::RO_DATE, "does not match the format 'd.m.Y'"];
    }

    #[DataProvider('invalidDateProvider')]
    public function test_parse_rejects_invalid_dates(string $value, string $format, string $message): void
    {
        try {
            Date::parse($value, $format);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringNotContainsString($value, $e->getMessage(), 'valoarea nu apare in mesaj');
        }
    }

    public function test_parse_rejects_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date::parse(): value is empty.');

        Date::parse('  ');
    }

    public function test_parse_or_null(): void
    {
        $this->assertNull(Date::parseOrNull(null));
        $this->assertNull(Date::parseOrNull(''));
        $this->assertNull(Date::parseOrNull('   '));
        $this->assertSame('2026-03-15', Date::parseOrNull('15.03.2026')?->format('Y-m-d'));

        $this->expectException(UnexpectedValueException::class);
        Date::parseOrNull('31.02.2026');
    }

    // baza de date

    public function test_from_db_accepts_date_datetime_and_microseconds(): void
    {
        $this->assertSame('2026-03-15 00:00:00', Date::fromDb('2026-03-15')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15 10:20:30', Date::fromDb('2026-03-15 10:20:30')->format('Y-m-d H:i:s'));
        $this->assertSame('123456', Date::fromDb('2026-03-15 10:20:30.123456')->format('u'));
    }

    public function test_from_db_rejects_zero_date_and_ro_format(): void
    {
        foreach (['0000-00-00', '0000-00-00 00:00:00', '15.03.2026', '2026-02-30'] as $value) {
            try {
                Date::fromDb($value);
                $this->fail("Expected UnexpectedValueException for {$value}");
            } catch (UnexpectedValueException $e) {
                $this->assertStringStartsWith('Date::fromDb():', $e->getMessage());
            }
        }
    }

    public function test_conversions_ro_db(): void
    {
        $date = new DateTimeImmutable('2026-03-15 10:20:30');

        $this->assertSame('2026-03-15', Date::toDb($date));
        $this->assertSame('2026-03-15 10:20:30', Date::toDb($date, true));
        $this->assertSame('2026-03-15', Date::roToDb('15.03.2026'));
        $this->assertSame('15.03.2026', Date::dbToRo('2026-03-15'));
        $this->assertSame('15.03.2026', Date::dbToRo('2026-03-15 10:20:30'));
    }

    public function test_ro_to_db_rejects_invalid_date(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Date::roToDb(): value is not a valid calendar date');

        Date::roToDb('31.04.2026');
    }

    // format

    public function test_format_with_romanian_names(): void
    {
        $sunday = new DateTimeImmutable('2026-03-15 08:05');

        $this->assertSame('15.03.2026', Date::format($sunday));
        $this->assertSame('15 martie 2026', Date::format($sunday, 'j F Y'));
        $this->assertSame("duminic\u{0103}, 15 mar 2026", Date::format($sunday, 'l, j M Y'));
        $this->assertSame('dum 08:05', Date::format($sunday, 'D H:i'));
        $this->assertSame("mar\u{021B}i", Date::format(new DateTimeImmutable('2026-03-17'), 'l'));
        $this->assertSame("s\u{00E2}m", Date::format(new DateTimeImmutable('2026-03-14'), 'D'));
        $this->assertSame('decembrie', Date::format(new DateTimeImmutable('2026-12-01'), 'F'));
    }

    public function test_format_keeps_escaped_characters(): void
    {
        $date = new DateTimeImmutable('2026-03-15');

        $this->assertSame('anul 2026, luna F', Date::format($date, '\a\n\u\l Y, \l\u\n\a \F'));
    }

    // diferente

    public function test_diff_in_days_ignores_time_and_dst(): void
    {
        $bucharest = new DateTimeZone('Europe/Bucharest');

        $this->assertSame(1, Date::diffInDays(new DateTimeImmutable('2026-03-16 23:00'), new DateTimeImmutable('2026-03-17 01:00')));
        $this->assertSame(0, Date::diffInDays(new DateTimeImmutable('2026-03-16 01:00'), new DateTimeImmutable('2026-03-16 23:00')));
        $this->assertSame(-5, Date::diffInDays(new DateTimeImmutable('2026-03-20'), new DateTimeImmutable('2026-03-15')));
        $this->assertSame(2, Date::diffInDays(
            new DateTimeImmutable('2026-03-28', $bucharest),
            new DateTimeImmutable('2026-03-30', $bucharest),
        ), 'peste trecerea la ora de vara (29.03.2026)');
        $this->assertSame(365, Date::diffInDays(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01')));
    }

    public function test_diff_in_months_and_years(): void
    {
        $this->assertSame(2, Date::diffInMonths(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-03-15')));
        $this->assertSame(1, Date::diffInMonths(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-03-14')));
        $this->assertSame(-14, Date::diffInMonths(new DateTimeImmutable('2026-03-15'), new DateTimeImmutable('2025-01-15')));
        $this->assertSame(2, Date::diffInYears(new DateTimeImmutable('2024-03-15'), new DateTimeImmutable('2026-03-15')));
        $this->assertSame(1, Date::diffInYears(new DateTimeImmutable('2024-03-15'), new DateTimeImmutable('2026-03-14 23:59')));
        $this->assertSame(-1, Date::diffInYears(new DateTimeImmutable('2026-03-15'), new DateTimeImmutable('2025-03-15')));
    }

    public function test_age(): void
    {
        $birth = new DateTimeImmutable('2000-03-15');

        $this->assertSame(25, Date::age($birth, new DateTimeImmutable('2026-03-14')));
        $this->assertSame(26, Date::age($birth, new DateTimeImmutable('2026-03-15')));
        $this->assertSame(0, Date::age($birth, $birth));
        $this->assertGreaterThanOrEqual(26, Date::age($birth));
    }

    public function test_age_rejects_future_birth_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date::age(): birth date is after the reference date.');

        Date::age(new DateTimeImmutable('2026-03-16'), new DateTimeImmutable('2026-03-15'));
    }

    // sarbatori si zile lucratoare

    /**
     * @return iterable<int, array{int, string}>
     */
    public static function easterProvider(): iterable
    {
        yield [2009, '2009-04-19'];
        yield [2016, '2016-05-01'];
        yield [2018, '2018-04-08'];
        yield [2021, '2021-05-02'];
        yield [2024, '2024-05-05'];
        yield [2025, '2025-04-20'];
        yield [2026, '2026-04-12'];
        yield [2027, '2027-05-02'];
        yield [2030, '2030-04-28'];
    }

    #[DataProvider('easterProvider')]
    public function test_orthodox_easter(int $year, string $expected): void
    {
        $this->assertSame($expected.' 00:00:00', Date::orthodoxEaster($year)->format('Y-m-d H:i:s'));
    }

    public function test_holidays_2026(): void
    {
        $this->assertSame([
            '2026-01-01' => 'Anul Nou',
            '2026-01-02' => 'Anul Nou',
            '2026-01-06' => 'Boboteaza',
            '2026-01-07' => 'Sfantul Ioan Botezatorul',
            '2026-01-24' => 'Ziua Unirii Principatelor Romane',
            '2026-04-10' => 'Vinerea Mare',
            '2026-04-12' => 'Pastele',
            '2026-04-13' => 'Pastele',
            '2026-05-01' => 'Ziua Muncii',
            '2026-05-31' => 'Rusaliile',
            '2026-06-01' => 'Ziua Copilului, Rusaliile',
            '2026-08-15' => 'Adormirea Maicii Domnului',
            '2026-11-30' => 'Sfantul Andrei',
            '2026-12-01' => 'Ziua Nationala',
            '2026-12-25' => 'Craciunul',
            '2026-12-26' => 'Craciunul',
        ], Date::holidays(2026));
    }

    public function test_holidays_follow_the_year_they_were_introduced(): void
    {
        $this->assertArrayNotHasKey('2011-11-30', Date::holidays(2011));
        $this->assertArrayHasKey('2012-11-30', Date::holidays(2012));
        $this->assertArrayNotHasKey('2016-01-24', Date::holidays(2016));
        $this->assertArrayHasKey('2017-01-24', Date::holidays(2017));
        $this->assertArrayHasKey('2017-06-01', Date::holidays(2017));
        $this->assertArrayNotHasKey('2017-04-14', Date::holidays(2017), 'Vinerea Mare abia din 2018');
        $this->assertSame('Vinerea Mare', Date::holidays(2018)['2018-04-06']);
        $this->assertArrayNotHasKey('2023-01-06', Date::holidays(2023));
        $this->assertArrayHasKey('2024-01-07', Date::holidays(2024));
    }

    public function test_holidays_on_the_same_day_are_merged(): void
    {
        $this->assertSame('Pastele, Ziua Muncii', Date::holidays(2016)['2016-05-01']);
    }

    public function test_holidays_year_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date::holidays(): year must be between 2009 and 2099, got 2008.');

        Date::holidays(2008);
    }

    public function test_weekend_holiday_and_working_day(): void
    {
        $this->assertTrue(Date::isWeekend(new DateTimeImmutable('2026-03-14')));
        $this->assertTrue(Date::isWeekend(new DateTimeImmutable('2026-03-15')));
        $this->assertFalse(Date::isWeekend(new DateTimeImmutable('2026-03-16')));

        $this->assertTrue(Date::isHoliday(new DateTimeImmutable('2026-12-01 15:00')));
        $this->assertFalse(Date::isHoliday(new DateTimeImmutable('2026-12-02')));

        $this->assertTrue(Date::isWorkingDay(new DateTimeImmutable('2026-03-16')));
        $this->assertFalse(Date::isWorkingDay(new DateTimeImmutable('2026-04-10')), 'Vinerea Mare');
        $this->assertFalse(Date::isWorkingDay(new DateTimeImmutable('2026-03-15')), 'duminica');
    }

    public function test_add_working_days_skips_weekends_and_holidays(): void
    {
        $thursday = new DateTimeImmutable('2026-04-09 14:30');

        // Vineri 10.04 Vinerea Mare, weekend, luni 13.04 a doua zi de Paste.
        $this->assertSame('2026-04-14 14:30', Date::addWorkingDays($thursday, 1)->format('Y-m-d H:i'));
        $this->assertSame('2026-04-09 14:30', Date::addWorkingDays(new DateTimeImmutable('2026-04-14 14:30'), -1)->format('Y-m-d H:i'));
        $this->assertSame('2026-04-16', Date::addWorkingDays($thursday, 3)->format('Y-m-d'));
        $this->assertSame('2026-04-11', Date::addWorkingDays(new DateTimeImmutable('2026-04-11'), 0)->format('Y-m-d'), '0 = neschimbat');
    }

    public function test_working_days_between_is_inverse_of_add_working_days(): void
    {
        foreach (['2026-04-09', '2026-04-11', '2026-12-24', '2026-01-01'] as $start) {
            $date = new DateTimeImmutable($start);
            for ($n = -12; $n <= 12; $n++) {
                $this->assertSame($n, Date::workingDaysBetween($date, Date::addWorkingDays($date, $n)), "{$start} {$n}");
            }
        }

        $this->assertSame(0, Date::workingDaysBetween(new DateTimeImmutable('2026-04-10'), new DateTimeImmutable('2026-04-13')));
        $this->assertSame(22, Date::workingDaysBetween(new DateTimeImmutable('2026-02-28'), new DateTimeImmutable('2026-03-31')), 'martie 2026');
    }

    public function test_month_boundaries(): void
    {
        $date = new DateTimeImmutable('2028-02-17 13:45', new DateTimeZone('Europe/Bucharest'));

        $this->assertSame('2028-02-01 00:00:00', Date::startOfMonth($date)->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-29 23:59:59', Date::endOfMonth($date)->format('Y-m-d H:i:s'), 'an bisect');
        $this->assertSame('2026-04-30', Date::endOfMonth(new DateTimeImmutable('2026-04-30'))->format('Y-m-d'));
        $this->assertSame('Europe/Bucharest', Date::endOfMonth($date)->getTimezone()->getName());
    }

    public function test_today_and_now(): void
    {
        $this->assertSame('00:00:00', Date::today()->format('H:i:s'));
        $this->assertSame(date('Y-m-d'), Date::today()->format('Y-m-d'));
        $this->assertLessThanOrEqual(2, abs(Date::now()->getTimestamp() - time()));
        $this->assertSame('Europe/Bucharest', Date::today(new DateTimeZone('Europe/Bucharest'))->getTimezone()->getName());
    }
}
