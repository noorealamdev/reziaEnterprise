<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('company.name') }} — {{ config('company.tagline') }}</title>
    <meta name="description" content="{{ config('company.name') }} supplies tiffin, labor, construction materials, diesel/oil, and ETP services to garment factories in Bangladesh's BEPZA zones.">

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">

    {{-- Mobile menu: a checkbox-driven disclosure, no JavaScript needed.
    The checkbox and every element that reacts to it are wrapped in one
    `group` (the whole <header>), so `group-has-[:checked]:*` can target
    them regardless of nesting depth — a plain `peer-checked:` would only
    ever match a literal DOM sibling of the checkbox, which none of these
    nested icons/panel are. --}}
    <header class="group sticky top-0 z-30 border-b border-slate-200 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-950/80">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <x-application-logo class="h-9 w-9 shrink-0" />
                <span class="text-sm font-bold tracking-tight text-slate-900 sm:text-base dark:text-white">{{ config('company.name') }}</span>
            </a>

            <nav class="hidden items-center gap-8 text-sm font-medium text-slate-600 sm:flex dark:text-slate-300">
                <a href="#services" class="transition hover:text-brand-600 dark:hover:text-brand-400">Services</a>
                <a href="#about" class="transition hover:text-brand-600 dark:hover:text-brand-400">About</a>
                <a href="#contact" class="transition hover:text-brand-600 dark:hover:text-brand-400">Contact</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="{{ route('login') }}" class="hidden items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-700 shadow-sm transition hover:bg-slate-50 sm:inline-flex dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                    Staff Login
                </a>

                <label for="mobile-menu" class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border border-slate-300 text-slate-600 sm:hidden dark:border-slate-600 dark:text-slate-300" aria-label="Toggle menu">
                    <input type="checkbox" id="mobile-menu" class="hidden">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" class="h-5 w-5 group-has-[:checked]:hidden">
                        <path d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" class="hidden h-5 w-5 group-has-[:checked]:block">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </label>
            </div>
        </div>

        <div class="hidden border-t border-slate-200 bg-white px-6 py-4 group-has-[:checked]:block sm:hidden dark:border-slate-800 dark:bg-slate-950">
            <nav class="flex flex-col gap-4 text-sm font-medium text-slate-600 dark:text-slate-300">
                <a href="#services" class="hover:text-brand-600 dark:hover:text-brand-400">Services</a>
                <a href="#about" class="hover:text-brand-600 dark:hover:text-brand-400">About</a>
                <a href="#contact" class="hover:text-brand-600 dark:hover:text-brand-400">Contact</a>
                <a href="{{ route('login') }}" class="font-semibold text-brand-600 dark:text-brand-400">Staff Login →</a>
            </nav>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-brand-50 via-white to-white dark:from-brand-950/30 dark:via-slate-950 dark:to-slate-950"></div>
            <div class="pointer-events-none absolute -top-24 -right-24 h-96 w-96 rounded-full bg-brand-200/50 blur-3xl dark:bg-brand-800/20"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-24 h-80 w-80 rounded-full bg-brand-100/60 blur-3xl dark:bg-brand-900/20"></div>

            <div class="relative mx-auto grid max-w-6xl grid-cols-1 items-center gap-16 px-6 py-20 sm:py-28 lg:grid-cols-2">
                <div>
                    <p class="inline-flex items-center rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-brand-700 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-300">
                        {{ config('company.tagline') }}
                    </p>
                    <h1 class="mt-6 text-4xl font-extrabold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl lg:text-6xl dark:text-white">
                        Reliable supply &amp; support for
                        <span class="bg-gradient-to-r from-brand-600 to-brand-400 bg-clip-text text-transparent">garment factories</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg text-slate-600 dark:text-slate-300">
                        {{ config('company.name') }} supplies tiffin, daily labor, construction materials, diesel/oil, and ETP support to garment factories across Bangladesh's export processing zones — dependable service, day in and day out.
                    </p>

                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <a href="#services" class="group inline-flex items-center gap-2 rounded-md bg-brand-600 px-6 py-3 text-sm font-semibold uppercase tracking-widest text-white shadow-lg shadow-brand-600/20 transition hover:bg-brand-500 hover:shadow-brand-500/30">
                            Our Services
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 transition group-hover:translate-x-0.5">
                                <path d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </a>
                        <a href="#contact" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-6 py-3 text-sm font-semibold uppercase tracking-widest text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                            Get in Touch
                        </a>
                    </div>
                </div>

                {{-- Decorative service-summary panel — no real photography on hand, so a clean
                composition of the four core services stands in for a hero image. --}}
                <div class="relative hidden lg:block">
                    <div class="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-brand-200/60 to-brand-50/0 blur-2xl dark:from-brand-800/30"></div>
                    <div class="relative rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-xl backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
                        <div class="grid grid-cols-2 gap-4">
                            @foreach ([
                                ['icon' => 'tiffin', 'label' => 'Tiffin Supply'],
                                ['icon' => 'labor', 'label' => 'Labor Supply'],
                                ['icon' => 'materials', 'label' => 'Materials'],
                                ['icon' => 'fuel', 'label' => 'Diesel & Oil'],
                            ] as $item)
                                <div class="rounded-2xl bg-slate-50 p-5 dark:bg-slate-800/60">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white">
                                        <x-home.service-icon :name="$item['icon']" class="h-5 w-5" />
                                    </div>
                                    <p class="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $item['label'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 flex items-center gap-2 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 text-xs font-medium text-brand-700 dark:border-brand-900 dark:bg-brand-900/20 dark:text-brand-300">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 shrink-0">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M9 12.5l2 2 4-4.5" />
                            </svg>
                            One vendor, every factory supply need
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Services --}}
        <section id="services" class="mx-auto max-w-6xl px-6 py-20 sm:py-28">
            <div class="max-w-2xl">
                <h2 class="text-sm font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">What We Do</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Services</p>
                <p class="mt-4 text-slate-600 dark:text-slate-400">Everything a factory needs from a single, dependable supply partner.</p>
            </div>

            <div class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'tiffin', 'title' => 'Tiffin Supply', 'description' => 'Daily meal supply for factory worker departments, delivered on schedule every working day.'],
                    ['icon' => 'labor', 'title' => 'Daily Labor Supply', 'description' => 'Reliable day labor for a factory\'s ongoing operational needs.'],
                    ['icon' => 'materials', 'title' => 'Construction Material Supply', 'description' => 'Sand, stone, brick, cement, and rebar delivered as factory projects need them.'],
                    ['icon' => 'fuel', 'title' => 'Diesel & Oil Supply', 'description' => 'Fuel supply for factory generators and equipment, with proper delivery documentation.'],
                    ['icon' => 'loading', 'title' => 'Loading & Unloading', 'description' => 'Floor-to-floor loading and unloading support for incoming and outgoing goods.'],
                    ['icon' => 'etp', 'title' => 'ETP Support Services', 'description' => 'ETP rubbish removal and tank cleaning to help keep a factory\'s effluent treatment running smoothly.'],
                ] as $service)
                    <div class="group rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-800">
                        <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white dark:bg-brand-900/30 dark:text-brand-400">
                            <x-home.service-icon :name="$service['icon']" class="h-5 w-5" />
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">{{ $service['title'] }}</h3>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ $service['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Why Choose Us --}}
        <section class="border-y border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/40">
            <div class="mx-auto max-w-6xl px-6 py-20 sm:py-28">
                <div class="max-w-2xl">
                    <h2 class="text-sm font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">Why Rezia Enterprise</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Built for factory operations</p>
                </div>

                <div class="mt-12 grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['icon' => 'single', 'title' => 'One Vendor', 'description' => 'Tiffin, labor, materials, and fuel through a single point of contact — less coordination for your team.'],
                        ['icon' => 'zone', 'title' => 'BEPZA Zone Experience', 'description' => 'Familiar with the day-to-day operating rhythm of export processing zone factories.'],
                        ['icon' => 'consistent', 'title' => 'Consistent Daily Service', 'description' => 'Built around recurring, reliable delivery — not one-off jobs.'],
                        ['icon' => 'responsive', 'title' => 'Direct Communication', 'description' => 'A responsive point of contact for adjustments, schedules, and requests.'],
                    ] as $point)
                        <div>
                            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-white text-brand-600 shadow-sm dark:bg-slate-800 dark:text-brand-400">
                                <x-home.service-icon :name="$point['icon']" class="h-5 w-5" />
                            </div>
                            <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">{{ $point['title'] }}</h3>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ $point['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- About --}}
        <section id="about" class="mx-auto max-w-6xl px-6 py-20 sm:py-28">
            <div class="grid grid-cols-1 items-center gap-12 lg:grid-cols-2">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">About Us</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Who We Are</p>
                    <p class="mt-6 text-lg text-slate-600 dark:text-slate-300">
                        {{ config('company.name') }} is a {{ Str::lower(config('company.tagline')) }} supporting garment factories in Bangladesh's export processing zones. We handle the day-to-day supply and support work a factory depends on, so factory management can stay focused on production.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    @foreach ([
                        ['icon' => 'tiffin', 'label' => 'Tiffin'],
                        ['icon' => 'labor', 'label' => 'Labor'],
                        ['icon' => 'materials', 'label' => 'Materials'],
                        ['icon' => 'etp', 'label' => 'ETP Support'],
                    ] as $item)
                        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-400">
                                <x-home.service-icon :name="$item['icon']" class="h-5 w-5" />
                            </div>
                            <p class="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $item['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- CTA band --}}
        <section class="mx-auto max-w-6xl px-6 pb-20 sm:pb-28">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-brand-800 px-8 py-14 text-center shadow-xl sm:px-16">
                <div class="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-20 -left-10 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>
                <h2 class="relative text-2xl font-bold tracking-tight text-white sm:text-3xl">Looking for a dependable supply partner?</h2>
                <p class="relative mx-auto mt-3 max-w-xl text-brand-100">Get in touch to discuss your factory's tiffin, labor, materials, or fuel supply needs.</p>
                <a href="#contact" class="relative mt-8 inline-flex items-center rounded-md bg-white px-6 py-3 text-sm font-semibold uppercase tracking-widest text-brand-700 shadow-sm transition hover:bg-brand-50">
                    Contact Us
                </a>
            </div>
        </section>

        {{-- Contact --}}
        <section id="contact" class="mx-auto max-w-6xl px-6 pb-20 sm:pb-28">
            <div class="max-w-2xl">
                <h2 class="text-sm font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">Get in Touch</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Contact</p>
            </div>

            <div class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-3">
                <a href="tel:{{ trim(str_replace('-', '', Str::before(config('company.phones'), ','))) }}" class="group rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-800">
                    <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white dark:bg-brand-900/30 dark:text-brand-400">
                        <x-home.service-icon name="phone" class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Phone</h3>
                    @foreach (explode(',', config('company.phones')) as $phone)
                        <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ trim($phone) }}</p>
                    @endforeach
                </a>

                <a href="mailto:{{ config('company.email') }}" class="group rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-800">
                    <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white dark:bg-brand-900/30 dark:text-brand-400">
                        <x-home.service-icon name="email" class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Email</h3>
                    <p class="mt-1 break-words font-medium text-slate-900 dark:text-white">{{ config('company.email') }}</p>
                </a>

                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-400">
                        <x-home.service-icon name="address" class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Address</h3>
                    <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ config('company.address') }}</p>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 dark:border-slate-800">
        <div class="mx-auto max-w-6xl px-6 py-12">
            <div class="grid grid-cols-1 gap-10 sm:grid-cols-3">
                <div>
                    <a href="{{ route('home') }}" class="flex items-center gap-2">
                        <x-application-logo class="h-8 w-8 shrink-0" />
                        <span class="text-sm font-bold tracking-tight text-slate-900 dark:text-white">{{ config('company.name') }}</span>
                    </a>
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ config('company.tagline') }}</p>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Navigate</h3>
                    <div class="mt-3 flex flex-col gap-2 text-sm text-slate-600 dark:text-slate-400">
                        <a href="#services" class="hover:text-brand-600 dark:hover:text-brand-400">Services</a>
                        <a href="#about" class="hover:text-brand-600 dark:hover:text-brand-400">About</a>
                        <a href="#contact" class="hover:text-brand-600 dark:hover:text-brand-400">Contact</a>
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Contact</h3>
                    <div class="mt-3 flex flex-col gap-2 text-sm text-slate-600 dark:text-slate-400">
                        <a href="mailto:{{ config('company.email') }}" class="hover:text-brand-600 dark:hover:text-brand-400">{{ config('company.email') }}</a>
                        <span>{{ Str::before(config('company.phones'), ',') }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-10 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-6 text-sm text-slate-500 sm:flex-row dark:border-slate-800 dark:text-slate-400">
                <p>&copy; {{ now()->year }} {{ config('company.name') }}. All rights reserved.</p>
                <a href="{{ route('login') }}" class="hover:text-brand-600 dark:hover:text-brand-400">Staff Login</a>
            </div>
        </div>
    </footer>

</body>
</html>
