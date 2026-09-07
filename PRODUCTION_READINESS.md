# Rezia Enterprise — Production Readiness Checklist

Audited the app end-to-end for running the real business day-to-day: environment config, file storage durability, scheduled jobs, mail, sessions/cache, and the reverse-proxy setup Coolify puts in front of it. Split into what's fixed in code already, what's fine as-is, and what only you can fix (server/hosting-dashboard config, credentials) — I can't see or change your Coolify project settings from here.

## 🔴 Critical — verify/fix before relying on this for real business data

### 1. Uploaded files must survive a redeploy
Every one of these is a real business document, stored on local disk (`storage/app/public`), not a database row:
- Purchase memos (`company-purchase-memos/`)
- Signed bill copies — proof a bill was delivered and accepted (`signed-bills/`)
- Check screenshots (`check-screenshots/`)
- Money receipt photos — proof cash was paid (`company-purchase-payment-receipts/`)
- Company logo

**If Coolify doesn't have a persistent volume mounted at `storage/app` (or `storage/app/public`), every redeploy wipes all of these out permanently.** This is the single biggest risk in the app right now, and it's grown with every document-upload feature added this session. Go into the Coolify service's Storage/Volumes settings and confirm a persistent volume is mounted there. If it isn't, mount one before the next deploy — otherwise the very next bill you upload could vanish on the deploy after.

### 2. Confirm backups actually cover this storage volume, not just the database
The existing understanding is that Coolify handles backups at deploy — but confirm that scope explicitly includes the `storage/app/public` volume from #1. A database-only backup would silently lose every signed bill and receipt while looking like a complete backup.

### 3. Production `.env` must differ from local in several places
None of this touches the repo (`.env` is gitignored, correctly) — this is a checklist for whatever `.env` actually lives on the production server:
- `APP_ENV=production`, `APP_DEBUG=false` — debug mode on a live server leaks stack traces (including database credentials in error pages) to anyone who hits an error.
- `APP_URL=https://<real-domain>` — wrong now (`http://localhost:8000` locally); affects every generated link, including ones in emails.
- `SESSION_SECURE_COOKIE=true` — unset by default; without it the session cookie can be sent over plain HTTP even though the site is HTTPS.
- `MAIL_MAILER` + real SMTP host/username/password — currently defaults to `log` (mail is written to a log file, never actually sent). Daily reports, unbilled alerts, and agreement-deadline alerts silently do nothing without this.
- `DB_*` — production database credentials, obviously not the local WAMP ones.

### 4. The scheduler needs a real cron entry
Three emails are scheduled but **nothing runs them** unless the server itself calls Laravel's scheduler every minute:
- `report:unbilled-alerts` — 9:00 AM Asia/Dhaka
- `report:daily` — 8:00 PM Asia/Dhaka
- `report:agreement-deadlines` — 9:00 AM Asia/Dhaka

Add this cron entry on the production server (Coolify supports scheduled/cron commands per service — check its "Scheduled Tasks" section, or add a system cron job):
```
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```
Without it, these three commands simply never fire — no error, no warning, just silence.

### 5. `storage:link` must run on every deploy
The `public` disk (everything in #1) is only web-accessible via a symlink at `public/storage` pointing to `storage/app/public`. If Coolify's build/deploy script doesn't run `php artisan storage:link`, every uploaded file 404s even if the volume itself is safe. Add it to the deploy command if it isn't already there (Coolify's Laravel recipe usually includes this, but confirm).

### 6. Migrations must run on deploy
Confirm `php artisan migrate --force` (the `--force` is required outside local/testing environments) is part of the actual deploy pipeline — every feature this session added a new column or table, so a deploy that skips this leaves the app broken against a stale schema.

## 🟡 Fixed in code this session

- **Trusted proxies** (`bootstrap/app.php`): added `$middleware->trustProxies(at: '*')`. Coolify's reverse proxy (Traefik) terminates HTTPS and forwards to the app container over plain HTTP — without this, Laravel doesn't know the original request was HTTPS, which breaks secure cookies and can make `route()`/`url()` generate `http://` links even on a live HTTPS site. Full test suite (405 tests) still green after this change.

## 🟢 Already fine, checked and confirmed

- **Health check**: `/up` route already configured (`bootstrap/app.php`'s `health: '/up'`) — Coolify can point its health check at this out of the box.
- **Session/cache/queue tables**: `sessions`, `cache`, `jobs`, `failed_jobs` tables all exist in migrations — the `database` driver default for sessions/cache/queue will work once migrations run.
- **No queue worker needed (for now)**: no `app/Jobs`, no Mailable implements `ShouldQueue` — mail sends synchronously from the scheduled commands, which is fine since nothing sends mail from a user-facing web request. **If a future update adds queued jobs or queued mail, a `php artisan queue:work` process (with a process supervisor) becomes a new requirement — it isn't one today.**
- **Timezone**: `config/app.php` and `config/database.php` are already correctly pinned to `Asia/Dhaka` / `+06:00` (recorded in `.ai/rules/config.md`), so this doesn't need touching for production.
- **Test suite**: 405 tests passing, covering permission boundaries, financial calculations, and every feature built this session — a strong safety net for future changes, but a safety net still needs `php artisan test` (or CI) actually run before each deploy to catch a regression before it reaches the business.

## Not urgent, but worth knowing about

- `MAIL_MAILER=log` and no queued mail means the 3 scheduled commands currently send mail **synchronously inline** — if SMTP is slow or briefly down, the command (and the cron minute it runs in) blocks until it times out. Not a problem at this app's scale, but worth knowing if report emails ever start taking noticeably long.
- No rate limiting is configured beyond Laravel/Livewire's own defaults (login throttling is already Breeze-standard). Not flagged as urgent for an internal business tool with a small, known user base, but worth a look if the app is ever exposed more broadly.

## Suggested order of operations for your next deploy

1. Confirm the Coolify volume for `storage/app/public` (item 1) — do this **before** anyone uploads another document.
2. Set the production `.env` values (item 3).
3. Add the scheduler cron entry (item 4).
4. Confirm `storage:link` and `migrate --force` are both in the deploy pipeline (items 5–6).
5. Deploy, then hit `/up` and try one real action (e.g. open Bill Statement) to confirm the app is actually live end-to-end.
