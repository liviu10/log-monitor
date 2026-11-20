@foreach($fields as $field)
    @php
        $isError = isset($errors[$field['key']]);
        $val = $old[$field['key']] ?? $field['value'] ?? '';
        $fieldId = !empty($xModelPrefix ?? '') ? str_replace('.', '_', (string) $xModelPrefix) . $field['key'] : $field['key'];
        $isRequired = $field['required'] ?? false;
    @endphp

    <div class="mb-3" @if($field['type'] === 'hidden') style="display: none;" @endif>
        @if($field['type'] !== 'hidden')
            <label for="{{ $fieldId }}" class="form-label text-secondary small fw-medium">
                {{ __($field['label']) }}
                @if($isRequired)
                    <span class="text-danger">*</span>
                @endif
            </label>
        @endif

        @if($field['type'] === 'select')
            <select name="{{ $field['key'] }}" id="{{ $fieldId }}" 
                    class="form-select @if($isError) is-invalid @endif" 
                    @if($field['required'] ?? false) required @endif
                    @if(isset($xModelPrefix) && !empty($xModelPrefix)) x-model="{{ $xModelPrefix }}{{ $field['key'] }}" @endif>
                @if(isset($field['options']) && is_array($field['options']))
                    @foreach($field['options'] as $opt)
                        <option value="{{ $opt['value'] }}" @if($val == $opt['value']) selected @endif>{{ __($opt['label']) }}</option>
                    @endforeach
                @endif
            </select>

        @elseif($field['type'] === 'textarea')
            <textarea name="{{ $field['key'] }}" id="{{ $fieldId }}" rows="{{ $field['rows'] ?? ($rows ?? 8) }}" 
                      class="form-control @if($isError) is-invalid @endif" 
                      @if($field['required'] ?? false) required @endif
                      @if($field['key'] === 'description') maxlength="180" @endif
                      @if(in_array($field['key'], ['tags', 'description'], true)) style="resize: none; height: 72px;" @endif
                      @if(isset($xModelPrefix) && !empty($xModelPrefix)) x-model="{{ $xModelPrefix }}{{ $field['key'] }}" @endif>{{ $val }}</textarea>

        @elseif($field['type'] === 'checkbox')
            <div class="form-check form-switch fs-6 mt-3 mb-3">
                <input class="form-check-input border-secondary" type="checkbox" name="{{ $field['key'] }}" id="{{ $fieldId }}" value="1" @if($val) checked @endif
                       @if(isset($xModelPrefix) && !empty($xModelPrefix)) x-model="{{ $xModelPrefix }}{{ $field['key'] }}" @endif>
                <label class="form-check-label text-secondary" for="{{ $fieldId }}">{{ __($field['label']) }}</label>
            </div>

        @elseif($field['type'] === 'file')
            <input type="file" name="{{ $field['key'] }}{{ ($field['multiple'] ?? false) ? '[]' : '' }}" id="{{ $fieldId }}" 
                   class="form-control" @if($field['multiple'] ?? false) multiple @endif>

        @elseif($field['type'] === 'hidden')
            <input type="hidden" name="{{ $field['key'] }}" id="{{ $fieldId }}" value="{{ $val }}"
                   @if(isset($xModelPrefix) && !empty($xModelPrefix)) x-model="{{ $xModelPrefix }}{{ $field['key'] }}" @endif>

        @elseif($field['type'] === 'password')
            <input type="password" name="{{ $field['key'] }}" id="{{ $fieldId }}" 
                   class="form-control @if($isError) is-invalid @endif" 
                   @if($field['required'] ?? false) required @endif
                   @if(isset($xModelPrefix) && !empty($xModelPrefix)) x-model="{{ $xModelPrefix }}{{ $field['key'] }}" @endif>

        @else
            <input type="{{ $field['type'] }}" name="{{ $field['key'] }}" id="{{ $fieldId }}" 
                   class="form-control @if($isError) is-invalid @endif" 
                   value="{{ $val }}" @if($field['required'] ?? false) required @endif
                   @if($field['readonly'] ?? false) readonly disabled @endif
                   @if(isset($xModelPrefix) && !empty($xModelPrefix)) x-model="{{ $xModelPrefix }}{{ $field['key'] }}" @endif>
        @endif

        @if($field['key'] === 'description')
            <div class="d-flex justify-content-between align-items-center mt-1">
                @if(isset($field['help_text']) && !empty($field['help_text']))
                    <small class="text-secondary">{!! __($field['help_text']) !!}</small>
                @else
                    <small class="text-secondary">{{ __('Brief summary for SEO and listing previews (max 180 characters).') }}</small>
                @endif
                <small id="char-count-{{ $field['key'] }}" class="text-secondary font-monospace"><span id="char-num-{{ $field['key'] }}">0</span> / 180</small>
            </div>
        @elseif(isset($field['help_text']) && !empty($field['help_text']))
            <small class="text-secondary d-block mt-1">{!! __($field['help_text']) !!}</small>
        @elseif($field['key'] === 'slug')
            <small class="text-secondary d-block mt-1">{{ __('Unique URL-friendly slug identifier.') }}</small>
        @elseif($field['key'] === 'tags')
            <small class="text-secondary d-block mt-1">{{ __('Comma-separated tags for categorizing content.') }}</small>
        @elseif($field['key'] === 'email')
            <small class="text-secondary d-block mt-1">{{ __('Valid email address used for login and notifications.') }}</small>
        @elseif($field['key'] === 'password')
            <small class="text-secondary d-block mt-1">{{ __('Strong password for account security (minimum 8 characters).') }}</small>
        @endif

        @if(isset($xModelPrefix) && !empty($xModelPrefix))
            @php 
                $errorsObject = str_contains($xModelPrefix, 'create') ? 'createErrors' : 'editErrors';
            @endphp
            <template x-if="{{ $errorsObject }} && {{ $errorsObject }}.{{ $field['key'] }}">
                <div class="invalid-feedback d-block" x-text="{{ $errorsObject }}.{{ $field['key'] }}[0]"></div>
            </template>
        @else
            @if($isError)
                <div class="invalid-feedback d-block">{{ $errors[$field['key']][0] }}</div>
            @endif
        @endif
    </div>
@endforeach
