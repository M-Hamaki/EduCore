# Staff module

This PSR-4 module owns the staff-profile workflows extracted from the legacy admin page.

## Ownership

- Staff profile request mapping, payload normalization, commands, attachments, deletion, profile/list queries, and their presentation fragments.
- Employment lifecycle, attendance, leave, accounts, permissions, and finance remain in their existing owners until separately migrated.

## Compatibility

- `admin/staff.php` remains the public entrypoint with unchanged forms, fields, redirects, permissions, and SQL behavior.
- `classes/Staff*.php` files for the migrated classes are compatibility aliases for legacy global names.
- Module code uses `EduCore\Modules\Staff`; new business logic must not be added to compatibility files.

## Transitional dependencies

The migrated profile workflow explicitly imports confirmed legacy shared services such as `User`, `StaffEmploymentLifecycleService`, `ProfileAttachmentStorage`, audit, validation, and classroom compatibility. These dependencies are not private internals of another migrated module.

## Rollback

Restore the implementations and presentation fragments to their former `classes/` paths, remove the staff aliases/bootstrap, and rerun staff contract/render tests. No schema or data migration is part of this move.
