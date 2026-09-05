@php
    $logoPath = \App\Models\Setting::current()->logo_path;
    $logoUrl = $logoPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) : null;
@endphp

@if ($logoUrl)
    <img src="{{ $logoUrl }}" alt="{{ config('company.name') }}" {{ $attributes->merge(['class' => 'object-contain']) }}>
@else
    <svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" {{ $attributes }}>
        <rect width="40" height="40" rx="10" fill="#303960" />
        <text x="20" y="26" text-anchor="middle" font-family="Figtree, ui-sans-serif, system-ui, sans-serif" font-size="15" font-weight="700" fill="#FFFFFF">RE</text>
    </svg>
@endif
