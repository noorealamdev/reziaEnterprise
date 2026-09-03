@props(['label', 'value' => null, 'soon' => false, 'hint' => null])

<div {{ $attributes->merge(['class' => 'flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800']) }}>
    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300">
        {{ $icon }}
    </div>

    <div class="min-w-0">
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</p>

        @if ($soon)
            <p class="mt-1 text-lg font-medium italic text-slate-400 dark:text-slate-500">Coming soon</p>
        @else
            <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ $value }}</p>
        @endif

        @if ($hint)
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $hint }}</p>
        @endif
    </div>
</div>
