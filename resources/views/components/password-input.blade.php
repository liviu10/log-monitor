@php
    $inputName = $name ?? 'password';
    $inputId = $id ?? $inputName;
    $inputLabel = $label ?? __('Password');
    $isRequired = $required ?? true;
    $autocompleteVal = $autocomplete ?? 'current-password';
    $modelAttr = $model ?? null;
    $hasError = isset($error) && !empty($error);
@endphp

<div x-data="{ show: false, toggle() { this.show = !this.show; } }">
    @if(!empty($inputLabel))
        <label for="{{ $inputId }}" class="form-label text-secondary small fw-medium mb-1">
            {{ $inputLabel }}
            @if($isRequired)
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif
    <div class="input-group">
        <span class="input-group-text border-secondary border-opacity-25 text-secondary bg-body-tertiary">
            <i class="fa-solid fa-key"></i>
        </span>
        <input :type="show ? 'text' : 'password'" 
               name="{{ $inputName }}" 
               id="{{ $inputId }}" 
               class="form-control border-end-0 @if($hasError) is-invalid @endif" 
               @if($modelAttr) x-model="{{ $modelAttr }}" @endif
               @if(isset($value)) value="{{ $value }}" @endif
               @if(isset($placeholder)) placeholder="{{ $placeholder }}" @endif
               autocomplete="{{ $autocompleteVal }}"
               @if($isRequired) required @endif>
        <button type="button" 
                class="input-group-text border-secondary border-opacity-25 text-secondary bg-body-tertiary cursor-pointer hover-text-primary" 
                @click="toggle()" 
                tabindex="-1"
                aria-label="{{ __('Toggle password visibility') }}">
            <i class="fa-solid" :class="show ? 'fa-eye-slash text-info' : 'fa-eye'"></i>
        </button>
    </div>
    @if($hasError)
        <div class="invalid-feedback d-block small mt-1">{{ is_array($error) ? $error[0] : $error }}</div>
    @endif
    @if(isset($helpText) && !empty($helpText))
        <div class="form-text small text-secondary mt-1">{{ __($helpText) }}</div>
    @endif
</div>
