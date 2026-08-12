<?php include __DIR__.'/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="App.dashboardPageData()">
    <div class="row g-0 vh-100">
        <?php include __DIR__.'/../layouts/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col h-100 overflow-auto bg-dark bg-opacity-25">
            <header class="navbar navbar-expand-lg border-bottom border-secondary border-opacity-25 px-4 py-3 sticky-top bg-dark bg-opacity-75 backdrop-blur" style="z-index: 100;">
                <button class="btn btn-dark btn-sm me-3" @click="sidebarOpen = !sidebarOpen">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h2 class="h5 mb-0 fw-bold text-light"><?= __('Logs Timeline') ?></h2>
                    
                    <!-- Live Tailing & User Dropdown -->
                    <div class="d-flex align-items-center gap-3">
                        <!-- Auto Refresh Pill -->
                        <button @click="toggleAutoRefresh()" 
                                class="btn btn-sm d-flex align-items-center gap-2 border px-3 rounded-pill transition-all"
                                :class="autoRefresh ? 'btn-success border-success bg-success bg-opacity-10 text-success' : 'btn-outline-secondary text-secondary'">
                            <span class="pulse-dot" :class="autoRefresh ? 'bg-success' : 'bg-secondary'"></span>
                            <span class="small fw-bold" x-text="autoRefresh ? window.__('LIVE (refresh in :seconds s)', { seconds: countdown }) : window.__('LIVE OFF')"></span>
                        </button>

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
                                <li>
                                    <a class="dropdown-item text-danger" href="login.php?action=logout">
                                        <i class="fas fa-sign-out-alt me-2"></i> <?= __('Logout') ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-4">
                <!-- Stats Overview Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Total Logs') ?></span>
                                    <h3 class="h2 text-light mb-0 font-monospace fw-bold"><?= number_format($stats['total'] ?? 0) ?></h3>
                                </div>
                                <div class="icon-shape bg-info bg-opacity-10 text-info rounded-3 p-3">
                                    <i class="fas fa-chart-line fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-info"></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Active Apps') ?></span>
                                    <h3 class="h2 text-light mb-0 font-monospace fw-bold"><?= count($apps ?? []) ?></h3>
                                </div>
                                <div class="icon-shape bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                                    <i class="fas fa-microchip fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-primary"></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Critical Alerts') ?></span>
                                    <h3 class="h2 text-danger mb-0 font-monospace fw-bold"><?= number_format($stats['critical'] ?? 0) ?></h3>
                                </div>
                                <div class="icon-shape bg-danger bg-opacity-10 text-danger rounded-3 p-3">
                                    <i class="fas fa-exclamation-triangle fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-danger"></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Warnings') ?></span>
                                    <h3 class="h2 text-warning mb-0 font-monospace fw-bold"><?= number_format($stats['warning'] ?? 0) ?></h3>
                                </div>
                                <div class="icon-shape bg-warning bg-opacity-10 text-warning rounded-3 p-3">
                                    <i class="fas fa-bell fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-warning"></div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4 border-0 shadow-sm bg-dark bg-opacity-50">
                    <div class="card-body p-4">
                        <form action="logs.php" method="GET" class="row g-3 align-items-end">
                            <input type="hidden" name="limit" value="<?= htmlspecialchars((string) ($limit ?? 10)) ?>">
                            
                            <!-- First row of filters -->
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?= __('Quick search') ?></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                                        <i class="fas fa-search"></i>
                                    </span>
                                    <input type="text" name="search" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="<?= __('Search in messages or context...') ?>" value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?= __('Applications') ?></label>
                                <select name="app_id" class="form-select bg-dark border-secondary border-opacity-25 text-light">
                                    <option value=""><?= __('All Applications') ?></option>
                                    <?php foreach ($apps as $app) { ?>
                                        <option value="<?= $app['id'] ?>" <?= ((string) ($filters['app_id'] ?? '') === (string) $app['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $app['name']) ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?= __('Severity Levels') ?></label>
                                <select name="level" class="form-select bg-dark border-secondary border-opacity-25 text-light">
                                    <option value=""><?= __('All Levels') ?></option>
                                    <?php foreach ($levels as $level) { ?>
                                        <option value="<?= $level ?>" <?= (($filters['level'] ?? '') === $level) ? 'selected' : '' ?>><?= $level ?></option>
                                    <?php } ?>
                                </select>
                            </div>

                            <!-- Second row: Sort and Actions -->
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?= __('Sort By') ?></label>
                                <select name="sort_by" class="form-select bg-dark border-secondary border-opacity-25 text-light">
                                    <option value="id" <?= ($sortBy === 'id') ? 'selected' : '' ?>><?= __('ID') ?></option>
                                    <option value="created_at" <?= ($sortBy === 'created_at') ? 'selected' : '' ?>><?= __('Time') ?></option>
                                    <option value="level" <?= ($sortBy === 'level') ? 'selected' : '' ?>><?= __('Severity') ?></option>
                                    <option value="app_name" <?= ($sortBy === 'app_name') ? 'selected' : '' ?>><?= __('App Name') ?></option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?= __('Direction') ?></label>
                                <select name="sort_dir" class="form-select bg-dark border-secondary border-opacity-25 text-light">
                                    <option value="DESC" <?= ($sortDir === 'DESC') ? 'selected' : '' ?>><?= __('Descending') ?></option>
                                    <option value="ASC" <?= ($sortDir === 'ASC') ? 'selected' : '' ?>><?= __('Ascending') ?></option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-info w-100 fw-bold shadow-sm">
                                    <i class="fas fa-filter me-1"></i> <?= __('Filter') ?>
                                </button>
                                <a href="logs.php" class="btn btn-outline-secondary" title="<?= __('Reset Filters') ?>">
                                    <i class="fas fa-undo"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Application Quick-Filter Pills -->
                <div class="mb-4">
                    <span class="text-secondary small fw-bold text-uppercase d-inline-block me-3 mb-2"><?= __('Applications:') ?></span>
                    <div class="d-inline-flex flex-wrap gap-2">
                        <a href="logs.php?<?= http_build_query(array_merge($filters, ['app_id' => '', 'page' => 1])) ?>" 
                           class="badge rounded-pill px-3 py-2 text-decoration-none transition-all <?= empty($filters['app_id']) ? 'bg-info text-dark fw-bold shadow-sm' : 'bg-dark text-secondary border border-secondary border-opacity-25 hover-bg-light' ?>">
                            <?= __('All Applications') ?>
                        </a>
                        <?php foreach ($apps as $app) { ?>
                            <?php
                                $isActive = (string) ($filters['app_id'] ?? '') === (string) $app['id'];
                            ?>
                            <a href="logs.php?<?= http_build_query(array_merge($filters, ['app_id' => $app['id'], 'page' => 1])) ?>" 
                               class="badge rounded-pill px-3 py-2 text-decoration-none transition-all <?= $isActive ? 'bg-info text-dark fw-bold shadow-sm' : 'bg-dark text-secondary border border-secondary border-opacity-25 hover-bg-light' ?>">
                                <?= htmlspecialchars((string) $app['name']) ?>
                            </a>
                        <?php } ?>
                    </div>
                </div>

                <!-- Logs List/Timeline View -->
                <div class="card border-0 shadow-sm overflow-hidden bg-transparent">
                    <div class="d-flex flex-column gap-2">
                        <?php if (empty($logs)) { ?>
                            <div class="card border-0 p-5 text-center text-secondary bg-dark bg-opacity-25" style="border: 1px solid rgba(51, 65, 85, 0.25) !important;">
                                <i class="fas fa-inbox d-block fs-1 mb-3 opacity-25"></i>
                                <?= __('No logs found for the selected filters.') ?>
                            </div>
                        <?php } ?>
                        
                        <?php foreach ($logs as $log) { ?>
                            <?php
                                $accentColor = match (true) {
                                    in_array($log['level'], ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT']) => '#ef4444',
                                    $log['level'] === 'WARNING' => '#f59e0b',
                                    $log['level'] === 'INFO' => '#3b82f6',
                                    $log['level'] === 'NOTICE' => '#10b981',
                                    default => '#6b7280'
                                };

                            $badgeClass = match (true) {
                                in_array($log['level'], ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT']) => 'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25',
                                $log['level'] === 'WARNING' => 'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25',
                                $log['level'] === 'INFO' => 'bg-info bg-opacity-10 text-info border-info border-opacity-25',
                                $log['level'] === 'NOTICE' => 'bg-success bg-opacity-10 text-success border-success border-opacity-25',
                                default => 'bg-secondary bg-opacity-10 text-secondary border-secondary border-opacity-25'
                            };

                            $logJson = json_encode([
                                'app' => $log['app_name'] ?? 'Unknown',
                                'level' => $log['level'] ?? 'INFO',
                                'message' => $log['message'] ?? '',
                                'timestamp' => $log['created_at'] ?? '',
                                'context' => $log['context'] ? json_decode((string) $log['context'], true) : null,
                            ]);
                            ?>
                            <div class="card log-timeline-card border-0 border-start border-4 shadow-sm p-3 transition-all" 
                                 style="border-left-color: <?= $accentColor ?> !important; background-color: #1e293b;"
                                 @click="openLog(<?= htmlspecialchars($logJson) ?>)">
                                <div class="row align-items-center g-2">
                                    <div class="col-md-2 col-sm-3 d-flex align-items-center gap-2">
                                        <span class="badge bg-secondary bg-opacity-25 text-light px-2 py-1 font-monospace text-truncate" style="max-width: 140px;">
                                            <?= htmlspecialchars((string) ($log['app_name'] ?? 'Unknown')) ?>
                                        </span>
                                    </div>
                                    <div class="col-md-2 col-sm-3">
                                        <span class="badge border rounded-pill px-2.5 py-0.5 small <?= $badgeClass ?>">
                                            <?= $log['level'] ?? 'INFO' ?>
                                        </span>
                                    </div>
                                    <div class="col col-md-5 col-sm-6 text-truncate">
                                        <span class="text-light opacity-90 fw-medium font-monospace message-text">
                                            <?= htmlspecialchars((string) ($log['message'] ?? '')) ?>
                                        </span>
                                        <?php if ($log['context']) { ?>
                                            <span class="badge bg-dark text-info ms-1 small" title="<?= __('Contains JSON context') ?>">
                                                <i class="fas fa-code fs-xs me-1"></i> JSON
                                            </span>
                                        <?php } ?>
                                    </div>
                                    <div class="col-md-2 col-sm-12 text-secondary font-monospace small">
                                        <?= date('H:i:s d.m.Y', strtotime($log['created_at'])) ?>
                                    </div>
                                    <div class="col-md-1 col-sm-12 text-end">
                                        <button class="btn btn-link text-info p-0 hover-scale" @click.stop="openLog(<?= htmlspecialchars($logJson) ?>)">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Pagination & Page Size controls -->
                <?php
                    $currentPage = (int) ($page ?? 1);
                    $range = 2; // Range of pages to show around the current page
                    $startPage = max(1, $currentPage - $range);
                    $endPage = min($totalPages ?? 1, $currentPage + $range);
                    $limitOptions = [10, 25, 50, 100];
                    $paginationParams = array_merge(array_filter($filters ?? []), [
                        'sort_by' => $sortBy ?? 'id',
                        'sort_dir' => $sortDir ?? 'DESC',
                    ]);
                ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">
                    <!-- Page Size Selector -->
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-secondary small text-nowrap mb-0"><?= __('Rows per page:') ?></label>
                        <select class="form-select form-select-sm bg-dark border-secondary border-opacity-25 text-light" style="width: auto; cursor: pointer;" @change="window.location.href = $event.target.value">
                            <?php foreach ($limitOptions as $l) { ?>
                                <?php
                    $urlParams = array_merge($paginationParams, [
                        'page' => 1,
                        'limit' => $l,
                    ]);
                                $url = '?'.http_build_query($urlParams);
                                ?>
                                <option value="<?= htmlspecialchars($url) ?>" <?= ($limit === $l) ? 'selected' : '' ?>>
                                    <?= $l ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <!-- Pagination Links -->
                    <?php if (($totalPages ?? 0) > 1) { ?>
                        <nav aria-label="<?= __('Pagination') ?>">
                            <ul class="pagination mb-0 gap-1 align-items-center">
                                <!-- First Page -->
                                <li class="page-item <?= ($currentPage <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage <= 1) ? '#' : '?'.http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) ?>" 
                                       title="<?= __('First') ?>">
                                        <i class="fas fa-angle-double-left small"></i>
                                    </a>
                                </li>

                                <!-- Prev Link -->
                                <li class="page-item <?= ($currentPage <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage <= 1) ? '#' : '?'.http_build_query(array_merge($paginationParams, ['page' => $currentPage - 1, 'limit' => $limit])) ?>" 
                                       title="<?= __('Previous') ?>">
                                        <i class="fas fa-chevron-left small"></i>
                                    </a>
                                </li>

                                <!-- First Page Number if range starts after page 1 -->
                                <?php if ($startPage > 1) { ?>
                                    <li class="page-item">
                                        <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                           href="?<?= http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2) { ?>
                                        <li class="page-item disabled"><span class="page-link rounded bg-dark border-secondary border-opacity-25 text-secondary">...</span></li>
                                    <?php } ?>
                                <?php } ?>

                                <!-- Intermediate Page Links -->
                                <?php for ($i = $startPage; $i <= $endPage; $i++) { ?>
                                    <li class="page-item <?= ($currentPage === $i) ? 'active' : '' ?>">
                                        <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                           href="?<?= http_build_query(array_merge($paginationParams, ['page' => $i, 'limit' => $limit])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php } ?>

                                <!-- Last Page Number if range ends before totalPages -->
                                <?php if ($endPage < $totalPages) { ?>
                                    <?php if ($endPage < $totalPages - 1) { ?>
                                        <li class="page-item disabled"><span class="page-link rounded bg-dark border-secondary border-opacity-25 text-secondary">...</span></li>
                                    <?php } ?>
                                    <li class="page-item">
                                        <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                           href="?<?= http_build_query(array_merge($paginationParams, ['page' => $totalPages, 'limit' => $limit])) ?>"><?= $totalPages ?></a>
                                    </li>
                                <?php } ?>

                                <!-- Next Link -->
                                <li class="page-item <?= ($currentPage >= $totalPages) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage >= $totalPages) ? '#' : '?'.http_build_query(array_merge($paginationParams, ['page' => $currentPage + 1, 'limit' => $limit])) ?>" 
                                       title="<?= __('Next') ?>">
                                        <i class="fas fa-chevron-right small"></i>
                                    </a>
                                </li>

                                <!-- Last Page -->
                                <li class="page-item <?= ($currentPage >= $totalPages) ? 'disabled' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" 
                                       href="<?= ($currentPage >= $totalPages) ? '#' : '?'.http_build_query(array_merge($paginationParams, ['page' => $totalPages, 'limit' => $limit])) ?>" 
                                       title="<?= __('Last') ?>">
                                        <i class="fas fa-angle-double-right small"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php } ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Details Modal -->
    <template x-if="showModal">
        <div class="modal d-block shadow-lg" tabindex="-1" style="background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px);" @click.self="showModal = false">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content bg-dark border-secondary shadow-lg border-opacity-25 rounded-4 overflow-hidden">
                    <div class="modal-header border-secondary border-opacity-25 bg-black bg-opacity-20 p-4">
                        <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                            <i class="fas fa-info-circle text-info"></i> <?= __('Log Entry Details') ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" @click="showModal = false"></button>
                    </div>
                    <div class="modal-body p-4" x-show="selectedLog">
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <label class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Application') ?></label>
                                <div class="text-light fs-5 fw-bold" x-text="selectedLog?.app"></div>
                            </div>
                            <div class="col-md-3">
                                <label class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Level') ?></label>
                                <span class="badge rounded-pill px-3 py-2 border" 
                                      :class="{
                                          'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25': ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'].includes(selectedLog?.level),
                                          'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25': selectedLog?.level === 'WARNING',
                                          'bg-info bg-opacity-10 text-info border-info border-opacity-25': selectedLog?.level === 'INFO',
                                          'bg-success bg-opacity-10 text-success border-success border-opacity-25': selectedLog?.level === 'NOTICE',
                                          'bg-secondary bg-opacity-10 text-secondary border-secondary border-opacity-25': !['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT', 'WARNING', 'INFO', 'NOTICE'].includes(selectedLog?.level)
                                      }"
                                      x-text="selectedLog?.level"></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Timestamp') ?></label>
                                <div class="text-secondary font-monospace" x-text="selectedLog?.timestamp"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Message') ?></label>
                            <div class="p-3 bg-black bg-opacity-25 rounded border border-secondary border-opacity-10 text-light font-monospace text-break" x-text="selectedLog?.message"></div>
                        </div>

                        <div>
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Context (JSON)') ?></label>
                            <div class="bg-dark bg-opacity-50 p-3 rounded border border-secondary border-opacity-25" style="max-height: 400px; overflow-y: auto;">
                                <pre class="mb-0 text-info small" style="white-space: pre-wrap; word-wrap: break-word;"><code x-text="JSON.stringify(selectedLog?.context, null, 4)"></code></pre>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary border-opacity-25 bg-black bg-opacity-10 p-3">
                        <button type="button" class="btn btn-secondary px-4 btn-sm" @click="showModal = false"><?= __('Close') ?></button>
                        <button 
                            type="button" 
                            class="btn px-4 btn-sm text-dark fw-bold transition-all" 
                            :class="payloadCopied ? 'btn-success' : 'btn-info'"
                            @click="copyLog()"
                        >
                            <span x-show="!payloadCopied">
                                <i class="fas fa-copy me-2"></i> <?= __('Copy Log') ?>
                            </span>
                            <span x-show="payloadCopied" x-cloak>
                                <i class="fas fa-check me-2"></i> <?= __('Log Copied') ?>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<?php include __DIR__.'/../layouts/footer.php'; ?>
