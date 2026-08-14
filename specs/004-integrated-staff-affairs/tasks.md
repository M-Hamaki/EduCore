# Tasks: منظومة شؤون العاملين والموارد البشرية المتكاملة

**Input**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`
**Tests**: إلزامية لأن المجال محمي ولأن المستخدم طلب تجربة فعلية وبيانات قبول متروكة للتسليم.
**Rule**: كل اختبار كتابة يستخدم قاعدة معزولة باسم ينتهي بـ `_test`، ولا توجد كتابة تجريبية على `educore`.

## Phase 1: Setup — خط الأساس وحواجز الأمان

**Goal**: تثبيت عقود النظام القديم، قاعدة الاختبار، وأدوات القياس قبل أي تغيير أعمال.

- [X] T001 Create route/field/session characterization inventory for current HR pages in `tests/staff_hr_legacy_surface_contract_test.php`
- [X] T002 [P] Characterize existing shift and attendance calculations in `tests/staff_hr_legacy_attendance_characterization_test.php`
- [X] T003 [P] Characterize existing permission, leave, and discipline state transitions in `tests/staff_hr_legacy_request_characterization_test.php`
- [X] T004 Create isolated staff-HR test bootstrap with `_test` fail-closed guard in `tests/bootstrap_staff_hr.php`
- [X] T005 [P] Add staff-HR database-target guard contract tests in `tests/staff_hr_test_database_guard_test.php`
- [X] T006 [P] Add feature-flag and legacy fallback contract tests in `tests/staff_hr_feature_flag_contract_test.php`
- [X] T007 Document module ownership, rollout, and rollback decision in `docs/architecture-decisions.md`
- [X] T008 Update database ownership and protected-write inventory in `docs/database.md`
- [X] T009 Run and record the pre-change `composer audit-write-coverage` baseline in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 2: Foundational — عقود مشتركة وهياكل مانعة

**Goal**: إنشاء البنية المشتركة التي تمنع تكرار السياسات وتسبق جميع القصص.

- [X] T010 Add organization, manager, group, delegation, and policy-version schema in `database/migrations/20260730_staff_hr_organization_policy_foundation.php`
- [X] T011 [P] Add workflow, inbox, outbox, external-effect, and migration-batch schema in `database/migrations/20260730_staff_hr_workflow_operations_foundation.php`
- [X] T012 [P] Add schema contract tests for all foundational tables and indexes in `tests/staff_hr_foundation_schema_contract_test.php`
- [X] T013 Add `_test` migration/apply/rollback integration coverage in `tests/staff_hr_foundation_schema_integration_test.php`
- [X] T014 [P] Define dated staff assignment and manager query contracts in `src/Modules/Staff/Contracts/StaffAssignmentAtDateQuery.php` and `src/Modules/Staff/Contracts/ManagerHierarchyAtDateQuery.php`
- [X] T015 [P] Define portal/access eligibility contracts in `src/Modules/Staff/Contracts/StaffPortalEligibilityQuery.php` and `src/Modules/Staff/Contracts/StaffAccessEligibilityQuery.php`
- [X] T016 [P] Define notification and payroll-effect ports in `src/Modules/Staff/Contracts/StaffNotificationPort.php` and `src/Modules/Staff/Contracts/PayrollImpactGateway.php`
- [X] T017 Implement immutable policy version/scope primitives in `src/Modules/Staff/Domain/Policy/EffectiveDatedPolicy.php` and `src/Modules/Staff/Domain/Policy/PolicyScope.php`
- [X] T018 Implement deterministic scope precedence and tie rejection in `src/Modules/Staff/Application/Policy/EffectivePolicyResolver.php`
- [X] T019 [P] Add policy precedence and conflict unit tests in `tests/staff_hr_effective_policy_resolver_test.php`
- [X] T020 Implement shared notification inbox/outbox adapter in `src/Modules/Staff/Infrastructure/Notification/PdoStaffNotificationOutbox.php`
- [X] T021 Add inbox/outbox idempotency and neutral-text integration tests in `tests/staff_hr_notification_outbox_integration_test.php`
- [X] T022 Register fail-closed audit policies and entity-aware redaction for new resources in `src/Modules/Operations/Audit/AuditPolicyRegistry.php`
- [X] T023 Add audit policy, redaction, transaction, and no-direct-undo contract tests in `tests/staff_hr_audit_policy_contract_test.php`
- [X] T024 Wire new module services without changing public routes in `src/Modules/Staff/bootstrap.php` and `src/Modules/Attendance/bootstrap.php`
- [X] T025 Run focused foundation tests plus `composer architecture-audit` and mark T001-T024 evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

**Checkpoint**: الأساس يعمل على `_test`، وكل جدول جديد مسجل في التدقيق ولا يتغير أي مسار قديم.

---

## Phase 3: User Story 1 — تطبيق الدوام الصحيح على كل عامل (P1)

**Goal**: سياسات دوام مؤرخة عامة/قوة/مسمى/مجموعة/عامل مع تفسير وأولوية حتمية.

**Independent Test**: اختيار يوم وعامل يعيد السياسة الصحيحة ومصدرها خلال جميع درجات الأولوية، ويرفض التعادل.

- [X] T026 [P] [US1] Add schedule/calendar/change-request schema in `database/migrations/20260730_staff_hr_schedule_calendar.php`
- [X] T027 [P] [US1] Add schedule schema, isolated migration/rollback, overlap, publication-immutability, and audited command tests in `tests/staff_hr_schedule_schema_contract_test.php`, `tests/staff_hr_schedule_schema_integration_test.php`, and `tests/staff_hr_schedule_policy_command_test.php`
- [X] T028 [US1] Implement the schedule policy repository and transaction-owning audited command service in `src/Modules/Attendance/Infrastructure/PdoSchedulePolicyRepository.php` and `src/Modules/Attendance/Application/SchedulePolicyCommandService.php`
- [X] T029 [US1] Implement effective schedule resolution plus the administration read model in `src/Modules/Attendance/Application/EffectiveScheduleQueryService.php` and `src/Modules/Attendance/Application/SchedulePolicyAdminQueryService.php`
- [X] T030 [P] [US1] Implement split-shift, break, overnight, and seasonal domain rules in `src/Modules/Attendance/Domain/Schedule/WorkSchedule.php`
- [X] T031 [P] [US1] Add precedence, overnight, break, and calendar unit tests in `tests/staff_hr_schedule_resolution_test.php`
- [X] T032 [US1] Implement temporary shift/swap/overtime request service in `src/Modules/Attendance/Application/ScheduleChangeRequestService.php`
- [X] T033 [P] [US1] Add swap acceptance, overlap, and overtime contract tests in `tests/staff_hr_schedule_change_contract_test.php`
- [X] T034 [US1] Build schedule policy administration surface in `admin/hr_policy_calendar.php`
- [X] T035 [US1] Extract an audited legacy-write adapter and convert `admin/staff_shifts.php` to a compatibility entrypoint while preserving every current field/action in `src/Modules/Attendance/Application/LegacyStaffShiftCompatibilityService.php` and `admin/staff_shifts.php`
- [X] T036 [P] [US1] Add admin auth/CSRF/UI/compatibility tests in `tests/staff_hr_schedule_admin_contract_test.php`
- [X] T037 [US1] Add Staff-owned assignment/population adapters, the Attendance module factory, and policy impact preview/conflict report in `src/Modules/Staff/Contracts/StaffPopulationAtDateQuery.php`, `src/Modules/Staff/Infrastructure/PdoStaffPopulationAtDateQuery.php`, `src/Modules/Staff/Infrastructure/PdoStaffAssignmentAtDateQuery.php`, `src/Modules/Attendance/Infrastructure/AttendanceModuleFactory.php`, and `src/Modules/Attendance/Application/SchedulePolicyImpactQuery.php`
- [X] T038 [US1] Verify Q01-Q03 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 4: User Story 2 — تحويل البصمات إلى حضور قابل للتفسير (P1)

**Goal**: أحداث خام غير قابلة للتعديل ونتيجة يوم مؤرخة وقابلة للتفسير والتصحيح.

**Independent Test**: استيراد بصمات طبيعية/ناقصة/مكررة/ليلية/متأخرة ينتج نتيجة صحيحة دون تعديل الخام.

- [X] T039 [P] [US2] Add `staff_attendance_entry_methods` plus biometric mapping/event/run/day-version/reason schema in `database/migrations/20260730_staff_hr_attendance_engine.php`; entry methods belong to the attendance-event ingestion boundary, not the schedule/calendar migration
- [X] T040 [P] [US2] Add entry-method safety, uniqueness, immutability, official-version, and clock-field schema tests in `tests/staff_hr_attendance_schema_contract_test.php`
- [X] T041 [US2] Implement dated biometric identity mapping service in `src/Modules/Attendance/Application/BiometricIdentityMappingService.php`
- [X] T042 [US2] Implement idempotent raw event ingestor with device/received time in `src/Modules/Attendance/Application/AttendanceEventIngestor.php`
- [X] T043 [P] [US2] Add identity reuse, duplicate, delayed, and clock-drift tests in `tests/staff_hr_biometric_ingestor_test.php`
- [X] T044 [US2] Implement schedule-window event matcher in `src/Modules/Attendance/Domain/Calculation/PunchWindowMatcher.php`
- [X] T045 [US2] Implement versioned attendance day calculator and reason lines in `src/Modules/Attendance/Domain/Calculation/AttendanceDayCalculator.php`
- [X] T046 [P] [US2] Add missing punch, overnight, multi-device, split-shift, and alternative-entry tests in `tests/staff_hr_attendance_calculator_test.php`
- [X] T047 [US2] Implement alternative attendance recorder and review rules in `src/Modules/Attendance/Application/AlternativeAttendanceRecorder.php`
- [X] T048 [US2] Implement worker/manager/HR attendance correction workflow in `src/Modules/Attendance/Application/AttendanceAdjustmentService.php`
- [X] T049 [P] [US2] Add correction authorization, versioning, and raw immutability integration tests in `tests/staff_hr_attendance_adjustment_integration_test.php`
- [X] T050 [US2] Implement shadow run and legacy comparison service in `src/Modules/Attendance/Application/AttendanceShadowRunService.php`
- [X] T051 [US2] Build exception review surface in `admin/hr_attendance_exceptions.php`
- [X] T052 [US2] Adapt biometric import and attendance pages to the new services in `admin/staff_biometric_import.php` and `admin/staff_attendance.php`
- [X] T053 [P] [US2] Add route/auth/CSRF and raw-event compatibility tests in `tests/staff_hr_attendance_admin_contract_test.php`
- [X] T054 [US2] Verify Q03, Q07, Q18-Q22 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 5: User Story 3 — طلب إذن من بوابة العامل (P1)

**Goal**: خدمة ذاتية للأذونات مع أنواع قابلة للتوسع وحصة شهرية سليمة.

**Independent Test**: العامل يقدم إذنًا ويرى المستخدم/المحجوز/المتاح؛ الطلبان المتزامنان لا يتجاوزان الحصة.

- [X] T055 [P] [US3] Add permission type/policy/request/quota-ledger schema in `database/migrations/20260730_staff_hr_permissions_quota.php`
- [X] T056 [P] [US3] Add permission policy, period split, and quota invariant schema tests in `tests/staff_hr_permission_schema_contract_test.php`
- [X] T057 [US3] Implement permission policy resolver in `src/Modules/Staff/Application/Permission/PermissionPolicyResolver.php`
- [X] T058 [US3] Implement quota account/ledger with row locking and idempotency in `src/Modules/Staff/Application/Permission/PermissionQuotaLedger.php`
- [X] T059 [P] [US3] Add concurrent reserve/consume/release tests in `tests/staff_hr_permission_quota_concurrency_test.php`
- [X] T060 [US3] Implement permission draft/submit/withdraw/cancel service in `src/Modules/Staff/Application/Permission/PermissionRequestService.php`
- [X] T061 [P] [US3] Add overlap, retroactive, custom-other, and future-assignment tests in `tests/staff_hr_permission_request_test.php`
- [X] T062 [US3] Add reusable staff self-service portal component in `src/Modules/Staff/Presentation/self_service_requests.php`
- [X] T063 [US3] Adapt `admin/permissions.php` to the new owner without changing legacy fields/actions in `admin/permissions.php`
- [X] T064 [P] [US3] Add self-only, IDOR, CSRF, draft recovery, and error-message tests in `tests/staff_hr_permission_portal_contract_test.php`

---

## Phase 6: User Story 4 — اعتماد متعدد المراحل وتنبيه المديرين (P1)

**Goal**: مسارات مؤرخة، مدير مباشر/إداري، إنابة، نصاب، تعارض، وصندوق معتمد.

**Independent Test**: الطلب يمر بالمراحل الصحيحة ولا يراه أو يقرره غير المسند، ولا يحتسب actor نفسه مرتين.

- [X] T066 [P] [US4] Add workflow template/stage/instance/decision/delegation tests in `tests/staff_hr_approval_schema_contract_test.php`
- [X] T067 [US4] Implement dated manager hierarchy repository in `src/Modules/Staff/Infrastructure/Organization/PdoManagerHierarchyQuery.php`
- [X] T068 [US4] Implement workflow snapshot and approver resolver in `src/Modules/Staff/Application/Approval/ApprovalWorkflowResolver.php`
- [X] T069 [P] [US4] Add direct/admin/fallback/delegation/conflict resolution tests in `tests/staff_hr_approval_resolver_test.php`
- [X] T070 [US4] Implement submit/decide/reassign/escalate state machine in `src/Modules/Staff/Application/Approval/ApprovalWorkflowService.php`
- [X] T071 [P] [US4] Add simultaneous decision, quorum, tie, rejection, and same-actor tests in `tests/staff_hr_approval_concurrency_test.php`
- [X] T072 [US4] Implement assigned approval inbox query in `src/Modules/Staff/Application/Approval/AssignedApprovalInboxQuery.php`
- [X] T073 [US4] Build manager inbox component and dashboard counter in `src/Modules/Staff/Presentation/manager_approval_inbox.php`
- [X] T074 [US4] Integrate neutral notification events and retry outbox in `src/Modules/Staff/Application/Approval/ApprovalNotificationService.php`
- [X] T075 [P] [US4] Add notification leakage, expired delegation, service-end, and session revalidation tests in `tests/staff_hr_approval_authorization_contract_test.php`
- [X] T076 [US4] Build workflow/delegation administration surface in `admin/hr_approval_workflows.php`
- [X] T077 [US4] Adapt pending actions in `admin/hr_center.php` to assigned approvals in `admin/hr_center.php`
- [X] T078 [P] [US4] Add admin compatibility and least-privilege page tests in `tests/staff_hr_approval_admin_contract_test.php`
- [X] T079 [US4] Verify Q08-Q09, Q23-Q24 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 7: User Story 5 — احتساب الإذن داخل نتيجة الحضور (P1)

**Goal**: تغطية المخالفة بالدقائق المعتمدة فقط مع إعادة احتساب وإقفال مؤرخ.

**Independent Test**: أمثلة 07:30/09:00 و14:30/12:00 صحيحة، والإذن بلا بصمة لا يثبت حضورًا.

- [X] T080 [P] [US5] Define approved coverage query contract in `src/Modules/Attendance/Contracts/ApprovedCoverageQuery.php`
- [X] T081 [US5] Implement permission/leave/mission coverage adapter in `src/Modules/Attendance/Infrastructure/PdoApprovedCoverageQuery.php`
- [X] T082 [US5] Extend day calculator with unioned coverage and no-double-grace rules in `src/Modules/Attendance/Domain/Calculation/AttendanceDayCalculator.php`
- [X] T083 [P] [US5] Add late/early/mission/overlap/no-show coverage tests in `tests/staff_hr_attendance_coverage_test.php`
- [X] T084 [US5] Implement idempotent affected-day recalculation service in `src/Modules/Attendance/Application/AttendanceRecalculationService.php`
- [X] T085 [US5] Implement period close/reopen and late-event handling in `src/Modules/Attendance/Application/AttendancePeriodService.php`
- [X] T086 [P] [US5] Add locked-period, late approval, reversal, and atomic batch tests in `tests/staff_hr_attendance_recalculation_integration_test.php`
- [X] T087 [US5] Publish coverage-approved/reversed events from request services in `src/Modules/Staff/Application/Approval/ApprovedCoveragePublisher.php`
- [X] T088 [US5] Verify Q04-Q06, Q18, Q25-Q26 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 8: User Story 6 — تقارير حضور وإذن متقدمة (P1)

**Goal**: تقارير رسمية يومية/شهرية/سنوية وفترية قابلة للتفسير والتصدير.

**Independent Test**: الإجماليات تساوي التفاصيل، والنسب تستخدم أيام العمل المؤهلة، والنطاق يمنع كشف غير المرؤوسين.

- [X] T089 [P] [US6] Add report projection/index schema in `database/migrations/20260730_staff_hr_attendance_reporting.php`
- [X] T090 [P] [US6] Add official-version and aggregate schema tests in `tests/staff_hr_attendance_reporting_schema_test.php`
- [X] T091 [US6] Implement official day report query with dated dimensions in `src/Modules/Attendance/Application/AttendanceReportQueryService.php`
- [X] T092 [US6] Implement monthly/annual/range aggregate projector in `src/Modules/Attendance/Application/AttendanceReportProjector.php`
- [X] T093 [P] [US6] Add denominator, totals, drill-down, reopened-version, and filter tests in `tests/staff_hr_attendance_report_query_test.php`
- [X] T094 [US6] Implement CSV/print export with formula protection in `src/Modules/Attendance/Presentation/AttendanceReportExporter.php`
- [X] T095 [P] [US6] Add export scope, formula, paging, and memory tests in `tests/staff_hr_attendance_export_contract_test.php`
- [X] T096 [US6] Rebuild report surface over the query service in `admin/staff_attendance_reports.php`
- [X] T097 [P] [US6] Add report UI/filters/role/legacy URL contract tests in `tests/staff_hr_attendance_reports_ui_contract_test.php`
- [X] T098 [US6] Add 500-staff/five-year performance fixture and benchmark in `tests/staff_hr_attendance_reporting_performance_test.php`
- [X] T099 [US6] Verify Q15 and locked/reopened report scenarios in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 9: User Story 7 — إدارة الإجازات والأرصدة (P2)

**Goal**: سياسات إجازة قابلة للتوسع ودفتر رصيد وحجز وتداخل ومرفقات خاصة.

**Independent Test**: طلب عابر للسنة يحجز/يستهلك/يعيد الرصيد الصحيح ولا يتجاوز حد التشغيل.

- [X] T100 [P] [US7] Add leave type/policy/request/day/account/ledger/return schema in `database/migrations/20260730_staff_hr_leave_ledger.php`
- [X] T101 [P] [US7] Add leave ledger, movement, snapshot, and no-negative schema tests in `tests/staff_hr_leave_schema_contract_test.php`
- [X] T102 [US7] Implement leave policy and workday calculator in `src/Modules/Staff/Application/Leave/LeavePolicyService.php`
- [X] T103 [US7] Implement locked leave balance ledger in `src/Modules/Staff/Application/Leave/LeaveBalanceLedger.php`
- [X] T104 [P] [US7] Add accrual/carry/expiry/year-crossing/concurrency tests in `tests/staff_hr_leave_balance_test.php`
- [X] T105 [US7] Implement leave request/extend/early-return/cancel service in `src/Modules/Staff/Application/Leave/LeaveRequestService.php`
- [X] T106 [US7] Implement staffing minimum/blackout policy check in `src/Modules/Staff/Application/Leave/LeaveStaffingPolicy.php`
- [X] T107 [P] [US7] Add overlap, staffing, service-end, and policy-snapshot tests in `tests/staff_hr_leave_request_test.php`
- [X] T108 [US7] Implement private medical attachment flow via `FileUploadGuard` in `src/Modules/Staff/Application/Leave/LeaveAttachmentService.php`
- [X] T109 [P] [US7] Add MIME/name/size/authorization/file-DB rollback tests in `tests/staff_hr_leave_attachment_integration_test.php`
- [X] T110 [US7] Adapt `admin/leaves.php` and `admin/leave_balances.php` to the new owner in `admin/leaves.php` and `admin/leave_balances.php`
- [X] T111 [P] [US7] Add leave portal/admin/UI/compatibility tests in `tests/staff_hr_leave_ui_contract_test.php`
- [X] T112 [US7] Implement Finance fact emission for paid/unpaid leave in `src/Modules/Staff/Application/Leave/LeaveFinanceEffectService.php`
- [X] T113 [US7] Verify Q11-Q12, Q24-Q27 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 10: User Story 8 — الجزاءات والتأديب والتظلم (P2)

**Goal**: قضية عادلة متعددة الأطراف مع تحقيق وقرار وتظلم وإجراء احترازي وأثر مالي منفصل.

**Independent Test**: لا حذف ولا جزاء تلقائي؛ التظلم والإجراء المؤقت وإعادة الفتح تحفظ الأصل وفصل الواجبات.

- [X] T114 [P] [US8] Add incident/case/party/investigation/evidence/decision/appeal/interim/reopen schema in `database/migrations/20260730_staff_hr_discipline.php`
- [X] T115 [P] [US8] Add discipline state, immutability, and separation schema tests in `tests/staff_hr_discipline_schema_contract_test.php`
- [X] T116 [US8] Implement incident/case and party service in `src/Modules/Staff/Application/Discipline/DisciplineCaseService.php`
- [X] T117 [US8] Implement investigation/evidence chain and private attachments in `src/Modules/Staff/Application/Discipline/DisciplineInvestigationService.php`
- [X] T118 [US8] Implement decision/notification/receipt service in `src/Modules/Staff/Application/Discipline/DisciplineDecisionService.php`
- [X] T119 [US8] Implement appeal, interim measure, and audited reopen service in `src/Modules/Staff/Application/Discipline/DisciplineAppealService.php`
- [X] T120 [P] [US8] Add role separation, conflict, receipt failure, interim, and reopen tests in `tests/staff_hr_discipline_workflow_test.php`
- [X] T121 [US8] Implement idempotent Finance fact/reversal adapter in `src/Modules/Staff/Application/Discipline/DisciplineFinanceEffectService.php`
- [X] T122 [P] [US8] Add posted-period and duplicate-effect tests in `tests/staff_hr_discipline_finance_integration_test.php`
- [X] T123 [US8] Rebuild `admin/disciplinary.php` as a compatible case adapter in `admin/disciplinary.php`
- [X] T124 [P] [US8] Add confidential fields, no-hard-delete, modal, and route tests in `tests/staff_hr_discipline_ui_contract_test.php`
- [X] T125 [US8] Verify Q13 and Q29 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 11: User Story 9 — منصة ارتق للشكاوى والمقترحات (P2)

**Goal**: تذاكر ومحادثات سرية وعاجلة ومتعددة الأطراف مع SLA ومسار حماية.

**Independent Test**: شكوى ضد المدير أو شكوى خطر فوري تصل للمخول فقط، ولا تتسرب في البحث أو Push.

- [X] T126 [P] [US9] Add Ertaq ticket/message/party/link/assignment/SLA/urgent schema in `database/migrations/20260730_staff_hr_ertaq.php`
- [X] T127 [P] [US9] Add confidentiality, immutable message, and urgent-route schema tests in `tests/staff_hr_ertaq_schema_contract_test.php`
- [X] T128 [US9] Implement ticket create/classify/assign/state service in `src/Modules/Staff/Application/Ertaq/ErtaqTicketService.php`
- [X] T129 [US9] Implement messages, parties, linked/collective tickets, and withdrawal service in `src/Modules/Staff/Application/Ertaq/ErtaqConversationService.php`
- [X] T130 [US9] Implement urgent protection route and conflict exclusion in `src/Modules/Staff/Application/Ertaq/ErtaqUrgentRoutingService.php`
- [X] T131 [US9] Implement SLA overdue queue/escalation service in `src/Modules/Staff/Application/Ertaq/ErtaqSlaService.php`
- [X] T132 [P] [US9] Add accused-manager bypass, collective, withdrawal, reopen, and SLA tests in `tests/staff_hr_ertaq_workflow_test.php`
- [X] T133 [US9] Implement private attachment and neutral notification handling in `src/Modules/Staff/Application/Ertaq/ErtaqAttachmentNotificationService.php`
- [X] T134 [P] [US9] Add search/notification/attachment leakage tests in `tests/staff_hr_ertaq_security_contract_test.php`
- [X] T135 [US9] Build worker conversation and admin assigned-inbox surfaces in `src/Modules/Staff/Presentation/ertaq_portal.php` and `admin/hr_ertaq.php`
- [X] T136 [P] [US9] Add portal/admin/IDOR/RTL/error-state tests in `tests/staff_hr_ertaq_ui_contract_test.php`
- [X] T137 [US9] Verify Q14 and Q28 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 12: User Story 10 — دورة حياة العامل ولوحة HR موحدة (P2)

**Goal**: تعيينات ومديرون مؤرخون وخدمة ذاتية متعددة الأدوار وخط زمني ولوحة تشغيل.

**Independent Test**: النقل/الوقف/العودة/الانتهاء يغير الأهلية والنطاق في التاريخ الصحيح ويحفظ الماضي.

- [X] T138 [P] [US10] Add organization assignment backfill/compatibility schema in `database/migrations/20260730_staff_hr_assignment_backfill.php`
- [X] T139 [P] [US10] Add ambiguous-data quarantine and dated-assignment tests in `tests/staff_hr_assignment_backfill_integration_test.php`
- [X] T140 [US10] Implement organization/unit/group/manager command service in `src/Modules/Staff/Application/Organization/StaffOrganizationService.php`
- [X] T141 [US10] Implement dated assignment and access eligibility queries in `src/Modules/Staff/Infrastructure/Organization/PdoStaffAssignmentAtDateQuery.php`
- [X] T142 [P] [US10] Add transfer, suspension, rehire, concurrent assignment, and access-revocation tests in `tests/staff_hr_lifecycle_access_test.php`
- [X] T143 [US10] Implement role-independent portal eligibility adapter in `src/Modules/Staff/Application/Portal/StaffPortalEligibilityService.php`
- [X] T144 [P] [US10] Add multi-role self-service and manager-scope tests in `tests/staff_hr_multi_role_portal_contract_test.php`
- [X] T145 [US10] Implement unified staff timeline query from owned contracts in `src/Modules/Staff/Application/Timeline/StaffHrTimelineQuery.php`
- [X] T146 [US10] Implement qualification/training/document expiry tracking in `src/Modules/Staff/Application/Timeline/StaffDocumentExpiryService.php`
- [X] T147 [US10] Build organization and assignment administration surface in `admin/hr_organization.php`
- [X] T148 [US10] Build unified operational center and audit surface in `admin/hr_center.php` and `admin/hr_audit.php`
- [X] T149 [P] [US10] Add timeline, dashboard, access, and legacy compatibility tests in `tests/staff_hr_center_ui_contract_test.php`
- [X] T150 [US10] Verify Q16, Q24, Q26 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 12.5: Cross-story permission acceptance evidence

**Goal**: إثبات سيناريوهات الأذونات التي تعتمد على مالكي الاعتماد، تغطية الحضور، وبوابة العامل متعددة الأدوار.

**Execution rule**: هذه المهمة كانت مدرجة سابقًا في Phase 5، لكن Q04-Q06 وQ18 تحتاجان T080-T088، وQ16 يحتاج T143-T150؛ لذلك نُقلت دون تغيير متطلباتها حتى لا يُعلَن قبول غير مبني.

- [X] T065 [US3] Verify Q04-Q06, Q10, Q16-Q18 and record evidence in `specs/004-integrated-staff-affairs/implementation-log.md`

---

## Phase 13: Acceptance, Migration, Security, and Handoff

**Goal**: إثبات المتطلبات الـ150، ترك بيئة قبول قابلة للتجربة، وتأمين الرجوع.

- [X] T151 Add resumable migration/cutover coordinator in `src/Modules/Staff/Infrastructure/Migration/StaffHrMigrationCoordinator.php`
- [X] T152 [P] Add checkpoint, rerun, concurrent-write, quarantine, and rollback tests in `tests/staff_hr_migration_coordinator_integration_test.php`
- [X] T153 Create guarded acceptance dataset manifest/builder in `tests/fixtures/staff_hr_acceptance_dataset.php`
- [X] T154 [P] Create acceptance dataset isolation/idempotency contract tests in `tests/staff_hr_acceptance_dataset_contract_test.php`
- [X] T155 Create guarded seed command in `tools/staff_hr_acceptance_seed.php`
- [X] T156 Create manifest-owned baseline restore command in `tools/staff_hr_acceptance_restore.php`
- [X] T157 [P] Add real-database refusal and scoped-restore integration tests in `tests/staff_hr_acceptance_restore_integration_test.php`
- [X] T158 Create browser acceptance harness and persona session helpers in `tests/browser/staff_hr_acceptance_runner.js`
- [X] T159 [P] Implement browser journeys Q01-Q17 in `tests/browser/staff_hr_acceptance_core.spec.js`
- [X] T160 [P] Implement browser journeys Q18-Q30 in `tests/browser/staff_hr_acceptance_edges.spec.js`
- [X] T161 [P] Implement browser journeys Q31-Q33 and baseline replay in `tests/browser/staff_hr_acceptance_handoff.spec.js`
- [X] T162 Generate redacted evidence index and result report in `tools/staff_hr_acceptance_report.php`
- [X] T163 Create Arabic user retest guide and secure credential handoff checklist in `docs/staff-hr-acceptance-guide.md`
- [X] T164 Add feature rollout flags, shadow/compare/display/official operational runbook in `docs/staff-hr-rollout-runbook.md`
- [X] T165 [P] Add security tests for IDOR, CSRF, sensitive search, export, session expiry, and private files in `tests/staff_hr_security_suite.php`
- [X] T166 [P] Add 500-staff/five-year performance and recalculation-resume suite in `tests/staff_hr_performance_suite.php`
- [X] T167 Run all focused PHP/JS tests and record exact commands/results in `specs/004-integrated-staff-affairs/implementation-log.md`
- [X] T168 Run `composer upload-policy-audit`, `composer architecture-audit`, `composer audit-write-coverage`, and `composer quality`
- [X] T169 Execute acceptance seed and all browser journeys on the isolated acceptance database, leaving the dataset available and recording results in `specs/004-integrated-staff-affairs/implementation-log.md`
- [X] T170 Restore acceptance baseline and repeat the worker→approval→report journey, recording handoff proof in `specs/004-integrated-staff-affairs/implementation-log.md`
- [X] T171 Reconcile legacy/new counts, hashes, and two representative reporting cycles and document every difference in `specs/004-integrated-staff-affairs/completion-audit.md`
- [X] T172 Update `docs/project-memory.md`, `docs/database.md`, and `docs/architecture-decisions.md` with final ownership and rollout evidence
- [X] T173 Run requirement-to-task and requirement-to-runtime completion audit for FR-001..FR-150 and SC-001..SC-024 in `specs/004-integrated-staff-affairs/completion-audit.md`
- [X] T174 Verify `git diff --check`, review scoped diff, and mark every completed task `[X]` in `specs/004-integrated-staff-affairs/tasks.md`

---

## Dependencies and Execution Order

### Phase Dependencies

- Phase 1 has no dependency.
- Phase 2 depends on Phase 1 and blocks all user stories.
- US1 blocks US2 and contributes to US3/US7.
- US2 blocks US5 and US6.
- US3 and US4 can progress in parallel after Phase 2, but both block US5.
- US6 depends on US2 and US5 for official results.
- T065 executes after T088 (approved coverage/recalculation evidence) and T150 (multi-role portal eligibility evidence); it is a cross-story acceptance task, not a Phase 5 completion gate.
- US7 depends on US1 and US4; its Finance adapter can progress after Phase 2.
- US8 depends on US4 and the Finance port.
- US9 depends on US4, notification outbox, private attachments, and organization scope.
- US10 organization foundations begin in Phase 2; its final timeline integrates US1-US9.
- Phase 13 depends on all stories and is the release/acceptance gate.

### User Story Dependency Graph

```text
Foundation
 ├─ US1 Schedule ──> US2 Attendance ──> US5 Coverage ──> US6 Reports
 ├─ US3 Permissions ─┘
 ├─ US4 Approvals ──> US7 Leave ──> US10 Timeline
 │                ├─> US8 Discipline ─┘
 │                └─> US9 Ertaq ──────┘
 └─ Organization/Access ───────────────┘
