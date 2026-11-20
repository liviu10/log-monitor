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

    // Dynamic construction of active filters summary
    $activeFilters = [];
    if (! empty($_GET['search'])) {
        $activeFilters[] = __('Search') . ': "' . htmlspecialchars($_GET['search']) . '"';
    }
    if (isset($_GET['filter_status']) && $_GET['filter_status'] !== 'all') {
        $activeFilters[] = __('Status') . ': ' . htmlspecialchars($_GET['filter_status']);
    }
    if (isset($_GET['filter_is_active']) && $_GET['filter_is_active'] !== 'all') {
        if (($resource ?? '') === 'apis') {
            $statusVal = $_GET['filter_is_active'] === '1' ? __('Installed') : __('Uninstalled');
        } else {
            $statusVal = $_GET['filter_is_active'] === '1' ? __('Active') : __('Inactive');
        }
        $activeFilters[] = __('Status') . ': ' . $statusVal;
    }
    if (isset($_GET['filter_role_id']) && $_GET['filter_role_id'] !== 'all') {
        $roleName = 'ID ' . htmlspecialchars((string) $_GET['filter_role_id']);
        if (isset($roles)) {
            foreach ($roles as $r) {
                if ((string) $r['id'] === (string) $_GET['filter_role_id']) {
                    $roleName = htmlspecialchars(__($r['name']));
                    break;
                }
            }
        }
        $activeFilters[] = __('Role') . ': ' . $roleName;
    }
    if (isset($_GET['filter_visibility']) && $_GET['filter_visibility'] !== 'all') {
        $activeFilters[] = __('Visibility') . ': ' . htmlspecialchars($_GET['filter_visibility']);
    }
    if (isset($_GET['filter_content_category_id']) && $_GET['filter_content_category_id'] !== 'all') {
        $catName = 'ID ' . htmlspecialchars((string) $_GET['filter_content_category_id']);
        if (isset($categories)) {
            foreach ($categories as $c) {
                if ((string) $c['id'] === (string) $_GET['filter_content_category_id']) {
                    $catName = htmlspecialchars((string) $c['name']);
                    break;
                }
            }
        }
        $activeFilters[] = __('Category') . ': ' . $catName;
    }
    if (isset($_GET['filter_language']) && $_GET['filter_language'] !== 'all') {
        $activeFilters[] = __('Language') . ': ' . htmlspecialchars(strtoupper((string) $_GET['filter_language']));
    }
    if (isset($_GET['filter_type']) && $_GET['filter_type'] !== 'all') {
        $activeFilters[] = __('Type') . ': ' . htmlspecialchars($_GET['filter_type']);
    }
    if (isset($_GET['filter_content_id']) && $_GET['filter_content_id'] !== 'all') {
        $artName = 'ID ' . $_GET['filter_content_id'];
        if (isset($articles)) {
            foreach ($articles as $a) {
                if ((string) $a['id'] === (string) $_GET['filter_content_id']) {
                    $artName = $a['title'];
                    break;
                }
            }
        }
        $activeFilters[] = __('Article') . ': ' . htmlspecialchars($artName);
    }
    if (isset($_GET['filter_queue']) && $_GET['filter_queue'] !== 'all') {
        $activeFilters[] = __('Queue') . ': ' . htmlspecialchars($_GET['filter_queue']);
    }
    if (isset($_GET['filter_device_type']) && $_GET['filter_device_type'] !== 'all') {
        $activeFilters[] = __('Device') . ': ' . htmlspecialchars(ucfirst($_GET['filter_device_type']));
    }
    if (isset($_GET['filter_channel']) && $_GET['filter_channel'] !== 'all') {
        $activeFilters[] = __('Channel') . ': ' . htmlspecialchars(strtoupper($_GET['filter_channel']));
    }
@endphp

<div x-data="dataTableComponent({{ json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, '{{ $resource }}', {{ $currentUserId ?? 'null' }})">
    @include('components.data-table.filters')

    @include('components.data-table.active-filters')

    <!-- Data Table -->
    <div class="card card-custom p-4">
        <div class="card-body p-0">
            <!-- Desktop Table View -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-custom table-hover align-middle m-0">
                    <thead>
                        @include('components.data-table.table-header')
                    </thead>
                    <tbody>
                        @include('components.data-table.empty-state')
                        @include('components.data-table.table-body')
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards View -->
            <div class="d-md-none">
                @include('components.data-table.mobile-cards')
            </div>

            <!-- Pagination Bar -->
            @include('components.data-table.pagination')
        </div>
    </div>
</div>

@include('components.data-table.script')
