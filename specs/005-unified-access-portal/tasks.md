# مهام التنفيذ: بوابة الوصول الموحدة والدخول التلقائي عبر Teams

> **تعديل مكتمل في 2026-08-11:** ألغى طلب المستخدم وضع الضيف بعد التنفيذ الأول. المهام التاريخية الخاصة بكتالوج وإعدادات الضيف أدناه مستبدلة بالمهام T106–T112 في نهاية الملف.

**مصدر المتطلبات:** [spec.md](spec.md)  
**الخطة التقنية:** [plan.md](plan.md)  
**العقود:** [contracts/](contracts/)  
**قاعدة التنفيذ:** لا يبدأ أي تعديل قبل إكمال مرحلة الحماية، ولا تُستخدم قاعدة `educore` الفعلية لاختبار كتابة أو migration.

## صيغة المهام

- `[P]`: قابلة للتنفيذ بالتوازي بعد اكتمال اعتمادياتها، وفي ملفات مستقلة.
- `[USn]`: قصة المستخدم التي تخدمها المهمة.
- أي مهمة تكتشف تداخلاً غير قابل للفصل في dirty worktree تتوقف وتطلب توجيهاً، ولا تعيد أو تمسح التغييرات.

## المرحلة 0 — التفعيل المؤجل وحماية العمل الموجود

- [x] T001 إنشاء `specs/005-unified-access-portal/implementation-notes.md` عند بدء التنفيذ وتسجيل ناتج `git status --short` وقائمة الملفات الموجودة مسبقاً داخل/خارج النطاق دون تعديلها.
- [x] T002 قراءة `AGENTS.md` و`docs/architecture.md` و`docs/database.md` و`docs/coding-rules.md` و`docs/project-structure.md` و`docs/ai-change-checklist.md` وتسجيل أي قيد جديد في `specs/005-unified-access-portal/implementation-notes.md`.
- [ ] T003 تفعيل الميزة عمداً في `.specify/feature.json` فقط بعد إنهاء/حفظ عمل `specs/004-integrated-staff-affairs` وتوثيق ذلك في `specs/005-unified-access-portal/implementation-notes.md`.
- [x] T004 حُسم عدم وجود migration أو اختبار كتابة مطلوب؛ استخدمت اختبارات SQLite معزولة لقراءة المواد ووُثقت خطة rollback دون لمس `educore`.
- [ ] T005 تشغيل baseline آمن لـPHP syntax والاختبارات الحالية و`composer architecture-audit` و`composer audit-write-coverage` و`composer quality` وتسجيل النتائج غير المعدلة في `specs/005-unified-access-portal/implementation-notes.md`.
- [x] T006 فحص جميع callers والمسارات والنماذج ومفاتيح session والعقود في `index.php` و`login.php` و`public_portal.php` و`intro_youtube.php` و`teams/app.html` و`auth/teams_token_handler.php` و`classes/MicrosoftSSO.php` وتوثيق contract baseline في `specs/005-unified-access-portal/implementation-notes.md`.
- [x] T007 فحص مركز المواد ومساراته وتحديث R7 إلى إعادة استخدام `enabled/downloadable` بأدلة التنفيذ.
- [x] T008 فحص مالك إعدادات الإدارة وAudit/Undo policy registry وإعادة استخدام `settings` و`AuditService` دون logger أو registry جديد.
- [ ] T009 التحقق خارج الكود من تسجيل Redirect URIs المحلية والإنتاجية في Microsoft Entra وتسجيل النتيجة فقط في `specs/005-unified-access-portal/implementation-notes.md`؛ لا تُخزن أسرار أو لقطات token.

**بوابة المرحلة:** مسارات المواد/audit مؤكدة، قاعدة الاختبار معزولة، ولا يوجد overlap غير محلول.

---

## المرحلة 1 — اختبارات Characterization والعقود المحمية

- [x] T010 [P] تثبيت تحويلات الجلسة والأدوار ومعاملات المرحلة في اختبارات البوابة والتوافق.
- [x] T011 [P] تثبيت أسماء حقول POST وسلوك رفض الدخول اليدوي في اختبارات الواجهة وسياسة الدخول.
- [x] T012 [P] تثبيت عقد POST/JSON/session لمعالج Teams في `teams_auto_sso_contract_test.php`.
- [x] T013 [P] تثبيت سبب التعطيل المخصص/العام عبر اختبارات سياسة الحساب والدخول اليدوي وMicrosoft/Teams.
- [x] T014 [P] اختبار اختيار Microsoft للـlocal/production وفشل الجلسة والتدقيق الآمن.
- [x] T015 [P] اختبار مصفوفة سياسة المقدمة والوجهات المسموحة.
- [x] T016 [P] اختبار سياسة قائمة/تنزيل المواد بقاعدة SQLite معزولة في `public_materials_policy_test.php`.
- [x] T017 تشغيل اختبارات التوصيف وتسجيل النتائج في `implementation-notes.md`.

