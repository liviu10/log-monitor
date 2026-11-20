<!DOCTYPE html>
<html lang="{{ getLang() }}">
<head>
    @include('components.head')
</head>
<body class="bg-body-tertiary" x-data="{ sidebarOpen: false, currentTheme: localStorage.getItem('app_theme') || 'dark' }">
@php
    $currentUser = getCurrentAuthUser();
    $currentPath = parse_url((string) (config('server.request_uri') ?? '/admin'), PHP_URL_PATH) ?: '/admin';
    $userFullName = trim((string) (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));
    if ($userFullName === '') {
        $userFullName = (string) ($currentUser['email'] ?? 'User');
    }
    $initials = strtoupper(substr($userFullName, 0, 1));
@endphp

<div class="d-flex min-vh-100">
    <!-- Sidebar -->
    <aside class="sidebar d-flex flex-column flex-shrink-0 border-end border-secondary border-opacity-10"
           :class="sidebarOpen ? 'show' : ''">
        <div class="p-3 border-bottom border-secondary border-opacity-10 d-flex align-items-center justify-content-between">
            <a href="/admin" class="d-flex align-items-center gap-2 text-decoration-none">
                <img src="/assets/images/logo.svg" alt="LogMonitor" style="height: 32px; width: auto;">
            </a>
            <button type="button" class="btn btn-sm btn-link text-secondary d-lg-none" @click="sidebarOpen = false" aria-label="{{ __('Close') }}">
                <i class="fa-solid fa-xmark fs-5"></i>
            </button>
        </div>

        <nav class="p-3 flex-grow-1 overflow-y-auto d-flex flex-column gap-4">
            <!-- Monitoring -->
            <div>
                <div class="text-secondary text-uppercase fw-bold px-2 mb-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">
                    {{ __('Monitoring') }}
                </div>
                <ul class="nav flex-column gap-1">
                    @if(hasPermission('admin.dashboard.index'))
                    <li class="nav-item">
                        <a href="/admin" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ $currentPath === '/admin' ? 'active' : '' }}">
                            <i class="fa-solid fa-gauge-high fa-fw"></i>
                            <span>{{ __('Dashboard') }}</span>
                        </a>
                    </li>
                    @endif
                    @if(hasPermission('admin.logs.index'))
                    <li class="nav-item">
                        <a href="/admin/logs" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ str_starts_with($currentPath, '/admin/logs') ? 'active' : '' }}">
                            <i class="fa-solid fa-layer-group fa-fw"></i>
                            <span>{{ __('Logs') }}</span>
                        </a>
                    </li>
                    @endif
                    @if(hasPermission('admin.logqueue.index'))
                    <li class="nav-item">
                        <a href="/admin/log-queue" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ str_starts_with($currentPath, '/admin/log-queue') ? 'active' : '' }}">
                            <i class="fa-solid fa-list-check fa-fw"></i>
                            <span>{{ __('Log Queue') }}</span>
                        </a>
                    </li>
                    @endif
                    @if(hasPermission('admin.apps.index'))
                    <li class="nav-item">
                        <a href="/admin/apps" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ str_starts_with($currentPath, '/admin/apps') ? 'active' : '' }}">
                            <i class="fa-solid fa-cubes fa-fw"></i>
                            <span>{{ __('Applications') }}</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>

            <!-- Settings -->
            @if(hasPermission('admin.settings.users.index') || hasPermission('admin.settings.roles.index') || hasPermission('admin.settings.queuemanager.index'))
            <div>
                <div class="text-secondary text-uppercase fw-bold px-2 mb-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">
                    {{ __('Settings') }}
                </div>
                <ul class="nav flex-column gap-1">
                    @if(hasPermission('admin.settings.users.index'))
                    <li class="nav-item">
                        <a href="/admin/settings/users" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ $currentPath === '/admin/settings/users' ? 'active' : '' }}">
                            <i class="fa-solid fa-users fa-fw"></i>
                            <span>{{ __('Users') }}</span>
                        </a>
                    </li>
                    @endif
                    @if(hasPermission('admin.settings.roles.index'))
                    <li class="nav-item">
                        <a href="/admin/settings/roles" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ str_starts_with($currentPath, '/admin/settings/roles') ? 'active' : '' }}">
                            <i class="fa-solid fa-shield-halved fa-fw"></i>
                            <span>{{ __('Roles') }}</span>
                        </a>
                    </li>
                    @endif
                    @if(hasPermission('admin.settings.queuemanager.index'))
                    <li class="nav-item">
                        <a href="/admin/settings/queue" class="nav-link sidebar-link d-flex align-items-center gap-2.5 rounded-3 px-3 py-2 {{ str_starts_with($currentPath, '/admin/settings/queue') ? 'active' : '' }}">
                            <i class="fa-solid fa-envelope-open-text fa-fw"></i>
                            <span>{{ __('Notification Queue') }}</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
        </nav>

        <div class="p-3 border-top border-secondary border-opacity-10">
            <div class="dropdown">
                <button class="btn btn-outline-custom w-100 d-flex align-items-center justify-content-between px-3 py-2 rounded-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        @if(!empty($currentUser['profile_image']))
                            <img src="/storage/{{ $currentUser['profile_image'] }}" alt="" class="rounded-circle object-fit-cover" style="width: 28px; height: 28px;">
                        @else
                            <span class="rounded-circle bg-info bg-opacity-20 text-info fw-bold d-inline-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                {{ $initials }}
                            </span>
                        @endif
                        <span class="small fw-semibold text-truncate">{{ $userFullName }}</span>
                    </span>
                    <i class="fa-solid fa-ellipsis-vertical text-secondary small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end w-100 shadow-lg">
                    <li>
                        <a class="dropdown-item py-2" href="/admin/settings/users/profile">
                            <i class="fa-solid fa-user-pen me-2 text-info"></i>{{ __('Profile') }}
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="/logout" method="POST" class="m-0">
                            {!! csrf_field() !!}
                            <button type="submit" class="dropdown-item py-2 text-danger">
                                <i class="fa-solid fa-right-from-bracket me-2"></i>{{ __('Logout') }}
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </aside>

    <!-- Backdrop for mobile sidebar -->
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="d-lg-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50" style="z-index: 1035;"></div>

    <!-- Main Wrapper -->
    <div class="flex-grow-1 d-flex flex-column min-w-0 main-wrapper">
        <header class="navbar-top px-3 px-lg-4 py-2.5 d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-10">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-custom d-lg-none" @click="sidebarOpen = !sidebarOpen" aria-label="{{ __('Toggle Navigation') }}">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <button type="button"
                        class="btn btn-sm btn-outline-custom d-none d-sm-inline-flex align-items-center gap-2 text-secondary px-3"
                        @click="$dispatch('open-command-palette')">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span class="small">{{ __('Quick Jump') }}</span>
                    <kbd class="bg-secondary bg-opacity-25 text-secondary px-1.5 rounded small">Ctrl+K</kbd>
                </button>
            </div>

            <div class="d-flex align-items-center gap-2">
                @yield('navbar-actions')
                @include('components.theme-switcher')
                @include('components.lang-switcher')
            </div>
        </header>

        <main class="flex-grow-1 p-3 p-lg-4">
            <div class="container-fluid px-0">
                @yield('content')
            </div>
        </main>

        <footer class="py-3 px-4 border-top border-secondary border-opacity-10 text-secondary small d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>&copy; {{ date('Y') }} LogMonitor</span>
            <span>{{ __('Centralized Telemetry & Error Monitoring') }}</span>
        </footer>
    </div>
</div>

@include('components.command-palette')
@include('components.scripts')
</body>
</html>
