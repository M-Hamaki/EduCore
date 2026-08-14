# Staging and production runbook

## Preconditions

1. Use a separate staging database and a copy of production uploads.
2. Set `APP_ENV=production`, HTTPS, and distinct secrets in each environment.
3. Keep `phpmyadmin/`, `archive/`, `.env`, backups, and maintenance tools outside the public document root.

## Staging

1. Restore the latest database and uploads backup.
2. Run `php tools/run_migrations.php`.
3. Run `composer install --no-dev --classmap-authoritative` and `composer audit`.
4. Run `composer lint`.
5. Test every role, student/staff creation, evaluation, report, attendance, Excel import, SSO, and Teams.
6. Verify unauthenticated admin access returns a redirect or 401/403, CSRF failures return 419, and unauthorized class IDs return 403.

## Production

1. Announce a short maintenance window and stop writes.
2. Back up the database, `.env`, and `uploads/` outside the web root.
3. Deploy code and dependencies, then run migrations once.
4. Smoke-test login, dashboard, evaluation, attendance, reports, and SSO.
5. Re-enable writes and monitor PHP, web-server, database, SSO, and queue logs for 24 hours.
6. Roll back code to `pre-stabilization-2026-06-23` and restore the matching database backup if validation fails.

Password storage remains AES-based by explicit product decision and is outside this stabilization run.
