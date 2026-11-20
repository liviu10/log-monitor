@php
    $cardTitle = isset($title) ? __($title) : __('Metric');
    $hasValue = isset($value) && $value !== null && $value !== '';
    $cardVal = $hasValue && is_numeric($value) ? number_format((float) $value) : ($value ?? 0);
    $cardColor = $color ?? 'primary';
    $cardIcon = $icon ?? 'fa-solid fa-chart-line';
    $cardBreakdown = (isset($breakdown) && is_array($breakdown) && !empty($breakdown)) ? $breakdown : [];
    $cardUrl = $url ?? null;
@endphp

<div class="card card-custom h-100 border-0 shadow-sm rounded-4 overflow-hidden position-relative transition-hover d-flex flex-column justify-content-between" style="background-color: var(--card-bg); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);">
    <div class="card-body p-4 d-flex align-items-center justify-content-between">
        <div>
            <p class="text-secondary small fw-medium mb-1 text-uppercase" style="letter-spacing: 0.05em;">{{ $cardTitle }}</p>
            @if($hasValue)
                <h2 class="fw-bold mb-0 text-body-emphasis">{{ $cardVal }}</h2>
            @else
                <span class="text-secondary small fst-italic">{{ __('No data available at this time') }}</span>
            @endif
            @if(!empty($cardBreakdown))
                <div class="d-flex align-items-center gap-1 mt-2 flex-wrap">
                    @foreach($cardBreakdown as $bLabel => $bVal)
                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-10 px-2 py-1 rounded-pill small fw-medium">
                            {{ is_string($bLabel) ? strtoupper(__($bLabel)) : $bLabel }}: <strong class="text-body-emphasis">{{ is_numeric($bVal) ? number_format((float) $bVal) : $bVal }}</strong>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="rounded-circle d-flex align-items-center justify-content-center bg-{{ $cardColor }} bg-opacity-10 text-{{ $cardColor }} shadow-sm" style="width: 56px; height: 56px; flex-shrink: 0;">
            <i class="{{ $cardIcon }} fs-4"></i>
        </div>
    </div>
    @if(!empty($cardUrl))
        <div class="card-footer bg-transparent border-top border-secondary border-opacity-10 py-2 px-4 d-flex justify-content-end align-items-center">
            <a href="{{ $cardUrl }}" class="small fw-semibold text-decoration-none stat-card-link d-inline-flex align-items-center gap-2 hover-arrow">
                <span>{{ __('View Details') }}</span>
                <i class="fa-solid fa-arrow-right-long transition-icon"></i>
            </a>
        </div>
    @endif
</div>
@php
    unset($title, $value, $color, $icon, $breakdown, $url, $cardTitle, $cardVal, $cardColor, $cardIcon, $cardBreakdown, $cardUrl);
@endphp
