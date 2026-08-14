# Tasks: Safe Academic Year Rollover

**Input**: Design documents from `specs/001-safe-year-rollover/`

## Phase 1: Setup

- [X] T001 Confirm overlapping dirty-worktree files and preserve unrelated changes in `classes/`, `admin/`, `docs/`, and `tests/`
- [X] T002 Record scope, contracts, data policy, rollback, and stop conditions in `specs/001-safe-year-rollover/spec.md`
- [X] T003 [P] Confirm backup runtime, annual schema, and isolated-test helpers in `specs/001-safe-year-rollover/research.md`

## Phase 2: Foundational

- [X] T004 Add guarded recovery/rollover schema migration in `database/migrations/20260718_safe_year_rollover.php`
- [X] T005 [P] Add recovery package contract tests in `tests/recovery_backup_contract_test.php`
- [X] T006 [P] Add rollover fail-closed contract tests in `tests/new_year_rollover_contract_test.php`
- [X] T007 Add shared locked-year write policy in `classes/AcademicYearWriteGuard.php`
- [X] T008 Register new write owners/entities in the shared audit policy and coverage classifications

## Phase 3: User Story 1 — Verified Disaster-Recovery Backup

- [X] T009 [P] [US1] Add recovery package unit tests in `tests/recovery_backup_service_test.php`
- [X] T010 [P] [US1] Add guarded restore integration test in `tests/recovery_backup_restore_integration_test.php`
- [X] T011 [US1] Implement package creation/fingerprints/manifest in `classes/RecoveryBackupService.php`
- [X] T012 [US1] Implement isolated restore verification and receipt persistence in `classes/RecoveryBackupService.php`
- [X] T013 [P] [US1] Add CLI package creator in `tools/create_recovery_backup.php`
- [X] T014 [P] [US1] Add CLI verifier in `tools/verify_recovery_backup.php`
- [X] T015 [US1] Harden scheduled SQL dump format and status evidence in `tools/backup_db_sql.php`

## Phase 4: User Story 2 — Fail-Closed Preflight and Execution

- [X] T016 [P] [US2] Add isolated preflight/execution integration fixtures in `tests/new_year_rollover_integration_test.php`
- [X] T017 [US2] Implement fixed policy, source fingerprint, preflight blockers, and target emptiness in `classes/NewYearRolloverService.php`
- [X] T018 [US2] Implement mapped calendar/class/enrollment/subject/scheme copy and audit manifest in `classes/NewYearRolloverService.php`
- [X] T019 [US2] Convert `classes/NewYearWizard.php` into a compatible adapter over the new service
- [X] T020 [US2] Remove option semantics and expose fixed readiness/backup state in `admin/academic_year_setup.php`

## Phase 5: User Story 3 — Verification, Rollback, and Activation

- [X] T021 [P] [US3] Add verification/rollback/activation integration coverage in `tests/new_year_rollover_lifecycle_integration_test.php`
- [X] T022 [US3] Implement independent post-verification and manifest-owned rollback in `classes/NewYearRolloverService.php`
- [X] T023 [US3] Implement verified activation plus source lock in `classes/NewYearRolloverService.php` and `classes/AcademicYear.php`
- [X] T024 [US3] Enforce locked-year policy in confirmed attendance/evaluation/assessment/finance write owners
- [X] T025 [US3] Add admin POST flows and confirmation modals in `admin/academic_year_setup.php`

## Phase 6: Polish and Gates

- [X] T026 [P] Update `docs/backup-retention-policy.md` and add restore runbook
- [X] T027 [P] Update `docs/new-academic-year-data-policy.md`, `docs/project-memory.md`, and `docs/architecture-decisions.md`
- [X] T028 Run touched PHP lint and focused contract/unit tests
- [X] T029 Run guarded integration tests only on explicit `*_test`
- [X] T030 Run write-coverage, upload-policy where applicable, strict architecture audit, and diff checks
- [X] T031 Create and restore-verify one real protected recovery package without applying production schema migration or rollover

