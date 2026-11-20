@php
    $searchModel = $model ?? 'searchQuery';
    $searchPlaceholder = $placeholder ?? __('Search...');
    $inputAction = $onInput ?? 'currentPage = 1; updateUrl()';
    $clearAction = $onClear ?? $searchModel . ' = \'\'; currentPage = 1; updateUrl()';
@endphp

<div class="position-relative">
    <input type="text" 
           x-model="{{ $searchModel }}" 
           @if(!empty($inputAction)) @input.debounce.400ms="{{ $inputAction }}" @endif
           x-init="if (sessionStorage.getItem('restoreSearchFocus') === 'true' || {{ $searchModel }} !== '') { sessionStorage.removeItem('restoreSearchFocus'); $nextTick(() => { $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length); }) }" 
           class="form-control ps-5 pe-5 rounded-3" 
           placeholder="{{ $searchPlaceholder }}"
           aria-label="{{ $searchPlaceholder }}">
    <i class="fa-solid fa-magnifying-glass position-absolute start-0 top-50 translate-middle-y ms-3 text-secondary opacity-75"></i>
    <button type="button" 
            x-show="{{ $searchModel }} !== ''" 
            x-cloak
            @click="{{ $clearAction }}" 
            class="btn btn-sm btn-link position-absolute end-0 top-50 translate-middle-y me-2 text-secondary p-0 border-0 text-decoration-none" 
            style="line-height: 1;"
            aria-label="{{ __('Clear search') }}"
            title="{{ __('Clear search') }}">
        <i class="fa-solid fa-circle-xmark fs-6"></i>
    </button>
</div>
