# نتائج تدقيق الهيكل والمسؤوليات

## طريقة التصنيف

- **الشدة:** Critical / High / Medium / Low / Informational.
- **الثقة:** High عندما يؤكدها الكود الحالي مباشرة، Medium عند اعتمادها على حدود نشر غير مثبتة.
- **التنفيذ التلقائي:** نعم فقط إذا كان التغيير محدودًا، متوافقًا، عكوسًا، ولا يتداخل مع ملفات متسخة.

## سجل النتائج

هذا الجدول يحفظ أدلة **خط الأساس 2026-07-12** كما اكتُشفت. حالة المعالجة الحالية موثقة في جدول الإغلاق بعده؛ لا تُقرأ أمثلة الخط الأساس وحدها كحقيقة نهائية.

| ID | الشدة | المكان | الدليل المختصر | الأثر | التصحيح المقترح | الثقة | خطر التنفيذ | اختبارات لازمة | Rollback | تلقائي؟ |
|---|---|---|---|---|---|---|---|---|---|---|
| SEC-001 | Critical | `classes/user.php`, `login.php` | `usernameExists()` يفك `users.password` و`login()` يقارن نصيًا؛ لا يقرأ `password_hash` | توثيق أمني مضلل وإهمال hash في المصادقة | استخدام `verifyStoredPassword()` مع `password_hash` كمسار أساسي، وترقية legacy بعد نجاح موثوق | High | High بسبب ملف متسخ وتأثير كل المستخدمين | login لكل الأدوار، legacy/GCM/hash، inactive/transfer/graduation، SSO unaffected | استعادة الدالتين واختبار قاعدة staging | لا حاليًا |
| SEC-002 | High | POST pages، مثال `admin/classes.php` | auth مبكر لكن لا `requireCsrfPost()` | طلبات state-changing قد تقبل cross-site POST | فاحص شامل ثم إضافة CSRF لكل صفحة مع tokens في كل form | High للمثال، Medium للجرد الكامل | Medium | add/edit/delete/toggle، 419، AJAX headers | إزالة الاستدعاء/tokens للصفحة المعنية | نعم على دفعات بعد جرد form contracts |
| SEC-003 | High | `tools/`, `tests/`, `scratch/`, `tmp/`, `config/`, `database/` | المشروع تحت `htdocs`; `tools/run_migrations.php` بلا CLI/auth و`scratch/check_stats.php` يطبع بيانات | تشغيل أدوات أو كشف بيانات عبر HTTP إذا لم توجد حماية أعلى | deny direct HTTP + CLI guards للكتابات | Medium-High لأن إعداد Apache الأعلى غير مؤكد | Low للحماية المجلدية، Medium للguards | CLI tools تستمر؛ direct HTTP =403؛ includes تعمل | حذف ملفات `.htaccess`/guards | نعم للحماية العكوسة |
| SEC-004 | High | `uploads/students/attachments`, `uploads/staff/attachments` | الصفحات تولد روابط مباشرة إلى الملفات تحت web root | تجاوز تفويض التطبيق والوصول لمرفقات PII عند معرفة URL | storage خاص + download controller مصرح + migration تدريجية | High | High | كل أنواع المرفقات، صلاحيات أدوار، روابط قديمة، range/download | fallback للرابط القديم أثناء الانتقال | لا |
| ARCH-001 | High | `teacher/lesson_prep.php` | 9,026 سطرًا و100+ دالة JS/عدة style/script blocks وSweetAlert | God page وصعوبة اختبار/تعديل | استخراج assets ثم client modules ثم orchestration service | High | High | visual/browser، كل AJAX/AI/export/upload | نقل واحد في كل commit وإعادة include | لا قبل characterization tests |
| ARCH-002 | High | `admin/students.php`, `admin/staff.php`, `classes/user.php` | 7,154/5,040/2,694 سطرًا ومسؤوليات متعددة | coupling ونطاق كسر واسع | فصل validators/services/repositories تدريجيًا مع إبقاء entrypoints | High | High وملفات متسخة | profile modal, import, accounts separation, lifecycle, attachments | adapters إلى التنفيذ القديم | لا حاليًا |
| ARCH-003 | Medium | 18 صفحة assessment/report | 18 نسخة من دالة `*_table_exists()` | duplication وتفاوت fallback | `SchemaInspector::tableExists()` cache per request | High | Medium | missing/present tables، كل صفحات assessment | إعادة الدوال المحلية | نعم بعد اختبار عقد |
| ARCH-004 | Medium | 7 صفحات | `escapeHtml()` مكرر في attendance/biometric/class_lists/staff/students/... | تفاوت escaping وصيانة | helper JS مركزي باسم واضح واختبارات DOM | High | Medium | payloads XSS، null/number/string | إبقاء wrapper محلي مؤقت | لاحقًا |
| VAL-001 | Medium | `admin/students.php`, `admin/staff.php` | نفس قواعد national ID/mobile/landline بدوال مختلفة | إصلاح غير متسق | Validators مشتركة أو module validators | High | Medium | valid/blank/invalid/Unicode digits | wrappers محلية | نعم بعد unit tests |
| DB-001 | High | صفحات وخدمات نشطة متعددة | `ALTER/CREATE TABLE` في request path | locks، implicit commit، drift، صلاحيات DB موسعة | نقل DDL إلى migrations guarded | High | High لكل صفحة | migration fresh/partial/repeat، page smoke | migration rollback موثق؛ fallback مؤقت | لا كدفعة واحدة |
| DB-002 | Medium | `admin/classes.php` | GET يعيد ترتيب `display_order` ويكتب DB | GET غير idempotent وحمل/مفاجآت | migration/maintenance command صريح مرة واحدة | High | Medium | ordering before/after، concurrent requests | إعادة bootstrap مؤقتًا | نعم بعد staging |
| DATA-001 | Medium | `admin/staff_financial_data.php` | profile insert + financial update + activity log دون transaction | بيانات/سجل جزئي عند الفشل | transaction تشمل خطوات DB، logging policy واضحة | High | Low-Medium | create missing profile/update/log failure rollback | إزالة transaction wrapper | نعم |
| AUTH-001 | High | `Utilities`, assessment permissions, custom admin roles | role + effective role + page allow-list + permission keys | صعوبة إثبات التفويض واتساقه | Authorization service يجمع page/domain policies دون تغيير المفاتيح | High | High | matrix لكل دور/صفحة/scope | adapter إلى Utilities القديمة | لا قبل matrix كاملة |
| ERR-001 | Medium | `UndoManager::undo()`, صفحات أخرى | يعيد `$e->getMessage()` للمستخدم/JSON | احتمال كشف SQL/schema | رسالة عامة + server log + request id | High | Low | forced exception response لا يكشف details | إعادة النص القديم | نعم |
| DOC-001 | High | README و`docs/PASSWORD_SECURITY.md` مقابل `User` | الوثائق تقول hash؛ login لا يستخدمه | قرارات على أساس غير صحيح | توثيق drift صريح ثم إصلاح الكود قبل استعادة الادعاء | High | Low للوثائق | cross-check source/test | revert docs | نعم |
| DOC-002 | Medium | `.specify/memory/constitution.md` | قالب placeholders غير مملوء | مصدر توجيه مضلل | إما تعبئته من AGENTS أو وسمه adapter غير مرجعي | High | Low | لا تعارض مع AGENTS | revert | نعم |
| TEST-001 | High | `tests/`, composer scripts | scripts متفرقة ولا runner/DB isolation مؤكد | تغييرات حساسة بلا شبكة أمان كافية | runner يميز Unit/Integration + DB staging guard | High | Medium | command exits، refusal on production DB | remove runner | نعم |
| OPS-001 | High | public document root | source/config/vendor/tools مجتمعة مع public files | ضعف deployment boundary | docroot `public/` كهدف بعيد أو deny rules الآن | Medium-High | High لتغيير docroot | deployment/staging كاملة | config Apache السابق | حماية الآن؛ نقل لاحقًا |
| OPS-002 | Medium | `composer audit` | فشل بسبب timeout | حالة الثغرات الخارجية غير معروفة | إعادة الفحص في CI/شبكة موثوقة وحفظ النتيجة | High | Low | composer audit succeeds | لا تغيير كود | نعم تشغيليًا |
| STRUCT-001 | High | `scratch/students_head.php` | نسخة 6,398 سطرًا من منطق الطلاب داخل web root | duplicate drift ومسار إضافي محتمل | حماية أولًا، إثبات عدم الاستخدام، ثم archive/delete منفصل | High | Medium | link/include search، HTTP 403 | إزالة الحماية/استعادة الملف | حماية نعم، حذف لا |
| UI-001 | Medium | `teacher/lesson_prep.php` | تحميل SweetAlert رغم حظره في AGENTS | انحراف معايير وتبعيات إضافية | تحويل التأكيدات إلى Bootstrap بعد جرد الاستخدام | High | Medium | كل confirm flows | إبقاء library حتى اكتمال التحويل | لا آليًا |
| LOG-001 | Medium | `ActivityLog`, `action_logs`, domain audits | عدة آليات logging بلا عقد موحد | تكرار وفجوات وتفاوت PII | تعريف policy: security/audit/diagnostic، ثم adapters | Medium | High | login/logout/CRUD/domain audit | إبقاء الكتابة المزدوجة مؤقتًا | لا |

