@extends('layouts.guest')

@section('title', $title ?? __('Application Error'))

@section('content')
<div class="row justify-content-center align-items-center" style="min-height: 65vh;">
    <div class="col-12">
        <div class="card card-custom p-4 p-sm-5 text-center shadow-lg border border-secondary border-opacity-25 rounded-4">
            <div class="card-body">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger mb-3" style="width: 72px; height: 72px;">
                    <i class="fa-solid fa-triangle-exclamation fs-1"></i>
                </div>
                <div class="display-4 fw-extrabold text-info font-monospace mb-2">{{ $code ?? 500 }}</div>
                <h3 class="fw-bold mb-2">{{ $title ?? __('Application Error') }}</h3>
                <p class="text-secondary small mb-4">{{ $message ?? __('An unexpected error occurred.') }}</p>

                <div class="d-flex justify-content-center gap-2 flex-wrap">
                    @if(getCurrentAuthUser())
                        <a href="/admin" class="btn btn-primary-custom rounded-pill px-4 fw-semibold">
                            <i class="fa-solid fa-gauge-high me-2"></i>{{ __('Back to Dashboard') }}
                        </a>
                    @else
                        <a href="/login" class="btn btn-primary-custom rounded-pill px-4 fw-semibold">
                            <i class="fa-solid fa-right-to-bracket me-2"></i>{{ __('Sign In') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
