<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\MySQLWrapper;
use App\Utilities\TimeSeriesHelper;

/**
 * User Model
 *
 * Manages queries and changes in the users table with RBAC support.
 */
class User
{
    /**
     * Finds a user by email address along with their role name.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            'SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? LIMIT 1',
            [$email]
        );
        $user = $stmt->fetch();

        if (is_array($user)) {
            /** @var array<string, mixed> $user */
            return $user;
        }

        return null;
    }

    /**
     * Finds a user by ID along with their role name.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            'SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? LIMIT 1',
            [$id]
        );
        $user = $stmt->fetch();

        if (is_array($user)) {
            /** @var array<string, mixed> $user */
            return $user;
        }

        return null;
    }

    /**
     * Updates profile info for a specified user.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateProfile(int $id, array $data): bool
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->update('users', [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return $rows >= 0;
    }

    public static function updateEmailVerifiedAt(int $id, string $dateTime): bool
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->update('users', [
            'email_verified_at' => ($dateTime !== '') ? $dateTime : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return $rows >= 0;
    }

    /**
     * Returns all users along with role names.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAllWithRoles(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            'SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC'
        );
        $rows = $stmt->fetchAll();
        /** @var array<int, array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * Creates a new user.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data): string|int
    {
        $db = MySQLWrapper::getInstance();
        $data['created_at'] = date('Y-m-d H:i:s');

        return $db->create('users', $data);
    }

    /**
     * Updates user information.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateUser(int $id, array $data): bool
    {
        $db = MySQLWrapper::getInstance();
        $data['updated_at'] = date('Y-m-d H:i:s');
        $rows = $db->update('users', $data, ['id' => $id]);

        return $rows >= 0;
    }

    /**
     * Returns summary dashboard card statistics for non-admin users.
     *
     * @return array<string, mixed>
     */
    public static function dashboardSummaryUsers(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query("SELECT COUNT(u.id) as total FROM users u JOIN roles r ON u.role_id = r.id WHERE UPPER(r.name) NOT IN ('ADMIN', 'ADMINISTRATOR')");
        $row = $stmt->fetch();
        $totalRaw = is_array($row) ? ($row['total'] ?? 0) : 0;
        $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;

        return [
            'title' => __('Registered Users'),
            'value' => $total,
            'icon' => 'fa-solid fa-users',
            'color' => 'primary',
            'url' => '/admin/settings/users',
        ];
    }

    /**
     * Returns doughnut chart distribution of non-admin users by role.
     *
     * @return array<string, mixed>
     */
    public static function dashboardChartUsers(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query("SELECT r.name as role_name, COUNT(u.id) as count FROM roles r LEFT JOIN users u ON r.id = u.role_id WHERE UPPER(r.name) NOT IN ('ADMIN', 'ADMINISTRATOR') GROUP BY r.id, r.name");
        $rows = $stmt->fetchAll();

        $labels = [];
        $data = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $roleName = $row['role_name'] ?? 'USER';
            $labels[] = __(is_string($roleName) ? $roleName : 'USER');
            $countVal = $row['count'] ?? 0;
            $data[] = is_numeric($countVal) ? (int) $countVal : 0;
        }

        return [
            'id' => 'chart_users_by_role',
            'title' => __('Users by Role'),
            'type' => 'doughnut',
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('Users'),
                    'data' => $data,
                ],
            ],
        ];
    }

    /**
     * Returns user registration trend chart data.
     *
     * @return array<string, mixed>
     */
    public static function dashboardChartUsersTrend(): array
    {
        $periods = TimeSeriesHelper::buildPeriods('users', 'created_at');
        $activePeriod = 'months';
        $periodData = $periods[$activePeriod] ?? $periods['days'];

        return [
            'id' => 'chart_users_registration_trend',
            'title' => __('Users Registration Trend'),
            'type' => 'line',
            'labels' => $periodData['labels'],
            'datasets' => [
                [
                    'label' => __('New Users'),
                    'data' => $periodData['data'],
                    'borderColor' => 'rgb(111, 66, 193)',
                    'backgroundColor' => 'rgba(111, 66, 193, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 6,
                ],
            ],
            'periods' => $periods,
            'activePeriod' => $activePeriod,
        ];
    }
}
