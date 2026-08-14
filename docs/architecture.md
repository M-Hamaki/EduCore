# EduCore Architecture

Purpose: provide a concise, evidence-based orientation for maintainers and AI coding assistants. Current code and executable configuration take precedence over historical documentation. Anything not verified is marked `Not confirmed yet`.

## Unified Public Access Boundary — 2026-08-11

- `index.php` is the single public access view and composes `includes/public_login_portal.php`; `login.php` remains the compatible manual-login POST owner, while legacy `stage` parameters no longer affect authentication.
- `src/Modules/PublicPortal` owns the intro-visit policy, the unified public view/query use cases, and the read-only adapter over the existing materials schema. There is no guest-service catalog, guest role, or guest settings repository.
- The login view exposes one anonymous materials action. `materials.php` preserves the intro-video contract and redirects to the existing `student/materials/` interface; `guest.php` is a redirect-only compatibility endpoint and creates no guest mode.
- Anonymous and student material reads reuse the same `materials.enabled` and `materials.downloadable` semantics and require active stage/grade rows. File paths are never emitted, and `material_download.php` revalidates the ID, basename, real path, and publication state on every request.
- Teams silent SSO starts once from `teams/app.html`, posts the token same-origin, has bounded initialization/token/network timeouts, and never auto-links. Acceptance requires the existing Microsoft ID plus the same normalized Microsoft email in both `users.email` and `users.username`.
- The removed `public_portal_services` setting is no longer read or written. Existing rows may remain inert; no schema or data migration is required.

## Student Affairs Data-Integrity Boundary — 2026-07-30

- `StudentAttendanceService` is the shared transaction owner for admin and teacher class-day attendance. It validates a strict in-year non-future date, locks the exact active roster, rejects partial or cross-class payloads, upserts without destructive replacement, and audits before commit.
- `StudentProfileCommandService::applyClassTransfer()` owns class transfers across the compatibility user class, profile grade, annual enrollment, assessment-mark class snapshots, and transfer history. Bulk class-list moves share one transaction and audit/undo batch.
- `StudentRelationshipService` owns all sibling and typed-kinship writes; entrypoints no longer mutate relationship tables directly.
- `StudentArchiveService` preserves archive-first behavior. Permanent purge uses explicit dependent-data policy plus fail-closed discovery of unclassified student references, so new official tables cannot be silently cascaded or orphaned.
- Student export/document surfaces reuse current-year and role class scope, exclude archived students, whitelist request-driven output options, and neutralize spreadsheet formula prefixes. Student export field definitions and formatting are centralized in `StudentExportFieldCatalog` and `StudentExportValueFormatter`; profile photos remain private and are rendered through the authorized attachment endpoint or embedded into generated Excel files.
- `admin/student_operations.php` is the dedicated Student Affairs audit surface. `StudentOperationLogQuery` scopes student-owned target types and Student Affairs routes while explicitly excluding student grades/mark recording, evaluations, and student account/status events; those events remain in the unscoped unified `admin/activity_logs.php` log. Both surfaces accept undo only for an exact linked activity/undo pair and delegate to shared `UndoManager`; unified cross-module undo is restricted to the active `super_admin`, while unavailable, completed, or unauthorized rows remain visible with disabled controls and an explicit reason.

## Student Completeness Read Boundary — 2026-07-29

- `src/Modules/Students/StudentCompletenessReadRepository.php` owns the selected-year student completeness dataset, annual-readiness policy, filtering, sorting, statistics, and year-scoped filter options.
- The selected year comes only from the shared admin header/session through `AcademicYear::getCurrent()`; the page has no independent year filter and its AJAX adapter does not accept a caller-supplied year override.
- Academic placement and both annual statuses come only from `student_enrollments` for the selected year. Permanent profile completeness is calculated separately from the configured profile/guardian/attachment fields.
- `admin/ajax_student_completeness.php` is the authenticated HTTP adapter and applies `ScopedStaffPortalContext`; `StudentCompletenessPresenter` owns DataTables HTML formatting.
- `StudentCompletenessConfigService` merges trusted field definitions with audited overrides stored in `settings`; web requests never rewrite the JSON definition file.

## Request Integrity Baseline — 2026-07-13

- Cookie-authenticated mutations use `requireCsrfPost()` or `requireCsrfToken()`.
- Anonymous activity and exam submissions receive a session token from their public entrypoint and return it with JSON writes.
- Read-only POST compatibility flows and the verified Microsoft bearer-token exchange are governed by the expiring manifest at `tools/architecture_csrf_exemptions.json`.
- Strict audit rejects new unreviewed candidates and invalid, stale, or expired manifest entries.
- The former Teams Context login is retired; only a cryptographically verified Microsoft token can establish an SSO session.

