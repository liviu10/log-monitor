@extends('layouts.admin')

@section('title', __('Notification Queue'))

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Notification Queue'));

    $currentPage = (int) ($page ?? 1);
    $totalPagesVal = (int) ($totalPages ?? 1);
    $range = 2;
    $startPage = max(1, $currentPage - $range);
    $endPage = min($totalPagesVal, $currentPage + $range);
    $limitOptions = [10, 25, 50, 100];
    $currentSearch = (string) ($filters['search'] ?? '');
    $currentStatus = (string) ($filters['status'] ?? 'all');
    $currentQueue = (string) ($filters['queue'] ?? 'all');
    $paginationParams = array_filter([
        'search' => $currentSearch !== '' ? $currentSearch : null,
        'filter_status' => $currentStatus !== 'all' ? $currentStatus : null,
        'filter_queue' => $currentQueue !== 'all' ? $currentQueue : null,
        'sort_by' => $sortBy ?? 'id',
        'sort_dir' => $sortDir ?? 'DESC',
    ], static fn ($v) => $v !== null);
@endphp

@include('components.page-header', [
    'title' => __('Notification Queue'),
    'subtitle' => __('Asynchronous email and alert notification jobs processed with automatic retries and dead-letter recovery')
])

<div x-data="App.notificationQueuePageData()">
    <!-- Stats Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 rounded-4">
                <div class="text-secondary small fw-medium mb-1">{{ __('Total Jobs') }}</div>
                <div class="h4 fw-bold text-body-emphasis mb-0">{{ number_format($stats['total'] ?? 0) }}</div>
            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 rounded-4 border-start border-warning border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-clock text-warning"></i>
                    <span>{{ __('Pending') }}</span>
                </div>
                <div class="h4 fw-bold text-warning mb-0">{{ number_format($stats['pending'] ?? 0) }}</div>
            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 rounded-4 border-start border-info border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-spinner fa-spin text-info"></i>
                    <span>{{ __('Processing') }}</span>
                </div>
                <div class="h4 fw-bold text-info mb-0">{{ number_format($stats['processing'] ?? 0) }}</div>
            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 rounded-4 border-start border-success border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-circle-check text-success"></i>
                    <span>{{ __('Completed') }}</span>
                </div>
                <div class="h4 fw-bold text-success mb-0">{{ number_format($stats['completed'] ?? 0) }}</div>
            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 rounded-4 border-start border-danger border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-triangle-exclamation text-danger"></i>
                    <span>{{ __('Failed (DLQ)') }}</span>
                </div>
                <div class="h4 fw-bold text-danger mb-0">{{ number_format($stats['failed'] ?? 0) }}</div>
            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 rounded-4 border-start border-secondary border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-calendar-xmark text-secondary"></i>
                    <span>{{ __('Expired') }}</span>
                </div>
                <div class="h4 fw-bold text-secondary mb-0">{{ number_format($stats['expired'] ?? 0) }}</div>
            </div>
        </div>
    </div>

    <!-- Global Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h3 class="h5 mb-0 fw-bold">{{ __('Active Notifications Buffer') }}</h3>
            @if(!empty($isWorkerRunning))
                <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2.5 py-1 rounded-pill d-inline-flex align-items-center gap-1.5 small">
                    <span class="status-dot pulse" style="background-color: var(--bs-success);"></span>
                    {{ __('Worker Active') }}
                </span>
            @else
                <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2.5 py-1 rounded-pill d-inline-flex align-items-center gap-1.5 small">
                    <span class="status-dot" style="background-color: var(--bs-secondary);"></span>
                    {{ __('Worker Idle') }}
                </span>
            @endif
        </div>

        <div class="d-flex flex-wrap gap-2">
            @if(hasPermission('admin.notificationqueue.runworker'))
            <form action="/admin/notification-queue/run-worker" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Running...') }}';}">
                {!! csrf_field() !!}
                <button type="submit" class="btn btn-outline-primary btn-sm rounded-3 d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-play"></i>
                    <span>{{ __('Run Worker Now') }}</span>
                </button>
            </form>
            @endif

            @if(hasPermission('admin.notificationqueue.retryall'))
            <button type="button" @click="confirmRetryAll()" class="btn btn-outline-warning btn-sm rounded-3 d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold" {{ ($stats['failed'] ?? 0) === 0 ? 'disabled' : '' }}>
                <i class="fa-solid fa-rotate-right"></i>
                <span>{{ __('Retry All Failed') }}</span>
            </button>
            @endif

            @if(hasPermission('admin.notificationqueue.purge'))
            <button type="button" @click="confirmPurge()" class="btn btn-outline-danger btn-sm rounded-3 d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                <i class="fa-solid fa-broom"></i>
                <span>{{ __('Purge Processed') }}</span>
            </button>
            @endif
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="card card-custom mb-4 border-0 shadow-sm rounded-4">
        <div class="card-body p-3">
            <form action="/admin/notification-queue" method="GET" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <input type="hidden" name="sort_by" value="{{ $sortBy ?? 'id' }}">
                <input type="hidden" name="sort_dir" value="{{ $sortDir ?? 'DESC' }}">

                <div class="input-group input-group-sm" style="max-width: 320px;">
                    <span class="input-group-text text-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="{{ __('Search ID, queue, payload, error...') }}" value="{{ $currentSearch }}">
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-secondary small text-nowrap">{{ __('Show') }}</span>
                        <select name="limit" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                            @foreach($limitOptions as $l)
                                <option value="{{ $l }}" @if((int) ($limit ?? 10) === $l) selected @endif>{{ $l }}</option>
                            @endforeach
                        </select>
                        <span class="text-secondary small text-nowrap">{{ __('entries') }}</span>
                    </div>

                    <select name="filter_status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <option value="all" @if($currentStatus === 'all') selected @endif>{{ __('All Statuses') }}</option>
                        <option value="pending" @if($currentStatus === 'pending') selected @endif>{{ __('Pending') }}</option>
                        <option value="processing" @if($currentStatus === 'processing') selected @endif>{{ __('Processing') }}</option>
                        <option value="completed" @if($currentStatus === 'completed') selected @endif>{{ __('Completed') }}</option>
                        <option value="failed" @if($currentStatus === 'failed') selected @endif>{{ __('Failed') }}</option>
                        <option value="expired" @if($currentStatus === 'expired') selected @endif>{{ __('Expired') }}</option>
                    </select>

                    <select name="filter_queue" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <option value="all" @if($currentQueue === 'all') selected @endif>{{ __('All Queues') }}</option>
                        @foreach(($distinctQueues ?? []) as $qName)
                            <option value="{{ $qName }}" @if($currentQueue === $qName) selected @endif>{{ ucfirst($qName) }}</option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn btn-sm btn-primary-custom fw-semibold px-3">
                        <i class="fa-solid fa-filter me-1"></i>{{ __('Filter') }}
                    </button>

                    <a href="/admin/notification-queue" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1 px-3">
                        <i class="fa-solid fa-filter-circle-xmark"></i>
                        <span>{{ __('Reset') }}</span>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table of Notification Queue Jobs (matches /admin/log-queue table style) -->
    <div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4" style="width: 110px;">
                                @php
                                    $nextDirId = ($sortBy === 'id' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlId = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit, 'sort_by' => 'id', 'sort_dir' => $nextDirId]));
                                @endphp
                                <a href="{{ $urlId }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Job ID') }}
                                    @if($sortBy === 'id')
                                        <i class="fa-solid fa-sort-{{ strtoupper($sortDir) === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th style="width: 130px;">
                                @php
                                    $nextDirQueue = ($sortBy === 'queue' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlQueue = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit, 'sort_by' => 'queue', 'sort_dir' => $nextDirQueue]));
                                @endphp
                                <a href="{{ $urlQueue }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Queue') }}
                                    @if($sortBy === 'queue')
                                        <i class="fa-solid fa-sort-{{ strtoupper($sortDir) === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th>{{ __('Payload Preview') }}</th>
                            <th style="width: 135px;">
                                @php
                                    $nextDirStatus = ($sortBy === 'status' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlStatus = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit, 'sort_by' => 'status', 'sort_dir' => $nextDirStatus]));
                                @endphp
                                <a href="{{ $urlStatus }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Status') }}
                                    @if($sortBy === 'status')
                                        <i class="fa-solid fa-sort-{{ strtoupper($sortDir) === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th style="width: 110px;">
                                @php
                                    $nextDirAttempts = ($sortBy === 'attempts' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlAttempts = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit, 'sort_by' => 'attempts', 'sort_dir' => $nextDirAttempts]));
                                @endphp
                                <a href="{{ $urlAttempts }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Attempts') }}
                                    @if($sortBy === 'attempts')
                                        <i class="fa-solid fa-sort-{{ strtoupper($sortDir) === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th style="width: 175px;">
                                @php
                                    $nextDirAvailable = ($sortBy === 'available_at' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlAvailable = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit, 'sort_by' => 'available_at', 'sort_dir' => $nextDirAvailable]));
                                @endphp
                                <a href="{{ $urlAvailable }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Available At') }}
                                    @if($sortBy === 'available_at')
                                        <i class="fa-solid fa-sort-{{ strtoupper($sortDir) === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th style="width: 175px;">
                                @php
                                    $nextDirCreated = ($sortBy === 'created_at' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlCreated = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit, 'sort_by' => 'created_at', 'sort_dir' => $nextDirCreated]));
                                @endphp
                                <a href="{{ $urlCreated }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Created At') }}
                                    @if($sortBy === 'created_at')
                                        <i class="fa-solid fa-sort-{{ strtoupper($sortDir) === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th class="text-end pe-4" style="width: 170px;">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($jobs as $job)
                            @php
                                $rawPayload = (string) ($job['payload'] ?? '');
                                $decoded = json_decode($rawPayload, true);
                                $previewSummary = $rawPayload;
                                if (is_array($decoded)) {
                                    $innerData = is_array($decoded['payload']['data'] ?? null) ? $decoded['payload']['data'] : [];
                                    $to = is_string($innerData['to'] ?? null) ? $innerData['to'] : '';
                                    $event = is_string($decoded['payload']['template_event'] ?? null) ? $decoded['payload']['template_event'] : (is_string($decoded['action'] ?? null) ? $decoded['action'] : '');
                                    $subject = is_string($innerData['subject'] ?? null) ? $innerData['subject'] : '';
                                    $parts = array_filter([$to, $event, $subject], static fn ($v) => $v !== '');
                                    if ($parts !== []) {
                                        $previewSummary = implode(' · ', $parts);
                                    }
                                }
                                $previewSummary = mb_strimwidth($previewSummary !== '' ? $previewSummary : 'N/A', 0, 75, '...');
                                $payloadSize = number_format(strlen($rawPayload) / 1024, 2) . ' KB';

                                $queueVal = (string) ($job['queue'] ?? 'default');
                                $queueBadge = match ($queueVal) {
                                    'critical' => 'bg-danger text-white',
                                    'alerts' => 'bg-warning text-dark',
                                    'low' => 'bg-secondary text-white',
                                    default => 'bg-primary text-white',
                                };

                                $statusVal = (string) ($job['status'] ?? 'pending');
                                $statusPillClass = match ($statusVal) {
                                    'completed' => 'status-active',
                                    'processing' => 'status-info',
                                    'pending' => 'status-pending',
                                    'expired' => 'status-inactive',
                                    default => 'status-danger',
                                };
                            @endphp
                            <tr>
                                <td class="ps-4 text-secondary font-monospace">#{{ $job['id'] }}</td>
                                <td>
                                    <span class="badge {{ $queueBadge }}">{{ $queueVal }}</span>
                                </td>
                                <td class="small">
                                    <span>{{ $previewSummary }}</span>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary ms-2">{{ $payloadSize }}</span>
                                </td>
                                <td>
                                    <span class="badge-status-pill {{ $statusPillClass }}">
                                        <span class="status-dot @if($statusVal === 'processing' || $statusVal === 'pending') pulse @endif"></span>
                                        <span>{{ $statusVal }}</span>
                                    </span>
                                </td>
                                <td class="small fw-semibold font-monospace">
                                    {{ (int) ($job['attempts'] ?? 0) }} / {{ (int) ($job['max_attempts'] ?? 5) }}
                                </td>
                                <td class="text-secondary small font-monospace text-nowrap">{{ $job['available_at'] ?: '-' }}</td>
                                <td class="text-secondary small font-monospace text-nowrap">{{ $job['created_at'] }}</td>
                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-1.5 align-items-center justify-content-end">
                                        <button type="button" class="btn btn-sm btn-outline-info px-2 py-1 rounded-3" title="{{ __('View Payload') }}" @click="showPayload({{ json_encode($job, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                        @if(!empty($job['error_message']))
                                        <button type="button" class="btn btn-sm btn-outline-warning px-2 py-1 rounded-3" title="{{ __('View Error Details') }}" @click="showError({{ json_encode($job, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                        </button>
                                        @endif
                                        @if(($statusVal === 'failed' || $statusVal === 'expired') && hasPermission('admin.notificationqueue.retry'))
                                        <button type="button" class="btn btn-sm btn-outline-primary px-2 py-1 rounded-3" title="{{ __('Retry Job') }}" @click="confirmRetrySingle({{ json_encode($job, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                            <i class="fa-solid fa-rotate-right"></i>
                                        </button>
                                        @endif
                                        @if(hasPermission('admin.notificationqueue.delete'))
                                        <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 rounded-3" title="{{ __('Delete Job') }}" @click="confirmDelete({{ json_encode($job, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if(empty($jobs))
                            <tr>
                                <td colspan="8" class="text-center py-5 text-secondary">
                                    <i class="fa-solid fa-inbox fs-2 mb-2 opacity-25 d-block"></i>
                                    <p class="mb-0 small">{{ __('No jobs currently in the notification queue.') }}</p>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination & Page Size Controls -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">
        <div class="d-flex align-items-center gap-2">
            <label class="text-secondary small text-nowrap mb-0">{{ __('Rows per page:') }}</label>
            <select class="form-select form-select-sm" style="width: auto; cursor: pointer;" @change="window.location.href = $event.target.value">
                @foreach($limitOptions as $l)
                    @php
                        $url = '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $l]));
                    @endphp
                    <option value="{{ $url }}" @if((int) $limit === $l) selected @endif>{{ $l }}</option>
                @endforeach
            </select>
        </div>

        @if($totalPagesVal > 1)
            <nav aria-label="{{ __('Pagination') }}">
                <ul class="pagination mb-0 gap-1 align-items-center">
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) }}" title="{{ __('First') }}">
                            <i class="fa-solid fa-angles-left small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage - 1, 'limit' => $limit])) }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left small"></i>
                        </a>
                    </li>
                    @for($i = $startPage; $i <= $endPage; $i++)
                        <li class="page-item @if($currentPage === $i) active @endif">
                            <a class="page-link rounded" href="/admin/notification-queue?{{ http_build_query(array_merge($paginationParams, ['page' => $i, 'limit' => $limit])) }}">{{ $i }}</a>
                        </li>
                    @endfor
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage + 1, 'limit' => $limit])) }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/notification-queue?' . http_build_query(array_merge($paginationParams, ['page' => $totalPagesVal, 'limit' => $limit])) }}" title="{{ __('Last') }}">
                            <i class="fa-solid fa-angles-right small"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        @endif
    </div>

    <!-- Payload Viewer Modal -->
    <div class="modal fade" id="queuePayloadModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-code text-info me-2"></i>{{ __('Job Payload') }} #<span x-text="activeJob?.id"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 small text-secondary">
                        <div>
                            <span>{{ __('Queue') }}:</span>
                            <span class="badge bg-secondary ms-1" x-text="activeJob?.queue"></span>
                        </div>
                        <div>
                            <span>{{ __('Attempts') }}:</span>
                            <span class="fw-bold ms-1" x-text="activeJob?.attempts"></span>
                        </div>
                    </div>
                    <pre class="bg-dark text-info p-3 rounded-3 small mb-0 font-monospace" style="max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;" x-text="formattedPayload"></pre>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    <button type="button"
                            class="btn btn-sm rounded-pill px-4 fw-bold"
                            :class="payloadCopied ? 'btn-success' : 'btn-info text-white'"
                            @click="copyJobPayload()">
                        <span x-show="!payloadCopied"><i class="fa-solid fa-copy me-2"></i>{{ __('Copy Payload') }}</span>
                        <span x-show="payloadCopied" x-cloak><i class="fa-solid fa-check me-2"></i>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Details Modal -->
    <div class="modal fade" id="queueErrorModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>{{ __('Error Details') }} #<span x-text="activeJob?.id"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body py-3">
                    <div class="p-3 rounded-3 border border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger mb-3">
                        <h6 class="fw-bold mb-1">{{ __('Failure Reason') }}</h6>
                        <div class="small" x-text="activeJob?.error_message || {{ json_encode(__('No error message logged.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></div>
                    </div>
                    <div class="small text-secondary mb-2">{{ __('Available / Next Attempt') }}: <span class="text-body-emphasis fw-semibold" x-text="activeJob?.available_at || '-'"></span></div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    @if(hasPermission('admin.notificationqueue.retry'))
                    <form :action="'/admin/notification-queue/' + activeJob?.id + '/retry'" method="POST" class="m-0">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="fa-solid fa-rotate-right me-1"></i>{{ __('Retry Job Now') }}
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Retry Single Modal -->
    <div class="modal fade" id="queueRetryConfirmModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-rotate-right text-primary me-2"></i>{{ __('Confirm Job Retry') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-rotate-right fa-3x text-primary mb-3"></i>
                    <p class="fs-6 fw-medium text-body-emphasis mb-1">
                        {{ __('Are you sure you want to retry job') }} #<span x-text="activeJob?.id"></span>?
                    </p>
                    <p class="text-secondary small mb-0">{{ __('The status will be reset to pending and attempts reset to 0 for immediate execution.') }}</p>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <form :action="'/admin/notification-queue/' + activeJob?.id + '/retry'" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Retrying...') }}';}">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-primary rounded-pill px-4">{{ __('Retry Job') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Retry All Modal -->
    <div class="modal fade" id="queueRetryAllModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold text-warning">
                        <i class="fa-solid fa-rotate-right me-2"></i>{{ __('Retry All Failed Jobs') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-triangle-exclamation fa-3x text-warning mb-3"></i>
                    <p class="fs-6 fw-medium text-body-emphasis mb-1">
                        {{ __('Reset and retry all currently failed jobs in Dead-Letter Queue?') }}
                    </p>
                    <p class="text-secondary small mb-0">{{ __('A pre-flight health check will test external dependencies before queuing.') }}</p>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <form action="/admin/notification-queue/retry-all" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Retrying...') }}';}">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-warning rounded-pill px-4">{{ __('Retry All') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div class="modal fade" id="queueDeleteModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fa-solid fa-trash-can me-2"></i>{{ __('Delete Queue Job') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-trash-can fa-3x text-danger mb-3"></i>
                    <p class="fs-6 fw-medium text-body-emphasis mb-1">
                        {{ __('Are you sure you want to permanently delete job') }} #<span x-text="activeJob?.id"></span>?
                    </p>
                    <p class="text-secondary small mb-0">{{ __('This action cannot be undone.') }}</p>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <form :action="'/admin/notification-queue/' + activeJob?.id + '/delete'" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Deleting...') }}';}">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-danger rounded-pill px-4">{{ __('Delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Purge Modal -->
    <div class="modal fade" id="queuePurgeModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fa-solid fa-broom me-2"></i>{{ __('Purge Processed Jobs') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-broom fa-3x text-danger mb-3"></i>
                    <p class="fs-6 fw-medium text-body-emphasis mb-1">
                        {{ __('Purge completed, failed, and expired jobs older than retention period?') }}
                    </p>
                    <p class="text-secondary small mb-3">{{ __('Select retention threshold in days:') }}</p>
                    <form id="purge-notification-queue-form" action="/admin/notification-queue/purge" method="POST">
                        {!! csrf_field() !!}
                        <select name="days" class="form-select w-auto mx-auto mb-2">
                            <option value="7">7 {{ __('days') }}</option>
                            <option value="14">14 {{ __('days') }}</option>
                            <option value="30" selected>30 {{ __('days') }}</option>
                            <option value="60">60 {{ __('days') }}</option>
                            <option value="90">90 {{ __('days') }}</option>
                        </select>
                    </form>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" @click="const b=$event.currentTarget; b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Purging...') }}'; document.getElementById('purge-notification-queue-form').submit();" class="btn btn-danger rounded-pill px-4">{{ __('Purge Now') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

