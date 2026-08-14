# Database Layer

Purpose: concise reference for confirmed DB architecture.

## Confirmed Access Pattern

- Database engine: MySQL/MariaDB.
- Database name: `educore`.
- Access library: PDO.
- Connection class: `Database` in `config/database.php`.
- Connection method: `Database::getConnection()`.
- Character set: `utf8mb4` confirmed in the PDO DSN.
- PDO error mode: exceptions.
- Credentials come from environment variables through `config/env_loader.php`.

Important files:

- `config/database.php`
- `config/env_loader.php`
- `config/encryption.php`
- `database_complete.sql`
- `database/migrations/`

## Configuration Keys

Confirmed keys used by `config/database.php`:

- `DB_HOST`
- `DB_NAME`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_PASSWORD_LOCAL`
- `SITE_URL`

Full `.env` contract: `Not confirmed yet`.

## Data Access Style

- Classes in `classes/` receive or create PDO access and run SQL directly.
- Prepared statements are the expected pattern.
- No ORM was confirmed.
- No central repository layer was confirmed.

Representative classes:

- `classes/user.php`
- `classes/ActivityLog.php`
- `classes/UndoManager.php`
- `classes/AcademicYear.php`
- `classes/StudentEnrollment.php`
- `classes/AssessmentEngine.php`

## Assessment Engine Tables

Confirmed new assessment/reporting tables are created by `database/migrations/20260627_assessment_engine_foundation.php`:

- `academic_terms`
- `academic_weeks`
- `subject_grade_assignments`
- `teacher_subject_assignments`
- `assessment_schemes`
- `assessment_components`
- `assessment_component_week_rules`
- `assessment_windows`
- `student_marks`
- `student_mark_audit`
- `assessment_permissions`
- `assessment_student_locks`
- `report_windows`
- `report_window_items`
- `published_reports`
- `published_report_details`

Legacy `grade_columns` and `student_grades` may still exist for compatibility/backup/migration history, but new grading work should use the assessment engine tables above.

## Migrations

- Runtime schema changes belong only in `database/migrations/`; active request and service files now pass the strict audit with zero runtime DDL findings.
- Evaluation reset/restore snapshots use fixed tables `evaluation_backup_snapshots` and `evaluation_backup_rows`; legacy timestamped evaluation tables are imported and retained only as rollback evidence.
- Migration `20260713_decommission_db_auto_backup.php` removes the retired same-schema MySQL EVENT/PROCEDURE; operational backups use the external SQL backup workflow.
- Migration `20260723_fixed_staff_roles.php` registers the real fixed roles as valid membership targets and backfills legacy roles; `20260723_multi_role_staff_accounts.php` adds normalized `user_role_assignments` and `role_key` to staff grade/class assignments. Academic scope uniqueness is `(staff_id, role_key, academic_year_id, grade_or_class_id)`, so roles persist across years while each new year begins without copied scope.
- Migration `20260726_ai_lesson_public_sharing.php` adds the nullable, unique `ai_lessons.public_share_token` plus enable/revoke timestamps. Tokens are explicit public bearer links, excluded from audit snapshots, and invalidated by clearing the token on revoke.
- Migration `20260728_class_rollover_mappings.php` adds the reviewed class-cohort mapping used by safe academic-year rollover; it was applied to `educore` on 2026-07-29 after a full checksum-recorded backup.
- Migration `20260729_academic_structure_experimental_scope.php` adds reversible `is_experimental` flags to `stages` and `classes`; together with the existing grade and account flags, effective academic scope is inherited as stage → grade → class, while `users.is_test_account` remains an independent student override. It was applied to `educore` on 2026-07-29 after recovery package `2a27f50841e71c7e5e7338dc2b2fbbc3` passed an isolated restore verification.
- Migration `20260730_remove_teacher_assignment_substitute.php` removes the unused `teacher_subject_assignments.is_substitute` marker; teacher assignment behavior is defined only by active state plus record/review permissions and academic scope.

## Finance schema boundary

- Additive Finance migrations are dated `20260723_*`, `20260724_finance_views.php`, `20260726_*`, and `20260728_finance_default_configuration.php`; web requests never create or alter Finance tables.
- Student and staff balances are derived only from posted signed lines in `finance_subledger_accounts`, `finance_subledger_transactions`, and `finance_subledger_lines`.
- Student accounts are scoped by `academic_year_id`; staff use the stable `STAFF_GLOBAL` scope so compensation-model changes do not split history.
- Party-affecting postings link one sub-ledger transaction to one balanced `accounting_journal_entries` record. Pure GL vouchers/manual journals have a null sub-ledger link.
- Posted receipts, refunds, payroll movements, vouchers, imports, and journals are corrected with linked opposite records; hard deletion is not a financial rollback mechanism.
- Read projections include `v_student_subledger_balances`, `v_staff_subledger_balances`, and `v_budget_actuals`.
- Finance integration commands must receive an explicit database ending in `_test`; the verified local target for this feature is `educore_finance_codex_test`.
- The 2026-07-28 authorized local rollout applied all 11 Finance migrations to `educore` after a checksum-verified backup/restore drill. The default-configuration migration seeds 13 accounts, `MAIN` cashbox, an open active-year period, 19 operation mappings, and four control accounts only when no user-managed mapping lines exist.

- Timestamped migration scripts live in `database/migrations/`.
- Migration runner exists at `tools/run_migrations.php`.
- Do not modify migrations unless the task explicitly requires a schema change.

Migration execution policy and rollback support: `Not confirmed yet`.

## Known Tables From Confirmed References

The following table names were observed in confirmed code/search context:

- `users`
  - `status` يظل ملخص التحكم المتوافق للحساب. migration `20260811_student_account_disable_reason.php` تضيف `login_disabled_reason` (حتى 500 حرف)، و`login_disabled_at`, و`login_disabled_by` لتعليق دخول الطالب مع سبب اختياري؛ لا تحفظ الرسالة العامة في الصف عند ترك السبب فارغاً.
- `user_role_assignments`
- `staff_roles`
- `staff_role_pages`
- `staff_grade_assignments`
- `staff_class_assignments`
- `student_profiles`
- `student_enrollments` (السجل السنوي المعتمد لموضع الطالب وحالتي القيد والدراسة)
- `student_promotion_decisions` (قرار تهيئة العام بحالتي القيد والدراسة)
- `class_rollover_mappings` (خريطة فصل المصدر إلى فصل المجموعة أو قالب الدخول في العام الهدف)
- `staff_profiles`
- `classes`
- `grades`
- `stages`
- `activity_logs`
- `undo_log`
- `settings`
- `settings.setting_key = student_completeness_fields_v2` stores only reviewed priority/weight overrides for student profile completeness; trusted table/column definitions remain in `config/student_fields_config.json`.
- `evaluations`
- `evaluation_types`
- `user_services`
- `student_siblings`
- `user_class_access`
- `specialist_classes`
- `specialist_grade_assignments` (نطاق صف سنوي للأخصائي)
- `specialist_class_assignments` (نطاق فصل سنوي صريح للأخصائي)
- `staff_grade_assignments` (نطاق صف سنوي عام للأخصائي والطبيب وأمين المكتبة)
- `staff_class_assignments` (نطاق فصل سنوي صريح للأدوار المقيدة)
- `staff_active_classes` (view للنطاق الفعال في العام النشط فقط)
- `specialist_active_classes` (view توافقية مشتقة من النطاق العام للأخصائي)
- `student_change_requests` (طلبات تعديل الطالب المعلقة ومراجعتها)
- `teacher_subjects`
- `ai_lessons` (generated lesson payloads plus explicitly enabled, revocable public-share state)

This is not a complete schema inventory.

## Staff biometric and profile-field compatibility

- `staff_profiles.biometric_id` is the canonical device identifier for a worker.
- `users.employee_code` is a separate legacy attendance identifier and is never synchronized with the profile fields.
- `staff_profiles.employee_code` is the independent internal school employee code in `E{YYYY}{NNNN}` format and is generated by the system.
- `staff_profiles.religion`, `marital_status`, and `contract_type` are `VARCHAR(100)` because the staff form accepts reviewed custom «أخرى» values.
- Migration `20260730_staff_profile_data_consistency.php` normalizes blank identifiers to `NULL` without cross-field backfill.
- Corrective migration `20260730_staff_profile_identity_separation.php` removes the proven erroneous mirror values and regenerates invalid/test internal codes while preserving `users.employee_code`.

## Integrated staff-HR ownership boundary

- The implementation plan is `specs/004-integrated-staff-affairs/`; schema changes are additive migrations only.
- `Staff` owns effective-dated organization assignments, manager relationships, policy groups, permission/leave requests, approval workflows, quota/leave ledgers, discipline, and Ertaq.
- `StaffOrganizationService` is the single command owner for effective-dated organization units, titles, groups, memberships, manager relationships, and explicit assignments. Its PDO adapter uses only the current active `users`/`user_role_assignments` evidence to authorize an admin or super-admin actor; it does not create a parallel account or role store.
- `Application/Organization/StaffOrganizationAdministrationQuery` is the presentation read boundary for `admin/hr_organization.php`. It reuses the Staff-owned live administrator check and reads a bounded, safe dashboard through `PdoStaffOrganizationAdministrationReadRepository`; the entrypoint never runs direct list SQL or writes directly to a Staff table.
- `Infrastructure/Organization/PdoStaffAssignmentAtDateQuery` is the dated lifecycle and current-access read boundary. It returns historical effective assignment state without profile fallback and rechecks fixed self/manager/HR Attendance capabilities against live account, employment, manager, and current role evidence. `PdoStaffPopulationAtDateQuery` remains the attendance-population projection and includes only `active` or `rehired` primary assignments.
- `Application/Portal/StaffPortalEligibilityService` is the Staff-owned worker-portal eligibility adapter. It requires an active `users` account, a `staff_profiles` identity, and one effective `active`/`rehired` primary assignment, never a browser-selected role. Its portal read adapter can expose only a fixed self-service capability and, when an effective direct/administrative relationship resolves to another eligible staff member, a manager-inbox capability; detailed request/decision authorization stays with the owning workflow boundary.
- `Application/Timeline/StaffHrTimelineQuery` composes only `StaffTimelineEventSource` summaries; it does not own cross-module SQL or sensitive resource content. Current Staff-owned sources are `Infrastructure/Timeline/PdoStaffAssignmentTimelineEventSource` (dated primary assignment identifier/status/version only) and `PdoStaffCredentialTimelineEventSource` (credential identifier/kind/effective date/status/version only). Later resource owners must add their own source rather than widening either PDO adapter into an unreviewed cross-resource query.
- Migration `20260809_staff_hr_credential_expiry.php` adds immutable `staff_credential_records` for qualifications, training, and documents. It stores a scalar attachment identifier only, links a successor to the immutable evidence it replaces, rejects hard update/delete with MariaDB triggers, and is a registered non-undoable audited resource. It was verified only in a throwaway `*_test` database and has not been applied to `educore`.
- Migration `20260809_staff_hr_organization_corrections.php` adds the immutable `staff_organization_corrections`, `staff_organization_correction_decisions`, and `staff_organization_correction_impacts` ledger. A preview freezes exact Staff-owned scope; a separate decision publishes only attendance-day, request-route, and report-period impact facts. All three tables reject UPDATE/DELETE through migration-owned triggers, are registered as non-directly-undoable resources, and were verified on a newly created disposable `*_test` database that was removed afterward; the migration remains unapplied to `educore`.
- `Attendance` owns schedule/calendar policies, biometric mappings and immutable raw events, calculation runs, versioned day results, corrections, period locks, and reporting projections.
- `Finance` remains the only writer of payroll/sub-ledger/GL data; staff HR sends idempotent facts through a Finance-owned contract.
- `Operations/Audit` remains the mandatory audit writer. Each new table must have an explicit policy before the first business write.
- `Operations/Audit/SystemActivityLogQuery` remains the read-only owner for activity-log projections. Its optional, parameterized `target_type_prefix` filter is fail-closed and is used by `admin/hr_audit.php` with the fixed `staff_` prefix; the page never renders serialized `details` or executes undo itself.
- Migration `20260809_staff_hr_ertaq_private_attachments.php` adds `staff_resource_attachments` as append-only private Ertaq ticket/message metadata; it stores only a normalized `private:ertaq_attachments/...` reference, never an absolute path or public URL, and ownership is constrained to Ertaq until a later reviewed migration expands it.
- Published policies and official records are corrected through a later version/reversal; they are not hard-deleted or silently overwritten.
- Migration `20260730_staff_hr_assignment_backfill.php` adds the append-only `staff_assignment_legacy_links` compatibility ledger. It records only a reviewed `mapped` assignment reference or a `quarantined` migration-exception reference, an effective range, source key, and SHA-256 source fingerprint; it never copies legacy free text or mutates `staff_profiles`/`staff_job_movements`. Its batch/assignment/exception identifiers are validated scalar references because the file is ordered before the prerequisite migrations; the later coordinator must validate them transactionally.
- Legacy HR tables remain readable through adapters during `off/shadow/compare/display`; they are not removed by this feature.
- Staff-HR integration tests call `tests/bootstrap_staff_hr.php`, require a database ending in `_test`, and require `STAFF_HR_TEST_MARKER=integrated-staff-hr`.
- `staff_hr_cutover_windows`, `staff_hr_migration_batches`, and `staff_hr_migration_exceptions` are operated through `StaffHrMigrationCoordinator`: source/target watermarks, monotonically increasing checkpoint counts, resume token, checksum, and quarantine identity are durable. A matched close is the only transition to `new_only`; rollback restores `legacy_only` and retains the three ledgers while a migration-specific executor reverses only scalar resources listed in the batch manifest.
- Acceptance seed/restore owns only manifest-listed demo rows and must fail before writing when the target is not an explicitly isolated test/demo database.

## Rules

- Never concatenate untrusted input into SQL.
- Use positional `?` or named `:param` prepared statements.
- Avoid N+1 queries; batch with joins, lookup maps, or `WHERE IN`.
- Use transactions for bulk operations.
- Log CRUD operations with `classes/ActivityLog.php` when required by `AGENTS.md`.
- Use `classes/UndoManager.php` for undo-aware create/update/delete flows where applicable.
- Composite undo operations use nullable `undo_log.batch_id` values created by `UndoManager::newBatchId()`; proximity in time or matching descriptions must never be used to infer a batch.
- New academic year initialization follows `docs/new-academic-year-data-policy.md`: persistent master data remains shared, annual configuration is copied with explicit ID remapping, and historical transactions/results are never copied.
- Class rollover plans are persisted before execution in `class_rollover_mappings`; enabled cohort mappings may populate the promoted enrollment's `class_id`, while retained enrollments must remain unassigned.
- `student_enrollments` has one row per student/year. `enrollment_status` owns registration state (`enrolled`, `transferred`, `discontinued`), while `academic_status` owns study outcome (`new`, `promoted`, `retained`, `graduated`); `student_profiles.enrollment_status` and `users.status` are compatibility summaries, not the annual source of truth.
## Staff-HR acceptance dataset safety (2026-08-11)

- البذر والاستعادة يعملان فقط في `test/testing` مع العلامة `integrated-staff-hr` وقاعدة طلب واتصال ينتهي اسمها بـ `_test` وبعد تطبيق ترحيلات Staff-HR؛ ولا ينفذان DDL وقت التشغيل.
- كل صف تجريبي يسجل بمعرفه داخل manifest دفعة ترحيل. الاستعادة تفحص علامة الملكية الخاصة بكل جدول وتحذف بترتيب عكسي يحترم المفاتيح الأجنبية، مع إبقاء سجل الدفعة والتدقيق والبيانات غير المملوكة.
- القبول الحي المحتفظ به يستخدم `educore_staff_hr_acceptance_20260811_v2_test` فقط. أُنشئ باستعادة baseline موثقة إلى قاعدة جديدة ثم reseed لـ121 صفًا مملوكًا؛ لم يُطبق أي ترحيل أو بذر على `educore`.
- `20260808_staff_hr_attendance_period_control.php` يربط `period_key` بتاريخ البداية عبر `YEAR/MONTH` بدل `DATE_FORMAT` داخل `CHECK` حتى يقبله MariaDB المستخدم، مع الإبقاء على حد أول/آخر يوم في الشهر.
- بيانات القبول تربط المدير المباشر والإداري بكل وحدة فعلية تستخدمها الشخصيات، وتثبت HR كـnamed workflow assignee؛ صندوق القرار نفسه يظل مسندًا بالـactor ويعيد التحقق عند كل قرار.
