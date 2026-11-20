<template x-if="totalItems === 0">
    <div class="empty-state-box py-4">
        <div class="empty-state-icon mx-auto shadow-sm mb-3">
            <i class="fa-solid fa-folder-open fs-3"></i>
        </div>
        <h6 class="fw-bold text-body-emphasis mb-1">{{ __('No records found.') }}</h6>
        <p class="text-secondary small mb-3">{{ __('Try adjusting your search query or filter options.') }}</p>
        <button type="button" @click="resetParams()" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5">
            <i class="fa-solid fa-rotate-left me-1"></i>{{ __('Reset Filters') }}
        </button>
    </div>
</template>

<template x-if="totalItems > 0">
    <div class="d-flex flex-column gap-3">
        <!-- Users Cards -->
        <template x-if="resource === 'users'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.status === 'Active' ? 'status-active' : 'status-inactive'">
                                <span class="status-dot" :class="item.status === 'Active' ? 'pulse' : ''"></span>
                                <span x-text="item.status_label"></span>
                            </span>
                        </div>
                        <div class="mb-3">
                             <h6 class="fw-bold text-body-emphasis mb-1" x-text="item.first_name + ' ' + item.last_name"></h6>
                            <span class="d-block text-info small mb-2 text-break"><i class="fa-solid fa-envelope me-1 opacity-75"></i><span x-text="item.email"></span></span>
                            <div>
                                <span class="badge px-3 py-1" :class="item.role_name === 'ADMIN' ? 'bg-warning text-dark' : 'bg-secondary'" x-text="item.role_label || item.role_name"></span>
                            </div>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex justify-content-end">
                            <button @click="$dispatch('edit-user', item)" class="btn btn-sm btn-outline-primary px-3 py-1.5 w-100 d-flex align-items-center justify-content-center gap-2 rounded-3" :disabled="item.id === currentUserId">
                                <i class="fa-solid fa-edit"></i>{{ __('Edit') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Roles Cards -->
        <template x-if="resource === 'roles'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.is_active ? 'status-active' : 'status-inactive'">
                                <span class="status-dot" :class="item.is_active ? 'pulse' : ''"></span>
                                <span x-text="item.is_active ? '{{ __('Active') }}' : '{{ __('Inactive') }}'"></span>
                            </span>
                        </div>
                        <div class="my-2">
                            <span class="badge fs-6 px-3 py-2 w-100 text-center" :class="item.name === 'ADMIN' ? 'bg-warning text-dark' : 'bg-secondary'" x-text="item.name_label || item.name"></span>
                        </div>
                        <div class="pt-2 mt-2 border-top border-secondary border-opacity-10">
                            <a :href="'/admin/settings/roles/' + item.id + '/permissions'" class="btn btn-sm btn-outline-warning w-100 py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3">
                                <i class="fa-solid fa-key"></i>{{ __('Permissions') }}
                            </a>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Pages Cards -->
        <template x-if="resource === 'pages'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.visibility === 'Published' ? 'status-active' : (item.visibility === 'Draft' ? 'status-pending' : 'status-info')">
                                <span class="status-dot" :class="item.visibility === 'Published' ? 'pulse' : ''"></span>
                                <span x-text="item.visibility"></span>
                            </span>
                        </div>
                        <div class="mb-3">
                            <h6 class="fw-bold text-body-emphasis mb-1" x-text="item.title"></h6>
                            <span class="d-block text-info small mb-2 text-break"><i class="fa-solid fa-link me-1 opacity-75"></i><span x-text="item.slug"></span></span>
                            <span class="small text-secondary d-block"><i class="fa-regular fa-calendar me-1 opacity-50"></i><span x-text="formatDateStr(item.created_at)"></span></span>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex gap-2">
                            <a :href="'/admin/management/cms/pages/' + item.id" class="btn btn-sm btn-outline-primary flex-grow-1 py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3">
                                <i class="fa-solid fa-edit"></i>{{ __('Edit') }}
                            </a>
                            @php
                                $mcSupportedLocales = function_exists('supportedLocales') ? array_keys(supportedLocales()) : array_keys((array) config('app.supported_locales', ['en' => 'English', 'ro' => 'Romana']));
                                $mcLocalesSuffixPattern = implode('|', array_map('preg_quote', $mcSupportedLocales));
                                $mcDefaultLocale = (string) config('app.locale', 'en');
                            @endphp
                            <a :href="item.visibility === 'Published' ? (item.url ? item.url.replace(new RegExp('-(' + '{{ $mcLocalesSuffixPattern }}' + ')$'), '') : '') + '?lang=' + (item.category_language || '{{ $mcDefaultLocale }}') : '/admin/management/cms/pages/' + item.id + '/preview?lang=' + (item.category_language || '{{ $mcDefaultLocale }}')" target="_blank" class="btn btn-sm btn-outline-info flex-grow-1 py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3">
                                <i class="fa-solid fa-eye"></i><span x-text="item.visibility === 'Published' ? '{{ __('View') }}' : '{{ __('Preview') }}'"></span>
                            </a>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Categories Cards -->
        <template x-if="resource === 'categories'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.is_active ? 'status-active' : 'status-inactive'">
                                <span class="status-dot" :class="item.is_active ? 'pulse' : ''"></span>
                                <span x-text="item.is_active ? '{{ __('Active') }}' : '{{ __('Inactive') }}'"></span>
                            </span>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold text-body-emphasis mb-0" x-text="item.name"></h6>
                                <span class="badge bg-secondary px-2.5 py-1" x-text="item.language ? item.language.toUpperCase() : ''"></span>
                            </div>
                            <span class="small text-secondary d-block"><i class="fa-regular fa-calendar me-1 opacity-50"></i><span x-text="formatDateStr(item.created_at)"></span></span>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex gap-2">
                            <button @click="$dispatch('edit-category', item)" class="btn btn-sm btn-outline-primary flex-fill py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3">
                                <i class="fa-solid fa-edit"></i>{{ __('Edit') }}
                            </button>
                            <button @click="$dispatch('delete-category', item)" class="btn btn-sm btn-outline-danger flex-fill py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3">
                                <i class="fa-solid fa-trash"></i>{{ __('Delete') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Media Cards -->
        <template x-if="resource === 'media'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge bg-secondary px-2.5 py-1" x-text="item.type"></span>
                        </div>
                        <div class="mb-3">
                            <h6 class="fw-bold text-body-emphasis mb-1" x-text="item.title"></h6>
                            <span class="d-block text-secondary small mb-1"><i class="fa-solid fa-file-lines me-1 opacity-75"></i><span x-text="item.page_title || '-'"></span></span>
                            <span class="d-block text-info small mb-2 text-break"><i class="fa-solid fa-folder me-1 opacity-75"></i><span x-text="item.path"></span></span>
                            <span class="small text-secondary d-block"><i class="fa-regular fa-calendar me-1 opacity-50"></i><span x-text="formatDateStr(item.created_at)"></span></span>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10">
                            <a :href="'/storage/' + item.path" target="_blank" class="btn btn-sm btn-outline-info w-100 py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3">
                                <i class="fa-solid fa-eye"></i>{{ __('View') }}
                            </a>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- APIs Cards -->
        <template x-if="resource === 'apis'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.is_active ? 'status-active' : 'status-inactive'">
                                <span class="status-dot" :class="item.is_active ? 'pulse' : ''"></span>
                                <span x-text="item.is_active ? '{{ __('Installed') }}' : '{{ __('Uninstalled') }}'"></span>
                            </span>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="fa-solid" :class="'fa-' + (item.metadata_decoded?.icon || 'puzzle-piece') + ' text-primary'"></i>
                                <h6 class="fw-bold text-body-emphasis mb-0" x-text="item.name"></h6>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25" x-text="item.category"></span>
                                <span class="small text-secondary"><i class="fa-solid fa-code-branch me-1"></i><span x-text="item.version_number || item.version"></span></span>
                                <span class="small text-secondary"><i class="fa-solid fa-user me-1"></i><span x-text="item.metadata_decoded?.author || 'System'"></span></span>
                            </div>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10">
                            <button @click="$dispatch('toggle-api', item)" class="btn btn-sm w-100 py-1.5 d-flex align-items-center justify-content-center gap-2 rounded-3" :class="item.is_active ? 'btn-outline-danger' : 'btn-primary'">
                                <i class="fa-solid" :class="item.is_active ? 'fa-power-off' : 'fa-play'"></i>
                                <span x-text="item.is_active ? '{{ __('Uninstall') }}' : '{{ __('Install') }}'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Comments Cards -->
        <template x-if="resource === 'comments'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.status === 'Approved' ? 'status-active' : (item.status === 'Pending' ? 'status-pending' : (item.status === 'Spam' ? 'status-danger' : 'status-rejected'))">
                                <span class="status-dot" :class="item.status === 'Approved' || item.status === 'Pending' ? 'pulse' : ''"></span>
                                <span x-text="item.status === 'Approved' ? '{{ __('Approved') }}' : (item.status === 'Pending' ? '{{ __('Pending Approval') }}' : (item.status === 'Spam' ? '{{ __('Spam') }}' : '{{ __('Rejected') }}'))"></span>
                            </span>
                        </div>
                        <div class="mb-2">
                            <h6 class="fw-bold text-body-emphasis mb-1" x-text="item.author_name"></h6>
                            <span class="d-block text-secondary small mb-1" x-text="item.author_email"></span>
                            <span class="d-block text-primary small"><i class="fa-solid fa-file-invoice me-1"></i><span x-text="item.content_title"></span></span>
                        </div>
                        <div class="p-2 bg-body rounded border border-secondary border-opacity-10 small mb-3" style="white-space: pre-wrap;" x-text="item.comment"></div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                            <span class="small text-secondary" x-text="formatDateStr(item.created_at)"></span>
                            <div class="d-flex gap-1.5">
                                <template x-if="item.status !== 'Approved'">
                                    <form :action="'/admin/management/cms/comments/' + item.id + '/status'" method="POST" class="d-inline m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i>';}">
                                        <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
                                        <input type="hidden" name="status" value="Approved">
                                        <button type="submit" class="btn btn-sm btn-outline-success px-2 py-1 rounded-3">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                    </form>
                                </template>
                                <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 rounded-3" @click="$dispatch('confirm-delete-comment', { id: item.id, comment: item.comment })">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Queue Cards -->
        <template x-if="resource === 'queue'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge-status-pill" :class="item.status === 'completed' ? 'status-active' : (item.status === 'processing' ? 'status-info' : (item.status === 'pending' ? 'status-pending' : (item.status === 'expired' ? 'status-inactive' : 'status-danger')))">
                                <span class="status-dot" :class="item.status === 'processing' || item.status === 'pending' ? 'pulse' : ''"></span>
                                <span x-text="item.status"></span>
                            </span>
                        </div>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge" :class="item.queue === 'critical' ? 'bg-danger text-white' : (item.queue === 'low' ? 'bg-secondary text-white' : 'bg-primary text-white')" x-text="item.queue"></span>
                                <span class="small fw-semibold text-secondary" x-text="item.attempts + ' / ' + (item.max_attempts || 5)"></span>
                            </div>
                            <div class="small font-monospace text-truncate py-1" x-text="item.payload"></div>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                            <span class="small text-secondary" x-text="item.created_at"></span>
                            <div class="d-flex gap-1.5">
                                <button type="button" @click="$dispatch('view-queue-payload', item)" class="btn btn-sm btn-outline-info px-2 py-1 rounded-3" title="{{ __('View Payload') }}">
                                    <i class="fa-solid fa-code"></i>
                                </button>
                                <template x-if="item.error_message">
                                    <button type="button" @click="$dispatch('view-queue-error', item)" class="btn btn-sm btn-outline-warning px-2 py-1 rounded-3" title="{{ __('View Error Details') }}">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </button>
                                </template>
                                <template x-if="item.status === 'failed' || item.status === 'expired'">
                                    <button type="button" @click="$dispatch('retry-queue-job', item)" class="btn btn-sm btn-outline-primary px-2 py-1 rounded-3" title="{{ __('Retry Job') }}">
                                        <i class="fa-solid fa-rotate-right"></i>
                                    </button>
                                </template>
                                <button type="button" @click="$dispatch('delete-queue-job', item)" class="btn btn-sm btn-outline-danger px-2 py-1 rounded-3" title="{{ __('Delete Job') }}">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Analytics Page Views Cards -->
        <template x-if="resource === 'analytics_page_views'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="small text-secondary fw-bold">ID #<span x-text="item.id"></span></span>
                            <span class="badge" :class="item.device_type === 'mobile' ? 'bg-warning text-dark' : (item.device_type === 'tablet' ? 'bg-info text-dark' : 'bg-primary text-white')">
                                <i class="fa-solid me-1" :class="item.device_type === 'mobile' ? 'fa-mobile-screen' : (item.device_type === 'tablet' ? 'fa-tablet-screen-button' : 'fa-desktop')"></i>
                                <span x-text="item.device_type ? item.device_type.toUpperCase() : 'DESKTOP'"></span>
                            </span>
                        </div>
                        <div class="mb-2">
                            <h6 class="fw-bold text-body-emphasis mb-1 text-break" x-text="item.url_path"></h6>
                            <template x-if="item.content_title">
                                <span class="d-block text-primary small mb-1"><i class="fa-solid fa-file-invoice me-1"></i><span x-text="item.content_title"></span></span>
                            </template>
                            <div class="small text-secondary mb-1">
                                <span class="fw-semibold">{{ __('Referrer') }}:</span> <span class="font-monospace" x-text="item.referrer_host || '-'"></span>
                            </div>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <template x-if="item.utm_source">
                                    <span class="badge bg-secondary bg-opacity-25 text-body small">Source: <span x-text="item.utm_source"></span></span>
                                </template>
                                <template x-if="item.utm_medium">
                                    <span class="badge bg-secondary bg-opacity-25 text-body small">Medium: <span x-text="item.utm_medium"></span></span>
                                </template>
                                <template x-if="item.utm_campaign">
                                    <span class="badge bg-info bg-opacity-25 text-info-emphasis small">Campaign: <span x-text="item.utm_campaign"></span></span>
                                </template>
                                <template x-if="item.utm_term">
                                    <span class="badge bg-secondary bg-opacity-25 text-body small">Term: <span x-text="item.utm_term"></span></span>
                                </template>
                                <template x-if="item.utm_content">
                                    <span class="badge bg-secondary bg-opacity-25 text-body small">Content: <span x-text="item.utm_content"></span></span>
                                </template>
                            </div>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                            <span class="small text-secondary" x-text="item.created_at"></span>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Notification Templates Cards -->
        <template x-if="resource === 'templates'">
            <div class="d-flex flex-column gap-3">
                <template x-for="item in items" :key="item.id">
                    <div class="card card-custom border border-secondary border-opacity-25 p-3 rounded-4 shadow-sm bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary border-opacity-10 pb-2 mb-2">
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace px-2 py-1 fs-6" x-text="item.event_name"></span>
                            <span class="badge-status-pill" :class="(item.is_active == 1 || item.is_active === true) ? 'status-active' : 'status-inactive'">
                                <span class="status-dot" :class="(item.is_active == 1 || item.is_active === true) ? 'pulse' : ''"></span>
                                <span x-text="(item.is_active == 1 || item.is_active === true) ? '{{ __('Active') }}' : '{{ __('Inactive') }}'"></span>
                            </span>
                        </div>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-info bg-opacity-25 text-info-emphasis px-2.5 py-1 text-uppercase" x-text="item.channel"></span>
                                    <span class="badge bg-secondary bg-opacity-25 text-body px-2 py-1 text-uppercase fw-bold" x-text="item.language || 'en'"></span>
                                </div>
                                <span class="small text-secondary" x-text="'ID #' + item.id"></span>
                            </div>
                            <h6 class="fw-bold text-body-emphasis mb-1" x-text="item.subject"></h6>
                        </div>
                        <div class="pt-2 border-top border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                            <span class="small text-secondary" x-text="formatDateStr(item.updated_at || item.created_at)"></span>
                            <div class="d-flex gap-1.5">
                                <button type="button" class="btn btn-sm btn-outline-info px-2 py-1 rounded-3" @click="$dispatch('preview-template', item)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                                <a :href="'/admin/settings/templates/' + item.id + '/edit'" class="btn btn-sm btn-outline-primary px-2 py-1 rounded-3" title="{{ __('Edit Template') }}">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 rounded-3" @click="$dispatch('delete-template', item)">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
