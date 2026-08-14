# Implementation Plan: Safe Academic Year Rollover

**Branch**: `main` (dirty worktree preserved) | **Date**: 2026-07-18 | **Spec**: [spec.md](spec.md)

## Summary

Extend the installed safe-rollover engine with explicit year-pair promotion rules, durable student decisions, experimental/test exclusions, unassigned target enrollments, grouped preflight evidence, and class drafts independent from allocation. Keep verified database-and-files recovery, explicit mappings, manifest-owned rollback, independent verification, and protected activation.

## Scope Boundaries

- **In scope**: recovery package/restore proof, promotion-rule and student-decision schema, experimental/test controls, fixed managed annual copy, enrollment creation from approved decisions, unassigned placement, grouped preview, pre/post verification, rollback, activation/source lock, admin workflow, tests, ADR/runbook.
- **Out of scope**: multi-school/tenant/branch support, education-structure version tables, automatic class allocation, copying teacher/timetable/transport/financial operational data, unrelated UI or cleanup.
- **Compatibility baseline**: existing admin route, auth/session/CSRF, retained-student field, academic-year URLs and compatibility classes.
- **Authorized side effects**: repository file changes; isolated `*_test` database creation/import/drop for verification; protected backup package creation. No production schema migration or rollover execution.

## Technical Context

**Language/Version**: PHP >= 8.0

**Primary Dependencies**: PDO, ZipArchive, existing AuditService/UndoManager, XAMPP mysql client utilities, Bootstrap 5 RTL

**Storage**: MariaDB/MySQL; protected packages under `storage/backups/recovery`; application roots `uploads/` and `storage/private/` excluding backup subtrees

**Testing**: PHP contract/unit scripts and guarded MySQL integration tests against explicit `*_test`

**Target Platform**: Existing XAMPP/Windows deployment

**Project Type**: Server-rendered PHP modular monolith

**Performance Goals**: Preflight completes without loading row payloads into the UI; backup and rollover provide progress-safe terminal states and bounded summaries.

**Constraints**: One school only; no production-writing tests; no raw secret/command output; no runtime DDL; no conflict-ignoring inserts; no historical result copy; no grade-name/order inference during promotion.

**Scale/Scope**: One school, thousands of students and many retained years; processing must stream/file-manifest rather than hold file contents in memory.

## Constitution Check

- [x] Canonical context read and unknowns bounded.
- [x] Compatibility contracts listed.
- [x] Existing modular-monolith and compatibility adapters retained.
- [x] Auth, CSRF, secrets, transactions, files, schema, and production impact assessed.
- [x] Characterization, isolated integration tests, rollback, and stop conditions defined.
- [x] ADR/docs and strict architecture gate planned.

Post-design re-check: all gates remain satisfied; no exception required.

## Project Structure

```text
admin/academic_year_setup.php
classes/NewYearWizard.php
classes/AcademicYear.php
classes/AcademicYearWriteGuard.php
classes/RecoveryBackupService.php
classes/NewYearRolloverService.php
database/migrations/20260718_safe_year_rollover.php
database/migrations/20260719_academic_promotion_decisions.php
src/Modules/Students/StudentProfileRequestMapper.php
src/Modules/Students/StudentProfileCommandService.php
src/Modules/Students/Presentation/profile_form.php
classes/UserProfileStore.php
admin/grades.php
admin/classes.php
tools/create_recovery_backup.php
tools/verify_recovery_backup.php
tests/recovery_backup_*.php
tests/new_year_rollover_*.php
docs/backup-retention-policy.md
docs/new-academic-year-data-policy.md
docs/architecture-decisions.md
```

**Structure Decision**: Extend the existing service and route rather than add a parallel promotion framework. Existing `classes/` remains the compatibility application/infrastructure boundary; student profile writes stay in the Students module. Schema changes remain additive migrations. No school/tenant ownership layer is introduced.

## Complexity Tracking

No constitution exceptions.
