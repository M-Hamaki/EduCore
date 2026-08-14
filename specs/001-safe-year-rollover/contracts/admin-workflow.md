# Admin Workflow Contract

## Existing route

`GET|POST admin/academic_year_setup.php` remains the single admin entrypoint.

## POST actions

- `save_promotion_rules`: persists the reviewed year-pair grade rules; requires CSRF and shared audit.
- `preview_year_setup`: prepares durable student decisions from rules plus manual overrides, audits the batch, then stores a compact grouped preview in session.
- `create_recovery_backup`: creates package and verifies isolated restore; requires admin password and exact confirmation.
- `execute_year_setup`: requires a verified receipt key, unchanged fingerprint, successful preflight, admin password, CSRF, and exact confirmation.
- `verify_year_setup`: independently verifies a completed run.
- `rollback_year_setup`: pre-activation only; requires password, CSRF, and exact confirmation.
- `activate_year_setup`: verified run only; requires password, CSRF, and exact confirmation.

## Compatibility

- Preserve `source_year_id`, `target_year_id`, and `retained_student_ids[]`.
- Accept `student_decisions[student_id]` as explicit overrides; retained IDs map to `retained` when no explicit override is supplied.
- Retire `copy_classes`, `copy_buses`, and `carry_balances` as accepted-but-ignored compatibility inputs during one transition; no checkbox controls are rendered.
- Responses remain PRG with session success/error messages.

## Error contract

- User receives a stable Arabic message and blocker list.
- SQL, filesystem paths, command arguments/output, credentials, and stack traces are logged only server-side.
- Blockers are grouped by code/grade with counts; bounded student details may be expanded without repeating the same message hundreds of times.
