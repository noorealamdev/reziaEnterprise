# Rezia Enterprise — Subcontractor Management System

## Business summary

Rezia Enterprise subcontracts a range of services to garment factories (BEPZA
zone factories in Bangladesh), starting with **Ananta Apparels Ltd** as the
first client. Services observed in the source workbook
(`Ananta Apparels ltd..xlsx`):

- Tiffin supply (per worker department: Swing, Wash Worker, ...)
- Diesel/oil supply
- ETP rubbish removing (dump truck + daily labour)
- Daily basic labour supply
- Loading-unloading (rate varies by floor)
- Embroidery & print (buyer/style/quantity based)
- Construction material supply (sand, stone, brick, cement, rebar)
- ETP tank cleaning during Eid holidays (advance-payment based)

Every factory (modeled in the app as a **Company**, since "Factory" collides
with Laravel's own model-factory naming convention) will get the same shape
of system with **minor per-company variation** (which service categories/
departments are active, rates, buyers, etc.) — so the schema must support
many companies from day one, even though we build and test against Ananta
Apparels Ltd first.

## Confirmed business rules

- **Tiffin (Swing dept):** Banana quantity is the base order. Egg quantity =
  Banana quantity + 5 (fixed constant). Bread quantity = Banana quantity.
  Cost/bill share the same quantity — only the rate differs.
- **VAT/Tax:** no fixed rate — varies transaction to transaction (seen as low
  as 2%, as high as 5%+). Always a free-editable field on the invoice,
  pre-filled with the **last VAT % used for that company + service
  category** as a convenience default.
- **Loading-Unloading:** bill rate depends on floor (e.g. Mazzanine vs
  Embroidery floor); cost rate stays constant. Floor is a plain text field,
  rate typed manually each entry (no managed rate table).
- **Embroidery & Print:** Buyer is a growable list (American Eagle, GAP,
  Calvin Klein, ...), can change mid-month. Style must be searchable/
  filterable to see aggregated quantity — this is a real reporting
  requirement, not just data entry.
- **Invoicing is manual:** the accountant selects unbilled job entries and
  generates an invoice on demand (no auto-batching).
- **One invoice = one company + one service category**, covering a date
  range (usually a month), identified by invoice numbers like
  `RE/AAL/DiBL-01`. **Tiffin is included in this same monthly cycle** — all
  tiffin entries (across departments) for the month are billed and land in
  Bill Statement exactly like every other category; the source workbook's
  tiffin sheets just hadn't reached that step yet.
- **Bill Statement is a derived view**, not separately maintained data — it
  must reflect every invoice across every category automatically, support
  attaching a scanned/photographed invoice copy, and marking an invoice
  Paid must reconcile its due amount to zero.
- Amounts are not strictly quantity × rate in every case (e.g. ETP Eid
  Holiday entries are flat project amounts) — cost/bill amount fields must
  stay manually editable, with quantity × rate only as a computed
  *default*.
- **Rates and profit must be actively managed, not just recorded.** A
  standard current rate (cost + bill) is maintained per company/category/
  item on a **Rate Card**; job entries pull today's rate as a default
  (still editable per entry) instead of the accountant re-typing or
  remembering it. Whenever a rate changes, the old rate is kept as history
  rather than overwritten — this is what makes profit/margin reporting
  over time possible, and lets us answer "what did we charge before vs.
  now" per company.

## Open assumptions to confirm during build

- No partial-payment cases appear in the sample data (always fully Paid or
  fully Unpaid) — building simple binary payment status first; can extend
  to partial payments later if needed.
- Whether tiffin's multiple departments (Swing, Wash Worker, ...) get
  **one combined monthly invoice** or **one invoice per department** isn't
  settled by the source data. The invoice-creation screen will filter
  unbilled entries by category + optional department + date range, so
  either workflow works without a schema change — we'll confirm the actual
  preference when building that screen.

## Tech stack

- **Laravel 12**
- **Livewire 3** (using the official Laravel + Livewire starter kit for
  auth/scaffolding — Tailwind CSS + Flux UI components, no Filament)
- **MySQL 8**
- File attachments (invoice copies) stored via Laravel's local `storage`
  disk — no external media package needed for a single-file-per-invoice
  use case.
- Simple `role` column on `users` (`owner`, `accountant`) rather than a
  full permissions package — current scope is one accountant-focused role;
  revisit if more roles emerge.

## Database schema (initial)

**companies** (the garment factories we subcontract to — named `Company` in
code because `Factory` collides with Laravel's own model-factory naming
convention: generating a model literally called `Factory` produces a
`database/factories/Factory.php` test-data class that clashes with
`Illuminate\Database\Eloquent\Factories\Factory`)
`id, name, code (short code for invoice numbers, e.g. AAL), address,
contact_person, phone, email, bepza_reg_no, is_active, timestamps`

**service_categories** (seeded lookup: Tiffin, Diesel, ETP Rubbish Removing,
Daily Basic Labour, Loading Unloading, Embroidery & Print, Construction
Material Supply, ETP Eid Holiday)
`id, name, invoice_code (e.g. DiBL, CMS, ETP-EID), unit_label, sort_order`

**company_service_categories** (pivot — which categories are active per
company)
`company_id, service_category_id`

**tiffin_departments** (seeded lookup: Swing, Wash Worker, ...)
`id, name`

**company_tiffin_departments** (pivot)
`company_id, tiffin_department_id`

**in_charges** (people responsible for a job, e.g. Mr. Monir, Sagor Vai)
`id, name, phone, is_active, timestamps`

**job_entries** (the daily transaction — one flexible table, nullable
category-specific columns rather than per-category tables)
`id, company_id, service_category_id, tiffin_department_id (nullable),
in_charge_id (nullable), entry_date, supply_type (free text, e.g. "Egg",
"Local Sand Supply", "Daily Basic Labour"), buyer (nullable, embroidery),
style (nullable, embroidery), floor (nullable, loading-unloading),
challan_no (nullable, diesel), company_adv_payment (nullable, ETP eid),
quantity (nullable), cost_rate (nullable), cost_amount, bill_rate
(nullable), bill_amount, profit_amount (computed on save), is_off_day
(bool), remarks, invoice_id (nullable FK, set once billed), timestamps`

**rate_cards** (current + historical standard rates per company/item —
job entries default from this but always stay editable)
`id, company_id, service_category_id, supply_type (matches job_entries
naming, e.g. "Egg", "Local Sand Supply"), tiffin_department_id (nullable),
buyer (nullable, embroidery), cost_rate, bill_rate, effective_from (date),
superseded_at (nullable, set when a newer rate for the same item is added),
remarks, created_by (user_id), timestamps`

Looking up the active rate for an item = the row for that
company+category+supply_type(+department/buyer) with the latest
`effective_from <= today` and `superseded_at` null/future. Margin
(`bill_rate - cost_rate`) is derived, not stored.

**invoices**
`id, invoice_number (unique), company_id, service_category_id,
period_start, period_end, sent_date, received_date, gross_amount,
vat_percent, vat_amount, net_payable, payment_status (unpaid/paid),
due_amount, payment_date (nullable), payment_ref (nullable),
payment_bank (nullable), attachment_path (nullable), description,
remarks, created_by (user_id), timestamps`

**users**
`id, name, email, password, role (owner/accountant), timestamps`

Relationships: a `job_entry` belongs to at most one `invoice`
(nullable `invoice_id`) — matches the observed 1:many batching pattern, no
need for a pivot table. `Bill Statement` is not a table; it's a Livewire
view querying `invoices` (+ their linked `job_entries`) across companies.

## Module / screen breakdown

1. **Auth** — from the Laravel Livewire starter kit, minimal changes.
2. **Companies** — CRUD, plus toggling which service categories and
   tiffin departments are active for that company.
3. **Rate Cards** — per company, manage the current cost/bill rate for
   each item (per category, department, or buyer as applicable). Editing a
   rate here doesn't touch past entries — it just changes what new entries
   default to going forward, and keeps the prior rate visible as history.
4. **Job Entries** — list + form per company, filterable by service
   category and month. Form fields adapt to the selected category
   (Tiffin: department + banana-driven auto-fill for egg/bread; Embroidery:
   buyer/style; Loading-Unloading: floor; Diesel: challan no.; ETP Eid:
   advance payment). Cost/bill rate default from the active Rate Card entry
   (falling back to quantity × rate for the amount) but stay editable.
5. **Invoice creation** — pick company + category (+ optional department)
   + a date range of unbilled entries, review computed gross amount, edit
   VAT % (pre-filled from last used), attach invoice copy image, save —
   this assigns `invoice_id` on the selected job entries.
6. **Invoices** — list/detail, mark paid (date, payment ref, bank), due
   amount recalculates automatically.
7. **Bill Statement** — read-only rollup across all invoices for a company
   (or all companies), filterable by month/status — mirrors the master
   ledger sheet.
8. **Dashboard & Profit Reports** — totals due, recent unpaid invoices,
   profit/margin by company/category/month, Style-based quantity search
   for Embroidery, and rate-change history per item.

## Build phases

1. **Scaffold** — `laravel new` with Livewire starter kit, MySQL config,
   base layout/navigation, deploy Ananta Apparels Ltd as the first seeded
   company. *(Done — see below.)*
2. **Foundation** — service category & tiffin department lookups, Company
   CRUD + category/department toggles, in-charge management, Rate Cards.
3. **Job Entries** — generic entry list/form covering all service
   categories, including the Tiffin auto-derivation rule, per-category
   field variations, and rate auto-fill from the active Rate Card.
4. **Invoicing** — unbilled-entry selection, invoice generation, VAT
   handling, attachment upload — including tiffin in the same monthly
   cycle.
5. **Payments** — mark-paid flow, due amount reconciliation.
6. **Bill Statement + Dashboard** — rollup views and profit/margin
   reporting (by category/month/item), Style-based quantity search for
   Embroidery, rate-change history.
7. **Hardening** — validation pass, second-company dry run to confirm the
   "minor changes per company" assumption holds without schema changes.

## Phase 1 status (done)

- Laravel 12 + Livewire 4 (latest) + Volt (latest) via Breeze's Livewire
  auth stack — login, register, password reset, email verification,
  profile all working.
- Tailwind v4 (latest, native Vite plugin) — fixed a downgrade-to-v3 bug in
  Breeze's installer.
- MySQL `reziaenterprise` database connected.
- `Company` model + migration + seeder, seeded with Ananta Apparels Ltd
  (code `AAL`).
- Root `/` redirects to the dashboard (guests bounce to login).
- 26 tests passing.
- Verified end-to-end with Playwright/Chromium (login, dashboard, profile,
  register, logout, guest/auth redirects) — this caught a real bug my
  earlier curl-only checks missed: `vite.config.js` was never registering
  the `@tailwindcss/vite` plugin, so every page was rendering completely
  unstyled (Tailwind directives served as literal dead text). Fixed.

## UI shell redesign (done)

Product is meant to eventually be resold to other subcontractor businesses
as **separate deployed instances per client** (not shared multi-tenant —
confirmed with client), so this pass focused on making the shell look like
a real, sellable product rather than Breeze's default scaffold:

- Brand identity: `--color-brand-*` theme scale in `resources/css/app.css`
  (Tailwind's indigo oklch stops, renamed to a single white-labelable
  token), `slate` neutrals replacing `gray` throughout, an "RE" monogram
  logo (`components/application-logo.blade.php`) replacing the default
  Laravel mark, and a matching `public/favicon.svg` (the old
  `favicon.ico` was a 0-byte placeholder with no `<link>` referencing it —
  fixed).
- Persistent left sidebar (`components/layout/sidebar.blade.php`) +
  slim topbar, collapsing to an off-canvas mobile drawer via Alpine (no
  new JS dependency). Lists all 8 planned modules; only Dashboard is a
  real link today — the rest show a muted row with a "Soon" badge
  (`components/badge.blade.php`) rather than a dead link or an unbuilt
  placeholder page.
- Dashboard (`resources/views/dashboard.blade.php`, plain Blade view —
  see gotcha below) shows real stat cards (Total/Active Companies, from
  `App\Models\Company`) plus honest "Coming soon" cards for Job
  Entries/Invoices (no fabricated numbers), and a "Getting Started"
  checklist using real data.
- Removed dead code: `welcome.blade.php` and its `livewire/welcome/`
  navigation (Laravel's stock marketing page, unreferenced since `/`
  redirects to `/dashboard`).
- **Gotcha hit and fixed**: first attempt made the dashboard a Volt
  full-page route (`Volt::route('dashboard', 'pages.dashboard')`) with
  the component template manually wrapped in `<x-app-layout>`. Livewire
  auto-wraps full-page route components in `layouts.app` by default —
  combined with the manual wrap, this rendered the entire sidebar+topbar
  shell **twice**, nested. Reverted to a plain `Route::get` closure
  returning `view('dashboard', [...])`, matching the same pattern
  `profile.blade.php` already used successfully. Caught by inspecting the
  live DOM via Playwright (`aside`/`nav` element counts), not visible from
  a quick screenshot glance alone.
- 29 tests passing (added `CompanyFactory::definition()`, which was empty
  and blocked any test using `Company::factory()`, plus `DashboardTest`).
- Verified with Playwright/Chromium at desktop + mobile widths, light +
  dark mode, including opening the mobile sidebar drawer.

Still using the plain single-role `users` table (no `role` column yet) —
that's Phase 2 territory when accountant-vs-owner permissions actually
matter.

## Companies CRUD (done)

First real business feature — full CRUD for Companies plus the "toggle
which service categories and tiffin departments are active" requirement.
Sidebar's Companies link is now real (was "Soon").

- New lookup tables: `service_categories` (8 seeded, matching invoice-code
  prefixes already observed in the client's spreadsheet: TIF, Diesel, RRW,
  DiBL, Load-Unload, EMB, CMS, ETP-EID) and `tiffin_departments` (Swing,
  Wash Worker), plus `company_service_categories`/`company_tiffin_departments`
  pivots (composite primary keys, cascade delete). `Company` gained
  `serviceCategories()`/`tiffinDepartments()` `BelongsToMany` relations.
- List (`livewire/companies/company-list.blade.php`) and form
  (`livewire/companies/company-form.blade.php`, shared by create + edit)
  as embedded Volt components inside plain Blade route views — same safe
  pattern as the dashboard, no `Volt::route()`.
- Mobile-first: card list (not a table), single-column form, checkbox
  grids collapse to 1 column on small screens, Prev/Next-only pagination
  (published + restyled Laravel's `pagination::simple-tailwind` view).
  Tiffin Departments only reveals when the Tiffin category is checked
  (Alpine `@entangle`, no server round-trip).
- Added an `href` prop to `primary-button`/`secondary-button` so
  link-styled buttons render as real `<a>` tags — the original plan had
  `<a><button></a>`, which is invalid HTML.

**Three real bugs found and fixed via testing, not assumed away:**
1. **Laravel container gotcha**: `mount(?Company $company = null)` — when
   Livewire renders `<livewire:companies.company-form />` with no
   `company` prop, Laravel's container auto-instantiates an *empty*
   `Company()` (it has a public no-arg constructor) instead of passing
   `null`. Checking truthiness (`if ($company)`) was wrong; fixed to check
   `$company->exists`. This would have broken the real create page in
   production, not just tests — caught because the Pest test suite tried
   the create path with no company at all.
2. **Modal stacking bug in Breeze's own stock `modal.blade.php`** (pre-existing,
   not introduced by this project): the backdrop div is `position: fixed`
   and the content panel is unpositioned (`static`) — per CSS stacking
   rules, positioned siblings always paint above static ones regardless of
   DOM order, so the backdrop silently blocked every click on anything
   inside any modal in the app, including the pre-existing profile-page
   delete-account confirmation. Confirmed empirically via
   `document.elementFromPoint()` before and after. Fixed by adding
   `relative` to the content panel. Playwright's click-actionability
   checker caught this; it wasn't visible from a static screenshot.
3. **Double HTML-escaping**: passed Blade component props as
   `label="{{ $category->name }}"` (string attribute, escaped once) instead
   of `:label="$category->name"` (bound expression) — the already-escaped
   string got escaped *again* inside the child component, so "Embroidery &
   Print" literally rendered as "Embroidery &amp; Print" on screen. Only
   visible by actually looking at a rendered screenshot with real seeded
   data containing an `&`; the automated DOM/test assertions didn't catch
   it since they didn't check for literal entity text.

38 tests passing. Verified with Playwright/Chromium: full create → search
→ edit → cancel → delete round trip, desktop + mobile (390×844) + dark
mode, plus the DOM shell-duplication check (`aside`/`nav` counts) on all
three new pages.

## Rate Cards (done, then removed — see "Tiffin Items & rate architecture change")

Originally built as an append-only historical ledger (separate `rate_cards`
table, its own CRUD page, `scopeCurrent()` lookup). **Superseded and
entirely removed** once the client decided rates should live directly on
each Job Entry instead — see the section below for the replacement design.
This section is kept for history; none of the code it describes still
exists (model, migration, seeder, routes, sidebar link, and tests were all
deleted).

## Job Entries (done)

The biggest, most central feature — the daily transaction record. Supports
all 8 service categories in one flexible form with conditional fields.
Sidebar's Job Entries link is now real. In-Charges (`in_charges` table)
ship with no standalone CRUD page — just a quick-add "+ Add new
in-charge…" option inline in the form's dropdown, per client decision.

- `job_entries` schema matches PLAN.md exactly, with `invoice_id`
  deliberately omitted (the `invoices` table doesn't exist yet — a
  follow-up migration will add it once Invoices is built). `profit_amount`
  is a real stored column (unlike `RateCard::margin`, a pure accessor),
  recomputed on every save via a `JobEntry::booted()` `saving` hook —
  excluded from `$fillable` so it's never directly user-settable.
- **Rate Card auto-fill**: blur-ish text fields (`supply_type`, `buyer`)
  and live selects (category, department) all trigger a shared
  `attemptRateCardAutoFill()` that overwrites `cost_rate`/`bill_rate` from
  `RateCard::current()` — a blur/change is the explicit "item changed"
  signal, so a stale rate for a different item is intentionally replaced;
  nothing re-runs on the rate fields themselves, so manual edits stick.
- **Tiffin/Swing/Banana three-row auto-derivation** — the hardest part:
  entering a Banana quantity for Tiffin+Swing switches the form into a
  read-only "Swing Tiffin Order" preview (Banana=X, Egg=X+5, Bread=X, each
  independently rate-carded), and `saveSwingTiffinOrder()` creates all
  three rows in one `DB::transaction()`, re-validating server-side rather
  than trusting the client-tracked mode flag, aborting the whole batch if
  any of the three items is missing a current rate card.
- Category field matrix (Tiffin→department required, Loading-Unloading→
  floor required, Embroidery→buyer+style both required, Diesel→challan_no
  shown but optional, ETP Eid→advance payment optional) with server-side
  re-nulling of whichever conditional fields don't apply, same pattern
  already proven in Rate Cards.

**Three real bugs found and fixed via testing, not assumed away:**
1. **`wire:model.blur` never fired at all.** The plan called for
   blur-triggered rate-card lookups on `supply_type`/`buyer` to avoid
   querying on every keystroke. In the browser, blurring the field
   triggered zero network requests — confirmed by monitoring actual
   Livewire traffic (the endpoint is a randomized path like
   `/livewire-{hash}/update`, not `/livewire/update` as first assumed,
   which cost some debugging time itself). Root-caused by comparing
   against `rate-card-form.blade.php`'s proven-working modifiers — nothing
   in this codebase had used `.blur` successfully before. Fixed by
   switching to `wire:model.live.debounce.500ms`, the same proven pattern
   already used for `quantity`/`cost_rate`/`bill_rate` in this exact form.
2. **A UTC vs. local-timezone date mismatch, surfaced only by manual
   debugging SQL, not the app itself.** While manually seeding extra Rate
   Card rows via raw SQL to test the Swing flow, `CURDATE()` (MySQL's
   `SYSTEM` timezone, i.e. the OS's local BST) produced a different
   calendar date than Laravel's `now()` (configured UTC) during the ~1hr
   window each day where the two disagree. `RateCard::scopeCurrent()`
   correctly and consistently uses `now()->toDateString()` everywhere in
   the actual app — the mismatch only bit because a manual debug insert
   used MySQL's clock instead. Not an app bug; fixed the manual seed data,
   not the code. Worth remembering for any future raw-SQL debugging in
   this environment.
3. **A weak Playwright assertion masked bug #2 for one extra run.** The
   test only checked that each item's *name* appeared in the Swing preview
   panel, not that a rate was actually found — so a "no current rate card"
   warning state would have still read as a pass. Strengthened to also
   assert the warning text is absent.

65 tests passing (13 new). Verified with Playwright/Chromium: a simple
category end-to-end, Diesel's challan_no field, Embroidery's buyer/style
fields, the full Swing/Banana three-row flow (quantities 40/45/40 with
independently correct rates, confirmed both in the live preview and the
resulting list), in-charge quick-add, desktop + mobile (390×844) + dark
mode, and the DOM shell-duplication check on both new pages.

## Tiffin Items & rate architecture change (done)

Three related changes landed together, in order: (1) Tiffin's Swing/Banana
rule went from hardcoded PHP strings to a data-driven catalog model with
full CRUD; (2) Rate Cards was removed entirely — rates now live directly
on each Job Entry, auto-filled from history instead of a separate ledger
table; (3) the initial "derive Egg = Banana + 5 automatically" design was
itself replaced, per direct client feedback, with a plain per-item
quantity+rate entry — simpler to reason about than an offset/derivation
system, even though the numbers happen to follow that relationship in
practice.

**Why the spreadsheet re-read mattered:** a Bangla developer note in the
source workbook ("Ananta Swing and Wash Staff will be same column") was
re-translated mid-build and revealed Wash Worker's real item set is
identical to Swing's (Banana, Egg, Bread) — not the Banana+Milk guess from
the original read. Confirmed with the client and corrected in the seeder
before it shipped anywhere.

### Data-driven Tiffin items (simplified: no derivation)

- `tiffin_items` (catalog: name unique, unit_label, is_active) and
  `tiffin_department_items` (which items belong to which department, plus
  `sort_order` — a plain ordered join, nothing else). An earlier version of
  this table also carried `derived_from_tiffin_item_id` +
  `quantity_offset` to auto-compute Egg from Banana; the client asked for
  something easier to reason about instead ("I'll add Banana = 200, Egg =
  205, Bread = 200 myself, for both Swing and Wash") — so those columns
  were dropped and every item's quantity is simply typed in, every time.
- `TiffinDepartment` gained a `departmentItems()` relation and its own
  full CRUD (`department-manager.blade.php`) — the client explicitly
  asked that departments be a scalable module, not a fixed
  Swing/Wash-Worker pair, so a third department needs zero code changes.
- `/tiffin-items` is one page, three Volt components: `department-manager`
  (add/edit/delete departments), `item-catalog-manager` (add/edit/delete
  catalog items), `department-recipe-manager` (per department: add an
  item, remove it, reorder via up/down buttons swapping `sort_order`).
  Deletes are blocked server-side (re-checked, not just a disabled
  button): a department can't be deleted while it has items assigned,
  company assignments, or job entries; a catalog item can't be deleted
  while assigned to any department; a department item can't be removed
  while it has recorded job entries. Cross-component sync uses two
  dispatched browser events (`tiffin-item-catalog-updated`,
  `tiffin-department-updated`) with empty `#[On(...)]` handlers to force
  a re-render.
- `job-entry-form.blade.php`: selecting a Tiffin department immediately
  shows **every item assigned to that department** as a batch — no
  separate "pick one item" step first. A new `currentDepartmentItems()`
  helper returns the department's active items (or null when a batch
  entry doesn't apply: not Tiffin, no department chosen, editing an
  existing single entry, or the department has no items configured yet).
  Each item gets its own `batchQuantities`/`batchCostRates`/
  `batchBillRates` array entry (keyed by item name, bound via Livewire's
  dot-path array binding, e.g. `wire:model.live.debounce.400ms=
  "batchQuantities.Egg"`), with cost/bill rate pre-filled from that item's
  own most-recent Job Entry when one exists — quantity is always typed
  fresh. Saving validates each `batchQuantities.{item}` /
  `batchCostRates.{item}` / `batchBillRates.{item}` key individually and
  creates one `JobEntry` per item in a single `DB::transaction()`.

### Rate Cards removed — rates now live on the Job Entry

Client's call: a separate rate ledger was redundant since Job Entries are
already dated, per-item records — the entries themselves are the rate
history. Deleted entirely: `RateCard` model, its migration, factory,
seeder, both Volt components, both routes, the sidebar link, and
`RateCardManagementTest.php`.

- New lookup: `mostRecentJobEntry(supplyType)` on the Job Entry form finds
  the latest entry matching company + category + department + supply type
  + buyer (`orderByDesc('entry_date')`, tie-broken by `id`) and suggests
  its `cost_rate`/`bill_rate`. First-ever entry for a combo has nothing to
  suggest — the accountant just types it in, same as any other field.
  Used both for the normal single-entry path and per-item inside the
  Tiffin batch.

### Bug found and fixed: category+department selected together clobbered the department

**Symptom:** picking a Tiffin department sometimes left the old single
"Tiffin Item" picker showing instead of the new per-item batch — visible
both in the client's own screenshot and reproduced in Playwright.

**Root cause, found by adding temporary `\Log::info()` tracing to every
`updated*` hook:** Livewire bundles multiple property changes into one
request when they happen close together (normal, expected behavior — not
itself a bug). When the category-select and department-select changes got
bundled into a single request, `updatedServiceCategoryId()` ran and
**unconditionally** reset `tiffin_department_id = null` — clobbering the
department value that arrived in the very same request, moments before
`updatedTiffinDepartmentId()` read it. Fixed by only clearing the
department when the newly-selected category is *not* Tiffin:
```php
if ($this->service_category_id != $tiffinId) {
    $this->tiffin_department_id = null;
}
```
Also removed the `@entangle()` bindings for `service_category_id` /
`tiffin_department_id` / `multiItemMode` / `inChargeSelection` in favor of
reading `$wire.propertyName` directly in `x-show` — entangle wasn't the
root cause here, but dual-binding the same property via both
`wire:model.live` and `@entangle` is unnecessary when Alpine only ever
*reads* the value, and removing it eliminates a class of similar races.

**Unrelated hygiene issue found during the same investigation:** two
independent `php artisan serve` processes were both bound to port 8000
(one left over from an earlier session), so requests were nondeterministically
landing on a stale process running old code. Killed both and started a
single fresh instance — worth checking `netstat -ano | grep :8000` first
if the browser ever seems to be running stale code despite a `view:clear`.

67 tests passing (13 new in `TiffinItemManagementTest.php`, the rest are
`JobEntryManagementTest.php`'s Tiffin batch/rate-history tests rewritten
against the simplified model). `migrate:fresh --seed` run twice
(idempotent). Verified with Playwright/Chromium: selecting a department
immediately shows all its items with editable quantity/cost/bill per item,
the full batch save round-trip producing correct `cost_amount`/
`bill_amount` per row in the database, the new `/tiffin-items` page at
desktop/mobile (390×844)/dark mode, the DOM shell-duplication check, and a
grep confirming `.blur` was never reintroduced.

**Known gap, not introduced by this change:** success flash messages
(`session()->flash('status', ...)`) are set after every save/delete across
the whole app (Companies, Job Entries, Tiffin Items) but the main layout
has no banner that actually displays them — only the auth pages render
`session('status')`. Pre-existing since the Companies feature; worth a
follow-up if the client wants save confirmations visible.

## Invoices / monthly billing (done)

The layer that turns a month of Job Entries into an actual bill: pick a
company + month, pull every unbilled entry in that range, group it by
service category with subtotals, and produce a document with an invoice
number and a Due/Paid status. Sidebar's Invoices link is now real (Bill
Statement stays "soon" — reserved for a possible future aging/overdue
report, not assumed to be the same screen).

- `invoices` table (`company_id`, `invoice_number` unique, `period_start`/
  `period_end`, `status` due\|paid, `paid_at`, `created_by`) +
  `job_entries.invoice_id` (nullable, `nullOnDelete`) — exactly the column
  the `job_entries` migration had reserved with a comment since Phase 1.
  A job entry starts unbilled and gets attached to exactly one invoice.
- **No uniqueness constraint on (company, period).** Generating only ever
  pulls entries still `invoice_id IS NULL` in the chosen range, so a late
  entry added after a month was already billed just produces a second,
  smaller invoice for that same month instead of blocking or double-
  billing anything — `invoice_number` gets a `-2`/`-3` suffix
  (`nextInvoiceNumber()`) only when that exact base number is already
  taken, keeping it globally unique without over-constraining the
  business rule.
- **Billed entries become immutable.** `JobEntry::isBilled` (an accessor,
  `invoice_id !== null`) gates both `job-entry-form.blade.php` (`mount()`
  redirects away with a flash error if the entry is already billed) and
  `job-entry-list.blade.php` (Edit/Delete buttons replaced with a "Billed"
  badge, and `delete()` re-checks `whereNull('invoice_id')` server-side —
  never trust the hidden button alone, same discipline as every other
  guard in this app).
- Three Volt components: `invoice-generate-form` (company + month picker,
  live preview of unbilled count/total via `with()`, a heads-up if a prior
  invoice already exists for the same period without blocking generation),
  `invoice-list` (mobile-first cards, company/status filters,
  `withSum('jobEntries as total_bill_amount', ...)` for the list total
  without N+1), `invoice-detail` (letterhead from the `Company` record,
  entries grouped by `service_category_id` ordered by `sort_order` with a
  subtotal per group, Mark as Paid/Due toggle, Delete blocked server-side
  once `status = paid`).
- **Print-safe by construction, not by convention**: cost/profit figures
  are real on-screen (for the admin) but carry Tailwind's `print:hidden`
  variant, so `window.print()` on the invoice page — likely how this
  actually gets sent to a factory — produces a document with quantity,
  rate, and bill amount only, never your own margin. Verified by
  rendering the page under emulated print media and asserting the cost
  text isn't visible (not just absent from a screenshot).
- Fixed the long-standing **known gap** noted in the previous section as
  a natural side effect of this feature needing a visible error message:
  `layouts/app.blade.php` now renders `session('status')` (green) and
  `session('error')` (red) banners above the page content — every prior
  silent flash message across Companies/Job Entries/Tiffin Items becomes
  visible for free.

77 tests passing (10 new in `InvoiceManagementTest.php`, 2 new in
`JobEntryManagementTest.php` for the billed-entry guards). Verified with
Playwright/Chromium: generate an invoice against the seeded demo month
(27 entries, matches the manually-summed total), confirm a billed entry
shows "Billed" and can't be edited, confirm the print view hides the
sidebar/nav *and* the cost column while keeping Grand Total visible
(checked via emulated print media + `isVisible`, not text presence — the
same mistake almost slipped through here, since `textContent` ignores
`display:none`), mobile (390×844) and dark mode on the detail page.

## Daily Summary (done)

Client's ask: "so when he clicks the date he'll be able to see everything
under a company" — pick a company, see every day that has activity, click
a day to see everything supplied that day across all categories in one
place. A pure read-only reporting view, deliberately not tied to invoicing
(`invoice_id` is irrelevant here — a day can span multiple invoices'
periods, or none yet).

- Two routes: `/daily-summary` (list) and
  `/daily-summary/{company}/{date}` (detail), `date` constrained to
  `\d{4}-\d{2}-\d{2}` via `->where()`. `{company}` uses normal route-model
  binding; `date` stays a plain string parsed with `Carbon::parse()`
  rather than a model.
- **List**: select a company, then every distinct `entry_date` for that
  company (grouped in PHP via `groupBy(fn ($e) => $e->entry_date->toDateString())`
  rather than a `DATE_FORMAT()`/`strftime()` raw SQL — the project's tests
  run on SQLite while production is MySQL, and this keeps the query
  portable across both, same lesson as `invoice-generate-form`'s month
  dropdown two features earlier) with entry count and total bill amount,
  newest first, linking to the detail page.
- **Detail — explicitly requested "sheet reference style"**: this is the
  one screen in the app that renders as an actual `<table>` instead of
  cards, because the client wants it to read like their original Excel
  day-sheet. Category header rows, one row per entry (item, quantity, cost
  rate, bill rate, cost, bill, profit), a subtotal row closing each
  category, a grand-total `<tfoot>` row. Wrapped in `overflow-x-auto` with
  `min-w-[720px]` on the table so it scrolls horizontally on mobile rather
  than breaking — a deliberate, narrow exception to this app's card-first
  mobile pattern, scoped to only this one genuinely tabular screen.
  Billed entries still show their green "Billed" badge inline, so this
  view also works as a quick "has this day been invoiced yet" check.
- New sidebar entry "Daily Summary", placed between Job Entries and Tiffin
  Items.

80 tests passing (3 new in `DailySummaryTest.php`). Verified with
Playwright/Chromium against the seeded demo data: the list's per-day
totals match a manual sum, the detail page's category subtotals and grand
total are correct, desktop/mobile/dark mode all render the table
correctly (mobile confirmed scrollable rather than clipped-with-no-escape).

## Job Entries list redesign + Bangladesh timezone (done)

Client feedback: the Job Entries list ("home page" for daily data entry)
was confusing — every entry rendered as a heavy card (company name
repeated on every one, three big Cost/Bill/Profit boxes) with all 8
categories interleaved in one flat chronological list, so a page of 10
entries could be 3+ screens of near-identical-looking cards.

- Entries are now grouped under a date heading (`l, d M Y`, e.g.
  "Saturday, 29 Aug 2026") computed once per page of results
  (`collect($jobEntries->items())->groupBy(...)`) rather than repeated
  per card. Each entry collapsed to one compact row: category badge +
  item name on the first line, company/buyer/style/floor/challan/cost/
  profit as small muted context on the second, Bill amount + Edit/Delete
  text links on the right — the same information as before, a fraction of
  the vertical space. Company name is only shown when the company filter
  isn't already narrowing to one (redundant otherwise).
  Pagination still slices by raw row count (10/page), so a date can in
  principle split across a page boundary and its heading repeat — same
  accepted tradeoff already noted for Daily Summary, not worth solving
  here since Daily Summary exists as the unbounded per-day view.
- Removed the "Billed" badge everywhere (list and Daily Summary) per
  explicit request — the underlying protection is unchanged: Edit/Delete
  still disappear and `delete()` still re-checks `whereNull('invoice_id')`
  server-side, only the visual badge is gone.

**App now runs on Bangladesh time, not UTC** — the client's business
operates there, so every `now()`/date default should reflect it.
`config('app.timezone')` is `Asia/Dhaka`; `config/database.php`'s `mysql`
connection also got `'timezone' => '+06:00'` so MySQL's own `NOW()`/
`CURDATE()` agree with PHP instead of silently drifting (the exact class
of bug already documented earlier this session under UTC-vs-local
mismatches). A fixed offset was used for the DB connection rather than
the IANA name because MySQL's named-timezone support depends on the
`mysql.time_zone_name` tables being loaded, which isn't guaranteed on
this WAMP install — Bangladesh has no DST, so `+06:00` never needs to
change. Recorded as a rule in `.ai/rules/config.md` so this pairing is
never accidentally split again. Verified via tinker that `now()` and
`SELECT NOW()` return the identical timestamp.

80 tests passing, no regressions from either change.

## Tiffin merged into one card + whole-batch edit + bill rate shown (done)

Follow-up client feedback on the just-redesigned Job Entries list: Tiffin
should read as one entry regardless of department (Swing and Wash Worker
merged, not two separate cards), the bill rate per item should be visible
(not just totals), and — since editing only worked one item at a time —
a whole Tiffin order should be editable together in one action, matching
how it was created.

- **Merge**: the list's grouping key for Tiffin entries dropped
  `tiffin_department_id` down to a sub-grouping concern —
  `"tiffin-{$entry->company_id}"` groups Swing and Wash Worker together
  under one card for the day, then `$group->groupBy('tiffin_department_id')`
  inside the template renders each department as its own labeled
  sub-section within that one card.
- **Bill rate**: every entry row (Tiffin item lines and the plain
  single-entry rows alike) now shows `Rate {{ bill_rate }}` alongside the
  existing Cost/Profit, so the per-unit rate charged to the factory is
  visible without opening Edit.
- **Whole-batch edit**: new route
  `job-entries/batches/{company}/{tiffinDepartment}/{date}/edit` and Volt
  component `tiffin-batch-edit-form` — a dedicated screen kept separate
  from the already-complex `job-entry-form` rather than overloading it
  further. `mount()` loads every `JobEntry` row for that exact
  company+department+day, pre-fills `quantities`/`costRates`/`billRates`
  keyed by item name (same dot-path array binding pattern as the create
  flow), and blocks the whole screen with a flash error + redirect if
  *any* item in the batch is already billed (an atomic batch shouldn't
  become half-billed/half-editable). `save()` loops the real Eloquent
  models (`JobEntry::findOrFail($id)->update([...])`, not a query-builder
  bulk update) specifically so the model's `profit_amount` recompute hook
  still fires — a bulk `update()` would have silently skipped it. Each
  department's "Edit" link (next to its sub-heading) points here; the
  per-item "Edit" link was removed in favor of it, per-item "Delete"
  stays for removing a single wrong line.
- Per-item Edit/Delete for non-Tiffin entries is unchanged.

83 tests passing (3 new: batch form loads pre-filled, saving updates every
item together and recomputes profit, editing is blocked once any item is
billed). Verified with Playwright/Chromium: the merged card correctly
shows Wash Worker and Swing as sub-sections of one "Tiffin" card, the
batch-edit link navigates to a form pre-filled with the department's
actual current values, and a stale test invoice from earlier Invoices
testing correctly hid Edit on already-billed data — confirming the
guard works — before re-seeding fresh to verify the edit flow itself.

## Company detail hub (done)

Client's ask, after thinking out loud about restructuring navigation
entirely around Company: "click a company, see everything we've done for
it, bill it properly." Rather than nesting Job Entries under Company (which
would cost the cross-company view the global Job Entries list already
gives for free — "everything Diesel this month across all clients" — for
a business meant to run several factories at once), added a **Company
detail page** as a hub that links out to the existing, already-filterable
screens, all pre-scoped to that company.

