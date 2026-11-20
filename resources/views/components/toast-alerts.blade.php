@php $flash = $appFlash ?? getFlash(); @endphp
@if($flash)
    <script nonce="{{ APP_NONCE }}">
        document.addEventListener('DOMContentLoaded', function() {
            App.handleToast({
                type: "{{ $flash['type'] === 'error' ? 'danger' : ($flash['type'] === 'warning' ? 'warning' : 'success') }}",
                title: "{{ htmlspecialchars($flash['title'] ?? '', ENT_QUOTES, 'UTF-8') }}",
                message: "{{ htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') }}",
                toastDelay: 5000
            });
        });
    </script>
@endif
