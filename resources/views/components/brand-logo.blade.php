@php
    $iconSize = $iconSize ?? 36;
    $fontSize = $fontSize ?? '1.25rem';
@endphp
<span class="d-inline-flex align-items-center gap-2 text-decoration-none">
    <img src="/favicon.svg" alt="" width="{{ $iconSize }}" height="{{ $iconSize }}" class="flex-shrink-0" style="width: {{ $iconSize }}px; height: {{ $iconSize }}px;">
    <span class="fw-bold text-body-emphasis" style="font-size: {{ $fontSize }}; letter-spacing: -0.03em; line-height: 1;">Log<span style="color: #06b6d4;">Monitor</span></span>
</span>

