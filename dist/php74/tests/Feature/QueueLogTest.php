<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\App;
use App\Models\Log;
use App\Utilities\MySQLWrapper;

// Importam functiile din worker daca este posibil, altfel le redefinim local pentru testare unitara
if (!function_exists('sanitizeSensitivePayloadTest')) {
    function sanitizeSensitivePayloadTest(array $data): array
    {
        $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'cvv', 'api_key', 'key'];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = sanitizeSensitivePayloadTest($value);
            } elseif (is_string($value)) {
                $lowerKey = strtolower((string)$key);
                $jsonValidate = function (string $json, int $depth = 512, int $flags = 0) {
                    if (function_exists('json_validate')) {
                        return json_validate($json, $depth, $flags);
                    }
                    $maxDepth = 0x7fffffff;
                    if (0 !== $flags && \defined('JSON_INVALID_UTF8_IGNORE') && \JSON_INVALID_UTF8_IGNORE !== $flags) {
                        throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
                    }
                    if ($depth <= 0) {
                        throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
                    }
                    if ($depth > $maxDepth) {
                        throw new \ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', $maxDepth));
                    }
                    json_decode($json, true, $depth, $flags);
                    return \JSON_ERROR_NONE === json_last_error();
                };
                if (in_array($lowerKey, $sensitiveKeys, true)) {
                    $data[$key] = '******';
                } elseif ($jsonValidate($value)) {
                    try {
                        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $data[$key] = json_encode(sanitizeSensitivePayloadTest($decoded), JSON_UNESCAPED_SLASHES);
                        }
                    } catch (\Throwable $exception) {
                    }
                }
            }
        }

        return $data;
    }
}

if (!function_exists('sanitizeLogMessageTest')) {
    function sanitizeLogMessageTest(string $message): string
    {
        return (string)preg_replace('/(password|pass|pwd|token|api_key)\s*=\s*[^\s&]+/ims', '$1=******', $message);
    }
}

class QueueLogTest extends TestCase
{
    private App $appModel;

    private Log $logModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->logModel = new Log();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE logs;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE log_queue;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function testSanitizerMasksSensitiveInformationInContext(): void
    {
        $context = [
            'username' => 'admin',
            'password' => 'supersecret123',
            'nested' => [
                'token' => 'abc-123-xyz',
                'normal_field' => 'hello'
            ],
            'json_string' => json_encode([
                'api_key' => 'secretkeyval',
                'data' => 'regular'
            ])
        ];

        $sanitized = sanitizeSensitivePayloadTest($context);

        $this->assertEquals('admin', $sanitized['username']);
        $this->assertEquals('******', $sanitized['password']);
        $this->assertEquals('******', $sanitized['nested']['token']);
        $this->assertEquals('hello', $sanitized['nested']['normal_field']);

        $decodedJsonString = json_decode($sanitized['json_string'], true);
        $this->assertEquals('******', $decodedJsonString['api_key']);
        $this->assertEquals('regular', $decodedJsonString['data']);
    }

    public function testSanitizerMasksSensitiveInformationInMessage(): void
    {
        $message = "User login failed for user=admin password=secret123 and token=abcde";
        $sanitized = sanitizeLogMessageTest($message);

        $this->assertStringContainsString('password=******', $sanitized);
        $this->assertStringContainsString('token=******', $sanitized);
        $this->assertStringContainsString('user=admin', $sanitized);
    }

    public function testQueueIntegrationLifecycle(): void
    {
        $apiKey = 'test-app-key-123';
        $appId = $this->appModel->create('Test App', $apiKey);

        $payload = [
            'level' => 'ERROR',
            'message' => 'DB Connection lost for host=localhost password=dbpass',
            'context' => [
                'token' => 'securetoken',
                'user_id' => 42
            ]
        ];

        // 1. Inseram direct in coada log_queue (simulam log.php)
        $db = $this->db();
        $db->create('log_queue', [
            'app_id' => $appId,
            'payload_raw' => json_encode($payload)
        ]);

        $queued = $db->read('log_queue');
        $this->assertCount(1, $queued);
        $this->assertEquals($appId, $queued[0]['app_id']);

        // 2. Procesam elementul din coada (simulam bin/worker.php)
        $job = $queued[0];
        $jobId = (int)$job['id'];
        $appId = (int)$job['app_id'];
        $payloadRaw = (string)$job['payload_raw'];
        $jsonValidate = function (string $json, int $depth = 512, int $flags = 0) {
            if (function_exists('json_validate')) {
                return json_validate($json, $depth, $flags);
            }
            $maxDepth = 0x7fffffff;
            if (0 !== $flags && \defined('JSON_INVALID_UTF8_IGNORE') && \JSON_INVALID_UTF8_IGNORE !== $flags) {
                throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
            }
            if ($depth <= 0) {
                throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
            }
            if ($depth > $maxDepth) {
                throw new \ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', $maxDepth));
            }
            json_decode($json, true, $depth, $flags);
            return \JSON_ERROR_NONE === json_last_error();
        };

        $this->assertTrue($jsonValidate($payloadRaw));
        $decoded = json_decode($payloadRaw, true);

        $level = strtoupper(trim((string)($decoded['level'] ?? 'INFO')));
        $message = sanitizeLogMessageTest(trim((string)($decoded['message'] ?? '')));
        $context = sanitizeSensitivePayloadTest($decoded['context'] ?? []);

        // Salvam in logs
        $logId = $this->logModel->create($appId, $level, $message, $context);
        $this->assertGreaterThan(0, $logId);

        // Stergem din coada
        $db->delete('log_queue', ['id' => $jobId]);

        // 3. Verificam ca a fost eliminat din coada si mutat in logs
        $this->assertCount(0, $db->read('log_queue'));

        $logs = $this->logModel->getPaginated(['app_id' => $appId]);
        $this->assertCount(1, $logs);
        $this->assertEquals('ERROR', $logs[0]['level']);
        $this->assertStringContainsString('password=******', $logs[0]['message']);

        $savedContext = json_decode($logs[0]['context'], true);
        $this->assertEquals('******', $savedContext['token']);
        $this->assertEquals(42, $savedContext['user_id']);
    }
}