## Profile Attachment Boundary

- New student/staff attachments are stored below `storage/private/profile_attachments/` and identified by a `private:` storage name in the existing attachment row.
- Admin pages use `admin/profile_attachment.php` for authorized inline viewing or download; filenames never select a table or filesystem path directly.
- `ProfileAttachmentStorage` provides legacy dual-read so existing rows remain usable while files are copied and checksummed incrementally.
- `tools/migrate_profile_attachments.php` is dry-run by default and refuses writes without a private snapshot and matching database confirmation. It retains legacy sources and writes a private migration manifest; `tools/rollback_profile_attachment_migration.php` verifies that manifest, the private checksum, and the legacy source before any conditional restore.
- After the local three-file migration passed checksum and rollback dry-run, direct HTTP access to both legacy attachment directories was denied. Files remain available only through the authenticated controller.

## Source Of Truth And Runtime

- `AGENTS.md` is the mandatory project-instruction source.
- `composer.json` is the executable source for the PHP requirement: **PHP >= 8.0**.
- The application is a single PHP deployment backed by MySQL/MariaDB through PDO.
- The confirmed local development shape is XAMPP with the repository under the web root. Production topology is `Not confirmed yet`.
- No application framework, central router, ORM, dependency-injection container, or complete MVC layer is confirmed.
- `docs/architecture-audit/` contains the detailed evidence, risks, decisions, and phased roadmap behind this summary.

## Current Architecture

EduCore is currently a deployable monolith organized mainly by user role and feature. Internally it combines:

- **Page Controller:** most PHP files are directly addressable entrypoints that read the request and render the response.
- **Transaction Script:** POST handling, validation, SQL, audit, and redirects often live in the same file.
- **Emerging Service Layer:** focused classes such as `AssessmentEngine`, `StaffAttendanceService`, and `StaffLeaveService` hold reusable workflows.
- **Gateway/Active-Record-like classes:** classes such as `User` and `ClassRoom` hold state and execute PDO queries.
- **Server-rendered presentation:** PHP templates, role headers/footers, Bootstrap RTL, jQuery, and DataTables.

This is not yet a clean modular architecture: SQL and business rules remain distributed across entrypoints and the shared `classes/` directory, and authorization, CSRF, errors, and transactions are not uniformly enforced.

## Current Module Map

| Area | Representative entrypoints/components | Notes |
|---|---|---|
| Identity and access | `login.php`, `logout.php`, `auth/*`, `classes/user.php`, `classes/utilities.php` | Session roles, custom admin-page access, supervisor modes, and SSO |
| Academic structure | `admin/academic_years.php`, `stages.php`, `grades.php`, `classes.php`, `subjects.php`, `src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php` | Years, stages, grades, classes, subjects, and the central direct/inherited experimental-scope policy |
| Students and enrollment | `admin/students.php`, `student_accounts.php`, `graduate_students.php`, `src/Modules/Students`, compatibility aliases in `classes/Student*.php` | First PSR-4 module; profiles, enrollment, transfer, graduation, siblings, and attachments |
| Staff and HR | `admin/staff.php`, `src/Modules/Staff`, compatibility aliases in `classes/Staff*.php`, staff accounts/attendance/leave/training pages | PSR-4 owns the migrated profile workflow only; lifecycle, accounts, attendance, leave, and finance retain separate legacy owners |
| Assessment and reporting | `classes/AssessmentEngine.php`, `admin/assessment_*.php`, `teacher/assessment_*.php` | Schemes, windows, marks, review, publication, and permissions |
| Behavior evaluation | `admin/evaluation_*.php`, `teacher/evaluations.php`, `src/Modules/BehaviorEvaluation` | PSR-4 owns evaluation models and AJAX actions; legacy class names and dispatcher path remain compatible |
| Attendance | admin/teacher attendance pages, `StaffAttendanceService`, `ZKTecoDevice` | Student and staff attendance plus biometric integration |
| Finance | `src/Modules/Finance`, `admin/finance_*.php`, legacy finance entrypoints | Isolated modular boundary with generic student/staff sub-ledger, GL, payroll, budgets, approvals, reports, and compatibility adapters |
| Transport | buses, student assignments, and bus staff pages | Direct page/SQL workflows remain common |
| Clinic and library | `admin/student_clinic.php`, `admin/library.php` | Ownership boundaries are only partially confirmed |
| Learning content and AI | `teacher/lesson_prep.php`, teacher AJAX generators, materials pages | AI providers, lessons, exams, templates, and Canva integration |
| Notifications | `admin/notifications.php`, `api/push_*.php`, `PushNotification` | In-app and web-push workflows |
| Global admin search | `assets/js/admin-global-search.js`, `includes/ajax_handlers.php?action=global_deep_search`, `src/Modules/Search` | Read-only search projection; result groups follow active role page grants and academic class scope |
| Operations and audit | backups, `api/undo.php`, `UndoManager`, `ActivityLog`, migrations, tools | Operational commands and multiple audit mechanisms |

