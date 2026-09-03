@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-400 dark:border-brand-600 text-sm font-medium leading-5 text-slate-900 dark:text-slate-100 focus:outline-none focus:border-brand-700 transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700 focus:outline-none focus:text-slate-700 dark:focus:text-slate-300 focus:border-slate-300 dark:focus:border-slate-700 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
