# Feature Specification: Safe Academic Year Rollover

**Feature Branch**: `main` (existing dirty worktree preserved)

**Created**: 2026-07-18

**Status**: Approved for implementation (academic-decision model v2)

**Input**: User description: "تجهيز النظام لتنفيذ تهيئة عام جديد بأمان كامل، مع حماية من الكوارث عن طريق نسخة احتياطية مجرّبة الاستعادة، دون تخصيص اختياري لما يُنقل."

## Scope And Non-Scope

### In Scope

- A fixed, documented rollover policy for one school deployment and many retained academic years.
- Mandatory preflight that blocks on missing student placement, unresolved references, non-empty target-year data, invalid date mapping, or incomplete backup evidence.
- A disaster-recovery package covering the database plus public/private application files, with hashes and a machine-readable manifest.
- Mandatory restoration of every accepted recovery package into a newly created isolated database whose name ends in `_test`, followed by schema/count/integrity verification.
- Safe rollover of the annual calendar, classes, student enrollments, subject-grade assignments, and assessment scheme structure as inactive drafts with explicit ID remapping.
- Preservation without copying of attendance, evaluations, marks, published reports, transfers, payments, results, audit history, and other historical transactions.
- A persisted rollover manifest, verification report, audit batch, and targeted rollback limited to rows created by that run.
- A separate activation step after verification; activation locks the source year against protected historical writes.
- Admin-visible readiness, backup verification, execution, post-verification, rollback-before-activation, and activation states.
- Explicit grade-promotion rules for each source/target year pair; grade names and display order never decide promotion.
- Persisted student promotion decisions before backup, including promoted, retained, pending, graduated, transferred-out, withdrawn, and test-excluded outcomes.
- Experimental grades and test student accounts that are visibly marked, audited, reversible, and excluded from official rollover.
- Copied target classes remain inactive drafts; promoted and retained enrollments start without a class and are assigned later.
- Guarded integration tests against an explicit isolated database ending in `_test`.

### Out Of Scope

- User-selectable rollover categories or optional migration checkboxes.
- Automatic copying of teacher assignments, timetable entries, student bus assignments, fee amounts/installments, outstanding debt, assessment/report windows, marks, attendance, evaluations, or published results.
- Deleting or rewriting source-year historical records.
- Production deployment, push, or automatic migration execution.
- Repairing ambiguous legacy rows automatically without an evidence-backed repair decision.
- Multi-school, branch, tenant, campus, or SaaS architecture.
- Automatic student-to-class distribution or balancing; class capacity is recorded for a later explicit allocation workflow.

### Compatibility Baseline

- Preserve `admin/academic_year_setup.php`, existing session/authentication behavior, CSRF field, role requirement, year IDs, student-retention selection, and existing academic-year URLs.
- Existing callers of `AcademicYear` and `NewYearWizard` remain compatible through adapters while new orchestration owns the protected workflow.
- The target year is never activated by rollover execution itself.

## User Scenarios & Testing

### User Story 1 - Verified Disaster-Recovery Backup (Priority: P1)

As an administrator, I can create a recovery package and see that it was restored and verified in an isolated environment before rollover is allowed.

**Why this priority**: Rollover must not begin without proven recoverability.

**Independent Test**: Create a package from a guarded fixture database and fixture file roots, restore it into a unique `_test` database, and verify database/file hashes and counts.

**Acceptance Scenarios**:

1. **Given** a valid database and readable data-file roots, **When** backup verification completes, **Then** a receipt records the package hash, database fingerprint, file manifest, isolated target, verification time, and success.
2. **Given** a corrupt dump, missing file, hash mismatch, failed import, or non-test restore target, **When** verification runs, **Then** it fails closed and no verified receipt is issued.
3. **Given** a verified receipt whose source fingerprint no longer matches current state, **When** rollover execution is requested, **Then** execution is rejected and a fresh backup is required.

---

### User Story 2 - Fail-Closed Rollover Preflight and Execution (Priority: P1)

As an administrator, I can preview one fixed rollover policy, resolve every blocker, and execute an atomic migration that never silently skips a student.

**Why this priority**: Every eligible student and every managed annual dependency must be accounted for.

**Independent Test**: On a seeded isolated database, preflight blocks malformed fixtures; after repair it creates mapped annual configuration and enrollments while leaving historical tables unchanged.

**Acceptance Scenarios**:

