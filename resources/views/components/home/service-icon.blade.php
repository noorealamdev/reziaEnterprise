@props(['name'])

@php
    $paths = match ($name) {
        // Tiffin carrier — stacked tiers with a top handle.
        'tiffin' => '<rect x="6" y="8" width="12" height="12" rx="1.5" /><path d="M6 13h12M6 17h12" /><path d="M9.5 8V6.5a2.5 2.5 0 0 1 5 0V8" />',
        // Two people — day labor supply.
        'labor' => '<circle cx="9" cy="8" r="2.25" /><path d="M4.5 19v-1a4.5 4.5 0 0 1 9 0v1" /><circle cx="17" cy="9" r="1.75" /><path d="M14.5 19v-.75a3.5 3.5 0 0 1 6 -2.47" />',
        // Stacked bricks — construction materials.
        'materials' => '<rect x="4" y="15" width="6" height="4.5" rx="0.75" /><rect x="10" y="15" width="6" height="4.5" rx="0.75" /><rect x="7" y="10" width="6" height="4.5" rx="0.75" /><rect x="13" y="10" width="6" height="4.5" rx="0.75" /><rect x="10" y="5" width="6" height="4.5" rx="0.75" />',
        // Fuel drop — diesel & oil supply.
        'fuel' => '<path d="M12 4.5s5.5 6.2 5.5 10.2a5.5 5.5 0 1 1 -11 0c0-4 5.5-10.2 5.5-10.2z" /><path d="M9.5 15.2a2.5 2.5 0 0 0 2.5 2.5" />',
        // Loading crate with directional arrows.
        'loading' => '<rect x="5" y="10" width="9" height="9" rx="1" /><path d="M9.5 12.5v4M7.5 14.5h4" /><path d="M17 4v6M17 4l-2.25 2.25M17 4l2.25 2.25" /><path d="M20.5 12.5v6" />',
        // Droplet with recycling-style arrows — ETP support.
        'etp' => '<path d="M12 3.5s4.5 5 4.5 8.3a4.5 4.5 0 1 1 -9 0c0-3.3 4.5-8.3 4.5-8.3z" /><path d="M9 19.5h6M8 21.5l1-2M16 21.5l-1-2" />',
        // Single vendor — one checkmark badge.
        'single' => '<circle cx="12" cy="12" r="8" /><path d="M9 12.5l2 2 4-4.5" />',
        // BEPZA zone — map pin.
        'zone' => '<path d="M12 21s6.5-6 6.5-10.8A6.5 6.5 0 0 0 5.5 10.2C5.5 15 12 21 12 21z" /><circle cx="12" cy="10.2" r="2.25" />',
        // Consistent daily service — repeat / calendar cycle.
        'consistent' => '<path d="M4.5 12a7.5 7.5 0 0 1 12.5-5.6M19.5 12a7.5 7.5 0 0 1-12.5 5.6" /><path d="M17.5 4.5v3h-3M6.5 19.5v-3h3" />',
        // Direct communication — chat bubble.
        'responsive' => '<path d="M4.5 12a7.5 7.5 0 1 1 3 6L4.5 19l1-3.2A7.4 7.4 0 0 1 4.5 12z" /><path d="M8.5 11.5h7M8.5 14h4.5" />',
        // Contact card icons.
        'phone' => '<path d="M5 4.5h3.2l1.3 4-2 1.3a11 11 0 0 0 5.7 5.7l1.3-2 4 1.3V18a1.5 1.5 0 0 1-1.6 1.5A15 15 0 0 1 3.5 6.1 1.5 1.5 0 0 1 5 4.5z" />',
        'email' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2" /><path d="M4.5 7l7.5 6 7.5-6" />',
        'address' => '<path d="M12 21s7-6.5 7-11.5A7 7 0 0 0 5 9.5C5 14.5 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.25" />',
        default => '<circle cx="12" cy="12" r="8" />',
    };
@endphp

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" {{ $attributes }}>
    {!! $paths !!}
</svg>