## حالة النتائج عند إغلاق الدفعات الآمنة

| النتيجة | الحالة في 2026-07-13 | الدليل |
|---|---|---|
| SEC-002 | مخففة جزئيًا | عولجت `admin/classes.php` واختبارها 9/9؛ بقي 50 مرشحًا للمراجعة اليدوية |
| SEC-003 / OPS-001 | مخففة جزئيًا | ثمانية مجلدات داخلية محمية، migration runner CLI-only، واختبار الحدود 9/9؛ topology و`vendor/phpmyadmin/uploads` غير محسومة |
| ERR-001 | مخففة جزئيًا | Undo/API يحجبان تفاصيل `Throwable` التي تبلغ catches واختبار العقد 11/11؛ `Database::getConnection()` ما زال يملك فرع `die()` عند عرض الأخطاء |
| DOC-001 | صححت الحقيقة التوثيقية فقط | README و`docs/PASSWORD_SECURITY.md` يصفان login المباشر ولا يدعيان hash-first؛ إصلاح المصادقة نفسه مؤجل |
| DOC-002 | مغلقة | دستور Spec Kit v1.0.0 مصدق ومتسق مع أولوية `AGENTS.md` |
| TEST-001 | مخففة جزئيًا | أضيفت بوابات architecture/documentation/contracts بلا DB؛ لا يزال تصنيف/حارس integration DB الشامل مطلوبًا |
| STRUCT-001 | مخففة جزئيًا | `scratch/` محمي من HTTP؛ لم يحدث حذف دون برهان استخدام |