1. **Given** any real eligible student lacks stage, grade, an approved decision, or an explicit promotion rule, **When** preflight runs, **Then** it lists grouped blockers and execution remains unavailable.
2. **Given** the target contains managed annual data, **When** preflight runs, **Then** it refuses to merge with that target.
3. **Given** approved persisted decisions, a successful current backup receipt, and clean preflight, **When** execution is confirmed, **Then** all managed rows are created in one protected workflow with explicit maps and audit evidence.
4. **Given** any execution step fails, **When** the transaction ends, **Then** database changes are rolled back and the source remains unchanged.

---

### User Story 3 - Verification, Rollback, and Activation (Priority: P2)

As an administrator, I can verify the completed rollover, roll it back before activation, or activate it only after every invariant passes.

**Why this priority**: A committed rollover still needs independent proof before becoming operational.

**Independent Test**: Execute rollover on an isolated fixture, verify counts/references, roll it back and prove only manifest-owned rows are removed; rerun and activate to prove the source becomes locked.

**Acceptance Scenarios**:

1. **Given** a completed rollover, **When** verification finds an orphan, count mismatch, missing enrollment, active copied assessment scheme, or historical row in the target, **Then** activation is blocked.
2. **Given** a verified but inactive target, **When** rollback is confirmed, **Then** only manifest-owned target rows are removed in reverse dependency order.
3. **Given** a fully verified target, **When** activation is confirmed, **Then** the target becomes active and the source is locked against protected writes.

### Edge Cases

- The target year already has classes or another managed annual configuration row.
- Source and target dates are missing, overlap, or are not chronologically ordered.
- A student is archived/inactive/transferred/graduated versus active and enrolled.
- A retained student has no source class or a promoted grade has fewer target classes.
- A promoted or retained student has no target class yet (valid and expected).
- An experimental grade appears between two official grades in display order.
- A test student has no stage, grade, or class.
- A real student has no stage or grade.
- A student decision is pending, changed after backup, or conflicts with the source enrollment status.
- A promotion rule is missing, points to an inactive/experimental grade, points to itself, or forms a cycle.
- A final grade is explicitly marked as graduation while another official grade transitions across stages.
- Backup succeeds but file archive verification or isolated database import fails.
- The database or files change after backup verification.
- The process is retried after a timeout or a partially reported external backup operation.
- A rollback is requested after activation or after target-year operational transactions exist.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST use one fixed rollover policy and MUST NOT expose per-category copy choices.
- **FR-002**: The system MUST require a current verified recovery receipt matching the database/file fingerprint before rollover execution.
- **FR-003**: A recovery package MUST include a consistent database dump, application data files, file hashes, database schema/table counts, and a manifest without secrets.
- **FR-004**: Recovery verification MUST restore into a newly created isolated database ending in `_test`, verify integrity, and clean up only the database created by that verification run.
- **FR-005**: Preflight MUST report every blocker and warning as grouped aggregate/non-sensitive evidence with optional bounded details and MUST never treat skipped students as success.
- **FR-006**: The target MUST be empty across every table managed by rollover.
- **FR-007**: Rollover MUST copy/remap calendar structure, classes, subject-grade assignments, and assessment scheme structure as drafts, then create only the enrollments authorized by persisted decisions.
- **FR-008**: Rollover MUST NOT copy historical transactions or results.
- **FR-009**: Every created or changed row MUST be recorded in a run manifest and shared audit batch inside the business transaction where possible.
- **FR-010**: Execution MUST be idempotent by refusing an already-used target/run; it MUST NOT use conflict-ignoring writes.
- **FR-011**: Independent post-run verification MUST validate counts, mappings, orphan references, enrollment coverage, draft status, and absence of forbidden historical target rows.
- **FR-012**: Rollback MUST be available only before activation and before target operational use, and MUST delete only manifest-owned rows in reverse dependency order.
- **FR-013**: Activation MUST require successful post-verification and MUST lock the source year in the same protected operation.
- **FR-014**: Protected attendance, evaluation, grade/assessment, reporting, and finance writes MUST reject locked academic years through shared server-side policy.
- **FR-015**: All state changes and external backup/restore outcomes MUST use the shared audit architecture without secrets or raw command output.
- **FR-016**: Integration tests MUST refuse `educore` and any database name not ending in `_test`.
- **FR-017**: Promotion MUST use an explicit rule keyed by source year, target year, and source grade ID; names and grade/stage order MUST NOT be runtime promotion logic.
- **FR-018**: Decision preparation MUST persist exactly one decision per eligible source enrollment for the selected target year and audit the batch atomically.
- **FR-019**: Pending/conditional decisions and malformed real-student placement MUST block execution; test accounts and experimental grades MUST be explicitly counted and excluded without blocking.
- **FR-020**: Promoted enrollments MUST use the rule target grade and its stage with `class_id = NULL`; retained enrollments MUST use the source grade/stage with `class_id = NULL` and repeater metadata.
- **FR-021**: Graduated, transferred-out, withdrawn, pending, and test-excluded decisions MUST NOT create a target-year enrollment.
- **FR-022**: Each created target enrollment MUST reference its source enrollment and applied promotion decision; repeat counts MUST be monotonic.
- **FR-023**: Grade experimental state and student test-account state MUST be editable by an admin, visible in the UI, reversible, and captured by the shared audit architecture.
- **FR-024**: Target class drafts MUST be copied independently from student decisions and MAY carry an optional capacity; class-count differences MUST NOT block rollover.
- **FR-025**: The system MUST remain a single-school deployment and MUST NOT introduce school, tenant, branch, or campus ownership keys.

