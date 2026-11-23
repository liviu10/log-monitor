window.__ = window.__ || function(key) { return key; };

const App = {
    /**
     * Read CSRF token emitted in head meta tag.
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    },

    /**
     * Toast notification management (DOM-safe, textContent only).
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

        let toastContainer = document.querySelector('.toast-container-main');

        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container-main position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '1090';
            document.body.appendChild(toastContainer);
        }

        const toastEl = document.createElement('div');
        toastEl.className = `toast border border-2 border-${type}`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');

        const toastHeader = document.createElement('div');
        toastHeader.className = 'toast-header fw-bold';
        const toastTitle = document.createElement('strong');
        toastTitle.className = 'me-auto';
        toastTitle.textContent = `${symbol} ${title}`;
        const toastClose = document.createElement('button');
        toastClose.type = 'button';
        toastClose.className = 'btn-close';
        toastClose.setAttribute('data-bs-dismiss', 'toast');
        toastClose.setAttribute('aria-label', 'Close');
        toastHeader.append(toastTitle, toastClose);

        const toastBody = document.createElement('div');
        toastBody.className = 'toast-body';
        toastBody.textContent = message;

        toastEl.appendChild(toastHeader);

        if (toastDelay > 0) {
            const progress = document.createElement('div');
            progress.className = `toast-progress-bar bg-${type}`;
            progress.style.height = '4px';
            progress.style.width = '100%';
            progress.style.transition = 'width linear';
            toastEl.appendChild(progress);

            setTimeout(() => {
                progress.style.transitionDuration = `${toastDelay}ms`;
                progress.style.width = '0%';
            }, 50);
        }

        toastEl.appendChild(toastBody);
        toastContainer.appendChild(toastEl);

        const toastOptions = {
            autohide: toastDelay !== 0,
        };

        if (toastDelay > 0) {
            toastOptions.delay = toastDelay;
        }

        const toast = new bootstrap.Toast(toastEl, toastOptions);
        toast.show();

        toastEl.addEventListener('hidden.bs.toast', function () {
            toast.dispose();
            toastEl.remove();
            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
        });
    },

    /**
     * Global utility method for clipboard copying and visual feedback.
     */
    copyPayload(alpineContext, dataObject, stateKey) {
        if (!dataObject) return;

        const textToCopy = dataObject.payload_raw
            ? dataObject.payload_raw
            : (typeof dataObject.payload === 'string'
                ? dataObject.payload
                : JSON.stringify(dataObject, null, 4));

        navigator.clipboard.writeText(textToCopy)
            .then(() => {
                alpineContext[stateKey] = true;
                setTimeout(() => {
                    alpineContext[stateKey] = false;
                }, 2000);
            })
            .catch(err => {
                console.error('Copy failed', err);
            });
    },

    /**
     * Alpine.js data constructor for the Logs timeline page (live tailing & log modal).
     */
    logsPageData() {
        return {
            selectedLog: null,
            showModal: false,
            autoRefresh: localStorage.getItem('auto_refresh') === 'true',
            countdown: 300,
            timer: null,
            payloadCopied: false,

            openLog(log) {
                this.selectedLog = log;
                this.showModal = true;
                this.payloadCopied = false;
            },

            toggleAutoRefresh() {
                this.autoRefresh = !this.autoRefresh;
                localStorage.setItem('auto_refresh', this.autoRefresh);
                if (this.autoRefresh) {
                    this.startCountdown();
                } else if (this.timer) {
                    clearInterval(this.timer);
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

            copyLog() {
                App.copyPayload(this, this.selectedLog, 'payloadCopied');
            },

            init() {
                if (this.autoRefresh) {
                    this.startCountdown();
                }
            },
        };
    },

    /**
     * Alpine.js data constructor for Manage Applications & App Settings modal.
     */
    appsPageData() {
        return {
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
                fetch(`/admin/apps/${this.currentAppId}/settings`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            this.settings = (data.settings || []).map(s => ({
                                ...s,
                                editing: false,
                                deleting: false,
                                dbKey: s.key,
                                dbValue: s.value,
                                tempKey: s.key,
                                tempValue: s.value,
                            }));
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error loading settings.'),
                                toastDelay: 5000,
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error loading settings.'),
                            toastDelay: 5000,
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
                        toastDelay: 3000,
                    });
                    return;
                }

                const keyPattern = /^[a-zA-Z0-9_\-\.]+$/;
                if (!keyPattern.test(this.newKey.trim())) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Format Invalid'),
                        message: window.__('Key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)'),
                        toastDelay: 5000,
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
                        toastDelay: 4000,
                    });
                    return;
                }

                this.pendingSettings.push({
                    key: keyVal,
                    value: valueVal,
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

                fetch(`/admin/apps/${this.currentAppId}/settings`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': App.getCsrfToken(),
                    },
                    body: JSON.stringify({
                        csrf_token: App.getCsrfToken(),
                        app_id: this.currentAppId,
                        settings: this.pendingSettings,
                    }),
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            App.handleToast({
                                type: 'success',
                                title: window.__('Success'),
                                message: data.message,
                                toastDelay: 3000,
                            });
                            this.pendingSettings = [];
                            this.fetchSettings();
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error saving settings.'),
                                toastDelay: 5000,
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error saving settings.'),
                            toastDelay: 5000,
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
                        toastDelay: 3000,
                    });
                    return;
                }

                const keyPattern = /^[a-zA-Z0-9_\-\.]+$/;
                if (!keyPattern.test(setting.tempKey.trim())) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Format Invalid'),
                        message: window.__('Key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)'),
                        toastDelay: 5000,
                    });
                    return;
                }

                if (this.settings.some(s => s !== setting && s.key === setting.tempKey.trim())) {
                    App.handleToast({
                        type: 'warning',
                        title: window.__('Warning'),
                        message: window.__('This key is already in use.'),
                        toastDelay: 4000,
                    });
                    return;
                }

                this.saving = true;

                fetch(`/admin/apps/${this.currentAppId}/settings`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': App.getCsrfToken(),
                    },
                    body: JSON.stringify({
                        csrf_token: App.getCsrfToken(),
                        app_id: this.currentAppId,
                        key: setting.tempKey.trim(),
                        value: setting.tempValue.trim(),
                        old_key: setting.dbKey,
                    }),
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            App.handleToast({
                                type: 'success',
                                title: window.__('Success'),
                                message: data.message,
                                toastDelay: 3000,
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
                                toastDelay: 5000,
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error updating setting.'),
                            toastDelay: 5000,
                        });
                    })
                    .finally(() => {
                        this.saving = false;
                    });
            },

            deleteSetting(key) {
                fetch(`/admin/apps/${this.currentAppId}/settings/delete`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': App.getCsrfToken(),
                    },
                    body: JSON.stringify({
                        csrf_token: App.getCsrfToken(),
                        app_id: this.currentAppId,
                        key: key,
                    }),
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            App.handleToast({
                                type: 'success',
                                title: window.__('Success'),
                                message: data.message,
                                toastDelay: 3000,
                            });
                            this.fetchSettings();
                        } else {
                            App.handleToast({
                                type: 'danger',
                                title: window.__('Error'),
                                message: data.message || window.__('Error deleting setting.'),
                                toastDelay: 5000,
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        App.handleToast({
                            type: 'danger',
                            title: window.__('Error'),
                            message: window.__('Network error deleting setting.'),
                            toastDelay: 5000,
                        });
                    });
            },
        };
    },

    /**
     * Shared Alpine modal & JSON formatter factory for queue management pages.
     */
    createQueuePageData(modalPrefix, payloadField) {
        const showModalBySuffix = (suffix) => {
            const el = document.getElementById(`${modalPrefix}${suffix}`);
            if (el) {
                bootstrap.Modal.getOrCreateInstance(el).show();
            }
        };

        return {
            activeJob: null,
            formattedPayload: '',
            payloadCopied: false,

            showPayload(job) {
                this.activeJob = job;
                this.payloadCopied = false;
                const raw = job ? (job[payloadField] || '') : '';
                try {
                    const parsed = JSON.parse(raw);
                    this.formattedPayload = JSON.stringify(parsed, null, 4);
                } catch (e) {
                    this.formattedPayload = raw;
                }
                showModalBySuffix('PayloadModal');
            },

            copyJobPayload() {
                App.copyPayload(this, this.activeJob, 'payloadCopied');
            },

            showError(job) {
                this.activeJob = job;
                showModalBySuffix('ErrorModal');
            },

            confirmRetrySingle(job) {
                this.activeJob = job;
                showModalBySuffix('RetryConfirmModal');
            },

            confirmRetryAll() {
                showModalBySuffix('RetryAllModal');
            },

            confirmDelete(job) {
                this.activeJob = job;
                showModalBySuffix('DeleteModal');
            },

            confirmPurge() {
                showModalBySuffix('PurgeModal');
            },
        };
    },

    /**
     * Alpine.js data constructor for the Raw Log Queue Manager page.
     */
    logQueuePageData() {
        return App.createQueuePageData('logQueue', 'payload_raw');
    },

    /**
     * Alpine.js data constructor for Sent Notifications timeline & details modal.
     */
    notificationsPageData() {
        return {
            selectedNotification: null,
            showModal: false,
            payloadCopied: false,

            openNotification(notification) {
                this.selectedNotification = notification;
                this.showModal = true;
                this.payloadCopied = false;
            },

            formatJson(rawJson) {
                if (typeof rawJson !== 'string') {
                    return JSON.stringify(rawJson ?? {}, null, 4);
                }
                try {
                    const parsed = JSON.parse(rawJson);
                    return JSON.stringify(parsed, null, 4);
                } catch (e) {
                    return rawJson;
                }
            },

            copyNotification() {
                App.copyPayload(this, this.selectedNotification, 'payloadCopied');
            },
        };
    },

    /**
     * Alpine.js data constructor for Notification Queue details, retry, delete, and purge modals.
     */
    notificationQueuePageData() {
        return App.createQueuePageData('queue', 'payload');
    },
};

