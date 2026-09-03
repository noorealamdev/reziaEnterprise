@props(['color' => 'slate'])

@php
$classes = match ($color) {
    'green' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    'red' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    'brand' => 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
    default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $slot }}
</span>