- New route `companies/{company}` (`companies.show`, registered *before*
  `companies/{company}/edit` so `{company}` never swallows a literal
  segment — same ordering discipline as every other parametered route in
  this app) + `company-detail.blade.php`: company info card, three stat
  cards (Total Entries, Unbilled amount + count, Lifetime Billed — all
  scoped with `where('company_id', ...)`, no cross-company leakage),
  quick-action buttons, and a compact "Recent Entries" table (last 10,
  sheet-style like Daily Summary) linking out to the full filtered list.
- The company list's "View" button (new, alongside existing Edit/Delete)
  opens this hub.
- **Quick actions reuse existing filters, zero new plumbing for three of
  four**: "Daily Summary" and "Invoices" links are plain
  `route(..., ['company' => $company->id])` calls — both target
  components already declare `#[Url(as: 'company')]` on their filter
  property, so Laravel appends it as a query string and Livewire's own
  URL-binding does the rest.
- **"New Entry" and "Generate Invoice" needed one line each**: those two
  forms don't have a company filter to reuse (they set `company_id` once
  at creation, no persistent filter concept), so each `mount()` gained
  `$this->company_id = request()->integer('company') ?: null;` — reads
  the query string directly rather than adding a Livewire `#[Url]`
  binding, since these are one-shot create forms where rewriting the URL
  on every category/company change (which `#[Url]` would do) isn't
  wanted.
