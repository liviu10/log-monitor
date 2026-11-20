@php
    $appName = config('app.name') ?? 'LogMonitor';
    $pageTitle = isset($title) && !empty($title) ? $title . ' - ' . $appName : $appName;
    $langFile = dirname(__DIR__, 3) . '/lang/' . getLang() . '.json';
    $jsTranslations = [];
    if (is_file($langFile)) {
        $rawLang = @file_get_contents($langFile);
        if (is_string($rawLang)) {
            $decodedLang = json_decode($rawLang, true);
            if (is_array($decodedLang)) {
                $jsTranslations = $decodedLang;
            }
        }
    }
@endphp
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $pageTitle }}</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preload" href="/assets/vendor/fonts/inter/files/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/vendor/fonts/inter/files/inter-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
<style nonce="{{ APP_NONCE }}">
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:300;src:url('/assets/vendor/fonts/inter/files/inter-latin-300-normal.woff2') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:300;src:url('/assets/vendor/fonts/inter/files/inter-latin-ext-300-normal.woff2') format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:400;src:url('/assets/vendor/fonts/inter/files/inter-latin-400-normal.woff2') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:400;src:url('/assets/vendor/fonts/inter/files/inter-latin-ext-400-normal.woff2') format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:500;src:url('/assets/vendor/fonts/inter/files/inter-latin-500-normal.woff2') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:500;src:url('/assets/vendor/fonts/inter/files/inter-latin-ext-500-normal.woff2') format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:600;src:url('/assets/vendor/fonts/inter/files/inter-latin-600-normal.woff2') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:600;src:url('/assets/vendor/fonts/inter/files/inter-latin-ext-600-normal.woff2') format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:700;src:url('/assets/vendor/fonts/inter/files/inter-latin-700-normal.woff2') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-style:normal;font-display:swap;font-weight:700;src:url('/assets/vendor/fonts/inter/files/inter-latin-ext-700-normal.woff2') format('woff2');unicode-range:U+0100-02AF,U+0304,U+0308,U+0329,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
</style>
<link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
<link href="/assets/app.css" rel="stylesheet">
<script nonce="{{ APP_NONCE }}">
    (function() {
        const theme = localStorage.getItem('app_theme') || 'dark';
        document.documentElement.setAttribute('data-bs-theme', theme);
        window.__translations = {!! json_encode($jsTranslations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        window.__ = function(key) {
            return (window.__translations && window.__translations[key]) ? window.__translations[key] : key;
        };
    })();
</script>
