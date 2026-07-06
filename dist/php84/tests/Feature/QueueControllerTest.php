<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Controllers\QueueController;
use App\Models\App;

if (!class_exists(\Tests\Feature\HttpRedirectException::class, false)) {
    class HttpRedirectException extends \Exception
    {
        public string $url;

        public function __construct(string $url)
        {
            parent::__construct("HTTP Redirect to: " . $url);
            $this->url = $url;
        }
    }
}

/**
 * Subclassed controller to intercept redirect/render calls.
 */
class TestableQueueController extends QueueController
{
    public string $renderedView = '';
    public array $renderedData = [];
    public ?string $redirectUrl = null;

    protected function redirect(string $url): never
    {
        $this->redirectUrl = $url;
        throw new HttpRedirectException($url);
    }

    protected function render(string $view, array $data = []): void
    {
        $this->renderedView = $view;
        $this->renderedData = $data;
    }
}

class QueueControllerTest extends TestCase
{
    private App $appModel;
    private TestableQueueController $controller;
    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->controller = new TestableQueueController();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE logs;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE log_queue;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];
        $this->appId = $this->appModel->create('Queue App', 'queue-key');
    }

    public function testIndexRendersQueueWithCorrectData(): void
    {
        // Insert some jobs
        $this->db()->create('log_queue', [
            'app_id' => $this->appId,
            'payload_raw' => json_encode(['level' => 'INFO', 'message' => 'Job 1'])
        ]);
        $this->db()->create('log_queue', [
            'app_id' => $this->appId,
            'payload_raw' => json_encode(['level' => 'ERROR', 'message' => 'Job 2'])
        ]);

        $this->controller->index();

        $this->assertEquals('queue/index', $this->controller->renderedView);
        $this->assertCount(2, $this->controller->renderedData['jobs']);
        $this->assertEquals(2, $this->controller->renderedData['totalJobs']);
        $this->assertEquals(1, $this->controller->renderedData['page']);
        $this->assertEquals(1, $this->controller->renderedData['totalPages']);
        $this->assertIsBool($this->controller->renderedData['isWorkerRunning']);
    }

    public function testDeleteRemovesSpecificJobFromQueue(): void
    {
        $this->db()->create('log_queue', [
            'app_id' => $this->appId,
            'payload_raw' => json_encode(['level' => 'INFO', 'message' => 'Job to delete'])
        ]);

        $jobs = $this->db()->read('log_queue');
        $this->assertCount(1, $jobs);
        $jobId = $jobs[0]['id'];

        try {
            $this->controller->delete(['id' => $jobId]);
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('queue.php', $e->url);
        }

        $this->assertCount(0, $this->db()->read('log_queue'));
    }

    public function testPurgeClearsAllJobsFromQueue(): void
    {
        $this->db()->create('log_queue', [
            'app_id' => $this->appId,
            'payload_raw' => json_encode(['level' => 'INFO', 'message' => 'Job 1'])
        ]);
        $this->db()->create('log_queue', [
            'app_id' => $this->appId,
            'payload_raw' => json_encode(['level' => 'ERROR', 'message' => 'Job 2'])
        ]);

        $this->assertCount(2, $this->db()->read('log_queue'));

        try {
            $this->controller->purge();
            $this->fail('Expected HttpRedirectException to be thrown');
        } catch (HttpRedirectException $e) {
            $this->assertEquals('queue.php', $e->url);
        }

        $this->assertCount(0, $this->db()->read('log_queue'));
    }
}
