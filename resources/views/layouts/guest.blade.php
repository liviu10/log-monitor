<!DOCTYPE html>
<html lang="{{ getLang() }}">
<head>
    @include('components.head')
</head>
<body class="bg-body-tertiary min-vh-100 d-flex flex-column" x-data="{ currentTheme: localStorage.getItem('app_theme') || 'dark' }">
    <div class="auth-background"></div>

    <header class="py-3 px-4 d-flex justify-content-between align-items-center position-relative" style="z-index: 10;">
        <a href="/login" class="d-flex align-items-center gap-2 text-decoration-none">
            @include('components.brand-logo', ['iconSize' => 34, 'fontSize' => '1.2rem'])
        </a>
        <div class="d-flex align-items-center gap-2">
            @include('components.theme-switcher')
            @include('components.lang-switcher')
        </div>
    </header>

    <main class="flex-grow-1 d-flex align-items-center justify-content-center py-4 px-3 position-relative" style="z-index: 5;">
        <div class="w-100" style="max-width: 440px;">
            @yield('content')
        </div>
    </main>

    <footer class="py-3 text-center text-secondary small position-relative" style="z-index: 5;">
        <span>&copy; {{ date('Y') }} LogMonitor &middot; {{ __('Private Telemetry Console') }}</span>
    </footer>

    @include('components.scripts')
</body>
</html>

