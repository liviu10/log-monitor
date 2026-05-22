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
                    <h2 class="h5 mb-0 fw-bold text-light">Manage Admin Users</h2>
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
                    <!-- Create User Form -->
                    <div class="col-md-4">
                        <div class="card shadow-sm border-0 h-100 bg-slate-800">
                            <div class="card-bocdy p-4">
                                <h3 class="h5 mb-4 text-light fw-bold">Add New Administrator</h3>
                                <form action="users.php" method="POST">
                                    <div class="mb-3">
                                        <label class="form-label text-secondary small fw-medium">Username</label>
                                        <input type="text" name="username" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="e.g. john_doe" required minlength="3">
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label text-secondary small fw-medium">Password</label>
                                        <input type="password" name="password" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="Min. 6 characters" required minlength="6">
                                    </div>
                                    <button type="submit" class="btn btn-info w-100 fw-bold">
                                        <i class="fas fa-user-plus me-2"></i> Create User
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Users List -->
                    <div class="col-md-8">
                        <div class="card shadow-sm border-0 h-100 bg-slate-800">
                            <div class="card-body p-0">
                                <div class="p-4 border-bottom border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                                    <h3 class="h5 mb-0 text-light fw-bold">System Administrators</h3>
                                    <span class="badge bg-dark text-secondary border border-secondary border-opacity-25 rounded-pill"><?= count($users ?? []) ?> Total</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-black bg-opacity-25 text-secondary small text-uppercase">
                                            <tr>
                                                <th class="ps-4 border-0">Username</th>
                                                <th class="border-0">Status</th>
                                                <th class="border-0" style="width: 200px;">Created At</th>
                                                <th class="pe-4 text-end border-0" style="width: 100px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($users ?? [] as $user): ?>
                                                <tr class="border-secondary border-opacity-10">
                                                    <td class="ps-4">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <div class="avatar bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                                                <i class="fas fa-user small"></i>
                                                            </div>
                                                            <span class="fw-bold text-light"><?= htmlspecialchars((string)($user['username'] ?? '')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Active</span>
                                                    </td>
                                                    <td class="text-secondary small font-monospace"><?= $user['created_at'] ?? '-' ?></td>
                                                    <td class="pe-4 text-end">
                                                        <?php if ((int)($user['id'] ?? 0) !== (int)($_SESSION['auth.user']['id'] ?? 0)): ?>
                                                            <form action="users.php?action=delete" method="POST" onsubmit="return confirm('Sigur dorești să ștergi acest utilizator?')">
                                                                <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm border-0">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary opacity-25">Self</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
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
