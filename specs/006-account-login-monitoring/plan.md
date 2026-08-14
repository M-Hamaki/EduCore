# Implementation Plan: مراقبة الدخول وأمان الحسابات

**Branch**: `main` حالياً؛ لا يُنشأ فرع حتى حسم العمل القائم | **Date**: 2026-08-14 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/006-account-login-monitoring/spec.md`

**Planning state**: حزمة تخطيط فقط. لم يُعدّل `.specify/feature.json` لأنه يشير إلى `004-integrated-staff-affairs` ويحتوي تغييراً قائماً، ولم تُنفذ migration أو backfill أو كتابة بيانات.

## Summary

تضيف الميزة طبقة مصادقة تشغيلية موحدة داخل وحدة Accounts القائمة، وتحوّل كل مسارات الدخول الحالية إلى adapters تستدعي خدمة واحدة بعد إثبات الاعتماد والسياسة. تحفظ الخدمة نتيجة نهائية معيارية من خلال بنية التدقيق المشتركة، وتحدث إسقاطاً سريعاً لآخر الدخول، وتدير throttle والجلسات المسجلة دون تخزين أسرار. تقرأ صفحتا حسابات الطلاب والعاملين الإسقاط السريع بدلاً من تجميع `activity_logs`، ثم تضيفان عموداً وفلاتر وسجل تفاصيل محدود الصلاحية. يتم الانتقال على مراحل خلف أعلام مستقلة، مع backfill محافظ، ومخطط additive، وعدم حذف كلمة المرور القديمة حتى اكتمال التحويل وإثبات الرجوع.

## Scope Boundaries *(mandatory)*

- **In scope**: مسارات الدخول اليدوي وMicrosoft/Teams الحالية، `logout.php`, اختيار الدور، انتهاء الجلسة، حسابات الطلاب والعاملين في `users`, صفحتا إدارة الحسابات، event/state/session stores، throttle، الاعتماد المؤقت، التنبيهات الداخلية، الاحتفاظ، backfill، الاختبارات والتشغيل المرحلي.
- **Out of scope**: إطار مصادقة جديد، MFA/passkeys، geolocation، بريد/SMS جديد، إعادة تصميم البوابة، تغيير أهلية الطالب/العامل، دمج `external_teachers`، حذف عمود legacy في أول إصدار، أو نشر/تشغيل على الإنتاج.
- **Compatibility baseline**: كل URL وPOST/GET field وCSRF/session key ودور ورسالة منع ووجهة لوحة وعقد DataTables حالي يبقى متوافقاً؛ ADR-075/076/077 تظل سارية.
- **Authorized side effects**: إنشاء وثائق التخطيط فقط في هذه المرحلة. التنفيذ اللاحق يضيف migrations وأحداثاً وحالة وجلسات مدققة؛ deploy/backfill/push غير مصرح بها ضمن هذه الخطة.

## Technical Context

**Language/Version**: PHP >= 8.0 من `composer.json`، مع `password_hash/password_verify` وخيار Argon2id فقط إذا أثبت runtime دعمه؛ bcrypt يبقى fallback توافقي.

**Primary Dependencies**: PDO، `AuditService`/`ActivityLog`/`AuditPolicyRegistry` القائمة، `PasswordAuthenticator`, `MicrosoftSSO`, `StaffActiveRoleService`, `StudentLoginAccessPolicy`, جلسات PHP، Bootstrap 5 RTL، DataTables server-side، ومالك الإشعارات الموجود.

**Storage**: MariaDB/MySQL عبر PDO؛ جداول additive للأحداث والحالة والجلسات وتشغيل الاحتفاظ، وحقول اعتماد additive على `users` أو جدول حالة اعتماد مملوك لـAccounts حسب بحث migration النهائي. لا runtime DDL.

**Testing**: PHP lint، contract/unit scripts تحت `tests/`، integration محروس حصراً بقاعدة تنتهي `_test`، browser acceptance مع هويات تجريبية، performance fixture اصطناعي، `composer audit-write-coverage`, `composer architecture-audit`, و`composer quality`.

**Target Platform**: Apache/XAMPP-compatible deployment الحالي. طوبولوجيا الإنتاج وعدد خوادم الويب ومخزن جلسات PHP `Not confirmed yet` وتمنع تفعيل session registry قبل إثباتها.

**Project Type**: modular monolith PHP server-rendered مع role/auth entrypoints متوافقة.

**Performance Goals**:

- تحميل قائمة 10,000 حساب خلال ثانيتين في القبول، وألا تزيد كلفة الاستعلام أكثر من 15% عن baseline.
- إدراج نتيجة المصادقة وتحديث الإسقاط ضمن ميزانية لا تضيف أكثر من 100ms p95 على دخول محلي في بيئة القبول.
- عدم كتابة heartbeat أكثر من مرة كل خمس دقائق لكل جلسة افتراضياً.
- اختبار مليون حدث مصادقة اصطناعي مع فهارس القائمة والتاريخ والاحتفاظ.

**Constraints**:

- لا أسرار أو قيم اعتماد أو session ID خام في أي جدول/سجل/استجابة.
- نجاح الدخول fail-closed عند فشل الأدلة الإلزامية، مع رسالة عامة ومسح جلسة جزئية.
- throttle متدرج بلا قفل دائم آلي؛ العتبات من البيئة ومسجلة في `.env.example` بلا قيمة سرية.
- تخزين الوقت UTC وعرضه بتوقيت المدرسة.
- لا direct SQL من صفحات الإدارة إلى الموارد الجديدة؛ القراءة والكتابة عبر عقود Accounts/Audit.

**Scale/Scope**: 10,000 مستخدم عامل/طالب، حتى مليون حدث محتفظ به، عدة جلسات لكل مستخدم، ومسارات الدخول الحالية فقط. الحجم الإنتاجي الحقيقي `Not confirmed yet` ويُقاس قبل اختيار batch sizes النهائية.

## Constitution Check

*GATE قبل Phase 0: ناجح.*

- [x] **Canonical context**: قُرئ `AGENTS.md`، وثائق architecture/database/memory/coding، الملفات الفعلية ومسارات الدخول؛ المجهول التشغيلي معلّم.
- [x] **Compatibility**: URLs والحقول والجلسات والأدوار والسياسات وDataTables مذكورة مع استراتيجية حفظ.
- [x] **Architecture**: وحدة Accounts وخدمة Audit القائمتان هما المالكان؛ لا framework أو router أو logger موازٍ.
- [x] **Security/data**: الأسرار، CSRF، التفويض، schema، المعاملة، throttle، الاحتفاظ، production-data والخصوصية محددة.
- [x] **Testing/rollback**: characterization، قواعد `_test`، المراحل، الأعلام والرجوع additive موثقة.
- [x] **Governance**: ADR جديد وتحديث memory/architecture مخططان، وكل بوابات الجودة مطلوبة بلا baseline expansion.

## Project Structure

### Documentation (this feature)

```text
specs/006-account-login-monitoring/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
└── contracts/authentication-observability.md
```

### Source Code (planned)

```text
login.php                                      # compatible manual POST adapter
logout.php                                     # compatible logout adapter
select_role.php                                # role-selection event adapter
auth/microsoft_callback.php                    # interactive SSO adapter
auth/teams_sso.php                             # Teams web flow adapter
auth/teams_token_handler.php                   # silent Teams token adapter

