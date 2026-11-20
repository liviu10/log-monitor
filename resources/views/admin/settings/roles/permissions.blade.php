@extends('layouts.admin')

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Settings'), '/admin/settings')
        ->add(__('Roles'), '/admin/settings/roles')
        ->add(__('Permissions'));

    $isAdmin = (strtoupper((string) ($role['name'] ?? '')) === 'ADMIN');

    // Group permissions by group_name for organized display
    $grouped = [];
    if (! $isAdmin) {
        foreach ($permissions as $perm) {
            $groupName = $perm['group_name'] ?? 'Ungrouped';
            $grouped[$groupName][] = $perm;
        }
    }
@endphp

@include('components.page-header', [
    'title' => __('Role Permissions'),
    'subtitle' => __('Configure actions available to the role:') . ' <strong class="text-warning">' . htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') . '</strong>',
    'backUrl' => '/admin/settings/roles'
])

<div class="row">
    <div class="col-12">
        @if($isAdmin)
            <!-- Premium Message for administrator (Bypass logic) -->
            <div class="card card-custom p-5 text-center">
                <div class="card-body">
                    <div class="bg-warning bg-opacity-10 p-4 rounded-circle text-warning d-inline-flex mb-4">
                        <i class="fa-solid fa-shield-halved fs-1"></i>
                    </div>
                    <h3 class="fw-bold mb-2">{{ __('ADMIN Bypass Enabled') }}</h3>
                    <p class="text-secondary mx-auto" style="max-width: 600px;">
                        {{ __('The ADMIN role has full, global, and implicit rights across all controllers and actions in the system.') }}
                        {{ __('This is hardcoded as an absolute security bypass, so permissions do not need to be manually configured or toggled.') }}
                    </p>
                    <a href="/admin/settings/roles" class="btn btn-outline-warning mt-3">
                        <i class="fa-solid fa-arrow-left me-2"></i>{{ __('Back to Roles') }}
                    </a>
                </div>
            </div>
        @else
            <!-- Form with AlpineJS -->
            <div class="card card-custom p-4" x-data="permissionsManagement()">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 gap-3">
                        <div>
                            <h4 class="fw-bold mb-1"><i class="fa-solid fa-sliders text-warning me-2"></i>{{ __('Manage Permissions') }}</h4>
                            <span class="text-secondary small">{{ __('Total active permissions:') }} <strong class="text-info" x-text="activeCount()"></strong> / {{ count($permissions) }}</span>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" @click="selectAll()">
                                <i class="fa-solid fa-check me-1"></i>{{ __('Select All') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" @click="deselectAll()">
                                <i class="fa-solid fa-xmark me-1"></i>{{ __('Deselect All') }}
                            </button>
                            <div class="position-relative" style="min-width: 250px;">
                                <input type="text" 
                                       x-model="searchQuery" 
                                       class="form-control ps-5 pe-5 rounded-3" 
                                       placeholder="{{ __('Search permissions...') }}"
                                       aria-label="{{ __('Search permissions...') }}">
                                <i class="fa-solid fa-magnifying-glass position-absolute start-0 top-50 translate-middle-y ms-3 text-secondary opacity-75"></i>
                                <button type="button" 
                                        x-show="searchQuery !== ''" 
                                        x-cloak
                                        @click="searchQuery = ''" 
                                        class="btn btn-sm btn-link position-absolute end-0 top-50 translate-middle-y me-2 text-secondary p-0 border-0 text-decoration-none" 
                                        style="line-height: 1;"
                                        aria-label="{{ __('Clear search') }}"
                                        title="{{ __('Clear search') }}">
                                    <i class="fa-solid fa-circle-xmark fs-6"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    @if(empty($grouped))
                        @include('components.empty-state', [
                            'icon' => 'fa-solid fa-sliders',
                            'title' => __('No controller permissions detected'),
                            'message' => __('No controller permissions detected in the application.')
                        ])
                    @else
                        <div x-show="!hasSearchResults()" class="empty-state-box py-5 text-center my-4">
                            <div class="empty-state-icon mx-auto shadow-sm mb-3">
                                <i class="fa-solid fa-magnifying-glass fs-3 text-secondary"></i>
                            </div>
                            <h6 class="fw-bold text-body-emphasis mb-1">{{ __('No permissions found') }}</h6>
                            <p class="text-secondary small mb-3">{{ __('No permissions match your search criteria.') }}</p>
                            <button type="button" @click="searchQuery = ''" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5">
                                <i class="fa-solid fa-rotate-left me-1"></i>{{ __('Clear Search') }}
                            </button>
                        </div>

                        <div class="row g-4" x-show="hasSearchResults()">
                            @foreach($grouped as $controllerName => $perms)
                                <div class="col-lg-6" x-show="matchesCard('{{ addslashes($controllerName) }}')">
                                    <div class="card card-custom border border-secondary border-opacity-10 rounded-4 p-4 h-100 transition-hover" style="background-color: var(--card-bg);">
                                        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom border-secondary border-opacity-10 pb-3">
                                            <h5 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-folder-open text-info"></i>
                                                <span>{{ $controllerName }}</span>
                                            </h5>
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 shadow-sm" @click="toggleGroup([{{ implode(',', array_column($perms, 'id')) }}])">
                                                <i class="fa-solid fa-check-double me-1"></i>{{ __('Toggle All') }}
                                            </button>
                                        </div>
                                        <div class="list-group list-group-flush">
                                            @foreach($perms as $p)
                                                <div class="list-group-item bg-transparent border-0 px-0 py-2.5 d-flex align-items-center justify-content-between"
                                                     x-show="matchesPerm('{{ $p['id'] }}')">
                                                    <div class="pe-3">
                                                        <span class="fw-semibold d-block text-body-emphasis">{{ __($p['permission_key']) }}</span>
                                                        <small class="text-secondary small">{{ $p['name'] }}</small>
                                                    </div>
                                                    <div class="form-check form-switch fs-5 mb-0">
                                                        <input class="form-check-input" type="checkbox" style="cursor: pointer;" x-model="permissions['{{ $p['id'] }}']" @change="isDirty = true">
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 pt-4 border-top border-secondary border-opacity-10 d-flex justify-content-center gap-3">
                            <a href="/admin/settings/roles" class="btn btn-outline-secondary rounded-pill px-4">
                                {{ __('Cancel') }}
                            </a>
                            <button @click="submit()" :disabled="isSaving" class="btn btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm">
                                <span x-show="!isSaving"><i class="fa-solid fa-floppy-disk me-2"></i>{{ __('Save Permissions') }}</span>
                                <span x-show="isSaving"><i class="fa-solid fa-spinner fa-spin me-2"></i>{{ __('Saving...') }}</span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

@if(! $isAdmin)
<script nonce="{{ APP_NONCE }}">
    function permissionsManagement() {
        return {
            searchQuery: '',
            isDirty: false,
            isSaving: false,
            permissions: {
                @foreach($permissions as $p)
                    '{{ $p['id'] }}': {{ $p['value'] ? 'true' : 'false' }},
                @endforeach
            },
            allPerms: [
                @foreach($permissions as $p)
                    {
                        id: '{{ $p['id'] }}',
                        group_name: {!! json_encode($p['group_name'] ?? '') !!},
                        key: {!! json_encode($p['permission_key'] ?? '') !!},
                        name: {!! json_encode($p['name'] ?? '') !!},
                        title: {!! json_encode(__($p['permission_key'] ?? '')) !!}
                    },
                @endforeach
            ],
            init() {
                window.addEventListener('beforeunload', (e) => {
                    if (this.isDirty && !this.isSaving) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            },
            activeCount() {
                return Object.values(this.permissions).filter(Boolean).length;
            },
            selectAll() {
                Object.keys(this.permissions).forEach(id => {
                    this.permissions[id] = true;
                });
                this.isDirty = true;
            },
            deselectAll() {
                Object.keys(this.permissions).forEach(id => {
                    this.permissions[id] = false;
                });
                this.isDirty = true;
            },
            hasSearchResults() {
                if (this.searchQuery.trim() === '') return true;
                const q = this.searchQuery.toLowerCase();
                return this.allPerms.some(p => 
                    p.group_name.toLowerCase().includes(q) ||
                    p.key.toLowerCase().includes(q) ||
                    p.name.toLowerCase().includes(q) ||
                    p.title.toLowerCase().includes(q)
                );
            },
            matchesCard(controllerName) {
                if (this.searchQuery.trim() === '') return true;
                const q = this.searchQuery.toLowerCase();
                if (controllerName.toLowerCase().includes(q)) return true;
                return this.allPerms.some(p => 
                    p.group_name.toLowerCase() === controllerName.toLowerCase() &&
                    (p.key.toLowerCase().includes(q) || p.name.toLowerCase().includes(q) || p.title.toLowerCase().includes(q))
                );
            },
            matchesPerm(id) {
                if (this.searchQuery.trim() === '') return true;
                const q = this.searchQuery.toLowerCase();
                const p = this.allPerms.find(item => item.id == id);
                if (!p) return false;
                return p.group_name.toLowerCase().includes(q) ||
                       p.key.toLowerCase().includes(q) ||
                       p.name.toLowerCase().includes(q) ||
                       p.title.toLowerCase().includes(q);
            },
            toggleGroup(ids) {
                let allSelected = ids.every(id => this.permissions[id]);
                ids.forEach(id => {
                    this.permissions[id] = !allSelected;
                });
                this.isDirty = true;
            },
            submit() {
                this.isSaving = true;
                let payload = {
                    permissions: this.permissions,
                    csrf_token: '{{ csrf_token() }}'
                };

                fetch('/admin/settings/roles/{{ $role['id'] }}/permissions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.isDirty = false;
                        App.handleToast({
                            type: 'success',
                            title: '{{ __('Success') }}',
                            message: '{{ __('Permissions saved successfully.') }}',
                            toastDelay: 1500,
                            redirectUrl: window.location.href
                        });
                    } else {
                        this.isSaving = false;
                        App.handleToast({
                            type: 'danger',
                            title: '{{ __('Error') }}',
                            message: data.message || '{{ __('Failed to save permissions.') }}',
                            toastDelay: 5000
                        });
                    }
                })
                .catch(err => {
                    console.error(err);
                    this.isSaving = false;
                    App.handleToast({
                        type: 'danger',
                        title: '{{ __('Error') }}',
                        message: '{{ __('An unexpected error occurred.') }}',
                        toastDelay: 5000
                    });
                });
            }
        };
    }
</script>
@endif
@endsection
