# Implementation Plan: منظومة شؤون العاملين والموارد البشرية المتكاملة

**Branch**: `[004-integrated-staff-affairs]` | **Date**: 2026-07-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/004-integrated-staff-affairs/spec.md`

**Planning status**: Design only. No application code, schema, production data, deployment, or Git branch change is authorized by this plan.

## Summary

تطوير منظومة شؤون العاملين تدريجيًا فوق المكونات الحالية، مع فصل واضح بين:

- `Staff`: ملف العامل، الهيكل التنظيمي، المديرون، الطلبات الذاتية، الإجازات، التأديب، و«ارتق».
- `Attendance`: سياسات الدوام، البصمات الخام، احتساب اليوم، الاستثناءات، وتقارير الحضور.
- `Finance`: الآثار المالية والرواتب؛ تستقبل آثارًا معتمدة بعقد ولا تسمح لـHR بالكتابة المباشرة.
- `Notifications`: تنبيهات الطلبات والموافقات والتصعيد عبر عقد صغير.
- `OperationsAudit`: التدقيق الإلزامي، سياسات التراجع، والدفعات الذرية.

يظل النظام Modular Monolith واحدًا، وتبقى صفحات الإدارة والبوابات الحالية مهايئات متوافقة. يبدأ التشغيل بوضع ظل يقرأ نفس البيانات ويحسب نتيجة جديدة للمقارنة، ثم ينتقل إلى العرض، ثم الاعتماد بعد مصالحة موثقة ونسخة احتياطية وتجربة رجوع.

## Scope Boundaries *(mandatory)*

- **In scope**: سياسات الدوام العامة والمسمى والقوة والمجموعة والفرد؛ البصمات والاحتساب؛ الأذونات وحصصها والموافقات؛ بوابة العامل والمدير؛ التقارير؛ الإجازات والأرصدة؛ التأديب والتظلم؛ ارتق؛ الهيكل والمديرون؛ التكامل مع Finance/Notifications/Audit.
- **Out of scope**: التوظيف قبل التعيين، إعادة بناء الرواتب، برمجيات أجهزة البصمة، حضور الطلاب، تطبيق هاتف أصلي، أو تثبيت قيم قانونية غير معتمدة.
- **Compatibility baseline**: تبقى URLs الحالية وحقول POST/GET والجلسات والدور الفعال وعقود DataTables والاستيراد والتصدير قابلة للاستخدام خلال الترحيل. تبقى الجداول الحالية مقروءة ولا يحذف تاريخها.
- **Authorized side effects**: في مرحلة التخطيط الحالية: إنشاء وتحديث ملفات `specs/004-integrated-staff-affairs/` و`.specify/feature.json` فقط. كل تطبيق أو migration أو كتابة بيانات أو نشر يحتاج موافقة لاحقة صريحة.

## Technical Context

**Language/Version**: PHP >= 8.0 من `composer.json`; JavaScript الحالي للواجهات فقط، دون إطار جديد.

**Primary Dependencies**: PDO، الجلسات والصلاحيات الحالية، Bootstrap 5 RTL، jQuery/DataTables، `AuditService`, `UndoManager`, `FileUploadGuard`, `PushNotification` أو مهايئه، وعقود Finance الحالية.

**Storage**: MySQL/MariaDB عبر PDO. الجداول الحالية المتأثرة بالقراءة/الترحيل: `users`, `staff_profiles`, `user_role_assignments`, `staff_attendance`, `staff_biometric_logs`, `staff_shift_overrides`, `staff_permissions`, `staff_leaves`, `staff_disciplinary`, `settings`, `activity_logs`, و`undo_log`. الجداول الجديدة محددة في [data-model.md](data-model.md).

**Testing**: اختبارات PHP تحت `tests/`، lint لكل ملف، اختبارات عقود وصلاحيات وحالات، اختبارات تكامل وتنافس على قاعدة صريحة منتهية بـ`_test`، تدقيق UI/رفع/كتابة/معمارية، واختبارات مقارنة shadow.

**Target Platform**: نشر Apache/XAMPP-compatible الحالي. تفاصيل production الكاملة `Not confirmed yet`.

**Project Type**: Modular monolith PHP server-rendered مع entrypoints حسب الدور وواجهات JSON محددة.

**Performance Goals**:

- نتيجة لوحة المدير وصندوق الطلبات في أقل من ثانيتين للعرض المعتاد.
- تقارير 500 عامل لسنة كاملة في أقل من 3 ثوانٍ في بيئة القبول، مع pagination وتصدير خلفي عند الأحجام الكبيرة.
- إعادة احتساب عامل/شهر تفاعليًا في أقل من 5 ثوانٍ؛ الدفعات الأكبر تعمل كعملية مراقبة قابلة للاستئناف.
- عدم إعادة احتساب التاريخ كاملًا عند كل عرض؛ التقارير تقرأ نتيجة يومية مشتقة ومؤرخة.

**Constraints**:

- المنطقة الزمنية الرسمية `Africa/Cairo` ما لم يعتمد إعداد مختلف.
- البصمات الخام append-only؛ التصحيح لا يغير الخام.
- السياسات المنشورة versioned وeffective-dated.
- لا DDL وقت الطلب، ولا SQL أعمال جديد داخل صفحات الدور.
- كل كتابة أعمال مدققة في المعاملة نفسها، ولا partial approval/quota/leave balance.
- البيانات الصحية والتأديبية والشكاوى والمرفقات خاصة ومقيدة.
- لا تفعيل على `educore` قبل clone معزول ونسخة احتياطية وتجربة رجوع ومصالحة.

**Scale/Scope**:

- الوضع الحالي المؤكد: 247 ملف عامل، وبيانات HR تشغيلية قليلة/تجريبية في الجداول الحالية.
- هدف التصميم: 500 عامل نشط، 5 سنوات نتائج يومية (نحو 912,500 يوم عمل قبل الاستبعاد)، وملايين أحداث البصمة الخام دون استعلامات N+1.
- نحو 20–35 مورد بيانات جديد على دفعات؛ لا تنشأ كلها في migration واحدة.
- 8–10 أسطح إدارة/خدمة ذاتية رئيسية مع مهايئات الأدوار.

## Constitution Check

*GATE: passed before Phase 0 research; re-check required after Phase 1 design.*

- [x] **Canonical context**: قُرئ `AGENTS.md` والدستور ووثائق العمارة والبنية وقاعدة البيانات والرفع ووحدة Staff؛ القيم القانونية غير المؤكدة معلّمة.
- [x] **Compatibility**: الصفحات والجداول والخدمات والعقود الحالية محصورة ولها استراتيجية adapter/shadow/migration.
- [x] **Architecture**: الخطة توسع `src/Modules/Staff` و`src/Modules/Attendance` القائمين، ولا تنشئ framework أو auth أو audit أو approval عام موازي.
- [x] **Security/data**: الصلاحيات والنطاق وCSRF والخصوصية والمرفقات والمعاملات والتدقيق والتزامن والترحيل موثقة.
- [x] **Testing/rollback**: characterization، `_test` guards، shadow comparison، النسخ الاحتياطي، reconciliation، والرجوع مراحل إلزامية.
- [x] **Governance**: يلزم ADR لملكية الوحدات والسياسات والنتائج المشتقة وعقود Finance/Notifications، مع `composer architecture-audit` دون توسيع baseline.

لا توجد استثناءات دستورية معتمدة. القيم القانونية وسياسات الإدارة تمنع **التفعيل التنفيذي** فقط، ولا تمنع تصميم البنية القابلة للتهيئة.

## Architecture Decisions

### 1. حدود الملكية

- `src/Modules/Attendance` يملك الدوام، التقويم التشغيلي، الخام، محرك الاحتساب، نتيجة اليوم، الاستثناءات، وتقارير الحضور.
- `src/Modules/Staff` يملك الهيكل الوظيفي، المديرين، مجموعات السياسات، الأذونات، الإجازات، الموافقات داخل HR، التأديب، وارتق.
- الموافقات ليست محركًا عامًا جديدًا للنظام كله؛ هي workflow مملوك لـStaff ويمكن لاحقًا استخراج primitive مشترك بعد إثبات مستهلك ثانٍ غير Finance.
- Finance وNotifications وOperationsAudit تتعامل معها الوحدة عبر عقود موثقة فقط.

### 2. الحقيقة الخام والنتيجة الرسمية

- `staff_biometric_logs` يبقى سجلًا خامًا غير قابل للتعديل.
- نتيجة اليوم الجديدة مورد مستقل versioned يثبت الدوام والسياسات والطلبات وإصدار الحاسبة.
- `staff_attendance` يبقى مهايئ التوافق في البداية؛ وضع الظل يقارن نتيجته بالنتيجة الجديدة قبل تحويل القراءة الرسمية.

### 3. السياسات المؤرخة

- كل دوام وحصة وإجازة ومسار موافقة ينشر كنسخة بفترة سريان.
- الأولوية: العامل > المجموعة الصريحة ذات الرتبة > القوة > المسمى > العام.
- تعادل سياستين صالحـتين في الدرجة نفسها خطأ نشر، وليس اختيارًا عشوائيًا.

### 4. الموافقة والحصص

- مسار الموافقة يُنسخ snapshot عند الإرسال؛ تغيير المدير لاحقًا لا يغير الطلب بصمت.
- الحصة ledger: حجز عند الإرسال، commit عند الاعتماد، release عند الرفض/الإلغاء.
- قرار المرحلة وخصم الحصة وأثر إعادة الاحتساب معاملة ذرية.

### 5. التقارير

- التقارير تقرأ `official daily result` مفهرسًا وتقدم drill-down إلى الخام والسياسات والقرارات.
- لا تعيد صفحات التقارير تشغيل الحاسبة لكل صف.
- التصدير الكبير عملية خاضعة للصلاحية مع ملف خاص مؤقت ومدة احتفاظ.

### 6. السجلات الرسمية والتراجع

- المسودات والإعدادات المؤهلة يمكن أن تستخدم undo المشترك.
- الطلبات المعتمدة والجزاءات والآثار المالية لا تُحذف أو تُستعاد snapshot مباشرة؛ تُلغى/تعكس بحدث رسمي جديد.
- أسرار الصحة والشكاوى وبيانات التحقيق تُنقح من audit snapshots وفق policy registry.

## Effective Rule Resolution

```text
Calendar day
  └─ Is employee in active service?
      └─ Resolve schedule by date
          1. Employee override
          2. Highest-priority explicit group
          3. Organizational unit/workforce
          4. Job title
          5. Global policy
      └─ Apply calendar exception/holiday
      └─ Collect raw punches in schedule window
      └─ Apply approved leave/permission/mission windows
      └─ Produce versioned day result + explanation
