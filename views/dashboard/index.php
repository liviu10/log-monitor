<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="{ 
    sidebarOpen: true, 
    selectedLog: null,
    showModal: false,
    openLog(log) {
        this.selectedLog = log;
        this.showModal = true;
    }
}">
    <div class="row g-0 vh-100">
        <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col h-100 overflow-auto bg-dark bg-opacity-25">
            <header class="navbar navbar-expand-lg border-bottom border-secondary border-opacity-25 px-4 py-3 sticky-top bg-dark bg-opacity-75 backdrop-blur">
                <button class="btn btn-dark btn-sm me-3" @click="sidebarOpen = !sidebarOpen">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h2 class="h5 mb-0 fw-bold text-light">System Logs</h2>
                    <div class="dropdown">
                        <button class="btn btn-dark btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="far fa-user-circle me-2 text-info"></i> <?= htmlspecialchars($_SESSION['auth.user']['username'] ?? 'User') ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="login.php?action=logout">Deconectare</a></li>
                        </ul>
                    </div>
                </div>
            </header>

            <main class="p-4">
                <!-- Filters -->
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-body">
                        <form action="index.php" method="GET" class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label text-secondary small fw-medium">Căutare</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                                        <i class="fas fa-search"></i>
                                    </span>
                                    <input type="text" name="search" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="Căutare în mesaje sau context..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-secondary small fw-medium">Aplicație</label>
                                <select name="app_id" class="form-select bg-dark border-secondary border-opacity-25 text-light">
                                    <option value="">Toate</option>
                                    <?php foreach ($apps as $app): ?>
                                        <option value="<?= $app['id'] ?>" <?= ((string)($filters['app_id'] ?? '') === (string)$app['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string)$app['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-secondary small fw-medium">Nivel</label>
                                <select name="level" class="form-select bg-dark border-secondary border-opacity-25 text-light">
                                    <option value="">Toate</option>
                                    <?php foreach ($levels as $level): ?>
                                        <option value="<?= $level ?>" <?= (($filters['level'] ?? '') === $level) ? 'selected' : '' ?>><?= $level ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-auto d-flex gap-2">
                                <button type="submit" class="btn btn-info px-4 fw-bold">Filtrează</button>
                                <a href="index.php" class="btn btn-outline-secondary px-4">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Logs Table -->
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="bg-black bg-opacity-25 text-secondary small text-uppercase">
                                <tr>
                                    <th class="ps-4 border-0" style="width: 150px;">Application</th>
                                    <th class="border-0" style="width: 120px;">Level</th>
                                    <th class="border-0">Message</th>
                                    <th class="border-0" style="width: 200px;">Timestamp</th>
                                    <th class="pe-4 text-end border-0" style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-secondary border-0">
                                            <i class="fas fa-inbox d-block fs-1 mb-3 opacity-25"></i>
                                            Nu s-au găsit loguri pentru filtrele selectate.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($logs as $log): ?>
                                    <?php 
                                        $badgeClass = match (true) {
                                            in_array($log['level'], ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT']) => 'bg-danger',
                                            $log['level'] === 'WARNING' => 'bg-warning text-dark',
                                            $log['level'] === 'INFO' => 'bg-info text-dark',
                                            $log['level'] === 'NOTICE' => 'bg-success',
                                            default => 'bg-secondary'
                                        };
                                        
                                        $logJson = json_encode([
                                            'app' => $log['app_name'] ?? 'Unknown',
                                            'level' => $log['level'] ?? 'INFO',
                                            'message' => $log['message'] ?? '',
                                            'timestamp' => $log['created_at'] ?? '',
                                            'context' => $log['context'] ? json_decode((string)$log['context'], true) : null
                                        ]);
                                    ?>
                                    <tr class="border-secondary border-opacity-10">
                                        <td class="ps-4 fw-medium text-light"><?= htmlspecialchars((string)($log['app_name'] ?? 'Unknown')) ?></td>
                                        <td><span class="badge <?= $badgeClass ?> rounded-pill px-2 py-1"><?= $log['level'] ?? 'INFO' ?></span></td>
                                        <td>
                                            <div class="text-light opacity-75 text-truncate" style="max-width: 400px;"><?= htmlspecialchars((string)($log['message'] ?? '')) ?></div>
                                        </td>
                                        <td class="text-secondary small font-monospace"><?= $log['created_at'] ?? '-' ?></td>
                                        <td class="pe-4 text-end">
                                            <button @click="openLog(<?= htmlspecialchars($logJson) ?>)" class="btn btn-outline-info btn-sm px-3">
                                                <i class="fas fa-eye me-1"></i> Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if (($totalPages ?? 0) > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center gap-1">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= ((int)($page ?? 1) === $i) ? 'active' : '' ?>">
                                    <a class="page-link rounded bg-dark border-secondary border-opacity-25 text-light" href="?page=<?= $i ?>&<?= http_build_query(array_filter($filters ?? [])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Details Modal -->
    <template x-if="showModal">
        <div class="modal d-block shadow-lg" tabindex="-1" style="background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);" @click.self="showModal = false">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content bg-dark border-secondary shadow-lg">
                    <div class="modal-header border-secondary border-opacity-25 bg-black bg-opacity-25">
                        <h5 class="modal-title text-light fw-bold">
                            <i class="fas fa-info-circle text-info me-2"></i> Log Entry Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" @click="showModal = false"></button>
                    </div>
                    <div class="modal-body p-4" x-show="selectedLog">
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <label class="text-secondary small fw-bold text-uppercase d-block mb-1">Application</label>
                                <div class="text-light fs-5" x-text="selectedLog?.app"></div>
                            </div>
                            <div class="col-md-3">
                                <label class="text-secondary small fw-bold text-uppercase d-block mb-1">Level</label>
                                <span class="badge rounded-pill px-3 py-2" 
                                      :class="{
                                          'bg-danger': ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'].includes(selectedLog?.level),
                                          'bg-warning text-dark': selectedLog?.level === 'WARNING',
                                          'bg-info text-dark': selectedLog?.level === 'INFO',
                                          'bg-success': selectedLog?.level === 'NOTICE',
                                          'bg-secondary': !['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT', 'WARNING', 'INFO', 'NOTICE'].includes(selectedLog?.level)
                                      }"
                                      x-text="selectedLog?.level"></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-secondary small fw-bold text-uppercase d-block mb-1">Timestamp</label>
                                <div class="text-secondary font-monospace" x-text="selectedLog?.timestamp"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">Message</label>
                            <div class="p-3 bg-black bg-opacity-25 rounded border border-secondary border-opacity-10 text-light lead" x-text="selectedLog?.message"></div>
                        </div>

                        <div>
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">Context (JSON)</label>
                            <div class="bg-black bg-opacity-50 p-3 rounded border border-secondary border-opacity-25 overflow-auto" style="max-height: 400px;">
                                <pre class="mb-0 text-info small"><code x-text="JSON.stringify(selectedLog?.context, null, 4)"></code></pre>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary border-opacity-25 bg-black bg-opacity-10">
                        <button type="button" class="btn btn-secondary px-4" @click="showModal = false">Close</button>
                        <button type="button" class="btn btn-info px-4" @click="navigator.clipboard.writeText(JSON.stringify(selectedLog, null, 4)); alert('Copied to clipboard!')">
                            <i class="fas fa-copy me-2"></i> Copy Log
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<style>
    .backdrop-blur { backdrop-filter: blur(8px); }
    .pagination .page-link:hover { background-color: var(--bs-info) !important; color: black !important; }
    .pagination .page-item.active .page-link { background-color: var(--bs-info) !important; border-color: var(--bs-info) !important; color: black !important; font-weight: bold; }
</style>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
