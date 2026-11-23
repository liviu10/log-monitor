@extends('layouts.guest')

@section('title', __('Login'))

@section('content')
<div class="row justify-content-center align-items-center" style="min-height: 70vh;">
    <div class="col-12" x-data="quickFill()" x-init="setTimeout(() => { if (document.getElementById('email')) document.getElementById('email').focus(); }, 100)">
        <div class="card card-custom p-4 shadow-lg border border-secondary border-opacity-25 rounded-4">
            <div class="card-body p-2 p-sm-4">
                <div class="text-center mb-4">
                    <div class="mb-3 d-flex justify-content-center">
                        @include('components.brand-logo', ['iconSize' => 42, 'fontSize' => '1.45rem'])
                    </div>
                    <h4 class="fw-bold mb-1">{{ __('Welcome Back') }}</h4>
                    <p class="text-secondary small mb-0">{{ __('Sign in to access centralized telemetry and system logs') }}</p>
                </div>

                <form action="/login" method="POST">
                    {!! csrf_field() !!}

                    <div class="mb-3">
                        <label for="email" class="form-label text-secondary small fw-medium mb-1">
                            {{ __('Email') }} <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text border-secondary border-opacity-25 text-secondary bg-body-tertiary">
                                <i class="fa-solid fa-envelope"></i>
                            </span>
                            <input type="email"
                                   name="email"
                                   id="email"
                                   value="{{ htmlspecialchars((string) ($oldEmail ?? ''), ENT_QUOTES, 'UTF-8') }}"
                                   class="form-control @if(isset($errors['email'])) is-invalid @endif"
                                   placeholder="admin@logmonitor.local"
                                   autocomplete="email"
                                   required>
                        </div>
                        @if(isset($errors['email']))
                            <div class="invalid-feedback d-block small mt-1">{{ is_array($errors['email']) ? $errors['email'][0] : $errors['email'] }}</div>
                        @endif
                    </div>

                    <div class="mb-4">
                        @include('components.password-input', [
                            'name' => 'password',
                            'id' => 'password',
                            'label' => __('Password'),
                            'required' => true,
                            'autocomplete' => 'current-password',
                            'placeholder' => '••••••••',
                            'error' => $errors['password'] ?? null,
                        ])
                    </div>

                    <button type="submit" class="btn btn-primary btn-primary-custom w-100 py-2.5 fw-bold rounded-3 shadow-sm">
                        <i class="fa-solid fa-right-to-bracket me-2"></i>{{ __('Sign In') }}
                    </button>
                </form>

                @include('components.quick-fill')
            </div>
        </div>
    </div>
</div>
@endsection

