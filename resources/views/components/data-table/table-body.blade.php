<!-- Users Row Template -->
<template x-if="resource === 'users'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td class="fw-bold" x-text="item.first_name + ' ' + item.last_name"></td>
            <td class="text-info" x-text="item.email"></td>
            <td>
                <span class="badge fs-6 px-3 py-2" :class="item.role_name === 'ADMIN' ? 'bg-warning text-dark' : 'bg-secondary'" x-text="item.role_label || item.role_name"></span>
            </td>
            <td>
                <span class="badge-status-pill" :class="item.status === 'Active' ? 'status-active' : 'status-inactive'">
                    <span class="status-dot" :class="item.status === 'Active' ? 'pulse' : ''"></span>
                    <span x-text="item.status_label"></span>
                </span>
            </td>
            <td class="text-end">
                <button
                    @click="$dispatch('edit-user', item)"
                    class="btn btn-sm btn-outline-primary"
                    :disabled="item.id === currentUserId"
                >
                    <i class="fa-solid fa-edit me-1"></i>{{ __('Edit') }}
                </button>
            </td>
        </tr>
    </template>
</template>

<!-- Roles Row Template -->
<template x-if="resource === 'roles'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <span class="badge fs-6 px-3 py-2" :class="item.name === 'ADMIN' ? 'bg-warning text-dark' : 'bg-secondary'" x-text="item.name_label || item.name"></span>
            </td>
            <td>
                <span class="badge-status-pill" :class="item.is_active ? 'status-active' : 'status-inactive'">
                    <span class="status-dot" :class="item.is_active ? 'pulse' : ''"></span>
                    <span x-text="item.is_active ? '{{ __('Active') }}' : '{{ __('Inactive') }}'"></span>
                </span>
            </td>
            <td class="text-end">
                <a :href="'/admin/settings/roles/' + item.id + '/permissions'" class="btn btn-sm btn-outline-warning">
                    <i class="fa-solid fa-key me-1"></i>{{ __('Permissions') }}
                </a>
            </td>
        </tr>
    </template>
</template>

<!-- Pages Row Template -->
<template x-if="resource === 'pages'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td class="fw-bold" x-text="item.title"></td>
            <td class="text-info" x-text="item.slug"></td>
            <td>
                <span class="badge-status-pill" :class="item.visibility === 'Published' ? 'status-active' : (item.visibility === 'Draft' ? 'status-pending' : 'status-info')">
                    <span class="status-dot" :class="item.visibility === 'Published' ? 'pulse' : ''"></span>
                    <span x-text="item.visibility"></span>
                </span>
            </td>
            <td class="small text-secondary" x-text="formatDateStr(item.created_at)"></td>
            <td class="text-end">
                <a :href="'/admin/management/cms/pages/' + item.id" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-edit me-1"></i>{{ __('Edit') }}
                </a>
                @php
                    $dtSupportedLocales = function_exists('supportedLocales') ? array_keys(supportedLocales()) : array_keys((array) config('app.supported_locales', ['en' => 'English', 'ro' => 'Romana']));
                    $dtLocalesSuffixPattern = implode('|', array_map('preg_quote', $dtSupportedLocales));
                    $dtDefaultLocale = (string) config('app.locale', 'en');
                @endphp
                <a :href="item.visibility === 'Published' ? (item.url ? item.url.replace(new RegExp('-(' + '{{ $dtLocalesSuffixPattern }}' + ')$'), '') : '') + '?lang=' + (item.category_language || '{{ $dtDefaultLocale }}') : '/admin/management/cms/pages/' + item.id + '/preview?lang=' + (item.category_language || '{{ $dtDefaultLocale }}')" target="_blank" class="btn btn-sm btn-outline-info ms-1">
                    <i class="fa-solid fa-eye me-1"></i><span x-text="item.visibility === 'Published' ? '{{ __('View') }}' : '{{ __('Preview') }}'"></span>
                </a>
            </td>
        </tr>
    </template>
</template>

