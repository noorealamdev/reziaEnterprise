@props(['href' => null, 'active' => false, 'soon' => false])

@if ($soon)
    <span aria-disabled="true" class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-400 dark:text-slate-600">
        <span class="h-5 w-5 shrink-0">{{ $icon }}</span>
        <span class="flex-1">{{ $slot }}</span>
        <x-badge color="slate">Soon</x-badge>
    </span>
@else
    <a
        href="{{ $href }}"
        wire:navigate
        {{ $attributes->merge([
            'class' => 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition '
                . ($active
                    ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'),
        ]) }}
    >
        <span class="h-5 w-5 shrink-0">{{ $icon }}</span>
        <span>{{ $slot }}</span>
    </a>
@endif
