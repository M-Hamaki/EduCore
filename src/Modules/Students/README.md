# Students module

This is the first PSR-4 module in EduCore's incremental modular-monolith migration.

## Ownership

- Student profile commands, request mapping, lifecycle, guardians, relationships, attachments, enrollment, archive/restore policy, operational eligibility guard, list queries, and profile queries.
- Student page presentation fragments under `Presentation/`.

## Compatibility

- Public entrypoints and URLs remain under `admin/`, `student/`, and the existing role folders.
- Files under `classes/Student*.php` are compatibility aliases for legacy global class names.
- New module code uses the `EduCore\Modules\Students` namespace and is loaded through Composer PSR-4.
- Do not add new student business logic to compatibility files.

## Dependency rule

The current migrated implementation may call confirmed legacy shared services through explicit imports while those services remain outside `src/`. New cross-module behavior must use the smallest documented contract rather than reaching into another module's private files.

## Rollback

Restore the implementations and presentation fragments to their previous `classes/` paths, remove the PSR-4 mapping, regenerate Composer autoload files, and rerun the student contract/integration tests.

For the archive feature specifically, first restore rows with `role='student' AND archived_by IS NOT NULL` by setting `status` from `status_before_archive`, clearing `deleted_at`, and clearing the archive metadata. After verifying the restored population, a reviewed rollback migration may remove `idx_users_role_deleted_at`, `archived_by`, `archive_reason`, and `status_before_archive`; the pre-existing `deleted_at` column remains owned by the data-safety migration.
