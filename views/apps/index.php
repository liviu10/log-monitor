<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="container-fluid p-0 overflow-hidden" x-data="{
    sidebarOpen: true,
    showSettingsModal: false,
    currentAppId: null,
    currentAppName: '',
    settings: [],
    newKey: '',
    newValue: '',
    loading: false,
    saving: false,
    editingAppId: null,
    oldSettingKey: '',
    
    openSettings(appId, appName) {
        this.currentAppId = appId;
        this.currentAppName = appName;
        this.showSettingsModal = true;
        this.newKey = '';
        this.newValue = '';
        this.oldSettingKey = '';
        this.fetchSettings();
    },
    
    fetchSettings() {
        this.loading = true;
        fetch(`app-settings.php?app_id=${this.currentAppId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.settings = data.settings;
                } else {
                    App.handleToast({
                        type: 'danger',
                        title: 'Eroare',
                        message: data.message || 'Eroare la încărcarea setărilor.',
                        toastDelay: 5000
                    });
                }
            })
            .catch(err => {
                console.error(err);
                App.handleToast({
                    type: 'danger',
                    title: 'Eroare',
                    message: 'Eroare de rețea la încărcarea setărilor.',
                    toastDelay: 5000
                });
            })
            .finally(() => {
                this.loading = false;
            });
    },
    
    saveSetting() {
        if (!this.newKey.trim() || !this.newValue.trim()) {
            App.handleToast({
                type: 'warning',
                title: 'Atenționare',
                message: 'Cheia și valoarea sunt obligatorii.',
                toastDelay: 3000
            });
            return;
        }
        
        const keyPattern = /^[a-zA-Z0-9_\-\.]+$/;
        if (!keyPattern.test(this.newKey.trim())) {
            App.handleToast({
                type: 'warning',
                title: 'Format Invalid',
                message: 'Cheia poate conține doar litere, cifre, sublinieri (_), cratime (-) și puncte (.).',
                toastDelay: 5000
            });
            return;
        }
        
        this.saving = true;
        
        const formData = new FormData();
        formData.append('app_id', this.currentAppId);
        formData.append('key', this.newKey.trim());
        formData.append('value', this.newValue.trim());
        
        const url = this.oldSettingKey ? 'app-settings.php?action=update' : 'app-settings.php';
        if (this.oldSettingKey) {
            formData.append('old_key', this.oldSettingKey);
        }
        
        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                App.handleToast({
                    type: 'success',
                    title: 'Succes',
                    message: data.message,
                    toastDelay: 3000
                });
                this.newKey = '';
                this.newValue = '';
                this.oldSettingKey = '';
                this.fetchSettings();
            } else {
                App.handleToast({
                    type: 'danger',
                    title: 'Eroare',
                    message: data.message || 'Eroare la salvarea setării.',
                    toastDelay: 5000
                });
            }
        })
        .catch(err => {
            console.error(err);
            App.handleToast({
                type: 'danger',
                title: 'Eroare',
                message: 'Eroare de rețea la salvarea setării.',
                toastDelay: 5000
            });
        })
        .finally(() => {
            this.saving = false;
        });
    },
    
    editSetting(setting) {
        this.newKey = setting.key;
        this.newValue = setting.value;
        this.oldSettingKey = setting.key;
        const keyInput = document.getElementById('setting-key-input');
        if (keyInput) keyInput.focus();
    },
    
    deleteSetting(key) {
        if (!confirm(`Sigur dorești să ștergi setarea '${key}'?`)) {
            return;
        }
        
        const formData = new FormData();
        formData.append('app_id', this.currentAppId);
        formData.append('key', key);
        
        fetch('app-settings.php?action=delete', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                App.handleToast({
                    type: 'success',
                    title: 'Succes',
                    message: data.message,
                    toastDelay: 3000
                });
                this.fetchSettings();
            } else {
                App.handleToast({
                    type: 'danger',
                    title: 'Eroare',
                    message: data.message || 'Eroare la ștergerea setării.',
                    toastDelay: 5000
                });
            }
        })
        .catch(err => {
            console.error(err);
            App.handleToast({
                type: 'danger',
                title: 'Eroare',
                message: 'Eroare de rețea la ștergerea setării.',
                toastDelay: 5000
            });
        });
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
                                                <tr x-data="{ editingName: '<?= htmlspecialchars(addslashes((string)$app['name'])) ?>' }">
                                                    <td class="ps-4 border-secondary border-opacity-10">
                                                        <!-- Edit Mode -->
                                                        <div x-show="editingAppId === <?= $app['id'] ?>" x-cloak>
                                                            <form action="apps.php?action=update" method="POST" class="d-flex gap-2 align-items-center m-0">
                                                                <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                                                <input type="text" name="name" x-model="editingName" class="form-control form-control-sm bg-dark border-secondary border-opacity-50 text-light" required minlength="3">
                                                                <button type="submit" class="btn btn-success btn-sm" title="Salvează"><i class="fas fa-check"></i></button>
                                                                <button type="button" class="btn btn-secondary btn-sm" @click="editingAppId = null; editingName = '<?= htmlspecialchars(addslashes((string)$app['name'])) ?>'" title="Anulează"><i class="fas fa-times"></i></button>
                                                            </form>
                                                        </div>
                                                        <!-- Read Mode -->
                                                        <div x-show="editingAppId !== <?= $app['id'] ?>">
                                                            <span class="text-light fw-medium" x-text="editingName"></span>
                                                        </div>
                                                    </td>
                                                    <td class="border-secondary border-opacity-10">
                                                        <div class="input-group input-group-sm" style="max-width: 330px;">
                                                            <input type="text" class="form-control bg-dark border-secondary border-opacity-25 text-info font-monospace" value="<?= htmlspecialchars((string)$app['api_key']) ?>" readonly id="key-<?= $app['id'] ?>">
                                                            <button class="btn btn-outline-secondary border-opacity-25" type="button" @click="navigator.clipboard.writeText('<?= $app['api_key'] ?>')" title="Copiază cheia">
                                                                <i class="far fa-copy"></i>
                                                            </button>
                                                            <form action="apps.php?action=update&sub_action=regenerate-key" method="POST" onsubmit="return confirm('Sigur dorești să regenerezi cheia API pentru această aplicație? Cheia veche nu va mai funcționa.')" class="m-0">
                                                                <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-warning border-opacity-25" style="border-top-left-radius: 0; border-bottom-left-radius: 0;" title="Regenerează cheia API">
                                                                    <i class="fas fa-sync-alt"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                    <td class="text-end pe-4 border-secondary border-opacity-10">
                                                        <div class="d-inline-flex gap-3 align-items-center">
                                                            <div x-show="editingAppId !== <?= $app['id'] ?>" class="d-flex gap-3">
                                                                <button type="button" class="btn btn-link text-warning p-0" title="Editează nume" @click="editingAppId = <?= $app['id'] ?>; editingName = '<?= htmlspecialchars(addslashes((string)$app['name'])) ?>'">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-link text-info p-0" title="Setări aplicație" @click="openSettings(<?= $app['id'] ?>, '<?= htmlspecialchars(addslashes((string)$app['name'])) ?>')">
                                                                    <i class="fas fa-cog"></i>
                                                                </button>
                                                                <form action="apps.php?action=delete" method="POST" onsubmit="return confirm('Sigur dorești să ștergi această aplicație? Logurile asociate vor rămâne dar nu vei mai putea trimite altele noi cu această cheie.')" class="m-0">
                                                                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                                                    <button type="submit" class="btn btn-link text-danger p-0" title="Șterge aplicație">
                                                                        <i class="far fa-trash-alt"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                            <div x-show="editingAppId === <?= $app['id'] ?>" x-cloak>
                                                                <span class="text-secondary small">Se editează...</span>
                                                            </div>
                                                        </div>
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

    <!-- Settings Modal -->
    <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" 
         style="z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);" 
         x-show="showSettingsModal" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         x-cloak>
        
        <div class="card shadow-lg border border-secondary border-opacity-25" style="width: 100%; max-width: 650px; background-color: #1e293b;" @click.outside="showSettingsModal = false">
            <!-- Modal Header -->
            <div class="card-header border-bottom border-secondary border-opacity-25 p-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-sliders-h text-info"></i>
                    <h4 class="h5 mb-0 fw-bold text-light">
                        Setări: <span class="text-info" x-text="currentAppName"></span>
                    </h4>
                </div>
                <button type="button" class="btn-close btn-close-white" @click="showSettingsModal = false" aria-label="Close"></button>
            </div>
            
            <!-- Modal Body -->
            <div class="card-body p-4 overflow-auto" style="max-height: 70vh;">
                <!-- Save/Edit Form -->
                <div class="mb-4 p-3 rounded" style="background-color: #0f172a; border: 1px solid rgba(51, 65, 85, 0.5);">
                    <h5 class="h6 mb-3 text-secondary text-uppercase fw-bold small" x-text="oldSettingKey ? 'Editează Setare' : 'Adaugă Setare Nouă'"></h5>
                    <form @submit.prevent="saveSetting" class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label text-secondary small fw-medium">Cheie (ex: notification_channel)</label>
                            <input type="text" id="setting-key-input" x-model="newKey" class="form-control form-control-sm bg-dark border-secondary border-opacity-25 text-light" placeholder="Cheie unică" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary small fw-medium">Valoare</label>
                            <input type="text" x-model="newValue" class="form-control form-control-sm bg-dark border-secondary border-opacity-25 text-light" placeholder="Valoare" required>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex gap-1">
                                <button type="submit" class="btn btn-info btn-sm w-100 fw-bold" :disabled="saving">
                                    <span x-show="!saving"><i class="fas fa-save me-1"></i> Salvează</span>
                                    <span x-show="saving"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></span>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" x-show="oldSettingKey" @click="newKey = ''; newValue = ''; oldSettingKey = ''" title="Anulează editarea">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Settings Table / List -->
                <div>
                    <h5 class="h6 mb-3 text-secondary text-uppercase fw-bold small">Setări Active</h5>
                    
                    <!-- Loading State -->
                    <div x-show="loading" class="text-center py-4">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Se încarcă...</span>
                        </div>
                        <p class="text-secondary small mt-2">Se încarcă setările...</p>
                    </div>
                    
                    <!-- Settings Content -->
                    <div x-show="!loading">
                        <!-- Empty State -->
                        <div x-show="settings.length === 0" class="text-center py-4 border border-dashed border-secondary border-opacity-25 rounded bg-dark bg-opacity-25">
                            <i class="fas fa-cogs text-secondary opacity-50 mb-2" style="font-size: 24px;"></i>
                            <p class="text-secondary small mb-0">Această aplicație nu are setări definite.</p>
                        </div>
                        
                        <!-- Table -->
                        <div x-show="settings.length > 0" class="table-responsive rounded border border-secondary border-opacity-25">
                            <table class="table table-dark table-hover mb-0 align-middle small">
                                <thead class="bg-dark text-secondary text-uppercase" style="font-size: 0.75rem;">
                                    <tr>
                                        <th class="ps-3 py-2">Cheie</th>
                                        <th class="py-2">Valoare</th>
                                        <th class="text-end pe-3 py-2" style="width: 100px;">Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="setting in settings" :key="setting.key">
                                        <tr class="border-secondary border-opacity-10">
                                            <td class="ps-3 py-2 font-monospace text-info" x-text="setting.key"></td>
                                            <td class="py-2 text-light" style="word-break: break-all;" x-text="setting.value"></td>
                                            <td class="text-end pe-3 py-2">
                                                <div class="d-inline-flex gap-2">
                                                    <button type="button" class="btn btn-link text-warning p-0" title="Editează" @click="editSetting(setting)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-link text-danger p-0" title="Șterge" @click="deleteSetting(setting.key)">
                                                        <i class="far fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="card-footer border-top border-secondary border-opacity-25 p-3 d-flex justify-content-end bg-dark bg-opacity-25">
                <button type="button" class="btn btn-secondary btn-sm px-4" @click="showSettingsModal = false">Închide</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
