@if (!empty($activeFilters))
    <div class="mb-3 text-secondary small px-1 d-flex flex-wrap align-items-center gap-2">
        <i class="fa-solid fa-filter text-primary"></i>
        <span>{{ __('Search results for filters:') }}</span>
        @foreach($activeFilters as $filter)
            <span class="badge badge-filter px-2.5 py-1.5 rounded">
                {!! $filter !!}
            </span>
        @endforeach
    </div>
@endif
