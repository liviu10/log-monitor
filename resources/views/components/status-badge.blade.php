@php
    $rawStatus = $status ?? false;
    $statusClass = 'secondary';
    $defaultLabel = '';

    if (is_bool($rawStatus) || $rawStatus === 1 || $rawStatus === 0 || $rawStatus === '1' || $rawStatus === '0') {
        $isActive = (bool) $rawStatus;
        $statusClass = $isActive ? 'active' : 'inactive';
        $defaultLabel = $isActive ? __('Active') : __('Inactive');
    } else {
        $normalized = strtolower((string) $rawStatus);
        switch ($normalized) {
            case 'active':
            case 'published':
            case 'completed':
            case 'success':
            case 'approved':
                $statusClass = 'active';
                $defaultLabel = __(ucfirst($normalized));
                break;
            case 'inactive':
            case 'draft':
            case 'danger':
            case 'rejected':
            case 'failed':
                $statusClass = 'inactive';
                $defaultLabel = __(ucfirst($normalized));
                break;
            case 'pending':
            case 'warning':
                $statusClass = 'pending';
                $defaultLabel = __(ucfirst($normalized));
                break;
            case 'sent':
            case 'info':
            case 'archived':
                $statusClass = 'info';
                $defaultLabel = __(ucfirst($normalized));
                break;
            default:
                $statusClass = 'primary';
                $defaultLabel = __(ucfirst($normalized));
                break;
        }
    }

    $labelText = isset($label) && $label !== '' ? __($label) : $defaultLabel;
    $pulse = $pulse ?? ($statusClass === 'active');
@endphp

<span class="badge-status-pill status-{{ $statusClass }}">
    <span class="status-dot @if($pulse) pulse @endif"></span>
    <span>{{ $labelText }}</span>
</span>
