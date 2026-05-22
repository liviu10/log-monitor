<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="{ sidebarOpen: true }">
    <div class="row g-0 vh-100">
        <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col h-100 overflow-auto bg-dark bg-opacity-25">
            <header class="navbar navbar-expand-lg border-bottom border-secondary border-opacity-25 px-4 py-3 sticky-top bg-dark bg-opacity-75 backdrop-blur">
                <button class="btn btn-dark btn-sm me-3" @click="sidebarOpen = !sidebarOpen">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center justify-content-between w-100">
                    <h2 class="h5 mb-0 fw-bold text-light">Manage Applications</h2>
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
                <!-- Flash Messages -->
                <?php if ($flash = getFlash()): ?>
                    <div class="alert alert-<?= $flash['type'] ?? 'info' ?> alert-dismissible fade show" role="alert">
                        <strong><?= $flash['title'] ?? 'Notificare' ?></strong>: <?= $flash['message'] ?? '' ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Create App Form -->
                    <div class="col-md-4">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body p-4">
                                <h3 class="h5 mb-4 text-light fw-bold">Register New App</h3>
                                <form action="apps.php" method="POST">
                                    <div class="mb-3">
                                        <label class="form-label text-secondary small fw-medium">Application Name</label>
                                        <input type="text" name="name" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="e.g. Mobile API" required minlength="3">
                                    </div>
                                    <button type="submit" class="btn btn-info w-100 fw-bold">
                                        <i class="fas fa-plus me-2"></i> Create Application
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Apps Table -->
                    <div class="col-md-8">
                        <div class="card shadow-sm border-0">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover mb-0 align-middle">
                                        <thead class="bg-dark text-secondary small text-uppercase">
                                            <tr>
                                                <th class="ps-4 border-0">App Name</th>
                                                <th class="border-0">API Key</th>
                                                <th class="border-0 text-end pe-4">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($apps as $app): ?>
                                                <tr>
                                                    <td class="ps-4 border-secondary border-opacity-10">
                                                        <span class="text-light fw-medium"><?= htmlspecialchars((string)$app['name']) ?></span>
                                                    </td>
                                                    <td class="border-secondary border-opacity-10">
                                                        <div class="input-group input-group-sm" style="max-width: 300px;">
                                                            <input type="text" class="form-control bg-dark border-secondary border-opacity-25 text-info font-monospace" value="<?= htmlspecialchars((string)$app['api_key']) ?>" readonly id="key-<?= $app['id'] ?>">
                                                            <button class="btn btn-outline-secondary border-opacity-25" type="button" @click="navigator.clipboard.writeText('<?= $app['api_key'] ?>')">
                                                                <i class="far fa-copy"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                    <td class="text-end pe-4 border-secondary border-opacity-10">
                                                        <form action="apps.php?action=delete" method="POST" onsubmit="return confirm('Sigur dorești să ștergi această aplicație? Logurile asociate vor rămâne dar nu vei mai putea trimite altele noi cu această cheie.')">
                                                            <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                                            <button type="submit" class="btn btn-link text-danger p-0">
                                                                <i class="far fa-trash-alt"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($apps)): ?>
                                                <tr>
                                                    <td colspan="3" class="text-center py-4 text-secondary">No applications registered yet.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
