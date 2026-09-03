---
paths:
  - 'app/**/*.php'
---

# App

## Carbon 3's diffIn* methods return signed floats — pass absolute: true
This project runs Carbon 3 (bundled with Laravel 12). Unlike Carbon 2, `diffInDays()`/`diffInHours()`/etc. now return signed floats by default (e.g. `-30.594185045081` instead of `31`) — the sign depends on which date is earlier, and the fraction includes partial-day precision.

Always pass `absolute: true` when you want a plain "how many days between these" count, and round/cast to int if a whole number is expected: `(int) round($a->diffInDays($b, absolute: true))`.

Caught in `app/Console/Commands/SendUnbilledAlerts.php` — the "Days" column in the unbilled alert email showed a negative fractional number until this was fixed. Check for the same mistake anywhere else `diffIn*` is used bare.