**بوابة المرحلة:** الاختبارات تصف العقود الحالية، وأي فشل baseline مفصول عن الميزة.

---

## المرحلة 2 — الأساس الداخلي والبيانات

- [x] T018 حُسم عدم الحاجة إلى migration: أعيد استخدام مفتاح unique موجود في جدول `settings`.
- [x] T019 حُسم عدم الحاجة إلى projection: أعيد استخدام `materials.enabled/downloadable` ووُثق القرار.
- [x] T020 [P] إنشاء كتالوج الخدمات الثابت ومنع المفاتيح والوجهات غير المعروفة.
- [x] T021 [P] إنشاء سياسة وصول الضيف default-deny.
- [x] T022 [P] إنشاء سياسة قرار المقدمة 15 يوماً وسياق Teams والوجهات المسموحة.
- [x] T023 [P] تعريف عقد إعدادات البوابة.
- [x] T024 [P] تعريف أصغر عقد قراءة للمواد وتوثيق adapter الفعلي.
- [x] T025 تنفيذ repository باستخدام PDO و`FOR UPDATE` ومعاملة تدقيق ذرية على جدول الإعدادات القائم.
- [x] T026 تنفيذ adapter قراءة المواد من المخطط الحالي مع سياسة مركزية للقائمة والتنزيل.
- [x] T027 [P] تنفيذ view model للبوابة والخدمات.
- [x] T028 [P] تنفيذ query خدمات الضيف.
- [x] T029 تنفيذ تحديث خدمات الضيف transactionally مع الكتالوج والتدقيق.
- [x] T030 تنفيذ قراءة المواد العامة؛ بقي نشر المادة في مركز المواد المالك ولم يُنشأ command مكرر.
- [x] T031 إعادة استخدام سياسة كيان `settings` المسجلة؛ لا كيان أو registry جديد مطلوب.
- [x] T032 إضافة أعلام التشغيل غير السرية وقراءتها من `config/public_portal.php`.
- [x] T033 [P] إضافة unit tests للكتالوج وسياسات الضيف والمقدمة والوجهات.
- [ ] T034 [P] إضافة integration tests للrepository والتزامن وrollback عند فشل audit في `tests/public_portal_settings_integration_test.php` على قاعدة الاختبار المعزولة.
- [x] T035 [P] إضافة اختبار SQLite لعقد المواد والنشر default-deny في `tests/public_materials_policy_test.php`.
- [x] T036 لا migrations مطلوبة؛ نجح audit-write-coverage بنتيجة `AUDIT_REVIEW_REQUIRED=0`.

**بوابة المرحلة:** لا كتابة بلا audit، ولا خدمة/مادة عامة بلا allow-list ونشر صريح.

---

## المرحلة 3 — قصة US1: البوابة الموحدة بلا مراحل

- [x] T037 [US1] إضافة اختبار DOM/HTML يثبت طرق الدخول والرابط المباشر وغياب المراحل.
- [x] T038 [P] [US1] إنشاء stylesheet مركزي مع الحفاظ على الهوية والوضع الداكن والاستجابة.
- [x] T039 [P] [US1] إنشاء سلوك إظهار كلمة المرور والمظهر دون Auth policy.
- [x] T040 [US1] استخراج بطاقة الدخول الموحدة مع escaping وحقول POST الحالية.
- [x] T041 [US1] تعديل `index.php` للـview model والعلم والمقدمة وتحويل الجلسة.
- [x] T042 [US1] إبقاء `login.php` معالج POST متوافقاً وإهمال المرحلة في المصادقة.
- [x] T043 [US1] تحويل `public_portal.php` إلى adapter توافق عند تفعيل العلم مع إبقائه مسار rollback.
- [x] T044 [US1] تحميل CSS/JS المركزيين في نقاط العرض العامة.
- [x] T045 [US1] تشغيل الاختبارات وQA فعلي لسطح المكتب وRTL والوضع الداكن وتسجيل النتيجة دون صور داخل المستودع.