- **Testing gotcha**: `Volt::test('component')` does not go through the
  real HTTP kernel, so `request()->integer(...)` inside `mount()` reads
  whatever the ambient test request happens to be — neither
  `request()->query->set(...)` nor swapping the bound `request()`
  singleton via `$this->app->instance('request', ...)` reached it. The
  reliable way to test a real querystring-driven `mount()` read is a
  genuine HTTP visit (`$this->get('/job-entries/create?company=5')`) and
  asserting against the page's embedded Livewire snapshot JSON — which is
  HTML-entity-encoded inside the `wire:snapshot` attribute
  (`company_id&quot;:5`, not `"company_id":5`) since it's serialized into
  an HTML attribute value, not raw script content.

86 tests passing (4 new). Verified with Playwright/Chromium: the hub
shows correct company-scoped totals, "New Entry" from the hub arrives
with the company already selected, mobile stacking confirmed.

## Tiffin combined on the Invoice detail too (done)

Same complaint as the Job Entries list, spotted on the Invoice detail
page: Tiffin still listed one row per item per day within the Tiffin
category group, instead of one row per day.

- `invoice-detail.blade.php`'s category loop now special-cases the group
  whose entries carry a `tiffin_department_id` (i.e. Tiffin): those get
  further grouped by `entry_date->toDateString().'-'.tiffin_department_id`
  so each department's daily order becomes one row ("Swing · 28 Aug",
  "Wash Worker · 29 Aug") listing its items with their own qty/rate/cost/
  bill underneath and a combined bill total for that row — the exact same
  grouping key already used for this on the Job Entries list, just applied
  here too rather than extracted into a shared helper (each view's
  `$entries` collection shape differs enough — job-entry-list pages
  fetched entries, invoice-detail has a full month in memory already —
  that duplicating the one-line `groupBy` was simpler than force-fitting
  a shared abstraction). Every other category is unaffected, unchanged.
- Needed `tiffinDepartment` added to invoice-detail's eager load
  (`->with(['serviceCategory', 'tiffinDepartment'])`) to render the
  department label without N+1 queries.

86 tests still passing (no new ones needed — existing invoice tests
already exercise multi-item Tiffin data through this page). Verified by
viewing a real generated invoice covering the seeded demo month: five
Tiffin day/department rows (Swing × 3 days, Wash Worker × 2 days), each
correctly totalling its three items instead of showing fifteen separate
item rows.

## Bill Statement (done)

Activated the last remaining "soon" placeholder that had real business
meaning: the original spreadsheet's own description ("bill added to the
Bill Statement... Paid or Due") plus a direct client question about
whether the Invoice page even needs a printable form. Given `/invoices`
already lists individual monthly bills, the useful *new* thing here is a
**running account statement per company** — every invoice ever issued to
one company in one ledger, so "how much do they currently owe us in
total" is a single glance instead of adding up several Invoice pages.

- `bill-statement.blade.php`: company picker (`#[Url(as: 'company')]`,
  same convention as every other filterable list this session) → a sheet-
  style table (Invoice / Period / Amount / Status / Balance Due) via
  `Invoice::withSum('jobEntries as amount', 'bill_amount')` ordered by
  `period_start`, with a `<tfoot>` of Total Billed / Total Paid / **Total
  Outstanding** (highlighted red when non-zero — the number that actually
  matters day to day). Balance Due per row is simply 0 for a paid invoice
  or its full amount for a due one — no partial-payment tracking exists
  in this app, so a true cumulative running balance would overstate what
  the data actually supports.
- Unlike the Invoice detail page, there's no cost/profit anywhere on this
  screen by construction — invoice numbers, periods, total amounts, and
  Paid/Due are the only figures a Bill Statement needs, so nothing needed
  `print:hidden` treatment here; the on-screen view already is the safe-
  to-print view.
- Sidebar's Bill Statement link is now real; the Company hub gained a
  fifth quick-action button alongside New Entry/Generate Invoice/Daily
  Summary/Invoices, same `route(..., ['company' => $company->id])`
  pre-scoping pattern as the other four.

90 tests passing (4 new): scoped totals render correctly, another
company's invoices never leak into this one's statement, and a paid
invoice's Balance Due is verified to be exactly `0.0` (via `viewData()`,
not `get()` — same `with()`-computed-value testing gotcha hit earlier
this session with Tiffin Items' `independentItemsByDepartment`). Verified
with Playwright: a real two-invoice statement (one paid, one due) shows
the correct running totals and highlights the outstanding balance.

## Invoices list retired into Bill Statement (done)

Client feedback, immediately after using Bill Statement for the first
time: it and the standalone Invoices list felt like the same page, and
generating a new invoice should happen *from* the statement you're
already looking at, not a separate flow. Correct call — Bill Statement
(per company) already showed everything the Invoices list did, plus the
running totals it didn't.

- Added a **"Generate Invoice" button directly on Bill Statement**
  (top-right, and again inside the empty state when a company has no
  invoices yet) — links to the existing `invoices.create` form
  pre-filled with the already-selected company via the `?company=` query
  param built earlier, so nothing about the generation form itself
  needed to change.
- **Retired `invoices.index`** (the standalone list) rather than
  deleting it outright: the route now just
  `redirect()->route('bill-statement.index')`, kept under its original
  name specifically so it still sits behind the `auth,verified`
  middleware group — an unauthenticated hit still bounces to `/login`
  first, exactly like before, so the existing guest-redirect test needed
  no changes. Deleted the now-truly-dead `invoice-list.blade.php`
  component and its `invoices/index.blade.php` wrapper, since nothing
  else referenced them once the sidebar link was removed.
- Every place that used to point at `invoices.index` now points at
  `bill-statement.index` instead, company-scoped where the context has
  one: the sidebar (single "Bill Statement" entry, `:active` now matches
  both `bill-statement.*` and `invoices.*` so viewing an invoice detail
  still highlights the right nav item), the Company hub (dropped the
  redundant "Invoices" button, kept "Bill Statement"), the generate
  form's Cancel button, and Invoice Detail's delete-redirect (captures
  `company_id` *before* the transaction deletes the row, since attribute
  access after `->delete()` is fragile to rely on).

90 tests still passing (existing coverage already exercised the redirect
target, just updated to the new destination). Verified with Playwright: a
visit to the old `/invoices` URL lands on `/bill-statement`, the sidebar
shows no separate "Invoices" entry, and "Generate Invoice" on the
statement page correctly opens the pre-filled create form.

## Dashboard rebuilt into a real home screen (done)

Client pushback on the dashboard as it stood: "not like this, think you
are a owner of the Rezia Enterprise, how would you make an app that
solves your workflow efficiently." The old dashboard was two stat cards
(Total/Active Companies) and a "Getting Started" checklist that still
referenced Rate Cards — a feature removed long before this session. None
of it answered the two questions an owner actually opens the app to ask
each morning: *who can I bill right now*, and *what got logged today*.

