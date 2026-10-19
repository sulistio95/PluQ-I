# PluQ-I local preflight verification — 2026-10-19

## Environment
- Local environment: Puppy Bookworm64, PHP 8.2.34, MariaDB/MySQL-compatible local test database.
- Repository: `sulistio95/PluQ-I`.
- Local database backup created before migration 012 and moved outside the repository to `/root/PluQ-I-backups/backup-sebelum-012.sql` (37 KB). It is intentionally not committed.

## Changes pulled and verified
- Pulled commit `346770985278f16453e7db74419e9b3dc0071a18` (`Allow local HTTP/debug in development preflight`) from `origin/main`.
- `php -l bin/preflight.php`: passed, no syntax errors.
- `php bin/preflight.php`: **48/48 checks passed**.
- Verified the local database connection, required tables, and migration records through `012_pricing_rules_hybrid`.
- Preflight now permits HTTP `APP_URL` and `APP_DEBUG=true` only when `APP_ENV=local`; other environments retain the HTTPS and debug-disabled requirements.
- Checkout payments, supplier ordering, and settlement/Google Drive backup remain disabled.

## Scope and remaining blockers
- This confirms local configuration/schema readiness only. It does not verify external Duitku credentials, a real sandbox payment/callback, supplier API requests, Google Drive access, shared-hosting configuration, cron behavior, or production readiness.
- Do not enable payment checkout, supplier ordering, settlement backup, or production deployment until dedicated staging checks are completed.
- Next safe step: continue reviewing and running isolated local tests only after confirming their effects and database target. Avoid destructive queue-recovery tests on the working local database.
