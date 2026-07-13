<?php include __DIR__.'/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="{ sidebarOpen: true }">
    <div class="row g-0 vh-100">
        <?php include __DIR__.'/../layouts/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col h-100 overflow-auto bg-dark bg-opacity-25">
            <header class="navbar navbar-expand-lg border-bottom border-secondary border-opacity-25 px-4 py-3 sticky-top bg-dark bg-opacity-75 backdrop-blur" style="z-index: 100;">
                <button class="btn btn-dark btn-sm me-3" @click="sidebarOpen = !sidebarOpen">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h2 class="h5 mb-0 fw-bold text-light"><?= __('Dashboard') ?></h2>
                    
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
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
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
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
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
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm metric-card p-3 position-relative overflow-hidden">
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

<?php include __DIR__.'/../layouts/footer.php'; ?>
