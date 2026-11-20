@extends('layouts.admin')

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Settings'), '/admin/settings')
        ->add(__('Users'));
@endphp

<div x-data="usersManagement()" @edit-user="openEdit($event.detail)">
@include('components.page-header', [
    'title' => __('Users Management'),
    'actionButton' => '<button @click="openCreateModal = true" class="btn btn-primary rounded-pill px-4 py-2 shadow-sm d-inline-flex align-items-center gap-2 fw-semibold"><i class="fa-solid fa-user-plus"></i><span>' . __('Create User') . '</span></button>'
])

<div class="row">
    <!-- Users -->
    <div class="col-12">
        @include('components.data-table', [
            'resource' => 'users',
            'items' => $users,
            'roles' => $roles,
            'pagination' => $pagination,
            'currentUserId' => getCurrentAuthUser()['id'] ?? null
        ])
    </div>

    <!-- Create User Modal -->
    @component('components.modal', [
        'showVariable' => 'openCreateModal',
        'title' => __('Create User'),
        'icon' => 'fa-user-plus',
        'action' => __('Save'),
        'loadingVariable' => 'isSavingCreate',
        'onSubmit' => 'submitCreate()'
    ])
        @include('components.form-fields', ['fields' => $createFields, 'xModelPrefix' => 'createUser.'])
    @endcomponent

    <!-- Edit User Modal -->
    @component('components.modal', [
        'showVariable' => 'openEditModal',
        'title' => __('Edit User'),
        'icon' => 'fa-user-pen',
        'action' => __('Save'),
        'loadingVariable' => 'isSavingEdit',
        'onSubmit' => 'submitEdit()'
    ])
        @include('components.form-fields', ['fields' => $editFields, 'xModelPrefix' => 'editUser.'])
    @endcomponent
</div>
</div>

<script nonce="{{ APP_NONCE }}">
    function usersManagement() {
        return {
            openCreateModal: false,
            openEditModal: false,
            isSavingCreate: false,
            isSavingEdit: false,
            createErrors: {},
            editErrors: {},
            createUser: {
                first_name: '',
                last_name: '',
                email: ''
            },
            editUser: {
                id: '',
                name: '',
                role_id: '',
                status: 'ACTIVE'
            },
            submitCreate() {
                this.isSavingCreate = true;
                this.createErrors = {};
                let formData = new FormData();
                Object.keys(this.createUser).forEach(key => {
                    formData.append(key, this.createUser[key]);
                });
                formData.append('csrf_token', '{{ csrf_token() }}');

                fetch('/admin/settings/users', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(res => res.json().then(data => ({ status: res.status, data })))
                .then(obj => {
                    this.isSavingCreate = false;
                    if (obj.status === 422) {
                        this.createErrors = obj.data.errors || {};
                    } else if (obj.status === 200 && obj.data.success) {
                        window.location.reload();
                    }
                })
                .catch(err => {
                    this.isSavingCreate = false;
                    console.error(err);
                });
            },
            openEdit(data) {
                this.editUser.id = data.userId || data.id;
                this.editUser.name = data.userFirstName && data.userLastName 
                    ? data.userFirstName + ' ' + data.userLastName 
                    : data.first_name + ' ' + data.last_name;
                this.editUser.role_id = data.userRoleId || data.role_id;
                this.editUser.status = data.raw_status || (data.status ? data.status.toUpperCase() : 'ACTIVE');
                this.editErrors = {};
                this.openEditModal = true;
            },
            submitEdit() {
                this.isSavingEdit = true;
                this.editErrors = {};
                let formData = new FormData();
                formData.append('role_id', this.editUser.role_id);
                if (this.editUser.status) {
                    formData.append('status', this.editUser.status);
                }
                formData.append('csrf_token', '{{ csrf_token() }}');
                formData.append('_method', 'PUT');

                fetch('/admin/settings/users/' + this.editUser.id, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(res => res.json().then(data => ({ status: res.status, data })))
                .then(obj => {
                    this.isSavingEdit = false;
                    if (obj.status === 422) {
                        this.editErrors = obj.data.errors || {};
                    } else if (obj.status === 200 && obj.data.success) {
                        window.location.reload();
                    }
                })
                .catch(err => {
                    this.isSavingEdit = false;
                    console.error(err);
                });
            }
        };
    }
</script>
@endsection