```

عند وجود إذن تأخير، يصبح آخر دخول بلا مخالفة هو نهاية نافذة الإذن المعتمدة، أو حد الدوام والسماح المعتاد أيهما أبعد وفق السياسة، دون جمع السماح مرتين. وعند إذن انصراف مبكر يصبح أول خروج بلا مخالفة هو بداية نافذة الإذن. أي دقائق خارج النافذة تبقى مخالفة.

## Approval Flow

```text
Draft
  → Submitted + quota reserved + workflow snapshot
  → Direct manager stage
  → Administrative/assigned approver stage(s)
  → Approved + quota committed + attendance recalculation queued
  └→ Rejected + quota released
  └→ Cancel request → cancellation workflow → released/recalculated
```

- المرحلة قد تتطلب شخصًا واحدًا، أي شخص من مجموعة، الجميع، أو أغلبية.
- الإنابة مؤرخة ولا تغير صاحب الصلاحية الأصلي في السجل.
- عدم وجود مدير مباشر يوجه لمسار fallback منشور؛ لا يوجد auto-approve.
- الشكوى ضد المدير تستخدم مسار confidential bypass.

## Page And Surface Plan

### صفحات الإدارة الحالية التي تتحول إلى adapters

| الصفحة الحالية | الهدف بعد الترحيل |
|---|---|
| `admin/hr_center.php` | لوحة HR موحدة وعدادات الاستثناءات والطلبات |
| `admin/staff_shifts.php` | محرر سياسات الدوام والنطاقات والمعاينة |
| `admin/staff_attendance.php` | تشغيل يومي واستثناءات وتصحيحات |
| `admin/staff_attendance_reports.php` | تقارير server-side وdrill-down |
| `admin/permissions.php` | إدارة أنواع/سياسات/طلبات الأذونات |
| `admin/leaves.php` | الطلبات وأنواع الإجازات والموافقات |
| `admin/leave_balances.php` | حسابات وحركات الأرصدة والتسويات |
| `admin/disciplinary.php` | قضايا وتحقيقات وقرارات وتظلمات |
| `admin/biometric_devices.php` | الأجهزة والربط وصحة الاستيراد فقط |

### صفحات إدارة جديدة مخططة

| الصفحة | المسؤولية |
|---|---|
| `admin/hr_organization.php` | القوى، المجموعات، المديرون، والإنابات |
| `admin/hr_approval_workflows.php` | قوالب المراحل والمعتمدون والتصعيد |
| `admin/hr_policy_calendar.php` | العطلات والمواسم والاستثناءات |
| `admin/hr_attendance_exceptions.php` | بصمات ناقصة/غير مربوطة وتعارضات |
| `admin/hr_ertaq.php` | صندوق ارتق والإسناد والتصعيد والمؤشرات |
| `admin/hr_audit.php` | سجل قرارات HR وإعادات الاحتساب |

### الخدمة الذاتية وصندوق المدير

- تستخدم بوابات الدور الحالية، مع adapter صغير في كل بوابة عامل قائمة (`teacher`, `specialist`, `supervisor`, والأدوار الموظفية التي تستخدم admin portal).
- العرض والمنطق مشتركان من `src/Modules/Staff/Presentation`؛ لا تنسخ قواعد الأعمال بين البوابات.
- الأسطح المخططة: "طلباتي"، "طلب إذن"، "طلب إجازة"، "رصيدي"، "حضوري"، "ارتق"، و"بانتظار قراري".
- قبل التنفيذ يجب حصر كل دور عامل فعلي وتحديد الـadapter العام المناسب؛ إنشاء مجلد دور جديد غير معتمد ليس جزءًا من الخطة.

## Project Structure

### Documentation (this feature)

```text
specs/004-integrated-staff-affairs/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
└── contracts/
    ├── attendance-calculation.md
    ├── approval-and-quota.md
    ├── acceptance-handoff.md
    ├── cross-module.md
    └── http-surfaces.md