- **Stat cards** replaced Total/Active Companies with Unbilled (sum +
  count of every entry still `invoice_id IS NULL`), Outstanding (Due)
  (sum of unpaid invoices' `bill_amount` across every company, via
  `withSum('jobEntries as amount', ...)`), and This Month (current
  calendar month's total billed activity, a pulse indicator). Total/
  Active Companies didn't disappear — they moved to a small footer line,
  since they're context, not the headline.
- **"Ready to Invoice"** section: unbilled entries grouped by company in
  PHP (`Collection::groupBy('company_id')`, not a raw SQL `GROUP BY` —
  same MySQL/SQLite portability reasoning already applied to the invoice
  generate form's month dropdown), sorted by total descending, each row
  linking straight to `invoices.create?company=X` via the `?company=`
  prefill mechanism built earlier this session. This is the single most
  actionable section on the page — it turns "go check every company" into
  a ranked to-do list.
- **"Today's Activity"** section: every job entry logged today, across
  all companies, in the same sheet-style table used on Daily Summary and
  Bill Statement — an at-a-glance confirmation of what's already been
  entered without needing to click into Job Entries.
- **"New Entry"** is now a persistent header button — the single most
  frequent action gets a one-tap path from the home screen, not buried
  under Job Entries.
- Retired the stale "Getting Started" checklist entirely.

Rewrote `tests/Feature/DashboardTest.php` from scratch (the old two tests
asserted on the now-deleted "Total Companies" / "Add your first company"
copy): 6 tests covering unbilled totals and grouping, already-invoiced
entries correctly excluded, outstanding sums only `due` invoices (not
`paid`), today's activity lists across companies, and the empty states
for a quiet day / fully-invoiced company. 93 tests passing overall.
Verified with Playwright (desktop, mobile, dark mode): stat cards, Ready
to Invoice, and Today's Activity all render correctly against the seeded
demo data; confirmed the Today's Activity table scrolls horizontally
within its own container on mobile without the page itself gaining a
horizontal scrollbar.

## Bill Statement now defaults to every company (done)

Client feedback: opening Bill Statement should show every bill (paid and
unpaid) across every factory right away, not force a company pick first;
selecting a company should then narrow the same view down to just that
factory.

- `with()` no longer returns an empty collection when `companyFilter` is
  blank — it now always queries `Invoice::with('company')`, applying the
  `company_id` filter conditionally via `->when(...)`. Totals (Billed/
  Paid/Outstanding) are computed the same way in both states, so they
  correctly reflect either "everything" or "just this factory."
- The table gained a **Company** column, shown only when no filter is
  applied (`@unless ($selectedCompany)`) — redundant once a single
  factory is selected, so it's dropped in that view instead of repeating
  the same name down every row. Footer `colspan`s adjust accordingly.
- The header card reads "All Companies" when unfiltered, the company's
  name when filtered.
- "Generate Invoice" only shows once a specific company is selected
  (it needs one to generate for); "Print" is available in both states.
- The old "Select a company" empty state is gone — an empty result now
  always means "no invoices yet" (worded generically when unfiltered,
  company-specific with a Generate Invoice shortcut when filtered).

Added a test confirming invoices from multiple companies appear together
with correct combined totals when unfiltered. 94 tests passing. Verified
with Playwright: the default view lists every invoice across companies
(mixed paid/due), picking a company narrows to just its bills and drops
the Company column, and the mobile view scrolls the table within its own
container without the page gaining horizontal scroll.

## Bill Statement shows a month's activity as a bill before it's invoiced (done)

Follow-up client feedback: on a fresh install with zero invoices ever
generated, Bill Statement showed "No invoices yet" — technically correct,
but not what the client meant by "all the bills." Their mental model:
a month of logged activity for a factory *is* the bill; clicking
"Generate Invoice" is just the paperwork step for something already
true, not the thing that makes it a bill in the first place.

- `with()` now builds two kinds of rows and merges them: real `Invoice`
  rows (unchanged), plus **pending rows** — unbilled job entries
  (`invoice_id IS NULL`) grouped by company + calendar month in PHP
  (`groupBy` on the `entry_date` collection, not a raw SQL `GROUP BY` —
  same MySQL/SQLite portability rule applied everywhere else this
  session). A pending row carries no invoice number and gets a "Not
  Invoiced" badge instead of Paid/Due.
- **Totals redefined accordingly**: Total Billed is now the value of
  *all* business activity (invoiced + pending), Total Paid is unchanged
  (only actual paid invoices), Total Outstanding = Billed − Paid — so it
  correctly represents everything still owed, whether or not a formal
  invoice document exists for it yet.
- The Invoice column shows a **"Generate Invoice" link** in place of an
  invoice number for pending rows, deep-linked to
  `invoices.create?company={id}&period={Y-m}` — one click from a pending
  row straight to a pre-filled, ready-to-submit form. This required
  teaching `invoice-generate-form`'s `mount()` to also read a `period`
  query param (previously only `company` was prefillable), validated
  against the `Y-m` format so a malformed value is silently ignored
  rather than crashing the form.
- On print, the pending row's link is replaced with a plain "Not
  invoiced" label (`print:hidden` / `print:inline` pair) — a printed
  statement shouldn't carry an interactive "generate" affordance.
- Removed the now-inaccurate "No invoices yet" empty state; it's "No
  billing activity yet" and only appears when there's truly nothing
  logged (no entries and no invoices) for the current filter.

5 new tests: a pending month renders as "Not Invoiced" with the right
total and correctly feeds Total Outstanding; the Generate Invoice link's
href carries the right company + period; a month that already has an
invoice does not also show as pending; plus two `CompanyManagementTest`
cases covering the new `period` query-param prefill (valid and
malformed). 99 tests passing. Verified with Playwright end-to-end against
the demo seed data (which deliberately ships with zero invoices): Bill
Statement immediately shows Ananta Apparels Ltd's full month of activity
as "Not Invoiced," and clicking through lands on the generate form with
both company and month already selected.

## Service Categories became a manageable module (done)

Client question, prompted by the Company form's "Which services does this
company subscribe to?" checklist: could a brand new service category
(the way Tiffin already works) be added without a developer? Turned out
the data layer was already dynamic — `ServiceCategory` is a real table,
and the Company form and Job Entry category dropdown both already query
it live — but there was no UI to create one. The only way in was editing
`ServiceCategorySeeder.php` by hand.

- New module at **`/service-categories`** (`service-categories.category-
  manager` Volt component), same CRUD-with-modal shape as the Tiffin
  Items departments/catalog managers: add/edit (name, invoice code, unit
  label) and reorder (move up/down, swapping `sort_order` — the same
  field that already drives the Company form and Job Entry dropdown
  ordering). Delete is blocked, with a friendly message, if any company
  is subscribed to the category or any job entry uses it — checked
  server-side, mirroring the Tiffin Department guard.
- Each row shows how many companies are subscribed and how many job
  entries exist, so it's obvious before deleting whether a category is
  actually free to remove.
- **What "dynamic" does and doesn't cover**: a newly added category is
  immediately selectable on the Company form and, once a company is
  subscribed, in the Job Entry form — using the generic Supply Type +
  Quantity + Cost Rate + Bill Rate flow that already applies to any
  category. Five existing categories (Tiffin, Embroidery & Print,
  Loading Unloading, Diesel Oil Supply, ETP Eid Holiday) additionally
  get bespoke extra fields in the Job Entry form, but that wiring is
  looked up by exact category *name* in code
  (`$categoryIds['Tiffin'] ?? null`, etc.) — a genuinely new kind of
  field still needs a developer, and this was made visible rather than
  silently fragile: each of those five rows carries a "Has custom
  fields" hint on the new management screen, explaining that renaming
  the category would disconnect its custom fields.
- **Tiffin's own item/department management moved to live "under"
  Service Categories** rather than sitting alongside it as an unrelated
  sidebar entry, since it's really per-category configuration, not a
  separate concern: removed the standalone "Tiffin Items" sidebar link
  (folded into "Service Categories", whose `:active` state now also
  matches `tiffin-items.*`), added a "Manage Items" button on the Tiffin
  row that opens it, and gave the Tiffin Items page a "Back to Service
  Categories" header link since it's no longer a top-level destination.
- Company form gained a small "Manage categories" link next to the
  checklist, so the question that started this ("which services does
  this company subscribe to") leads straight to where new ones get
  added.
- Also added a small **"Beta" badge** next to the app name in the
  sidebar header (every authenticated page) at the client's request, so
  it's visible the product is still under active development.

8 new tests in `ServiceCategoryManagementTest.php`: create/edit, a newly
created category is immediately selectable on the Company form and
persists the subscription, name/invoice-code uniqueness, delete blocked
by a company subscription, delete blocked by a job entry, an unused
category deletes cleanly, and reordering swaps `sort_order` correctly.
107 tests passing. Verified with Playwright: added "Security Guard
Supply" end-to-end through the new screen and confirmed it appeared
immediately in the Company form's checklist with zero code changes,
checked dark mode and mobile (no horizontal scroll), and confirmed the
Tiffin row's "Manage Items" link and the sidebar's merged active state
both work.

## Tiffin's per-item rows collapsed everywhere they'd leaked back in (done)

Client clarification: Tiffin is billed to a factory as one package per
department per day — not priced per ingredient — but four screens were
still showing Banana/Egg/Bread as separate rows (that data shape is
correct and deliberately unchanged — "job entries should stay same" —
this was purely a display problem). The Job Entries list and the
original Invoice detail grouping had already solved this pattern once
this session; the same root cause had just resurfaced in every screen
built *after* that fix, since each queries `JobEntry` directly rather
than reusing that grouping.

Applied the identical fix (group by company + Tiffin department + day,
sum `bill_amount`, drop the per-item breakdown) to every place it had
recurred:

- **Dashboard "Today's Activity"** (`routes/web.php`): grouped in the
  route closure before handing rows to the view; the Item column shows
  the department name instead of the ingredient.
- **Company hub "Recent Entries"**: same grouping, but since collapsing
  rows means fewer *visible* rows for the same fetch size, widened the
  raw query from 10 to 30 entries before grouping and taking the latest
  10 groups — otherwise a busy Tiffin day could silently crowd out every
  other category from the "recent" list. Its Edit link now points at
  the Tiffin batch-edit route for grouped rows (matching Job Entries
  list) instead of a single entry's edit page, and "Billed" now shows if
  *any* item in the group is billed, not just the first one queried.
- **Invoice detail**: went further than the earlier "one row per
  department per day" grouping — that fix still listed each item
  underneath with its own qty/rate/cost. Removed that itemized
  breakdown entirely; the invoice a factory receives now shows just the
  department, date, and total, matching how the business actually bills
  it. The line-level detail still lives on the Job Entries screen for
  internal audit.
- **Daily Summary detail**: within the Tiffin category section, one row
  per department instead of one per item; Cost Rate/Bill Rate show "—"
  (a per-unit rate isn't meaningful once summed across different-priced
  ingredients) while Quantity and Cost/Bill/Profit are proper sums —
  client follow-up: Quantity initially also showed "—", but the client
  wanted the combined item count (Banana + Egg + Bread pieces) visible
  after all, so that one was changed to `$batch->sum('quantity')`.

All four keyed the grouping off `tiffin_department_id` being present
(not the category name), matching the existing convention — an ad-hoc
Tiffin entry with no department (rare, but the factory/seed data
occasionally has one) still renders itemized, since there's nothing to
group it under.

4 new tests, one per screen, each asserting the department name and
combined total are present and no separate per-item dollar amounts
remain. 111 tests passing. Verified with Playwright against real seeded
data: Today's Activity dropped from 6 Tiffin rows to 2 ("Swing", "Wash
Worker"), the Company hub's Recent Entries showed the same collapse
(surfacing two older entries that ingredient-level rows had been
crowding out), the invoice showed clean department-only Tiffin lines
alongside fully itemized rows for every other category, and Daily
Summary matched.

**Follow-up, same conversation**: two client refinements to the
collapsed row rather than a return to separate rows —
1. Daily Summary's Quantity column had shown "—" for the collapsed
   Tiffin row (summing quantities across different ingredients felt like
   a meaningless number at the time); the client wanted it back as the
   combined total after all, so it's now `$batch->sum('quantity')`, same
   treatment as Cost/Bill/Profit.
2. All four screens gained a small muted "— Banana, Bread, Egg"-style
   label beside the department name, naming which items are actually in
   that day's package without going back to one row per item. Sourced
   live from `pluck('supply_type')->unique()->sort()` on the same
   entries already being summed — not a hardcoded ingredient list, so it
   automatically reflects whatever a department's recipe actually is,
   including future changes made in Service Categories → Tiffin →
   Manage Items. The `->sort()` matters: plucking straight from an
   `orderByDesc('id')` collection listed the most-recently-entered item
   first, which read as arbitrary and made two tests intermittently
   fail on ordering.

Updated the same 4 tests to assert the item-name label now appears
(`'Banana, Egg'`) instead of asserting it's absent, plus the Daily
Summary test now feeds distinct quantities and asserts their sum. All
111 tests still pass.

## Tiffin entry covers every department in one submission (done)

Client observation: creating a Tiffin job entry only let you pick one
department (e.g. Swing) at a time — logging Wash Worker's tiffin for the
same day meant starting a whole second "New Entry" from scratch. Fixed
by reshaping the create flow, not the data: each item is still its own
`JobEntry` row exactly as before ("job entries should stay same" was the
explicit constraint) — only how many departments one form submission can
cover changed.

- `job-entry-form.blade.php`'s Tiffin section no longer asks "which
  department?" before showing items. Once Tiffin is selected, it shows
  **every department the company is assigned**, each as its own card
  with quantity/cost/bill inputs per item (rates pre-filled from the
  last entry, same as before) — Swing and Wash Worker side by side,
  fillable in one visit.
- A department left completely untouched is silently skipped rather than
  blocking submission — "just Swing today" still works exactly as
  before. But once a department has *any* value entered, all of its
  items become required, same strictness the single-department flow
  already had; this is enforced by computing which departments were
  "touched" before building validation rules, so an empty Wash Worker
  section never generates rules that would block Swing's submission.
  One click creates entries for every touched department at once.
  Company + category selection triggers a normal Livewire round-trip
  already, so no new client-side machinery was needed to swap between
  companies with different department counts.
- State shape changed from flat (`batchQuantities['Banana']`) to nested
  by department (`batchQuantities[$departmentId]['Banana']`), since the
  same item name can now appear in two department sections on screen at
  once.
- The old single-department dropdown + item picker didn't disappear —
  it's kept, scoped to **editing** only, since that path is only ever
  reached for a legacy Tiffin entry with no department set (normal
  Tiffin edits already go through the dedicated batch-edit screen, per
  the Job Entries list's per-department Edit link). Create and edit now
  render genuinely different markup for this section (`@if ($jobEntry)`
  branching in the template) rather than trying to force one Alpine
  expression to cover both shapes.
- Edge case handled explicitly rather than silently: a company subscribed
  to Tiffin with zero departments (or none with a configured recipe) now
  shows a message pointing at Service Categories → Manage Items instead
  of a dead-end empty form — the primary Save button is hidden for that
  specific state (`create + Tiffin + no departments`) since there would
  be nothing valid to submit.

Rewrote the Tiffin tests in `JobEntryManagementTest.php` for the new
shape (7 tests, up from 3): both departments visible at once, filling in
two departments in one submission creates entries for both, leaving one
department blank skips it without blocking the other, per-item
requiredness once a department is touched, an all-blank submission is
rejected with a clear error, and rate auto-fill now scoped correctly per
department. 114 tests passing. Verified with Playwright: selecting
Tiffin for Ananta Apparels Ltd shows Swing and Wash Worker simultaneously
with pre-filled rates; filling in only Wash Worker and saving created
exactly those 3 entries ("Tiffin entries created (3)") while leaving
Swing's existing data untouched.

**Follow-up, same conversation**: Daily Summary's Tiffin rows still
showed "—" for Cost Rate and Bill Rate (a single per-unit rate isn't
well-defined once items with different prices are combined into one
row). Client wanted them filled rather than blank, so both now show the
**blended average** — total Cost ÷ combined Quantity, total Bill ÷
combined Quantity — the same relationship those columns already have
for every other row, just computed instead of stored. Added a `title`
tooltip on each noting it's a blended average across N items, and a
dedicated test with clean numbers (Cost 1000+3000 over Qty 200+200 =
10.00; Bill 1200+3600 over the same = 12.00) confirming the exact blend
math rather than an approximate string match. 115 tests passing.

## Professional, per-category invoices matching the client's real paper bills (done)

The client shared 7 real invoices (`invoice-reference/`) showing how
Rezia Enterprise actually bills factories by hand: a letterhead
document, a Ref number, a "To the Managing Director" block, a "Sub: Bill
For {thing} Month Of {Month Year}" line, a dated table, a total, the
amount spelled out in words ("Three Lakh Eighteen Thousand ... Taka
Only"), and a signature footer — critically, **one invoice per service
category**, not one invoice bundling everything. This was the biggest
change of the project so far: invoice generation moved from
company+month to company+category+month, and the invoice document was
rebuilt from an in-app summary card into an actual printable bill.

Confirmed with the client before building: Tiffin stays **one** invoice
per month covering every department (not split like the paper
reference); VAT is **optional**, off by default, a checkbox at
generation adds a flat 10% line.

- **`invoices` gained `service_category_id`** (required FK) and
  `vat_percent` (nullable decimal — `null` means no VAT line, a value
  means show one at that rate). `Invoice::serviceCategory()` added.
- **Generation** (`invoice-generate-form.blade.php`) gained a required
  Category select between Company and Month (from
  `$company->serviceCategories()`, same relation the Company form's
  checklist already uses) and a VAT checkbox. The unbilled-entries query
  is now scoped by category too — generating Tiffin can never sweep up
  an unbilled Diesel entry from the same month.
- **`invoice_number` *is* the client-facing Ref number now**, not a
  separate internal code:
  `RE/{CompanyCode}/{CategoryInvoiceCode}/Bill#{n}/{MMYYYY}` (e.g.
  `RE/AAL/TIF/Bill#1/082026`), reusing `ServiceCategory.invoice_code`
  (already a user-editable field) as the category segment. `{n}` is a
  running count of invoices for that exact company+category.
- **New `app/Support/NumberToWords::taka()`** — spells out an amount
  using **Bangladeshi/Indian numbering** (Lakh = 100,000, Crore =
  10,000,000), not Laravel's own Western million/billion grouping.
  Verified against real totals from the reference invoices (318,552 →
  "Three Lakh Eighteen Thousand Five Hundred Fifty Two", etc.) — every
  sample matched exactly. Unit-tested directly (`tests/Unit/
  NumberToWordsTest.php`), no package needed.
- **New `config/company.php`** holds Rezia Enterprise's own letterhead
  details (name, tagline, phones, email, address, invoice prefix) with
  `.env` overrides — kept out of the Blade view so a future deployment
  of this app for a different business only needs to change config/env
  values to relabel the whole invoicing system, not touch templates.
- **`invoice-detail.blade.php` rebuilt** around a single category (the
  "group by category" loop is gone — an invoice only ever has one now):
  letterhead header (logo + `config('company.*')`), Ref/Date, To block,
  Sub line, a Date/Description/Quantity/Rate/Amount table (Tiffin rows
  reuse the department+day grouping and the blended-quantity/rate math
  already built for Daily Summary; other categories are one row per
  entry with buyer/floor/challan detail folded into the description),
  Total → VAT line (if any) → Grand Total → Advance Paid/Due (if any
  entry carries `company_adv_payment` — checked by data, not category
  name, so it's not hardcoded to ETP Eid Holiday even though that's the
  only category using it today) → the amount in words → a signature
  footer. Cost/profit moved into a small `print:hidden` card above the
  document instead of being interleaved with it, since the letterhead
  itself must never carry either, even on-screen.
- **Bill Statement and Dashboard's "Ready to Invoice" became
  category-aware**, since a company can now have several concurrent
  pending bills the same month (one per category) instead of one:
  pending-row grouping keys on company+category+month, Bill Statement
  gained a Category column, and both screens' "Generate Invoice" links
  now carry `&category=`.
- **Follow-up fix found during Playwright verification, not planned
  up front**: Bill Statement's "Balance Due" was still showing an
  invoice's full billed amount even when an advance had already been
  paid against it (visible immediately on the ETP Eid Holiday sample —
  100,000 billed, 50,000 already advanced, but Balance Due still read
  100,000). Fixed by summing each invoice's `company_adv_payment` via
  `withSum` and computing `balanceDue = max(0, amount − advancePaid)`
  per row (0 for paid invoices) instead of assuming Balance Due always
  equals the billed amount; `totalOutstanding` now sums each row's own
  `balanceDue` rather than `Total Billed − Total Paid`, which had the
  same blind spot. The "Amount" column also now includes VAT where
  applicable, so it matches what the invoice document itself shows as
  billed.

15 new/rewritten tests across `InvoiceManagementTest.php` (new Ref
number format, category isolation, VAT storage, the words line, the
VAT/Grand Total math, the Advance Paid/Due math), `BillStatementTest.php`
(two categories → two pending rows, the advance-aware balance due), and
`tests/Unit/NumberToWordsTest.php` (7 cases including a crore-scale
amount and fractional rounding); every existing `Invoice::create(...)`
across 4 test files updated for the new required column. 129 tests
passing. Verified with Playwright against real seeded data: generated a
Tiffin invoice and matched its Ref number, department lines, and amount-
in-words exactly against the paper reference's format; generated a
Diesel invoice with VAT checked and confirmed the VAT line and Grand
Total math; generated an ETP Eid Holiday invoice and confirmed the
Advance Paid/Due breakdown; confirmed the print view (emulated print
media) shows only the clean letterhead document — no sidebar, no admin
controls, no cost/profit; dark mode and mobile (no horizontal scroll)
checked on the invoice document and the generation form.

**Follow-up, same conversation**: the VAT checkbox generated a fixed
10% and nothing else, but the client's real rates vary (7%, 10%, 15%,
...) depending on the job. Replaced the checkbox with a plain "VAT Rate
(%)" number field on the generate form — blank means no VAT (unchanged
default), any value 0–100 is stored as-is on `vat_percent`. No schema
change needed since `vat_percent` was already a decimal column, not a
boolean — the invoice document's VAT line already rendered whatever
rate was stored (`VAT ({{ vat_percent }}%)`), so it needed no changes
either. Updated the generate-form tests for the new field name and
added coverage for an out-of-range rate (>100) being rejected and a
blank rate correctly storing no VAT. 131 tests passing. Verified with
Playwright: entered 15% on a Diesel Oil Supply invoice and confirmed
VAT 6,075.00 / Grand Total 46,575.00 (40,500 × 1.15) on the rendered
document.

**Follow-up, same conversation**: the `RE/AAL/TIF/Bill#1/082026`-style
invoice number read as noisy to the client. Replaced it with a plain
hyphen-joined `{CompanyCode}-{CategoryInvoiceCode}-{YYYYMM}` (e.g.
`AAL-TIF-202609`), matching the pre-per-category convention
(`AAL-202608`) with just the category code folded in so two categories
billed the same company/month still get distinct, meaningful numbers
instead of colliding into an arbitrary `-2` suffix. Removed the
now-unused `company.invoice_prefix` config value (the "RE/" segment)
since nothing reads it anymore. Updated the two generation tests
asserting the old format. 131 tests passing. Verified with Playwright —
also surfaced that the running `php artisan serve` process was serving
a stale compiled version of the component after the edit; killing it
and starting a fresh one picked up the change immediately, confirming
it was a stale-process artifact rather than a real bug (worth checking
`netstat` for a stuck server before trusting a "nothing changed"
observation during verification, same as the stale-duplicate-process
issue seen earlier in the project).

## Check payment details on Mark as Paid (done)

Client pays are settled by check, and the client wanted that recorded on
the invoice: check number, bank name, a screenshot of the check itself,
and a free-text description for anything else worth noting.

- `invoices` gained `check_number`, `bank_name`, `check_image_path`
  (nullable strings) and `payment_description` (nullable text).
- **"Mark as Paid" now opens a modal** instead of acting instantly —
  `confirmMarkPaid()` pre-fills the four fields from whatever was
  recorded last time (so a due→paid→due→paid correction doesn't lose
  prior details) and opens it; `markPaid()` validates (all four fields
  optional — a cash or bank-transfer payment can still be marked paid
  with nothing filled in) and stores them, uploading the screenshot via
  Livewire's `WithFileUploads` to the `public` disk under
  `check-screenshots/`. Ran `php artisan storage:link` so uploaded
  images are actually reachable — wasn't set up yet in this project.
  "Mark as Due" stays a single click and does *not* clear the stored
  payment fields, so switching back to Paid later re-shows them instead
  of starting over.
- A new **"Payment Details" card** (admin-only, `print:hidden`, sits
  above the printable letterhead) shows the four fields once paid —
  check number, bank name, description, and the screenshot as a
  clickable thumbnail linking to the full-size image. This is
  deliberately kept off the printable document itself; it's Rezia
  Enterprise's own bookkeeping, not something a factory needs to see.

4 new tests: full check details round-trip and persist (using
`Storage::fake('public')` + `UploadedFile::fake()->image(...)`, asserting
the file actually lands on disk); marking paid with zero details still
works (covers non-check payments); the modal pre-fills from a
previously-recorded payment. 134 tests passing. Verified with Playwright
end-to-end: opened the modal, filled in a check number, bank name, and
description, uploaded one of the client's own reference JPEGs as the
screenshot, marked paid, and confirmed the Payment Details card rendered
all four correctly with a working thumbnail.

## Partial payments (done)

Client follow-up, immediately after the single-payment check feature:
factories sometimes pay an invoice off across two or more checks over
time, and the previous design (one set of check fields directly on the
invoice, a manual Paid/Due toggle) couldn't represent that — a second
payment would just overwrite the first's details, and there was no
"partly settled" state at all. This replaces that with a proper
`invoice_payments` table and a derived status, so status is never set by
hand again — it's always computed from what's actually been paid.

- **New `invoice_payments` table**: `invoice_id`, `amount`, `paid_on`,
  `check_number`, `bank_name`, `check_image_path`, `description`,
  `created_by`. `Invoice` gained a `payments(): HasMany`. The single-set
  check fields added on `invoices` last turn were dropped in a follow-up
  migration — superseded, not needed in parallel.
- **Invoice `status` became a 3-state derived value** (`due` /
  `partial` / `paid`), recalculated by a private
  `refreshInvoiceStatus()` every time a payment is added or removed:
  compares `sum(payments.amount)` against what's owed (bill total + VAT
  − any pre-invoice advance) — `<= 0` is Due, `>=` owed is Paid
  (`paid_at` set to the completing payment's own date, not "now"),
  anything between is Partially Paid. The old manual "Mark as Due"
  toggle is gone entirely — reverting now happens by removing the
  payment that shouldn't have been recorded (e.g., a bounced check),
  which naturally recomputes status back down.
- **"Record Payment" replaces the old "Mark as Paid"** — usable
  repeatedly, not just once. `startRecordPayment()` pre-fills the amount
  with whatever's still remaining (so completing a partial payment is a
  one-field confirm, not a lookup-and-type exercise) and today's date;
  check number/bank/screenshot/description stay optional per payment,
  same as before. A **Payment History** list (admin-only, `print:hidden`)
  shows every payment recorded against the invoice with its own check
  details and screenshot thumbnail, each individually removable.
- **Deleting an invoice is now blocked by "has any payment recorded"**
  instead of "status is paid" — a Partially Paid invoice has real money
  against it and shouldn't be deletable either; the guard checks
  `payments()->exists()` directly.
- **The printed document** gained an Amount Paid / Balance Due block
  (mirroring how Advance Paid/Due already worked) that only appears once
  something's been paid — a fresh Due invoice still prints exactly as
  before. The "In Word" line now always spells out the true remaining
  balance, not the original bill total, so "Zero Taka Only" prints
  correctly once an invoice is fully settled.
- **Bill Statement and the Dashboard's Outstanding stat became
  payment-aware**: both previously treated "Paid" as "owes nothing" and
  "Due" as "owes everything" — now every balance calculation subtracts
  `withSum('payments as ..., 'amount')` explicitly, so a Partially Paid
  invoice correctly shows its real remaining balance in both places
  (previously would have shown as either the full amount or zero,
  never the true partial figure). Bill Statement's Total Paid footer
  stat changed from "sum of invoices marked Paid" to "sum of all
  payments actually received," which is the same number for a
  fully-paid-only dataset but now also correctly counts partial
  receipts.

9 new/rewritten tests: full payment marks Paid; a partial payment marks
Partially Paid and shows the right remaining balance; two payments that
together cover the total roll the status to Paid (and the second
payment's form correctly pre-fills the leftover amount); removing a
payment recalculates back to Due; deleting an invoice with any payment
is blocked; Bill Statement shows Partially Paid with the correct balance
and Total Paid; the existing "paid invoice, zero balance" test updated
to actually record a payment rather than just setting the status column
by hand (which is no longer how the app itself ever sets it). 135 tests
passing. Verified with Playwright end-to-end on real seeded data: two
partial payments against one Diesel Oil Supply invoice (15,000 then the
auto-filled remaining 25,500) correctly moved it Due → Partially Paid →
Paid, with the printed document's Balance Due and "In Word" line
updating correctly at each step, and Bill Statement reflecting the final
Paid state with a 0.00 balance.

## Bill Statement print output fits on one page (done)

Printing the Bill Statement (either "All Companies" or a single company)
was spilling across multiple pages — the on-screen table lived inside an
`overflow-x-auto` wrapper sized for a scrollable admin screen, not a
printed document, and the page had no `@page` margin rule, no reduced
print font size, and no protection against a row splitting across a
page break.

Rebuilt the printable output to read as an actual statement:
- **A print-only letterhead** (logo, company name/tagline/contact/
  address from `config('company.*')`) now heads the printed page,
  matching the same letterhead already used on the invoice document —
  the on-screen title card is replaced rather than duplicated
  (`hidden print:block` / print-specific text sizing on the on-screen
  heading).
- **The table and its wrapper shrink for print**: `print:overflow-visible`
  and `print:min-w-0` replace the screen-only horizontal scroll
  container (which was clipping columns when printed), cell padding and
  font size drop via `print:px-2 print:py-1 print:text-xs`, and status/
  category badges lose their pill background and become plain text in
  print (`print:!bg-transparent print:!px-0`) since color-coded pills
  don't reproduce well on paper and aren't needed once "Not Invoiced"/
  "Partially Paid" etc. already read as text.
- **Every row and the totals footer get `print:break-inside-avoid`**, so
  a row is never split across a page boundary.
- **Added a global `@page { margin: 12mm; }` rule** in `app.css` (the
  one addition that can't be expressed as a Tailwind `print:` utility —
  everything else stays inline utility classes as before).

Verified with Playwright: rendered both the "All Companies" and a
single-company view under `page.emulateMedia({ media: 'print' })` and
also exported each to a PDF via `page.pdf()` to count actual pages —
both now come back as exactly one page, letterhead included, against
the full ~14-row seeded dataset. On-screen (non-print) rendering
re-checked and unaffected. No test changes needed (pure presentational
change); full suite still at 136 passing.

**Follow-up, same conversation**: both this page and the invoice
document (`invoice-detail.blade.php`) still left a large empty band
above the letterhead when printed. Cause: the printable card is the
last child in a `space-y-*` wrapper, stacked after several
`print:hidden` blocks (filter bar, cost card, payment history) — those
blocks stop rendering in print, but Tailwind's `space-y` margin is
applied via a sibling selector (`:not([hidden])`) that only checks the
HTML `hidden` attribute, not a `print:hidden` class, so the margin
those hidden blocks would have contributed still lands on the printable
card. Fixed by adding `print:!mt-0` directly on the printable card in
both files, forcing its top margin to zero in print regardless of what
precedes it. Verified with Playwright print-media screenshots on both
pages — the letterhead now sits flush at the top of the page. No
behavioral/test changes (pure spacing fix).

The remaining gap the client saw in their own print preview afterward
was the page's own `@page` top margin, not app markup — reduced it from
a uniform `12mm` to `6mm 12mm 12mm 12mm` (top only) in `app.css`, so the
letterhead sits closer to the physical page edge while side/bottom
margins stay generous. This environment has no PDF-rendering tool
(`pdftoppm`/`ImageMagick`/Python) to screenshot actual paginated output,
so this one couldn't be visually re-verified the usual way — confirmed
the CSS compiles clean and the full suite still passes, but asked the
client to confirm the real print preview looks right.

**Follow-up, same conversation**: the printed invoice was still showing
one Tiffin row per *department* per day (e.g. "Swing" and "Wash Worker"
as two rows on the same date) — client wanted the whole day on one line
regardless of department. Changed the grouping key in `with()` from
`entry_date + tiffin_department_id` to `entry_date` alone, so every
department's entries for a day now fold into a single row; quantity and
the blended rate sum across all of them, and the Description column
lists every department name involved (`"Swing, Wash Worker"`) followed
by the combined item list, instead of naming one department. This is
specific to the printed invoice document — Daily Summary, Dashboard, and
the other screens still show the department-level breakdown, since
nobody had asked to collapse those and department-level detail is still
useful there for the owner's own bookkeeping. Renamed the existing
single-department test to reflect "one row per day" and added a new
test with two departments on the same date, asserting the row count is
exactly 1 (not 2) and both department names and both items appear in
that one row's text. 136 tests passing. Verified with Playwright against
real seeded data reproducing the exact case from the client's
screenshot (Swing + Wash Worker, same date) — now renders as a single
combined row.

## Daily Tiffin item purchases: locked cost rates + substitution + item visibility (done)

The client's real Egg workflow had three gaps: Egg is bought in one bulk
purchase a day (covering every factory's tiffin that day) at a market
price that moves daily, and nothing linked that real cost back to what
was typed on each Tiffin entry; some days Egg isn't available and a
different item is served instead, with no way to log a one-off
substitute without permanently changing a department's recipe; and the
client wanted to see each item's own data by date/factory/bill status,
which existed at the row level but had no way to filter down to it.

Confirmed with the client: substitution is a free-text override typed
per entry, not a recipe change; the daily purchase is one bulk buy per
day, scoped to Egg for now but built generically by item; and once a
purchase exists for an item+date, that entry's cost rate is locked
completely, not just suggested. Deliberately did **not** rebuild
anything resembling Rate Cards (a rate-config ledger built earlier this
project, then removed at the client's own request — "the entries
themselves are the rate history") for `bill_rate` — the existing
`mostRecentJobEntry()` history auto-fill already gives "type it once,
reused every day after" for the fixed 30 BDT factory rate, which barely
ever changes.

- **New `tiffin_item_purchases` table + `TiffinItemPurchase` model**:
  `tiffin_item_id`, `purchase_date`, `quantity`, `cost_rate`,
  `cost_amount`, `supplier_name`, `remarks`, `created_by`, with a unique
  index on `[tiffin_item_id, purchase_date]` — one bulk buy per item per
  day. No FK from `job_entries` back to this table; a `JobEntry`'s cost
  rate is a snapshot copy taken at creation time (matching the existing
  invariant that a billed entry's figures are frozen), looked up by item
  **name** via a static `TiffinItemPurchase::findFor($itemName, $date)`,
  consistent with how Tiffin already matches items by name rather than
  FK (`job_entries` has no `tiffin_item_id` column).
- **New `/tiffin-purchases` page** (`purchase-manager.blade.php`): modal
  add/edit form (mirroring the existing Tiffin catalog managers) plus a
  paginated list, with a live cost-amount preview. Picking an item+date
  that already has a purchase recorded auto-loads it into the form
  instead of erroring on the unique constraint — there's only ever one
  bulk buy per item per day. New sidebar link between Job Entries and
  Daily Summary.
- **Tiffin batch form (`job-entry-form.blade.php`) gained**: a
  `batchSubstituteNames` array (same `[dept][item]` shape as the other
  three `batch*` arrays) — an optional free-text field per item row,
  "Substituting with", defaulting blank. Every rate lookup
  (`mostRecentJobEntry()`, the purchase check) keys off an
  `effectiveName` (the substitute name if filled, else the catalog item
  name) instead of always the catalog name — this alone is the whole
  substitution mechanism: a substituted row never matches a purchase
  under the original item's name (so it's never locked) and never
  matches the original item's own rate history (so a repeated substitute
  builds its own history independently). `entry_date` became
  `wire:model.live` with an `updated()` hook that re-checks locks
  whenever the date or a substitute name changes, without wiping
  already-typed quantities/rates (a separate, lighter-weight path than
  `refreshMultiItemMode()`, which still resets everything on a
  company/category/department change).
- **The lock itself**: when a purchase matches, the Cost Rate input
  renders `disabled` with a caption ("Locked from today's purchase: 500
  @ 12.50 from Karim Traders — View Tiffin Purchases"). A disabled input
  isn't a security boundary, so `saveTiffinItemBatch()` re-derives the
  authoritative cost rate from the purchase record server-side
  regardless of what was posted for that field — proven with a test that
  posts a deliberately wrong value and asserts the purchase's rate wins
  anyway. The same lock (and override) was added to
  `tiffin-batch-edit-form.blade.php`, which already keyed every row
  generically by `supply_type` and needed no changes for substitution
  itself to display correctly there.
- **Two items substituted to the same name in one department/day is
  rejected** before any row is written — the batch-edit form keys rows
  by item name, so a silent collision would make one of the two entries
  permanently unreachable there afterward.
- **Job Entries list gained Item and Status (Billed/Unbilled) filters**,
  following the exact existing `#[Url]`-bound pattern already used for
  company/category/month — this is what actually answers "each item's
  data by date/factory/bill status," reusing the existing screen instead
  of a new report.

12 new tests (`TiffinPurchaseManagementTest.php` plus additions to
`JobEntryManagementTest.php`) covering: purchase CRUD, the auto-load
UX, the lock forcing cost rate even against a tampered posted value,
normal behavior with no matching purchase, a substituted item's name
and independence from the original item's purchase, the duplicate-name
rejection, the same lock in the batch-edit form, and the two new list
filters. 148 tests passing. Verified end-to-end with Playwright against
real seeded data (not just unit tests, since a debounced-field browser
race turned up during manual testing that the Pest suite couldn't have
caught): recorded an Egg purchase, confirmed its cost rate rendered
disabled with the correct caption in the Tiffin batch form, confirmed
Banana/Bread stayed normal/editable, saved a full batch and verified
the stored `cost_rate` matched the purchase exactly; substituted Egg
with "Orange" on a different date, confirmed the cost field unlocked
and the saved row's `supply_type` was "Orange" with the manually-typed
rate; confirmed the same lock renders (and is truly `disabled`, not
just visually similar) in the batch-edit screen; confirmed the new
Item/Status filters correctly isolate the substituted entry. Desktop,
mobile (390×844), and dark mode all checked.

**Follow-up, same conversation**: client clarified two scope details
after seeing the feature — Banana is the item that sometimes needs a
one-off replacement, not Egg (Egg was only ever the daily-purchase/
locked-cost item); and the purchase ledger should only ever be usable
for Egg, not Banana or Bread. Two narrow, explicitly-requested
restrictions, not a redesign:
- The "Substituting with" field in the Tiffin batch form now only
  renders on the item row named `'Banana'` — a direct `@if` check in
  the Blade template, since this is a specific product decision, not
  something that should vary per catalog. The underlying mechanism
  (`batchSubstituteNames`, `effectiveItemName()`) is unchanged and
  still fully general — it simply never receives a value for any other
  item now that the input isn't rendered for them.
- The Tiffin Purchases item picker (`purchase-manager.blade.php`) now
  filters to `TiffinItem::where('name', 'Egg')`, so Banana/Bread can
  never even be selected there; `startCreate()` pre-selects Egg
  automatically since it's the only option. The existing "load the
  existing purchase instead of erroring on a duplicate" check only ran
  from Livewire's `updated()` hook (which fires on an incoming property
  change) — since Egg is now set programmatically rather than picked by
  the user, that check was extracted into a shared
  `loadExistingPurchaseIfAny()` method so `startCreate()` can call it
  directly too, preserving the auto-load behavior when reopening the
  form on a day that already has an Egg purchase.

3 new tests: the substitute field's HTML only appears on Banana's row
and not Egg's/Bread's; the purchase form's item options list only ever
contains Egg; `startCreate()` pre-selects Egg. 151 tests passing.
Verified with Playwright: the batch form now shows "Substituting with"
under Banana only, and the Tiffin Purchases "Item" dropdown offers only
"Egg".

## Tiffin billing becomes a fixed per-person company rate, carried only by Egg (done)

Client clarified how Tiffin is actually billed: a factory pays one fixed
rate per person for the whole meal (30 BDT for Ananta Apparels Ltd), not
a separate price per ingredient — the old model priced Egg/Banana/Bread
independently and summed them (30+6+10=46/person), roughly 1.5× the real
price. Fixed by making only **Egg** carry a bill going forward
(`bill_rate` × Egg's quantity, which is already the day's headcount —
every person gets exactly one egg and Egg is never in short supply);
Banana and Bread became pure cost-tracking rows (`bill_rate`/
`bill_amount` always 0). The rate itself lives on a new
`companies.tiffin_bill_rate` column, configured once per company and
still editable ("typable") rather than retyped on every entry.

Separately, "Banana sometimes runs short" turned out to mean a **split**,
not the full-swap "Substituting with" field built earlier this session:
e.g. 1500 of the day's 2500 Banana servings are real Banana, the other
1000 a different item (Biscuit) filling the gap — both need their own
cost tracked as separate rows the same day. Replaced the old substitute
mechanism entirely with an **Exchange Item** add-on (name + quantity +
cost rate, one slot per department, rendered once rather than per item)
that appends an extra cost-only `JobEntry` row alongside Banana's own
(possibly reduced) quantity.

- **`job-entry-form.blade.php`**: removed the per-item Bill Rate
  input/validation/state everywhere except the item literally named
  `'Egg'`; `refreshMultiItemMode()` now sources Egg's Bill Rate from
  `$company->tiffin_bill_rate` when set, falling back to the existing
  history auto-fill otherwise (so a company with no rate configured yet
  keeps working exactly as before). The earlier substitution machinery
  (`batchSubstituteNames`, `effectiveItemName()`, `refreshRatesForSlot()`,
  the duplicate-effective-name check) was removed outright — no longer
  needed now that nothing swaps an item's identity — and replaced with
  `batchExchangeItemNames`/`batchExchangeQuantities`/
  `batchExchangeCostRates` (keyed by department only, since there's one
  slot). `saveTiffinItemBatch()` forces `bill_rate`/`bill_amount` to 0
  for every non-Egg row server-side regardless of posted state, rejects
  an exchange name that collides with a catalog item in that department,
  and appends the exchange row (if filled) after the normal per-item
  rows.
- **`tiffin-batch-edit-form.blade.php`**: same rule — Bill Rate only
  shown/editable for a row named `'Egg'`, forced to 0 for every other
  row at save time. No changes needed for exchange items themselves,
  since this screen already keyed every row generically by `supply_type`.
- **Two aggregation sites that blended a "rate" across every item in a
  Tiffin group broke** once only Egg carries a nonzero `bill_amount`:
  `invoice-detail.blade.php` and `daily-summary-detail.blade.php` both
  divided a summed `bill_amount` by a summed `quantity` across
  Egg+Banana+Bread+exchange. Fixed by switching the denominator to
  `$batch->where('bill_amount', '>', 0)->sum('quantity')` — isolates
  Egg's quantity automatically (driven by which row carries money, no
  hardcoded item name) and produces a more meaningful number either way:
  "cost/bill per person" instead of a figure diluted across mismatched
  ingredient units. Confirmed via research that every other aggregation
  site (Dashboard, Company hub, Bill Statement, Daily Summary's list
  view) only ever sums `bill_amount` as a flat total and needed no
  changes.
- **Job Entries list**: a non-billing item's line no longer prints
  "Rate 0.00" (reads like an error) — the Rate segment is simply omitted
  when `bill_rate` is 0.
- **Found and fixed a pre-existing, unrelated bug while verifying this
  in the browser**: the company edit form's Tiffin Departments section
  (and now the new Tiffin Bill Rate field sitting beside it) never
  rendered when editing an *existing* company, even with Tiffin checked
  — `x-show="categories.includes('{{ $tiffinCategoryId }}')"` compared
  a string against an array of integers on first render, since
  `mount()` seeded `serviceCategoryIds` straight from Eloquent's `pluck()`
  (ints) while a toggled checkbox would have produced strings. `.includes()`
  uses strict equality, so `[1,2].includes('1')` is `false`. Fixed by
  casting the seeded ids to strings in `mount()`, matching what a
  checkbox always produces.
- Seeders updated to demo the new model correctly: `CompanySeeder` sets
  `tiffin_bill_rate = 30.00` on Ananta Apparels Ltd; `JobEntrySeeder`
  zeroes Banana/Bread's bill rates and adds a Biscuit exchange-item row
  on one seeded day.

7 new tests plus several existing ones reworked (`JobEntryManagementTest`,
`CompanyManagementTest`, `DailySummaryTest`, `InvoiceManagementTest`):
Egg's bill rate auto-fills from the company rate (and still falls back
to history when unset); a full batch save computes `bill_amount` from
Egg's quantity × the company rate; an exchange item saves as its own
cost-only row alongside a reduced Banana quantity; a colliding exchange
name is rejected; the exchange fields render once, not per item; the
batch-edit form only shows Bill Rate for Egg; setting/validating
`tiffin_bill_rate` on a company; Daily Summary and Invoice Detail both
show quantity/rate based on the billing item only, not a blended sum
across every ingredient. 158 tests passing. Verified end-to-end with
Playwright on real seeded data: company edit form correctly shows and
persists the Tiffin Bill Rate (30.00, now actually visible after the
Alpine fix); a full Tiffin batch (Banana 1500 + exchange Biscuit 1000,
Egg 2500, Bread 2500) saved with Egg alone carrying `bill_amount`
75,000 and everything else at 0; Daily Summary's detail view for the
seeded Swing/Wash Worker days shows headcount-based quantity/cost-per-
person/bill-per-person exactly matching hand-calculated figures.
Desktop, mobile (390×844), and dark mode all checked.

**Follow-up, same conversation**: on the Job Entries list, Egg's line
read "Egg · Qty 2,500 · Rate 30.00 · Cost 25,000.00 · Profit 50,000.00"
— "Rate" sat inline between Qty and Cost with no label distinguishing
it as the factory's billing rate, reading as if it might be a cost
figure. Pulled it out into its own `<x-badge color="brand">` reading
"Factory rate 30.00/person", visually and semantically separated from
the Qty/Cost/Profit segment (which stays plain text, unchanged for
Banana/Bread since they never bill). No test changes (pure
presentational); full suite re-verified at 158 passing; checked
desktop and dark mode.

## Bill Statement accuracy audit (done)

Client flagged Bill Statement as financially sensitive and asked for a
full accuracy check, prompted by the recent Tiffin billing redesign
(only Egg carries a bill now). Audited rather than assumed:

- Confirmed `bill-statement.blade.php` only ever sums whole
  `bill_amount`/`company_adv_payment`/payment `amount` values — it never
  divides to derive a per-unit rate (unlike Invoice Detail/Daily
  Summary, which needed the earlier headcount fix), so it was never
  vulnerable to that class of bug. Correctness here rests entirely on
  each `JobEntry.bill_amount` already being right at the row level.
- Checked the live database directly for **stale pre-redesign rows** —
  the one real risk the code audit alone couldn't rule out: a Tiffin
  entry created before the redesign could still carry an old nonzero
  Banana/Bread `bill_amount` that nothing would retroactively correct.
  Found zero such rows (`JobEntry::whereNotNull('tiffin_department_id')->where('supply_type', '!=', 'Egg')->where('bill_amount', '>', 0)`
  returned empty) and zero Egg rows sitting at `bill_amount = 0`
  (which would mean a silent under-bill).
- Independently recomputed the exact Bill Statement formula for every
  invoice and every pending (unbilled) group straight from the database
  in a standalone script, then compared against the live rendered page
  — Total Billed, Total Paid, and Total Outstanding matched to the
  cent (306,660.00 / 0.00 / 256,660.00 against real data: one generated
  Tiffin invoice, one ETP Eid Holiday advance-payment case, several
  unbilled months across categories). Also confirmed every one of the
  34 `JobEntry` rows in the database is accounted for in exactly one
  place (an invoice's sum or one pending group) — no row silently
  double-counted or dropped.
- Added 2 permanent regression tests to `BillStatementTest.php`
  asserting a Tiffin pending group and a generated Tiffin invoice both
  bill only Egg's amount, not a sum across every ingredient — the exact
  failure mode a future change could reintroduce without these. 160
  tests passing.

No code changes were needed — this was verification only, and it
confirmed the statement is accurate against the current data.

## Egg's fixed +5 buffer, kept out of billing (done)

Client revealed a business rule not previously captured: every day's Egg
count always includes a fixed +5 on top of the real headcount (a
spoilage/breakage margin — e.g. 2500 headcount means 2505 eggs actually
bought and sent). Confirmed with the client: the factory is billed for
headcount only (2500 × rate), never the buffered count, and the
accountant should type the headcount and have the system add the buffer
automatically rather than doing that math by hand every day.

- New `config/tiffin.php` (`egg_buffer_quantity`, default 5, overridable
  via `TIFFIN_EGG_BUFFER_QUANTITY`) — a single named constant instead of
  a magic number repeated across files.
- Egg's "Quantity" field is now labelled **Headcount** everywhere it
  appears (create-batch form, batch-edit form) and is the only thing
  typed. `saveTiffinItemBatch()`/`tiffin-batch-edit-form.blade.php`'s
  `save()` both compute `storedQuantity = headcount + buffer` (what's
  saved as `JobEntry.quantity`, and what cost is based on) while
  `bill_amount` stays `headcount × bill_rate` — the two are deliberately
  computed from different multipliers now, so this only lives in two
  well-contained places rather than assuming quantity always means one
  thing.
- Both forms show a live caption under Headcount — "+5 buffer → 2,505
  eggs costed" — so the accountant always sees the real number being
  costed, not just a mysteriously-larger Cost figure than Headcount ×
  Cost Rate would suggest.
- Editing an existing batch reverses the math to display Headcount
  (`stored_quantity − buffer`) rather than the raw buffered number,
  round-tripping correctly on save.
- Seeder (`JobEntrySeeder`) updated to demo the same headcount/buffer
  split via its existing `tiffinDay()` helper (callers still just pass
  headcount, matching the real form).

**Related, same conversation**: client also clarified the purchase lock
shouldn't require an *exact* date match — "if we don't purchase the same
day, the last purchase's cost rate should be shown." `TiffinItemPurchase::findFor()`
now finds the most recent purchase **on or before** the given date
instead of only an exact match, so a rate stays in effect until a newer
purchase updates it (you don't buy eggs literally every day). The
"Locked from today's purchase" caption was updated to name the actual
purchase date when it's not the same day, so it never claims a lock
happened "today" when it carried forward from days earlier. This one
`findFor()` change automatically propagates everywhere the lock is
checked (create form, batch-edit form, server-side save override) since
they all share the same method.

8 new/updated tests: `findFor()` carries forward correctly (including
never returning a future-relative purchase, and matching the closest
earlier one when multiple exist); a full batch save with no purchase for
the exact date still locks from an earlier one; every existing
quantity/cost/bill assertion across both Tiffin forms updated for the
headcount+buffer split. 162 tests passing. Verified live: recorded a
purchase 3 days before the entry date, confirmed the lock caption read
"Locked from the 30 Aug 2026's purchase" (not "today's"); typed
Headcount 2500 for Egg and confirmed Cost 33,191.25 (2,505 × 13.25),
Bill 75,000.00 (2,500 × 30), Profit 41,808.75 — all correct and
transparently captioned. Checked the edit form's reverse calculation,
dark mode, and mobile.

**Note**: verifying this live involved recording a real Tiffin Purchase
and generating fresh job entries against the dev database, followed by
the same `migrate:fresh --seed --force` reset used throughout this
session's verification workflow — any of the client's own hands-on
testing data present before this reset (e.g. the previously-audited
Invoice #1) was reset back to seeded demo data as a result, consistent
with how every prior feature in this session concluded.

## Bill Statement: Total Paid now includes advance payments (fixed)

Client spotted that Total Billed − Total Paid didn't equal Total
Outstanding (225,660 − 5,010 = 220,650, but the page showed 170,650 —
a 50,000 gap) and asked for an explanation. Traced it exactly: the ETP
Eid Holiday row's Balance Due already correctly subtracts its 50,000
`company_adv_payment` advance (100,000 → 50,000), but "Total Paid" only
ever summed the formal `invoice_payments` table — it never counted an
advance as money received, even though it's just as real. So every
individual row was already correct; only the aggregate reconciliation
was misleading.

Fixed in `bill-statement.blade.php`: each row's `paidAmount` (summed
into Total Paid) now includes `advancePaid` alongside `paidViaPayments`,
for both generated-invoice rows and not-yet-invoiced pending rows —
previously pending rows hardcoded `paidAmount => 0.0`, ignoring any
advance entirely. This makes the three totals reconcile exactly
(Billed − Paid = Outstanding) whenever no row is overpaid, which is
what anyone checking the statement by hand would expect. Balance Due
itself (already correct) is unchanged.

1 new regression test asserting `totalBilled - totalPaid === totalOutstanding`
on a scenario with an advance payment. 163 tests passing. Verified live:
the exact seeded scenario the client flagged (225,660 billed, ETP Eid
Holiday's 50,000 advance) now shows Total Paid 55,010.00 (was 5,010.00)
and 225,660.00 − 55,010.00 = 170,650.00 matches Total Outstanding
exactly.

## Year/Month/Status filters + full pagination across data screens (done, 2026-09-02)

Client asked for "extra and needed filtering system... bill statement
should have monthly/yearly and billed/unbilled" plus, mid-request, "make
sure all data tables are paginated" — with the hard constraint that Bill
Statement must keep printing every filtered row on one document (an
earlier, explicit requirement) even though it's now paginated on screen.

**Filters added**, all `#[Url(history: true)]`-bound so they survive a
refresh/back-button and are shareable as links:

- **Bill Statement**: Company (existing) + new Year, Month, Status
  (billed/unbilled). Month `<select>` is disabled until a Year is picked
  ("Pick a year first"); picking a new Year clears an incompatible Month.
- **Job Entries**: Company/Category (existing) + Item/Status (existing)
  swapped from a single `<input type="month">` to the same Year+Month
  `<select>` pair as the other screens, for consistency and to support
  whole-year filtering (impossible with a single month input).
- **Daily Summary**: new Year/Month, shown once a company is selected
  (the list is company-scoped by design).
- **Tiffin Purchases**: new Year/Month alongside the existing Item filter.

Query-level filtering (Job Entries, Tiffin Purchases) uses portable
`whereYear()`/`whereMonth()`. In-memory collection filtering (Bill
Statement's combined invoice+pending rows, Daily Summary's grouped days)
uses `->filter()` closures on real `Carbon` properties — "available
years" dropdowns are always built from plain date columns mapped to
`->year` in PHP, never a raw SQL `YEAR()` aggregate, to stay portable
across MySQL/SQLite per existing convention.

**Print-safe pagination (Bill Statement, Daily Summary)**: both screens
now paginate on screen (15/page) without dropping rows from print. The
pattern: the full filtered collection always drives totals and print;
a manually-built `LengthAwarePaginator` (empty `items`, just for
`currentPage()`/`lastPage()`/`total()`) drives the on-screen `->links()`
nav; each row computes its own page via
`intdiv($loop->index, $pagination->perPage()) + 1` and gets
`class="{{ $onScreenPage ? '' : 'hidden print:!table-row' }}"` — hidden
on screen when off the current page, forced back with `!important`
under print regardless. Job Entries and Tiffin Purchases already had
real `simplePaginate(10)` pagination from earlier work; unchanged here.

One bug caught while building the manual paginators: passing
`'path' => request()->url()` breaks pagination links during Livewire's
AJAX page-change requests (that URL resolves to Livewire's internal
update endpoint, not the browser's page). Fixed by omitting `path`
entirely and letting Laravel's default resolver (which Livewire
configures correctly) handle it.

9 new/updated tests (Bill Statement, Daily Summary, Tiffin Purchases,
Job Entries) covering: filters narrow rows/totals correctly, the year
dropdown always offers every year present regardless of the current
filter, changing the year clears an incompatible month, and — the
critical guarantee — on-screen pagination never drops a row from the
printed statement or its totals (verified with 20 rows spread across 20
months, well past one screen page). 172 tests passing.

Verified live across all four screens: filter bars render correctly,
Job Entries' existing pagination controls still work, Daily Summary's
Year/Month appears once a company is picked with correct real totals,
Tiffin Purchases' Item/Year/Month renders with the right empty state.
Checked dark mode and mobile (390×844) on Bill Statement — filters
stack cleanly, table scrolls in its own container, no regressions.
`migrate:fresh --seed --force` run to restore clean demo data.

## Dashboard rebuilt with a billed vs unbilled chart system (done, 2026-09-03)

Client asked for "a complete dashboard with chart system, focus on
unbilled and billed so client can see what should be billed and what's
already billed." The dashboard was a plain Blade view fed by a route
closure — converted it into `livewire:dashboard.dashboard` (Volt SFC),
matching every other feature's `{feature}/index.blade.php` +
`<livewire:...>` pattern, so `routes/web.php`'s dashboard route is now
a one-line `Route::view(...)`.

No JS charting library was added — the project has none installed and
CLAUDE.md requires approval before changing dependencies, so charts are
hand-built with Tailwind + inline `style="width/height: X%"` bars (no
new dependency, themeable, and immune to the re-initialization problems
a JS chart library can hit under Livewire's `wire:navigate`).

**New "Billed" stat card** (was missing entirely — only Unbilled,
Outstanding, and This Month existed): all-time
`JobEntry::whereNotNull('invoice_id')->sum('bill_amount')`. Stat grid
widened from 3 to 4 cards, and its breakpoints changed to
`grid-cols-1 sm:grid-cols-2 lg:grid-cols-4` — an initial `grid-cols-2`
on mobile clipped the larger amounts (six-plus digits) inside the
narrower cards; caught in mobile Playwright verification and fixed
before considering this done.

**Two new charts**:
- *Billed vs Unbilled — Last 6 Months*: one stacked bar per month
  (brand = billed at the bottom, amber = unbilled on top), each scaled
  to the tallest month's total. Grouped in PHP by `entry_date`'s Carbon
  `format('Y-m')` rather than a raw SQL date function — the same
  portability convention used for Bill Statement/Daily Summary's month
  grouping (MySQL in production, SQLite in tests).
- *By Company — Billed vs Unbilled*: one horizontal stacked bar per
  company (all-time), sorted by total descending, top 8 shown with a
  "+N more companies not shown" note beyond that. Backed by a new
  `Company::jobEntries()` relation feeding two `withSum()` aggregates
  (`whereNotNull`/`whereNull('invoice_id')`) — portable, no raw SQL.

Both charts floor any nonzero segment at 2% of the bar so a small real
amount next to a much larger one never renders as an invisible sliver.
Each bar carries a `title` tooltip with the exact figures.

Existing sections (Ready to Invoice, Today's Activity, company-count
footer) carried over unchanged, same markup and copy, so all 8
pre-existing dashboard tests kept passing without modification. 3 new
tests added: the Billed stat total excludes unbilled entries, the
monthly trend chart's tooltip text reflects the correct billed/unbilled
split for the current month, and the company breakdown orders by total
descending and truncates past 8 with the correct "+N more" count. 175
tests passing (was 172).

Verified live: generated a real invoice via the UI against seeded data
so both chart colors would appear, checked desktop, dark mode, and
mobile (390×844) — caught and fixed the stat-card clipping bug above
during this pass. `migrate:fresh --seed --force` run afterward to
discard that verification invoice and restore clean demo data.

## Tiffin invoice/summary rate no longer distorted by Egg's buffer (fixed, 2026-09-03)

Client spotted it directly from a printed invoice (AAL-TIF-202609): the
Rate column showed 26.81, 25.71, 26.00 instead of the company's actual
configured 30.00 Tiffin rate, and asked for the real rate. Root cause:
Egg's *stored* `quantity` is headcount **plus** the always-sent +5
buffer (from the earlier Egg-buffer feature), but `bill_amount` is
headcount-only. Both `invoice-detail.blade.php` and
`daily-summary-detail.blade.php` computed their displayed rate as
`bill_amount ÷ quantity` — dividing a headcount-only amount by a
buffered quantity, understating the rate (e.g. 1,260 ÷ 47 = 26.81
instead of the true 30.00, since 47 = 42 headcount + 5 buffer).

Fixed in both files: rate now comes directly from the billing row's own
stored `bill_rate` (exactly what was configured/agreed, never
recomputed), and the displayed Quantity is reverse-derived as
`bill_amount ÷ bill_rate` (the true billed headcount) instead of the
buffered stored quantity. This keeps Quantity × Rate = Amount exactly
reconciling on the printed document — critical since this client
scrutinizes bill statements closely. Daily Summary's Cost Rate column
is untouched (a legitimate blend of every item's cost across the
corrected headcount denominator); only Bill Rate stopped being a blend
and became the billing item's real rate.

Two DailySummaryTest cases predating the Egg-only-bills redesign
(`...blended average across its items`) were still asserting the old,
now-incorrect behavior (both Banana and Egg carrying a bill, "blended"
rate) — rewritten to match the current, correct model instead of
deleted, since the scenario they check (department collapsing multiple
items into one row) is still valid. 1 new regression test added
reproducing the client's exact numbers (Egg stored quantity 47 = 42
headcount + 5 buffer, bill_rate 30, bill_amount 1,260) asserting the
displayed rate is 30.00 and quantity is 42.00, not the distorted
26.81/47. 179 tests passing. Verified live: regenerated the exact
AAL-TIF-202609 invoice from the client's screenshot — Rate now reads
30.00 on every row, Quantity dropped from 47/70/75 to the correct
42/60/65, and Amount still reconciles exactly.

## Signed bill copy storage (done, 2026-09-03)

Client explained the paper workflow: two copies of each bill are
printed, the factory keeps one and signs the other as proof of
delivery — that signed copy needs to be stored against the invoice.
Added a `signed_copy_path` column on `invoices` (nullable, alongside
`remarks`) and an upload/view/remove flow on the invoice detail page,
mirroring the existing check-screenshot pattern already used for
payments (`WithFileUploads`, `Storage::disk('public')`, `mimes:jpg,jpeg,png,pdf`
capped at 10MB). A signed copy shows as a thumbnail (or a "PDF" tile
for non-image files) with a "View signed copy" link and a "Remove"
action gated behind the same confirm-modal pattern used for deleting a
payment/invoice. Caught and fixed one bug during verification: Livewire's
`temporaryUrl()` throws `FileNotPreviewableException` for a selected
file outside its `preview_mimes` list (PDF isn't in it) — guarded the
pre-upload preview with `$signedCopy->isPreviewable()`, falling back to
just naming the selected file. Noted but out of scope: the existing
payment check-image preview has the same latent crash risk if a
non-image file is ever selected past the `accept="image/*"` hint —
worth the same guard if it's ever hit in practice.

3 new tests: upload stores the file and links it to the invoice,
uploading a non-image/PDF file is rejected by validation, and removing
a signed copy deletes it from storage and clears the column. 179 tests
passing. Verified live: uploaded an image, confirmed the thumbnail/view
link appeared; removed it via the confirm modal, confirmed the upload
form returned; checked dark mode and mobile (390×844).

**Also this session**: consolidated every `add_x_to_y_table`/
`drop_x_from_y_table` migration into its table's original `create_*`
migration, per the client's instruction that the app is still pre-launch
(no production data to preserve) so migrations should default to being
edited in place rather than stacked incrementally until go-live. This
removed `add_service_category_id_and_vat_to_invoices_table`,
`add_check_payment_details_to_invoices_table` +
`drop_check_payment_details_from_invoices_table` (a no-op pair — folded
into nothing), and `add_tiffin_bill_rate_to_companies_table`. One case
needed more than a straight fold: `job_entries.invoice_id` referenced
`invoices`, but `create_job_entries_table` ran *before*
`create_invoices_table` — fixed by re-timestamping
`create_invoices_table` to run earlier (nothing between the two
depended on the reordering), then folding `invoice_id` directly into
`create_job_entries_table`. Recorded as a standing rule in
`.ai/rules/migrations.md` so this convention is followed by default
going forward, with a note to switch back to additive migrations once
the app goes live.

## Dashboard: filterable Profit panel (done, 2026-09-03)

Client asked to see "his entire profit with good filtering options" on
the dashboard. Added a new "Profit" card to `dashboard.blade.php` with
its own Company/Year/Month filters (`#[Url]`-bound, same select-bar
pattern as Bill Statement), independent of the existing stat cards and
charts. Defaults to every company, all-time — "entire profit" is the
first thing shown, narrowed only once a filter is picked.

Shows Total Billed, Total Cost, Total Profit, and Margin % for the
filtered scope, plus a breakdown list underneath: **By Company** when
every company is in view (top 8 by profit, "+N more" beyond that,
matching the existing By-Company chart's truncation pattern), or **By
Category** once a single company is selected — a company breakdown
would just be one row at that point, so it switches to showing where
that company's own profit comes from instead. Negative profit (a
company/category running a net loss) renders in red with an empty bar
rather than crashing or showing a nonsensical negative-width bar.

One existing test broke as a side effect: `assertDontSee('Breakdown Co 1')`
assumed a truncated company would never appear anywhere on the page,
but the new Profit filter's company `<select>` legitimately lists every
company regardless of billing activity. Rewrote that test to assert
against the component's `viewData('companyBreakdown')` directly instead
of loose page-text matching — more robust, and no longer coupled to
what else the page happens to render. 3 new tests for the Profit panel
itself (totals, filter narrowing + year/month-clearing, company→category
breakdown switch). 182 tests passing. Verified live: default view,
filtered to a single company (breakdown correctly switched to
categories), dark mode, and mobile (390×844).

## Tiffin Purchases: "Supply by Item" tab for supplier payment (done, 2026-09-03)

Client wants to pay their Banana supplier monthly and needed to see
total Banana quantity supplied across *every* company, not per-company
(Daily Summary is company-scoped and Tiffin Purchases only tracks
Egg's cost-rate-locking bulk buys — neither answers "how much Banana
did we use business-wide this month"). Clarified scope with the client:
cover every Tiffin item (not just Banana — Banana is just the default
selection), add it as a new tab on the existing Tiffin Purchases page
rather than a separate screen, and show daily rows plus a monthly total
so the office can cross-check against what the supplier's own delivery
log says before paying.

Added a "Supply by Item" tab to `purchase-manager.blade.php` (Volt
component gained `activeView` state, `#[Url]`-bound, switched via a
`switchView()` method) alongside the existing "Purchases" tab. The new
tab has its own Item/Year/Month filters — Item is populated from every
*distinct* `supply_type` actually used across Tiffin department job
entries (not just the 3-item catalog), so it also covers ad-hoc
exchange items like Biscuit, defaulting to Banana. For the selected
item, sums `JobEntry.quantity` across every company grouped by day
(Egg's stored quantity already includes its +5 buffer, which is exactly
the real physical quantity received from the supplier, so no special-
casing was needed there). Days paginate with their own named paginator
(`supplyPage`) so they don't collide with the existing Purchases tab's
pagination on the same page. A headline card shows the total for
whatever scope is selected ("Total Banana — September 2026" /
"— 2026" / "— all-time").

4 new tests: default view is Purchases with Banana pre-selected;
quantity totals correctly across companies per day and per month while
excluding other items; the item list reflects real distinct
`supply_type` values including an exchange item; changing the year
clears an incompatible month. 186 tests passing. Verified live: the
tab switch, Banana's real seeded daily/monthly totals reconciling
correctly, dark mode, and mobile (390×844).

## Egg buffer applies once per company per day, not once per department (fixed, 2026-09-03)

Client caught it directly from the batch form's live caption ("+5
buffer → 405 eggs costed"): the +5 buffer was being added to *every*
Tiffin department's Egg row independently (Swing +5, Wash Worker +5, a
company with both running that day got +10 total), when the client's
actual practice is +5 once for the whole company's delivery regardless
of how many departments split the headcount.

Fixed by introducing the rule "only the first department (alphabetically,
matching every existing department-ordering convention in the app) with
an actual Egg quantity absorbs the buffer; every sibling department's
Egg row stores exactly its own headcount." Applied in three places that
all needed to agree on the same department for the same company/day:

- **Create-batch form** (`job-entry-form.blade.php`): new
  `firstEggDepartmentId()` helper, used both when saving
  (`saveTiffinItemBatch()`) and in the live preview (`with()`), so the
  caption and the saved data can never disagree. The buffer caption now
  reads "+5 buffer (once for the whole company) → X eggs costed" only on
  the department that carries it; every other department's Egg field
  shows "Buffer already added under {Department} for the day" instead.
- **Batch-edit form** (`tiffin-batch-edit-form.blade.php`): this form
  only ever loads *one* department's entries, so it can't locally derive
  "am I the buffer department" — added `eggBufferDepartmentId()`, a
  query across every Egg entry for that company+day (not scoped to the
  department being edited) sorted by department name, matching the
  create form's convention exactly. Since the query reads whatever is
  *actually saved* rather than a fixed rule, it stays correct even if a
  sibling department's entries are later deleted (the next edit
  self-heals which department is treated as the carrier).
- **`JobEntrySeeder`**: `tiffinDay()` gained an `$includeEggBuffer`
  parameter (default `true`); the two days where both Swing and Wash
  Worker are seeded together now pass `includeEggBuffer: false` for
  Wash Worker's call, matching the corrected real-world behavior instead
  of demoing the bug.

One existing test asserted the old (buggy) per-department behavior
directly (`'filling in two Tiffin departments...'` expected Wash
Worker's Egg row to also be headcount+5) — updated to expect the
corrected split (Swing 205+5=210, Wash Worker exactly 90, no double
buffer). 3 new tests: the create form's caption appears only on the
carrying department; editing the *non*-carrying department via
batch-edit doesn't add a second buffer; editing the carrying department
still reverse-computes its headcount correctly even with a sibling
department present. 189 tests passing. Verified live: filled in Swing
(200) and Wash Worker (80) on the real create form — Swing showed "+5
buffer (once for the whole company) → 205 eggs costed", Wash Worker
showed "Buffer already added under Swing for the day"; re-seeded and
confirmed Wash Worker's cost figure in Daily Summary no longer
double-counts the buffer.

## Dashboard Profit now only counts what's actually been paid (fixed, 2026-09-03)

Client clarified: "Total Profit" was summing every entry's
`profit_amount` regardless of invoice status — so a bill that's Due or
Partially Paid was already being counted as profit even though no money
has actually come in yet. Fixed so **Total Profit** and **Margin** in
the dashboard's Profit panel (and its By Company/By Category breakdown)
only include entries whose invoice status is `paid`. Unbilled entries
and entries on a Due/Partially Paid invoice contribute to Total Billed
and Total Cost (work was done, cost was incurred) but never to profit
until the invoice is actually marked Paid.

Both cards were relabeled "Total Profit (Paid only)" / "Margin (Paid
only)" (plus a hover title on all four cards spelling out what each one
does and doesn't include) so the distinction is visible at a glance
rather than a silent behavior change; the breakdown section header
gained "— Paid Only" and its empty state now reads "No paid invoices in
this scope yet." Margin's denominator changed to match — paid profit
÷ paid billed, not paid profit ÷ every billed entry — so the percentage
stays meaningful instead of being deflated by unpaid work sitting in
the denominator.

3 existing Profit tests were updated to attach real `Invoice` records
(mostly `paid`, one deliberately `due`) instead of bare unbilled
`JobEntry` rows, since the old fixtures no longer exercise the paid
condition at all. The main test was renamed and extended to prove the
distinction directly in one place: a Due invoice's 9,999 bill_amount
counts toward Total Billed/Cost but contributes exactly 0 to Total
Profit. 189 tests passing. Verified live end-to-end: generated a real
Diesel invoice (Due) — Total Profit stayed 0.00 while Total Billed grew
by the new invoice's amount; recorded full payment on it — Total Profit
and the By Company breakdown updated to the paid amount, Margin
recalculated correctly. Checked dark mode and mobile (390×844).

## Settings page: uploadable company logo (done, 2026-09-03)

Client wants a Settings menu to upload a company logo that reflects
everywhere the app currently shows the hardcoded "RE" mark. Added a
single-row `settings` table (`Setting::current()` — a `firstOrCreate`
singleton, since there's no multi-tenant concept here, just one
business) with a `logo_path` column, a new `/settings` page linked from
the Admin dropdown (alongside Profile), and a Livewire upload form
mirroring the existing check-screenshot/signed-bill-copy upload pattern
(image validation, `Storage::disk('public')`, a confirm-modal-gated
Remove action that reverts to the default mark and deletes the old
file — replacing also deletes the previous file, which the earlier
upload features intentionally didn't bother with since they're
never-replaced single artifacts, but a logo very much is).

`<x-application-logo>` (previously a static inline SVG) now checks
`Setting::current()->logo_path` and renders the uploaded image via
`Storage::disk('public')->url()` with `object-contain`, falling back to
the original SVG mark when nothing's uploaded — every one of its four
existing call sites (sidebar, guest/login layout, Bill Statement print
header, Invoice print header) picked up the change automatically with
no per-site work, since they all go through the same component.

Also fixed a real, reproducible bug while building this: the same
`FileNotPreviewableException` crash caught last session on the signed-
bill-copy upload (Livewire's `temporaryUrl()` throws for a selected
file outside its `preview_mimes` allowlist) also existed here and was
still unfixed on the payment check-image upload — guarded all three
pre-upload previews with `isPreviewable()` now, consistently.

5 new tests (upload persists and appears via the component on a real
page, non-image rejected, replacing deletes the old file, removing
deletes the file and reverts to default). 194 tests passing.

**Also this turn**: added a small circular monogram (the same
`<x-application-logo>`, styled as a rounded seal with a border) to the
invoice's signature block, next to "Rezia Enterprise" above the
"Authorised" line — the client asked for something in the invoice body
itself (not just the header) to read as more official/original,
matching how a lot of professional invoices carry a small stamp-like
mark near the authorizing signature. No new component needed — reusing
the existing dynamic logo meant this automatically reflects whatever
logo is uploaded (or the default mark) with zero extra state.

**Note on this session's own concurrent testing**: partway through,
the client was live-testing the new Settings page themselves (uploading
their real company logo) at the same time synthetic test uploads were
being verified against the same dev database — briefly producing a
confusing state (a corrupt synthetic test image, and a moment where the
settings row referenced a file that had already been cleaned up). Once
noticed, verification switched to read-only checks against the client's
actual upload rather than continuing to overwrite it. **`migrate:fresh
--seed --force` was deliberately *not* run at the end of this turn** —
unlike every prior feature this session, the client now has real data
in the dev database (their uploaded logo, and an invoice they generated
themselves) that a reset would destroy. This session's dev-data-reset
habit needs to stop by default going forward now that the client is
actively using the app as a live environment, not only as a sandbox for
verification.

**Follow-up, same conversation**: client asked for a large, faint
watermark of the logo in the middle of the invoice body too (on top of
the small signature-block monogram above). Added a large `<x-application-logo>`
(h-96, grayscale, `opacity-5`/`print:opacity-10`) absolutely centered
behind the whole printable card — an actual `<img>`/`<svg>` element
rather than a CSS `background-image`, specifically so it still prints
even when the viewer's browser has "background graphics" turned off in
its print dialog (a setting that only suppresses CSS backgrounds, never
foreground elements). Required wrapping the card's real content in its
own `relative z-10` layer, since an absolutely-positioned sibling with
default stacking (`z-index: auto`) paints *above* static in-flow
content per the CSS stacking spec — without that wrapper the watermark
would've covered the invoice instead of sitting behind it. Shows
through the table rows too (they have no opaque background of their
own), which is the point of a document watermark. No new tests needed
— purely visual, same underlying `<x-application-logo>` component
already covered by the Settings upload tests. Verified live: on-screen
and print-media emulation both show the mark correctly centered,
grayscale, faint enough not to interfere with reading any figure.

## Users, roles, and permissions (done, 2026-09-03)

The app had zero authorization before this — the one seeded account
could do everything. Client wanted real staff accounts: an
**Accountant** who can create/submit day-to-day records (job entries,
Tiffin batches, Tiffin purchases, invoices, payments) but never
edit/delete once created, and **Staff** who start with nothing until
the Super Admin grants specific capabilities via a UI. Planned in
detail before writing code (`EnterPlanMode`) given the size — nearly
every route and Livewire component in the app needed a gate.

**Data model**: `App\UserRole` (`SuperAdmin`/`Accountant`/`Staff`, a
backed enum) and `App\Permission` (18 cases across every feature area —
`dashboard.view_profit`, `job_entries.create`, `invoices.modify`, etc.,
each with a `label()` and `group()` for the settings UI). `users`
gained `role` and `is_active` columns; a new `role_permissions` table
(`role`, `permission`, unique together) stores which permissions
Accountant/Staff currently have — Super Admin is never stored there,
it's a hard bypass in code (`User::hasPermission()`), so the owner can
never be locked out by a misconfigured grant. `users.manage` (user
administration itself) is likewise never a stored/grantable permission
— always a direct `role === SuperAdmin` check — so no combination of
granted permissions can let anyone promote themselves.

**Enforcement**: every `Permission` case registered as a Laravel Gate
in `AppServiceProvider::boot()`, so the rest of the app uses plain
Laravel idioms — `->middleware('can:job_entries.create')` on routes for
whole-page access, `Gate::authorize('job_entries.modify')` as the first
line of every mutating Livewire method (the real enforcement — routes
only gate the *page*, not each action reachable from a shared page like
a list's inline delete), and `@can(...)` in Blade to hide buttons a
user can't use. Repeated this pattern across ~15 Volt components
(Job Entries, Tiffin Purchases, Companies, Invoices/Payments, Service
Categories/Tiffin Items, Settings). `dashboard` deliberately has **no**
route gate — it's the hardcoded post-login landing page — so instead
its financial content (stat cards, charts, Ready to Invoice, Today's
Activity, Profit) is wrapped in `@can('dashboard.view')` with a
friendly "ask your Super Admin" empty state, and Profit is *additionally*
gated behind its own `dashboard.view_profit` on top of that.

**New Settings tabs** (`Users`, `Roles & Permissions`, both gated to
`users.manage`, alongside the existing Company Logo tab — same tab
pattern already used by Tiffin Purchases): Users lets a Super Admin
create/edit accounts and assign a role, with two safeguards enforced
server-side (not just hidden UI) — a Super Admin can never demote or
deactivate *themselves*, or the *last* remaining active Super Admin.
Roles & Permissions shows two checkbox grids (Accountant, Staff; Super
Admin isn't shown — it's always everything) grouped by feature area,
saving by syncing `role_permissions` rows. Deactivated users are
blocked at login (`LoginForm::authenticate()`) with a clear message.

**Bug caught and fixed while building this**: permission values contain
dots (`job_entries.create`), and the Roles & Permissions checkboxes
were bound via `wire:model="staffGrants.{{ $permission->value }}"` —
Livewire's dot-notation property binding parses *every* dot as a
nesting level, so this silently wrote into a nested array instead of
the flat one, meaning no checkbox actually worked. Fixed by keying the
grant arrays on the enum case's `->name` (e.g. `JobEntriesCreate`, no
dots) instead of its dotted `->value`, converting back to the value
only when persisting to `role_permissions`. Caught by a test
(`Gate::forUser($staff)->allows(...)` came back false after granting)
rather than by manual inspection — a good reminder to assert on the
actual authorization outcome, not just that a form submitted without
errors.

**Considered and declined**: `spatie/laravel-permission` — the
ecosystem-standard package for this, but more powerful than needed
(multiple roles per user, direct per-user grants, teams/guards) for
exactly 3 fixed roles; client chose to keep the hand-rolled version
already in progress rather than add the dependency and rework.

5 new tests in `PermissionsTest.php` (Super Admin bypasses every gate;
a fresh Staff denies every gate; Accountant's seeded defaults allow
create/deny modify; `users.manage` stays denied even if every other
permission is granted; toggling a `RolePermission` row immediately
changes a live gate check) plus 8 in `SettingsManagementTest.php` (user
CRUD, both self-protection safeguards, deactivation blocks login, the
Users/Roles tabs are invisible *and* return 403 server-side to a
non-admin, granting/revoking a role permission from the UI) plus one
representative test each in `JobEntryManagementTest.php` and
`InvoiceManagementTest.php` (an Accountant can create but gets 403
editing/deleting). Existing ~190 tests needed no changes — the
`UserFactory` now defaults every created user to `SuperAdmin`, so
tests written before roles existed keep exercising full access
unchanged; tests that specifically exercise permissions override the
role explicitly. 207 tests passing.

Verified live end-to-end across separate browser sessions: Super Admin
created an Accountant and a Staff user via Settings → Users; the
Accountant could create a job entry but had no Edit/Delete controls
anywhere (including on Tiffin department batches — caught one missed
spot, a batch "Edit" link that wasn't gated, found only by seeing it
render for a real Accountant session) and a direct URL to an edit route
403'd; a zero-permission Staff user hit 403 on `/job-entries/create`
and saw the new "ask your Super Admin" Dashboard empty state instead of
financial totals; granting `job_entries.create` to Staff from Roles &
Permissions immediately turned that same route into a 200 on the next
request, with no cache/session issue. Checked dark mode and mobile
(390×844) on the new Settings tabs.

## Daily report & unbilled-statement alert emails (done, 2026-09-03)

Two recurring emails to every active Super Admin (`role = super_admin`,
`is_active = true`) — no new settings UI, since that recipient set is
already fully derived from existing role/is_active data:

- **Daily report** (`report:daily`, scheduled 8:00 PM Asia/Dhaka) — an
  end-of-day snapshot: today's entry count/total, unbilled total/count,
  outstanding (due) total, month-to-date total, and the "Ready to
  Invoice" breakdown (same company+category grouping as the Dashboard).
- **Unbilled statement alert** (`report:unbilled-alerts`, scheduled
  9:00 AM Asia/Dhaka) — flags a company+category group once its oldest
  unbilled entry is older than `config('reports.unbilled_alert_aging_days')`
  (default 14, env-overridable, same pattern as `config/tiffin.php`).
  Confirmed with the client: aging-only trigger (not dollar amount), and
  re-sent once then weekly while unresolved rather than every day — a
  new `unbilled_alerts` table (`company_id`, `service_category_id`,
  `last_alerted_at`, unique together) tracks the dedup state via
  `App\Models\UnbilledAlert`.

Both commands send synchronously (`App\Mail\DailyReportMail` /
`UnbilledAlertMail`, not `ShouldQueue`) since they only ever run from
the scheduler — no web request is waiting — and there's no queue worker
process configured in this deployment yet; queuing them would risk a
silent no-op. Each recipient gets their own `Mail::to()->send()` call
rather than one email addressed to all Super Admins together. Markdown
mail views (`resources/views/mail/daily-report.blade.php`,
`unbilled-alert.blade.php`) use Laravel's built-in `<x-mail::message>`/
`<x-mail::table>`/`<x-mail::button>` components — no `vendor:publish`
needed, no visual customization requested yet.

`routes/console.php` registers both via `Schedule::command(...)
->dailyAt(...)->timezone('Asia/Dhaka')` — this project's existing
timezone convention (`.ai/rules/config.md`). Nothing in-app triggers
the scheduler itself: the production/Coolify server needs a system cron
entry (`* * * * * php artisan schedule:run`), a one-time deployment
step outside this repo — recorded as a project memory so it isn't
missed at deploy time.

`tests/Feature/DailyReportTest.php` and `UnbilledAlertTest.php` (8 new
tests, `Mail::fake()`) cover: recipients limited to active Super Admins
only; report data accuracy (unbilled total spot-check); aging threshold
(older entry alerts, younger doesn't); the once-then-weekly dedup
(immediate re-run sends nothing, backdating `last_alerted_at` past the
resend window sends again); a fully-billed group with a stale alert row
never re-alerts; nothing sends with zero active Super Admins even if a
group qualifies. 215 tests passing (up from 207).

Manually ran both commands against the seeded dev DB
(`MAIL_MAILER=log`) and read the rendered output in
`storage/logs/laravel.log` to check formatting — caught and fixed two
real bugs this way that the tests didn't: Carbon 3 changed `diffIn*`
methods to return signed floats by default (Laravel 11/12 upgrade
note), so the "Days" column was showing e.g. `-30.594185045081`
instead of `31` — fixed by passing `absolute: true` and rounding to
int; and the alert subject/body had a subject-verb agreement bug for
the singular case ("1 group need/have invoicing") — fixed with an
explicit singular/plural branch instead of relying on pluralization
alone.

## In-Charge now selects a real User account (done, 2026-09-03)

In-Charge was previously a standalone lookup entity (`in_charges` table
— name/phone, no login) that anyone could grow inline from the Job
Entry form's "+ Add new in-charge…" option. The client asked for
In-Charge to instead come from Users created via Settings — any active
user regardless of role (not just Staff), since the client didn't want
a role restriction.

`in_charge_id` on `job_entries` now references `users.id`
(`nullOnDelete()`, same as before) — folded directly into
`create_job_entries_table` per the pre-launch migration convention,
since `users` is already created earlier in migration order (no
reordering needed, unlike the earlier `invoice_id` FK fix). The
standalone `in_charges` table/model/factory/seeder are deleted
entirely rather than kept unused. `JobEntry::inCharge()` now points at
`App\Models\User`.

The Job Entry form's inline "add new in-charge" flow (separate
`addingInCharge`/`newInChargeName`/`newInChargePhone` properties, its
own validation branch) is removed — In-Charge is strictly a dropdown of
`User::where('is_active', true)` now, matching how the Tiffin
batch-edit form already worked (it never had a quick-add). Creating a
new In-Charge means creating a User via Settings → Users, same place
Accountant/Staff accounts are created.

`tests/Feature/JobEntryManagementTest.php`: replaced the "in-charge
quick-add" test with one asserting In-Charge selects an existing active
user, and added one confirming an inactive user's name never appears in
the dropdown. 216 tests passing (up from 215).

**Correction (same day)**: initially seeded "Mr. Monir"/"Sagor Vai" as
real Staff-role User accounts in `JobEntrySeeder` so the demo data had
someone to show as In-Charge. The client pointed out these two never
actually had accounts created for them — the seed data was inventing
placeholder logins that don't correspond to anyone the Super Admin
actually set up. Reverted: `JobEntrySeeder` no longer creates or
assigns any In-Charge; every seeded job entry's `in_charge_id` is null
until a Super Admin creates a real account via Settings → Users and
starts assigning it. This matches the actual intent — In-Charge should
only ever list accounts that were deliberately created, never
synthesized ones.

## Bill Statement: search by invoice number (done, 2026-09-03)

Added a search box (`resources/views/livewire/bill-statement/bill-statement.blade.php`)
alongside the existing Company/Year/Month/Status filters, matching the
`#[Url(as: 'q', history: true)] public string $search` +
`updatingSearch(): void { $this->resetPage(); }` pattern already used by
`companies/company-list.blade.php`.

Search only ever matches invoiced rows — a case-insensitive substring
match on `Invoice::invoice_number` (e.g. typing "AAL-DBL" or "0925"
finds "AAL-DBL-0925-01"). A pending (not-yet-invoiced) row never
matches, since it has no invoice number yet. Considered also matching
the invoice's raw numeric primary key ("bill id" was in the original
ask), but that id is never displayed anywhere in the app — the only
identifier a user ever sees is the invoice number — so matching on it
would let a short numeric search silently false-positive against
unrelated invoice numbers that happen to contain the same digits (e.g.
searching "1" would match both "...-0925-01" and "...-0825-01" via
their trailing "-01"), without ever being something a user could
intentionally type. Caught by the test before shipping, not by
inspection — the first implementation attempt did exactly this and
`BillStatementTest` failed with 2 matches instead of 1.

`tests/Feature/BillStatementTest.php` gained one test: search matches
by a substring of the invoice number (case-insensitive), and a pending
row is never returned regardless of search term. 217 tests passing (up
from 216).

## Bill Statement: Signed indicator (done, 2026-09-03)

Client wanted the Bill Statement to reflect that a bill was sent to the
factory and signed, independent of payment status (a bill can be
signed and still unpaid). Rather than add a new `sent_at` column and a
"Mark as Sent" action, the existing `signed_copy_path` (already
uploaded on the Invoice Detail page — see "Signed bill copy storage")
is reused as the single source of truth: uploading a signed copy is
itself proof the bill was sent, since one can't happen without the
other in practice. No migration needed.

Each Bill Statement row now carries a `hasSignedCopy` boolean
(`invoice->signed_copy_path !== null`, always `false` for a pending/
not-yet-invoiced row). Shown as a small "Signed" badge next to the
existing Due/Partial/Paid status badge — kept as a separate badge
rather than merged into one combined label, since payment status and
signed status are independent facts (e.g. "Due" + "Signed" together
means exactly what was asked for: sent, signed, still unpaid).

`tests/Feature/BillStatementTest.php` gained one test confirming the
flag is true only for an invoice with a signed copy on file, regardless
of its payment status. 218 tests passing (up from 217).

**Follow-up (same day)**: client asked that the Signed badge not show
once a bill is fully Paid — its purpose is to flag "signed but still
owed," which stops mattering once payment is complete. The underlying
`hasSignedCopy` flag is untouched (still reflects the real fact), only
the badge's render condition gained `&& $row->status !== 'paid'`. New
test confirms a paid+signed invoice renders no "Signed" badge while a
due+signed one still does. 219 tests passing (up from 218).

## Staff salary tracking (done, 2026-09-03)

Client wants to track salaries for all staff — drivers, laborers, floor
supervisors, office staff — most of whom will never need or want an app
login. Built as a new `Employee` entity independent of `users`, mirroring
the app's existing Company → Bill Statement → Invoice Detail hierarchy:

- **Employees** (`app/Models/Employee.php`, `employees` table: name,
  phone, position, `monthly_salary`, `is_active`, remarks) — the roster,
  CRUD'd via `employees/*` routes and `livewire/employees/employee-list`
  + `employee-form`, directly modeled on `companies/company-list` +
  `company-form`.
- **SalaryPayment** (`app/Models/SalaryPayment.php`, `salary_payments`
  table: `employee_id`, `for_month` — stored as the 1st of the month it
  pays, `amount`, `paid_on`, `payment_method`, remarks, `created_by`) —
  mirrors `invoice_payments` exactly. No unique constraint on
  `(employee_id, for_month)`: multiple partial payments in the same
  month are allowed, same as invoice payments.
- **Staff Salaries** (`livewire/staff-salaries/staff-salaries.blade.php`,
  route `staff-salaries.index`) — the monthly cross-employee view
  (`<input type="month">`-driven, defaulting to the current month):
  Expected/Paid/Balance/status per active employee, with Due/Partially
  Paid/Paid totals — modeled on Bill Statement's per-company monthly
  view but simpler (no year+month dropdown pair needed since payroll is
  inherently single-month-scoped, just a plain month picker).
- **Employee Detail** (`livewire/employees/employee-detail.blade.php`,
  route `employees.show`) — this month's status card, a "Record
  Payment" modal, and full payment history with remove — modeled on
  `invoices/invoice-detail`'s payment section minus everything
  print/VAT/signed-copy specific, since none of that applies to payroll.
  A `?month=YYYY-MM` query param (same convention `job-entries.create`
  uses for `?company=`) lets the Staff Salaries page's "Record Payment"
  link jump straight into the modal pre-filled for that month.

Status (Due/Partially Paid/Paid) is computed live from
`sum(salary_payments.amount) vs monthly_salary` for the relevant month
— never a stored column, same pattern as Bill Statement's pending rows
and Invoice's derived status. Known v1 simplification: `monthly_salary`
is a single current rate, not a rate history, so a past raise/cut
applies retroactively to how old months are judged — acceptable for a
first version per the client's own scoping, revisit only if it becomes
a real problem.

Permissions: 5 new `App\Permission` cases (`EmployeesView/Create/Modify`,
`SalaryPaymentsCreate/Modify`), grouped "Staff Salaries". Accountant
gets the same create-only rule as every other module (seeded via
`RolePermissionSeeder`); Staff gets nothing by default, as always.
`staff-salaries.index` reuses `employees.view` rather than adding a 6th
permission — same underlying data, just sliced differently.

New sidebar link (`@can('employees.view')`) points at Staff Salaries
(the operational view), not the Employees roster — same pattern Bill
Statement uses over a raw Invoices list.

`tests/Feature/EmployeeManagementTest.php` (9 tests) and
`tests/Feature/SalaryPaymentTest.php` (6 tests): CRUD, search,
delete-blocked-when-has-payments, Accountant create-but-403-on-edit,
partial→full payment status transitions, a payment in one month never
bleeding into another month's status, payment removal recalculating
status back down, Accountant record-but-403-on-delete, and the
`?month=` deep-link. `PermissionsTest.php` extended with the new
Accountant grants (the "every permission" loops already covered the 5
new cases automatically). One test-only gotcha hit along the way:
`assertDontSee('Paid')` false-failed because the Record Payment modal's
"Paid On" label is always in the DOM (just Alpine-hidden), so "Paid" is
a substring match regardless of actual status — fixed by asserting on
`viewData('statusLabel')` directly instead of scraping rendered HTML
text, everywhere status needed checking in these tests.

Manually verified via tinker against the real dev DB: two 5,000 partial
payments against a 15,000 monthly salary correctly summed to
"Partially Paid" (10,000/15,000), a third payment flipped it to
"Paid" (15,000/15,000), and a different month for the same employee
independently stayed at 0 paid / Due throughout. 234 tests passing (up
from 219).

## Expense tracking (done, 2026-09-03)

Client wants to record business expenses — transport cost, tea bills,
cash advances taken from accounts. Confirmed free-text only (no managed
category list like Service Categories), and kept as a standalone ledger
rather than feeding into the Dashboard's Profit panel (which is about
per-job billing margin, a different concept from general overhead).

Built as a single new `Expense` entity (`expenses` table: `expense_date`,
`amount`, `description` — the free-text field carrying "Transport cost
today", "Tea bill", "Cash advance to Karim", etc. — `remarks` for
overflow detail, `created_by`) and one Livewire component,
`livewire/expenses/expense-manager.blade.php`, modeled directly on
`tiffin-purchases/purchase-manager.blade.php`'s "Purchases" tab (same
modal-based add/edit, year/month filter, running total card) rather
than the multi-page Companies/Employees pattern — a flat ledger doesn't
need a per-row detail page. Single route `expenses.index`; add/edit/
delete all happen via modals on that one page.

Permissions: 3 new cases (`ExpensesView/Create/Modify`), grouped
"Expenses". Accountant gets the usual create-only grant (view + create,
never modify) via `RolePermissionSeeder`; Staff gets nothing by
default. New sidebar link (`@can('expenses.view')`) after Staff
Salaries.

`tests/Feature/ExpenseManagementTest.php` (10 tests): listing, year/
month filtering (including the total-for-scope figure), create/edit/
delete, required-field validation, Accountant create-but-403-on-edit-
or-delete, Staff forbidden outright. `PermissionsTest.php` extended
with the new Accountant grant. 244 tests passing (up from 234).

## Pagination audit (done, 2026-09-03)

Client asked to confirm every data table is correctly paginated.
Surveyed all 33 Livewire components; the substantive listing screens
(Companies, Job Entries, Bill Statement, Tiffin Purchases, Daily
Summary, Employees, Expenses) already paginate correctly — found and
fixed 4 real gaps, all growing, unbounded lists that had none:

- **`settings/user-manager.blade.php`** (Users list) — was a bare
  `User::orderBy('name')->get()`. Added `WithPagination` +
  `simplePaginate(10)`.
- **`employees/employee-detail.blade.php`** (a staff member's salary
  Payment History) — grows without bound over years of employment.
  Added pagination; `recordPayment()` now calls `resetPage()` so a
  freshly recorded payment (newest-first) is visible immediately
  regardless of which page was open.
- **`invoices/invoice-detail.blade.php`** (an invoice's Payment
  History) — same fix, plus the same `resetPage()` on `recordPayment()`.
- **`staff-salaries/staff-salaries.blade.php`** (built earlier today,
  already missing pagination) — added the same manual
  `LengthAwarePaginator`-around-a-slice pattern Daily Summary uses for
  its own computed collections, since rows here come from a
  status-filtered `map()`, not a raw query.

The one bug class worth flagging: naively pagination-wrapping a list
whose *sum* feeds a displayed total silently breaks that total — it
would only reflect whichever page happened to be on screen. Both
Invoice Detail's `balanceDue`/`totalPaidViaPayments` and Staff
Salaries' `totalExpected`/`totalPaid`/`totalOutstanding` compute from
the full unpaginated set (an extra query / a pre-slice collection
sum), only the *display* list is paginated — this was already the
pattern Bill Statement and Expenses used for their own totals, just
hadn't been applied to these two yet.

Screens deliberately left unpaginated, not overlooked: Dashboard's
"Ready to Invoice"/"Today's Activity" (bounded snapshot widgets, full
data lives at Bill Statement/Job Entries), Company Detail's "Recent
Entries" (explicit `limit(30)` + a "View All →" link to the real
paginated Job Entries list), and the small admin-managed reference
lists (Service Categories, Tiffin Departments/Items, Roles &
Permissions) — these are short, deliberately bounded catalogs, not
growing transactional data.

