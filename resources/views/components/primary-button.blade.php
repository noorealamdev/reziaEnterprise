@props(['href' => null])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex items-center px-4 py-2 bg-slate-800 dark:bg-slate-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-slate-800 uppercase tracking-widest hover:bg-slate-700 dark:hover:bg-white focus:bg-slate-700 dark:focus:bg-white active:bg-slate-900 dark:active:bg-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 transition ease-in-out duration-150']) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-slate-800 dark:bg-slate-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-slate-800 uppercase tracking-widest hover:bg-slate-700 dark:hover:bg-white focus:bg-slate-700 dark:focus:bg-white active:bg-slate-900 dark:active:bg-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 transition ease-in-out duration-150']) }}>
        {{ $slot }}
    </button>
@endif
