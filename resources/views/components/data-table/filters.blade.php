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
@endphp
<div class="card card-custom p-4 mb-4">
    <!-- Filter, Sort and Search Bar -->
    <div class="row g-3 align-items-center justify-content-between">
        
        <!-- Global Search (left) -->
        <div class="col-md-4">
            @include('components.search-input')
        </div>

        <!-- Dynamic Filters (right) -->
        <div class="col-md-8 d-flex flex-wrap gap-2 justify-content-md-end align-items-center">
            
            <!-- Items per page selector -->
            <div class="d-flex flex-wrap align-items-center gap-2 filter-control-wrap">
                <span class="text-secondary small">{{ __('Show') }}</span>
                <select x-model="perPage" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span class="text-secondary small">{{ __('entries') }}</span>
            </div>



            <!-- Dynamic Resource Filters -->
            <!-- Users Filters -->
            @if($resource === 'users')
                <div class="d-flex flex-wrap gap-2 filter-control-wrap">
                    <select x-model="filterStatus" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>{{ __('All Statuses') }}</option>
                        <option value="Active" {{ $currentStatus === 'Active' ? 'selected' : '' }}>{{ __('Active') }}</option>
                        <option value="Inactive" {{ $currentStatus === 'Inactive' ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                    </select>

                    <select x-model="filterRole" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ (string)$currentRole === 'all' ? 'selected' : '' }}>{{ __('All Roles') }}</option>
                        @if(isset($roles))
                            @foreach($roles as $role)
                                <option value="{{ $role['id'] }}" {{ (string)$currentRole === (string)$role['id'] ? 'selected' : '' }}>
                                    {{ __($role['name']) }}
                                </option>
                            @endforeach
                        @endif
                    </select>
                </div>
            @endif

            <!-- Roles Filters -->
            @if($resource === 'roles')
                <div class="d-flex flex-wrap gap-2 filter-control-wrap">
                    <select x-model="filterStatus" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>{{ __('All Statuses') }}</option>
                        <option value="active" {{ in_array($currentStatus, ['active', '1'], true) ? 'selected' : '' }}>{{ __('Active') }}</option>
                        <option value="inactive" {{ in_array($currentStatus, ['inactive', '0'], true) ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                    </select>
                </div>
            @endif

            <!-- Queue Filters -->
            @if($resource === 'queue')
                <div class="d-flex flex-wrap gap-2 filter-control-wrap">
                    <select x-model="filterStatus" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>{{ __('All Statuses') }}</option>
                        <option value="pending" {{ $currentStatus === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                        <option value="processing" {{ $currentStatus === 'processing' ? 'selected' : '' }}>{{ __('Processing') }}</option>
                        <option value="completed" {{ $currentStatus === 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                        <option value="failed" {{ $currentStatus === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                        <option value="expired" {{ $currentStatus === 'expired' ? 'selected' : '' }}>{{ __('Expired') }}</option>
                    </select>

                    <select x-model="filterQueue" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ $currentQueue === 'all' ? 'selected' : '' }}>{{ __('All Queues') }}</option>
                        @if(isset($distinctQueues))
                            @foreach($distinctQueues as $qName)
                                <option value="{{ $qName }}" {{ $currentQueue === $qName ? 'selected' : '' }}>{{ ucfirst($qName) }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>
            @endif

            <!-- Notification Templates Filters -->
            @if($resource === 'templates')
                <div class="d-flex flex-wrap gap-2 filter-control-wrap">
                    <select x-model="filterChannel" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ ($currentChannel ?? 'all') === 'all' ? 'selected' : '' }}>{{ __('All Channels') }}</option>
                        @if(isset($channels))
                            @foreach($channels as $ch)
                                <option value="{{ $ch }}" {{ ($currentChannel ?? 'all') === $ch ? 'selected' : '' }}>{{ strtoupper($ch) }}</option>
                            @endforeach
                        @endif
                    </select>

                    <select x-model="filterLanguage" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ ($currentLanguage ?? 'all') === 'all' ? 'selected' : '' }}>{{ __('All Languages') }}</option>
                        @if(isset($languages))
                            @foreach($languages as $code => $name)
                                <option value="{{ $code }}" {{ ($currentLanguage ?? 'all') === $code ? 'selected' : '' }}>{{ strtoupper($code) }} - {{ $name }}</option>
                            @endforeach
                        @endif
                    </select>

                    <select x-model="filterIsActive" @change="currentPage = 1; updateUrl()" class="form-select w-auto py-1">
                        <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>{{ __('All Statuses') }}</option>
                        <option value="1" {{ ($currentStatus === 'active' || $currentStatus === '1') ? 'selected' : '' }}>{{ __('Active') }}</option>
                        <option value="0" {{ ($currentStatus === 'inactive' || $currentStatus === '0') ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                    </select>
                </div>
            @endif

            <!-- Reset Button -->
            <button @click="resetParams()" class="btn btn-outline-danger py-1 px-3 d-flex align-items-center gap-2">
                <i class="fa-solid fa-filter-circle-xmark"></i> {{ __('Reset') }}
            </button>

        </div>
    </div>
</div>
