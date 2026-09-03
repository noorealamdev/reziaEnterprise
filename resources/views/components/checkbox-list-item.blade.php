@props(['value', 'label', 'hint' => null])

<label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-checked:border-brand-500 has-checked:bg-brand-50 dark:border-slate-700 dark:has-checked:border-brand-600 dark:has-checked:bg-brand-900/20">
    <input type="checkbox" value="{{ $value }}" {{ $attributes->merge(['class' => 'mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500']) }}>
    <span>
        <span class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ $label }}</span>
        @if ($hint)
            <span class="block text-xs text-slate-400 dark:text-slate-500">{{ $hint }}</span>
        @endif
    </span>
</label>