<!-- Categories Row Template -->
<template x-if="resource === 'categories'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td class="fw-bold" x-text="item.name"></td>
            <td>
                <span class="badge bg-secondary px-2.5 py-1.5" x-text="item.language ? item.language.toUpperCase() : ''"></span>
            </td>
            <td>
                <span class="badge-status-pill" :class="item.is_active ? 'status-active' : 'status-inactive'">
                    <span class="status-dot" :class="item.is_active ? 'pulse' : ''"></span>
                    <span x-text="item.is_active ? '{{ __('Active') }}' : '{{ __('Inactive') }}'"></span>
                </span>
            </td>
            <td class="small text-secondary" x-text="formatDateStr(item.created_at)"></td>
            <td class="text-end">
                <div class="d-inline-flex gap-2">
                    <button @click="$dispatch('edit-category', item)" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-edit me-1"></i>{{ __('Edit') }}
                    </button>
                    <button @click="$dispatch('delete-category', item)" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-trash me-1"></i>{{ __('Delete') }}
                    </button>
                </div>
            </td>
        </tr>
    </template>
</template>

<!-- Media Row Template -->
<template x-if="resource === 'media'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <span class="badge bg-secondary px-2.5 py-1.5" x-text="item.type"></span>
            </td>
            <td class="fw-bold" x-text="item.title"></td>
            <td class="text-secondary" x-text="item.page_title || '-'"></td>
            <td class="text-info" x-text="item.path"></td>
            <td class="small text-secondary" x-text="formatDateStr(item.created_at)"></td>
            <td class="text-end">
                <a :href="'/storage/' + item.path" target="_blank" class="btn btn-sm btn-outline-info">
                    <i class="fa-solid fa-eye me-1"></i>{{ __('View') }}
                </a>
            </td>
        </tr>
    </template>
</template>

<!-- APIs Row Template -->
<template x-if="resource === 'apis'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid" :class="'fa-' + (item.metadata_decoded?.icon || 'puzzle-piece') + ' text-primary'"></i>
                    <span class="fw-bold" x-text="item.name"></span>
                </div>
            </td>
            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25" x-text="item.category"></span></td>
            <td class="text-secondary" x-text="item.version_number || item.version"></td>
            <td class="text-secondary" x-text="item.metadata_decoded?.author || 'System'"></td>
            <td>
                <span class="badge-status-pill" :class="item.is_active ? 'status-active' : 'status-inactive'">
                    <span class="status-dot" :class="item.is_active ? 'pulse' : ''"></span>
                    <span x-text="item.is_active ? '{{ __('Installed') }}' : '{{ __('Uninstalled') }}'"></span>
                </span>
            </td>
            <td class="text-end">
                <button @click="$dispatch('toggle-api', item)" class="btn btn-sm" :class="item.is_active ? 'btn-outline-danger' : 'btn-primary'">
                    <i class="fa-solid" :class="item.is_active ? 'fa-power-off me-1' : 'fa-play me-1'"></i> 
                    <span x-text="item.is_active ? '{{ __('Uninstall') }}' : '{{ __('Install') }}'"></span>
                </button>
            </td>
        </tr>
    </template>
</template>