/**
 * Dashboard Chart.js Analytics Constructor
 * Keep Chart instances outside Alpine's reactive Proxy to prevent recursive stack overflows.
 */
window.dashboardAnalytics = function(initialData) {
    let volumeChart = null;
    let severityChart = null;
    let appsChart = null;

    const toRaw = (val) => {
        try {
            return JSON.parse(JSON.stringify(val ?? {}));
        } catch (e) {
            return val;
        }
    };

    const rawInitial = toRaw(initialData);

    const chartTheme = () => {
        const isLight = document.documentElement.getAttribute('data-bs-theme') === 'light';
        return {
            gridColor: isLight ? 'rgba(15, 23, 42, 0.08)' : 'rgba(255, 255, 255, 0.06)',
            tickColor: isLight ? '#475569' : '#94a3b8',
            appTickColor: isLight ? '#1e293b' : '#e2e8f0',
            doughnutBorder: isLight ? '#ffffff' : '#0f172a',
            emptyColor: isLight ? 'rgba(15, 23, 42, 0.08)' : 'rgba(255, 255, 255, 0.05)',
        };
    };

    return {
        period: rawInitial.period || 'day',
        rangeLabel: (rawInitial.range && rawInitial.range.label) || '',
        summary: rawInitial.summary || { total: 0, errors: 0 },
        customStart: new Date(Date.now() - 7 * 86400000).toISOString().split('T')[0],
        customEnd: new Date().toISOString().split('T')[0],
        loading: false,
        severityEmpty: !rawInitial.severityPie || !rawInitial.severityPie.data || rawInitial.severityPie.data.length === 0,
        appsEmpty: !rawInitial.appsBar || !rawInitial.appsBar.data || rawInitial.appsBar.data.length === 0,

        initCharts() {
            this.$nextTick(() => {
                if (typeof Chart === 'undefined') {
                    return;
                }
                this.buildVolumeChart(rawInitial.lineChart || { labels: [], total: [], errors: [] });
                this.buildSeverityChart(rawInitial.severityPie || { labels: [], data: [], colors: [] });
                this.buildAppsChart(rawInitial.appsBar || { labels: [], data: [] });
            });
        },

        selectPeriod(p) {
            this.period = p;
            if (p !== 'custom') {
                this.fetchData();
            }
        },

        applyCustomRange() {
            if (this.customStart && this.customEnd) {
                this.fetchData();
            }
        },

        async fetchData() {
            this.loading = true;
            try {
                let url = '/admin/chart-data?period=' + encodeURIComponent(this.period);
                if (this.period === 'custom') {
                    url += '&start_date=' + encodeURIComponent(this.customStart) + '&end_date=' + encodeURIComponent(this.customEnd);
                }

                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error('Network error fetching chart data');
                const data = toRaw(await res.json());

                this.rangeLabel = data.range ? data.range.label : '';
                this.summary = data.summary || { total: 0, errors: 0 };
                this.severityEmpty = !data.severityPie || !data.severityPie.data || data.severityPie.data.length === 0;
                this.appsEmpty = !data.appsBar || !data.appsBar.data || data.appsBar.data.length === 0;

                this.updateVolumeChart(data.lineChart || { labels: [], total: [], errors: [] });
                this.updateSeverityChart(data.severityPie || { labels: [], data: [], colors: [] });
                this.updateAppsChart(data.appsBar || { labels: [], data: [] });
            } catch (err) {
                console.error('Failed to load chart data:', err);
            } finally {
                this.loading = false;
            }
        },

        buildVolumeChart(raw) {
            const ctx = document.getElementById('chartLogVolume');
            if (!ctx) return;

            const data = toRaw(raw);
            const theme = chartTheme();

            if (volumeChart) {
                volumeChart.destroy();
            }

            volumeChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels || [],
                    datasets: [
                        {
                            label: window.__('Total Logs'),
                            data: data.total || [],
                            borderColor: '#06b6d4',
                            backgroundColor: 'rgba(6, 182, 212, 0.12)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                        },
                        {
                            label: window.__('Error / Critical'),
                            data: data.errors || [],
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.12)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            padding: 10,
                            boxPadding: 4,
                            usePointStyle: true,
                        },
                    },
                    scales: {
                        x: {
                            grid: { color: theme.gridColor },
                            ticks: {
                                color: theme.tickColor,
                                font: { size: 11 },
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 12,
                            },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: theme.gridColor },
                            ticks: {
                                color: theme.tickColor,
                                font: { size: 11 },
                                precision: 0,
                            },
                        },
                    },
                },
            });
        },

        updateVolumeChart(raw) {
            const data = toRaw(raw);
            if (!volumeChart) {
                this.buildVolumeChart(data);
                return;
            }
            volumeChart.data.labels = data.labels || [];
            volumeChart.data.datasets[0].data = data.total || [];
            volumeChart.data.datasets[1].data = data.errors || [];
            volumeChart.update();
        },

        buildSeverityChart(raw) {
            const ctx = document.getElementById('chartSeverityBreakdown');
            if (!ctx) return;

            const data = toRaw(raw);
            const theme = chartTheme();
            const hasData = data && data.data && data.data.length > 0;
            const labels = hasData ? data.labels : ['No Data'];
            const counts = hasData ? data.data : [1];
            const colors = hasData ? data.colors : [theme.emptyColor];

            if (severityChart) {
                severityChart.destroy();
            }

            severityChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: theme.doughnutBorder,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: theme.tickColor,
                                boxWidth: 10,
                                padding: 12,
                                font: { size: 11 },
                            },
                        },
                        tooltip: {
                            enabled: hasData,
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            padding: 10,
                        },
                    },
                },
            });
        },

        updateSeverityChart(raw) {
            const data = toRaw(raw);
            const theme = chartTheme();
            if (!severityChart) {
                this.buildSeverityChart(data);
                return;
            }
            const hasData = data && data.data && data.data.length > 0;
            severityChart.data.labels = hasData ? data.labels : ['No Data'];
            severityChart.data.datasets[0].data = hasData ? data.data : [1];
            severityChart.data.datasets[0].backgroundColor = hasData ? data.colors : [theme.emptyColor];
            severityChart.options.plugins.tooltip.enabled = hasData;
            severityChart.update();
        },

        buildAppsChart(raw) {
            const ctx = document.getElementById('chartAppsDistribution');
            if (!ctx) return;

            const data = toRaw(raw);
            const theme = chartTheme();

            if (appsChart) {
                appsChart.destroy();
            }

            appsChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels || [],
                    datasets: [{
                        label: window.__('Logs Count'),
                        data: data.data || [],
                        backgroundColor: 'rgba(59, 130, 246, 0.75)',
                        hoverBackgroundColor: '#3b82f6',
                        borderRadius: 6,
                        barThickness: 18,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            padding: 10,
                        },
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: theme.gridColor },
                            ticks: {
                                color: theme.tickColor,
                                font: { size: 11 },
                                precision: 0,
                            },
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                color: theme.appTickColor,
                                font: { size: 11, family: 'monospace' },
                            },
                        },
                    },
                },
            });
        },

        updateAppsChart(raw) {
            const data = toRaw(raw);
            if (!appsChart) {
                this.buildAppsChart(data);
                return;
            }
            appsChart.data.labels = data.labels || [];
            appsChart.data.datasets[0].data = data.data || [];
            appsChart.update();
            this.$nextTick(() => {
                if (appsChart) appsChart.resize();
            });
        },
    };
};

/**
 * Global Fallback Functions for Alpine Components
 */
window.passwordToggle = window.passwordToggle || function() {
    return {
        show: false,
        toggle() {
            this.show = !this.show;
        },
    };
};

window.quickFill = window.quickFill || function() {
    return {
        fillCredentials(email, password) {
            const emailInput = document.querySelector('input[name="email"]');
            const passwordInput = document.querySelector('input[name="password"]');

            if (emailInput) {
                emailInput.value = email;
                emailInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            if (passwordInput) {
                passwordInput.value = password;
                passwordInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            const submitBtn = document.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.focus();
        },
    };
};

/**
 * Reusable Alpine.js Components
 */
const initAlpineComponents = () => {
    if (typeof Alpine !== 'undefined' && typeof Alpine.data === 'function') {
        Alpine.data('passwordToggle', window.passwordToggle);
        Alpine.data('quickFill', window.quickFill);
    }
};

if (window.Alpine) {
    initAlpineComponents();
} else {
    document.addEventListener('alpine:init', initAlpineComponents);
}