This map describes functional areas, not exclusive ownership of every table. Complete table ownership is `Not confirmed yet`.

### Finance module boundary

- `src/Modules/Finance/Domain` owns money values, authorization, and financial policies without PDO or HTTP dependencies.
- `src/Modules/Finance/Application` owns atomic use cases, maker-checker approval, period guards, audit orchestration, and transaction boundaries through contracts.
- `src/Modules/Finance/Infrastructure/Pdo` implements repository/query contracts; finance admin entrypoints do not own SQL.
- The generic party sub-ledger (`finance_subledger_accounts`, `finance_subledger_transactions`, `finance_subledger_lines`) is the balance truth source for students and staff; posted party movements link one-to-one with GL journals.
- New finance pages live at their stable `admin/finance_*.php` URLs. Large ledgers, receipts, audit, payroll, vouchers, and journal use the authenticated `admin/finance_datatable.php` server-side paging endpoint.
- Rollout remains gated by `FINANCE_LEDGER_MODE=off|shadow|display|execute`; legacy tables are retained and production enablement requires reconciliation on an isolated clone first.

## Request And Response Flow

A typical protected HTML request currently:

1. Includes database, session, utility, and feature files.
2. Calls `Utilities::validateSession(<role>)`; older routes may rely on a header include or a different check.
3. Creates a `Database` instance and obtains PDO with `getConnection()`.
4. Reads data or processes POST, ideally with server-side authorization, CSRF validation, validation, and a transaction decision.
5. Uses Post/Redirect/Get and session flash messages for a state-changing HTML workflow when the page has been modernized.
6. Renders through a role header/footer or returns a standalone response.

JSON endpoints exist under `api/`, `ajax/`, `admin/ajax/`, `teacher/ajax/`, and in the intentionally callable `includes/ajax_handlers.php`. Their authentication, status codes, and error shapes are not yet governed by one middleware or response contract.

## Authentication, Authorization, Session, And CSRF

### Authentication reality

- Traditional login enters through `login.php` and delegates credential lookup to `classes/user.php`.
- `User::login()` وعمليات التحقق الإدارية المحمية تفوض إلى `PasswordAuthenticator`: إذا وجد `password_hash` يصبح المصدر الملزم ولا يحدث fallback إلى البيانات القابلة للعكس.
- الحساب القديم بلا hash يقبل مؤقتًا فقط عندما يكون `PASSWORD_LEGACY_LOGIN_ENABLED=true`، وبعد تحقق ناجح يكتب hash فورًا. يمكن إغلاق النافذة بالمتغير نفسه بعد قياس اكتمال الترحيل.
- إنشاء الحساب أو إعادة تعيين كلمة مروره يكتب hash مع الغلاف القابل للعكس الحالي الذي ما زالت تعتمد عليه سياسة reveal الإدارية. إزالة reveal/التشفير القابل للعكس قرار مستقل يحتاج موافقة تشغيلية.
- Microsoft/Teams SSO uses `auth/*` and `classes/MicrosoftSSO.php`; it must ultimately produce the same authenticated-user context as traditional login.

### Session and authorization

