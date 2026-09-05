<div
    x-show="sidebarOpen"
    x-transition.opacity
    @click="sidebarOpen = false"
    class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
    style="display: none;"
></div>

<aside
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 ease-in-out lg:static lg:translate-x-0 dark:border-slate-800 dark:bg-slate-900 print:hidden"
>
    <a href="{{ route('dashboard') }}" wire:navigate class="flex h-16 shrink-0 items-center gap-2 border-b border-slate-200 px-4 dark:border-slate-800">
        <x-application-logo class="h-8 w-8" />
        <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ config('app.name', 'Rezia Enterprise') }}</span>
        <x-badge color="amber">Beta</x-badge>
    </a>

    <nav class="flex-1 space-y-1 overflow-y-auto p-4">
        <x-layout.sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 11.5L12 4l9 7.5" />
                    <path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9" />
                </svg>
            </x-slot:icon>
            Dashboard
        </x-layout.sidebar-link>

        @can('companies.view')
            <x-layout.sidebar-link :href="route('companies.index')" :active="request()->routeIs('companies.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="3" width="8" height="18" />
                        <rect x="13" y="9" width="7" height="12" />
                        <path d="M7 7h2M7 11h2M7 15h2M15.5 12.5h1M15.5 16h1" />
                    </svg>
                </x-slot:icon>
                Companies
            </x-layout.sidebar-link>
        @endcan

        @can('job_entries.view')
            <x-layout.sidebar-link :href="route('job-entries.index')" :active="request()->routeIs('job-entries.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="4" width="14" height="17" rx="2" />
                        <rect x="9" y="2.5" width="6" height="3" rx="1" />
                        <path d="M8.5 10h7M8.5 13.5h7M8.5 17h4" />
                    </svg>
                </x-slot:icon>
                Job Entries
            </x-layout.sidebar-link>
        @endcan

        @can('egg_purchases.view')
            <x-layout.sidebar-link :href="route('egg-purchases.index')" :active="request()->routeIs('egg-purchases.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 9h14l-1.5 10.5a2 2 0 0 1-2 1.5H8.5a2 2 0 0 1-2-1.5L5 9z" />
                        <path d="M9 9V6a3 3 0 0 1 6 0v3" />
                    </svg>
                </x-slot:icon>
                Egg Purchases & Stock
            </x-layout.sidebar-link>
        @endcan

        @can('company_purchases.view')
            <x-layout.sidebar-link :href="route('company-purchases.index')" :active="request()->routeIs('company-purchases.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3.5" y="7" width="17" height="13" rx="1.5" />
                        <path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7" />
                        <path d="M3.5 12h17" />
                    </svg>
                </x-slot:icon>
                Company Purchases
            </x-layout.sidebar-link>
        @endcan

        @can('daily_summary.view')
            <x-layout.sidebar-link :href="route('daily-summary.index')" :active="request()->routeIs('daily-summary.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3.5" y="4.5" width="17" height="16" rx="2" />
                        <path d="M3.5 9.5h17M8 3v3M16 3v3" />
                        <path d="M7.5 13.5h2M11 13.5h2M14.5 13.5h2M7.5 17h2M11 17h2" />
                    </svg>
                </x-slot:icon>
                Daily Summary
            </x-layout.sidebar-link>
        @endcan

        @can('daily_summary.view')
            <x-layout.sidebar-link :href="route('service-summary.index')" :active="request()->routeIs('service-summary.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 9h14l-1.5 10.5a2 2 0 0 1-2 1.5H8.5a2 2 0 0 1-2-1.5L5 9z" />
                        <path d="M9 9V6a3 3 0 0 1 6 0v3" />
                        <path d="M9 13h6M9 16h6" />
                    </svg>
                </x-slot:icon>
                Service Summary
            </x-layout.sidebar-link>
        @endcan

        @can('service_categories.manage')
            <x-layout.sidebar-link :href="route('service-categories.index')" :active="request()->routeIs(['service-categories.*', 'tiffin-items.*'])">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="4" width="7" height="7" rx="1.5" />
                        <rect x="13" y="4" width="7" height="7" rx="1.5" />
                        <rect x="4" y="13" width="7" height="7" rx="1.5" />
                        <rect x="13" y="13" width="7" height="7" rx="1.5" />
                    </svg>
                </x-slot:icon>
                Service Categories
            </x-layout.sidebar-link>
        @endcan

        @can('bill_statement.view')
            <x-layout.sidebar-link :href="route('bill-statement.index')" :active="request()->routeIs(['bill-statement.*', 'invoices.*'])">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z" />
                        <path d="M9 8h6M9 11.5h6M9 15h4" />
                    </svg>
                </x-slot:icon>
                Bill Statement
            </x-layout.sidebar-link>
        @endcan

        @can('employees.view')
            <x-layout.sidebar-link :href="route('staff-salaries.index')" :active="request()->routeIs(['staff-salaries.*', 'employees.*'])">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="8" r="3.25" />
                        <path d="M3.5 20v-1.5A4.5 4.5 0 0 1 8 14h2a4.5 4.5 0 0 1 4.5 4.5V20" />
                        <path d="M15.5 5.5a3 3 0 0 1 0 5.8M18.5 20v-1.5a4.3 4.3 0 0 0-2.7-4" />
                    </svg>
                </x-slot:icon>
                Staff Salaries
            </x-layout.sidebar-link>
        @endcan

        @can('expenses.view')
            <x-layout.sidebar-link :href="route('expenses.index')" :active="request()->routeIs('expenses.*')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v10M9.5 9.5c0-1.1 1.1-2 2.5-2s2.5.9 2.5 2-1.1 2-2.5 2-2.5.9-2.5 2 1.1 2 2.5 2 2.5-.9 2.5-2" />
                    </svg>
                </x-slot:icon>
                Expenses
            </x-layout.sidebar-link>
        @endcan
    </nav>
</aside>