src/Modules/Accounts/Authentication/
├── AuthenticationOutcomeService.php           # orchestration and final outcome
├── AuthenticationOutcome.php                  # validated outcome value object
├── AuthenticationMethod.php                   # canonical methods
├── AuthenticationReason.php                   # canonical non-secret reason codes
├── LoginThrottlePolicy.php                    # pure rate policy
├── AuthenticationStateRepository.php          # owned persistence contract
├── AuthenticationHistoryQuery.php             # safe detailed read contract
├── AuthenticationSessionService.php           # start/heartbeat/end/revoke
├── AuthenticationSessionRepository.php        # session registry contract
└── Pdo*/                                      # PDO adapters under the same owned boundary

src/Modules/Operations/Audit/
├── AuditService.php                            # shared recordAuthenticationOutcome entry
└── AuditPolicyRegistry.php                     # redaction/retention/undo policies

classes/
├── user.php                                    # legacy password adapter delegates outcome
├── MicrosoftSSO.php                            # SSO adapter delegates outcome
├── PasswordAuthenticator.php                  # existing verification and hash upgrade
└── AccountListDataTableQuery.php               # LEFT JOIN fast auth state projection

admin/
├── student_accounts.php                        # column/filter/modal shell
├── staff_accounts.php                          # column/filter/modal shell
├── ajax_student_accounts_datatable.php         # existing list contract preserved
├── ajax_staff_accounts_datatable.php           # existing list contract preserved
├── ajax_account_auth_history.php               # sensitive read POST + CSRF
└── ajax_account_session_action.php              # revoke POST + CSRF + audit

