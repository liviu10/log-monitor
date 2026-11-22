@php
    $locales = function_exists('supportedLocales') ? supportedLocales() : (config('app.supported_locales') ?? ['en' => 'English', 'ro' => 'Romana']);
    $currentLang = getLang();
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-custom admin-topbar-btn dropdown-toggle d-flex align-items-center gap-2 px-3 py-2 rounded-3" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        @if($currentLang === 'ro')
            <svg class="me-1 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3 2" width="16" height="11" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 2px;">
                <rect width="1" height="2" fill="#002B7F"/>
                <rect x="1" width="1" height="2" fill="#FCD116"/>
                <rect x="2" width="1" height="2" fill="#CE1126"/>
            </svg>
        @elseif($currentLang === 'en')
            <svg class="me-1 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 19 10" width="16" height="11" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 2px;">
                <rect width="19" height="10" fill="#B22234"/>
                <rect y="0.77" width="19" height="0.77" fill="#FFFFFF"/>
                <rect y="2.31" width="19" height="0.77" fill="#FFFFFF"/>
                <rect y="3.85" width="19" height="0.77" fill="#FFFFFF"/>
                <rect y="5.38" width="19" height="0.77" fill="#FFFFFF"/>
                <rect y="6.92" width="19" height="0.77" fill="#FFFFFF"/>
                <rect y="8.46" width="19" height="0.77" fill="#FFFFFF"/>
                <rect width="7.6" height="5.38" fill="#3C3B6E"/>
                <circle cx="1.5" cy="1.1" r="0.2" fill="#FFFFFF"/>
                <circle cx="3.0" cy="1.1" r="0.2" fill="#FFFFFF"/>
                <circle cx="4.5" cy="1.1" r="0.2" fill="#FFFFFF"/>
                <circle cx="6.0" cy="1.1" r="0.2" fill="#FFFFFF"/>
                <circle cx="2.2" cy="2.2" r="0.2" fill="#FFFFFF"/>
                <circle cx="3.7" cy="2.2" r="0.2" fill="#FFFFFF"/>
                <circle cx="5.2" cy="2.2" r="0.2" fill="#FFFFFF"/>
                <circle cx="1.5" cy="3.3" r="0.2" fill="#FFFFFF"/>
                <circle cx="3.0" cy="3.3" r="0.2" fill="#FFFFFF"/>
                <circle cx="4.5" cy="3.3" r="0.2" fill="#FFFFFF"/>
                <circle cx="6.0" cy="3.3" r="0.2" fill="#FFFFFF"/>
                <circle cx="2.2" cy="4.4" r="0.2" fill="#FFFFFF"/>
                <circle cx="3.7" cy="4.4" r="0.2" fill="#FFFFFF"/>
                <circle cx="5.2" cy="4.4" r="0.2" fill="#FFFFFF"/>
            </svg>
        @else
            <i class="fa-solid fa-globe me-1 text-secondary"></i>
        @endif
        <span class="fw-medium small">{{ strtoupper($currentLang) }}</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="langDropdown">
        @foreach($locales as $val => $name)
            @php
                $legacyKey = ($val === 'ro' ? 'RO - Romanian' : ($val === 'en' ? 'EN - English' : ''));
                $displayLabel = ($legacyKey !== '' && __($legacyKey) !== $legacyKey) ? __($legacyKey) : (strtoupper((string)$val) . ' - ' . __((string)$name));
            @endphp
            <li>
                <a class="dropdown-item d-flex align-items-center py-2 px-3" href="/change-lang?lang={{ $val }}" @click.prevent="localStorage.setItem('app_language', '{{ $val }}'); window.location.href = '/change-lang?lang={{ $val }}'">
                    @if($val === 'ro')
                        <svg class="me-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3 2" width="18" height="12" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 2px;">
                            <rect width="1" height="2" fill="#002B7F"/>
                            <rect x="1" width="1" height="2" fill="#FCD116"/>
                            <rect x="2" width="1" height="2" fill="#CE1126"/>
                        </svg>
                    @elseif($val === 'en')
                        <svg class="me-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 19 10" width="18" height="12" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 2px;">
                            <rect width="19" height="10" fill="#B22234"/>
                            <rect y="0.77" width="19" height="0.77" fill="#FFFFFF"/>
                            <rect y="2.31" width="19" height="0.77" fill="#FFFFFF"/>
                            <rect y="3.85" width="19" height="0.77" fill="#FFFFFF"/>
                            <rect y="5.38" width="19" height="0.77" fill="#FFFFFF"/>
                            <rect y="6.92" width="19" height="0.77" fill="#FFFFFF"/>
                            <rect y="8.46" width="19" height="0.77" fill="#FFFFFF"/>
                            <rect width="7.6" height="5.38" fill="#3C3B6E"/>
                            <circle cx="1.5" cy="1.1" r="0.2" fill="#FFFFFF"/>
                            <circle cx="3.0" cy="1.1" r="0.2" fill="#FFFFFF"/>
                            <circle cx="4.5" cy="1.1" r="0.2" fill="#FFFFFF"/>
                            <circle cx="6.0" cy="1.1" r="0.2" fill="#FFFFFF"/>
                            <circle cx="2.2" cy="2.2" r="0.2" fill="#FFFFFF"/>
                            <circle cx="3.7" cy="2.2" r="0.2" fill="#FFFFFF"/>
                            <circle cx="5.2" cy="2.2" r="0.2" fill="#FFFFFF"/>
                            <circle cx="1.5" cy="3.3" r="0.2" fill="#FFFFFF"/>
                            <circle cx="3.0" cy="3.3" r="0.2" fill="#FFFFFF"/>
                            <circle cx="4.5" cy="3.3" r="0.2" fill="#FFFFFF"/>
                            <circle cx="6.0" cy="3.3" r="0.2" fill="#FFFFFF"/>
                            <circle cx="2.2" cy="4.4" r="0.2" fill="#FFFFFF"/>
                            <circle cx="3.7" cy="4.4" r="0.2" fill="#FFFFFF"/>
                            <circle cx="5.2" cy="4.4" r="0.2" fill="#FFFFFF"/>
                        </svg>
                    @else
                        <i class="fa-solid fa-globe me-3 text-secondary"></i>
                    @endif
                    <span>{{ $displayLabel }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
