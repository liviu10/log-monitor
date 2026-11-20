@php
    $res = $resource ?? '';
    $currentStatus = 'all';
    if (!empty($_GET['filter_status']) && $_GET['filter_status'] !== 'all') {
        $currentStatus = (string) $_GET['filter_status'];
    } elseif (isset($_GET['filter_is_active']) && $_GET['filter_is_active'] !== '' && $_GET['filter_is_active'] !== 'all') {
        if ($res === 'apis') {
            $currentStatus = (string) $_GET['filter_is_active'] === '1' ? 'installed' : 'uninstalled';
        } else {
            $currentStatus = (string) $_GET['filter_is_active'] === '1' ? 'active' : 'inactive';
        }
    }

    $currentRole = (!empty($_GET['filter_role_id']) && $_GET['filter_role_id'] !== 'all') ? (string) $_GET['filter_role_id'] : 'all';
    $currentVisibility = (!empty($_GET['filter_visibility']) && $_GET['filter_visibility'] !== 'all') ? (string) $_GET['filter_visibility'] : 'all';
    $currentCategory = (!empty($_GET['filter_content_category_id']) && $_GET['filter_content_category_id'] !== 'all') ? (string) $_GET['filter_content_category_id'] : 'all';
    $currentLanguage = (!empty($_GET['filter_language']) && $_GET['filter_language'] !== 'all') ? (string) $_GET['filter_language'] : 'all';
    $currentType = (!empty($_GET['filter_type']) && $_GET['filter_type'] !== 'all') ? (string) $_GET['filter_type'] : 'all';
    $currentContentId = (!empty($currentContentId) && $currentContentId !== 'all') ? (string) $currentContentId : ((!empty($_GET['filter_content_id']) && $_GET['filter_content_id'] !== 'all') ? (string) $_GET['filter_content_id'] : 'all');
    $currentQueue = (!empty($_GET['filter_queue']) && $_GET['filter_queue'] !== 'all') ? (string) $_GET['filter_queue'] : 'all';
    $currentDevice = (!empty($_GET['filter_device_type']) && $_GET['filter_device_type'] !== 'all') ? (string) $_GET['filter_device_type'] : 'all';
    $currentChannel = (!empty($_GET['filter_channel']) && $_GET['filter_channel'] !== 'all') ? (string) $_GET['filter_channel'] : 'all';
