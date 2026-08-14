# Data Model: Safe Academic Year Rollover

## recovery_backups

- `id`: primary identifier
- `backup_key`: random public-safe identifier
- `status`: creating, created, verifying, verified, failed
- `package_path`: normalized relative path below protected recovery storage
- `package_sha256`, `manifest_sha256`
- `database_fingerprint`, `files_fingerprint`
- `database_name`: source database label
- `test_database_name`: isolated restore target used for proof
- `created_by`, `created_at`, `verified_at`, `expires_at`
- `verification_summary`: compact JSON without secrets or row payloads

State: creating → created → verifying → verified; any step may transition to failed. Verified receipts are immutable.

## academic_year_rollover_runs

- `id`, `run_key`
- `source_year_id`, `target_year_id`, `recovery_backup_id`
- `status`: previewed, executing, completed, verified, rolled_back, activated, failed
- `source_fingerprint`, `preflight_summary`, `execution_summary`, `verification_summary`
- `audit_batch_id`, `created_by`, timestamps

Only one non-failed/non-rolled-back run may own a target year.

## academic_year_rollover_items

- `id`, `run_id`
- `entity_table`, `source_record_id`, `target_record_id`
- `dependency_order`, `action`
- unique target ownership per run/table/record

Items contain identifiers only; business snapshots stay in the shared redacted audit engine.

## grade_promotion_rules

- `id`
- `source_year_id`, `target_year_id`, `source_grade_id`
- `rule_type`: promote or graduate
- `target_grade_id`: required for promote and null for graduate
- `status`: active or inactive
- `created_by`, `updated_by`, timestamps
- unique `(source_year_id, target_year_id, source_grade_id)`

Rules may not target an inactive/experimental grade, may not target the same grade, and may not form a cycle within a year pair.

## student_promotion_decisions

- `id`
- `source_year_id`, `target_year_id`, `source_enrollment_id`, `student_id`
- `decision`: promoted, retained, pending, graduated, transferred_out, withdrawn, excluded_test
- `status`: draft, approved, applied, cancelled
- `target_grade_id`, `reason_code`, `note`, `decision_source`
- `source_snapshot_hash`
- `applied_run_id`, `target_enrollment_id`
- `decided_by`, `approved_by`, timestamps
- unique `(source_enrollment_id, target_year_id)`

State: draft/pending → approved → applied. Source changes invalidate approval and require preparation again.

## Existing entity extensions

- `grades.is_experimental`: reversible admin flag; experimental grades need no promotion rule.
- `users.is_test_account`: reversible account flag managed only from student accounts; test accounts may lack official placement.
- `classes.capacity`: nullable positive capacity for later allocation; copied with the class draft.
- `student_enrollments.source_enrollment_id`: self-reference to the previous annual enrollment.
- `student_enrollments.promotion_decision_id`: unique reference to the applied decision.
- `student_enrollments.is_repeater`, `repeat_count`: retained-student history.

## Managed mapping order

1. academic_terms
2. academic_months
3. academic_weeks
4. classes
5. student_enrollments (created from approved decisions; class is null)
6. subject_grade_assignments
7. assessment_schemes
8. assessment_components (parents before children)
9. assessment_component_week_rules

Rollback uses the reverse order.

## Invariants

- Source and target differ and target is chronologically later.
- Target is inactive and empty across all managed and forbidden historical tables.
- Every real eligible source student has stage and grade; a source class is not required for rollover.
- Every official source grade has exactly one active promote/graduation rule for the year pair.
- Every eligible source enrollment has exactly one durable accounted decision.
- Pending decisions block execution; experimental/test exclusions are explicit and counted.
- Every target enrollment is unique by student/year.
- Promoted/retained target enrollments have `class_id = NULL` until a later allocation action.
- Graduated/transferred/withdrawn/pending/test-excluded decisions create no target enrollment.
- Copied schemes are draft; copied subject assignments are inactive until reviewed.
- Historical target tables remain empty after rollover.
- Activation requires verified run state and locks source.