**اختبار القصة المستقل:** مع العلم مفعلاً، `index.php` يعرض بطاقة واحدة داخل الشكل الحالي ولا يحتوي على اسم أو اختيار مرحلة.

---

## المرحلة 4 — قصة US2: Teams SSO تلقائي صارم

- [x] T046 [US2] توسيع اختبارات Microsoft/Teams لتغطية المطابقة وتغير البريد والربط المفقود والتكرار وبيئة التحقق.
- [ ] T047 [P] [US2] إضافة اختبار جلسة ووجهة دور بعد نجاح token في `tests/teams_auto_sso_session_test.php`.
- [x] T048 [US2] إعادة فحص البريد مقابل `email` و`username` ومنع ID وحده.
- [x] T049 [US2] قبول POST same-origin وعدم auto-link وإعادة redirect/fallback آمن.
- [x] T050 [US2] تطبيق سياسة التعطيل المشتركة وعدم كشف stack/token/هوية محتملة.
- [x] T051 [US2] بدء `getAuthToken()` آلياً مرة واحدة مع مهلات initialize/token/fetch.
- [x] T052 [US2] إزالة الاعتماد على `request_sso` من الدخول الموحد.
- [x] T053 [US2] إزالة `postMessage('*')` ومسار الرسائل القديم.
- [x] T054 [US2] مراجعة manifest وإثبات تطابق App ID/resource/domain الإنتاج؛ لا تعديل مطلوب.
- [ ] T055 [US2] إضافة اختبار browser/mock لحالة loading ثم dashboard دون وميض البوابة في `tests/browser/teams_auto_sso_behavior_test.js`.
- [ ] T056 [US2] تشغيل مصفوفة Teams محلياً بالمحاكاة ثم end-to-end في مستأجر تجريبي مرتبط، وتسجيل request/correlation IDs فقط دون tokens في `specs/005-unified-access-portal/implementation-notes.md`.

**اختبار القصة المستقل:** فتح tab بحساب مرتبط ومطابق ينشئ الجلسة ويعرض لوحة الدور دون نقرة أو فيديو أو صفحة دخول.

---

## المرحلة 5 — قصة US3: fallback وطرق الدخول البديلة داخل Teams

- [x] T057 [US3] تغطية timeout/network/invalid/not-linked/mismatch في عقد Teams والمهلات.
- [x] T058 [US3] إعادة أكواد ورسائل SSO عامة ثابتة من الخادم دون استثناء Microsoft خام.
- [x] T059 [US3] تحميل fallback مرة واحدة ومنع إعادة auto SSO داخل البوابة.
- [x] T060 [US3] ضمان طرق الدخول البديلة والرابط المباشر في fallback.
- [x] T061 [US3] الحفاظ على POST اليدوي وسياق Teams ووجهة الدور.
- [x] T062 [US3] جعل إعادة المحاولة تفاعلية من زر Microsoft داخل البوابة، دون retry صامت متكرر.
- [x] T063 [US3] تشغيل اختبارات fallback والحلقات والبيانات الحساسة.

**اختبار القصة المستقل:** الحساب غير المرتبط أو الرمز الفاشل يرى البوابة الموحدة مرة واحدة ويمكنه استعمال كل البدائل.

---

## المرحلة 6 — قصة US4/US6: الضيف وإدارة الخدمات والمواد العامة

- [x] T064 [P] [US4] اختبار default-deny والخدمة المعطلة والمفتاح المجهول.
- [x] T065 [P] [US4] اختبار SQLite للمنشور/غير المنشور/العرض فقط/المرحلة المعطلة.
- [x] T066 [P] [US6] اختبار auth-before-processing وCSRF والتفويض إلى مالك التدقيق.
- [x] T067 [US4] إنشاء `guest.php` كـentrypoint قراءة فقط يستدعي `GetGuestServices` بلا دور.
- [x] T068 [US4] إنشاء `materials.php` بفلاتر allow-listed وpagination ودون مسارات ملفات.
- [x] T069 [US4] إنشاء `material_download.php` مع إعادة فحص شاملة واحتواء المسار.
- [x] T070 [US4] إبقاء التنزيل العام مستقلاً عن روابط `uploads` المباشرة وبنفس حدود التخزين القائمة.
- [x] T071 [US6] إنشاء صفحة إعداد أدمن مصادق عليها قبل المعالجة مع CSRF وfooter موحد.
- [x] T072 [US6] ربط POST بـ`UpdateGuestServices` والمعاملة المدققة؛ نشر المادة بقي في مالكه الحالي.
- [x] T073 [US6] إضافة رابط الإعداد إلى قائمة الأدمن دون تغيير أدوار أخرى.
- [x] T074 [US6] إضافة معاينة آمنة لا تتجاوز policy.
- [x] T075 [US4] إظهار زر الضيف عند وجود خدمة مفعلة وإبقاء رابط المواد المباشر دائماً كما طلب المستخدم؛ endpoint يمنعها عند التعطيل.
- [x] T076 [US4] إضافة empty state بلا كشف مفاتيح أو مسارات.
- [x] T077 [US4] إثبات إعادة فحص حالة الخدمة والمادة عند التنزيل.
- [ ] T078 [US6] إضافة `admin/public_portal_settings.php` إلى اختبار تغطية role footer/data-entry surface المؤكد في T008، ثم تشغيل `composer audit-write-coverage` وإثبات `AUDIT_REVIEW_REQUIRED=0` واختبار undo/conflict على قاعدة الاختبار.

