@php
    $emptyIcon = $icon ?? 'fa-solid fa-inbox';
    $emptyTitle = isset($title) ? __($title) : __('No records found');
    $emptyMessage = isset($message) ? __($message) : (isset($description) ? __($description) : __('Try adjusting your search query or filter options.'));
    $actUrl = $actionUrl ?? null;
    $actText = isset($actionText) ? __($actionText) : null;
    $actIcon = $actionIcon ?? 'fa-solid fa-rotate-left';
    $actClick = $onAction ?? null;
@endphp

<div class="empty-state-box">
    <div class="empty-state-icon mx-auto shadow-sm">
        <i class="{{ $emptyIcon }} fs-3"></i>
    </div>
    <h5 class="fw-bold mb-2 text-body-emphasis">{{ $emptyTitle }}</h5>
    @if(!empty($emptyMessage))
        <p class="text-secondary small mb-3 max-width-md mx-auto" style="max-width: 420px;">{{ $emptyMessage }}</p>
    @endif
    @if(!empty($actText))
        @if(!empty($actUrl))
            <a href="{{ $actUrl }}" class="btn btn-sm btn-primary-custom d-inline-flex align-items-center gap-2 rounded-pill px-4 py-2">
                <i class="{{ $actIcon }}"></i>
                <span>{{ $actText }}</span>
            </a>
        @elseif(!empty($actClick))
            <button type="button" @click="{{ $actClick }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 rounded-pill px-4 py-2">
                <i class="{{ $actIcon }}"></i>
                <span>{{ $actText }}</span>
            </button>
        @endif
    @endif
</div>
