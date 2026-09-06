---
paths:
  - 'resources/views/livewire/job-entries/**'
---

# Job Entries

## The 8 real service categories and their category-specific fields
Source of truth: database/seeders/ServiceCategorySeeder.php. This is real client business data (Rezia Enterprise's actual service lines), not a placeholder list — never add, rename, or remove a category without the client confirming a real new/changed service line.

| Category | invoice_code | unit_label | Category-specific field(s) in job_entries |
|---|---|---|---|
| Tiffin | TIF | pieces | tiffin_department_id + per-item batch entry (TiffinDepartmentItem: Banana/Egg/Bread etc) — quantity/cost/bill come from the batch, never typed directly |
| Diesel Oil Supply | Diesel | litres | challan_no |
| ETP Rubbish Removing | RRW | trips | none extra |
| Daily Basic Labour | DiBL | workers | none extra |
| Loading Unloading | Load-Unload | trips | per-item batch entry (LoadingUnloadingItem: Big/Small/Wash/Wash Big/Machine Set/Daily Labour/Bosa Gari, each with its own unit_label) — quantity/cost/bill come from the batch, never typed directly on create. `floor` still exists but is legacy-only (no longer required, only shown editing a pre-batch entry) |
| Embroidery & Print | EMB | pieces | buyer, style |
| Construction Material Supply | CMS | units | none extra |
| ETP Eid Holiday | ETP-EID | project | company_adv_payment; quantity is unused (null) — Cost/Bill Amount are typed directly instead of quantity × rate |

Pattern in job-entry-form.blade.php: each extra field is shown/hidden via x-show keyed on $categoryIds[name] (from ServiceCategory::pluck('id','name')), and nulled out both in updatedServiceCategoryId() and defensively again in save(). Follow this same pattern for any future category-specific field.

## Loading Unloading item list is real client data too
Source of truth: database/seeders/LoadingUnloadingItemSeeder.php, sourced from an actual Rezia Enterprise bill to Simba Fashion (Bill No. 230, Jul-2026) — a real day can have several of these on one bill, each independently priced and unit'd (e.g. "Big" per Cover Van, "Daily Labour" per Person). Same rule as the service category list: don't add/rename/remove an item without the client confirming it against a real bill. `job_entries.unit_label` is only ever populated by this batch flow (`saveLoadingUnloadingBatch()`); every other category leaves it null.
