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
| Loading Unloading | Load-Unload | trips | floor |
| Embroidery & Print | EMB | pieces | buyer, style |
| Construction Material Supply | CMS | units | none extra |
| ETP Eid Holiday | ETP-EID | project | company_adv_payment; quantity is unused (null) — Cost/Bill Amount are typed directly instead of quantity × rate |

Pattern in job-entry-form.blade.php: each extra field is shown/hidden via x-show keyed on $categoryIds[name] (from ServiceCategory::pluck('id','name')), and nulled out both in updatedServiceCategoryId() and defensively again in save(). Follow this same pattern for any future category-specific field.
