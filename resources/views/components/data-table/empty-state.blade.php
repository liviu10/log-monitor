<template x-if="totalItems === 0">
    <tr>
        <td :colspan="getTableColspan($el)" class="text-center py-5">
            <div class="empty-state-box py-4">
                <div class="empty-state-icon mx-auto shadow-sm mb-3">
                    <i class="fa-solid fa-folder-open fs-3"></i>
                </div>
                <h6 class="fw-bold text-body-emphasis mb-1">{{ __('No records found.') }}</h6>
                <p class="text-secondary small mb-3">{{ __('Try adjusting your search query or filter options.') }}</p>
                <button type="button" @click="resetParams()" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5">
                    <i class="fa-solid fa-rotate-left me-1"></i>{{ __('Reset Filters') }}
                </button>
            </div>
        </td>
    </tr>
</template>
