@if(in_array(strtolower((string) ($_ENV['APP_ENV'] ?? 'production')), ['development', 'local', 'testing'], true))
<div class="mt-4 pt-3 border-top border-secondary border-opacity-10">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-secondary text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.08em;">
            <i class="fa-solid fa-bolt text-warning me-1"></i>{{ __('Quick Dev Login') }}
        </span>
        <span class="text-secondary" style="font-size: 0.7rem;">{{ __('Click to fill') }}</span>
    </div>
    <div class="d-flex justify-content-center gap-2 flex-wrap">
        <button type="button"
                @click="fillCredentials('admin@logmonitor.local', 'admin123')"
                class="btn btn-sm btn-outline-info rounded-pill px-3 py-1"
                style="font-size: 0.78rem;">
            <i class="fa-solid fa-user-shield me-1"></i>{{ __('Admin') }}
        </button>
        <button type="button"
                @click="fillCredentials('user@logmonitor.local', 'user123')"
                class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1"
                style="font-size: 0.78rem;">
            <i class="fa-solid fa-user me-1"></i>{{ __('User') }}
        </button>
    </div>
</div>
@endif
