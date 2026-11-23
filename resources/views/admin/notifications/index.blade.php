@extends('layouts.admin')

@section('title', __('Notifications'))

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Notifications'));

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

<div x-data="App.notificationsPageData()">
    @include('components.page-header', [
        'title' => __('Sent Notifications'),
        'subtitle' => __('Inspect delivered alert emails, verification messages, and complete notification payloads')
    ])

    <!-- Stats Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Total Sent'),
                'value' => $stats['total'] ?? 0,
                'color' => 'info',
                'icon' => 'fa-solid fa-paper-plane',
            ])
        </div>
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Alert Notifications'),
                'value' => $stats['alerts'] ?? 0,
                'color' => 'warning',
                'icon' => 'fa-solid fa-triangle-exclamation',
            ])
        </div>
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('System Emails'),
                'value' => $stats['emails'] ?? 0,
                'color' => 'primary',
                'icon' => 'fa-solid fa-envelope-circle-check',
            ])
        </div>
        <div class="col-md-3">
            @include('components.stat-card', [
                'title' => __('Sent Today'),
                'value' => $stats['today'] ?? 0,
                'color' => 'success',
                'icon' => 'fa-solid fa-calendar-check',
            ])
        </div>
    </div>

    <!-- Filters -->
    <div class="card card-custom mb-4 border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form action="/admin/notifications" method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="limit" value="{{ (int) ($limit ?? 10) }}">

                <div class="col-md-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Quick search') }}</label>
                    <div class="input-group">
                        <span class="input-group-text text-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="{{ __('Search recipient, event, or payload...') }}" value="{{ $filters['search'] ?? '' }}">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Queue') }}</label>
                    <select name="queue" class="form-select">
                        <option value="">{{ __('All Queues') }}</option>
                        @foreach(($queues ?? []) as $qName)
                            <option value="{{ $qName }}" @if(($filters['queue'] ?? '') === $qName) selected @endif>{{ ucfirst($qName) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Sort By') }}</label>
                    <select name="sort_by" class="form-select">
                        <option value="id" @if(($sortBy ?? 'id') === 'id') selected @endif>{{ __('ID') }}</option>
                        <option value="processed_at" @if(($sortBy ?? 'id') === 'processed_at') selected @endif>{{ __('Sent At') }}</option>
                        <option value="created_at" @if(($sortBy ?? 'id') === 'created_at') selected @endif>{{ __('Created At') }}</option>
                        <option value="queue" @if(($sortBy ?? 'id') === 'queue') selected @endif>{{ __('Queue') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-secondary small fw-bold text-uppercase">{{ __('Direction') }}</label>
                    <select name="sort_dir" class="form-select">
                        <option value="DESC" @if(strtoupper((string) ($sortDir ?? 'DESC')) === 'DESC') selected @endif>{{ __('Descending') }}</option>
                        <option value="ASC" @if(strtoupper((string) ($sortDir ?? 'DESC')) === 'ASC') selected @endif>{{ __('Ascending') }}</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom w-100 fw-bold">
                        <i class="fa-solid fa-filter me-1"></i>{{ __('Filter') }}
                    </button>
                    <a href="/admin/notifications" class="btn btn-outline-secondary" title="{{ __('Reset Filters') }}">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Queue Quick-Filter Pills -->
    <div class="mb-4">
        <span class="text-secondary small fw-bold text-uppercase d-inline-block me-3 mb-2">{{ __('Queues:') }}</span>
        <div class="d-inline-flex flex-wrap gap-2">
            <a href="/admin/notifications?{{ http_build_query(array_merge($filters ?? [], ['queue' => '', 'page' => 1])) }}"
               class="badge rounded-pill px-3 py-2 text-decoration-none {{ empty($filters['queue']) ? 'bg-info text-dark fw-bold shadow-sm' : 'bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25' }}">
                {{ __('All Queues') }}
            </a>
            @foreach(($queues ?? []) as $qName)
                @php
                    $isActive = ($filters['queue'] ?? '') === $qName;
                @endphp
                <a href="/admin/notifications?{{ http_build_query(array_merge($filters ?? [], ['queue' => $qName, 'page' => 1])) }}"
                   class="badge rounded-pill px-3 py-2 text-decoration-none {{ $isActive ? 'bg-info text-dark fw-bold shadow-sm' : 'bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25' }}">
                    {{ ucfirst($qName) }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- Sent Notifications Timeline List View -->
    <div class="d-flex flex-column gap-2">
        @if(empty($notifications))
            <div class="card card-custom border-0 p-5 text-center text-secondary rounded-4">
                <i class="fa-solid fa-bell-slash d-block fs-1 mb-3 opacity-25"></i>
                {{ __('No sent notifications found for the selected filters.') }}
            </div>
        @endif

        @foreach($notifications as $item)
            @php
                $rawPayload = (string) ($item['payload'] ?? '');
                $decoded = json_decode($rawPayload, true);
                if (! is_array($decoded)) {
                    $decoded = [];
                }

                $action = is_string($decoded['action'] ?? null) ? $decoded['action'] : 'email';
                $innerPayload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
                $innerData = is_array($innerPayload['data'] ?? null) ? $innerPayload['data'] : [];
                $templateEvent = is_string($innerPayload['template_event'] ?? null) && $innerPayload['template_event'] !== ''
                    ? $innerPayload['template_event']
                    : $action;

                $recipient = is_string($innerData['to'] ?? null) && $innerData['to'] !== '' ? $innerData['to'] : __('N/A');
                $appName = is_string($innerData['app_name'] ?? null) ? $innerData['app_name'] : '';
                $alertLevel = strtoupper(is_string($innerData['level'] ?? null) ? $innerData['level'] : '');
                $subjectOrMsg = is_string($innerData['subject'] ?? null) && $innerData['subject'] !== ''
                    ? $innerData['subject']
                    : (is_string($innerData['message'] ?? null) && $innerData['message'] !== ''
                        ? $innerData['message']
                        : $templateEvent);
                if ($appName !== '' && $alertLevel !== '') {
                    $summaryLine = "[{$appName} / {$alertLevel}] {$subjectOrMsg}";
                } else {
                    $summaryLine = $subjectOrMsg;
                }

                $queueName = (string) ($item['queue'] ?? 'default');
                $accentColor = match (true) {
                    $queueName === 'alerts' && in_array($alertLevel, ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'], true) => '#ef4444',
                    $queueName === 'alerts' => '#f59e0b',
                    $queueName === 'emails' => '#06b6d4',
                    default => '#10b981',
                };
                $badgeClass = match ($queueName) {
                    'alerts' => 'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25',
                    'emails' => 'bg-info bg-opacity-10 text-info border-info border-opacity-25',
                    default => 'bg-success bg-opacity-10 text-success border-success border-opacity-25',
                };

                $sentAtRaw = (string) ($item['processed_at'] ?? ($item['created_at'] ?? 'now'));
                $sentAtFormatted = date('H:i:s d.m.Y', strtotime($sentAtRaw));

                $modalData = [
                    'id' => $item['id'] ?? '',
                    'queue' => $queueName,
                    'action' => strtoupper($action),
                    'event' => $templateEvent,
                    'recipient' => $recipient,
                    'summary' => $summaryLine,
                    'sent_at' => $sentAtRaw,
                    'created_at' => $item['created_at'] ?? '',
                    'attempts' => $item['attempts'] ?? 1,
                    'payload' => $decoded !== [] ? $decoded : $rawPayload,
                ];
            @endphp
            <div class="card card-custom border-0 border-start border-4 shadow-sm p-3 rounded-3 cursor-pointer"
                 style="border-left-color: {{ $accentColor }} !important;"
                 @click="openNotification({{ json_encode($modalData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                <div class="row align-items-center g-2">
                    <div class="col-md-2 col-sm-4 d-flex align-items-center gap-2">
                        <span class="badge border rounded-pill px-2.5 py-0.5 small {{ $badgeClass }}">
                            {{ strtoupper($queueName) }}
                        </span>
                        <span class="text-secondary font-monospace small">#{{ $item['id'] ?? '' }}</span>
                    </div>
                    <div class="col-md-3 col-sm-8 text-truncate">
                        <i class="fa-regular fa-envelope text-secondary me-1 small"></i>
                        <span class="fw-medium font-monospace small">{{ $recipient }}</span>
                    </div>
                    <div class="col-md-4 col-sm-6 text-truncate">
                        <span class="fw-medium">{{ mb_strimwidth($summaryLine, 0, 90, '...') }}</span>
                        <span class="badge bg-info bg-opacity-10 text-info ms-1 small" title="{{ __('Contains JSON payload') }}">
                            <i class="fa-solid fa-code me-1"></i>JSON
                        </span>
                    </div>
                    <div class="col-md-2 col-sm-4 text-secondary font-monospace small">
                        {{ $sentAtFormatted }}
                    </div>
                    <div class="col-md-1 col-sm-2 text-end">
                        <button type="button" class="btn btn-link text-info p-0" @click.stop="openNotification({{ json_encode($modalData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})" aria-label="{{ __('View Details') }}">
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
                        $url = '/admin/notifications?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $l]));
                    @endphp
                    <option value="{{ $url }}" @if(($limit ?? 10) === $l) selected @endif>{{ $l }}</option>
                @endforeach
            </select>
        </div>

        @if($totalPagesVal > 1)
            <nav aria-label="{{ __('Pagination') }}">
                <ul class="pagination mb-0 gap-1 align-items-center">
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/notifications?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) }}" title="{{ __('First') }}">
                            <i class="fa-solid fa-angles-left small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/notifications?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage - 1, 'limit' => $limit])) }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left small"></i>
                        </a>
                    </li>
                    @for($i = $startPage; $i <= $endPage; $i++)
                        <li class="page-item @if($currentPage === $i) active @endif">
                            <a class="page-link rounded" href="/admin/notifications?{{ http_build_query(array_merge($paginationParams, ['page' => $i, 'limit' => $limit])) }}">{{ $i }}</a>
                        </li>
                    @endfor
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/notifications?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage + 1, 'limit' => $limit])) }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/notifications?' . http_build_query(array_merge($paginationParams, ['page' => $totalPagesVal, 'limit' => $limit])) }}" title="{{ __('Last') }}">
                            <i class="fa-solid fa-angles-right small"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        @endif
    </div>

    <!-- Notification Details Modal -->
    <template x-if="showModal">
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
             style="z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);"
             @click.self="showModal = false">
            <div class="card card-custom shadow-lg border border-secondary border-opacity-25 rounded-4 overflow-hidden" style="width: 100%; max-width: 800px;" @click.stop>
                <div class="card-header border-bottom border-secondary border-opacity-25 p-4 d-flex align-items-center justify-content-between">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <i class="fa-solid fa-bell text-info"></i>{{ __('Sent Notification Details') }} <span class="text-info">#<span x-text="selectedNotification?.id"></span></span>
                    </h5>
                    <button type="button" class="btn-close" @click="showModal = false" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="card-body p-4 overflow-auto" style="max-height: 70vh;" x-show="selectedNotification">
                    <div class="row g-4 mb-4">
                        <div class="col-md-5">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Recipient') }}</label>
                            <div class="fw-bold font-monospace text-break" x-text="selectedNotification?.recipient"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Queue / Event') }}</label>
                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-3 py-1" x-text="(selectedNotification?.queue || '').toUpperCase()"></span>
                            <div class="small text-secondary font-monospace mt-1" x-text="selectedNotification?.event"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Sent At') }}</label>
                            <div class="text-secondary font-monospace" x-text="selectedNotification?.sent_at"></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Summary') }}</label>
                        <div class="p-3 bg-body-tertiary rounded border border-secondary border-opacity-25 font-monospace text-break" x-text="selectedNotification?.summary"></div>
                    </div>

                    <div>
                        <label class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Payload (JSON)') }}</label>
                        <div class="bg-dark p-3 rounded border border-secondary border-opacity-25" style="max-height: 400px; overflow-y: auto;">
                            <pre class="mb-0 text-info small" style="white-space: pre-wrap; word-wrap: break-word;"><code x-text="formatJson(selectedNotification?.payload)"></code></pre>
                        </div>
                    </div>
                </div>
                <div class="card-footer border-top border-secondary border-opacity-25 p-3 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-4" @click="showModal = false">{{ __('Close') }}</button>
                    <button type="button"
                            class="btn btn-sm rounded-pill px-4 fw-bold"
                            :class="payloadCopied ? 'btn-success' : 'btn-info text-white'"
                            @click="copyNotification()">
                        <span x-show="!payloadCopied"><i class="fa-solid fa-copy me-2"></i>{{ __('Copy Payload') }}</span>
                        <span x-show="payloadCopied" x-cloak><i class="fa-solid fa-check me-2"></i>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

