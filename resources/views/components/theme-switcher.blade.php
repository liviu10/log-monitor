<button @click="let t = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'; document.documentElement.setAttribute('data-bs-theme', t); localStorage.setItem('app_theme', t); currentTheme = t"
        class="btn btn-sm btn-outline-custom d-flex align-items-center justify-content-center"
        style="width: 32px; height: 32px; border-radius: 50%; padding: 0;"
        title="{{ __('Toggle Theme') }}">
    <i class="fa-solid" :class="currentTheme === 'dark' ? 'fa-sun text-warning' : 'fa-moon text-primary'"></i>
</button>
