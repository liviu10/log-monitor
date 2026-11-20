@extends('layouts.guest')

@section('content')
<div class="row justify-content-center align-items-center" style="min-height: 60vh;">
    <div class="col-12">
        <div class="card card-custom p-4">
            <div class="card-body">
                <div class="text-center mb-4">
                    <i class="fa-solid fa-user-check text-success fs-1 mb-2"></i>
                    <h3 class="card-title fw-bold">{{ __('Confirm Email Address') }}</h3>
                    <p class="text-secondary small">{{ __('Please enter your email address to validate the token and complete account verification.') }}</p>
                </div>

                <form action="/verify-email" method="POST">
                    {!! csrf_field() !!}
                    <input type="hidden" name="token" value="{{ $token ?? '' }}">

                    <div class="mb-4">
                        <label for="email" class="form-label text-secondary small fw-medium">{{ __('Email') }} <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="email" class="form-control bg-transparent border-secondary @if(isset($errors['email'])) is-invalid @endif" required placeholder="{{ __('name@example.com') }}">
                        @if(isset($errors['email']))
                            <div class="invalid-feedback d-block">{{ $errors['email'][0] }}</div>
                        @endif
                        <div class="form-text small text-secondary mt-1">{{ __('Enter the email address you used to register your account.') }}</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-primary-custom w-100 py-2.5 fw-bold">
                        <i class="fa-solid fa-circle-check me-2"></i>{{ __('Confirm Email') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

