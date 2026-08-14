# File Upload And Storage Standard

`AGENTS.md` is the mandatory rules source. This document is the implementation reference for ADR-013 and ADR-017.

## Scope

Apply this standard to user uploads, profile attachments, educational materials, timetable images, logos, templates, imports, generated media accepted from a request, and any future file replacement or deletion workflow.

## Required design

1. Authenticate and authorize before reading upload input, then validate CSRF for state-changing requests.
2. Validate `UPLOAD_ERR_*`, size, final extension, dangerous intermediate extensions, and detected MIME through `FileUploadGuard`.
3. Generate the stored name with cryptographic randomness. Preserve the basename of the original name only as escaped display metadata.
4. Store a relative path or identifier, never an environment-specific URL or filesystem path.
5. Use `APP_URL` only when an absolute external URL is required. Internal navigation should be relative or derived from the current request URL.
6. Store sensitive files below `storage/private/` and stream them through an authorized controller. Public assets may remain below `uploads/` only when direct access is intended.

## Database and filesystem sequence

### Create

1. Validate and move the new file.
2. Insert the database row.
3. If insertion fails, delete the new file and log the diagnostic error.

### Replace

1. Validate and move the replacement under a new random name.
2. Update the database reference.
3. If the update fails, delete the replacement and retain the old reference/file.
4. After the update succeeds, delete the old file.

### Delete

1. Load and authorize the target record.
2. Delete or clear the database reference.
3. After the database operation succeeds, delete the old file.
4. A failed physical deletion is an operational cleanup finding; it must not restore an unsafe or stale public database reference implicitly.

## New workflow checklist

- [ ] Owner, users, roles, and public/private classification are documented.
- [ ] Allowed extensions, MIME map, maximum bytes, and upload count are explicit.
- [ ] `FileUploadGuard` is used and the original name never selects a filesystem path.
- [ ] Stored database value is relative and external links use `APP_URL`.
- [ ] Create, replace, delete, and rollback order is covered where applicable.
- [ ] `tools/upload_policy_manifest.json` contains the reviewed path and classification.
- [ ] Unit/contract tests cover spoofed MIME, double extension, size/error paths, collisions, authorization, and rollback.
- [ ] `composer upload-policy-audit`, `composer architecture-audit`, lint, and relevant role tests pass.
- [ ] Staging-only failure injection from `docs/upload-verification-plan.md` is recorded when the environment is available.

## Deployment

Set `APP_URL` without a trailing slash, for example:

```dotenv
APP_URL=https://school.example.com/EduCore
```

Before deployment, verify all of the following:

- PHP is 8.0 or newer and the `fileinfo` and `pdo_mysql` extensions are enabled. `composer install` must pass its platform-requirement check.
- PHP accepts the application's 10 MiB upload limit; use at least `upload_max_filesize=12M` and `post_max_size=16M`. Configure an equivalent request-body limit in Nginx, LiteSpeed, a reverse proxy, or the hosting control panel.
- The web-server user can create, write, and read `storage/private/profile_attachments/`, and can write the public image directories intentionally retained below `uploads/`. Prefer correct ownership with directory modes `0750`/`0755`; do not use `0777` as a permanent fix.
- User files are deployed or migrated separately from source code. `/uploads/*` and `storage/private/*` are intentionally excluded from version control, so a code-only Git deployment does not transfer existing files even when their database rows are present.
- Database rows and their corresponding files are migrated from the same backup/cutover point. A missing file must not be repaired by rewriting stored paths globally.

Run the read-only CLI preflight on the target server before switching traffic:

```powershell
composer upload-runtime-check
```

The command must finish with `UPLOAD_RUNTIME_ERRORS=0`. Warnings for the reverse-proxy body limit and separate user-file migration require manual confirmation because PHP CLI cannot verify those external deployment layers.

Apache must honor the tracked `uploads/.htaccess`. Nginx or another server must implement equivalent deny-execution and no-index rules in deployment configuration; `.htaccess` is not read by those servers.

## Rollback

Code rollback restores the previous handlers and configuration alias. Never roll back file/database changes by deleting directories or rewriting stored paths globally. Any data migration requires a backup, dry run, scoped update, verification report, and a separate rollback procedure.
