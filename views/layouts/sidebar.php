<!-- Mobile Sidebar Backdrop Overlay -->
<div class="sidebar-backdrop d-lg-none" 
     x-show="sidebarOpen" 
     @click="sidebarOpen = false" 
     x-transition:enter="transition-opacity ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak></div>

<!-- Sidebar Wrapper -->
<aside class="sidebar-wrapper d-flex flex-column justify-content-between h-100 bg-dark border-end border-secondary border-opacity-25" 
       :class="{ 'sidebar-open': sidebarOpen, 'sidebar-closed': !sidebarOpen }">
    <div class="p-3 p-xl-4 overflow-y-auto flex-grow-1">
        <!-- Brand Header -->
        <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom border-secondary border-opacity-10">
            <a href="index.php" class="d-flex align-items-center gap-2 text-decoration-none text-light">
                <div class="brand-icon-wrapper rounded-3 d-flex align-items-center justify-content-center bg-info bg-opacity-10 text-info border border-info border-opacity-25" style="width: 38px; height: 38px;">
                    <i class="fas fa-terminal fs-5"></i>
                </div>
                <div>
                    <span class="fw-bold h5 mb-0 text-white tracking-wide d-block">LogMonitor</span>
                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 py-0 px-1 font-monospace" style="font-size: 0.65rem;">v1.4</span>
                </div>
            </a>
            
            <!-- Mobile Close Button -->
            <button type="button" class="btn btn-sm btn-dark d-lg-none text-secondary" @click="sidebarOpen = false" aria-label="<?= __('Close') ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="nav flex-column gap-1">
            <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
            
            <a href="index.php" @click="if (window.innerWidth < 992) sidebarOpen = false"
               class="nav-link rounded-3 px-3 py-2.5 <?= $currentPage === 'index.php' ? 'active' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-chart-pie me-3 fs-6"></i>
                <span class="fw-medium"><?= __('Dashboard') ?></span>
            </a>
            
            <a href="logs.php" @click="if (window.innerWidth < 992) sidebarOpen = false"
               class="nav-link rounded-3 px-3 py-2.5 <?= $currentPage === 'logs.php' ? 'active' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-list-ul me-3 fs-6"></i>
                <span class="fw-medium"><?= __('Logs') ?></span>
            </a>
            
            <a href="queue.php" @click="if (window.innerWidth < 992) sidebarOpen = false"
               class="nav-link rounded-3 px-3 py-2.5 <?= $currentPage === 'queue.php' ? 'active' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-tasks me-3 fs-6"></i>
                <span class="fw-medium"><?= __('Queue') ?></span>
            </a>
            
            <a href="apps.php" @click="if (window.innerWidth < 992) sidebarOpen = false"
               class="nav-link rounded-3 px-3 py-2.5 <?= $currentPage === 'apps.php' ? 'active' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-microchip me-3 fs-6"></i>
                <span class="fw-medium"><?= __('Apps') ?></span>
            </a>
            
            <a href="users.php" @click="if (window.innerWidth < 992) sidebarOpen = false"
               class="nav-link rounded-3 px-3 py-2.5 <?= $currentPage === 'users.php' ? 'active' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-users me-3 fs-6"></i>
                <span class="fw-medium"><?= __('Users') ?></span>
            </a>
        </nav>
    </div>

    <!-- Sidebar Bottom User Profile & Logout -->
    <div class="p-3 border-top border-secondary border-opacity-15 bg-black bg-opacity-25">
        <div class="sidebar-user-card p-2 rounded-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <div class="user-avatar rounded-circle bg-info bg-opacity-15 text-info d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; min-width: 36px;">
                    <?= strtoupper(substr((string) ($_SESSION['auth.user']['username'] ?? 'U'), 0, 1)) ?>
                </div>
                <div class="overflow-hidden">
                    <div class="d-flex align-items-center gap-1.5">
                        <span class="status-dot-online"></span>
                        <span class="fw-bold text-white small text-truncate d-block" style="max-width: 105px;">
                            <?= htmlspecialchars((string) ($_SESSION['auth.user']['username'] ?? 'User')) ?>
                        </span>
                    </div>
                    <span class="text-secondary small d-block" style="font-size: 0.7rem;"><?= __('Administrator') ?></span>
                </div>
            </div>
            
            <!-- Quick Logout Action Button -->
            <a href="logout.php" class="btn btn-sm btn-outline-danger border-0 rounded-circle d-flex align-items-center justify-content-center p-2 hover-scale" 
               title="<?= __('Logout') ?>"
               data-bs-toggle="tooltip" 
               data-bs-placement="top">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</aside>
