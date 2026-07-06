<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;
use PDO;

/**
 * Clasa QueueController
 *
 * Responsabila pentru gestionarea paginii si operatiilor din cadrul Queue Manager-ului.
 * Permite listarea joburilor din coada, stergerea individuala, curatarea completa a cozii
 * si verificarea starii de functionare a worker-ului de fundal.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class QueueController extends BaseController
{
    /**
     * Afiseaza pagina de vizualizare a cozii active.
     *
     * @param array $queryParams Parametrii de filtrare si paginare.
     * @return void
     */
    public function index(array $queryParams = []): void
    {
        $this->checkAuth();

        try {
            $db = MySQLWrapper::getInstance();

            // Configurare paginare
            $page = (int)($queryParams['page'] ?? 1);
            if ($page < 1) {
                $page = 1;
            }

            $allowedLimits = [10, 25, 50, 100];
            $limit = (int)($queryParams['limit'] ?? 10);
            if (!in_array($limit, $allowedLimits, true)) {
                $limit = 10;
            }
            $offset = ($page - 1) * $limit;

            // Obtinem parametrii de sortare din URL
            $sortBy = (string)($queryParams['sort_by'] ?? 'id');
            $sortDir = (string)($queryParams['sort_dir'] ?? 'DESC');

            $allowedSorts = ['id', 'created_at', 'app_name'];
            $allowedDirections = ['ASC', 'DESC'];

            if (!in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            if (!in_array(strtoupper($sortDir), $allowedDirections, true)) {
                $sortDir = 'DESC';
            }
            $sortOrder = strtoupper($sortDir);

            if ($sortBy === 'app_name') {
                $orderClause = "ORDER BY a.name {$sortOrder}";
            } elseif ($sortBy === 'created_at') {
                $orderClause = "ORDER BY q.created_at {$sortOrder}, q.id {$sortOrder}";
            } else {
                $orderClause = "ORDER BY q.id {$sortOrder}";
            }

            // Obtinem numarul total de joburi din coada
            $stmtCount = $db->query('SELECT COUNT(*) FROM log_queue');
            $totalJobs = (int)$stmtCount->fetchColumn();
            $totalPages = (int)ceil($totalJobs / $limit);

            // Obtinem elementele din coada cu JOIN pe aplicatii
            // Securizam LIMIT si OFFSET prin interpolare ca intregi
            $sql = "SELECT q.id, q.app_id, q.payload_raw, q.created_at, a.name as app_name 
                    FROM log_queue q 
                    LEFT JOIN apps a ON q.app_id = a.id 
                    {$orderClause} 
                    LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

            $stmtJobs = $db->query($sql);
            $jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Verificam starea worker-ului in fundal executand pgrep
            $output = [];
            $returnVar = 0;
            exec('pgrep -f "bin/worker.php"', $output, $returnVar);
            $isWorkerRunning = ($returnVar === 0 && !empty($output));

            $this->render('queue/index', [
                'jobs' => $jobs,
                'totalJobs' => $totalJobs,
                'totalPages' => $totalPages,
                'page' => $page,
                'limit' => $limit,
                'isWorkerRunning' => $isWorkerRunning,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ]);
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Queue index processing failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'QueueController_Index_Failure'
            ]);

            throw new \RuntimeException(__('Critical error loading queue data. Please try again later.'));
        }
    }

    /**
     * Sterge un singur job specific din coada.
     *
     * @param array $postData Vectorul de date transmise prin POST.
     * @return never Redirectioneaza inapoi la pagina de coada.
     */
    public function delete(array $postData): never
    {
        $this->checkAuth();

        $id = (int)($postData['id'] ?? 0);
        if ($id <= 0) {
            setFlash('danger', __('Error'), __('Invalid job ID.'));
            $this->redirect('queue.php');
        }

        try {
            $db = MySQLWrapper::getInstance();
            $db->delete('log_queue', ['id' => $id]);
            setFlash('success', __('Success'), __('Job has been deleted from queue.'));
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Queue delete job failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'job_id' => $id,
                'identifier' => 'QueueController_DeleteJob_Failure'
            ]);
            setFlash('danger', __('Error'), __('Failed to delete job from queue.'));
        }

        $this->redirect('queue.php');
    }

    /**
     * Curata complet coada (Purge).
     *
     * @return never Redirectioneaza inapoi la pagina de coada.
     */
    public function purge(): never
    {
        $this->checkAuth();

        try {
            $db = MySQLWrapper::getInstance();
            // Curatam tabela log_queue tranzactional prin TRUNCATE
            $db->getConnection()->exec('TRUNCATE TABLE log_queue');
            setFlash('success', __('Success'), __('The queue has been completely purged.'));
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Queue purge failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'identifier' => 'QueueController_Purge_Failure'
            ]);
            setFlash('danger', __('Error'), __('Failed to purge the queue.'));
        }

        $this->redirect('queue.php');
    }
}
