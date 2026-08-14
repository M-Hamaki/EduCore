# Upload Verification Plan

This plan covers the public upload hardening and file/database consistency boundary adopted in ADR-017.

## Automated checks

- `tools/audit_upload_policy.php --strict`: discovers direct upload movers, requires reviewed manifest classification, verifies contract markers, rejects environment-specific upload paths, and checks the tracked public-upload boundary.
- `tests/upload_policy_audit_test.php`: proves that the current inventory passes and a newly introduced unreviewed upload handler fails the gate.
- `tests/file_upload_guard_test.php`: valid content, spoofed MIME, dangerous double extension, oversized file, partial upload, and collision resistance for concurrent names.
- `tests/app_url_config_test.php`: deployment under an HTTPS domain and a non-root base path.
- `tests/upload_storage_contract_test.php`: Apache boundary, centralized validation, and cleanup after database-write failure.
- `tests/profile_attachment_boundary_test.php`: authenticated profile-attachment downloads and private storage.
- `tests/student_attachment_service_test.php`, `tests/student_attachment_contract_test.php`, and `tests/staff_attachment_contract_test.php`: profile upload compatibility and atomic cleanup contracts.
- `tools/audit_stored_upload_urls.php`: read-only count audit for localhost URLs and local filesystem paths in known upload columns.

## Staging-only failure injection

Run these checks only against an isolated test database and disposable upload directories:

1. Interrupt a large request mid-upload and confirm that no database row or final file is created.
2. Make the target upload directory read-only and confirm a user-safe error with no database change.
3. Force the repository update/insert to throw after moving a file and confirm the new file is removed while the old file remains.
4. Fill a disposable filesystem or apply a temporary quota and confirm the same rollback behavior.
5. Start concurrent uploads with identical original names and confirm unique stored names and valid database rows.
6. Attempt profile-attachment download as each role and anonymously; only the currently approved admin boundary may succeed.
7. Deploy below an HTTPS base path, set `APP_URL`, and verify copied material-preview links from an external browser.

Production data and the `educore` database must not be used for failure injection.

## Required gates

Run `composer upload-policy-audit` for upload-related changes and `composer quality` before merge. The tracked GitHub Actions workflow runs the same quality command for pull requests and pushes to `main` or `master`. The database URL inventory remains a deployment/staging check because CI must not connect to the operational database.
