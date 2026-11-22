<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js" defer nonce="{{ APP_NONCE }}"></script>
<script src="/assets/vendor/alpine/alpine.min.js" defer nonce="{{ APP_NONCE }}"></script>
<script src="/assets/app.js?v={{ @filemtime(dirname(storage_path()) . '/public/assets/app.js') ?: '1' }}" nonce="{{ APP_NONCE }}"></script>
@if(getCurrentAuthUser())
    <form id="global-logout-form" action="/logout" method="POST" class="d-none">
        {!! csrf_field() !!}
        <input type="hidden" name="inactivity" value="">
    </form>
    <script nonce="{{ APP_NONCE }}">
        window.appLogout = function (inactivity) {
            var form = document.getElementById('global-logout-form');
            if (!form) { return; }
            form.elements.inactivity.value = inactivity ? '1' : '';
            form.submit();
        };
    </script>
    <script src="/assets/js/inactivity-logout.js?v={{ @filemtime(dirname(storage_path()) . '/public/assets/js/inactivity-logout.js') ?: '1' }}" data-timeout="{{ (int) (config('app.session_idle_timeout') ?? 1800) }}" defer nonce="{{ APP_NONCE }}"></script>
@endif
@include('components.toast-alerts')