4 new tests added (`InvoiceManagementTest`, `SalaryPaymentTest` ×2,
`SettingsManagementTest`), each asserting the specific failure mode
that matters: only one page's worth of rows renders, but the total/
count still reflects every row, not just the visible page. 248 tests
passing (up from 244).

## Accountant create-only rule: full audit (done, 2026-09-03)

Client asked to confirm only Super Admin can edit/delete anything, and
Accountant is strictly create-only, everywhere. Audited every
`Gate::authorize()` call (32 call sites across every module — Companies,
Job Entries, Tiffin Purchases, Invoices/Payments/Signed Copies,
Employees, Salary Payments, Expenses, Service Categories/Tiffin Items,
Settings/Users) against its actual mutating action, and every `@can`
in Blade against the matching backend check. All correctly split
`.create` (only ever used for genuinely new records — including the
subtler cases: `saveTiffinItemBatch()` always creates new `JobEntry`
rows, and `uploadSignedCopy()` only ever runs when no signed copy
exists yet, since the form only renders that far) from `.modify`
(edits, deletes, and the "already have a signed copy" removal path).
`RolePermissionSeeder`'s Accountant grant list contains zero `.modify`
cases. Service Categories/Tiffin Items intentionally give Accountant
no access at all (a single bundled `service_categories.manage`
permission, never granted) rather than a create/modify split — a
stricter outcome than asked for, not a gap. `users.manage` stays a
hard Super-Admin-only check regardless of any grant, already covered
by an existing test.

