<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="App.queuePageData()">
    <div class="row g-0 vh-100">
        <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col h-100 overflow-auto bg-dark bg-opacity-25">
            <header class="navbar navbar-expand-lg border-bottom border-secondary border-opacity-25 px-4 py-3 sticky-top bg-dark bg-opacity-75 backdrop-blur" style="z-index: 100;">
                <button class="btn btn-dark btn-sm me-3" @click="sidebarOpen = !sidebarOpen">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h2 class="h5 mb-0 fw-bold text-light"><?= __('Queue Manager') ?></h2>
                    
                    <div class="d-flex align-items-center gap-3">
                        <!-- Language Switcher -->
                        <div class="dropdown">
                            <button class="btn btn-dark btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-globe text-secondary"></i>
                                <span class="text-uppercase"><?= getLang() ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                                <li>
                                    <a class="dropdown-item d-flex align-items-center justify-content-between <?= getLang() === 'ro' ? 'active font-weight-bold' : '' ?>" href="change-lang.php?lang=ro">
                                        Romana <?= getLang() === 'ro' ? '✓' : '' ?>
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center justify-content-between <?= getLang() === 'en' ? 'active font-weight-bold' : '' ?>" href="change-lang.php?lang=en">
                                        English <?= getLang() === 'en' ? '✓' : '' ?>
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <div class="dropdown">
                            <button class="btn btn-dark btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="far fa-user-circle me-2 text-info"></i> <?= htmlspecialchars($_SESSION['auth.user']['username'] ?? 'User') ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="login.php?action=logout"><?= __('Logout') ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-4">
                <!-- Metric Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Pending Jobs') ?></span>
                                    <h3 class="h2 text-light mb-0 font-monospace fw-bold"><?= number_format($totalJobs ?? 0) ?></h3>
                                </div>
                                <div class="icon-shape bg-info bg-opacity-10 text-info rounded-3 p-3">
                                    <i class="fas fa-tasks fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-info"></div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Worker Status') ?></span>
                                    <?php if ($isWorkerRunning): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="pulse-dot bg-success"></span>
                                            <h3 class="h3 text-success mb-0 fw-bold"><?= __('ACTIVE') ?></h3>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="pulse-dot bg-danger"></span>
                                            <h3 class="h3 text-danger mb-0 fw-bold"><?= __('INACTIVE') ?></h3>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="icon-shape <?= $isWorkerRunning ? 'bg-success text-success' : 'bg-danger text-danger' ?> bg-opacity-10 rounded-3 p-3">
                                    <i class="fas <?= $isWorkerRunning ? 'fa-check-circle' : 'fa-times-circle' ?> fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow <?= $isWorkerRunning ? 'bg-success' : 'bg-danger' ?>"></div>
                        </div>
                    </div>
                </div>

                <!-- Global Actions -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="h5 mb-0 text-light fw-bold"><?= __('Active Queue') ?></h3>
                    <?php if ($totalJobs > 0): ?>
                        <form action="queue.php?action=purge" method="POST" onsubmit="return confirm('<?= __('Are you sure you want to completely purge the log queue?') ?>')" class="m-0">
                            <button type="submit" class="btn btn-outline-danger btn-sm fw-bold">
                                <i class="far fa-trash-alt me-2"></i> <?= __('Purge Queue') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Table of jobs -->
                <div class="card border-0 shadow-sm bg-dark bg-opacity-50">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0 align-middle">
                                <thead class="bg-dark text-secondary small text-uppercase">
                                    <tr>
                                        <th class="ps-4 border-0" style="width: 130px;">
                                            <?php
                                                $nextDirId = ($sortBy === 'id' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                                $urlId = '?' . http_build_query(['page' => 1, 'limit' => $limit, 'sort_by' => 'id', 'sort_dir' => $nextDirId]);
                                            ?>
                                            <a href="<?= $urlId ?>" class="text-decoration-none text-secondary hover-text-light d-flex align-items-center gap-1">
                                                <?= __('Job ID') ?>
                                                <?php if ($sortBy === 'id'): ?>
                                                    <i class="fas fa-sort-<?= $sortDir === 'ASC' ? 'up' : 'down' ?> text-info ms-1"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-sort text-secondary opacity-50 ms-1" style="font-size: 0.8rem;"></i>
                                                <?php endif; ?>
                                            </a>
                                        </th>
                                        <th class="border-0" style="width: 180px;">
                                            <?php
                                                $nextDirApp = ($sortBy === 'app_name' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                                $urlApp = '?' . http_build_query(['page' => 1, 'limit' => $limit, 'sort_by' => 'app_name', 'sort_dir' => $nextDirApp]);
                                            ?>
                                            <a href="<?= $urlApp ?>" class="text-decoration-none text-secondary hover-text-light d-flex align-items-center gap-1">
                                                <?= __('App Name') ?>
                                                <?php if ($sortBy === 'app_name'): ?>
                                                    <i class="fas fa-sort-<?= $sortDir === 'ASC' ? 'up' : 'down' ?> text-info ms-1"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-sort text-secondary opacity-50 ms-1" style="font-size: 0.8rem;"></i>
                                                <?php endif; ?>
                                            </a>
                                        </th>
                                        <th class="border-0"><?= __('Payload Preview') ?></th>
                                        <th class="border-0" style="width: 180px;">
                                            <?php
                                                $nextDirTime = ($sortBy === 'created_at' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                                $urlTime = '?' . http_build_query(['page' => 1, 'limit' => $limit, 'sort_by' => 'created_at', 'sort_dir' => $nextDirTime]);
                                            ?>
                                            <a href="<?= $urlTime ?>" class="text-decoration-none text-secondary hover-text-light d-flex align-items-center gap-1">
                                                <?= __('Created At') ?>
                                                <?php if ($sortBy === 'created_at'): ?>
                                                    <i class="fas fa-sort-<?= $sortDir === 'ASC' ? 'up' : 'down' ?> text-info ms-1"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-sort text-secondary opacity-50 ms-1" style="font-size: 0.8rem;"></i>
                                                <?php endif; ?>
                                            </a>
                                        </th>
                                        <th class="border-0 text-end pe-4" style="width: 180px;"><?= __('Actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($jobs as $job): ?>
                                        <?php 
                                            $payload = json_decode($job['payload_raw'], true) ?: [];
                                            $previewMsg = $payload['message'] ?? ($payload[0]['message'] ?? 'N/A');
                                            if (is_array($previewMsg)) {
                                                $previewMsg = json_encode($previewMsg);
                                            }
                                            $previewMsg = mb_strimwidth((string)$previewMsg, 0, 80, '...');
                                            $payloadSize = number_format(strlen($job['payload_raw']) / 1024, 2) . ' KB';
                                        ?>
                                        <tr>
                                            <td class="ps-4 border-secondary border-opacity-10 text-secondary font-monospace">
                                                #<?= $job['id'] ?>
                                            </td>
                                            <td class="border-secondary border-opacity-10 text-light fw-medium">
                                                <?= htmlspecialchars($job['app_name'] ?? __('Unknown App')) ?>
                                            </td>
                                            <td class="border-secondary border-opacity-10 text-secondary small">
                                                <span class="text-light"><?= htmlspecialchars($previewMsg) ?></span>
                                                <span class="badge bg-secondary bg-opacity-25 text-secondary ms-2"><?= $payloadSize ?></span>
                                            </td>
                                            <td class="border-secondary border-opacity-10 text-secondary small font-monospace">
                                                <?= htmlspecialchars($job['created_at']) ?>
                                            </td>
                                            <td class="text-end pe-4 border-secondary border-opacity-10">
                                                <div class="d-inline-flex gap-3 align-items-center">
                                                    <button type="button" class="btn btn-link text-info p-0" title="<?= __('View Details') ?>" @click="viewJob(<?= htmlspecialchars(json_encode($job), ENT_QUOTES, 'UTF-8') ?>)">
                                                        <i class="far fa-eye"></i>
                                                    </button>
                                                    <form action="queue.php?action=delete" method="POST" onsubmit="return confirm('<?= __('Are you sure you want to delete this job from the queue?') ?>')" class="m-0">
                                                        <input type="hidden" name="id" value="<?= $job['id'] ?>">
                                                        <button type="submit" class="btn btn-link text-danger p-0" title="<?= __('Delete') ?>">
                                                            <i class="far fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($jobs)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-secondary">
                                                <i class="fas fa-inbox fa-2x mb-3 text-secondary opacity-25"></i>
                                                <p class="mb-0 small"><?= __('No jobs currently in the queue.') ?></p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pagination & Page Size controls -->
                <?php
                    $currentPage = (int)($page ?? 1);
                    $range = 2; // Range of pages to show around the current page
                    $startPage = max(1, $currentPage - $range);
                    $endPage = min($totalPages ?? 1, $currentPage + $range);
                    $limitOptions = [10, 25, 50, 100];
                    $paginationParams = [
                        'sort_by' => $sortBy ?? 'id',
                        'sort_dir' => $sortDir ?? 'DESC'
                    ];
                ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">
                    <!-- Page Size Selector -->
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-secondary small text-nowrap mb-0"><?= __('Rows per page:') ?></label>
                        <select class="form-select form-select-sm bg-dark border-secondary border-opacity-25 text-light" style="width: auto; cursor: pointer;" @change="window.location.href = $event.target.value">
                            <?php foreach ($limitOptions as $l): ?>
                                <?php
                                    $urlParams = array_merge($paginationParams, [
                                        'page' => 1,
                                        'limit' => $l
                                    ]);
                                    $url = '?' . http_build_query($urlParams);
                                ?>
                                <option value="<?= htmlspecialchars($url) ?>" <?= ($limit === $l) ? 'selected' : '' ?>>
                                    <?= $l ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Pagination Links -->
                    <?php if (($totalPages ?? 0) > 1): ?>
                        <nav aria-label="<?= __('Pagination') ?>">
                            <ul class="pagination mb-0 gap-1 align-items-center">
                                <!-- First Page -->
                                <li class="page-item <?= ($currentPage <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage <= 1) ? '#' : '?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) ?>" 
                                       title="<?= __('First') ?>">
                                        <i class="fas fa-angle-double-left small"></i>
                                    </a>
                                </li>

                                <!-- Prev Link -->
                                <li class="page-item <?= ($currentPage <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage <= 1) ? '#' : '?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage - 1, 'limit' => $limit])) ?>" 
                                       title="<?= __('Previous') ?>">
                                        <i class="fas fa-chevron-left small"></i>
                                    </a>
                                </li>

                                <!-- First Page Number if range starts after page 1 -->
                                <?php if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                           href="?<?= http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link rounded bg-dark border-secondary border-opacity-25 text-secondary">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- Intermediate Page Links -->
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?= ($currentPage === $i) ? 'active' : '' ?>">
                                        <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                           href="?<?= http_build_query(array_merge($paginationParams, ['page' => $i, 'limit' => $limit])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <!-- Last Page Number if range ends before totalPages -->
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link rounded bg-dark border-secondary border-opacity-25 text-secondary">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                           href="?<?= http_build_query(array_merge($paginationParams, ['page' => $totalPages, 'limit' => $limit])) ?>"><?= $totalPages ?></a>
                                    </li>
                                <?php endif; ?>

                                <!-- Next Link -->
                                <li class="page-item <?= ($currentPage >= $totalPages) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage >= $totalPages) ? '#' : '?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage + 1, 'limit' => $limit])) ?>" 
                                       title="<?= __('Next') ?>">
                                        <i class="fas fa-chevron-right small"></i>
                                    </a>
                                </li>

                                <!-- Last Page -->
                                <li class="page-item <?= ($currentPage >= $totalPages) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage >= $totalPages) ? '#' : '?' . http_build_query(array_merge($paginationParams, ['page' => $totalPages, 'limit' => $limit])) ?>" 
                                       title="<?= __('Last') ?>">
                                        <i class="fas fa-angle-double-right small"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Job Details Modal -->
    <template x-if="showModal">
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" 
             style="z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);" 
             @click.self="showModal = false">
            
             <div class="card shadow-lg border border-secondary border-opacity-25" style="width: 100%; max-width: 800px; background-color: #1e293b;" @click.stop>
                <!-- Modal Header -->
                <div class="card-header border-bottom border-secondary border-opacity-25 p-4 d-flex align-items-center justify-content-between bg-black bg-opacity-20">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-tasks text-info"></i>
                        <h4 class="h5 mb-0 fw-bold text-light">
                            <?= __('Job details:') ?> <span class="text-info">#<span x-text="selectedJob.id"></span></span>
                        </h4>
                    </div>
                    <button type="button" class="btn-close btn-close-white" @click="showModal = false" aria-label="<?= __('Close') ?>"></button>
                </div>
                
                <!-- Modal Body -->
                <div class="card-body p-4 overflow-auto" style="max-height: 70vh;">
                    <div class="mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Application') ?></span>
                        <span class="text-light fw-medium" x-text="selectedJob.app_name || '<?= __('Unknown App') ?>'"></span>
                    </div>

                    <div class="mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Created At') ?></span>
                        <span class="text-light font-monospace" x-text="selectedJob.created_at"></span>
                    </div>
                    
                    <div>
                        <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Payload (JSON Raw)') ?></span>
                        <pre class="bg-black bg-opacity-50 p-3 rounded text-info font-monospace small overflow-auto" style="max-height: 400px; white-space: pre-wrap; word-break: break-all;" x-text="formatJson(selectedJob.payload_raw)"></pre>
                    </div>
                </div>
                
                <!-- Modal Footer -->
                <div class="card-footer border-top border-secondary border-opacity-25 p-3 d-flex justify-content-end gap-2 bg-dark bg-opacity-25">
                    <button type="button" class="btn btn-outline-info btn-sm px-3" @click="copyPayload()" x-text="window.__('Copy Payload')"></button>
                    <button type="button" class="btn btn-secondary btn-sm px-4" @click="showModal = false" x-text="window.__('Close')"></button>
                </div>
            </div>
        </div>
    </template>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