## Phase 7: User Story 4 — Explicit Academic Decisions (v2)

- [X] T032 [US4] Expand the approved specification, research, data model, workflow contract, and validation guide in `specs/001-safe-year-rollover/`
- [X] T033 [P] [US4] Add fail-closed schema and contract coverage in `tests/academic_promotion_decisions_contract_test.php`
- [X] T034 [US4] Add additive promotion/decision/test-data schema in `database/migrations/20260719_academic_promotion_decisions.php`
- [X] T035 [US4] Register promotion rules and decision records in `src/Modules/Operations/Audit/AuditPolicyRegistry.php` and write-coverage classifications
- [X] T036 [US4] Implement explicit rule validation and durable decision preparation in `classes/NewYearRolloverService.php`
- [X] T037 [US4] Replace grade-order/class-rank enrollment derivation with applied decisions and unassigned target enrollments in `classes/NewYearRolloverService.php`
- [X] T038 [US4] Preserve the `NewYearWizard` compatibility API while exposing grouped decisions/blockers in `classes/NewYearWizard.php`

## Phase 8: User Story 5 — Admin Control of Rules and Test Data

- [X] T039 [P] [US5] Add ownership tests proving `is_test_account` is absent from profile mapping/storage/UI in `tests/student_test_account_contract_test.php`
- [X] T040 [US5] Add audited test-account persistence in `users` and use the persisted account flag for the missing-placement exception
- [X] T041 [US5] Add the reversible test-account control exclusively to `admin/student_accounts.php`
- [X] T042 [P] [US5] Add grade experimental-state contract coverage in `tests/grade_experimental_contract_test.php`
- [X] T043 [US5] Add audited experimental-grade controls and badges in `admin/grades.php`
- [X] T044 [US5] Add optional class capacity persistence and form controls in `admin/classes.php`
- [X] T045 [US5] Add year-pair rule editing, per-student decision overrides, grouped blocker tables, and updated lifecycle copy in `admin/academic_year_setup.php`

## Phase 9: User Story 6 — Safety Proof and Production Readiness

- [X] T046 [P] [US6] Add isolated decision/execute/verify/rollback fixtures for all approved outcomes in `tests/academic_promotion_decisions_integration_test.php`
- [X] T047 [US6] Prove migration forward compatibility and rollback ownership on a dedicated `*_test` clone
- [X] T048 [US6] Run focused tests, touched PHP lint, write audit, strict architecture audit, and full quality gate
- [X] T049 [US6] Update policy, ADR, project memory, and recovery runbook in `docs/`
- [X] T050 [US6] Create and restore-verify a fresh production recovery package before applying only `database/migrations/20260719_academic_promotion_decisions.php`
- [X] T051 [US6] Apply the target migration only, create a post-migration verified recovery package, and verify the live page remains blocked until rules/decisions are prepared

## Dependencies

- T004–T008 block all user stories.
- US1 blocks US2 execution because a verified receipt is mandatory.
- US2 blocks US3 lifecycle.
- T031 occurs only after all safety checks pass.
- T033–T035 block the v2 application and UI work.
- T036–T038 block T045 and all v2 integration tests.
- T039–T044 may proceed after T034 and before T045.
- T050–T051 occur only after T046–T049 pass and never through the all-pending migration runner.

## Independent Test Criteria

- **US1**: A valid fixture package restores and verifies; corrupt package and non-test targets fail.
- **US2**: Invalid students/target state fail; valid run maps every managed row and copies no history.
- **US3**: Verification gates activation; rollback deletes only owned rows; activation locks source writes.
- **US4**: Every eligible source enrollment has an explicit durable decision; no name/order/class-rank inference remains.
- **US5**: Admin can mark test data, configure year-pair rules, override exceptional decisions, and see grouped evidence.
- **US6**: All outcomes pass on `*_test`; a fresh restore-proven package gates the single target production migration.

## Implementation Strategy

Deliver backup/restore proof first, then fixed rollover, then lifecycle activation. No production migration or rollover is executed during implementation.
