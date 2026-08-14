# Quickstart Validation

## Preconditions

1. Use a non-production environment.
2. Set `EDUCORE_TEST_DB_NAME` to a dedicated name ending in `_test`.
3. Clone schema into that database using the existing guarded helper.
4. Apply the feature migration only to the isolated database.
5. Use fixture upload/private directories, never live data roots, for automated tests.

## Scenarios

### Backup/restore proof

- Create a fixture database and two fixture files.
- Create a recovery package.
- Restore to a unique secondary `*_test` database.
- Expect matching dump/package/file hashes, table list, row counts, and a verified receipt.
- Corrupt one package entry and expect verification failure.

### Preflight

- Seed source/target years, official and experimental grades, explicit rules, calendar, classes, assignments, schemes, and students.
- Prepare decisions and expect promoted/retained/graduated/test-excluded counts.
- Add one real student without a grade; expect one grouped placement blocker.
- Mark the same fixture as a test account; expect exclusion without a blocker.
- Add a pending decision; expect execution blocked until resolved.
- Mark an intermediate experimental grade; expect official rules to transition around it without using display order.
- Seed one target class; expect target-not-empty blocker.

### Execute and verify

- Use a verified receipt matching the fixture state.
- Execute rollover.
- Expect exact enrollment coverage and mapped annual configuration.
- Expect promoted and retained enrollment classes to be null, source/decision links present, and repeat counts correct.
- Expect no enrollment for graduated, transferred, withdrawn, pending, or test-excluded decisions.
- Expect historical tables to have zero target rows.

### Rollback

- Roll back before activation.
- Expect all manifest-owned rows removed and source fingerprints unchanged.

### Activation and lock

- Execute again, verify, activate.
- Expect target active, source locked, and protected source-year writes rejected.

## Required checks

- Touched PHP lint
- Focused contract/unit tests
- Guarded integration tests on explicit `*_test`
- `tools/audit_write_coverage.php`
- `tools/audit_architecture.php --strict`
- Full quality gate when Composer is available