**اختبار القصتين المستقل:** ما يفعله الأدمن يطابق ما يراه الضيف وما يسمح به الخادم، ولا تصبح أي مادة خاصة عامة ضمنياً.

---

## المرحلة 7 — قصة US5: فيديو المقدمة والوجهة المحفوظة

- [x] T079 [US5] إضافة unit matrix لقرار first visit/<15/=15/>15/Teams/cookie invalid/session fallback.
- [ ] T080 [P] [US5] إضافة browser behavior test للبوابة ورابط المواد والكوكيز المحظورة في `tests/browser/intro_visit_behavior_test.js`.
- [x] T081 [US5] استخدام `IntroVisitPolicy` ووجهتي `portal|materials` فقط.
- [x] T082 [US5] تقييم السياسة في `index.php` ومنع الحلقة.
- [x] T083 [US5] دعم أول زيارة مباشرة للمواد وتخطي Teams.
- [x] T084 [US5] ضبط cookie 15 يوماً وخصائصه الآمنة مع session fallback.
- [x] T085 [US5] اختبار الوجهات الخبيثة والمجهولة وإثبات عدم وجود open redirect.
- [x] T086 [US5] تشغيل مصفوفة السياسة وQA المتصفح وتسجيل النتائج.

**اختبار القصة المستقل:** سياسة الـ15 يوماً صحيحة، Teams لا يرى الفيديو، ورابط المواد الأول يعود إلى المواد لا إلى البوابة.

---

## المرحلة 8 — التقوية والتوافق والتوثيق

- [x] T087 [P] تثبيت السبب المخصص وحده والعام وحده في اختبارات سياسة الدخول والقنوات الحالية.
- [x] T088 [P] إضافة اختبار عدم تسجيل/تخزين tokens وبيانات الهوية الحساسة.
- [x] T089 [P] إضافة اختبارات توافق stage وTeams ومسار البوابة القديم.
- [x] T090 [P] إضافة فحص accessibility أساسي للـlabels والـARIA ولوحة المفاتيح مع QA فعلي.
- [ ] T091 مراجعة CSP وframe ancestors/Teams domains وsession cookie headers في `teams/app.html` وملفات حماية الخادم، وإضافة التغيير الأصغر المثبت فقط.
- [x] T092 تحديث دليل Microsoft SSO بالسلوك والمصفوفة والأعلام والمطابقة.
- [x] T093 إضافة ADR-077 لحدود PublicPortal والضيف وTeams والمواد.
- [x] T094 تحديث المعمارية وبنية المشروع بالمداخل والملكية.
- [x] T095 تحديث ذاكرة المشروع بالحقائق المؤكدة.
- [x] T096 تحديث `.env.example` بقيم غير سرية فقط.

---

## المرحلة 9 — الجودة والنشر المرحلي والتراجع