includes/
├── session_config.php                          # timeout remains compatible
└── ...                                         # registry checks wired only through shared helper

database/migrations/
├── 20260814_authentication_event_state.php
├── 20260814_authentication_session_registry.php
└── 20260814_credential_lifecycle.php

tools/
├── backfill_authentication_state.php            # dry-run/apply guarded CLI
└── retain_authentication_events.php             # dry-run/apply guarded CLI

tests/
├── authentication_outcome_contract_test.php
├── authentication_route_coverage_test.php
├── authentication_throttle_policy_test.php
├── authentication_state_integration_test.php
├── authentication_session_integration_test.php
├── authentication_backfill_integration_test.php
├── authentication_retention_integration_test.php
├── account_last_login_datatable_contract_test.php
├── credential_lifecycle_contract_test.php
└── browser/authentication_monitoring_runner.js

docs/
├── architecture-decisions.md                    # new ADR required
├── architecture.md                              # Accounts/Audit boundary update
├── database.md                                  # new tables and ownership
└── project-memory.md                            # confirmed rollout state/unknowns
```

**Structure Decision**: تبقى HTTP entrypoints الحالية adapters متوافقة. تملك `src/Modules/Accounts/Authentication` سياسة المصادقة وحالة المستخدم والجلسات، بينما تكون كتابة الأدلة عبر method جديد في `AuditService` وتخضع `authentication_events` لـ`AuditPolicyRegistry`. لا تكتب صفحات الإدارة مباشرة، ولا تصبح `activity_logs` مصدر قائمة ثقيل. أي high-volume heartbeat يُصنف كإسقاط تشغيلي مشتق، بينما create/end/revoke/failure/success أحداث أمنية مسجلة؛ التصنيف يحتاج اختبار تغطية وADR ولا يضاف إلى baseline كمهرب.

## Design Decisions

### 1. مفردات النتيجة الموحدة

كل adapter يمرر مدخلاً إلى خدمة النتيجة مرة واحدة بعد الوصول إلى نتيجة نهائية:

- `event_name`: `login_attempt`, `session_started`, `role_selected`, `logout`, `session_expired`, `session_revoked`, `credential_changed`.
- `outcome`: `success`, `failure`, `denied`, `error`.
- `method`: `password`, `microsoft_interactive`, `microsoft_silent`, `teams_silent`.
- `reason_code`: قائمة مغلقة مثل `invalid_credentials`, `inactive`, `graduated`, `transferred`, `identity_mismatch`, `stage_missing`, `throttled`, `session_init_failed`.

لا تُحفظ الرسالة العربية كحقيقة قرار؛ الواجهة تحوّل reason code إلى نص متوافق. `request_id` يمنع التكرار داخل الطلب، ومفتاح idempotency يحمي callbacks المعاد إرسالها.

### 2. ملكية التدقيق وعدم إنشاء logger موازٍ

- لا يسمح لأي entrypoint بعمل `INSERT` مباشر.
- `AuthenticationOutcomeService` يستدعي `AuditService::recordAuthenticationOutcome()` داخل معاملة تكتب الحدث الأمني وتحدث الإسقاط.
- النتيجة المعروفة المستخدم تنتج صفاً عاماً واحداً فقط في `activity_logs` بالـ`request_id` نفسه لتظل صفحة التدقيق الشاملة مفيدة.
- فشل اسم غير موجود لا ينشئ مستخدماً وهمياً في `activity_logs`; يسجل في مخزن الأمن عبر البنية المشتركة ببصمة keyed غير قابلة للبحث خارج سياسة throttle.
- `authentication_events` مورد non-undoable، محدود الاحتفاظ، محجوب الأسرار ومسجل في policy registry. عمليات الإلغاء والإعداد والاحتفاظ نفسها تُدقق كأفعال إدارية.

### 3. ترتيب نجاح الدخول والفشل المغلق

1. التحقق من CSRF/الطلب.
2. حل الحساب والتحقق من الاعتماد أو هوية Microsoft.
3. تطبيق سياسة الأهلية وthrottle.
4. بدء معاملة قصيرة: تسجيل outcome + تحديث user state + إنشاء session-registry intent عند النجاح.
5. commit.
6. تجديد session ID وتعبئة مفاتيح الجلسة الحالية.
7. عند فشل الخطوة 6: مسح الجلسة، إنهاء registry intent، وتسجيل `session_init_failed`; لا يبقى نجاح قابل للاستخدام.

تحتاج هذه السلسلة characterization خاصاً لمسار Microsoft link لأن ADR-076 يمنع جلسة جزئية عند فشل الربط أو التدقيق.

### 4. سياسة throttle الافتراضية

- نافذة حساب: 15 دقيقة.
- قبل 5 إخفاقات: لا تأخير إضافي.
- من الإخفاق الخامس: backoff يبدأ 30 ثانية ويتضاعف حتى 15 دقيقة.
- لا قفل دائم تلقائي؛ عند 20 إخفاقاً في النافذة ينشأ تنبيه ويستمر الحد الأقصى المؤقت.
- عداد الحساب يرتبط بالحساب عند حله؛ عداد المصدر يرتبط بعنوان مصدر موثوق وبصمة معرف غير موجود.
- نجاح الاعتماد يعيد العداد المتصل به. فشل SSO قبل إثبات حساب لا يزيد عداد حساب محلي.
- مصدر IP لا يُقبل من `X-Forwarded-For` إلا بعد قائمة proxy موثوقة صريحة؛ وإلا يستخدم عنوان الاتصال المباشر.

القيم أعلام بيئية وليست hard-coded؛ لا تُفعّل enforcement أولاً، بل observe-only ثم enforce بعد قياس false positives.

### 5. معنى «آخر دخول» و«متصل الآن»

- `last_success_at` يعني اكتمال مصادقة وجلسة قابلة للاستخدام، لا مجرد صحة كلمة المرور.
- `previous_success_at` يحفظ النجاح السابق لإشعارات الأمان المستقبلية.
- `last_seen_at` تقديري ويحدث بنبضة محدودة؛ لا يُعرض كخروج مؤكد.
- «متصل الآن» يعني جلسة غير منتهية ونبضة ضمن نافذة قصيرة، مع وسم «تقريبي».
- اختيار الدور حدث منفصل ولا يغير وقت آخر دخول للحساب.

### 6. التاريخ القديم

- migration تهيئ كل مستخدم موجود بحالة `unknown_historical` وتاريخ بدء تتبع.
- backfill يقرأ فقط actions المعروفة (`login`, `microsoft_login`, `microsoft_sso_login`) ويستخرج `MAX(created_at)` للمستخدم.
- لا يستنتج عدد محاولات أو previous login من تقارب زمني عندما يغيب `request_id`.
- وجود نجاح موثوق يحول الحالة إلى `observed`; غيابه يبقي `unknown_historical`.
- المستخدم المنشأ بعد cutover يبدأ `never` ثم يتحول `observed` عند أول نجاح.
- كل تشغيل يحمل batch id/checkpoint/checksum ويكون idempotent؛ production apply يحتاج authority منفصلة.

### 7. الاعتمادات وكلمات المرور

- لا تغيير دوري اعتباطي؛ `must_change_password` للاعتماد المؤقت أو compromise فقط.
- كل password reset جديد يولد قيمة مؤقتة، يخزن hash فقط، ويعرضها مرة واحدة في الاستجابة المصرح بها.
- نجاح legacy login يستكمل hash upgrade الموجود، لكن مسح القيمة القابلة للكشف لا يتم حتى نجاح تحقق hash البديل داخل معاملة.
- تبقى عمليات reveal/export خلف علم مستقل في مرحلة الانتقال، مع تقرير نسبة الحسابات legacy.
- عند وصول النسبة إلى صفر وإثبات rollback، يعطل العلم وتزال الأزرار/التصدير بعقد UI؛ حذف العمود القديم قرار وم migration لاحقان خارج أول rollout.

### 8. الخصوصية والاحتفاظ

- IP يخزن بصيغة normalized، ويعرض مقنعاً. User-Agent الخام لا يخزن؛ يخزن hash وملخص عائلة متصفح/نوع جهاز محدود.
- بصمة username غير الموجود تكون HMAC بمفتاح بيئي مخصص أو خدمة تشفير المشروع، ولا تحفظ القيمة الأصلية.
- التفاصيل الشبكية 30 يوماً، الأحداث 180 يوماً، ثم حذف دفعي. state summary يبقى بلا IP/جهاز.
- أداة الاحتفاظ CLI فقط، dry-run أولاً، وتحذف بحد batch وتكتب حصيلة لا snapshots حساسة.
- لا geolocation ولا تصدير سجل حساس في هذا النطاق.

### 9. الصلاحيات

- رؤية عمود آخر دخول تتبع الوصول الحالي لكل صفحة.
- التاريخ المختصر دون IP الكامل متاح لمدير الصفحة إن اعتمد UI.
- التاريخ الحساس، الجلسات، والإلغاء لـ`super_admin` افتراضياً، مع تحقق خادمي ثابت؛ لا role من POST.
- لا يستطيع المدير إلغاء جلسة ليست ضمن حساب `users` مستهدف، ولا self-revoke الحالي إلا عبر تدفق تأكيد يضمن استمرار جلسة إدارية واحدة أو تسجيل الخروج المقصود.
- تعطيل/إعادة تعيين الحساب يفوض إلى مالك الكتابة الحالي، الذي يستدعي عقد session revocation داخل نفس orchestration.

## Delivery Phases

### Phase 0 — Baseline and characterization

**Goal**: تثبيت السلوك الحالي قبل أي استخراج.

- حصر كل مسارات النجاح/الفشل والـcallbacks واختيار الدور والخروج والtimeout.
- إضافة contract يثبت التكرار الحالي وأسماء الأحداث كدليل تحول، لا كقيمة مستهدفة.
- قياس query baseline لقائمتي الحسابات، وحجم `activity_logs` في بيئة آمنة read-only.
- إثبات طوبولوجيا الإنتاج ومخزن جلسات PHP وproxy headers.
- حسم تداخلات الملفات المعدلة حالياً؛ يمنع البدء إذا لم يُعرف مالك كل hunk.

**Exit gate**: characterization أخضر، test DB محروس، data-retention approver معروف، وملفات النطاق خالية من overlap غير محسوم.

### Phase 1 — Schema and shared contracts, feature flags off

**Goal**: إضافة مخطط additive وعقود بلا تغيير سلوك مستخدم.

- migration event/state + policy registry + ADR.
- value objects/repository contracts وAuditService method.
- flags: `AUTH_OBSERVABILITY_ENABLED`, `AUTH_HISTORY_UI_ENABLED`, `AUTH_THROTTLE_ENFORCEMENT_ENABLED`, `AUTH_SESSION_REGISTRY_ENABLED`, `PASSWORD_LEGACY_REVEAL_ENABLED`.
- unit/integration للمخطط والمعاملة والإخفاء.

**Rollback**: إبقاء الجداول، تعطيل flags، إزالة wiring فقط.

### Phase 2 — Canonical outcomes in observe-only

**Goal**: توحيد كل مسارات الدخول وإزالة ازدواج activity log دون فرض throttle.

- توصيل manual/interactive/silent adapters بخدمة outcome.
- حدث واحد معروف المستخدم في activity log، والحدث الأمني التفصيلي في store.
- مقارنة shadow بين القديم والجديد لمدة مراجعة؛ لا يؤثر الاختلاف على السماح.
- بعد تطابق العقد، إزالة الاستدعاءات المكررة القديمة من adapters.

**Rollback**: flag يعيد logging adapter القديم؛ لا حذف أحداث جديدة.

### Phase 3 — State projection, conservative backfill, account UI

**Goal**: تقديم آخر الدخول والفلاتر بأداء ثابت.

- تهيئة `unknown_historical`, تشغيل backfill dry-run ثم test apply.
- join الإسقاط بالـPK في `AccountListDataTableQuery`.
- إضافة column, order map, presenter cells, filters, summary counts, column settings مع الحفاظ على IDs القائمة.
- Bootstrap history modal وendpoint قراءة؛ لا session actions بعد.
- performance fixture واختبارات DataTables/escaping/authorization.

**Rollback**: `AUTH_HISTORY_UI_ENABLED=0` يخفي UI؛ query يعود دون join، state يبقى.

### Phase 4 — Failure recording and throttle enforcement

**Goal**: منع التخمين مع generic errors وقياس false positives.

- تفعيل observe counters أولاً، ثم alert-only، ثم enforcement.
- per-account + per-source policies، trusted proxy resolution، reset after success.
- تغطية invalid username/password, inactive, terminal student, SSO mismatch, invalid tokens، وDB errors.
- لا CAPTCHA في أول إصدار؛ يظل خياراً لاحقاً إذا أثبتت القياسات الحاجة.

**Rollback**: enforcement flag off مع استمرار الرصد؛ لا مسح counters مطلوب.

### Phase 5 — Session registry and revocation

**Goal**: فصل last login عن active sessions وتمكين الإلغاء.

- migration session registry، HMAC session key، start/end/revoke، heartbeat throttled.
- تسجيل الجلسات القديمة opportunistically عند أول طلب بعد التفعيل بحالة `adopted`.
- ربط logout وidle timeout وaccount disable/password reset.
- UI session list/revoke لـsuper_admin، POST/CSRF/audit.
- اختبار multi-tab/multi-device/concurrency/proxy وsession-store topology.

**Rollback**: إيقاف registry checks والheartbeat؛ جلسات PHP الحالية تستمر حسب السلوك القديم، ولا تحذف registry evidence.

### Phase 6 — Credential hardening

**Goal**: منع إنشاء أسرار جديدة قابلة للاسترجاع وإنهاء reveal/export تدريجياً.

- migration lifecycle fields، reset flow مؤقت، first-login change، session revocation.
- hash algorithm capability test وترقية cost دون كسر PHP/XAMPP.
- report legacy coverage، تحويل on-login، bulk reset اختياري بتفويض صريح فقط.
- تعطيل reveal/export بعد صفر legacy وبوابة قبول مستقلة.

**Rollback**: إعادة flag legacy خلال نافذة التوافق ما دام العمود لم يحذف؛ لا downgrade للـhash.

### Phase 7 — Retention, alerts, operations

**Goal**: تشغيل مستدام ومراقب.

- CLI retention dry-run/apply، batch/checkpoint، تنقيح 30 يوم وحذف 180 يوم.
- deduplicated in-app alerts عبر notification contract.
- health metrics: event failures, duplicate requests, throttle, login latency, state lag, active sessions, retention backlog.
- runbook للنسخ الاحتياطي والاستعادة والflags والحوادث.

**Rollback**: إيقاف scheduler/alerts مع بقاء الأحداث؛ لا إعادة بيانات حُذفت بانتهاء الاحتفاظ إلا من backup المعتمد.

### Phase 8 — Production rollout and cleanup

**Goal**: نشر تدريجي بلا انقطاع.

1. backup + restore proof على قاعدة جديدة.
2. additive migrations فقط.
3. code with all new enforcement/UI flags off.
4. observe-only outcomes and metrics.
5. backfill dry-run/report ثم apply بسلطة منفصلة.
6. history UI.
7. throttle observe ثم enforce.
8. session registry.
9. credential hardening and legacy retirement.
10. إزالة compatibility logging القديم بعد دورة مستقرة وADR update.

## Verification Matrix

| Area | Required evidence |
|---|---|
| Manual login | success, bad username/password, inactive staff, terminal/inactive student, missing stage, DB/audit failure |
| Microsoft/Teams | interactive success, silent success, identity mismatch, invalid/expired token, retry callback, link/audit/session failure |
| Deduplication | one final event per request/idempotency key; no duplicate activity row |
| Throttle | thresholds, exponential delay, account/source separation, success reset, concurrency, no permanent lock |
| State | `unknown_historical/never/observed`, last/previous success, method, monotonic timestamps |
| DataTables | query fields, order map, filter arrays, summary, XSS escaping, no N+1, performance fixture |
| History permissions | normal admin vs super_admin, POST CSRF, masked IP, generic errors |
| Sessions | start/adopt/heartbeat/end/revoke, multiple devices, timeout, disabled account, password reset |
| Credentials | temporary display once, hash only, first-login change, session invalidation, legacy flag/report |
| Backfill | dry-run, idempotency, resume, checksum, unknown history, no production guard bypass |
| Retention | dry-run, batch bounds, anonymize/delete age, audit summary, failure resume |
| Leakage | password/token/session/CSRF/error strings absent from DB rows, logs, JSON and HTML |
| Rollback | each flag off independently, old login still works, schema remains additive |

## Data Safety and Migration Rules

- لا integration write على `educore` أو production-like DB؛ كل fixture تحت اسم ينتهي `_test` وعلامة صريحة.
- schema migration لا تنفذ heavy backfill؛ backfill CLI منفصل، قابل للاستئناف وبـdry-run.
- migration تضيف فقط ولا تسقط أو تعيد تسمية أعمدة قائمة.
- كل resource جديد مسجل قبل أول write بسياسة undo (non-undoable للأدلة الأمنية)، retention، redaction، actor scope وconflict.
- نجاح login known-user يكتب outcome/state/session intent في معاملة واحدة حيث يمكن؛ فشل audit يمنع الجلسة.
- session revocation الناتج عن disable/reset جزء من transaction أو outbox/idempotent follow-up موثق إذا تعذر atomicity بين ملاك الموارد.
- لا تشغّل أداة retention قبل نسخة احتياطية وسياسة معتمدة؛ الحذف مقصود وغير قابل للتراجع من التطبيق.

## Rollback Strategy

- المستوى الأول: تعطيل flag المتأثر فقط.
- المستوى الثاني: إعادة adapters السابقة مع بقاء dual-written evidence للقراءة فقط.
- المستوى الثالث: rollback code release؛ تظل الجداول/الأعمدة additive ولا ينفذ DROP.
- throttle rollback لا يمسح events، ويوقف enforcement فوراً.
- session rollback يوقف checks/heartbeat ولا يلغي جلسات سليمة إضافية.
- credential rollback يعيد reveal مؤقتاً فقط إذا بقي ciphertext ولم يبدأ قرار الإزالة؛ لا يعيد كلمة مرور hash إلى نص قابل للكشف.
- production data restore آخر حل ويتطلب backup verified، سلطة صريحة، ونافذة توقف؛ ليس جزءاً من rollout العادي.

## Operational Metrics and Alerts

- login attempts/success/failure/denied/error by method and reason (بدون أسماء خام).
- `event_write_failure`, `state_projection_failure`, `duplicate_idempotency_key`.
- p50/p95 login latency قبل وبعد الخدمة.
- throttle activations وfalse-positive review count.
- accounts by history state and last-success age bucket، مع فصل test accounts.
- active/expired/revoked sessions وheartbeat write rate.
- legacy credential coverage، temporary password pending count.
- event rows/oldest row/retention backlog/last retention result.

## Stop Conditions

- أي overlap غير محسوم في `login.php`, `MicrosoftSSO.php`, `ActivityLog.php`, صفحتي الحسابات أو `AccountListDataTableQuery.php`.
- عدم وجود/إثبات قاعدة `_test` مستقلة أو محاولة test على `educore`.
- غياب قرار مالك الصلاحية لعرض IP والجهاز أو retention قبل الإنتاج.
- وجود multi-node deployment بلا session store مشترك أو آلية revocation قابلة للمشاركة.
- migration state غير معروف أو mismatch بين code/schema.
- عدم قدرة tests على محاكاة Microsoft/Teams دون أسرار حقيقية.
- فشل `composer audit-write-coverage` أو `composer architecture-audit` أو توسعة baseline لتجاوز finding.
- عدم وجود backup وrestore proof قبل production backfill/retention.

## Post-Design Constitution Check

- [x] التوافق محفوظ عبر adapters وأعلام مستقلة.
- [x] الملكية داخل Accounts/Audit موثقة ولا يوجد logger أو auth stack موازٍ.
- [x] schema additive وproduction writes محروسة.
- [x] الأسرار والخصوصية والاحتفاظ والتفويض والفشل المغلق محددة.
- [x] كل مرحلة قابلة للاختبار والرجوع منفردة.
- [x] ADR/docs/tests/audits مطلوبة قبل الإغلاق.

## Complexity Tracking

لا يوجد استثناء دستوري معتمد. مخزن الأحداث المتخصص مبرر بالحاجة إلى تسجيل محاولات غير معروفة المستخدم واحتفاظ مختلف عن `activity_logs`، لكنه يظل خلف `AuditService` و`AuditPolicyRegistry` ولا يُعامل logger موازياً. إذا تعذر إثبات هذا الحد باختبار وADR، يتوقف التنفيذ ولا يُضاف استثناء baseline.
