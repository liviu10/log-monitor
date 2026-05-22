    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- App JS -->
    <script src="assets/app.js?v=<?= time() ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php $flash = getFlash(); if ($flash): ?>
                App.handleToast(<?= json_encode($flash) ?>);
            <?php endif; ?>
        });
    </script>
</body>
</html>