All stories ──> Acceptance/Migration/Handoff
```

### Parallel Opportunities

- Schema contract tests marked `[P]` can run before their migration implementation.
- Domain tests for schedule, quota, approvals, leave, discipline, and Ertaq are file-independent.
- US3 request policy and US4 workflow engine can progress in parallel after Phase 2.
- US7, US8, and US9 domain work can progress in parallel once approvals/contracts are stable.
- Browser suites Q01-Q17, Q18-Q30, and Q31-Q33 can run in parallel only after a fresh shared baseline per worker.

## Implementation Strategy

1. **Safety first**: complete T001-T025; no production-like write before `_test` guard passes.
2. **Attendance MVP**: complete US1→US2→US3→US4→US5 and prove the two user examples.
3. **Official reporting**: complete US6 only after shadow differences are explained.
4. **P2 HR flows**: deliver US7, US8, US9, then unified US10 timeline.
5. **Acceptance**: execute T151-T174; the feature is not complete merely because automated tests pass.
6. **No legacy deletion**: old tables/routes remain adapters until a separate retirement decision proves no callers.

## Independent Story Test Summary

| Story | Independent proof |
|---|---|
| US1 | Deterministic policy source and conflict rejection for any worker/day |
| US2 | Raw events preserved; correct versioned day result for normal and edge punches |
| US3 | Worker self-service request and concurrency-safe quota |
| US4 | Correct assigned multi-stage approvals with delegation/conflict controls |
| US5 | Approved minutes cover only their window; no-show remains missing/absent |
| US6 | Totals equal details and scoped reports export safely |
| US7 | Leave ledger balances across years and concurrent requests |
| US8 | Fair case/appeal/interim/reopen flow with no hard delete |
| US9 | Confidential/urgent/collective Ertaq routing without leakage |
| US10 | Dated lifecycle, access revocation, and unified timeline |

## Format Validation

- All implementation items use `- [ ] TNNN`.
- Story-phase tasks include `[US#]`.
- `[P]` is used only for different files without an incomplete dependency in the same file.
- Every task names an exact target file.

---

## Phase 14: Convergence — إغلاق دورة الإجازة التشغيلية

**Purpose**: معالجة فجوات الاعتماد النهائي والاستثناءات والتشغيل التي اكتشفتها مراجعة التقارب، قبل اعتبار إدارة الإجازات مكتملة تشغيليًا.

- [X] T175 [US7] Implement a resource-aware final leave-approval outcome bridge in `src/Modules/Staff/Application/Leave/LeaveApprovalOutcomeHandler.php`, `src/Modules/Staff/Application/Approval/StaffApprovalOutcomeRouter.php`, and `src/Modules/Staff/Infrastructure/StaffModuleFactory.php` that applies/reverts the correct immutable leave-ledger movements, publishes attendance coverage changes, and queues Finance facts only after final approval (FR-064, FR-071, FR-078, Q11, Q25).
- [X] T176 [US7] Implement the authorized, reason-required staffing-override lifecycle in `src/Modules/Staff/Application/Leave/LeaveStaffingOverrideService.php` and `tests/staff_hr_leave_staffing_override_test.php`, with role/approval verification, immutable snapshot/audit, and no balance mutation before a decision (FR-137, Q27).
- [X] T177 [US7] Extend `src/Modules/Staff/Presentation/self_service_requests.php` and add `tests/staff_hr_leave_self_service_portal_contract_test.php` so the worker can safely submit, inspect, and attach evidence to leave requests and the authorized manager can act through the approved workflow, with CSRF/idempotency and Arabic-safe errors (US7, FR-063, FR-070, FR-071).
- [X] T178 [US7] Add a due-effect claim/dispatch path in `src/Modules/Staff/Application/Leave/LeaveFinanceEffectService.php`, `src/Modules/Staff/Infrastructure/PdoLeaveFinanceEffectRepository.php`, `tools/staff_leave_finance_effect_dispatcher.php`, and `tests/staff_hr_leave_finance_effect_dispatch_test.php`, preserving retry/idempotency and the Finance contract boundary (FR-078, Q25).

---

## Phase 15: Convergence — التصحيح التنظيمي المؤثر

**Purpose**: إغلاق فجوة المعاينة والاعتماد وإعادة الاحتساب المحددة التي كشفها إثبات T150 قبل إعلان Q26.

- [X] T179 [US10] Implement an immutable preview→approval→scoped-impact workflow for retroactive organization, title, manager, and calendar corrections in `src/Modules/Staff/Application/Organization/StaffOrganizationCorrectionService.php`, `src/Modules/Staff/Contracts/StaffOrganizationCorrectionRepository.php`, `src/Modules/Staff/Contracts/StaffOrganizationCorrectionImpactGateway.php`, `src/Modules/Staff/Infrastructure/Organization/PdoStaffOrganizationCorrectionRepository.php`, `database/migrations/20260809_staff_hr_organization_corrections.php`, and `tests/staff_hr_organization_correction_test.php`, then expose the reviewed flow from `admin/hr_organization.php` and record Q26 evidence per FR-136/Q26 (missing).

---

## Phase 16: Convergence — بوابات الخدمة الذاتية والقبول الحي

**Purpose**: إغلاق الفجوات البنائية التي أثبتها T173 قبل السماح بتنفيذ T169 أو إعلان جاهزية المستخدم.

- [X] T180 [US3] [US4] [US7] [US9] Wire the shared permission/leave self-service, assigned manager inbox, and worker Ertaq conversation components into the existing teacher, specialist, supervisor, and authorized admin/employee portals without a new auth stack or role directory, preserving active-role sessions and adding focused self-scope/manager-scope/CSRF/IDOR/RTL contract tests per FR-024, FR-048, FR-063, FR-094, and FR-111 (missing, CRITICAL).
- [X] T181 Expand `tests/fixtures/staff_hr_acceptance_dataset.php`, `tools/includes/StaffHrAcceptanceDatasetStore.php`, and the manifest-owned restore integration test so the deterministic baseline owns representative biometric events, permission/leave requests and ledgers, attendance versions, a discipline case/appeal, and normal/confidential/urgent Ertaq tickets without real data or secrets, with exact idempotent seed/restore ownership proof per FR-144 and SC-023.
- [X] T182 Implement a concrete same-origin UI action executor for every action named by Q01–Q33 in `tests/browser/staff_hr_acceptance_*`, using the existing HTTP forms/routes, CSRF, role sessions, safe uploads, and redacted evidence references; any unavailable field, route, or expected result must remain `blocked` and prevent T169 per FR-146 and SC-021 (missing, HIGH).

---

## Phase 17: Convergence — إغلاق أسطح القبول الحي المتبقية

**Purpose**: تحويل إجراءات Q01–Q33 التي ما زالت تفشل مغلقة إلى رحلات واجهة حقيقية قابلة للإعادة على قاعدة القبول المعزولة، دون إضافة مسار مصادقة أو قاعدة بيانات أو أدوات تشغيل مكشوفة للويب.

- [X] T183 [US1] [US2] Complete the dated schedule, raw-biometric import, attendance-exception, period-close, and scoped recalculation UI actions in `admin/staff_shifts.php`, `admin/staff_biometric_import.php`, `admin/hr_attendance_exceptions.php`, `admin/staff_attendance_audit.php`, and `tests/browser/staff_hr_acceptance_action_executor.js`, with CSRF/audit/Arabic result evidence for Q01-Q03, Q07, Q21, Q25, and Q26 per FR-003..FR-023, FR-130, FR-135, FR-136, FR-146, and SC-021 (partial, HIGH).
- [X] T184 [US3] [US4] [US5] Complete the permission quota, ordered approval, delegation, attendance-adjustment, conflict, future-dated, and final-coverage UI actions in `staff_hr_portal.php`, `admin/hr_approval_workflows.php`, `admin/hr_attendance_exceptions.php`, and `tests/browser/staff_hr_acceptance_action_executor.js`, preserving server-side assignment revalidation and no self-approval for Q04-Q10, Q17-Q19, Q23, and Q24 per FR-024..FR-050, FR-122, FR-123, FR-126, FR-127, FR-132..FR-134, FR-141, FR-146, and SC-021 (partial, HIGH).
- [X] T185 [US7] Complete worker/manager leave submission, opening-balance, cross-year ledger, safe private medical attachment, rollback, blackout, staffing-limit, and reasoned override UI actions in `staff_hr_portal.php`, `admin/leaves.php`, `admin/leave_balances.php`, and `tests/browser/staff_hr_acceptance_action_executor.js` for Q11, Q12, and Q27 per FR-062..FR-080, FR-115, FR-137, FR-146, and SC-021 (partial, HIGH).
- [X] T186 [US8] Complete the separated incident, investigation, decision, appeal, temporary-measure, close, and reopen UI actions in `admin/disciplinary.php` and `tests/browser/staff_hr_acceptance_action_executor.js`, retaining immutable prior decisions and Finance facts for Q13 and Q29 per FR-081..FR-093, FR-140, FR-146, and SC-021 (partial, HIGH).
- [X] T187 [US9] Complete confidential, normal, conflicted-access, urgent protection-team, collective-party, withdrawal, conversion, close, and reopen UI actions in `staff_hr_portal.php`, `admin/hr_ertaq.php`, and `tests/browser/staff_hr_acceptance_action_executor.js` for Q14 and Q28 per FR-094..FR-105, FR-138, FR-139, FR-146, SC-014, SC-020, and SC-021 (partial, HIGH).
- [X] T188 [US6] Complete report filter, totals drill-down, formula-safe export, denominator/scope, cross-role reconciliation, and redacted Finance-fact UI verification in `admin/staff_attendance_reports.php`, `admin/finance_staff_ledger.php`, and `tests/browser/staff_hr_acceptance_action_executor.js` for Q15 and Q32 per FR-051..FR-061, FR-119, FR-146, SC-003, SC-008, and SC-021 (partial, HIGH).
- [X] T189 [US2] Complete biometric-identity overlap/reuse, delayed/drifted event, split-shift, temporary swap, overtime, and alternative-attendance review UI actions in `admin/staff.php`, `admin/staff_biometric_import.php`, `admin/hr_attendance_exceptions.php`, and `tests/browser/staff_hr_acceptance_action_executor.js` for Q20-Q22 per FR-128..FR-131, FR-143, FR-146, SC-017, and SC-021 (partial, HIGH).
- [X] T190 Implement a non-web, fail-closed acceptance operator adapter in `tests/browser/staff_hr_acceptance_operator.js` and wire it through `tests/browser/run_staff_hr_acceptance.js` for migration interruption/resume, refused-target seed, idempotent seed/checksum, receipt capture, manifest-owned mutation, scoped baseline restore, and replay actions in Q30, Q31, and Q33; it MUST invoke only the existing guarded CLI owners in `tools/staff_hr_acceptance_seed.php` and `tools/staff_hr_acceptance_restore.php`, redact secrets, require `_test` plus the explicit marker, and never create an HTTP endpoint per FR-142, FR-144..FR-150, SC-019, and SC-021..SC-024 (partial, HIGH).
