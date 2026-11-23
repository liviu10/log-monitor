@extends('layouts.admin')

@section('title', __('Logs'))

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Logs'));

    $currentPage = (int) ($page ?? 1);
    $totalPagesVal = (int) ($totalPages ?? 1);
    $range = 2;
    $startPage = max(1, $currentPage - $range);
    $endPage = min($totalPagesVal, $currentPage + $range);
    $limitOptions = [10, 25, 50, 100];
    $paginationParams = array_merge(array_filter($filters ?? [], static fn ($v) => $v !== null && $v !== ''), [
        'sort_by' => $sortBy ?? 'id',
        'sort_dir' => $sortDir ?? 'DESC',
    ]);
@endphp

<div x-data="App.logsPageData()" x-init="init()">
    @include('components.page-header', [
        'title' => __('Logs Timeline'),
        'subtitle' => __('Search, inspect JSON payloads, and monitor incoming events in real time'),
        'actionButton' => '<button type="button" @click="toggleAutoRefresh()" class="btn btn-sm d-inline-flex align-items-center gap-2 border px-3 py-2 rounded-pill fw-semibold" :class="autoRefresh ? \'btn-success border-success bg-success bg-opacity-10 text-success\' : \'btn-outline-secondary text-secondary\'"><span class="pulse-dot" :class="autoRefresh ? \'bg-success\' : \'bg-secondary\'"></span><span x-text="autoRefresh ? (window.__(\'LIVE\') + \' (\' + countdown + \'s)\') : window.__(\'LIVE OFF\')"></span></button>'
    ])

    <!-- Stats Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Total Logs'),
                'value' => $stats['total'] ?? 0,
                'color' => 'info',
                'icon' => 'fa-solid fa-chart-line',
            ])
        </div>
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Active Apps'),
                'value' => count($apps ?? []),
                'color' => 'primary',
                'icon' => 'fa-solid fa-microchip',
            ])
        </div>
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Critical Alerts'),
                'value' => $stats['critical'] ?? 0,
                'color' => 'danger',
                'icon' => 'fa-solid fa-triangle-exclamation',
            ])
        </div>
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Warnings'),
                'value' => $stats['warning'] ?? 0,
                'color' => 'warning',
                'icon' => 'fa-solid fa-bell',
            ])
        </div>
    </div>

    <!-- Filters -->
    <div class="card card-custom mb-4 border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form action="/admin/logs" method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="limit" value="{{ (int) ($limit ?? 10) }}">

                <div class="col-md-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Quick search') }}</label>
                    <div class="input-group">
                        <span class="input-group-text text-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="{{ __('Search in messages or context...') }}" value="{{ $filters['search'] ?? '' }}">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Applications') }}</label>
                    <select name="app_id" class="form-select">
                        <option value="">{{ __('All Applications') }}</option>
                        @foreach($apps as $app)
                            <option value="{{ $app['id'] }}" @if((string) ($filters['app_id'] ?? '') === (string) $app['id']) selected @endif>{{ $app['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Severity Levels') }}</label>
                    <select name="level" class="form-select">
                        <option value="">{{ __('All Levels') }}</option>
                        @foreach($levels as $level)
                            <option value="{{ $level }}" @if(($filters['level'] ?? '') === $level) selected @endif>{{ $level }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Sort By') }}</label>
                    <select name="sort_by" class="form-select">
                        <option value="id" @if(($sortBy ?? 'id') === 'id') selected @endif>{{ __('ID') }}</option>
                        <option value="created_at" @if(($sortBy ?? 'id') === 'created_at') selected @endif>{{ __('Time') }}</option>
                        <option value="level" @if(($sortBy ?? 'id') === 'level') selected @endif>{{ __('Severity') }}</option>
                        <option value="app_name" @if(($sortBy ?? 'id') === 'app_name') selected @endif>{{ __('App Name') }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Direction') }}</label>
                    <select name="sort_dir" class="form-select">
                        <option value="DESC" @if(strtoupper((string) ($sortDir ?? 'DESC')) === 'DESC') selected @endif>{{ __('Descending') }}</option>
                        <option value="ASC" @if(strtoupper((string) ($sortDir ?? 'DESC')) === 'ASC') selected @endif>{{ __('Ascending') }}</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom w-100 fw-bold">
                        <i class="fa-solid fa-filter me-1"></i>{{ __('Filter') }}
                    </button>
                    <a href="/admin/logs" class="btn btn-outline-secondary" title="{{ __('Reset Filters') }}">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Application Quick-Filter Pills -->
    <div class="mb-4">
        <span class="text-secondary small fw-bold text-uppercase d-inline-block me-3 mb-2">{{ __('Applications:') }}</span>
        <div class="d-inline-flex flex-wrap gap-2">
            <a href="/admin/logs?{{ http_build_query(array_merge($filters ?? [], ['app_id' => '', 'page' => 1])) }}"
               class="badge rounded-pill px-3 py-2 text-decoration-none {{ empty($filters['app_id']) ? 'bg-info text-dark fw-bold shadow-sm' : 'bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25' }}">
                {{ __('All Applications') }}
            </a>
            @foreach($apps as $app)
                @php
                    $isActive = (string) ($filters['app_id'] ?? '') === (string) $app['id'];
                @endphp
                <a href="/admin/logs?{{ http_build_query(array_merge($filters ?? [], ['app_id' => $app['id'], 'page' => 1])) }}"
                   class="badge rounded-pill px-3 py-2 text-decoration-none {{ $isActive ? 'bg-info text-dark fw-bold shadow-sm' : 'bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25' }}">
                    {{ $app['name'] }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- Logs List/Timeline View -->
    <div class="d-flex flex-column gap-2">
        @if(empty($logs))
            <div class="card card-custom border-0 p-5 text-center text-secondary rounded-4">
                <i class="fa-solid fa-inbox d-block fs-1 mb-3 opacity-25"></i>
                {{ __('No logs found for the selected filters.') }}
            </div>
        @endif

        @foreach($logs as $log)
            @php
                $levelUpper = strtoupper((string) ($log['level'] ?? 'INFO'));
                $accentColor = match (true) {
                    in_array($levelUpper, ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'], true) => '#ef4444',
                    $levelUpper === 'WARNING' => '#f59e0b',
                    $levelUpper === 'INFO' => '#06b6d4',
                    $levelUpper === 'NOTICE' => '#10b981',
                    default => '#64748b',
                };
                $badgeClass = match (true) {
                    in_array($levelUpper, ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'], true) => 'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25',
                    $levelUpper === 'WARNING' => 'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25',
                    $levelUpper === 'INFO' => 'bg-info bg-opacity-10 text-info border-info border-opacity-25',
                    $levelUpper === 'NOTICE' => 'bg-success bg-opacity-10 text-success border-success border-opacity-25',
                    default => 'bg-secondary bg-opacity-10 text-secondary border-secondary border-opacity-25',
                };
                $logPayload = [
                    'app' => $log['app_name'] ?? 'Unknown',
                    'level' => $levelUpper,
                    'message' => $log['message'] ?? '',
                    'timestamp' => $log['created_at'] ?? '',
                    'context' => ! empty($log['context']) ? json_decode((string) $log['context'], true) : null,
                ];
            @endphp
            <div class="card card-custom border-0 border-start border-4 shadow-sm p-3 rounded-3 cursor-pointer"
                 style="border-left-color: {{ $accentColor }} !important;"
                 @click="openLog({{ json_encode($logPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                <div class="row align-items-center g-2">
                    <div class="col-md-2 col-sm-3 d-flex align-items-center gap-2">
                        <span class="badge bg-secondary bg-opacity-25 text-body-emphasis px-2 py-1 font-monospace text-truncate" style="max-width: 140px;">
                            {{ $log['app_name'] ?? 'Unknown' }}
                        </span>
                    </div>
                    <div class="col-md-2 col-sm-3">
                        <span class="badge border rounded-pill px-2.5 py-0.5 small {{ $badgeClass }}">
                            {{ $levelUpper }}
                        </span>
                    </div>
                    <div class="col-md-5 col-sm-6 text-truncate">
                        <span class="fw-medium font-monospace">{{ $log['message'] ?? '' }}</span>
                        @if(!empty($log['context']))
                            <span class="badge bg-info bg-opacity-10 text-info ms-1 small" title="{{ __('Contains JSON context') }}">
                                <i class="fa-solid fa-code me-1"></i>JSON
                            </span>
                        @endif
                    </div>
                    <div class="col-md-2 col-sm-6 text-secondary font-monospace small">
                        {{ date('H:i:s d.m.Y', strtotime((string) ($log['created_at'] ?? 'now'))) }}
                    </div>
                    <div class="col-md-1 col-sm-6 text-end">
                        <button type="button" class="btn btn-link text-info p-0" @click.stop="openLog({{ json_encode($logPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})" aria-label="{{ __('View Details') }}">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Pagination & Page Size Controls -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">
        <div class="d-flex align-items-center gap-2">
            <label class="text-secondary small text-nowrap mb-0">{{ __('Rows per page:') }}</label>
            <select class="form-select form-select-sm" style="width: auto; cursor: pointer;" @change="window.location.href = $event.target.value">
                @foreach($limitOptions as $l)
                    @php
                        $url = '/admin/logs?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $l]));
                    @endphp
                    <option value="{{ $url }}" @if(($limit ?? 10) === $l) selected @endif>{{ $l }}</option>
                @endforeach
            </select>
        </div>

        @if($totalPagesVal > 1)
            <nav aria-label="{{ __('Pagination') }}">
                <ul class="pagination mb-0 gap-1 align-items-center">
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/logs?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) }}" title="{{ __('First') }}">
                            <i class="fa-solid fa-angles-left small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/logs?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage - 1, 'limit' => $limit])) }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left small"></i>
                        </a>
                    </li>
                    @for($i = $startPage; $i <= $endPage; $i++)
                        <li class="page-item @if($currentPage === $i) active @endif">
                            <a class="page-link rounded" href="/admin/logs?{{ http_build_query(array_merge($paginationParams, ['page' => $i, 'limit' => $limit])) }}">{{ $i }}</a>
                        </li>
                    @endfor
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/logs?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage + 1, 'limit' => $limit])) }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/logs?' . http_build_query(array_merge($paginationParams, ['page' => $totalPagesVal, 'limit' => $limit])) }}" title="{{ __('Last') }}">
                            <i class="fa-solid fa-angles-right small"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        @endif
    </div>

    <!-- Details Modal -->
    <template x-if="showModal">
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
             style="z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);"
             @click.self="showModal = false">
            <div class="card card-custom shadow-lg border border-secondary border-opacity-25 rounded-4 overflow-hidden" style="width: 100%; max-width: 800px;" @click.stop>
                <div class="card-header border-bottom border-secondary border-opacity-25 p-4 d-flex align-items-center justify-content-between">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <i class="fa-solid fa-circle-info text-info"></i>{{ __('Log Entry Details') }}
                    </h5>
                    <button type="button" class="btn-close" @click="showModal = false" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="card-body p-4 overflow-auto" style="max-height: 70vh;" x-show="selectedLog">
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Application') }}</label>
                            <div class="fs-5 fw-bold" x-text="selectedLog?.app"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Level') }}</label>
                            <span class="badge rounded-pill px-3 py-2 border"
                                  :class="{
                                      'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25': ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'].includes(selectedLog?.level),
                                      'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25': selectedLog?.level === 'WARNING',
                                      'bg-info bg-opacity-10 text-info border-info border-opacity-25': selectedLog?.level === 'INFO',
                                      'bg-success bg-opacity-10 text-success border-success border-opacity-25': selectedLog?.level === 'NOTICE',
                                      'bg-secondary bg-opacity-10 text-secondary border-secondary border-opacity-25': !['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT', 'WARNING', 'INFO', 'NOTICE'].includes(selectedLog?.level)
                                  }"
                                  x-text="selectedLog?.level"></span>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Timestamp') }}</label>
                            <div class="text-secondary font-monospace" x-text="selectedLog?.timestamp"></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Message') }}</label>
                        <div class="p-3 bg-body-tertiary rounded border border-secondary border-opacity-25 font-monospace text-break" x-text="selectedLog?.message"></div>
                    </div>

                    <div>
                        <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Context (JSON)') }}</label>
                        <div class="bg-dark p-3 rounded border border-secondary border-opacity-25" style="max-height: 400px; overflow-y: auto;">
                            <pre class="mb-0 text-info small" style="white-space: pre-wrap; word-wrap: break-word;"><code x-text="JSON.stringify(selectedLog?.context, null, 4)"></code></pre>
                        </div>
                    </div>
                </div>
                <div class="card-footer border-top border-secondary border-opacity-25 p-3 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-4" @click="showModal = false">{{ __('Close') }}</button>
                    <button type="button"
                            class="btn btn-sm rounded-pill px-4 fw-bold"
                            :class="payloadCopied ? 'btn-success' : 'btn-info text-white'"
                            @click="copyLog()">
                        <span x-show="!payloadCopied"><i class="fa-solid fa-copy me-2"></i>{{ __('Copy Log') }}</span>
                        <span x-show="payloadCopied" x-cloak><i class="fa-solid fa-check me-2"></i>{{ __('Log Copied') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

