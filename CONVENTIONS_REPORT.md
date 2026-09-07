# Rezia Enterprise — Codebase Conventions Report

Generated via a full sweep of the codebase (validation, controllers, authorization, Eloquent, architecture, frontend, database, testing, responses, strings/collections/dates). This documents how the app is **actually written today** — not recommendations, not "best practice" — so future updates (by any agent or developer) follow the same structure instead of drifting.

Framework: Laravel 12, PHP 8.2, Livewire 4 + Volt 1.7, Pest 3. No Rector, no PHPStan, default Pint preset.

---

## Architecture — how business logic is organized

### 1. No controllers or Form Requests for business logic
`routes/web.php` is entirely closures returning views (29 of them), 0 controller-class references. The only real controller is Breeze's own `VerifyEmailController`. Every feature lives as a Volt single-file component under `resources/views/livewire/**`, validated via inline `$this->validate()` inside the component. Routes that need a model use implicit route-model binding (e.g. `fn (Company $company) => view(...)`), never manual `findOrFail()` in a controller.

**Implication for future work:** never add an `app/Http/Controllers/*` class or a Form Request for a new business feature — build a Volt component and a closure route instead.

### 2. No Policy classes — one centralized Gate loop
`app/Providers/AppServiceProvider.php` loops over every `App\Permission` enum case and registers one `Gate::define($permission->value, fn ($user) => $user->hasPermission($permission))`. Two additional hardcoded gates exist outside that loop for actions that must never be delegable via the Roles & Permissions screen: `users.manage` and `personal-ledger.manage`, both restricted to `UserRole::SuperAdmin` directly.

Call sites are consistently split by purpose:
- `Gate::authorize()` inside a component's mutating method (create/update/delete) — 56 occurrences.
- `@can()` in Blade to conditionally show UI (buttons, tabs, stat cards) — 63 occurrences.

**Implication:** a new permission gets a `Permission` enum case (which the loop picks up automatically) — never a new Policy class.

### 3. No repository or query-object layer, no query scopes
Eloquent is queried directly inside each Volt component, either in its `with()` method or a dedicated private method (e.g. `stockSummary()`, `salesReport()`, `wasteReport()`, `companyBreakdown()`). Filtering is built as inline `->when($this->xFilter, fn ($q) => $q->where(...))` chains repeated per component. Zero `scope*` methods exist on any model, and there is no `app/Repositories` or `app/Queries` directory.

**Implication:** don't introduce a repository, query-builder class, or model scope for a new filtered list — follow the existing `->when()`-chain-in-`with()` shape.

### 4. No events/listeners layer
No `app/Events` or `app/Listeners` directories. Side effects (flashing a status message, redirecting, sending an alert email from a scheduled command) happen directly inline in the acting method, not via dispatched domain events.

---

## Models & enums

### 5. Enums live in `app/` root, not `app/Enums/`
`App\Permission` and `App\UserRole` are both `app/Permission.php` / `app/UserRole.php` directly under the `App` namespace — not `App\Enums\Permission`. (Only two enums exist in the whole app, but both agree with zero exceptions.)

### 6. `created_by` convention
Records that need to track who created them use a nullable `foreignId('created_by')->constrained('users')->nullOnDelete()`, set to `auth()->id()` at creation. Used consistently across ~10 models (purchases, sales, payments, invoices, etc.) — never a polymorphic "actor" column or a non-nullable owner FK.

---

## Frontend

### 7. All business Livewire is Volt functional SFCs
36+ of the 42 files under `resources/views/livewire/**` are `new class extends Component { ... }; ?>` Volt single-file components. `app/Livewire/` contains only Breeze's own scaffolding (`Actions/Logout.php`, `Forms/LoginForm.php`) and is never used for an actual feature — no class-based Livewire components exist for business logic.

### 8. Blade composition via anonymous components
23 files under `resources/views/components/**`, the majority (19) using `@props()` anonymous components (`x-text-input`, `x-badge`, `x-select-input`, etc.). Two class-based layout components exist (`AppLayout`, `GuestLayout`, both Breeze-standard). Zero `@include()` partials anywhere in the app.