- [x] T097 تشغيل PHP syntax الشامل وجميع اختبارات الميزة وSQLite المعزولة وتسجيل الملخص.
- [ ] T098 تشغيل `composer audit-write-coverage` و`composer architecture-audit` و`composer quality` وإصلاح الأسباب الحقيقية دون توسيع baseline أو تعطيل gate.
- [x] T099 تشغيل `git diff --check` ومراجعة ملفات النطاق؛ النتيجة نظيفة ولا توجد أسرار أو backup/cache/log مولدة ضمن الميزة.
- [ ] T100 نشر migrations الإضافية والكود في staging مع `UNIFIED_ACCESS_PORTAL_ENABLED=false` و`TEAMS_AUTO_SSO_ENABLED=false` وتنفيذ smoke tests legacy/new.
- [ ] T101 تفعيل `UNIFIED_ACCESS_PORTAL_ENABLED` لمجموعة اختبار وتنفيذ مصفوفة local/production/manual/Microsoft/guest/materials/intro.
- [ ] T102 تفعيل `TEAMS_AUTO_SSO_ENABLED` لحسابات Teams تجريبية تشمل المطابق وغير المرتبط ومتغير البريد والمعطل، ومراجعة النتائج دون تسجيل tokens.
- [ ] T103 توثيق قرار go/no-go وخطوات rollback الفعلية في `specs/005-unified-access-portal/implementation-notes.md` قبل الإنتاج.
- [ ] T104 إطلاق تدريجي ومراقبة أخطاء SSO والـredirect والتنزيل العام؛ عند الخلل أوقف علم Teams أولاً أو خدمة المواد أو علم البوابة حسب مصدره، ولا تسقط schema في rollback الفوري.
- [ ] T105 بعد الاستقرار، إزالة الشيفرة legacy خلف العلم في إصدار تنظيف مستقل فقط بعد إثبات عدم وجود callers وروابط مطلوبة وتحديث ADR جديد.

## المرحلة 10 — تعديل المتطلبات: إلغاء الضيف وتوحيد واجهة المواد

- [x] T106 إزالة زر الدخول كضيف وإظهار زر مواد مباشر واحد بالنص المعتمد.
- [x] T107 تحويل `guest.php` إلى adapter توافق بلا صفحة أو دور أو جلسة صلاحيات.
- [x] T108 إزالة كتالوج وسياسة ومستودع وإعداد خدمات الضيف وإلغاء رابط إعدادها من الإدارة.
- [x] T109 تحويل `materials.php` إلى بوابة مقدمة ثم `student/materials/` وإعادة واجهة الصف الأصلية داخل `student/materials/view.php`.
- [x] T110 توحيد الظهور على `materials.enabled` ونشاط المرحلة/الصف، والتنزيل على `downloadable` مع احتواء المسار.
- [x] T111 تحديث اختبارات العقود وSQLite والتوثيق وADR-078.
- [x] T112 تنفيذ QA متصفح نهائي للرابط والتحويل وصفحات الفصل والصف والتنزيل قبل النشر.

## ترتيب الاعتماديات

```text
Phase 0
  -> Characterization
  -> Foundation/Data
  -> US1 Unified Portal
      -> US4/US6 Guest + Materials
      -> US5 Intro
  -> US2 Teams Auto SSO
      -> US3 Teams Fallback
  -> Hardening
  -> Staged Rollout
```

- US1 هو MVP المرئي، لكنه لا يُطلق بإتاحة عامة قبل اكتمال حماية المواد المطلوبة.
- US2 يمكن تطويره بعد foundation بالتوازي مع US4 في ملفات مستقلة، لكن الإطلاق يحتاج fallback من US3.
- US5 يعتمد على وجود الوجهات الموحدة `portal` و`materials`.
- كل مرحلة كتابة تعتمد على audit registry وtest DB في المرحلتين 0 و2.

## الحد الأدنى القابل للإطلاق

لا يعتبر مجرد دمج بطاقة الدخول MVP آمناً للإنتاج. الحد الأدنى القابل للإطلاق هو:

1. US1 كاملة؛
2. US4 لخدمة المواد مع default-deny وتنزيل محكوم؛
3. US5 لسياسة المقدمة؛
4. regression التعطيل والتوافق؛
5. أعلام تشغيل واختبارات جودة.

Teams auto SSO يمكن تفعيله لاحقاً بعلم مستقل بعد نجاح US2 وUS3 واختبار Entra الحقيقي.

## مصفوفة تتبع المتطلبات

| المتطلبات | المهام الأساسية |
|---|---|
| FR-001–FR-004، FR-024–FR-025 | T037–T045، T089–T090 |
| FR-005–FR-010، FR-023، FR-026–FR-027 | T046–T063، T088، T091–T092 |
| FR-011–FR-012 | T013، T050، T087 |
| FR-013–FR-018 | T018–T036، T064–T078 |
| FR-019–FR-022 | T079–T086 |
| FR-017 والتدقيق | T029–T036، T066، T071–T078، T098 |
| FR-028 والتراجع | T032، T100–T105 |