- `includes/session_config.php` creates the session and CSRF token, applies idle timeouts and periodic session-ID regeneration, and configures an `HttpOnly` cookie.
- The cookie is `Secure` when the request is HTTPS. `SameSite` is environment-configurable and defaults to `Lax`.
- Confirmed session keys include `user_id`, `name`, `role`, `is_supervisor`, `class_id`, and `csrf_token`.
- قرار الدور العام وأوضاع المشرف والوصول لصفحات الإدارة يمر عبر `AuthorizationFacade` النقي مع `Utilities` كمهايئ للجلسة/إعادة التوجيه و`staff_role_pages`. تبقى صلاحيات Assessment المتخصصة contract مستقلًا، وبعض role checks الخاصة بنقاط الدخول legacy ما زالت تنتقل تدريجيًا.
- يملك `AdminRolePageCatalog` عقد الأدوار الإدارية المحددة حسب القسم وصفحات هبوطها واعتماديات صفحاتها. تستخدم أدوار الطبيب وأمين المكتبة وشؤون الطلاب والحركة والصلاحيات `admin/role_dashboard.php` كلوحة ترحيب إلزامية مشتركة تتشكل حسب عائلة الدور وصفحاته الفعلية، بينما يحتفظ الأخصائي بلوحته الأكاديمية المتخصصة. تخزن `staff_role_pages` الصفحات التفاعلية المرئية، وتُشتق نقاط AJAX وصفحات الهبوط الإلزامية عند القراءة حتى لا تظهر كصلاحيات مستقلة أو تُفقد عند تعديل الدور.
- يسمح محرر الأدوار للمدير الأعلى بتخصيص الصفحات واستنساخ الأدوار الإدارية الستة المراجعة فقط. تحفظ النسخة `base_role_key`، ويحل `StaffRoleCapabilityResolver` عائلتها حتى تبقى نسخ الأخصائي والطبيب وأمين المكتبة خاضعة للنطاق الأكاديمي السنوي نفسه.
- يفصل `SystemAdministratorRoleService` انتقالات `admin`/`super_admin` عن تعيين الأدوار العادي: الفاعل يجب أن يكون مديراً أعلى نشطاً، ويجوز له تعديل أدواره الثانوية فقط مع تثبيت `super_admin` عضويةً ودوراً أساسياً، بينما يظل تغيير حالته ذاتياً وإزالة آخر مدير أعلى نشط ممنوعين. يبقى `AuthorizationFacade` مرجع وراثة `super_admin` لصلاحيات `admin`، بينما الصفحات الحرجة تطلب `super_admin` صراحةً.
- عضويات العاملين متعددة الأدوار مملوكة لـ `StaffRoleAssignmentService` في `user_role_assignments`. يبقى `users.role` مرآة للدور الأساسي فقط، ولا يستخدم كاتحاد صلاحيات. بعد المصادقة يفرض `StaffActiveRoleService` دور جلسة واحدًا في `active_role`؛ الحساب متعدد الأدوار يمر عبر `select_role.php`، وكل تبديل يتحقق من العضوية النشطة ويجدد معرّف الجلسة.
- صفحات الإدارة المشتركة تحسب عائلة الدور النشط عبر `StaffRoleCapabilityResolver`، بينما يحتفظ `ScopedStaffPortalContext::assignedRole()` بالمفتاح الفعلي لعزل نطاق الدور نفسه. صلاحيات `super_admin` الحرجة تتطلب أن يكون هو الدور النشط، لا مجرد عضوية إضافية للحساب.

### CSRF reality

- A central token and helpers exist, and many write endpoints validate them with `requireCsrfPost()` or `hash_equals()`.
- `assets/js/main.js` injects the token into supported jQuery/fetch requests.
- Coverage is not proven for every state-changing route. The architecture audit reports candidates for manual review; a candidate is not automatically a vulnerability, and absence from that list is not a security proof.
- The target invariant is server-side CSRF validation for every POST/PUT/PATCH/DELETE and any legacy GET that still changes state; state-changing GET routes should be removed.

## Assessment And Published Reports

The flexible grading/reporting workflow is the clearest current example of an emerging service boundary:

- Core logic: `classes/AssessmentEngine.php`.
- Admin configuration:
  - `admin/assessment_calendar.php`
  - `admin/assessment_subject_assignments.php`
  - `admin/assessment_teacher_assignments.php`
  - `admin/assessment_schemes.php`
  - `admin/assessment_components.php`
  - `admin/assessment_component_week_rules.php`
  - `admin/assessment_windows.php`
  - `admin/assessment_reports.php`
  - `admin/assessment_permissions.php`
  - `admin/assessment_student_locks.php`
  - `admin/assessment_setup.php`, now an archive/directory page rather than the old monolithic write UI.
- Teacher workflows:
  - `teacher/assessment_marks.php` for scoped mark entry, bulk fill, grading absence statuses, and CSV import/export.
  - `teacher/assessment_review.php` for review/approval/rejection where required.
  - `teacher/assessment_reports.php` for publication through available report windows.
- Student output: `student/reports/published_reports.php`.
- Admin viewer: `admin/view_student_report.php`, reading published report tables.
- Legacy report/month-selection student pages redirect to `published_reports.php`.

