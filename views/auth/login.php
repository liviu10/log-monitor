<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="d-flex align-items-center justify-content-center vh-100 p-3">
    <div class="card shadow-lg border-0 bg-slate-800" style="max-width: 400px; width: 100%;">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-info bg-opacity-10 rounded-4 mb-3" style="width: 64px; height: 64px;">
                    <i class="fas fa-terminal text-info fs-3"></i>
                </div>
                <h1 class="h4 fw-bold mb-1"><?= APP_NAME ?></h1>
                <p class="text-secondary small">Acces Securizat Panou Admin</p>
            </div>

            <!-- Flash Messages -->
            <?php if ($flash = getFlash()): ?>
                <div class="alert alert-<?= $flash['type'] ?? 'info' ?> small mb-4" role="alert">
                    <?= $flash['message'] ?? '' ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label text-secondary small fw-medium">Utilizator</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                            <i class="far fa-user"></i>
                        </span>
                        <input type="text" name="username" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="Username" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Parolă</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="password" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-info w-100 py-2 fw-bold">
                    Autentificare <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<style>
    .bg-slate-800 { background-color: #1e293b; }
</style>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
