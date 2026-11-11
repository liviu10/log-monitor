<?php include __DIR__.'/../layouts/header.php'; ?>

<div class="position-absolute top-0 end-0 p-3" style="z-index: 1050;">
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
</div>

<div class="d-flex align-items-center justify-content-center vh-100 p-3 position-relative overflow-hidden" x-data="{ showPassword: false }">
    <div class="login-bg-glow"></div>
    <div class="card shadow-lg border-0" style="max-width: 420px; width: 100%;">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-info bg-opacity-10 rounded-4 mb-3 border border-info border-opacity-20" style="width: 68px; height: 68px;">
                    <i class="fas fa-terminal text-info fs-3"></i>
                </div>
                <h1 class="h4 fw-bold mb-1 text-light"><?= APP_NAME ?></h1>
                <p class="text-secondary small mb-0"><?= __('Secure Admin Panel Access') ?></p>
            </div>

            <?php if (isset($_GET['expired'])) { ?>
                <div class="alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning d-flex align-items-center gap-2 mb-4 py-2.5 px-3 rounded-3 small">
                    <i class="fas fa-hourglass-end flex-shrink-0"></i>
                    <span><?= __('Your session has expired due to inactivity. Please log in again.') ?></span>
                </div>
            <?php } elseif (isset($_GET['logged_out'])) { ?>
                <div class="alert alert-info border-info border-opacity-25 bg-info bg-opacity-10 text-info d-flex align-items-center gap-2 mb-4 py-2.5 px-3 rounded-3 small">
                    <i class="fas fa-check-circle flex-shrink-0"></i>
                    <span><?= __('You have been logged out successfully.') ?></span>
                </div>
            <?php } ?>

            <?php if (! empty($_SESSION['errors'])) { ?>
                <div class="alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger mb-4 py-2.5 px-3 rounded-3 small">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($_SESSION['errors'] as $errorGroup) {
                            foreach ((array) $errorGroup as $errorMsg) { ?>
                                <li><?= htmlspecialchars((string) $errorMsg) ?></li>
                            <?php }
                            } ?>
                    </ul>
                </div>
                <?php unset($_SESSION['errors']); ?>
            <?php } ?>

            <form action="login.php" method="POST">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label text-secondary small fw-medium"><?= __('Username') ?></label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                            <i class="far fa-user"></i>
                        </span>
                        <input type="text" name="username" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="<?= __('Username') ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium"><?= __('Password') ?></label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input :type="showPassword ? 'text' : 'password'" name="password" class="form-control bg-dark border-secondary border-opacity-25 text-light" placeholder="••••••••" required>
                        <button type="button" class="btn btn-dark border border-secondary border-opacity-25 text-secondary" @click="showPassword = !showPassword" aria-label="Toggle password visibility">
                            <i class="far" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-info w-100 py-2.5 fw-bold shadow-sm">
                    <?= __('Login') ?> <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </form>
        </div>
    </div>
</div>


<?php include __DIR__.'/../layouts/footer.php'; ?>
