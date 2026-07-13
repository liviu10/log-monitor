<!-- Sidebar -->
<div class="col-auto h-100 bg-dark border-end border-secondary border-opacity-25 transition-all" 
     :style="{ width: sidebarOpen ? '240px' : '0px', visibility: sidebarOpen ? 'visible' : 'hidden' }">
    <div class="p-4">
        <a href="index.php" class="d-flex align-items-center gap-2 text-decoration-none text-light mb-5">
            <i class="fas fa-terminal text-info fs-4"></i>
            <span class="fw-bold h5 mb-0">LogMonitor</span>
        </a>

        <nav class="nav flex-column gap-2">
            <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
            
            <a href="index.php" class="nav-link rounded-3 px-3 py-2 <?= $currentPage === 'index.php' ? 'active bg-info bg-opacity-10 text-info' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-chart-pie me-2"></i> <?= __('Dashboard') ?>
            </a>
            
            <a href="logs.php" class="nav-link rounded-3 px-3 py-2 <?= $currentPage === 'logs.php' ? 'active bg-info bg-opacity-10 text-info' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-list-ul me-2"></i> <?= __('Logs') ?>
            </a>
            
            <a href="queue.php" class="nav-link rounded-3 px-3 py-2 <?= $currentPage === 'queue.php' ? 'active bg-info bg-opacity-10 text-info' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-tasks me-2"></i> <?= __('Queue') ?>
            </a>
            
            <a href="apps.php" class="nav-link rounded-3 px-3 py-2 <?= $currentPage === 'apps.php' ? 'active bg-info bg-opacity-10 text-info' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-microchip me-2"></i> <?= __('Apps') ?>
            </a>
            
            <a href="users.php" class="nav-link rounded-3 px-3 py-2 <?= $currentPage === 'users.php' ? 'active bg-info bg-opacity-10 text-info' : 'text-secondary hover-bg-light' ?>">
                <i class="fas fa-users me-2"></i> <?= __('Users') ?>
            </a>
        </nav>
    </div>
</div>

