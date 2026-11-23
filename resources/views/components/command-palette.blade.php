<div x-data="commandPalette()"
     @keydown.window.prevent.ctrl.k="open()"
     @keydown.window.prevent.meta.k="open()"
     @open-command-palette.window="open()"
     x-show="isOpen"
     x-cloak
     class="position-fixed top-0 start-0 w-100 h-100 pt-5 px-3"
     style="z-index: 1090; background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(4px);">
    <div class="d-flex align-items-start justify-content-center w-100">
    <div class="card card-custom shadow-lg border border-secondary border-opacity-25 rounded-4 overflow-hidden mt-5"
         style="width: 100%; max-width: 560px;"
         @click.away="close()"
         @keydown.escape.window="close()"
         @keydown.down.prevent="moveDown()"
         @keydown.up.prevent="moveUp()"
         @keydown.enter.prevent="executeSelected()">
        <div class="p-3 border-bottom border-secondary border-opacity-10 d-flex align-items-center gap-2">
            <i class="fa-solid fa-magnifying-glass text-info"></i>
            <input type="text"
                   x-ref="paletteInput"
                   x-model="query"
                   class="form-control border-0 bg-transparent shadow-none"
                   placeholder="{{ __('Type a command or search destination...') }}">
            <span class="badge bg-secondary bg-opacity-25 text-secondary font-monospace">ESC</span>
        </div>
        <div class="list-group list-group-flush overflow-auto" style="max-height: 360px;">
            <template x-for="(item, idx) in filteredItems()" :key="item.id">
                <button type="button"
                        class="list-group-item list-group-item-action bg-transparent border-0 px-3 py-2.5 d-flex align-items-center justify-content-between"
                        :class="selectedIndex === idx ? 'bg-info bg-opacity-10 text-info' : ''"
                        @click="run(item)"
                        @mouseenter="selectedIndex = idx">
                    <span class="d-flex align-items-center gap-2.5">
                        <i :class="item.icon" class="fa-fw text-info"></i>
                        <span class="fw-medium" x-text="item.label"></span>
                    </span>
                    <small class="text-secondary" x-text="item.group"></small>
                </button>
            </template>
            <div x-show="filteredItems().length === 0" class="p-4 text-center text-secondary small">
                {{ __('No matching commands found.') }}
            </div>
        </div>
    </div>
    </div>
</div>

<script nonce="{{ APP_NONCE }}">
function commandPalette() {
    const items = [
        @if(hasPermission('admin.dashboard.index'))
        { id: 'dashboard', label: @json(__('Dashboard')), group: @json(__('Monitoring')), icon: 'fa-solid fa-gauge-high', url: '/admin' },
        @endif
        @if(hasPermission('admin.logs.index'))
        { id: 'logs', label: @json(__('Logs')), group: @json(__('Monitoring')), icon: 'fa-solid fa-layer-group', url: '/admin/logs' },
        @endif
        @if(hasPermission('admin.logqueue.index'))
        { id: 'log-queue', label: @json(__('Log Queue')), group: @json(__('Monitoring')), icon: 'fa-solid fa-list-check', url: '/admin/log-queue' },
        @endif
        @if(hasPermission('admin.notifications.index'))
        { id: 'notifications', label: @json(__('Notifications')), group: @json(__('Monitoring')), icon: 'fa-solid fa-bell', url: '/admin/notifications' },
        @endif
        @if(hasPermission('admin.notificationqueue.index'))
        { id: 'notification-queue', label: @json(__('Notification Queue')), group: @json(__('Monitoring')), icon: 'fa-solid fa-envelope-open-text', url: '/admin/notification-queue' },
        @endif
        @if(hasPermission('admin.apps.index'))
        { id: 'apps', label: @json(__('Applications')), group: @json(__('Monitoring')), icon: 'fa-solid fa-cubes', url: '/admin/apps' },
        @endif
        @if(hasPermission('admin.settings.users.index'))
        { id: 'users', label: @json(__('Users')), group: @json(__('Settings')), icon: 'fa-solid fa-users', url: '/admin/settings/users' },
        @endif
        @if(hasPermission('admin.settings.roles.index'))
        { id: 'roles', label: @json(__('Roles')), group: @json(__('Settings')), icon: 'fa-solid fa-shield-halved', url: '/admin/settings/roles' },
        @endif
        { id: 'profile', label: @json(__('Profile')), group: @json(__('Account')), icon: 'fa-solid fa-user-circle', url: '/admin/settings/users/profile' },
        { id: 'theme', label: @json(__('Toggle Theme')), group: @json(__('Preferences')), icon: 'fa-solid fa-circle-half-stroke', action: 'theme' },
        { id: 'logout', label: @json(__('Logout')), group: @json(__('Account')), icon: 'fa-solid fa-right-from-bracket', action: 'logout' },
    ];

    return {
        isOpen: false,
        query: '',
        selectedIndex: 0,
        open() {
            this.isOpen = true;
            this.query = '';
            this.selectedIndex = 0;
            this.$nextTick(() => {
                if (this.$refs.paletteInput) this.$refs.paletteInput.focus();
            });
        },
        close() {
            this.isOpen = false;
        },
        filteredItems() {
            const q = this.query.trim().toLowerCase();
            if (!q) return items;
            return items.filter(i => i.label.toLowerCase().includes(q) || i.group.toLowerCase().includes(q));
        },
        moveDown() {
            const list = this.filteredItems();
            if (list.length > 0) this.selectedIndex = (this.selectedIndex + 1) % list.length;
        },
        moveUp() {
            const list = this.filteredItems();
            if (list.length > 0) this.selectedIndex = (this.selectedIndex - 1 + list.length) % list.length;
        },
        executeSelected() {
            const list = this.filteredItems();
            if (list[this.selectedIndex]) this.run(list[this.selectedIndex]);
        },
        run(item) {
            this.close();
            if (item.url) {
                window.location.href = item.url;
            } else if (item.action === 'theme') {
                const nextTheme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', nextTheme);
                localStorage.setItem('app_theme', nextTheme);
            } else if (item.action === 'logout') {
                const form = document.getElementById('global-logout-form');
                if (form) form.submit();
            }
        },
    };
}
</script>

