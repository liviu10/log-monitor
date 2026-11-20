@extends('layouts.admin')

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Log Queue'));

    $currentPage = (int) ($page ?? 1);
    $totalPagesVal = (int) ($totalPages ?? 1);
    $range = 2;
    $startPage = max(1, $currentPage - $range);
    $endPage = min($totalPagesVal, $currentPage + $range);
    $limitOptions = [10, 25, 50, 100];
    $paginationParams = [
        'sort_by' => $sortBy ?? 'id',
        'sort_dir' => $sortDir ?? 'DESC',
    ];
@endphp

@include('components.page-header', [
    'title' => __('Raw Log Ingestion Queue'),
    'subtitle' => __('High-throughput buffered log payloads awaiting batch processing by log-worker')
])

<div x-data="App.logQueuePageData()">
    <!-- Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            @include('components.stat-card', [
                'title' => __('Pending Jobs'),
                'value' => $totalJobs ?? 0,
                'color' => 'info',
                'icon' => 'fa-solid fa-list-check',
            ])
        </div>
        <div class="col-md-6">
            @include('components.stat-card', [
                'title' => __('Log Worker Status'),
                'value' => $isWorkerRunning ? __('ACTIVE') : __('INACTIVE'),
                'color' => $isWorkerRunning ? 'success' : 'danger',
                'icon' => $isWorkerRunning ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark',
            ])
        </div>
    </div>

    <!-- Global Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="h5 mb-0 fw-bold">{{ __('Active Log Buffer') }}</h3>
        @if(($totalJobs ?? 0) > 0 && hasPermission('admin.logqueue.purge'))
            <form action="/admin/log-queue/purge"
                  method="POST"
                  @submit.prevent="if (confirm(@json(__('Are you sure you want to completely purge the log queue?')))) $el.submit()"
                  class="m-0">
                {!! csrf_field() !!}
                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold">
                    <i class="fa-regular fa-trash-can me-2"></i>{{ __('Purge Queue') }}
                </button>
            </form>
        @endif
    </div>

    <!-- Table of Jobs -->
    <div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4" style="width: 130px;">
                                @php
                                    $nextDirId = ($sortBy === 'id' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlId = '/admin/log-queue?' . http_build_query(['page' => 1, 'limit' => $limit, 'sort_by' => 'id', 'sort_dir' => $nextDirId]);
                                @endphp
                                <a href="{{ $urlId }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Job ID') }}
                                    @if($sortBy === 'id')
                                        <i class="fa-solid fa-sort-{{ $sortDir === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th style="width: 180px;">
                                @php
                                    $nextDirApp = ($sortBy === 'app_name' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlApp = '/admin/log-queue?' . http_build_query(['page' => 1, 'limit' => $limit, 'sort_by' => 'app_name', 'sort_dir' => $nextDirApp]);
                                @endphp
                                <a href="{{ $urlApp }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('App Name') }}
                                    @if($sortBy === 'app_name')
                                        <i class="fa-solid fa-sort-{{ $sortDir === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th>{{ __('Payload Preview') }}</th>
                            <th style="width: 180px;">
                                @php
                                    $nextDirTime = ($sortBy === 'created_at' && strtoupper($sortDir) === 'DESC') ? 'ASC' : 'DESC';
                                    $urlTime = '/admin/log-queue?' . http_build_query(['page' => 1, 'limit' => $limit, 'sort_by' => 'created_at', 'sort_dir' => $nextDirTime]);
                                @endphp
                                <a href="{{ $urlTime }}" class="text-decoration-none text-secondary d-flex align-items-center gap-1">
                                    {{ __('Created At') }}
                                    @if($sortBy === 'created_at')
                                        <i class="fa-solid fa-sort-{{ $sortDir === 'ASC' ? 'up' : 'down' }} text-info ms-1"></i>
                                    @else
                                        <i class="fa-solid fa-sort text-secondary opacity-50 ms-1"></i>
                                    @endif
                                </a>
                            </th>
                            <th class="text-end pe-4" style="width: 140px;">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($jobs as $job)
                            @php
                                $payload = json_decode((string) $job['payload_raw'], true) ?: [];
                                $previewMsg = $payload['message'] ?? ($payload[0]['message'] ?? 'N/A');
                                if (is_array($previewMsg)) {
                                    $previewMsg = json_encode($previewMsg);
                                }
                                $previewMsg = mb_strimwidth((string) $previewMsg, 0, 80, '...');
                                $payloadSize = number_format(strlen((string) $job['payload_raw']) / 1024, 2) . ' KB';
                            @endphp
                            <tr>
                                <td class="ps-4 text-secondary font-monospace">#{{ $job['id'] }}</td>
                                <td class="fw-medium">{{ $job['app_name'] ?? __('Unknown App') }}</td>
                                <td class="small">
                                    <span>{{ $previewMsg }}</span>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary ms-2">{{ $payloadSize }}</span>
                                </td>
                                <td class="text-secondary small font-monospace">{{ $job['created_at'] }}</td>
                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-3 align-items-center">
                                        <button type="button" class="btn btn-link text-info p-0" title="{{ __('View Details') }}" @click="viewJob(@json($job))">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                        @if(hasPermission('admin.logqueue.delete'))
                                        <form action="/admin/log-queue/{{ (int) $job['id'] }}/delete"
                                              method="POST"
                                              @submit.prevent="if (confirm(@json(__('Are you sure you want to delete this job from the queue?')))) $el.submit()"
                                              class="m-0">
                                            {!! csrf_field() !!}
                                            <button type="submit" class="btn btn-link text-danger p-0" title="{{ __('Delete') }}">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if(empty($jobs))
                            <tr>
                                <td colspan="5" class="text-center py-5 text-secondary">
                                    <i class="fa-solid fa-inbox fs-2 mb-2 opacity-25 d-block"></i>
                                    <p class="mb-0 small">{{ __('No jobs currently in the queue.') }}</p>
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
                        $url = '/admin/log-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $l]));
                    @endphp
                    <option value="{{ $url }}" @if($limit === $l) selected @endif>{{ $l }}</option>
                @endforeach
            </select>
        </div>

        @if($totalPagesVal > 1)
            <nav aria-label="{{ __('Pagination') }}">
                <ul class="pagination mb-0 gap-1 align-items-center">
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/log-queue?' . http_build_query(array_merge($paginationParams, ['page' => 1, 'limit' => $limit])) }}" title="{{ __('First') }}">
                            <i class="fa-solid fa-angles-left small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage <= 1) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage <= 1 ? '#' : '/admin/log-queue?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage - 1, 'limit' => $limit])) }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left small"></i>
                        </a>
                    </li>
                    @for($i = $startPage; $i <= $endPage; $i++)
                        <li class="page-item @if($currentPage === $i) active @endif">
                            <a class="page-link rounded" href="/admin/log-queue?{{ http_build_query(array_merge($paginationParams, ['page' => $i, 'limit' => $limit])) }}">{{ $i }}</a>
                        </li>
                    @endfor
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/log-queue?' . http_build_query(array_merge($paginationParams, ['page' => $currentPage + 1, 'limit' => $limit])) }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                    </li>
                    <li class="page-item @if($currentPage >= $totalPagesVal) disabled @endif">
                        <a class="page-link rounded" href="{{ $currentPage >= $totalPagesVal ? '#' : '/admin/log-queue?' . http_build_query(array_merge($paginationParams, ['page' => $totalPagesVal, 'limit' => $limit])) }}" title="{{ __('Last') }}">
                            <i class="fa-solid fa-angles-right small"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        @endif
    </div>

    <!-- Job Details Modal -->
    <template x-if="showModal">
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
             style="z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);"
             @click.self="showModal = false">
            <div class="card card-custom shadow-lg border border-secondary border-opacity-25 rounded-4 overflow-hidden" style="width: 100%; max-width: 800px;" @click.stop>
                <div class="card-header border-bottom border-secondary border-opacity-25 p-4 d-flex align-items-center justify-content-between">
                    <h4 class="h5 mb-0 fw-bold">
                        <i class="fa-solid fa-list-check text-info me-2"></i>{{ __('Job details:') }} <span class="text-info">#<span x-text="selectedJob?.id"></span></span>
                    </h4>
                    <button type="button" class="btn-close" @click="showModal = false" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="card-body p-4 overflow-auto" style="max-height: 70vh;">
                    <div class="mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Application') }}</span>
                        <span class="fw-medium" x-text="selectedJob?.app_name || @json(__('Unknown App'))"></span>
                    </div>
                    <div class="mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Created At') }}</span>
                        <span class="font-monospace text-secondary" x-text="selectedJob?.created_at"></span>
                    </div>
                    <div>
                        <span class="text-secondary small fw-bold text-uppercase d-block mb-1">{{ __('Payload (JSON Raw)') }}</span>
                        <pre class="bg-dark text-info p-3 rounded font-monospace small overflow-auto" style="max-height: 400px; white-space: pre-wrap; word-break: break-all;" x-text="formatJson(selectedJob?.payload_raw || '')"></pre>
                    </div>
                </div>
                <div class="card-footer border-top border-secondary border-opacity-25 p-3 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-4" @click="showModal = false">{{ __('Close') }}</button>
                    <button type="button"
                            class="btn btn-sm rounded-pill px-4 fw-bold"
                            :class="payloadCopied ? 'btn-success' : 'btn-info text-white'"
                            @click="copyJobPayload()">
                        <span x-show="!payloadCopied"><i class="fa-solid fa-copy me-2"></i>{{ __('Copy Log') }}</span>
                        <span x-show="payloadCopied" x-cloak><i class="fa-solid fa-check me-2"></i>{{ __('Log Copied') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