@endphp
<script nonce="{{ APP_NONCE }}">
    function dataTableComponent(rawItems, resource, currentUserId) {
        return {
            items: rawItems,
            resource: resource,
            currentUserId: currentUserId,
            
            // Parameters tied directly to the backend
            searchQuery: '{{ htmlspecialchars($pagination['search'] ?? '', ENT_QUOTES, 'UTF-8') }}',
            sortBy: '{{ $pagination['sort'] }}',
            sortDesc: {{ $pagination['order'] === 'desc' ? 'true' : 'false' }},
            currentPage: {{ $pagination['current_page'] }},
            perPage: {{ $pagination['per_page'] }},
            totalPages: {{ $pagination['total_pages'] }},
            totalItems: {{ $pagination['total_items'] }},
            
            // Filters
            filterStatus: '{{ $currentStatus }}',
            filterRole: '{{ $currentRole }}',
            filterVisibility: '{{ $currentVisibility }}',
            filterCategory: '{{ $currentCategory }}',
            filterLanguage: '{{ $currentLanguage }}',
            filterType: '{{ $currentType }}',
            filterContentId: '{{ $currentContentId }}',
            filterQueue: '{{ $currentQueue }}',
            filterDevice: '{{ $currentDevice }}',
            filterChannel: '{{ $currentChannel }}',
            filterIsActive: '{{ $currentStatus }}',
            
            init() {
                // Local initialization if needed
            },

            resetParams() {
                window.location.href = window.location.pathname;
            },

            updateUrl() {
                sessionStorage.setItem('restoreSearchFocus', 'true');
                const url = new URL(window.location.href);
                
                // Pagination
                url.searchParams.set('page', this.currentPage);
                url.searchParams.set('per_page', this.perPage);
                
                // Search
                if (this.searchQuery.trim() !== '') {
                    url.searchParams.set('search', this.searchQuery);
                } else {
                    url.searchParams.delete('search');
                }
                
                // Sort & Order
                url.searchParams.set('sort', this.sortBy);
                url.searchParams.set('order', this.sortDesc ? 'desc' : 'asc');
                
                // Clear old filters
                for (const key of [...url.searchParams.keys()]) {
                    if (key.startsWith('filter_')) {
                        url.searchParams.delete(key);
                    }
                }
                
                // Specific active filters
                if (this.resource === 'users') {
                    if (this.filterStatus && this.filterStatus !== 'all') url.searchParams.set('filter_status', this.filterStatus);
                    if (this.filterRole && this.filterRole !== 'all') url.searchParams.set('filter_role_id', this.filterRole);
                } else if (this.resource === 'roles') {
                    if (this.filterStatus && this.filterStatus !== 'all') url.searchParams.set('filter_is_active', this.filterStatus === 'active' ? '1' : '0');
                } else if (this.resource === 'pages') {
                    if (this.filterVisibility && this.filterVisibility !== 'all') url.searchParams.set('filter_visibility', this.filterVisibility);
                    if (this.filterCategory && this.filterCategory !== 'all') url.searchParams.set('filter_content_category_id', this.filterCategory);
                } else if (this.resource === 'categories') {
                    if (this.filterLanguage && this.filterLanguage !== 'all') url.searchParams.set('filter_language', this.filterLanguage);
                    if (this.filterStatus && this.filterStatus !== 'all') url.searchParams.set('filter_is_active', this.filterStatus === 'active' ? '1' : '0');
                } else if (this.resource === 'media') {
                    if (this.filterType && this.filterType !== 'all') url.searchParams.set('filter_type', this.filterType);
                } else if (this.resource === 'apis') {
                    if (this.filterStatus && this.filterStatus !== 'all') url.searchParams.set('filter_is_active', (this.filterStatus === 'installed' || this.filterStatus === 'active') ? '1' : '0');
                } else if (this.resource === 'comments') {
                    if (this.filterStatus && this.filterStatus !== 'all') url.searchParams.set('filter_status', this.filterStatus);
                    if (this.filterContentId && this.filterContentId !== 'all') url.searchParams.set('filter_content_id', this.filterContentId);
                } else if (this.resource === 'queue') {
                    if (this.filterStatus && this.filterStatus !== 'all') url.searchParams.set('filter_status', this.filterStatus);
                    if (this.filterQueue && this.filterQueue !== 'all') url.searchParams.set('filter_queue', this.filterQueue);
                } else if (this.resource === 'analytics_page_views') {
                    if (this.filterDevice && this.filterDevice !== 'all') url.searchParams.set('filter_device_type', this.filterDevice);
                } else if (this.resource === 'templates') {
                    if (this.filterChannel && this.filterChannel !== 'all') url.searchParams.set('filter_channel', this.filterChannel);
                    if (this.filterLanguage && this.filterLanguage !== 'all') url.searchParams.set('filter_language', this.filterLanguage);
                    if (this.filterIsActive && this.filterIsActive !== 'all') url.searchParams.set('filter_is_active', this.filterIsActive === 'active' || this.filterIsActive === '1' ? '1' : '0');
                }
                
                window.location.href = url.toString();
            },

            getPages() {
                let start = Math.max(1, this.currentPage - 2);
                let end = Math.min(this.totalPages, start + 4);
                if (end - start < 4) {
                    start = Math.max(1, end - 4);
                }
                let pages = [];
                for (let i = start; i <= end; i++) {
                    pages.push(i);
                }
                return pages;
            },

            setPage(page) {
                if (page >= 1 && page <= this.totalPages) {
                    this.currentPage = page;
                }
            },

            toggleSort(field) {
                if (this.sortBy === field) {
                    this.sortDesc = !this.sortDesc;
                } else {
                    this.sortBy = field;
                    this.sortDesc = false;
                }
            },

            formatDateStr(dateStr) {
                if (!dateStr) return '';
                try {
                    const parts = dateStr.split(' ')[0].split('-');
                    if (parts.length === 3) {
                        return `${parts[2]}.${parts[1]}.${parts[0]}`;
                    }
                    return dateStr;
                } catch(e) {
                    return dateStr;
                }
            },

            getTableColspan(el = null) {
                const root = (el && el.closest) ? (el.closest('table') || this.$root) : (this.$root || document);
                const headerRows = root ? root.querySelectorAll('thead tr') : [];
                const dynamicMap = {};

                headerRows.forEach(tr => {
                    const res = tr.getAttribute('data-resource') 
                        || tr.getAttribute('x-show')?.match(/resource\s*===\s*['"]([^'"]+)['"]/)?.[1];
                    const count = tr.querySelectorAll('th').length;
                    if (res && count > 0) {
                        dynamicMap[res] = count;
                    }
                });

                if (dynamicMap[this.resource]) {
                    return dynamicMap[this.resource];
                }

                if (root) {
                    const activeTr = root.querySelector(`thead tr[data-resource="${this.resource}"]`)
                        || root.querySelector(`thead tr[x-show*="'${this.resource}'"]`)
                        || root.querySelector('thead tr');
                    if (activeTr) {
                        const count = activeTr.querySelectorAll('th').length;
                        if (count > 0) {
                            return count;
                        }
                    }
                }

                return 1;
            }
        };
    }
</script>
