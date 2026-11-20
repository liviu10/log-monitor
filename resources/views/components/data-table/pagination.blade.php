<!-- Pagination Bar -->
<div class="d-flex flex-wrap align-items-center justify-content-between mt-4 gap-3 pt-3 border-top border-secondary border-opacity-25 text-secondary small">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span>{{ __('Showing') }} <strong class="text-body-emphasis" x-text="totalItems === 0 ? 0 : (currentPage - 1) * perPage + 1"></strong> {{ __('to') }} <strong class="text-body-emphasis" x-text="Math.min(totalItems, currentPage * perPage)"></strong> {{ __('of') }} <strong class="text-body-emphasis" x-text="totalItems"></strong> {{ __('records') }}</span>
        <span class="mx-1 d-none d-sm-inline">|</span>
        <span>{{ __('Page') }} <strong class="text-body-emphasis" x-text="currentPage"></strong> {{ __('of') }} <strong class="text-body-emphasis" x-text="totalPages"></strong></span>
    </div>

    <nav aria-label="Table navigation">
        <ul class="pagination pagination-sm m-0 gap-1 bg-transparent">
            <!-- First -->
            <li class="page-item" :class="currentPage === 1 ? 'disabled' : ''">
                <button class="page-link page-link-custom" @click="setPage(1); updateUrl()" :disabled="currentPage === 1" aria-label="First">
                    <i class="fa-solid fa-angles-left"></i>
                </button>
            </li>
            <!-- Previous -->
            <li class="page-item" :class="currentPage === 1 ? 'disabled' : ''">
                <button class="page-link page-link-custom" @click="setPage(currentPage - 1); updateUrl()" :disabled="currentPage === 1" aria-label="Previous">
                    <i class="fa-solid fa-angle-left"></i>
                </button>
            </li>

            <!-- Page Numbers -->
            <template x-for="p in getPages()" :key="p">
                <li class="page-item" :class="currentPage === p ? 'active' : ''">
                    <button class="page-link page-link-custom" x-text="p" @click="setPage(p); updateUrl()"></button>
                </li>
            </template>

            <!-- Next -->
            <li class="page-item" :class="currentPage === totalPages ? 'disabled' : ''">
                <button class="page-link page-link-custom" @click="setPage(currentPage + 1); updateUrl()" :disabled="currentPage === totalPages" aria-label="Next">
                    <i class="fa-solid fa-angle-right"></i>
                </button>
            </li>
            <!-- Last -->
            <li class="page-item" :class="currentPage === totalPages ? 'disabled' : ''">
                <button class="page-link page-link-custom" @click="setPage(totalPages); updateUrl()" :disabled="currentPage === totalPages" aria-label="Last">
                    <i class="fa-solid fa-angles-right"></i>
                </button>
            </li>
        </ul>
    </nav>
</div>
