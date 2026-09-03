---
paths:
  - 'config/*.php'
---

# Config

## App timezone is Asia/Dhaka, MySQL connection pinned to match
The client's business operates in Bangladesh, so `config('app.timezone')` is `Asia/Dhaka` (not UTC) — every `now()`, `Carbon::now()`, and default date affects business logic (entry dates, invoice periods, "most recent job entry" lookups) in that timezone.

`config/database.php`'s `mysql` connection also sets `'timezone' => '+06:00'` so MySQL's own `NOW()`/`CURDATE()` agree with PHP's `now()`. A fixed offset is used (not the IANA name) since MySQL's named-timezone support depends on the `mysql.time_zone_name` tables being loaded, which isn't guaranteed on this WAMP install — Bangladesh has no DST, so a fixed `+06:00` is always correct.

This directly fixes a previously-documented class of bug (UTC-vs-local date mismatches during manual/raw-SQL testing). Never reintroduce a mismatch between the two settings.
