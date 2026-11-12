<?php include __DIR__.'/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="{ sidebarOpen: window.innerWidth >= 992 }">
    <div class="row g-0 vh-100">
        <?php include __DIR__.'/../layouts/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col h-100 overflow-y-auto overflow-x-hidden bg-dark bg-opacity-25">
            <header class="navbar navbar-expand-lg border-bottom border-secondary border-opacity-25 px-3 px-lg-4 py-3 sticky-top bg-dark bg-opacity-75 backdrop-blur" style="z-index: 100;">
                <button class="btn btn-dark btn-sm me-3" @click="sidebarOpen = !sidebarOpen" aria-label="<?= __('Toggle Navigation') ?>">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h2 class="h5 mb-0 fw-bold text-light"><?= __('Dashboard') ?></h2>
                    
                    <div class="d-flex align-items-center gap-2 gap-sm-3">
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
                                <i class="far fa-user-circle me-1 me-sm-2 text-info"></i> <span class="d-none d-sm-inline"><?= htmlspecialchars($_SESSION['auth.user']['username'] ?? 'User') ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                                <li>
                                    <a class="dropdown-item text-danger" href="logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i> <?= __('Logout') ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-3 p-lg-4">
                <!-- Stats Overview Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden h-100">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Success Rate') ?></span>
                                    <h3 class="h2 text-success mb-0 font-monospace fw-bold"><?= $successRate ?>%</h3>
                                </div>
                                <div class="icon-shape bg-success bg-opacity-10 text-success rounded-3 p-3">
                                    <i class="fas fa-check-circle fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-success"></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden h-100">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Total Logs') ?></span>
                                    <h3 class="h2 text-light mb-0 font-monospace fw-bold"><?= number_format($totalLogs) ?></h3>
                                </div>
                                <div class="icon-shape bg-info bg-opacity-10 text-info rounded-3 p-3">
                                    <i class="fas fa-terminal fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-info"></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden h-100">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Queue Size') ?></span>
                                    <h3 class="h2 text-warning mb-0 font-monospace fw-bold"><?= number_format($queueSize) ?></h3>
                                </div>
                                <div class="icon-shape bg-warning bg-opacity-10 text-warning rounded-3 p-3">
                                    <i class="fas fa-tasks fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-warning"></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden h-100">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-secondary small fw-bold text-uppercase d-block mb-1"><?= __('Active Apps') ?></span>
                                    <h3 class="h2 text-primary mb-0 font-monospace fw-bold"><?= number_format($activeApps) ?></h3>
                                </div>
                                <div class="icon-shape bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                                    <i class="fas fa-microchip fa-lg"></i>
                                </div>
                            </div>
                            <div class="card-glow bg-primary"></div>
                        </div>
                    </div>
                </div>

                <!-- Charts & Analytics Section -->
                <div class="card border-0 shadow-sm mb-4 overflow-hidden" x-data="dashboardAnalytics(<?= htmlspecialchars(json_encode($chartData), ENT_QUOTES, 'UTF-8') ?>)" x-init="initCharts()">
                    <div class="card-header bg-transparent border-secondary border-opacity-10 p-3 p-lg-4">
                        <div class="d-flex flex-column flex-xl-row align-items-start align-items-xl-center justify-content-between gap-3 w-100">
                            <!-- Left: Title, Badge and Subtitle -->
                            <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-2 gap-sm-3 flex-wrap">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fas fa-chart-line text-info fs-5"></i>
                                    <h3 class="h6 mb-0 text-light fw-bold text-uppercase tracking-wider text-nowrap"><?= __('Charts & Analytics') ?></h3>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-dark border border-secondary border-opacity-25 text-secondary font-monospace small px-2.5 py-1 text-nowrap" x-text="rangeLabel"></span>
                                    <!-- Loading Spinner -->
                                    <div x-show="loading" class="spinner-border spinner-border-sm text-info flex-shrink-0" role="status">
                                        <span class="visually-hidden"><?= __('Loading analytics...') ?></span>
                                    </div>
                                </div>
                                <span class="text-secondary small d-none d-md-inline opacity-75">• <?= __('Analytics Overview') ?></span>
                            </div>

                            <!-- Right: Filter Controls -->
                            <div class="w-100 w-xl-auto ms-xl-auto">
                                <div class="btn-group-responsive bg-dark p-1 rounded-3 border border-secondary border-opacity-25 shadow-sm">
                                    <button type="button" class="btn btn-sm px-2.5 px-sm-3 py-1.5 rounded-2 fw-medium text-nowrap transition-all"
                                            :class="period === 'day' ? 'btn-info text-white shadow-sm' : 'text-secondary hover-text-light'"
                                            @click="selectPeriod('day')" :disabled="loading">
                                        <i class="far fa-clock me-1"></i> <?= __('Day (24h)') ?>
                                    </button>
                                    <button type="button" class="btn btn-sm px-2.5 px-sm-3 py-1.5 rounded-2 fw-medium text-nowrap transition-all"
                                            :class="period === 'month' ? 'btn-info text-white shadow-sm' : 'text-secondary hover-text-light'"
                                            @click="selectPeriod('month')" :disabled="loading">
                                        <i class="far fa-calendar-alt me-1"></i> <?= __('Month (30d)') ?>
                                    </button>
                                    <button type="button" class="btn btn-sm px-2.5 px-sm-3 py-1.5 rounded-2 fw-medium text-nowrap transition-all"
                                            :class="period === 'year' ? 'btn-info text-white shadow-sm' : 'text-secondary hover-text-light'"
                                            @click="selectPeriod('year')" :disabled="loading">
                                        <i class="fas fa-calendar me-1"></i> <?= __('Year (12m)') ?>
                                    </button>
                                    <button type="button" class="btn btn-sm px-2.5 px-sm-3 py-1.5 rounded-2 fw-medium text-nowrap transition-all"
                                            :class="period === 'custom' ? 'btn-info text-white shadow-sm' : 'text-secondary hover-text-light'"
                                            @click="selectPeriod('custom')" :disabled="loading">
                                        <i class="fas fa-sliders-h me-1"></i> <?= __('Custom') ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Custom Date Range Form -->
                        <div x-show="period === 'custom'" x-transition class="mt-3 pt-3 border-top border-secondary border-opacity-10 w-100">
                            <form @submit.prevent="applyCustomRange()" class="row g-2 align-items-center justify-content-end">
                                <div class="col-12 col-sm-auto d-flex align-items-center gap-2">
                                    <label class="text-secondary small fw-medium text-nowrap"><?= __('From') ?>:</label>
                                    <input type="date" x-model="customStart" class="form-control form-control-sm bg-dark border-secondary border-opacity-25 text-light w-100" required>
                                </div>
                                <div class="col-12 col-sm-auto d-flex align-items-center gap-2">
                                    <label class="text-secondary small fw-medium text-nowrap"><?= __('To') ?>:</label>
                                    <input type="date" x-model="customEnd" class="form-control form-control-sm bg-dark border-secondary border-opacity-25 text-light w-100" required>
                                </div>
                                <div class="col-12 col-sm-auto">
                                    <button type="submit" class="btn btn-sm btn-info text-white px-3 fw-bold text-nowrap w-100 w-sm-auto" :disabled="loading">
                                        <i class="fas fa-filter me-1"></i> <?= __('Apply') ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body p-3 p-lg-4">
                        <div class="row g-4">
                            <!-- Line Chart: Log Volume Over Time -->
                            <div class="col-12 col-xl-8">
                                <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-10 h-100 d-flex flex-column">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                        <span class="text-secondary small fw-bold text-uppercase d-flex align-items-center gap-2 text-nowrap">
                                            <i class="fas fa-wave-square text-info"></i> <?= __('Log Volume Over Time') ?>
                                        </span>
                                        <div class="d-flex align-items-center gap-2 gap-sm-3 small font-monospace text-nowrap">
                                            <span class="text-info text-nowrap"><i class="fas fa-circle me-1" style="font-size: 8px;"></i> <?= __('Total') ?>: <strong x-text="summary.total"></strong></span>
                                            <span class="text-danger text-nowrap"><i class="fas fa-circle me-1" style="font-size: 8px;"></i> <?= __('Errors') ?>: <strong x-text="summary.errors"></strong></span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 position-relative" style="min-height: 260px;">
                                        <canvas id="chartLogVolume"></canvas>
                                    </div>
                                </div>
                            </div>

                            <!-- Doughnut Chart: Severity Breakdown -->
                            <div class="col-12 col-xl-4">
                                <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-10 h-100 d-flex flex-column">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <span class="text-secondary small fw-bold text-uppercase d-flex align-items-center gap-2 text-nowrap">
                                            <i class="fas fa-chart-pie text-warning"></i> <?= __('Severity Breakdown') ?>
                                        </span>
                                    </div>
                                    <div class="flex-grow-1 position-relative d-flex align-items-center justify-content-center" style="min-height: 260px;">
                                        <canvas id="chartSeverityBreakdown"></canvas>
                                        <div x-show="severityEmpty" class="position-absolute text-center text-secondary small px-3">
                                            <i class="fas fa-info-circle mb-1 opacity-50 fs-5 d-block"></i>
                                            <?= __('No log data available for this timeframe.') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bar Chart: Logs by Application -->
                            <div class="col-12">
                                <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-10">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <span class="text-secondary small fw-bold text-uppercase d-flex align-items-center gap-2 text-nowrap">
                                            <i class="fas fa-chart-bar text-primary"></i> <?= __('Logs by Application') ?>
                                        </span>
                                    </div>
                                    <div class="position-relative d-flex align-items-center justify-content-center" style="min-height: 220px; max-height: 320px;">
                                        <div x-show="!appsEmpty" class="w-100 h-100">
                                            <canvas id="chartAppsDistribution"></canvas>
                                        </div>
                                        <div x-show="appsEmpty" class="position-absolute text-center text-secondary small p-4">
                                            <i class="fas fa-inbox mb-2 opacity-50 fs-2 d-block"></i>
                                            <span><?= __('No log data available for this timeframe.') ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left Column (Activity Tables) -->
                    <div class="col-lg-8 d-flex flex-column gap-4">
                        <!-- Latest Log Activity -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center px-4 pt-4">
                                <h3 class="h6 mb-0 text-light fw-bold text-uppercase tracking-wider"><?= __('Latest Log Activity') ?></h3>
                                <a href="logs.php" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 fw-bold hover-scale transition-all" style="font-size: 0.8rem; border-color: rgba(6, 182, 212, 0.4); color: #06b6d4;">
                                    <?= __('View History') ?> <i class="fas fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover mb-0 align-middle">
                                        <thead class="bg-dark text-secondary text-uppercase small" style="font-size: 0.75rem;">
                                            <tr>
                                                <th class="ps-4 border-0"><?= __('Application') ?></th>
                                                <th class="border-0"><?= __('Level') ?></th>
                                                <th class="border-0"><?= __('Message') ?></th>
                                                <th class="border-0 text-end pe-4"><?= __('Logged At') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($latestLogs as $log) { ?>
                                                <?php
                                                $badgeClass = match (true) {
                                                    in_array($log['level'], ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT']) => 'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25',
                                                    $log['level'] === 'WARNING' => 'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25',
                                                    $log['level'] === 'INFO' => 'bg-info bg-opacity-10 text-info border-info border-opacity-25',
                                                    $log['level'] === 'NOTICE' => 'bg-success bg-opacity-10 text-success border-success border-opacity-25',
                                                    default => 'bg-secondary bg-opacity-10 text-secondary border-secondary border-opacity-25'
                                                };
                                                ?>
                                                <tr>
                                                    <td class="ps-4 border-secondary border-opacity-10 font-monospace text-light">
                                                        <?= htmlspecialchars((string) ($log['app_name'] ?? 'Unknown')) ?>
                                                    </td>
                                                    <td class="border-secondary border-opacity-10">
                                                        <span class="badge border rounded-pill px-2.5 py-0.5 small <?= $badgeClass ?>">
                                                            <?= $log['level'] ?? 'INFO' ?>
                                                        </span>
                                                    </td>
                                                    <td class="border-secondary border-opacity-10 text-truncate font-monospace" style="max-width: 250px;" title="<?= htmlspecialchars((string) ($log['message'] ?? '')) ?>">
                                                        <?= htmlspecialchars((string) ($log['message'] ?? '')) ?>
                                                    </td>
                                                    <td class="text-end pe-4 border-secondary border-opacity-10 text-secondary font-monospace">
                                                        <?= date('H:i:s d.m.Y', strtotime($log['created_at'])) ?>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                            <?php if (empty($latestLogs)) { ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-5 text-secondary">
                                                        <i class="fas fa-inbox d-block fs-2 mb-3 opacity-25"></i>
                                                        <?= __('No logs recorded yet.') ?>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Top Apps by Log Volume -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-transparent border-0 px-4 pt-4">
                                <h3 class="h6 mb-0 text-light fw-bold text-uppercase tracking-wider"><?= __('Top Apps by Log Volume') ?></h3>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover mb-0 align-middle">
                                        <thead class="bg-dark text-secondary text-uppercase small" style="font-size: 0.75rem;">
                                            <tr>
                                                <th class="ps-4 border-0"><?= __('Application') ?></th>
                                                <th class="border-0"><?= __('Logs Count') ?></th>
                                                <th class="border-0 text-end pe-4"><?= __('Last Active') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topApps as $app) { ?>
                                                <tr>
                                                    <td class="ps-4 border-secondary border-opacity-10 font-monospace text-light fw-bold">
                                                        <?= htmlspecialchars((string) ($app['app_name'] ?? 'Unknown')) ?>
                                                    </td>
                                                    <td class="border-secondary border-opacity-10 text-info font-monospace fw-bold">
                                                        <?= number_format($app['log_count'] ?? 0) ?>
                                                    </td>
                                                    <td class="text-end pe-4 border-secondary border-opacity-10 text-secondary font-monospace">
                                                        <?= $app['last_logged_at'] ? date('H:i:s d.m.Y', strtotime($app['last_logged_at'])) : __('Never') ?>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                            <?php if (empty($topApps)) { ?>
                                                <tr>
                                                    <td colspan="3" class="text-center py-5 text-secondary">
                                                        <i class="fas fa-inbox d-block fs-2 mb-3 opacity-25"></i>
                                                        <?= __('No applications registered.') ?>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column (Severity, Queue Preview, Actions) -->
                    <div class="col-lg-4 d-flex flex-column gap-4">
                        <!-- Severity Distribution -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-transparent border-0 px-4 pt-4">
                                <h3 class="h6 mb-0 text-light fw-bold text-uppercase tracking-wider"><?= __('Severity Distribution') ?></h3>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="d-flex flex-column gap-3">
                                    <!-- Error / Critical -->
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-1 small text-secondary">
                                            <span class="fw-semibold text-danger"><i class="fas fa-exclamation-triangle me-1"></i> <?= __('Error / Critical') ?></span>
                                            <span class="font-monospace fw-bold text-light"><?= $errorLogs ?> (<?= $errorPct ?>%)</span>
                                        </div>
                                        <div class="progress bg-dark bg-opacity-50" style="height: 6px;">
                                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $errorPct ?>%" aria-valuenow="<?= $errorPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>

                                    <!-- Warning -->
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-1 small text-secondary">
                                            <span class="fw-semibold text-warning"><i class="fas fa-bell me-1"></i> <?= __('Warning') ?></span>
                                            <span class="font-monospace fw-bold text-light"><?= $warningLogs ?> (<?= $warningPct ?>%)</span>
                                        </div>
                                        <div class="progress bg-dark bg-opacity-50" style="height: 6px;">
                                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $warningPct ?>%" aria-valuenow="<?= $warningPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>

                                    <!-- Info / Debug -->
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-1 small text-secondary">
                                            <span class="fw-semibold text-info"><i class="fas fa-info-circle me-1"></i> <?= __('Info / Debug') ?></span>
                                            <span class="font-monospace fw-bold text-light"><?= $infoLogs ?> (<?= $infoPct ?>%)</span>
                                        </div>
                                        <div class="progress bg-dark bg-opacity-50" style="height: 6px;">
                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $infoPct ?>%" aria-valuenow="<?= $infoPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pending Queue Preview -->
                        <div class="card border-0 shadow-sm bg-dark bg-opacity-50">
                            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center px-4 pt-4">
                                <h3 class="h6 mb-0 text-light fw-bold text-uppercase tracking-wider"><?= __('Pending Queue Preview') ?></h3>
                                <a href="queue.php" class="btn btn-xs btn-link text-info p-0 hover-scale transition-all" style="font-size: 0.85rem; text-decoration: none;">
                                    <?= __('View') ?> <i class="fas fa-chevron-right ms-1"></i>
                                </a>
                            </div>
                            <div class="card-body px-4 pb-4 pt-2">
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($pendingQueue as $job) { ?>
                                        <div class="d-flex justify-content-between align-items-center p-2 rounded bg-black bg-opacity-25 border border-secondary border-opacity-10">
                                            <span class="badge bg-secondary bg-opacity-25 text-light font-monospace text-truncate" style="max-width: 140px;">
                                                <?= htmlspecialchars((string) ($job['app_name'] ?? 'Unknown')) ?>
                                            </span>
                                            <span class="text-secondary font-monospace small" style="font-size: 11px;">
                                                <?= date('H:i:s', strtotime($job['created_at'])) ?>
                                            </span>
                                        </div>
                                    <?php } ?>
                                    <?php if (empty($pendingQueue)) { ?>
                                        <div class="text-center py-4 text-secondary small">
                                            <?= __('No pending jobs in the queue.') ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-transparent border-0 px-4 pt-4">
                                <h3 class="h6 mb-0 text-light fw-bold text-uppercase tracking-wider"><?= __('Quick Actions') ?></h3>
                            </div>
                            <div class="card-body px-4 pb-4 pt-2 d-flex flex-column gap-3">
                                <!-- Register App -->
                                <a href="apps.php" class="btn btn-info w-100 py-2.5 px-3 rounded-3 d-flex align-items-center justify-content-between shadow-sm fw-bold hover-scale transition-all text-white">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="fas fa-plus-circle fs-5"></i>
                                        <span><?= __('Register New App') ?></span>
                                    </span>
                                    <i class="fas fa-chevron-right"></i>
                                </a>

                                <!-- View Active Queue -->
                                <a href="queue.php" class="btn btn-dark w-100 py-2.5 px-3 rounded-3 d-flex align-items-center justify-content-between border border-secondary border-opacity-25 fw-bold hover-scale transition-all text-light" style="background: rgba(15, 23, 42, 0.45);">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="fas fa-tasks fs-5 text-warning"></i>
                                        <span><?= __('View Active Queue') ?></span>
                                    </span>
                                    <i class="fas fa-chevron-right text-secondary"></i>
                                </a>

                                <!-- View Logs History -->
                                <a href="logs.php" class="btn btn-dark w-100 py-2.5 px-3 rounded-3 d-flex align-items-center justify-content-between border border-secondary border-opacity-25 fw-bold hover-scale transition-all text-light" style="background: rgba(15, 23, 42, 0.45);">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="fas fa-history fs-5 text-info"></i>
                                        <span><?= __('View Logs History') ?></span>
                                    </span>
                                    <i class="fas fa-chevron-right text-secondary"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js" nonce="<?= APP_NONCE ?>"></script>
<script nonce="<?= APP_NONCE ?>">
function dashboardAnalytics(initialData) {
    return {
        period: initialData.period || 'day',
        rangeLabel: (initialData.range && initialData.range.label) || '',
        summary: initialData.summary || { total: 0, errors: 0 },
        customStart: new Date(Date.now() - 7 * 86400000).toISOString().split('T')[0],
        customEnd: new Date().toISOString().split('T')[0],
        loading: false,
        severityEmpty: !initialData.severityPie || !initialData.severityPie.data || initialData.severityPie.data.length === 0,
        appsEmpty: !initialData.appsBar || !initialData.appsBar.data || initialData.appsBar.data.length === 0,
        volumeChart: null,
        severityChart: null,
        appsChart: null,

        initCharts() {
            this.$nextTick(() => {
                if (typeof Chart === 'undefined') {
                    console.error('Chart.js is not loaded');
                    return;
                }
                this.buildVolumeChart(initialData.lineChart || { labels: [], total: [], errors: [] });
                this.buildSeverityChart(initialData.severityPie || { labels: [], data: [], colors: [] });
                this.buildAppsChart(initialData.appsBar || { labels: [], data: [] });
            });
        },

        selectPeriod(p) {
            this.period = p;
            if (p !== 'custom') {
                this.fetchData();
            }
        },

        applyCustomRange() {
            if (this.customStart && this.customEnd) {
                this.fetchData();
            }
        },

        async fetchData() {
            this.loading = true;
            try {
                let url = 'index.php?action=chart-data&period=' + encodeURIComponent(this.period);
                if (this.period === 'custom') {
                    url += '&start_date=' + encodeURIComponent(this.customStart) + '&end_date=' + encodeURIComponent(this.customEnd);
                }

                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!res.ok) throw new Error('Network error fetching chart data');
                const data = await res.json();

                this.rangeLabel = data.range ? data.range.label : '';
                this.summary = data.summary || { total: 0, errors: 0 };
                this.severityEmpty = !data.severityPie || !data.severityPie.data || data.severityPie.data.length === 0;
                this.appsEmpty = !data.appsBar || !data.appsBar.data || data.appsBar.data.length === 0;

                this.updateVolumeChart(data.lineChart || { labels: [], total: [], errors: [] });
                this.updateSeverityChart(data.severityPie || { labels: [], data: [], colors: [] });
                this.updateAppsChart(data.appsBar || { labels: [], data: [] });
            } catch (err) {
                console.error('Failed to load chart data:', err);
            } finally {
                this.loading = false;
            }
        },

        buildVolumeChart(data) {
            const ctx = document.getElementById('chartLogVolume');
            if (!ctx) return;

            const gridColor = 'rgba(255, 255, 255, 0.05)';
            const tickColor = '#94a3b8';

            this.volumeChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: window.__ ? window.__('Total Logs') : 'Total Logs',
                            data: data.total,
                            borderColor: '#06b6d4',
                            backgroundColor: 'rgba(6, 182, 212, 0.12)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                        },
                        {
                            label: window.__ ? window.__('Error / Critical') : 'Errors',
                            data: data.errors,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.12)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            padding: 10,
                            boxPadding: 4,
                            usePointStyle: true,
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: gridColor },
                            ticks: {
                                color: tickColor,
                                font: { size: 10 },
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 12,
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: gridColor },
                            ticks: {
                                color: tickColor,
                                font: { size: 10 },
                                precision: 0,
                            }
                        }
                    }
                }
            });
        },

        updateVolumeChart(data) {
            if (!this.volumeChart) {
                this.buildVolumeChart(data);
                return;
            }
            this.volumeChart.data.labels = data.labels;
            this.volumeChart.data.datasets[0].data = data.total;
            this.volumeChart.data.datasets[1].data = data.errors;
            this.volumeChart.update();
        },

        buildSeverityChart(data) {
            const ctx = document.getElementById('chartSeverityBreakdown');
            if (!ctx) return;

            const hasData = data && data.data && data.data.length > 0;
            const labels = hasData ? data.labels : ['No Data'];
            const counts = hasData ? data.data : [1];
            const colors = hasData ? data.colors : ['rgba(255, 255, 255, 0.05)'];

            this.severityChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#0f172a',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#94a3b8',
                                boxWidth: 10,
                                padding: 12,
                                font: { size: 11 },
                            }
                        },
                        tooltip: {
                            enabled: hasData,
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            padding: 10,
                        }
                    }
                }
            });
        },

        updateSeverityChart(data) {
            if (!this.severityChart) {
                this.buildSeverityChart(data);
                return;
            }
            const hasData = data && data.data && data.data.length > 0;
            this.severityChart.data.labels = hasData ? data.labels : ['No Data'];
            this.severityChart.data.datasets[0].data = hasData ? data.data : [1];
            this.severityChart.data.datasets[0].backgroundColor = hasData ? data.colors : ['rgba(255, 255, 255, 0.05)'];
            this.severityChart.options.plugins.tooltip.enabled = hasData;
            this.severityChart.update();
        },

        buildAppsChart(data) {
            const ctx = document.getElementById('chartAppsDistribution');
            if (!ctx) return;

            this.appsChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: window.__ ? window.__('Logs Count') : 'Logs Count',
                        data: data.data,
                        backgroundColor: 'rgba(59, 130, 246, 0.75)',
                        hoverBackgroundColor: '#3b82f6',
                        borderRadius: 4,
                        barThickness: 16,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            padding: 10,
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: {
                                color: '#94a3b8',
                                font: { size: 10 },
                                precision: 0,
                            }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                color: '#e2e8f0',
                                font: { size: 11, family: 'monospace' },
                            }
                        }
                    }
                }
            });
        },

        updateAppsChart(data) {
            if (!this.appsChart) {
                this.buildAppsChart(data);
                return;
            }
            this.appsChart.data.labels = data.labels;
            this.appsChart.data.datasets[0].data = data.data;
            this.appsChart.update();
            this.$nextTick(() => {
                if (this.appsChart) this.appsChart.resize();
            });
        }
    };
}
</script>

<?php include __DIR__.'/../layouts/footer.php'; ?>
