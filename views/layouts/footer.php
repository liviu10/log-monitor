    <?php if (isset($_SESSION['auth.user'])) { ?>
    <!-- Session Timeout Warning Modal -->
    <div class="modal fade" id="sessionTimeoutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="sessionTimeoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-warning border-opacity-25 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <div class="d-flex align-items-center gap-2 text-warning">
                        <i class="fas fa-hourglass-half fs-5"></i>
                        <h5 class="modal-title h6 fw-bold mb-0" id="sessionTimeoutModalLabel"><?= __('Session Warning') ?></h5>
                    </div>
                </div>
                <div class="modal-body text-center py-4">
                    <p class="text-secondary small mb-3">
                        <?= __('Your session is about to expire due to inactivity.') ?>
                    </p>
                    <div class="display-6 fw-bold font-monospace text-warning mb-2" id="sessionCountdownTimer">120</div>
                    <span class="text-secondary small"><?= __('seconds remaining') ?></span>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex gap-2">
                    <a href="logout.php" class="btn btn-sm btn-dark flex-fill">
                        <i class="fas fa-sign-out-alt me-1"></i> <?= __('Logout') ?>
                    </a>
                    <button type="button" class="btn btn-sm btn-info flex-fill fw-bold" id="sessionStayLoggedInBtn">
                        <i class="fas fa-redo-alt me-1"></i> <?= __('Stay Logged In') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="assets/app.js?v=<?= time() ?>"></script>

    <script nonce="<?= APP_NONCE ?>">
        document.addEventListener('DOMContentLoaded', function() {
            App.initSessionManager();

            <?php $flash = getFlash();
if ($flash) { ?>
                App.handleToast(<?= json_encode($flash) ?>);
            <?php } ?>

            <?php if (isset($_GET['logged_out'])) { ?>
                App.handleToast({
                    type: 'info',
                    title: window.__('Logged Out'),
                    message: window.__('You have been logged out successfully.'),
                    toastDelay: 4000
                });
            <?php } elseif (isset($_GET['expired'])) { ?>
                App.handleToast({
                    type: 'warning',
                    title: window.__('Session Expired'),
                    message: window.__('Your session has expired due to inactivity. Please log in again.'),
                    toastDelay: 6000
                });
            <?php } ?>
        });
    </script>
</body>
</html>