<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * App Model
 *
 * Manages client applications registered to emit logs to LogMonitor.
 */
class App
{
    protected MySQLWrapper $db;

    public function __construct(?MySQLWrapper $db = null)
    {
        $this->db = $db ?? MySQLWrapper::getInstance();
    }

    /**
     * Finds an application using its unique API key.
     *
     * @return array<string, mixed>
     */
    public function findByApiKey(string $apiKey): array
    {
        $trimmedKey = trim($apiKey);
        if ($trimmedKey === '') {
            throw new InvalidArgumentException(__('API key cannot be empty'));
        }

        try {
            $results = $this->db->read('apps', ['api_key' => $trimmedKey]);
            $first = $results[0] ?? null;
            if (! is_array($first)) {
                throw new RuntimeException(__('Application not found for the provided API key'));
            }

            return $first;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'SELECT FROM apps WHERE api_key = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during application lookup'), 0, $e);
        }
    }

    /**
     * Finds an application by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $results = $this->db->read('apps', ['id' => $id]);
        $first = $results[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Retrieves all registered applications.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        try {
            return $this->db->read('apps', [], ['*']);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'SELECT ALL FROM apps',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return [];
        }
    }

    public function create(string $name, string $apiKey): int
    {
        $trimmedName = trim($name);
        $trimmedKey = trim($apiKey);

        if ($trimmedName === '' || $trimmedKey === '') {
            throw new InvalidArgumentException(__('Application name and API key cannot be empty'));
        }

        $params = ['name' => $trimmedName, 'api_key' => $trimmedKey];

        try {
            $result = $this->db->create('apps', $params);
            if ($result === 0 || $result === '') {
                throw new RuntimeException(__('Failed to create application record'));
            }

            return (int) $result;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'INSERT INTO apps (name, api_key)',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during application creation'), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(__('Invalid application ID'));
        }
        if ($data === []) {
            throw new InvalidArgumentException(__('Update data cannot be empty'));
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $this->db->update('apps', $data, ['id' => $id]);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'UPDATE apps SET ... WHERE id = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during application update'), 0, $e);
        }
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(__('Invalid application ID for deletion'));
        }

        try {
            $result = $this->db->delete('apps', ['id' => $id]);
            if ($result === 0) {
                throw new RuntimeException(__('Application deletion failed or record does not exist'));
            }
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'DELETE FROM apps WHERE id = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during application deletion'), 0, $e);
        }
    }

    /**
     * Returns top applications by total log count.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getTopApps(int $limit = 5): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            'SELECT a.id, a.name as app_name, COUNT(l.id) as log_count, MAX(l.created_at) as last_logged_at
             FROM apps a
             LEFT JOIN logs l ON l.app_id = a.id
             GROUP BY a.id, a.name
             ORDER BY log_count DESC
             LIMIT '.max(1, $limit)
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var array<int, array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * Dashboard summary card for registered apps.
     *
     * @return array<string, mixed>
     */
    public static function dashboardSummaryApps(): array
    {
        $db = MySQLWrapper::getInstance();
        $row = $db->query('SELECT COUNT(*) as total FROM apps')->fetch();
        $total = is_array($row) && is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0;

        return [
            'title' => __('Active Apps'),
            'value' => $total,
            'icon' => 'fa-solid fa-microchip',
            'color' => 'info',
            'url' => '/admin/apps',
        ];
    }
}
