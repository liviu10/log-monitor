@extends('layouts.admin')

@section('title', __('Profile'))

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Profile'));
@endphp

@include('components.page-header', [
    'title' => __('Profile'),
    'subtitle' => __('Manage your account details')
])

<div class="row justify-content-center">
    <div class="col-12">
        <div class="card card-custom p-4">
            <div class="card-body">
                <form action="/admin/settings/users/profile" method="POST" enctype="multipart/form-data" x-data="{ isSaving: false, formData: { @foreach($form as $field) '{{ $field['key'] }}': '{{ $field['value'] }}', @endforeach } }" @submit="isSaving = true">
                    {!! csrf_field() !!}
                    
                    @foreach($form as $field)
                        @if($field['type'] === 'hidden')
                            @if($field['key'] === 'bd')
                                <div style="display: none;">
                                    <input type="text" name="bd" x-model="formData.bd">
                                </div>
                            @else
                                <input type="hidden" name="{{ $field['key'] }}" :value="formData.{{ $field['key'] }}" x-model="formData.{{ $field['key'] }}">
                            @endif
                        @else
                            <div class="mb-3">
                                <label for="{{ $field['key'] }}" class="form-label text-secondary small fw-medium">
                                    {{ __($field['label']) }}
                                    @if($field['key'] !== 'email')
                                        <span class="text-danger">*</span>
                                    @endif
                                </label>
                                <input type="{{ $field['type'] }}" name="{{ $field['key'] }}" id="{{ $field['key'] }}" class="form-control @if(isset($errors[$field['key']])) is-invalid @endif" x-model="formData.{{ $field['key'] }}" @if($field['key'] === 'email') disabled @else required @endif>
                                @if(isset($errors[$field['key']]))
                                    <div class="invalid-feedback d-block">{{ $errors[$field['key']][0] }}</div>
                                @endif
                                @if($field['key'] === 'email')
                                    <div class="form-text small text-secondary mt-1">{{ __('Email address cannot be changed directly for security reasons.') }}</div>
                                @endif
                            </div>
                        @endif
                    @endforeach

                    <div class="mb-3">
                        <label for="current_password" class="form-label text-secondary small fw-medium">{{ __('Current Password') }}</label>
                        @include('components.password-input', [
                            'name' => 'current_password',
                            'id' => 'current_password',
                            'placeholder' => __('Required only to change the password'),
                            'required' => false,
                        ])
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label text-secondary small fw-medium">{{ __('New Password') }}</label>
                        @include('components.password-input', [
                            'name' => 'password',
                            'id' => 'password',
                            'placeholder' => __('Enter new password'),
                            'required' => false,
                        ])
                        <small class="text-secondary d-block mt-1">{{ __('Leave blank to keep your current password') }}</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label text-secondary small fw-medium">{{ __('Confirm Password') }}</label>
                        @include('components.password-input', [
                            'name' => 'password_confirmation',
                            'id' => 'password_confirmation',
                            'placeholder' => __('Confirm new password'),
                            'required' => false,
                        ])
                    </div>
                    
                    <div class="mb-4">
                        <label for="profile_image" class="form-label text-secondary small fw-medium">{{ __('Profile Image') }}</label>
                        @if(!empty($user['profile_image']))
                            <div class="mb-2">
                                <img src="/storage/{{ $user['profile_image'] }}" alt="Profile Image" class="rounded-circle border border-secondary" style="width: 80px; height: 80px; object-fit: cover;">
                            </div>
                        @endif
                        <input type="file" name="profile_image" id="profile_image" class="form-control" accept="image/*">
                        <div class="form-text small text-secondary mt-1">{{ __('Upload a square avatar image (JPG, PNG, WebP).') }}</div>
                    </div>

                    <div class="row mb-4 bg-body-tertiary border border-secondary border-opacity-10 p-3 rounded-4 mx-0 align-items-center">
                        <div class="col-6 text-secondary small d-flex align-items-center gap-2">
                            <strong>{{ __('Role') }}:</strong>
                            <span class="badge bg-secondary px-3 py-1.5 rounded-pill">{{ $user['role_name'] }}</span>
                        </div>
                        <div class="col-6 text-secondary small d-flex align-items-center gap-2">
                            <strong>{{ __('Status') }}:</strong>
                            @include('components.status-badge', ['status' => $user['status']])
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-primary-custom px-5 py-2.5 rounded-pill fw-semibold shadow-sm" :disabled="isSaving">
                            <span x-show="!isSaving"><i class="fa-solid fa-floppy-disk me-2"></i>{{ __('Save Changes') }}</span>
                            <span x-show="isSaving"><i class="fa-solid fa-spinner fa-spin me-2"></i>{{ __('Saving...') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
