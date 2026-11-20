@extends('layouts.admin')

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Settings'), '/admin/settings')
        ->add(__('Queue Management'));
@endphp

<div x-data="queueManager()">
    <!-- Page Header & Action Controls -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="h3 fw-bold text-body-emphasis mb-0">{{ __('Queue Management') }}</h1>
                @if($isWorkerRunning)
                    <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2.5 py-1.5 rounded-pill d-inline-flex align-items-center gap-1.5">
                        <span class="status-dot pulse" style="background-color: var(--bs-success);"></span>
                        {{ __('Worker Active') }}
                    </span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2.5 py-1.5 rounded-pill d-inline-flex align-items-center gap-1.5">
                        <span class="status-dot" style="background-color: var(--bs-secondary);"></span>
                        {{ __('Worker Idle') }}
                    </span>
                @endif
            </div>
            <p class="text-secondary small mb-0 mt-1">{{ __('Monitor asynchronous job queues, dead-letter retries, and worker health') }}</p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <!-- Run Worker Now Button -->
            <form action="/admin/settings/queue/run-worker" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Running...') }}';}">
                {!! csrf_field() !!}
                <button type="submit" class="btn btn-outline-primary d-flex align-items-center gap-2 rounded-3 shadow-sm px-3 py-2">
                    <i class="fa-solid fa-play"></i>
                    <span>{{ __('Run Worker Now') }}</span>
                </button>
            </form>

            <!-- Retry All Failed Button -->
            <button type="button" @click="confirmRetryAll()" class="btn btn-outline-warning d-flex align-items-center gap-2 rounded-3 shadow-sm px-3 py-2" {{ ($stats['failed'] ?? 0) === 0 ? 'disabled' : '' }}>
                <i class="fa-solid fa-rotate-right"></i>
                <span>{{ __('Retry All Failed') }}</span>
            </button>

            <!-- Purge Old Jobs Button -->
            <button type="button" @click="confirmPurge()" class="btn btn-outline-danger d-flex align-items-center gap-2 rounded-3 shadow-sm px-3 py-2">
                <i class="fa-solid fa-broom"></i>
                <span>{{ __('Purge Processed') }}</span>
            </button>
        </div>
    </div>

    <!-- Stats Cards Grid -->
    <div class="row g-3 mb-4">
        <!-- Total Jobs -->
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100">
                <div class="text-secondary small fw-medium mb-1">{{ __('Total Jobs') }}</div>
                <div class="h4 fw-bold text-body-emphasis mb-0">{{ number_format($stats['total'] ?? 0) }}</div>
            </div>
        </div>

        <!-- Pending Jobs -->
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 border-start border-warning border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-clock text-warning"></i>
                    <span>{{ __('Pending') }}</span>
                </div>
                <div class="h4 fw-bold text-warning mb-0">{{ number_format($stats['pending'] ?? 0) }}</div>
            </div>
        </div>

        <!-- Processing Jobs -->
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 border-start border-info border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-spinner fa-spin text-info"></i>
                    <span>{{ __('Processing') }}</span>
                </div>
                <div class="h4 fw-bold text-info mb-0">{{ number_format($stats['processing'] ?? 0) }}</div>
            </div>
        </div>

        <!-- Completed Jobs -->
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 border-start border-success border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-circle-check text-success"></i>
                    <span>{{ __('Completed') }}</span>
                </div>
                <div class="h4 fw-bold text-success mb-0">{{ number_format($stats['completed'] ?? 0) }}</div>
            </div>
        </div>

        <!-- Failed Jobs (DLQ) -->
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 border-start border-danger border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-triangle-exclamation text-danger"></i>
                    <span>{{ __('Failed (DLQ)') }}</span>
                </div>
                <div class="h4 fw-bold text-danger mb-0">{{ number_format($stats['failed'] ?? 0) }}</div>
            </div>
        </div>

        <!-- Expired Jobs -->
        <div class="col-6 col-lg-2">
            <div class="card card-custom p-3 border-0 shadow-sm h-100 border-start border-secondary border-3">
                <div class="text-secondary small fw-medium mb-1 d-flex align-items-center gap-1">
                    <i class="fa-solid fa-calendar-xmark text-secondary"></i>
                    <span>{{ __('Expired') }}</span>
                </div>
                <div class="h4 fw-bold text-secondary mb-0">{{ number_format($stats['expired'] ?? 0) }}</div>
            </div>
        </div>
    </div>

    <!-- Data Table Container -->
    @include('components.data-table', [
        'resource' => 'queue',
        'items' => $items,
        'pagination' => $pagination,
        'distinctQueues' => $distinctQueues
    ])

    <!-- Payload Viewer Modal -->
    <div class="modal fade" id="queuePayloadModal" tabindex="-1" aria-hidden="true" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-code text-info me-2"></i>{{ __('Job Payload') }} #<span x-text="activeJob?.id"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                    <pre class="bg-dark text-light p-3 rounded-3 small mb-0 font-monospace" style="max-height: 400px; overflow-y: auto;"><code x-text="formattedPayload"></code></pre>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Close') }}</button>
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <div class="p-3 rounded-3 border border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger mb-3">
                        <h6 class="fw-bold mb-1">{{ __('Failure Reason') }}</h6>
                        <div class="small" x-text="activeJob?.error_message || '{{ __('No error message logged.') }}'"></div>
                    </div>
                    <div class="small text-secondary mb-2">{{ __('Available / Next Attempt') }}: <span class="text-body-emphasis fw-semibold" x-text="activeJob?.available_at || '-'"></span></div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    <button type="button" @click="retrySingle(activeJob?.id)" class="btn btn-primary rounded-pill px-4">
                        <i class="fa-solid fa-rotate-right me-1"></i>{{ __('Retry Job Now') }}
                    </button>
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                    <form :action="'/admin/settings/queue/' + activeJob?.id + '/retry'" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Retrying...') }}';}">
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                    <form action="/admin/settings/queue/retry-all" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Retrying...') }}';}">
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                    <form :action="'/admin/settings/queue/' + activeJob?.id + '/delete'" method="POST" class="m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Deleting...') }}';}">
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-broom fa-3x text-danger mb-3"></i>
                    <p class="fs-6 fw-medium text-body-emphasis mb-1">
                        {{ __('Purge completed, failed, and expired jobs older than retention period?') }}
                    </p>
                    <p class="text-secondary small mb-3">{{ __('Select retention threshold in days:') }}</p>
                    <form id="purge-form" action="/admin/settings/queue/purge" method="POST">
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
                    <button type="button" @click="const b=$event.currentTarget; b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>{{ __('Purging...') }}'; document.getElementById('purge-form').submit();" class="btn btn-danger rounded-pill px-4">{{ __('Purge Now') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ APP_NONCE }}">
    function queueManager() {
        return {
            activeJob: null,
            formattedPayload: '',

            init() {
                window.addEventListener('view-queue-payload', (e) => this.showPayload(e.detail));
                window.addEventListener('view-queue-error', (e) => this.showError(e.detail));
                window.addEventListener('retry-queue-job', (e) => this.confirmRetrySingle(e.detail));
                window.addEventListener('delete-queue-job', (e) => this.confirmDelete(e.detail));
            },

            showPayload(job) {
                this.activeJob = job;
                try {
                    const parsed = JSON.parse(job.payload);
                    this.formattedPayload = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    this.formattedPayload = job.payload || '';
                }
                const modal = new bootstrap.Modal(document.getElementById('queuePayloadModal'));
                modal.show();
            },

            showError(job) {
                this.activeJob = job;
                const modal = new bootstrap.Modal(document.getElementById('queueErrorModal'));
                modal.show();
            },

            confirmRetrySingle(job) {
                this.activeJob = job;
                const modal = new bootstrap.Modal(document.getElementById('queueRetryConfirmModal'));
                modal.show();
            },

            retrySingle(jobId) {
                if (!jobId) return;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/admin/settings/queue/' + jobId + '/retry';
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '{{ csrf_token() }}';
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            },

            confirmRetryAll() {
                const modal = new bootstrap.Modal(document.getElementById('queueRetryAllModal'));
                modal.show();
            },

            confirmDelete(job) {
                this.activeJob = job;
                const modal = new bootstrap.Modal(document.getElementById('queueDeleteModal'));
                modal.show();
            },

            confirmPurge() {
                const modal = new bootstrap.Modal(document.getElementById('queuePurgeModal'));
                modal.show();
            }
        };
    }
</script>
@endsection