The mark-write workflow checks assignment/window/student scope, validates marks through `AssessmentEngine`, respects locks, and uses a transaction for the batch. Grading absence statuses are assessment data and are not connected to the separate attendance workflow.

## Database And Schema Changes

- `config/database.php` is the shared connection entrypoint and loads environment settings through `config/env_loader.php`.
- PDO prepared statements are the required access pattern; SQL currently exists both in role entrypoints and in classes/services.
- `database_complete.sql` is the documented bootstrap schema, while timestamped changes belong in `database/migrations/`.
- `tools/run_migrations.php` has a CLI-only guard before loading database configuration.
- Runtime DDL still exists in active application files and is tracked as architecture debt. The target rule is: migrations/installer commands may change schema; web requests may inspect schema for compatibility but must not create or alter it.
- Schema changes require guarded migrations, explicit preconditions, repeatability where appropriate, and a rollback or restore plan.
- Tests that write to a database must target an explicit test database and refuse `educore`/production. This repository audit does not authorize running DB-writing tests against live data.

Complete foreign-key relationships, migration history across all installations, and exclusive table ownership are `Not confirmed yet`.

## Error Handling And Logging

- Public HTML/JSON responses must use stable, user-safe messages; exception, SQL, path, credential, and stack details belong only in protected server logs. Production must keep `display_errors` disabled.
- `UndoManager::undo()` and the `api/undo.php` dispatch boundary now convert `Throwable` paths that reach their catches into the existing `success/message` JSON contract with a generic Arabic message; full exception details remain in `error_log()`. Normal business failures and success payloads retain their previous contracts.
- `config/database.php` يعيد `null` توافقياً عند فشل الاتصال ولا يطبع تفاصيل PDO أو ينهي الطلب. تمر الأخطاء عبر `SafeErrorPolicy` كسجل تشخيصي منظم ذي reference، مع حجب أنماط الأسرار؛ تختبر هذه السياسة مع تفعيل `display_errors` واتصال زائف.
- Diagnostic logging currently uses `error_log()`, while `ActivityLog`, `audit_logs`, and domain-specific audit tables record business/security events. These are not yet one logging subsystem and must not be collapsed without retention, PII, actor, and transaction requirements.
- The target policy distinguishes diagnostic errors, security events, and immutable business audit events; it forbids secrets and unnecessary PII in logs and adds correlation/request IDs only through a documented shared contract.

## Web And Internal Boundaries

### Callable web surface

The current application is served from the repository tree. Expected web-facing areas include root entrypoints and role folders such as `admin/`, `teacher/`, `student/`, `specialist/`, `supervisor/`, and `external/`, plus `auth/`, `api/`, `ajax/`, `teams/`, and static `assets/`.

`includes/` is a mixed boundary: most files are shared includes, but `includes/ajax_handlers.php` is an active public endpoint with real callers. The whole directory must not be denied without first extracting or rerouting that endpoint.

`shared_lesson.php` and `shared_lesson_download.php` are intentional anonymous LearningContent entrypoints. Access is limited to completed lessons with an explicitly enabled 256-bit bearer link; revocation clears the active token. The public view is noindex/no-referrer/no-store and does not establish an authenticated session.

### Protected internal surface