<!-- Comments Row Template -->
<template x-if="resource === 'comments'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar-circle flex-shrink-0 bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.85rem;">
                        <template x-if="item.profile_image">
                            <img :src="item.profile_image" alt="Avatar" class="rounded-circle w-100 h-100 object-fit-cover">
                        </template>
                        <template x-if="!item.profile_image">
                            <span x-text="(item.author_name ? item.author_name.charAt(0) : 'G').toUpperCase()"></span>
                        </template>
                    </div>
                    <div class="overflow-hidden" style="max-width: 180px;">
                        <div class="fw-bold text-body-emphasis text-truncate" :title="item.author_name" x-text="item.author_name"></div>
                        <div class="small text-secondary text-truncate" :title="item.author_email" x-text="item.author_email"></div>
                        <div>
                            <template x-if="item.user_id">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-1.5 py-0.5 rounded" style="font-size: 0.68rem;">
                                    <i class="fa-solid fa-user-check me-0.5"></i>{{ __('Registered User') }}
                                </span>
                            </template>
                            <template x-if="!item.user_id">
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-1.5 py-0.5 rounded" style="font-size: 0.68rem;">
                                    <i class="fa-regular fa-user me-0.5"></i>{{ __('Anonymous Guest') }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>
            </td>
            <td>
                <template x-if="item.parent_id">
                    <div class="small text-muted mb-1">
                        <i class="fa-solid fa-reply fa-rotate-180 me-1"></i>{{ __('In reply to') }} #<span x-text="item.parent_id"></span>
                    </div>
                </template>
                <div class="comment-body-text text-body" style="max-height: 80px; overflow-y: auto; white-space: pre-wrap; font-size: 0.875rem;" x-text="item.comment"></div>
            </td>
            <td>
                <a :href="'/admin/management/cms/pages/' + item.content_id" class="text-decoration-none fw-semibold text-truncate d-block" :title="item.content_title" x-text="item.content_title"></a>
                <span class="badge bg-secondary-subtle text-secondary small" style="font-size: 0.72rem;" x-text="item.content_type || 'Article'"></span>
            </td>
            <td>
                <span class="badge-status-pill" :class="item.status === 'Approved' ? 'status-active' : (item.status === 'Pending' ? 'status-pending' : (item.status === 'Spam' ? 'status-danger' : 'status-rejected'))">
                    <span class="status-dot" :class="item.status === 'Approved' || item.status === 'Pending' ? 'pulse' : ''"></span>
                    <span x-text="item.status === 'Approved' ? '{{ __('Approved') }}' : (item.status === 'Pending' ? '{{ __('Pending Approval') }}' : (item.status === 'Spam' ? '{{ __('Spam') }}' : '{{ __('Rejected') }}'))"></span>
                </span>
            </td>
            <td class="small text-secondary" x-text="formatDateStr(item.created_at)"></td>
            <td class="text-end">
                <div class="d-inline-flex gap-1.5 align-items-center justify-content-end">
                    <template x-if="item.status !== 'Approved'">
                        <form :action="'/admin/management/cms/comments/' + item.id + '/status'" method="POST" class="d-inline m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i>';}">
                            <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="status" value="Approved">
                            <button type="submit" class="btn btn-sm btn-outline-success px-2 py-1 rounded-3" title="{{ __('Approve Comment') }}">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </form>
                    </template>
                    <template x-if="item.status !== 'Rejected'">
                        <form :action="'/admin/management/cms/comments/' + item.id + '/status'" method="POST" class="d-inline m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i>';}">
                            <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="status" value="Rejected">
                            <button type="submit" class="btn btn-sm btn-outline-secondary px-2 py-1 rounded-3" title="{{ __('Reject Comment') }}">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        </form>
                    </template>
                    <template x-if="item.status !== 'Spam'">
                        <form :action="'/admin/management/cms/comments/' + item.id + '/status'" method="POST" class="d-inline m-0" @submit="const b=$el.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i>';}">
                            <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="status" value="Spam">
                            <button type="submit" class="btn btn-sm btn-outline-warning px-2 py-1 rounded-3" title="{{ __('Mark Comment as Spam') }}">
                                <i class="fa-solid fa-shield-virus"></i>
                            </button>
                        </form>
                    </template>
                    <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 rounded-3" title="{{ __('Delete Comment') }}" @click="$dispatch('confirm-delete-comment', { id: item.id, comment: item.comment })">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </td>
        </tr>
    </template>
</template>

<!-- Queue Row Template -->
<template x-if="resource === 'queue'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <span class="badge" :class="item.queue === 'critical' ? 'bg-danger text-white' : (item.queue === 'low' ? 'bg-secondary text-white' : 'bg-primary text-white')" x-text="item.queue"></span>
            </td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <span class="small font-monospace text-truncate d-inline-block" style="max-width: 260px;" x-text="item.payload ? (item.payload.length > 50 ? item.payload.substring(0, 50) + '...' : item.payload) : '-'"></span>
                    <button type="button" @click="$dispatch('view-queue-payload', item)" class="btn btn-xs btn-outline-info px-1.5 py-0.5 rounded" title="{{ __('View Payload') }}">
                        <i class="fa-solid fa-code"></i>
                    </button>
                </div>
            </td>
            <td>
                <span class="badge-status-pill" :class="item.status === 'completed' ? 'status-active' : (item.status === 'processing' ? 'status-info' : (item.status === 'pending' ? 'status-pending' : (item.status === 'expired' ? 'status-inactive' : 'status-danger')))">
                    <span class="status-dot" :class="item.status === 'processing' || item.status === 'pending' ? 'pulse' : ''"></span>
                    <span x-text="item.status"></span>
                </span>
            </td>
            <td>
                <span class="small fw-semibold" x-text="item.attempts + ' / ' + (item.max_attempts || 5)"></span>
            </td>
            <td class="small" :class="new Date(item.available_at) > new Date() ? 'text-warning fw-semibold' : 'text-secondary'" x-text="item.available_at || '-'"></td>
            <td class="small text-secondary" x-text="item.created_at"></td>
            <td class="text-end">
                <div class="d-inline-flex gap-1.5 align-items-center justify-content-end">
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
            </td>
        </tr>
    </template>
</template>

<!-- Analytics Page Views Row Template -->
<template x-if="resource === 'analytics_page_views'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <div class="fw-semibold text-break" x-text="item.url_path"></div>
                <template x-if="item.content_title">
                    <span class="small text-secondary d-block mt-0.5">
                        <i class="fa-solid fa-file-invoice me-1 opacity-75"></i><span x-text="item.content_title"></span>
                    </span>
                </template>
            </td>
            <td>
                <span class="small font-monospace" x-text="item.referrer_host || '-'"></span>
            </td>
            <td>
                <span class="badge bg-secondary bg-opacity-25 text-body small" x-text="item.utm_source || '-'"></span>
            </td>
            <td>
                <span class="small text-secondary" x-text="item.utm_medium || '-'"></span>
            </td>
            <td>
                <span class="badge bg-info bg-opacity-25 text-info-emphasis small" x-text="item.utm_campaign || '-'"></span>
            </td>
            <td>
                <span class="small text-secondary" x-text="item.utm_term || '-'"></span>
            </td>
            <td>
                <span class="small text-secondary" x-text="item.utm_content || '-'"></span>
            </td>
            <td>
                <span class="badge" :class="item.device_type === 'mobile' ? 'bg-warning text-dark' : (item.device_type === 'tablet' ? 'bg-info text-dark' : 'bg-primary text-white')">
                    <i class="fa-solid me-1" :class="item.device_type === 'mobile' ? 'fa-mobile-screen' : (item.device_type === 'tablet' ? 'fa-tablet-screen-button' : 'fa-desktop')"></i>
                    <span x-text="item.device_type ? item.device_type.toUpperCase() : 'DESKTOP'"></span>
                </span>
            </td>
            <td class="small text-secondary text-nowrap" x-text="item.created_at"></td>
        </tr>
    </template>
</template>

<!-- Notification Templates Row Template -->
<template x-if="resource === 'templates'">
    <template x-for="item in items" :key="item.id">
        <tr>
            <th scope="row" x-text="item.id"></th>
            <td>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace px-2 py-1 fs-6" x-text="item.event_name"></span>
            </td>
            <td>
                <span class="badge bg-info bg-opacity-25 text-info-emphasis px-2.5 py-1 text-uppercase" x-text="item.channel"></span>
            </td>
            <td>
                <span class="badge bg-secondary bg-opacity-25 text-body px-2.5 py-1 text-uppercase fw-bold" x-text="item.language || 'en'"></span>
            </td>
            <td>
                <div class="fw-semibold text-break" x-text="item.subject"></div>
            </td>
            <td>
                <span class="badge-status-pill" :class="(item.is_active == 1 || item.is_active === true) ? 'status-active' : 'status-inactive'">
                    <span class="status-dot" :class="(item.is_active == 1 || item.is_active === true) ? 'pulse' : ''"></span>
                    <span x-text="(item.is_active == 1 || item.is_active === true) ? '{{ __('Active') }}' : '{{ __('Inactive') }}'"></span>
                </span>
            </td>
            <td class="small text-secondary text-nowrap" x-text="formatDateStr(item.updated_at || item.created_at)"></td>
            <td class="text-end text-nowrap">
                <div class="d-inline-flex gap-1">
                    <button
                        type="button"
                        @click="$dispatch('preview-template', item)"
                        class="btn btn-sm btn-outline-info"
                        title="{{ __('Preview Template') }}"
                    >
                        <i class="fa-solid fa-eye me-1"></i>{{ __('Preview') }}
                    </button>
                    <a
                        :href="'/admin/settings/templates/' + item.id + '/edit'"
                        class="btn btn-sm btn-outline-primary"
                        title="{{ __('Edit Template') }}"
                    >
                        <i class="fa-solid fa-pen-to-square me-1"></i>{{ __('Edit') }}
                    </a>
                    <button
                        type="button"
                        @click="$dispatch('delete-template', item)"
                        class="btn btn-sm btn-outline-danger"
                        title="{{ __('Delete Template') }}"
                    >
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </td>
        </tr>
    </template>
</template>