Found no backend or UI bugs — the one real gap was test coverage, not
behavior: `CompanyManagementTest` and `TiffinPurchaseManagementTest`
were the only two management modules without an explicit "accountant
can create but 403s on edit/delete" regression test (every other
module already had one). Added both, mirroring the exact pattern
already used everywhere else. 250 tests passing (up from 248).

## Public homepage (done, 2026-09-03)

Client wants a public marketing page for Rezia Enterprise — separate
concern from the internal management app this whole project has been
until now. Confirmed: the homepage takes over `/` (previously a bare
`Route::redirect('/', '/dashboard')`), with a "Staff Login" link to
`/login` for the internal app; content is a standard one-page company
site (hero, services, about, contact) using data already on file
(`config('company.*')` — name, tagline, phones, email, address, the
same values the invoice letterhead already uses — and the services
list from this project's own business summary in this file).

`resources/views/home.blade.php` — a standalone Blade view (own
`<html>` document, not the authenticated `x-app-layout` shell, same
`@vite(...)` include `layouts/guest.blade.php` already uses for the
login page), styled with the app's existing `brand-*` color scale from
`resources/css/app.css` and dark-mode variants throughout, so it looks
like the same product family as the internal app rather than a
mismatched bolt-on. No new backend logic — a static page, no contact
form (not asked for, and would need spam handling/mail wiring beyond
scope).

`routes/web.php`: `Route::view('/', 'home')->name('home')` replaces
the old redirect. The stock Laravel scaffold test asserting the old
`/` → `/dashboard` redirect (`ExampleTest.php`) was updated to assert
the new behavior instead of being left contradicting reality.
`tests/Feature/HomepageTest.php` (new, 3 tests): loads without auth,
shows services/contact details, links to Staff Login. 253 tests passing
(up from 250).

Not verified in an actual browser — no browser-automation tool was
available this session, so this was checked via `assertOk()`/`assertSee()`
HTTP-level tests and a raw response fetch, not a visual/rendered check.
Worth a real look before treating it as launch-ready, and note per
Boost guidelines: if the client doesn't see the new page reflected,
`npm run build` (or `npm run dev`/`composer run dev`) may be needed to
compile the frontend assets.

## Homepage visual redesign (done, 2026-09-03)

Client asked for a more professional/modern look, and specifically to
verify it visually via Playwright + Chromium rather than judging from
markup alone. No Playwright MCP tool was available, so it was installed
ad hoc: `npx playwright install chromium` (global npm cache, not added
to `package.json` — no project dependency change) and driven via a
throwaway Node script in the scratchpad directory, screenshotting
desktop/tablet/mobile plus a dark-mode pass and the mobile-menu
interaction, viewed via the `Read` tool. Screenshots and the script
were deleted after use — nothing under version control.

Redesign, still zero new npm dependencies:
- **Distinct icon per service/value-prop** (`x-home.service-icon`,
  `resources/views/components/home/service-icon.blade.php`) — 13 hand-
  drawn line icons (tiffin carrier, two-person labor, brick stack, fuel
  drop, forklift crate, ETP droplet, plus the "Why Choose Us" and
  contact-card icons), replacing the original build's one generic
  checkmark reused everywhere.
- **Two-column hero** with a decorative "service summary" card (2×2
  icon grid + "One vendor" badge) standing in for a hero photo, plus
  soft blurred gradient blobs behind it — there's no real product
  photography to use, so an abstract composition fills that role
  instead of a stock-photo placeholder.
- **New "Why Rezia Enterprise" section** — four honest, qualitative
  value props (single vendor, BEPZA zone experience, consistent daily
  service, direct communication). Deliberately no fabricated numbers
  ("10+ years", "50+ clients") since none of that is real data on file.
- **Gradient CTA band** before the contact section.
- **Working mobile navigation** — the original build's nav links simply
  vanished below the `sm` breakpoint with no menu at all. Fixed with a
  pure-CSS checkbox-driven disclosure (no JS dependency): the checkbox
  and every element that reacts to it sit inside one `group` (the
  `<header>`), toggled via `group-has-[:checked]:*`. Worth noting for
  future editors of this file: a plain `peer-checked:` was tried first
  and silently didn't work, because `peer-*` only ever matches a literal
  DOM sibling of the checkbox — none of the icons or the mobile panel
  here are actually siblings of it, just nested descendants of one.
  `group-has-[:checked]:` matches regardless of nesting depth, which is
  what this pattern actually needs.
- **Hover/transition polish** throughout (card lift-on-hover, icon
  color inversion on hover, button transitions) and a richer 3-column
  footer.

No test changes needed — `HomepageTest.php`'s content assertions still
matched the redesigned markup. Full suite still 253 passing.
