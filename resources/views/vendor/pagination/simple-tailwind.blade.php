@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex gap-2 items-center justify-between">

        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-400 bg-white border border-slate-300 cursor-not-allowed leading-5 rounded-md dark:text-slate-500 dark:bg-slate-800 dark:border-slate-700">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 leading-5 rounded-md hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-brand-500 transition ease-in-out duration-150 dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 leading-5 rounded-md hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-brand-500 transition ease-in-out duration-150 dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-400 bg-white border border-slate-300 cursor-not-allowed leading-5 rounded-md dark:text-slate-500 dark:bg-slate-800 dark:border-slate-700">
                {!! __('pagination.next') !!}
            </span>
        @endif

    </nav>
@endif
