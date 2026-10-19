<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.2. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== volt/core rules ===

# Livewire Volt

- Single-file Livewire components: PHP logic and Blade templates in one file.
- Always check existing Volt components to determine functional vs class-based style.
- IMPORTANT: Always use `search-docs` tool for version-specific Volt documentation and updated code examples.
- IMPORTANT: Activate `volt-development` every time you're working with a Volt or single-file component-related task.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

<!--
  The section below is maintained by hand, not by Laravel Boost. Keep it
  outside the <laravel-boost-guidelines> block above so Boost's own
  regeneration never overwrites it.
-->

# Application Overview

Rezia Enterprise — billing/invoicing system for a service business that
supplies several factory clients (companies) with labour, Tiffin (meal)
service, materials, and other site services, then bills them per job entry.

## Core domain model

- **Company** — a client factory (e.g. Ananta Apparels Ltd, Simba Fashion
  Ltd, Progress Apparels Ltd.). Has a unique `code` (e.g. `AAL`, `SFL`,
  `PAL`) — always verify a company's real `code` against the database
  before hardcoding it; it will not match seeded demo data.
- **ServiceCategory** — what's being billed: Daily Basic Labour, Tiffin,
  Loading Unloading, Embroidery & Print, ETP Eid Holiday, Diesel Oil
  Supply, Combustible Material, Construction Material Supply, etc. Each
  category can have its own extra fields on the job entry form (e.g.
  Embroidery & Print requires Buyer/Style).
- **JobEntry** — one line of billable work (a day's labour, a Tiffin
  batch item, a material delivery, ...). Has `cost_amount`/`bill_amount`,
  computed `profit_amount`, and links to an `Invoice` once billed
  (`invoice_id`). Entries with no invoice yet are "unbilled"/"pending".
- **Invoice** — generated per company + category + month (via
  `invoices.create`), pulling in that scope's unbilled entries. A company
  can have several concurrent invoices across categories/units in the
  same month. Status: due, partial, paid.
- **TiffinDepartment** — originally just Tiffin's per-department billing
  split (e.g. Swing, Wash Worker), but is also reused as the mechanism
  for Simba Fashion's physical units (see below) — the field name
  (`tiffin_department_id`) predates that second use, so don't assume it
  always means "Tiffin".

## Company-specific customizations

- **Simba Fashion Ltd (code `SFL`)** operates 4 physical units and bills
  Daily Basic Labour and Tiffin separately per unit. This reuses the
  `TiffinDepartment` mechanism (units are literally `TiffinDepartment`
  rows named "Simba Unit-01..04", attached only to this company) rather
  than a new concept. Gated by hardcoded `company->code === 'SFL'` checks
  in `job-entry-form.blade.php` and `invoice-generate-form.blade.php` —
  intentionally not a generic multi-company feature (user's choice).
  Adding a 5th unit: create a new `TiffinDepartment` (Service Categories →
  Manage Items → Tiffin Departments) and attach it to Simba Fashion Ltd's
  company record (Companies → edit → Tiffin Departments checklist).
- **Progress Apparels Ltd. (code `PAL`)** uses the Combustible Material
  category (Floor Dust, Fire Wood, collected as factory byproduct) and
  has a "Collected From" field (`collected_from_company_id`, FK to
  `companies`) recording which other company the material came from.

## Dashboard (redesigned 2026-10)

The Dashboard (`resources/views/livewire/dashboard/dashboard.blade.php`)
is the one place a factory's whole financial picture lives — there is no
separate "Cost & Profit Report" page anymore (removed; its by-company/
by-category, All Work vs Paid Only profit breakdown was folded into the
Dashboard's "Companies — Cost & Profit" table, which shows every company,
never truncated). Structure: stat cards (Unbilled/Billed/Outstanding/
Profit This Month, all clickable through to the relevant Bill Statement
filter or the Companies table) → Pending Bills (Ready to Invoice + Invoice
Overview) → Companies — Cost & Profit table → Today's Activity → trend
chart + Agreement Deadlines → Expenses.

## Working conventions from this project's history

- This is a WAMP (Windows) environment. PHP: full path
  `C:\wamp64\bin\php\php8.2.29\php.exe` (not reliably on PATH in
  tool-spawned shells). MySQL:
  `C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe -u root`.
- The local `reziaenterprise` MySQL database holds **real production
  data**, not disposable test fixtures — always confirm before
  destructive operations, verify nothing is already invoiced/linked
  before deleting a row, and never run `DatabaseSeeder` (full demo
  seeder) against it — it wipes and rebuilds Simba Fashion's data.
- `reziaenterprise.com` resolves to a real public IP, not this machine —
  never use it to preview local changes. Use `php artisan serve` and hit
  `127.0.0.1` instead; remember to stop the server afterward.
- Running the full test suite (`vendor/bin/pest`) can exhaust PHP's
  default 128M CLI memory limit; pass
  `php -d memory_limit=1024M vendor/bin/pest` for a full run.
