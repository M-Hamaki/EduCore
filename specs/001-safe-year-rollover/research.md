# Research: Safe Academic Year Rollover

## Decision 1: Fixed fail-closed policy

- **Decision**: Copy only calendar, classes, subject-grade assignments, and assessment scheme structure as drafts; derive enrollments; start teacher assignments, timetable, transport assignments, fees/debt, windows, results, attendance, and evaluations empty.
- **Rationale**: These excluded domains contain unresolved staffing, capacity, scheduling, publication, or accounting decisions. Empty is reversible; stale copied operations are not.
- **Alternatives considered**: User-selectable categories were rejected by the user; copying all source rows was rejected because it would copy invalid annual state.

## Decision 2: Recovery package and verified restore are a hard gate

- **Decision**: Create a ZIP package under protected storage containing a single-database SQL dump, file payload, and manifest. Restore the dump into a unique database ending in `_test`, compare schema/table counts and file hashes, then issue a receipt.
- **Rationale**: Existing scheduled SQL dumps do not include files and have no restore proof. A package/receipt is independently inspectable and can gate rollover.
- **Alternatives considered**: Same-schema backup tables are not disaster recovery; an untested dump is insufficient; restoring a dump containing `CREATE DATABASE educore` is unsafe.

## Decision 3: Fingerprint before execution

- **Decision**: The receipt stores database schema/table-count fingerprints plus file manifest hash. Rollover recomputes and requires an exact match before execution.
- **Rationale**: A successful restore of stale state must not authorize a later rollover after source data changed.
- **Alternatives considered**: Age-only acceptance was rejected because recent writes can invalidate it.

## Decision 4: Manifest-owned rollback

- **Decision**: Persist run and item records. Rollback is allowed only while the target is inactive and has no operational rows outside the manifest; delete manifest-owned rows in reverse dependency order.
- **Rationale**: Broad `DELETE WHERE academic_year_id = ?` could erase user-created target data.
- **Alternatives considered**: Undo-log time grouping and target-wide delete were rejected.

## Decision 5: Existing compatibility entrypoints remain

- **Decision**: Keep `admin/academic_year_setup.php`, `NewYearWizard`, and `AcademicYear`; add owned services behind them.
- **Rationale**: Preserves URLs and callers while moving orchestration out of the page.
- **Alternatives considered**: New router/framework or replacement page rejected.

## Decision 6: Archive dates shift by year-start delta

- **Decision**: Target start/end dates are mandatory. Calendar dates shift by the day difference between source and target start dates, then must fit the target range.
- **Rationale**: Preserves relative calendar layout and makes validation deterministic.
- **Alternatives considered**: Copying identical dates is wrong; arbitrary year addition mishandles leap years and nonstandard starts.

## Confirmed Runtime

- PHP has the ZIP extension.
- Existing backup code expects XAMPP `mysqldump.exe`; restore uses the paired `mysql.exe`.
- Guarded integration helpers refuse production and require an explicit `*_test` database.
- Current annual schema supports explicit foreign-key remapping for calendar, assignments, schemes, components, and week rules.

## Decision 7: Explicit rules replace ordering inference

- **Decision**: Store one promotion rule for every official source grade for each source/target year pair. A rule either targets a grade ID or declares graduation.
- **Rationale**: Names and display order are presentation data; the active experimental `test grade` currently breaks the inferred sixth-primary transition.
- **Alternatives considered**: Global next-grade pointers cannot express a temporary reform by year; education-structure version tables are unnecessary for one school at this stage.

## Decision 8: Persist decisions before backup

- **Decision**: An explicit preparation action creates or updates one audited `student_promotion_decisions` row per eligible source enrollment. Execution consumes only unchanged approved decisions.
- **Rationale**: Pending decisions must survive sessions and the recovery package must capture the exact approved decision state.
- **Alternatives considered**: Session-only retained IDs and recomputing decisions during execution were rejected because they can drift after preview.

## Decision 9: Allocation is separate from rollover

- **Decision**: Copy class structure as inactive drafts, but create promoted and retained enrollments with no class. Optional capacity is stored for later placement.
- **Rationale**: Promotion is an academic decision; class allocation depends on capacity and staffing. Fewer next-grade classes must not block academic rollover.
- **Alternatives considered**: Rank-based source/target class matching was rejected as fragile and produced 337 false blockers in current data.

## Decision 10: Explicit experimental data

- **Decision**: Add reversible `grades.is_experimental` and `users.is_test_account` flags. The account flag is managed only from student accounts; such rows are excluded and counted, not silently ignored.
- **Rationale**: Test data exists in the live school dataset and currently distorts final/next-grade inference.
- **Alternatives considered**: Naming conventions such as `test grade` are ambiguous and unsafe.
