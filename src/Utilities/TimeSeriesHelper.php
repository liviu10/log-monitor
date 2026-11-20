<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\MySQLWrapper;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Utility for aggregating time-series data across standard reporting periods
 * (days, months, quarters, semesters, years, and year-to-date).
 */
final class TimeSeriesHelper
{
    /**
     * Builds time-series datasets for all 6 standard periods.
     *
     * @param  string  $table  Database table name.
     * @param  string  $dateColumn  Date/datetime column name to aggregate by.
     * @param  string  $where  Additional WHERE clause conditions.
     * @param  array<int, mixed>  $params  Query parameter bindings for the WHERE clause.
     * @param  DateTimeInterface|null  $referenceDate  Optional reference date (defaults to today).
     * @param  MySQLWrapper|null  $db  Optional database wrapper instance.
     * @return array<string, array{label: string, labels: array<int, string>, data: array<int, int>}>
     */
    public static function buildPeriods(
        string $table,
        string $dateColumn = 'created_at',
        string $where = '1=1',
        array $params = [],
        ?DateTimeInterface $referenceDate = null,
        ?MySQLWrapper $db = null
    ): array {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $dateColumn) !== 1) {
            throw new InvalidArgumentException("Invalid date column name: {$dateColumn}");
        }

        $db ??= MySQLWrapper::getInstance();
        $ref = $referenceDate !== null
            ? new DateTime($referenceDate->format('Y-m-d H:i:s'))
            : new DateTime('today');

        // 1. Daily Query (Last 30 days)
        $dailyStart = (clone $ref)->modify('-29 days')->format('Y-m-d 00:00:00');
        $dailySql = "SELECT DATE({$dateColumn}) AS period_key, COUNT(*) AS count_val FROM {$table} WHERE {$where} AND {$dateColumn} >= ? GROUP BY period_key ORDER BY period_key ASC";
        $dailyParams = array_merge($params, [$dailyStart]);
        $dailyRows = $db->query($dailySql, $dailyParams)->fetchAll();

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            if (is_array($row)) {
                $key = is_scalar($row['period_key'] ?? null) ? (string) $row['period_key'] : '';
                $val = is_numeric($row['count_val'] ?? null) ? (int) $row['count_val'] : 0;
                if ($key !== '') {
                    $dailyMap[$key] = $val;
                }
            }
        }

        $daysLabels = [];
        $daysData = [];
        for ($i = 29; $i >= 0; $i--) {
            $dt = (clone $ref)->modify("-{$i} days");
            $key = $dt->format('Y-m-d');
            $daysLabels[] = $dt->format('d M');
            $daysData[] = $dailyMap[$key] ?? 0;
        }

        // 2. Monthly Query (Last 24 months to cover months, quarters, semesters, and YTD)
        $firstDayThisMonth = new DateTime($ref->format('Y-m-01 00:00:00'));
        $monthlyStart = (clone $firstDayThisMonth)->modify('-23 months')->format('Y-m-01 00:00:00');
        $monthlySql = "SELECT DATE_FORMAT({$dateColumn}, '%Y-%m') AS period_key, COUNT(*) AS count_val FROM {$table} WHERE {$where} AND {$dateColumn} >= ? GROUP BY period_key ORDER BY period_key ASC";
        $monthlyParams = array_merge($params, [$monthlyStart]);
        $monthlyRows = $db->query($monthlySql, $monthlyParams)->fetchAll();

        $monthlyMap = [];
        foreach ($monthlyRows as $row) {
            if (is_array($row)) {
                $key = is_scalar($row['period_key'] ?? null) ? (string) $row['period_key'] : '';
                $val = is_numeric($row['count_val'] ?? null) ? (int) $row['count_val'] : 0;
                if ($key !== '') {
                    $monthlyMap[$key] = $val;
                }
            }
        }

        // Months (Last 12 months)
        $monthsLabels = [];
        $monthsData = [];
        for ($i = 11; $i >= 0; $i--) {
            $dt = (clone $firstDayThisMonth)->modify("-{$i} months");
            $key = $dt->format('Y-m');
            $monthsLabels[] = $dt->format('M Y');
            $monthsData[] = $monthlyMap[$key] ?? 0;
        }

        // Quarters (Last 8 quarters)
        $quartersLabels = [];
        $quartersData = [];
        $currentYear = (int) $ref->format('Y');
        $currentMonth = (int) $ref->format('n');
        $currentQuarter = (int) ceil($currentMonth / 3);

        for ($i = 7; $i >= 0; $i--) {
            $quarterIndex = ($currentYear * 4) + ($currentQuarter - 1) - $i;
            $targetYear = intdiv($quarterIndex, 4);
            $targetQuarter = ($quarterIndex % 4) + 1;
            $startMonth = ($targetQuarter - 1) * 3 + 1;

            $sum = 0;
            for ($m = $startMonth; $m <= $startMonth + 2; $m++) {
                $key = sprintf('%04d-%02d', $targetYear, $m);
                $sum += $monthlyMap[$key] ?? 0;
            }

            $quartersLabels[] = "Q{$targetQuarter} {$targetYear}";
            $quartersData[] = $sum;
        }

        // Semesters (Last 4 semesters)
        $semestersLabels = [];
        $semestersData = [];
        $currentSemester = $currentMonth <= 6 ? 1 : 2;

        for ($i = 3; $i >= 0; $i--) {
            $semesterIndex = ($currentYear * 2) + ($currentSemester - 1) - $i;
            $targetYear = intdiv($semesterIndex, 2);
            $targetSemester = ($semesterIndex % 2) + 1;
            $startMonth = $targetSemester === 1 ? 1 : 7;
            $endMonth = $targetSemester === 1 ? 6 : 12;

            $sum = 0;
            for ($m = $startMonth; $m <= $endMonth; $m++) {
                $key = sprintf('%04d-%02d', $targetYear, $m);
                $sum += $monthlyMap[$key] ?? 0;
            }

            $semestersLabels[] = "S{$targetSemester} {$targetYear}";
            $semestersData[] = $sum;
        }

        // 3. Yearly Query (Last 5 years)
        $yearlyStart = sprintf('%04d-01-01 00:00:00', $currentYear - 4);
        $yearlySql = "SELECT YEAR({$dateColumn}) AS period_key, COUNT(*) AS count_val FROM {$table} WHERE {$where} AND {$dateColumn} >= ? GROUP BY period_key ORDER BY period_key ASC";
        $yearlyParams = array_merge($params, [$yearlyStart]);
        $yearlyRows = $db->query($yearlySql, $yearlyParams)->fetchAll();

        /** @var array<int, int> $yearlyMap */
        $yearlyMap = [];
        foreach ($yearlyRows as $row) {
            if (is_array($row)) {
                $key = is_numeric($row['period_key'] ?? null) ? (int) $row['period_key'] : 0;
                $val = is_numeric($row['count_val'] ?? null) ? (int) $row['count_val'] : 0;
                if ($key > 0) {
                    $yearlyMap[$key] = $val;
                }
            }
        }

        $yearsLabels = [];
        $yearsData = [];
        for ($i = 4; $i >= 0; $i--) {
            $yr = $currentYear - $i;
            $yearsLabels[] = (string) $yr;
            $yearsData[] = $yearlyMap[$yr] ?? 0;
        }

        // 4. YTD (Year-To-Date: from Jan 1 of current year to current month)
        $ytdLabels = [];
        $ytdData = [];
        for ($m = 1; $m <= $currentMonth; $m++) {
            $key = sprintf('%04d-%02d', $currentYear, $m);
            $monthDt = DateTime::createFromFormat('Y-m-d', "{$key}-01");
            $ytdLabels[] = $monthDt !== false ? $monthDt->format('M Y') : $key;
            $ytdData[] = $monthlyMap[$key] ?? 0;
        }

        return [
            'days' => [
                'label' => __('Days'),
                'labels' => $daysLabels,
                'data' => $daysData,
            ],
            'months' => [
                'label' => __('Months'),
                'labels' => $monthsLabels,
                'data' => $monthsData,
            ],
            'quarters' => [
                'label' => __('Quarters'),
                'labels' => $quartersLabels,
                'data' => $quartersData,
            ],
            'semesters' => [
                'label' => __('Semesters'),
                'labels' => $semestersLabels,
                'data' => $semestersData,
            ],
            'years' => [
                'label' => __('Years'),
                'labels' => $yearsLabels,
                'data' => $yearsData,
            ],
            'ytd' => [
                'label' => __('YTD'),
                'labels' => $ytdLabels,
                'data' => $ytdData,
            ],
        ];
    }
}