بقية النتائج تبقى مفتوحة أو مؤجلة كما في سجل المخاطر. لا يعني غياب regression في الفاحص أن الدين داخل ملف baselined لم يزد؛ يلزم diff review يدوي.

## نتائج إيجابية يجب الحفاظ عليها

| ID | الدليل | لماذا مهم |
|---|---|---|
| GOOD-001 | `teacher/assessment_marks.php` | auth + CSRF + scope + validation + transaction + domain audit + PRG |
| GOOD-002 | `includes/session_config.php`, `includes/csrf.php` | نقطة مشتركة قابلة للتوحيد بدل إنشاء نظام بديل |
| GOOD-003 | `config/env_loader.php`, `config/database.php` | مصدر إعدادات مشترك واستخدام PDO |
| GOOD-004 | migrations المؤرخة | أساس صالح لإزالة runtime DDL |
| GOOD-005 | `tools/php_lint.php`, `tools/audit_admin_ui.php` | بوابات تحقق قائمة يمكن توسيعها |
| GOOD-006 | `ActivityLog` و`student_mark_audit` | اهتمام فعلي بالتدقيق، ويحتاج فقط سياسة وحدود أوضح |

## Deferred — Requires Specialist Review

- تغيير مصادقة `User::login()` بسبب ملف متسخ وحساسية التوافق مع legacy passwords.
- نقل مرفقات المستخدمين خارج web root.
- نقل `admin/students.php`, `admin/staff.php`, `teacher/lesson_prep.php` أو تقسيمها.
- إعادة تصميم authorization/RBAC.
- إزالة runtime DDL جماعيًا.
- حذف scratch/archive/tmp أو أي endpoint قديم.
