@extends('layouts.admin')

@section('title', __('Dashboard'))

@section('navbar-actions')
    <div class="d-flex align-items-center gap-2">
        <div class="d-none d-xl-flex align-items-center gap-2 px-3 py-2 admin-topbar-btn rounded-pill border border-secondary border-opacity-25 bg-secondary bg-opacity-10 small">
            <div class="pulse-dot bg-success"></div>
            <span class="text-secondary small fw-medium">{{ __('Last synchronized at') }}:</span>
            <span class="fw-semibold text-body-emphasis small">
                {{ isset($cachedAt) && $cachedAt > 0 ? date('H:i:s / d.m.Y', $cachedAt) : __('Never') }}
            </span>
        </div>
        <a href="/admin?refresh=1"
           class="btn btn-sm btn-outline-custom admin-topbar-btn d-inline-flex align-items-center gap-2 rounded-3 px-3 py-2 shadow-sm"
           title="{{ __('Refresh Stats') }}">
            <i class="fa-solid fa-rotate text-info"></i>
            <span class="d-none d-lg-inline fw-medium small">{{ __('Refresh Stats') }}</span>
        </a>
    </div>
@endsection

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'));

    $severityLabels = $chartData['severityPie']['labels'] ?? [];
    $severityCounts = $chartData['severityPie']['data'] ?? [];
    $errorLogsCount = 0;
    $warningLogsCount = 0;
    $infoLogsCount = 0;

    foreach ($severityLabels as $idx => $lvl) {
        $count = (int) ($severityCounts[$idx] ?? 0);
        $lvlUpper = strtoupper((string) $lvl);
        if (in_array($lvlUpper, ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'], true)) {
            $errorLogsCount += $count;
        } elseif ($lvlUpper === 'WARNING') {
            $warningLogsCount += $count;
        } else {
            $infoLogsCount += $count;
        }
    }
    $severityTotal = max(1, $errorLogsCount + $warningLogsCount + $infoLogsCount);
    $errorPct = (int) round(($errorLogsCount / $severityTotal) * 100);
    $warningPct = (int) round(($warningLogsCount / $severityTotal) * 100);
    $infoPct = (int) round(($infoLogsCount / $severityTotal) * 100);
@endphp

@include('components.page-header', [
    'title' => __('Telemetry Dashboard'),
    'subtitle' => __('Real-time log volume, severity breakdown, and system queue status')
])

<!-- Summary Cards Grid -->
@if(isset($summaries) && !empty($summaries))
    <div class="row g-3 mb-4">
        @foreach($summaries as $card)
            <div class="col-12 col-md-6 col-xl-3">
                @include('components.stat-card', array_merge(['breakdown' => []], $card))
            </div>
        @endforeach
    </div>
@endif

<!-- Interactive Chart.js Analytics Section -->
<div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden mb-4"
     x-data="dashboardAnalytics({{ json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})"
     x-init="initCharts()">
    <div class="card-header bg-transparent border-bottom border-secondary border-opacity-10 p-3 p-lg-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <i class="fa-solid fa-chart-line text-info fs-5"></i>
                <h3 class="h6 mb-0 fw-bold text-uppercase">{{ __('Charts & Analytics') }}</h3>
                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 font-monospace small px-2.5 py-1" x-text="rangeLabel"></span>
                <div x-show="loading" x-cloak class="spinner-border spinner-border-sm text-info" role="status">
                    <span class="visually-hidden">{{ __('Loading analytics...') }}</span>
                </div>
            </div>

            <div class="chart-period-filters-container d-inline-flex flex-wrap align-items-center">
                <button type="button" class="chart-period-btn d-inline-flex align-items-center gap-1"
                        :class="period === 'day' ? 'active' : ''"
                        @click="selectPeriod('day')" :disabled="loading">
                    <i class="fa-regular fa-clock"></i><span>{{ __('Day (24h)') }}</span>
                </button>
                <button type="button" class="chart-period-btn d-inline-flex align-items-center gap-1"
                        :class="period === 'month' ? 'active' : ''"
                        @click="selectPeriod('month')" :disabled="loading">
                    <i class="fa-regular fa-calendar-days"></i><span>{{ __('Month (30d)') }}</span>
                </button>
                <button type="button" class="chart-period-btn d-inline-flex align-items-center gap-1"
                        :class="period === 'year' ? 'active' : ''"
                        @click="selectPeriod('year')" :disabled="loading">
                    <i class="fa-solid fa-calendar"></i><span>{{ __('Year (12m)') }}</span>
                </button>
                <button type="button" class="chart-period-btn d-inline-flex align-items-center gap-1"
                        :class="period === 'custom' ? 'active' : ''"
                        @click="selectPeriod('custom')" :disabled="loading">
                    <i class="fa-solid fa-sliders"></i><span>{{ __('Custom') }}</span>
                </button>
            </div>
        </div>

        <div x-show="period === 'custom'" x-cloak class="mt-3 pt-3 border-top border-secondary border-opacity-10">
            <form @submit.prevent="applyCustomRange()" class="row g-2 align-items-center justify-content-end">
                <div class="col-12 col-sm-auto d-flex align-items-center gap-2">
                    <label class="text-secondary small fw-medium text-nowrap">{{ __('From') }}:</label>
                    <input type="date" x-model="customStart" class="form-control form-control-sm" required>
                </div>
                <div class="col-12 col-sm-auto d-flex align-items-center gap-2">
                    <label class="text-secondary small fw-medium text-nowrap">{{ __('To') }}:</label>
                    <input type="date" x-model="customEnd" class="form-control form-control-sm" required>
                </div>
                <div class="col-12 col-sm-auto">
                    <button type="submit" class="btn btn-sm btn-info text-white px-3 fw-bold" :disabled="loading">
                        <i class="fa-solid fa-filter me-1"></i>{{ __('Apply') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card-body p-3 p-lg-4">
        <div class="row g-4">
            <div class="col-12 col-xl-8">
                <div class="p-3 rounded-3 bg-body-tertiary border border-secondary border-opacity-10 h-100 d-flex flex-column">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-flex align-items-center gap-2">
                            <i class="fa-solid fa-wave-square text-info"></i>{{ __('Log Volume Over Time') }}
                        </span>
                        <div class="d-flex align-items-center gap-3 small font-monospace">
                            <span class="text-info"><i class="fa-solid fa-circle me-1" style="font-size: 8px;"></i>{{ __('Total') }}: <strong x-text="summary.total"></strong></span>
                            <span class="text-danger"><i class="fa-solid fa-circle me-1" style="font-size: 8px;"></i>{{ __('Errors') }}: <strong x-text="summary.errors"></strong></span>
                        </div>
                    </div>
                    <div class="flex-grow-1 position-relative overflow-hidden" style="height: 260px;">
                        <canvas id="chartLogVolume"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="p-3 rounded-3 bg-body-tertiary border border-secondary border-opacity-10 h-100 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-flex align-items-center gap-2">
                            <i class="fa-solid fa-chart-pie text-warning"></i>{{ __('Severity Breakdown') }}
                        </span>
                    </div>
                    <div class="flex-grow-1 position-relative overflow-hidden" style="height: 260px;">
                        <div class="position-relative w-100 h-100">
                            <canvas id="chartSeverityBreakdown"></canvas>
                        </div>
                        <div x-show="severityEmpty" x-cloak class="position-absolute top-0 start-0 w-100 h-100 pe-none">
                            <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center text-secondary small px-3">
                                <i class="fa-solid fa-circle-info mb-1 opacity-50 fs-5 d-block"></i>
                                <span>{{ __('No log data available for this timeframe.') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="p-3 rounded-3 bg-body-tertiary border border-secondary border-opacity-10 overflow-hidden">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="text-secondary small fw-bold text-uppercase d-flex align-items-center gap-2">
                            <i class="fa-solid fa-chart-bar text-primary"></i>{{ __('Logs by Application') }}
                        </span>
                    </div>
                    <div class="position-relative overflow-hidden" style="height: 220px;" :style="{ height: appsChartHeight + 'px' }">
                        <div class="position-relative w-100 h-100" :class="appsEmpty ? 'invisible pe-none' : ''">
                            <canvas id="chartAppsDistribution"></canvas>
                        </div>
                        <div x-show="appsEmpty" x-cloak class="position-absolute top-0 start-0 w-100 h-100 pe-none">
                            <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center text-secondary small p-4">
                                <i class="fa-solid fa-inbox mb-2 opacity-50 fs-2 d-block"></i>
                                <span>{{ __('No log data available for this timeframe.') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Latest Logs & Top Apps -->
    <div class="col-lg-8 d-flex flex-column gap-4">
        <div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center px-4 pt-4">
                <h3 class="h6 mb-0 fw-bold text-uppercase">{{ __('Latest Log Activity') }}</h3>
                <a href="/admin/logs" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 fw-bold">
                    {{ __('View History') }} <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ __('Application') }}</th>
                                <th>{{ __('Level') }}</th>
                                <th>{{ __('Message') }}</th>
                                <th class="text-end pe-4">{{ __('Logged At') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($latestLogs as $log)
                                @php
                                    $lvlUpper = strtoupper((string) ($log['level'] ?? 'INFO'));
                                    $badgeClass = match (true) {
                                        in_array($lvlUpper, ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'], true) => 'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25',
                                        $lvlUpper === 'WARNING' => 'bg-warning bg-opacity-10 text-warning border-warning border-opacity-25',
                                        $lvlUpper === 'INFO' => 'bg-info bg-opacity-10 text-info border-info border-opacity-25',
                                        $lvlUpper === 'NOTICE' => 'bg-success bg-opacity-10 text-success border-success border-opacity-25',
                                        default => 'bg-secondary bg-opacity-10 text-secondary border-secondary border-opacity-25',
                                    };
                                @endphp
                                <tr>
                                    <td class="ps-4 font-monospace fw-medium">{{ $log['app_name'] ?? 'Unknown' }}</td>
                                    <td>
                                        <span class="badge border rounded-pill px-2.5 py-0.5 small {{ $badgeClass }}">{{ $lvlUpper }}</span>
                                    </td>
                                    <td class="text-truncate font-monospace" style="max-width: 260px;" title="{{ $log['message'] ?? '' }}">
                                        {{ $log['message'] ?? '' }}
                                    </td>
                                    <td class="text-end pe-4 text-secondary font-monospace small">
                                        {{ date('H:i:s d.m.Y', strtotime((string) ($log['created_at'] ?? 'now'))) }}
                                    </td>
                                </tr>
                            @endforeach
                            @if(empty($latestLogs))
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-secondary">
                                        <i class="fa-solid fa-inbox d-block fs-2 mb-2 opacity-25"></i>
                                        {{ __('No logs recorded yet.') }}
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-transparent border-0 px-4 pt-4">
                <h3 class="h6 mb-0 fw-bold text-uppercase">{{ __('Top Apps by Log Volume') }}</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ __('Application') }}</th>
                                <th>{{ __('Logs Count') }}</th>
                                <th class="text-end pe-4">{{ __('Last Active') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topApps as $app)
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold">{{ $app['app_name'] ?? 'Unknown' }}</td>
                                    <td class="text-info font-monospace fw-bold">{{ number_format((float) ($app['log_count'] ?? 0)) }}</td>
                                    <td class="text-end pe-4 text-secondary font-monospace small">
                                        {{ !empty($app['last_logged_at']) ? date('H:i:s d.m.Y', strtotime((string) $app['last_logged_at'])) : __('Never') }}
                                    </td>
                                </tr>
                            @endforeach
                            @if(empty($topApps))
                                <tr>
                                    <td colspan="3" class="text-center py-5 text-secondary">
                                        <i class="fa-solid fa-inbox d-block fs-2 mb-2 opacity-25"></i>
                                        {{ __('No applications registered.') }}
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Severity Distribution, Queue Preview, Quick Actions -->
    <div class="col-lg-4 d-flex flex-column gap-4">
        <div class="card card-custom border-0 shadow-sm rounded-4">
            <div class="card-header bg-transparent border-0 px-4 pt-4">
                <h3 class="h6 mb-0 fw-bold text-uppercase">{{ __('Severity Distribution (24h)') }}</h3>
            </div>
            <div class="card-body px-4 pb-4 d-flex flex-column gap-3">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1 small text-secondary">
                        <span class="fw-semibold text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ __('Error / Critical') }}</span>
                        <span class="font-monospace fw-bold">{{ $errorLogsCount }} ({{ $errorPct }}%)</span>
                    </div>
                    <div class="progress bg-secondary bg-opacity-25" style="height: 6px;">
                        <div class="progress-bar bg-danger" role="progressbar" style="width: {{ $errorPct }}%" aria-valuenow="{{ $errorPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1 small text-secondary">
                        <span class="fw-semibold text-warning"><i class="fa-solid fa-bell me-1"></i>{{ __('Warning') }}</span>
                        <span class="font-monospace fw-bold">{{ $warningLogsCount }} ({{ $warningPct }}%)</span>
                    </div>
                    <div class="progress bg-secondary bg-opacity-25" style="height: 6px;">
                        <div class="progress-bar bg-warning" role="progressbar" style="width: {{ $warningPct }}%" aria-valuenow="{{ $warningPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1 small text-secondary">
                        <span class="fw-semibold text-info"><i class="fa-solid fa-circle-info me-1"></i>{{ __('Info / Debug') }}</span>
                        <span class="font-monospace fw-bold">{{ $infoLogsCount }} ({{ $infoPct }}%)</span>
                    </div>
                    <div class="progress bg-secondary bg-opacity-25" style="height: 6px;">
                        <div class="progress-bar bg-info" role="progressbar" style="width: {{ $infoPct }}%" aria-valuenow="{{ $infoPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom border-0 shadow-sm rounded-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center px-4 pt-4">
                <h3 class="h6 mb-0 fw-bold text-uppercase">{{ __('Pending Queue Preview') }}</h3>
                <a href="/admin/log-queue" class="btn btn-sm btn-link text-info p-0 text-decoration-none">
                    {{ __('View') }} <i class="fa-solid fa-chevron-right ms-1"></i>
                </a>
            </div>
            <div class="card-body px-4 pb-4 pt-2 d-flex flex-column gap-2">
                @foreach($pendingQueue as $job)
                    <div class="d-flex justify-content-between align-items-center p-2 rounded bg-body-tertiary border border-secondary border-opacity-10">
                        <span class="badge bg-secondary bg-opacity-25 text-body-emphasis font-monospace text-truncate" style="max-width: 160px;">
                            {{ $job['app_name'] ?? 'Unknown' }}
                        </span>
                        <span class="text-secondary font-monospace small">
                            {{ date('H:i:s', strtotime((string) ($job['created_at'] ?? 'now'))) }}
                        </span>
                    </div>
                @endforeach
                @if(empty($pendingQueue))
                    <div class="text-center py-4 text-secondary small">
                        {{ __('No pending jobs in the queue.') }}
                    </div>
                @endif
            </div>
        </div>

        <div class="card card-custom border-0 shadow-sm rounded-4">
            <div class="card-header bg-transparent border-0 px-4 pt-4">
                <h3 class="h6 mb-0 fw-bold text-uppercase">{{ __('Quick Actions') }}</h3>
            </div>
            <div class="card-body px-4 pb-4 pt-2 d-flex flex-column gap-2">
                @if(hasPermission('admin.apps.index'))
                <a href="/admin/apps" class="btn btn-primary-custom w-100 py-2.5 px-3 rounded-3 d-flex align-items-center justify-content-between fw-bold">
                    <span class="d-flex align-items-center gap-2"><i class="fa-solid fa-plus-circle"></i><span>{{ __('Register New App') }}</span></span>
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                @endif
                @if(hasPermission('admin.logqueue.index'))
                <a href="/admin/log-queue" class="btn btn-outline-custom w-100 py-2.5 px-3 rounded-3 d-flex align-items-center justify-content-between fw-semibold">
                    <span class="d-flex align-items-center gap-2"><i class="fa-solid fa-list-check text-warning"></i><span>{{ __('View Active Queue') }}</span></span>
                    <i class="fa-solid fa-chevron-right text-secondary"></i>
                </a>
                @endif
                @if(hasPermission('admin.logs.index'))
                <a href="/admin/logs" class="btn btn-outline-custom w-100 py-2.5 px-3 rounded-3 d-flex align-items-center justify-content-between fw-semibold">
                    <span class="d-flex align-items-center gap-2"><i class="fa-solid fa-clock-rotate-left text-info"></i><span>{{ __('View Logs History') }}</span></span>
                    <i class="fa-solid fa-chevron-right text-secondary"></i>
                </a>
                @endif
            </div>
        </div>
    </div>
</div>

<script src="/assets/vendor/chartjs/chart.umd.min.js" defer nonce="{{ APP_NONCE }}"></script>
@endsection

