@extends('layouts.admin')

@section('title', __('Applications'))

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Applications'));
@endphp

@include('components.page-header', [
    'title' => __('Manage Applications'),
    'subtitle' => __('Register applications, inspect API keys, and configure alert settings')
])

<div x-data="App.appsPageData()">
    <div class="row g-4">
        <!-- Create App Form -->
        @if(hasPermission('admin.apps.store'))
        <div class="col-lg-4">
            <div class="card card-custom shadow-sm border-0 h-100 rounded-4">
                <div class="card-body p-4">
                    <h3 class="h5 mb-4 fw-bold">
                        <i class="fa-solid fa-plus-circle text-info me-2"></i>{{ __('Register New App') }}
                    </h3>
                    <form action="/admin/apps" method="POST">
                        {!! csrf_field() !!}
                        <div class="mb-3">
                            <label for="app-name" class="form-label text-secondary small fw-medium">{{ __('Application Name') }}</label>
                            <input type="text" id="app-name" name="name" class="form-control" placeholder="{{ __('e.g. Mobile API') }}" required minlength="3" maxlength="100">
                        </div>
                        <button type="submit" class="btn btn-primary-custom w-100 fw-bold rounded-3">
                            <i class="fa-solid fa-plus me-2"></i>{{ __('Create Application') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endif

        <!-- Apps Table -->
        <div class="{{ hasPermission('admin.apps.store') ? 'col-lg-8' : 'col-12' }}">
            <div class="card card-custom shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ __('App Name') }}</th>
                                    <th>{{ __('API Key') }}</th>
                                    <th class="text-end pe-4">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($apps as $app)
                                    @php
                                        $escapedName = htmlspecialchars((string) $app['name'], ENT_QUOTES, 'UTF-8');
                                    @endphp
                                    <tr x-data="{ editingName: {{ json_encode((string) $app['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, keyCopied: false }">
                                        <td class="ps-4">
                                            @if(hasPermission('admin.apps.update'))
                                            <div x-show="editingAppId === {{ (int) $app['id'] }}" x-cloak>
                                                <form action="/admin/apps/{{ (int) $app['id'] }}" method="POST" class="d-flex gap-2 align-items-center m-0">
                                                    {!! csrf_field() !!}
                                                    <input type="text" name="name" x-model="editingName" class="form-control form-control-sm" required minlength="3" maxlength="100">
                                                    <button type="submit" class="btn btn-success btn-sm" title="{{ __('Save') }}"><i class="fa-solid fa-check"></i></button>
                                                    <button type="button" class="btn btn-secondary btn-sm" @click="editingAppId = null; editingName = {{ json_encode((string) $app['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}" title="{{ __('Cancel') }}"><i class="fa-solid fa-xmark"></i></button>
                                                </form>
                                            </div>
                                            @endif
                                            <div x-show="editingAppId !== {{ (int) $app['id'] }}">
                                                <span class="fw-semibold" x-text="editingName">{{ $app['name'] }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm" style="max-width: 340px;">
                                                <input type="text" class="form-control text-info font-monospace" value="{{ $app['api_key'] }}" readonly id="key-{{ (int) $app['id'] }}">
                                                <button class="btn btn-outline-secondary"
                                                        type="button"
                                                        @click="navigator.clipboard.writeText({{ json_encode((string) $app['api_key'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}).then(() => { keyCopied = true; setTimeout(() => keyCopied = false, 1500); })"
                                                        title="{{ __('Copy Key') }}">
                                                    <i class="fa-solid" :class="keyCopied ? 'fa-check text-success' : 'fa-copy'"></i>
                                                </button>
                                                @if(hasPermission('admin.apps.regeneratekey'))
                                                <form action="/admin/apps/{{ (int) $app['id'] }}/regenerate-key"
                                                      method="POST"
                                                      @submit.prevent="if (confirm({{ json_encode(__('Are you sure you want to regenerate the API key for this application? The old key will no longer work.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})) $el.submit()"
                                                      class="m-0">
                                                    {!! csrf_field() !!}
                                                    <button type="submit" class="btn btn-outline-warning" style="border-top-left-radius: 0; border-bottom-left-radius: 0;" title="{{ __('Regenerate API Key') }}">
                                                        <i class="fa-solid fa-arrows-rotate"></i>
                                                    </button>
                                                </form>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="d-inline-flex gap-3 align-items-center">
                                                <div x-show="editingAppId !== {{ (int) $app['id'] }}" :class="editingAppId !== {{ (int) $app['id'] }} ? 'd-flex gap-3 align-items-center' : 'd-none'">
                                                    @if(hasPermission('admin.apps.update'))
                                                    <button type="button"
                                                            class="btn btn-link text-warning p-0"
                                                            title="{{ __('Edit name') }}"
                                                            @click="editingAppId = {{ (int) $app['id'] }}; editingName = {{ json_encode((string) $app['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>
                                                    @endif
                                                    @if(hasPermission('admin.appsettings.index'))
                                                    <button type="button"
                                                            class="btn btn-link text-info p-0"
                                                            title="{{ __('Application settings') }}"
                                                            @click="openSettings({{ (int) $app['id'] }}, {{ json_encode((string) $app['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                                        <i class="fa-solid fa-gear"></i>
                                                    </button>
                                                    @endif
                                                    @if(hasPermission('admin.apps.delete'))
                                                    <form action="/admin/apps/{{ (int) $app['id'] }}/delete"
                                                          method="POST"
                                                          @submit.prevent="if (confirm({{ json_encode(__('Are you sure you want to delete this application? The associated logs will remain but you will no longer be able to send new ones with this key.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }})) $el.submit()"
                                                          class="m-0">
                                                        {!! csrf_field() !!}
                                                        <button type="submit" class="btn btn-link text-danger p-0" title="{{ __('Delete application') }}">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                    @endif
                                                </div>
                                                <div x-show="editingAppId === {{ (int) $app['id'] }}" x-cloak>
                                                    <span class="text-secondary small">{{ __('Editing...') }}</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                @if(empty($apps))
                                    <tr>
                                        <td colspan="3" class="text-center py-5 text-secondary">
                                            <i class="fa-solid fa-cubes d-block fs-2 mb-2 opacity-25"></i>
                                            {{ __('No applications registered yet.') }}
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <template x-if="showSettingsModal">
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
             style="z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);"
             @click.self="showSettingsModal = false">
            <div class="card card-custom shadow-lg border border-secondary border-opacity-25 rounded-4 overflow-hidden" style="width: 100%; max-width: 720px;" @click.stop>
                <div class="card-header border-bottom border-secondary border-opacity-25 p-4 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-sliders text-info"></i>
                        <h4 class="h5 mb-0 fw-bold">
                            {{ __('Settings:') }} <span class="text-info" x-text="currentAppName"></span>
                        </h4>
                    </div>
                    <button type="button" class="btn-close" @click="showSettingsModal = false" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="card-body p-4 overflow-auto" style="max-height: 70vh;">
                    <div class="mb-4 p-3 rounded-3 bg-body-tertiary border border-secondary border-opacity-25">
                        <h5 class="h6 mb-3 text-secondary text-uppercase fw-bold small">{{ __('Add New Settings (Multi-Save)') }}</h5>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-5">
                                <label class="form-label text-secondary small fw-medium">{{ __('Key') }}</label>
                                <input type="text" id="setting-key-input" x-model="newKey" class="form-control form-control-sm" placeholder="{{ __('Unique Key') }}" @keydown.enter.prevent="addPendingSetting()">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label text-secondary small fw-medium">{{ __('Value') }}</label>
                                <input type="text" x-model="newValue" class="form-control form-control-sm" placeholder="{{ __('Value') }}" @keydown.enter.prevent="addPendingSetting()">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-info btn-sm w-100 fw-bold text-white" @click="addPendingSetting()">
                                    <i class="fa-solid fa-plus me-1"></i>{{ __('Add') }}
                                </button>
                            </div>
                        </div>

                        <template x-if="pendingSettings.length > 0">
                            <div class="mt-3">
                                <div class="table-responsive rounded border border-secondary border-opacity-25 mb-3">
                                    <table class="table table-hover mb-0 align-middle small">
                                        <thead>
                                            <tr>
                                                <th class="ps-3 py-2">{{ __('New Key') }}</th>
                                                <th class="py-2">{{ __('Value') }}</th>
                                                <th class="text-end pe-3 py-2" style="width: 60px;">{{ __('Delete') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="(item, idx) in pendingSettings" :key="idx">
                                                <tr>
                                                    <td class="ps-3 py-2 font-monospace text-warning" x-text="item.key"></td>
                                                    <td class="py-2" x-text="item.value"></td>
                                                    <td class="text-end pe-3 py-2">
                                                        <button type="button" class="btn btn-link text-danger p-0" @click="removePendingSetting(idx)">
                                                            <i class="fa-solid fa-circle-xmark"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn btn-success btn-sm fw-bold shadow-sm" @click="saveAllSettings()" :disabled="saving">
                                    <span x-show="!saving"><i class="fa-solid fa-floppy-disk me-1"></i>{{ __('Save All New Settings') }} (<span x-text="pendingSettings.length"></span>)</span>
                                    <span x-show="saving"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>{{ __('Saving...') }}</span>
                                </button>
                            </div>
                        </template>
                    </div>

                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="h6 mb-0 text-secondary text-uppercase fw-bold small">{{ __('Active Settings') }}</h5>
                        </div>

                        <div x-show="loading" class="text-center py-4">
                            <div class="spinner-border text-info" role="status">
                                <span class="visually-hidden">{{ __('Loading...') }}</span>
                            </div>
                            <p class="text-secondary small mt-2">{{ __('Loading settings...') }}</p>
                        </div>

                        <div x-show="!loading">
                            <div x-show="settings.length === 0" class="text-center py-4 border border-secondary border-opacity-25 rounded">
                                <i class="fa-solid fa-gears text-secondary opacity-50 mb-2 fs-4 d-block"></i>
                                <p class="text-secondary small mb-0">{{ __('This application has no active settings.') }}</p>
                            </div>

                            <div x-show="settings.length > 0" class="table-responsive rounded border border-secondary border-opacity-25">
                                <table class="table table-hover mb-0 align-middle small">
                                    <thead>
                                        <tr>
                                            <th class="ps-3 py-2">{{ __('Key') }}</th>
                                            <th class="py-2">{{ __('Value') }}</th>
                                            <th class="text-end pe-3 py-2" style="width: 120px;">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="setting in settings" :key="setting.dbKey">
                                            <tr>
                                                <td class="ps-3 py-2 font-monospace">
                                                    <span x-show="!setting.editing" class="text-info" x-text="setting.key"></span>
                                                    <input x-show="setting.editing" type="text" x-model="setting.tempKey" class="form-control form-control-sm font-monospace">
                                                </td>
                                                <td class="py-2 text-break" :class="setting.dbKey !== setting.tempKey || setting.dbValue !== setting.tempValue ? 'bg-warning bg-opacity-10' : ''">
                                                    <span x-show="!setting.editing" x-text="setting.value"></span>
                                                    <input x-show="setting.editing" type="text" x-model="setting.tempValue" class="form-control form-control-sm">
                                                </td>
                                                <td class="text-end pe-3 py-2">
                                                    <div x-show="!setting.editing && !setting.deleting" :class="(!setting.editing && !setting.deleting) ? 'd-inline-flex gap-2' : 'd-none'">
                                                        <button type="button" class="btn btn-link text-warning p-0" title="{{ __('Edit inline') }}" @click="setting.editing = true">
                                                            <i class="fa-solid fa-pen-to-square"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-link text-danger p-0" title="{{ __('Delete') }}" @click="setting.deleting = true">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    </div>
                                                    <div x-show="setting.deleting" x-cloak :class="setting.deleting ? 'd-inline-flex align-items-center gap-1' : 'd-none'">
                                                        <button type="button" class="btn btn-danger btn-sm py-0 px-2" @click="deleteSetting(setting.dbKey)">{{ __('Yes') }}</button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" @click="setting.deleting = false">{{ __('No') }}</button>
                                                    </div>
                                                    <div x-show="setting.editing" x-cloak :class="setting.editing ? 'd-inline-flex gap-2' : 'd-none'">
                                                        <button type="button" class="btn btn-link text-success p-0" title="{{ __('Save setting') }}" @click="saveSingleSetting(setting)">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-link text-secondary p-0" title="{{ __('Reset row') }}" @click="setting.tempKey = setting.dbKey; setting.tempValue = setting.dbValue; setting.editing = false">
                                                            <i class="fa-solid fa-xmark"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer border-top border-secondary border-opacity-25 p-3 d-flex justify-content-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-4" @click="showSettingsModal = false">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

