const App = {
    /**
     * Gestionare notificari de tip Toast
     */
    handleToast(toastContent) {
        const { type, title, message, toastDelay, redirectUrl } = toastContent;
        const toastSymbols = {
            danger: '🔥',
            warning: '⚠️',
            success: '✅',
            notice: 'ℹ️',
            info: '💡',
        };
        const symbol = toastSymbols[type] || '';

        let toastContainer = document.querySelector(".toast-container-main");

        if (!toastContainer) {
            toastContainer = document.createElement("div");
            toastContainer.className = "toast-container-main position-fixed top-0 end-0 p-3";
            toastContainer.style.zIndex = "1090";
            document.body.appendChild(toastContainer);
        }

        const toastEl = document.createElement("div");
        toastEl.className = `toast border border-2 border-${type}`;
        toastEl.setAttribute("role", "alert");
        toastEl.setAttribute("aria-live", "assertive");
        toastEl.setAttribute("aria-atomic", "true");

        const toastHeader = document.createElement("div");
        toastHeader.className = "toast-header fw-bold";
        toastHeader.innerHTML = `
            <strong class="me-auto">${symbol} ${title}</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        `;

        const toastBody = document.createElement("div");
        toastBody.className = "toast-body";
        toastBody.innerHTML = message;

        toastEl.appendChild(toastHeader);

        // Bara de progres (Timer)
        if (toastDelay > 0) {
            const progress = document.createElement("div");
            progress.className = `toast-progress-bar bg-${type}`;
            progress.style.height = "4px";
            progress.style.width = "100%";
            progress.style.transition = "width linear";
            toastEl.appendChild(progress);

            setTimeout(() => {
                progress.style.transitionDuration = `${toastDelay}ms`;
                progress.style.width = "0%";
            }, 50);
        }

        toastEl.appendChild(toastBody);
        toastContainer.appendChild(toastEl);

        const toastOptions = {
            autohide: toastDelay !== 0
        };

        if (toastDelay > 0) {
            toastOptions.delay = toastDelay;
        }

        const toast = new bootstrap.Toast(toastEl, toastOptions);
        toast.show();

        toastEl.addEventListener("hidden.bs.toast", function () {
            toast.dispose();
            toastEl.remove();
            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
        });
    },

    /**
     * Constructor date Alpine.js pentru pagina Dashboard.
     */
    dashboardPageData() {
        return {
            sidebarOpen: true,
            selectedLog: null,
            showModal: false,
            autoRefresh: localStorage.getItem('auto_refresh') === 'true',
            countdown: 300,
            timer: null,

            openLog(log) {
                this.selectedLog = log;
                this.showModal = true;
            },

            toggleAutoRefresh() {
                this.autoRefresh = !this.autoRefresh;
                localStorage.setItem('auto_refresh', this.autoRefresh);
                if (this.autoRefresh) {
                    this.startCountdown();
                } else {
                    if (this.timer) clearInterval(this.timer);
                }
            },

            startCountdown() {
                this.countdown = 300;
                this.timer = setInterval(() => {
                    if (this.showModal) {
                        this.countdown = 300;
                        return;
                    }
                    this.countdown--;
                    if (this.countdown <= 0) {
                        clearInterval(this.timer);
                        window.location.reload();
                    }
                }, 1000);
            },

            init() {
                if (this.autoRefresh) {
                    this.startCountdown();
                }
            }
        };
    },

    /**
     * Constructor date Alpine.js pentru pagina de gestionare aplicatii (Manage Applications).
     */
    appsPageData() {
        return {
            sidebarOpen: true,
            showSettingsModal: false,
            currentAppId: null,
            currentAppName: '',
            settings: [],
            pendingSettings: [],
            newKey: '',
            newValue: '',
            loading: false,
            saving: false,
            editingAppId: null,

            openSettings(appId, appName) {
                this.currentAppId = appId;
                this.currentAppName = appName;
                this.showSettingsModal = true;
                this.newKey = '';
                this.newValue = '';
                this.pendingSettings = [];
                this.fetchSettings();
            },

            fetchSettings() {
                this.loading = true;
                fetch(`app-settings.php?app_id=${this.currentAppId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            this.settings = data.settings.map(s => ({
                                ...s,
                                editing: false,
                                deleting: false,
                                dbKey: s.key,
                                dbValue: s.value,
                                tempKey: s.key,
                                tempValue: s.value
                            }));
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error loading settings.'),
                                toastDelay: 5000
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error loading settings.'),
                            toastDelay: 5000
                        });
                    })
                    .finally(() => {
                        this.loading = false;
                    });
            },

            addPendingSetting() {
                if (!this.newKey.trim() || !this.newValue.trim()) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Warning'),
                        message: window.__('Key and value are required.'),
                        toastDelay: 3000
                    });
                    return;
                }

                const keyPattern = /^[a-zA-Z0-9_\-\.]+$/;
                if (!keyPattern.test(this.newKey.trim())) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Format Invalid'),
                        message: window.__('Key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)'),
                        toastDelay: 5000
                    });
                    return;
                }

                const keyVal = this.newKey.trim();
                const valueVal = this.newValue.trim();

                if (this.settings.some(s => s.key === keyVal) || this.pendingSettings.some(s => s.key === keyVal)) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Warning'),
                        message: window.__('This key already exists in the list or active settings.'),
                        toastDelay: 4000
                    });
                    return;
                }

                this.pendingSettings.push({
                    key: keyVal,
                    value: valueVal
                });

                this.newKey = '';
                this.newValue = '';

                this.$nextTick(() => {
                    const keyInput = document.getElementById('setting-key-input');
                    if (keyInput) keyInput.focus();
                });
            },

            removePendingSetting(index) {
                this.pendingSettings.splice(index, 1);
            },

            saveAllSettings() {
                if (this.pendingSettings.length === 0) return;

                this.saving = true;

                fetch('app-settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        app_id: this.currentAppId,
                        settings: this.pendingSettings
                    })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            App.handleToast({
                                type: 'success',
                                title: window.__('Success'),
                                message: data.message,
                                toastDelay: 3000
                            });
                            this.pendingSettings = [];
                            this.fetchSettings();
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error saving settings.'),
                                toastDelay: 5000
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error saving settings.'),
                            toastDelay: 5000
                        });
                    })
                    .finally(() => {
                        this.saving = false;
                    });
            },

            saveSingleSetting(setting) {
                if (!setting.tempKey.trim() || !setting.tempValue.trim()) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Warning'),
                        message: window.__('Key and value are required.'),
                        toastDelay: 3000
                    });
                    return;
                }

                const keyPattern = /^[a-zA-Z0-9_\-\.]+$/;
                if (!keyPattern.test(setting.tempKey.trim())) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Format Invalid'),
                        message: window.__('Key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)'),
                        toastDelay: 5000
                    });
                    return;
                }

                if (this.settings.some(s => s !== setting && s.key === setting.tempKey.trim())) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Warning'),
                        message: window.__('This key is already in use.'),
                        toastDelay: 4000
                    });
                    return;
                }

                this.saving = true;

                fetch('app-settings.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        app_id: this.currentAppId,
                        key: setting.tempKey.trim(),
                        value: setting.tempValue.trim(),
                        old_key: setting.dbKey
                    })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            App.handleToast({
                                type: 'success',
                                title: window.__('Success'),
                                message: data.message,
                                toastDelay: 3000
                            });
                            setting.key = setting.tempKey.trim();
                            setting.value = setting.tempValue.trim();
                            setting.dbKey = setting.key;
                            setting.dbValue = setting.value;
                            setting.editing = false;
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error updating setting.'),
                                toastDelay: 5000
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error updating setting.'),
                            toastDelay: 5000
                        });
                    })
                    .finally(() => {
                        this.saving = false;
                    });
            },

            deleteSetting(key) {
                fetch('app-settings.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        app_id: this.currentAppId,
                        key: key
                    })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            App.handleToast({
                                type: 'success',
                                title: window.__('Success'),
                                message: data.message,
                                toastDelay: 3000
                            });
                            this.fetchSettings();
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error deleting setting.'),
                                toastDelay: 5000
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error deleting setting.'),
                            toastDelay: 5000
                        });
                    });
            }
        };
    }
};