### Key Entities

- **Recovery Package**: Immutable dump/file bundle, hashes, source fingerprint, and creation metadata.
- **Recovery Verification Receipt**: Proof of isolated restore and integrity checks for one package.
- **Rollover Run**: Source/target years, state, actor, backup receipt, fingerprints, timestamps, and audit batch.
- **Rollover Manifest Item**: Source/target table and record mapping with dependency order and rollback ownership.
- **Rollover Verification**: Aggregate checks and activation eligibility.
- **Grade Promotion Rule**: Explicit source-grade to target-grade/graduation rule for one source/target year pair.
- **Student Promotion Decision**: Durable, audited outcome for one source enrollment and target year, optionally linked to the resulting enrollment and run.

## Success Criteria

### Measurable Outcomes

- **SC-001**: 100% of rollover executions are rejected without a successful, current, matching restore-verification receipt.
- **SC-002**: 100% of eligible active enrolled students receive exactly one accounted decision; only promoted/retained decisions receive exactly one target enrollment and zero students are silently skipped.
- **SC-003**: Zero attendance, evaluation, mark, published report, payment, or transfer result rows are copied to the target.
- **SC-004**: A completed unactivated rollover can be rolled back with zero source-row changes and zero non-manifest target-row deletions.
- **SC-005**: Corrupt dump/file fixtures and non-`_test` restore targets are rejected in automated tests.
- **SC-006**: All focused tests, PHP lint, write-coverage gate, and strict architecture audit pass before handoff.
- **SC-007**: Experimental grades and test accounts are excluded and counted; they never create official target enrollments.
- **SC-008**: Class-count changes between grades produce zero rollover blockers and every promoted/retained enrollment is initially unassigned.

## Assumptions

- The deployment remains one school on the current XAMPP/MariaDB host.
- Education-structure versioning is not required now; year-pair promotion rules cover official structure changes without multi-school abstractions.
- Safe default for unresolved operational domains is to start empty and require manual setup rather than copy stale assignments, schedules, transport, or financial obligations.
- Source and target academic-year start/end dates are present and define the calendar shift; missing dates block rollover.
- The server account can run database dump/import binaries and read the configured application data roots.
- Production migration/deployment remains a separate explicitly authorized step.

## Compatibility, Security, and Data Impact

- **Existing contracts**: Preserve current academic-year URLs, role/session keys, CSRF field, retained-student IDs, and navigation; remove only copy-option semantics from the rollover UI.
- **Roles and authorization**: Admin only; session validation precedes all processing; re-authentication and exact confirmation are required for backup verification, execution, rollback, and activation.
- **State-changing requests**: POST + timing-safe CSRF, PRG responses, transaction/row/advisory locks, and compact user-safe errors.
- **Data/schema**: Add recovery receipt, rollover run, manifest item, and verification tables through guarded migrations; no runtime DDL in HTTP requests.
- **Sensitive data and errors**: Package manifests contain no credentials or row payloads; filesystem paths shown to users are normalized labels; raw subprocess output is server-log only.
- **Rollback**: Code rollback disables new orchestration first; data rollback uses manifest ownership before activation; recovery package remains retained according to policy.
- **Stop conditions / unknowns**: Stop if isolated restore cannot be proven, target cleanup ownership is ambiguous, source/target dates are invalid, a protected writer lacks a year identifier, or current dirty changes conflict with a touched hunk.
