@props(['title', 'message' => null])

<div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-800">
    @isset($icon)
        <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-700 dark:text-slate-500">{{ $icon }}</div>
    @endisset

    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>

    @if ($message)
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $message }}</p>
    @endif

    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
