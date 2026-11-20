<div class="row mb-4">
    <div class="col-12">
        <div class="text-start mb-2">
            {!! breadcrumbs() !!}
        </div>
        
        <div class="d-flex align-items-center justify-content-center position-relative py-2">
            @if(isset($backUrl) && !empty($backUrl))
                <a href="{{ $backUrl }}" class="btn btn-outline-secondary p-2 rounded-circle d-flex align-items-center justify-content-center position-absolute start-0 top-50 translate-middle-y flex-shrink-0 shadow-sm" style="width: 44px; height: 44px; z-index: 5;" aria-label="{{ __('Back') }}">
                    <i class="fa-solid fa-arrow-left fs-5"></i>
                </a>
            @endif

            <div class="text-center w-100 @if((isset($backUrl) && !empty($backUrl)) || (isset($actionButton) && !empty($actionButton))) px-5 @endif" style="word-break: break-word;">
                <h2 class="fw-bold mb-1 fs-3 fs-md-2">{{ $title }}</h2>
                @if(isset($subtitle) && !empty($subtitle))
                    <p class="text-secondary mb-0 small small-md-regular">{!! $subtitle !!}</p>
                @endif
            </div>

            @if(isset($actionButton) && !empty($actionButton))
                <div class="position-absolute end-0 top-50 translate-middle-y d-none d-md-block" style="z-index: 5;">
                    {!! $actionButton !!}
                </div>
            @endif
        </div>

        @if(isset($actionButton) && !empty($actionButton))
            <div class="text-center d-block d-md-none mt-3">
                {!! $actionButton !!}
            </div>
        @endif
    </div>
</div>