`classes/`, `config/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, and `storage/` currently contain defensive `.htaccess` rules that deny direct access, disable indexes, and disable CGI execution.

These files are a reversible defense-in-depth control, not proof of production protection:

- Their effect depends on Apache and `AllowOverride`.
- Production web-server and vhost configuration are `Not confirmed yet`.
- Access to `vendor/` and bundled `phpMyAdmin` in production remains unresolved.
- Some student/staff attachments are still directly linked under public `uploads/`; sensitive content should move incrementally to `storage/private/` and be served by an authorized download endpoint.

The longer-term option of a dedicated `public/` document root is a deferred architectural decision, not an implemented fact.

## Frontend Boundaries

- Pages are server-rendered PHP using Bootstrap 5 RTL, Font Awesome, jQuery, and DataTables.
- Shared behavior lives primarily in `assets/js/main.js` and `assets/js/admin_table_actions.js`.
- Shared admin visual ownership is defined in `assets/css/style.css`, `premium-dashboard.css`, `buttons.css`, and `admin-unified.css` as specified by `AGENTS.md`.
- Large inline JavaScript/CSS and presentation mixed with SQL remain migration debt. Extraction must preserve existing DOM IDs, form names, actions, and response behavior before visual or structural cleanup.

## Target: Pragmatic Modular Monolith

The approved direction is a **Pragmatic Modular Monolith** on the existing PHP/PDO stack:

- Keep current URLs as thin compatibility adapters during migration.
- Do not introduce a framework, ORM, microservices, or a second parallel bootstrap/auth system merely to obtain layering.
- Extract one characterized use case at a time using a strangler approach.
- Composer PSR-4 is active for `EduCore\\` under `src/`; `Students` is the first migrated module. Keep the legacy `classes/` classmap and compatibility aliases until consumers are migrated.
- Preserve URL, field, session, JSON, and schema contracts unless a documented migration explicitly changes them.

### Target layers

| Layer | Responsibility | Must not own |
|---|---|---|
| Entrypoint / Presentation | Request parsing, authentication/authorization/CSRF orchestration, DTO creation, service call, response/view | SQL, DDL, or substantial business algorithms |
| Application | Use-case workflow, transaction boundary, audit orchestration | Superglobals or HTML rendering |
| Domain | Policies, validators, value objects, and state transitions | PDO, HTTP, session, filesystem |
| Infrastructure | PDO repositories, storage, logging, encryption, external APIs | Business permission decisions |
| Views | Escaped rendering from prepared data/ViewModels | Queries or writes |
| Shared Kernel | Generic HTTP/auth context, transactions, errors, and validation primitives | Rules specific to students, marks, staff, or finance |

### Target module boundaries

- `IdentityAccess`
- `AcademicStructure`
- `Students`
- `StaffHr`
- `AssessmentReporting`
- `Attendance`
- `BehaviorEvaluation`
- `Finance`
- `Transport`
- `ClinicLibrary`
- `LearningContent`
- `Notifications`
- `OperationsAudit`

The names describe target ownership; they do not imply that matching `src/Modules/*` directories are all implemented today.

### Dependency direction

1. Role/root entrypoints may depend on shared contracts and a module's Application layer.
2. Application depends on Domain and repository/shared interfaces.
3. Infrastructure implements those interfaces and depends on PDO or external libraries.
4. Domain depends on neither Presentation nor Infrastructure.
5. Views consume prepared primitives or ViewModels and perform context-appropriate escaping at output.
6. Cross-module work MUST use an Application service or documented read-only contract. If none exists,
   define the smallest owned contract first; new code must not include another module's page or query its
   internal tables. A temporary exception requires the governed ADR process.

Current direct page-to-PDO and shared God-class access are migration debt, not patterns to copy.

## Architecture Audit Gate

`composer architecture-audit` runs `tools/audit_architecture.php --strict` and compares current findings with `tools/architecture_audit_baseline.json`. It currently tracks:

- PHP files over the configured large-file threshold.
- Active files containing runtime DDL.
- POST handlers that may lack an explicit server-side CSRF check.
- Required internal-directory web protections.

The baseline is a ratchet for known debt, not a permanent allow-list:

- Strict mode fails when a new file enters a debt category, a scanned PHP file becomes unreadable, or an
  internal-directory protection disappears, while keeping existing debt visible.
- The current comparison is path-level; it does not detect additional DDL/POST branches or line growth
  inside a file already present in the baseline. Those diffs still require manual review.
- Findings, especially CSRF candidates, require human review.
- Do not add a new violation to the baseline merely to make CI pass.
- Remove a baseline entry in the same change that removes the corresponding debt.
- Any deliberate baseline expansion needs a focused change, evidence, and a documented decision.

For PHP or web-boundary changes, the minimum architecture checks are:

```powershell
composer validate --no-interaction
composer lint
composer architecture-audit
composer documentation-audit
php tests/architecture_audit_test.php
php tests/internal_web_boundary_test.php
```

Admin UI changes additionally require:

```powershell
php tools/audit_admin_ui.php
```

Run relevant unit/characterization/HTTP tests for the affected workflow as well.

## Unknowns And Stop Conditions

The following remain `Not confirmed yet`:

- Production topology, document root, web-server rules, and `AllowOverride`.
- Complete endpoint/action and authorization matrix.
- Complete foreign-key graph and table ownership by module.
- All dynamic includes and runtime consumers of legacy/scratch files.
- Attachment volume, access patterns, retention, and encryption policy.
- Final rollout/cutover and administrator-reveal policy for a hash-first migration.
- Final authorization-service interfaces and detailed PSR-4 namespace layout.

Stop a refactor and request evidence or a decision when a business rule, permission scope, route consumer, production-data impact, rollback, or relevant test cannot be established safely.