```

### Source Code (future implementation; no files created in this planning turn)

```text
admin/
├── hr_center.php
├── staff_shifts.php
├── staff_attendance.php
├── staff_attendance_reports.php
├── permissions.php
├── leaves.php
├── leave_balances.php
├── disciplinary.php
├── biometric_devices.php
├── hr_organization.php
├── hr_approval_workflows.php
├── hr_policy_calendar.php
├── hr_attendance_exceptions.php
├── hr_ertaq.php
└── hr_audit.php

src/Modules/Attendance/
├── Application/        # resolve/calculate/recalculate/report use cases
├── Domain/             # schedules, punch rules, day-result policies
├── Contracts/          # staff/calendar/request read contracts
├── Infrastructure/     # PDO repositories and legacy adapters
└── Presentation/       # report/exception view models

src/Modules/Staff/
├── Application/        # organization, requests, approvals, leave, discipline, Ertaq
├── Domain/             # policies, quota/leave ledgers, state machines
├── Contracts/          # Finance/Notification/Attendance-facing contracts
├── Infrastructure/     # PDO repositories and legacy table adapters
└── Presentation/       # shared portal/admin fragments and presenters

database/migrations/    # additive, one bounded phase per migration set
storage/private/hr/     # request/medical/discipline/Ertaq attachments
tools/                  # guarded preview/reconcile/recalculate/rollback/acceptance-reset CLIs
tests/                  # contract/unit/_test integration/performance/security/browser acceptance
tests/fixtures/         # versioned acceptance dataset and manifest; no real secrets
docs/                   # ADR, database ownership, operations runbook
```

**Structure Decision**: نوسع وحدتي Staff وAttendance الموجودتين فقط عند تنفيذ use case حقيقي، ونبقي الملفات في `classes/` كواجهات توافق حتى نقل مسؤوليتها واختبارها. صفحات الدور تنسق HTTP ولا تملك SQL جديدًا. لا ينشأ `Shared` أو محرك workflow عام قبل إثبات الحاجة عبر أكثر من وحدة.

## Delivery Phases

### Phase 0 — Baseline, policy decisions, and safety harness

- اعتماد قاموس القوة/الوحدة، المدير المباشر، أنواع الأذونات والإجازات، الحصص، ومراحل الموافقة.
- تثبيت أولوية الإسناد: العامل > المجموعة > القوة/الوحدة > المسمى > العام.
- اعتماد سياسات الاستراحة والعمل الإضافي والحضور البديل وحد التشغيل الأدنى ومسار شكوى الخطر الفوري.
- characterization tests للصفحات والخدمات والجداول الحالية.
- حصر بوابات كل دور عامل وصلاحيات المديرين.
- ADR لحدود Staff/Attendance/Finance/Notifications وللسجلات الرسمية.
- clone `_test`، snapshot، reconciliation format، وأعلام التشغيل.
- تعريف حزمة قبول دائمة: personas، dataset manifest، baseline، دليل السيناريوهات، وطريقة تسليم بيانات الدخول الآمنة.
- **Gate**: لا schema ولا feature implementation قبل اعتماد السياسة والrollback.

### Phase 1 — Organization and effective-dated policy foundation

- القوى والمجموعات والعضويات والمديرون والإنابات.
- policy version/scope/resolution primitives داخل Staff.
- التعامل مع الطلب المستقبلي حسب تعيين تاريخ الاستحقاق، وإنهاء الوصول عند انتهاء الخدمة/علاقة المدير.
- واجهة إدارة ومعاينة التعارض.
- migration من `staff_profiles.department` إلى assignment مؤرخ مع إبقاء dual-read.
- **Rollback**: feature flag يعيد القراءة للنص القديم دون حذف الجديد.

### Phase 2 — Schedule and attendance shadow engine

- سياسات الدوام والتقويم والاستثناءات.
- الاستراحات والورديات المنقسمة والتبديل المؤقت والعمل الإضافي ووسائل الحضور البديلة.
- immutable raw punch contract مع وقت الجهاز/الاستلام ومنع تداخل هوية البصمة، وday-result calculator.
- طلب العامل مراجعة يوم حضور، وعدم اعتبار الإذن إثبات وجود أو علاجًا لبصمة مفقودة.
- تشغيل shadow بجانب `StaffAttendanceService` الحالي.
- تقارير فرق old/new لكل عامل/يوم وسبب الفرق.
- **Gate**: صفر سجلات مفقودة، وكل فرق مصنف ومعتمد.

### Phase 3 — Permissions, quota ledger, and approvals

- أنواع الأذونات وسياساتها وحصص العدد/الدقائق.
- request state machine، workflow snapshots، المديرون، الإنابة، conflict rules.
- قواعد actor المكرر بين المراحل، النصاب، التعادل، وأثر الرفض في الموافقات الجماعية.
- انتقال الطلب عند النقل المستقبلي أو انتهاء الخدمة، وقواعد الفترة المقفلة.
- بوابة العامل وصندوق المدير والتنبيهات.
- ربط الاعتماد بإعادة احتساب الحضور.
- **Gate**: اختبارات التزامن، النطاق، أمثلة 07:30/09:00 و14:30/12:00.

### Phase 4 — Official attendance reports

- تحويل القراءة الرسمية تدريجيًا إلى day results.
- تقارير الفرد/القوة/المسمى/الفترة/الغياب/الأذونات.
- إقفال الفترة وإعادة فتحها ووسم الأحداث المتأخرة والتصحيحات.
- drill-down، export، snapshots، وصلاحيات المدير.
- **Gate**: totals equal details؛ shadow reconciliation معتمد؛ performance target.

### Phase 5 — Leave ledger and comprehensive leave workflows

- أنواع وسياسات وأرصدة بحركات immutable.
- الطلبات والموافقات والمرفقات الطبية والعودة/التمديد/الإلغاء.
- حد التشغيل الأدنى وفترات الحظر والاستثناء الإداري المسبب.
- ربط Attendance وFinance عبر عقود.
- ترحيل `annual_leave_balance` و`staff_leaves` بتقرير opening balance.
- **Gate**: balance invariant، year-crossing، overlap، private upload rollback.

### Phase 6 — Discipline, investigation, and appeal

- قضايا وأحداث وتحقيق وقرار وتبليغ وتظلم.
- قضايا متعددة الأطراف، إجراءات احترازية مؤقتة، وإعادة فتح بدليل جديد.
- فصل الواجبات، confidential access، وربط الأدلة.
- Finance impact request فقط بعد القرار النهائي.
- **Gate**: no hard delete، no self-approval، full audit/redaction tests.

### Phase 7 — Ertaq employee relations

- تذاكر ورسائل وإسناد وSLA وتصعيد وسرية.
- مسار حماية عاجل، شكاوى جماعية/مرتبطة، وطلب سحب لا يمحو الأصل بعد بدء التحقيق.
- تحويل موثق إلى قضية أو مبادرة.
- مؤشرات مجمعة لا تكشف الهوية.
- **Gate**: search/notification leakage tests، attachment authorization، conflict bypass.

### Phase 8 — Unified HR timeline and operations

- timeline موحد، لوحة HR، تنبيهات الوثائق والتواريخ.
- تقارير تشغيل ومصالحة وأدوات إعادة الاحتساب.
- توثيق التشغيل والدعم والاحتفاظ.
- **Gate**: role matrix end-to-end، quality suite، recovery drill.

### Phase 9 — Rollout and legacy retirement

- أوضاع `off → shadow → compare → display → official`.
- batches قابلة للاستئناف ونافذة تحويل تضبط الكتابات المتزامنة وتمنع dual-write اليدوي.
- تفعيل وحدة واحدة/قوة واحدة أولًا، ثم توسيع النطاق.
- الاحتفاظ بالـlegacy adapters لدورة تقارير كاملة.
- تحميل حزمة القبول المعزولة، تنفيذ الرحلات الحرجة فعليًا من المتصفح لكل persona، وحفظ الأدلة.
- ترك حسابات وبيانات القبول للمستخدم مع دليل التجربة وbaseline ووسيلة استعادة آمنة وتقرير تسليم.
- لا حذف جداول أو حقول قديمة ضمن هذا feature؛ retirement مواصفة منفصلة بعد إثبات عدم وجود callers.

## Verification Strategy

### Unit/domain

- precedence/conflict resolution.
- schedule windows and overnight shifts.
- permission coverage and minute calculations.
- partial permission without attendance, split shifts, breaks, overtime, and alternative attendance.
- biometric identity overlap, delayed/out-of-order events, and device clock drift.
- quota reservation/commit/release.
- leave accrual/carryover/movement invariants.
- request/approval/discipline/Ertaq state transitions.

### Contract/security

- existing URLs/fields/session/JSON remain valid.
- role and manager scope matrix.
- access revalidation after service/manager/delegation expiry.
- quorum/tie/same-actor approval rules.
- CSRF and PRG/API response contracts.
- audit policy registration/redaction/undo eligibility.
- Finance/Notifications/Attendance contracts.
- private upload/download authorization.

### Guarded integration

- complete create/approve/reject/cancel flows on `_test`.
- simultaneous approvals and quota requests.
- biometric import → day result → permission recalculation → report.
- employee attendance correction → approval → new official version.
- late device events and reused biometric identity without historical reassignment.
- leave approval → balance → attendance → cancellation.
- discipline → appeal → Finance effect request.
- Ertaq confidential routing and conversion.
- urgent Ertaq routing, collective parties, withdrawal after investigation, and conflict bypass.
- interim discipline measure, new evidence, and audited case reopening.
- migration preview/apply/reconcile/rollback on full clone.
- interrupted migration resume and writes during cutover window.
- acceptance dataset seed/reset idempotency and isolation guard.

### Browser acceptance and handoff

- فتح النظام فعليًا وتسجيل الدخول بكل persona: عامل، مدير مباشر، مدير إداري، HR، Finance، وSuper Admin.
- تنفيذ الإنشاء والتعديل والإرسال والاعتماد والرفض والإلغاء والتصحيح وإعادة الاحتساب والتقارير من الواجهة.
- اختبار رسائل الخطأ، النطاق، الصلاحيات المنتهية، المرفقات، التصدير، والإقفال/إعادة الفتح.
- حفظ نتيجة كل خطوة ودليل مناسب وissue لأي فشل؛ لا إعلان للجاهزية مع رحلة حرجة فاشلة.
- تسليم دليل عربي يربط كل persona بالسيناريو والنتيجة المتوقعة، دون تخزين كلمات المرور في المستودع.
- ترك dataset القبول كما هو بعد الاختبار، مع snapshot baseline وخيار استعادة محدد النطاق للمستخدم.

### Performance and operations

- 500 staff × 365 days report dataset.
- raw punch duplicate/import stress.
- delayed/out-of-order device event replay and clock-drift thresholds.
- server-side pagination and export memory limits.
- recalculation chunking/idempotency/resume.
- backup restore and feature-flag rollback drill.

### Mandatory gates per implementation slice

- touched PHP lint.
- focused unit/contract/integration tests.
- `composer audit-write-coverage`.
- `composer upload-policy-audit` when attachments are touched.
- relevant role/UI/DataTables tests.
- `composer architecture-audit`.
- `composer quality` before closing a state-changing feature.
- `git diff --check` and scoped diff review.

### Acceptance handoff gate

- قاعدة الهدف تحمل marker اختبار/قبول مصرحًا؛ أي غموض يمنع seed/reset.
- manifest يطابق جميع السجلات التجريبية ولا يملك سجلًا فعليًا.
- حسابات القبول تعمل وبيانات دخولها مسلمة بقناة آمنة.
- الرحلات الحرجة مكتملة من المتصفح أو لها عيوب مانعة موثقة.
- دليل المستخدم وbaseline والاستعادة وتقرير الأدلة موجودة ومجربة مرة بعد الاختبار.

## Data Migration And Rollback

1. Full SQL backup plus private-file snapshot and checksums.
2. Migration preview reports counts, invalid references, overlapping biometric identities, duplicate managers, policy conflicts, and quarantined rows.
3. Additive schema only; no legacy column rewrite in the first pass.
4. Backfill with source IDs, migration batch ID, checkpoint, resume token, and idempotency key.
5. Reconcile counts, hashes, and sampled calculations.
6. Run shadow and compare for at least one complete attendance/payroll reporting cycle.
7. Open a documented cutover window with one explicit write mode and reconciliation watermark; no page-level dual-write.
8. Enable display per scope through feature flags.
9. Enable official results only after signed acceptance.
10. Rollback toggles readers to legacy and reverses only migration-owned inserts using manifest; no broad delete.
11. Acceptance data remains in the isolated environment for user retesting; resetting it targets manifest-owned demo rows only.

## Stop Conditions

- legal leave/discipline/retention values are not approved before activation.
- manager/workforce ownership cannot be established.
- a current caller or dynamic include of legacy pages cannot be proved compatible.
- a migration or test would write to `educore` instead of explicit `_test`.
- Finance effect contract or rollback is unverified.
- approval scope permits self-approval or data outside manager scope.
- confidential attachment storage/authorization is incomplete.
- shadow differences are unexplained.
- demo seed/reset target cannot be proven isolated or manifest ownership is incomplete.
- any critical browser journey remains failed without an accepted blocking issue.
- dirty-worktree overlap affects an implementation hunk and cannot be isolated.

## Post-Design Constitution Re-check

- [x] Research decisions consolidated in `research.md`.
- [x] Data entities, invariants, relationships, and transitions complete in `data-model.md`.
- [x] HTTP and cross-module contracts documented.
- [x] Quickstart proves end-to-end scenarios and rollback.
- [x] No unresolved `NEEDS CLARIFICATION` remains for architecture; policy values remain activation gates.
- [x] Complexity Tracking remains empty or has approved exceptions.

## Complexity Tracking

لا توجد مخالفات معمارية مطلوبة أو استثناءات معتمدة في الخطة. أي حاجة لاحقة إلى محرك workflow مشترك أو مجلد دور جديد أو وصول مباشر عبر الوحدات تستلزم ADR ومراجعة مستقلة، ولا تُفترض ضمن هذا التصميم.
