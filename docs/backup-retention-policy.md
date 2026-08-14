# Backup retention policy

## Scope

This policy distinguishes scheduled SQL dumps from protected change snapshots and user uploads.

## Scheduled SQL dumps

- The admin setting `db_backup_sql_retention_days` is the intended retention control; its default is 14 days and `0` means keep all dumps.
- Automatic deletion may apply only to dumps created by the scheduled SQL-backup workflow in its configured dump directory.
- Before enabling deletion, verify that a current dump can be restored into an isolated database.

## Protected snapshots

The following content must not be removed by a generic age-based cleanup:

- `storage/backups/pre_*` change and migration snapshots.
- attachment migration manifests and rollback evidence under `storage/private/`.
- backups named by architecture decisions, implementation closure records, or rollback instructions.
- the latest known-good pre-deployment backup.

Protected snapshots require an owner, the associated change identifier, and explicit manual approval before deletion.

## Academic-year recovery packages

- Packages under `storage/backups/recovery/` are protected disaster-recovery evidence, not scheduled SQL dumps.
- A rollover package is usable only while its database-content fingerprint and file fingerprint still match the source and its verified receipt has not expired.
- Verification must restore the package into a newly created database ending in `_test`, compare schema, row counts, per-table content hashes, and file hashes, then remove only that test database.
- The package associated with an activated academic year must not be removed by automatic retention. Keep it until an owner approves deletion after a later independently verified package exists and the school's legal/records-retention policy permits deletion.
- Generic cleanup must never traverse `storage/backups/recovery/`; deletion requires an explicit recovery-package workflow with audit evidence.
- The operational procedure is documented in `docs/recovery-restore-runbook.md`.

## Uploads

`uploads/` and `storage/private/profile_attachments/` contain application data, not repository clutter. Their retention must follow the owning student/staff workflow and must never be coupled to repository cleanup.

## Operational path

`DbSqlBackupManager` schedules the CLI-only `tools/backup_db_sql.php`. The default dump directory is `storage/backups/sql`, covered by the internal HTTP deny boundary. Existing installations that saved a custom path keep that path. Restore verification into an isolated database remains an operational prerequisite before relying on retention deletion; no backup files were deleted during repository cleanup.