---

## Idiom

### 9. `auth()` helper in domain code; `Auth::` facade only in Breeze scaffolding
`auth()->id()` / `auth()->user()` is used 30× across business components. Every one of the 15 `Auth::` facade usages is confined to Breeze's own auth/profile files (`Logout.php`, `LoginForm.php`, `confirm-password.blade.php`, `register.blade.php`, `verify-email.blade.php`, `delete-user-form.blade.php`, `update-password-form.blade.php`, `update-profile-information-form.blade.php`) — never introduced into new domain code. Likewise `config()` is used exclusively (21×) with zero `Config::` facade calls anywhere.

### 10. `Str::` static methods, not fluent `Str::of()`
20 static `Str::` calls, 0 `Str::of()` usages.

### 11. `simplePaginate()` for every list
12 uses, 0 `paginate()`, 0 `cursorPaginate()` — avoids the extra `COUNT` query. When a list is built by merging non-Eloquent data (e.g. Bill Statement's invoiced + pending rows), the pattern is a manually-built `LengthAwarePaginator([], $count, ...)` with an **empty** items array purely to drive the page-link controls, while the real (already fully-loaded) data is sliced from the merged collection separately for the print view.

---

## Testing

### 12. No Mockery — full integration-style tests
Zero `Mockery::`, `->mock()`, or `->spy()` calls anywhere in `tests/`. Every test hits real Eloquent models and real Livewire components end-to-end. Facade fakes (`Storage::fake('public')`, `Mail::fake()`) are used normally for file storage and mail side effects — that's isolating a framework service, not a departure from the "no mocking collaborators" convention.

### 13. Fixed lookup/reference data gets a Pest helper, not a factory
`tests/Pest.php` defines global helper functions for models that are fixed lookup/reference data rather than randomized test data — `makeServiceCategory(string $name)` (176 call sites across the suite) and `makeTiffinRecipe(TiffinDepartment $department, array $itemNames)` (27 call sites). Neither `ServiceCategory` nor these recipe rows have a factory; a new fixed-lookup model needing test setup should get its own helper in `Pest.php` the same way, not a factory and not inline manual creation repeated per test.

### 14. Permission-boundary tests call the gated action directly
A test proving a role lacks a permission calls the actual gated method through `Volt::test(...)->call(...)` and asserts `->assertForbidden()` — never mocks `Gate` or asserts on a Policy response.

---

## Notable non-findings (checked, not conventions)

- `$fillable` vs `$guarded`: 25/25 models use `$fillable` — this is the common Laravel default, not a distinguishing choice.
- Accessors: 7 models use the modern `Attribute` class, 0 legacy `getXxxAttribute()` — Laravel 12's own current idiom, not a house choice.
- `casts(): array` method used in 21 models — Laravel 11+ default, already covered by the project's own Boost-loaded guidelines.
- Primary keys: plain auto-increment everywhere, no UUID/ULID.
- `foreignId()->constrained()` used 35×, 0 `foreignIdFor()`/manual `->foreign()` — the modern Laravel default.
- Migrations: 29/29 have a real `down()` with `dropIfExists` — matches the anonymous-migration stub's own default shape.
- `DB::transaction()` closure style used 9×, 0 manual `beginTransaction`/`commit` — the recommended default.
- No API — no `app/Http/Resources`, no JSON responses; this is a pure Blade/Livewire app.
- `route()` used 74× vs `url()` 0× — already stated in this project's own loaded Laravel Boost guidelines, not re-recorded.
- No `lang/` directory — `__()` calls exist (94, mostly Breeze scaffolding) but resolve to their own literal key; localization isn't actually implemented.

---

## Next step

These conventions have **not yet been written to `.ai/rules`** — this file is the report only. To make them load-bearing for future agents (including me, in later sessions), say the word and I'll run `record-rule` for each approved item, scoped to the right path globs (e.g. the architecture items to `app/**`, the Volt/Blade items to `resources/views/livewire/**` and `resources/views/components/**`, the testing items to `tests/**`).
