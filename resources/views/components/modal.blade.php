<div class="modal fade shadow-lg" :class="{{ $showVariable }} ? 'show d-block' : 'd-none'" style="background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);" tabindex="-1" role="dialog" x-cloak>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-custom p-3 rounded-4 shadow-lg">
            <div class="modal-header border-bottom border-secondary border-opacity-10 pb-3">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid {{ $icon ?? 'fa-circle-info' }} text-primary me-2"></i>{{ $title }}
                </h5>
                <button type="button" class="btn-close" @click="{{ $showVariable }} = false" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form @submit.prevent="{{ $onSubmit ?? '' }}">
                    {!! $slot !!}

                    <div class="d-flex gap-2 mt-4 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" @click="{{ $showVariable }} = false">
                            {{ __('Cancel') }}
                        </button>
                        @php
                            $loadingVar = $loadingVariable ?? null;
                            $loadingTxt = $loadingText ?? __('Saving...');
                        @endphp
                        <button type="submit" class="btn {{ $btnClass ?? 'btn-primary-custom' }} rounded-pill px-4 shadow-sm fw-semibold"
                            @if($loadingVar) :disabled="{{ $loadingVar }}" @endif>
                            @if($loadingVar)
                                <span x-show="!{{ $loadingVar }}">{{ $action ?? __('Save') }}</span>
                                <span x-show="{{ $loadingVar }}"><i class="fa-solid fa-spinner fa-spin me-2"></i>{{ $loadingTxt }}</span>
                            @else
                                {{ $action ?? __('Save') }}
                            @endif
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
