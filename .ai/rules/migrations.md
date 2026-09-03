---
paths:
  - 'database/migrations/**'
---

# Migrations

## Fold schema changes into existing migrations while pre-launch
The app has not gone live yet, so there is no production data to preserve across incremental migrations. Default to editing the relevant table's existing `create_*_table` migration (add/remove the column there) rather than creating a new `add_x_to_y_table` migration, then reset with `php artisan migrate:fresh --seed --force`.

If a new FK column's target table is created *later* in migration order, first re-timestamp the target's `create_*_table` migration to run earlier (as long as nothing between the two depends on the reordered table), then fold the column into the referencing table's create migration. `job_entries.invoice_id` → `invoices` was fixed this way: `create_invoices_table` was moved to `2026_08_28_195054` (before `create_job_entries_table`), so `invoice_id` now lives directly in `create_job_entries_table` with no separate `add_invoice_id_to_job_entries_table` migration.

Revisit this convention once the app goes live and real data must be preserved — at that point switch back to additive migrations.

Confirmed with the client (2026-09-03): all current dev-database content, including things that looked like real usage (an uploaded logo, a generated invoice), is disposable test data, not production data. `php artisan migrate:fresh --seed --force` stays the default reset after schema changes — an earlier session briefly assumed otherwise and used an additive migration for `users.role`/`is_active` before this was corrected back to folding into `create_users_table`.
