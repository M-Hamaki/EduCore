# خارطة طريق التنفيذ

## قواعد عامة لكل مرحلة

- تشغيل `git status --short --branch` قبل وبعد.
- عدم تعديل أو stage أي تغيير سابق غير متعلق.
- `php -l` لكل PHP معدل، ثم `tools/php_lint.php` عند اتساع الأثر.
- تشغيل الاختبارات ذات الصلة فقط على بيانات آمنة؛ اختبارات DB تتطلب staging صريحة.
- `git diff --check` يقيّم diff المرحلة نفسها، مع تسجيل عيوب baseline السابقة منفصلة.
- commit واحد لكل concern ناجح؛ لا push دون طلب صريح.
- التوقف عند أي اختلاف في URL/form/session/API/schema/permission غير موثق.

## مصفوفة النطاق والملفات

هذه المصفوفة جزء ملزم من تعريف كل مرحلة؛ الرمز `—` يعني أن العملية غير مسموحة في المرحلة، وليس أنها لم تُبحث.

| المرحلة | الملفات المتأثرة بدقة | ملفات لا تتأثر | ملفات جديدة | نقل | تقسيم | إهمال/Deprecation | الاعتماديات |
|---|---|---|---|---|---|---|---|
| 0 | `docs/architecture-audit/01-15` | كل PHP/CSS/JS/config/schema/data | 15 ملف تدقيق | — | — | — | Git وقراءة المستودع |
| 1 | `.htaccess` تحت `classes/`, `config/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, `storage/`; `tools/run_migrations.php`; `tests/internal_web_boundary_test.php` | `includes/`, role pages، `assets/`, `uploads/`, schema، `vendor/`, `phpmyadmin/` | 8 ملفات حماية + اختبار عقد | — | — | — | Apache/AllowOverride |
| 2 | `tools/audit_architecture.php`, `tools/architecture_audit_baseline.json`, `composer.json`, `tests/architecture_audit_test.php` | صفحات التطبيق وقاعدة البيانات | الفاحص وbaseline والاختبار | — | — | — | PHP tokenizer/filesystem فقط |
| 3 | `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/coding-rules.md`, `.specify/memory/constitution.md`, قوالب plan/spec/tasks، وثائق البنية/القرارات/checklist، واختبار عقد الوثائق | application behavior/schema | 3 وثائق + اختبار | — | تفصيل القواعد من AGENTS إلى docs ومهايئ Spec Kit دون مصدر موازٍ | constitution template غير المفعّل استُبدل بدستور v1.0.0 | نتائج المرحلتين 0–2 |
| 4 | `admin/classes.php`, `tests/classes_csrf_contract_test.php` | `classes/classroom.php`, DB schema، بقية صفحات admin | اختبار عقد | — | — | — | `includes/csrf.php`, session config |
| 5 | `classes/UndoManager.php`, `api/undo.php`, `tests/undo_error_contract_test.php` | allow-list, schema، مستهلك JavaScript | اختبار عقد | — | — | — | logger/error policy |
| 6 | `admin/staff_financial_data.php`, `tests/staff_finance_transaction_test.php` | UI fields، schema، RBAC، `admin/staff.php` | اختبار integration | — | — | — | DB اختبار و`ActivityLog` connection semantics |
| 7 | مكوّن `SchemaInspector` في موضع يقرره ADR + صفحة assessment واحدة + test | باقي 17 صفحة وruntime DDL | class + unit test | — | helper صفحة واحدة إلى shared component | helper المحلي في الصفحة المهاجرة فقط | PDO/information_schema |
| 8 | Validator عام + tests + صفحة تجريبية نظيفة | `admin/students.php`, `admin/staff.php` حتى clean worktree | validator/tests | — | — | wrapper المحلي بعد إثبات التكافؤ | field contracts الحالية |
| 9 | ملف migration محدد + request file واحد في كل sub-phase | كل الوحدات الأخرى | migration follow-up عند الحاجة | — | DDL من request إلى migration | ensure/ALTER runtime للجزء المثبت فقط | staging DB versions |
| 10 | يحدد per-use-case: يبدأ `admin/classes.php`/Finance ثم Assessment؛ ملفات God pages لاحقًا | أي وحدة غير المرحلة | services/repositories/tests | لا نقل URL | action/method واحد | implementation قديم بعد parity وفترة توافق | characterization tests |
| 11 | download controller + storage adapter + migration manifest + مستهلكو روابط مثبتون | أنواع ملفات/وحدات غير مجرودة | private storage components | محتوى مرفقات تدريجيًا | direct link إلى authorized endpoint | direct-public read بعد اكتمال dual-read | authorization matrix + backup/checksums |
| 12 | docs/log/allow-lists وملفات obsolete مثبتة فقط | business behavior غير متعلق | تقارير إغلاق عند الحاجة | فقط ببرهان caller-free | — | ملفات مثبتة غير مستخدمة في commit مستقل | full test/staging/access logs |

## أوامر التحقق المشتركة

```powershell
git status --short --branch
C:\xampp\php\php.exe -l <كل-ملف-PHP-معدل>
C:\xampp\php\php.exe tools\php_lint.php
C:\xampp\php\php.exe tools\audit_admin_ui.php
git diff --check
git diff --stat
git diff
```

للمرحلة التي تضيف أو تعدل اختبارات، يُشغّل ملف الاختبار المحدد بـ`C:\xampp\php\php.exe`. لا يُشغّل integration test قبل نجاح guard الذي يثبت أن اسم قاعدة الاختبار ليس `educore` وأن البيئة ليست production.

## المرحلة 0 — خط الأساس ووثائق التدقيق

**الهدف:** توثيق الواقع والمخاطر والخطة دون تغيير التطبيق.
**النطاق:** `docs/architecture-audit/01-15`.
**خارج النطاق:** كل PHP/CSS/JS/schema/data.
**ملفات جديدة:** الوثائق الخمس عشرة.
**نقل/تقسيم/إهمال:** لا شيء.
**المخاطر:** توثيق ادعاء غير مثبت أو قديم.
**النسخ الاحتياطي:** Git status/diff يكفي؛ لا بيانات.
**Rollback:** حذف ملفات المرحلة فقط.
**التحقق:** وجود 15 ملفًا، روابط داخلية، Mermaid syntax review، `git diff --check -- docs/architecture-audit`.
**اختبارات آلية:** lint baseline، unit scripts الآمنة.
**يدوي:** مراجعة كل Finding مقابل source.
**القبول:** كل deliverable موجود، unknowns موسومة، لا ملف تطبيق تغير.
**شرط التوقف:** تعارض دليلين لا يمكن حسمه.
**Commit مقترح:** `docs: add evidence-based architecture audit`

## المرحلة 1 — حماية حدود الويب غير العامة

**الهدف:** منع الوصول HTTP المباشر إلى source/tools/tests/temp دون تغيير includes أو CLI.
**الحالة:** مكتملة في commit `9faf9d3` (`security: protect internal web boundaries`).
**النطاق المنفذ:** ملفات حماية صغيرة داخل `config/`, `classes/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, `storage/`، وحارس CLI في `tools/run_migrations.php`، واختبار `tests/internal_web_boundary_test.php`.
**خارج النطاق:** `admin/`, role entrypoints, `assets/`, uploads links، إعداد document root.
**ملفات جديدة:** `.htaccess` موحدة/مكررة بقاعدة `Require all denied` مع توافق Apache المؤكد.
**ملفات معدلة:** `tools/run_migrations.php` لإضافة CLI guard فقط إذا كان الوصول HTTP ممكنًا في الاختبار.
**لا نقل/تقسيم/حذف.**
**الاعتماديات:** Apache AllowOverride؛ include filesystem لا يتأثر.
**المخاطر:** server لا يستخدم Apache أو AllowOverride مغلق؛ الحماية تصبح غير فعالة لا مكسرة.
**Rollback:** حذف ملفات الحماية والـguard المحدد.
**التحقق:** direct HTTP =403؛ `php tools/run_migrations.php` لا يُشغّل على DB الحالية، بل اختبار guard بـweb SAPI/stub أو مراجعة؛ application pages تفتح.
**القبول:** لا direct execution للمجلدات، CLI/lint/includes تعمل.
**شرط التوقف:** include أو asset مشروع موجود في مجلد سيُحظر مباشرة كresource.
**Commit:** `security: deny direct web access to internal project directories`

## المرحلة 2 — فاحص امتثال معماري read-only

**الهدف:** تحويل أهم المخاطر إلى إشارات قابلة للقياس دون فرض إصلاح شامل.
**الحالة:** مكتملة في commit `554a3ec` (`chore: add architecture regression audit`).
**النطاق:** `tools/audit_architecture.php`, `tools/architecture_audit_baseline.json`, `composer.json`, `tests/architecture_audit_test.php`.
**خارج النطاق:** تعديل الصفحات المخالفة.
**الفحوص المنفذة:** ملفات PHP الأكبر من 2000 سطر، runtime DDL داخل النصوص البرمجية، ملفات تعالج POST دون CSRF صريح، تعذر قراءة ملفات PHP، وفقدان حماية مجلدات الداخل.
**المخاطر:** false positives.
**Rollback:** إزالة script وbaseline والاختبار وComposer entry.
**التحقق:** الوضع report يعرض الدين الحالي كاملًا. الوضع strict يستخدم baseline مراجعة كآلية ratchet؛ يفشل عند دخول ملف جديد إلى إحدى مجموعات الدين، أو فقدان حماية مجلد داخلي، أو تعذر القراءة. الاختبار ينشئ baseline مؤقتة ناقصة لإثبات اكتشاف regression، ويتحقق من رفض baseline المفقودة والتالفة.
**القبول:** deterministic output ولا يقرأ `.env` أو DB.
**شرط التوقف:** الفاحص يفسر النصوص/التعليقات كأخطاء بلا طريقة استثناء.
**Commit:** `chore: add read-only architecture compliance audit`

## المرحلة 3 — التعليمات الدائمة ومنع الانحراف

**الهدف:** جعل `AGENTS.md` مصدرًا معماريًا فعليًا وتحديث docs/adapters.
**الحالة:** مكتملة ومثبتة في commit `09872ad`.
**النطاق:** `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/coding-rules.md`, `docs/project-structure.md`, `docs/architecture-decisions.md`, `docs/ai-change-checklist.md`, `.specify/memory/constitution.md`, قوالب Spec Kit الثلاثة، واختبار عقد الوثائق.
**خارج النطاق:** code behavior.
**ملفات جديدة:** ملفات docs الثلاثة واختبار `tests/architecture_documentation_test.php`.
**المخاطر:** تضخم `AGENTS.md` أو تعارض قواعد UI الحالية.
**Rollback:** revert docs commit.
**التحقق:** `composer documentation-audit` يثبت أولوية AGENTS وتطابق PHP والحقائق الأمنية الأساسية والدستور والقوالب؛ مراجعة يدوية تؤكد أن مهايئ Spec Kit يشير إلى AGENTS، ولا أسرار أو أمثلة وهمية.
**القبول:** canonical source واضح ولا تكرار متعارض.
**شرط التوقف:** instruction file format غير مثبت؛ لا يُنشأ.
**Commit:** `docs: establish permanent architecture guardrails`

## المرحلة 4 — CSRF لصفحة الفصول كدفعة نموذجية

**الهدف:** إصلاح workflow واحد مثبت دون مسح جماعي.
**الحالة:** مكتملة في commit `cc8c222` (`security: enforce CSRF on class management writes`).
**النطاق:** `admin/classes.php` واختبار render/POST contract جديد.
**خارج النطاق:** بقية candidate pages و`ClassRoom` business behavior.
**التغيير:** `requireCsrfPost()` بعد auth، token في كل form write، 419 عند invalid.
**المخاطر:** form ديناميكي بلا token.
**Rollback:** revert ملف الصفحة والاختبار.
**التحقق:** add/edit/delete/toggle tokens، guard قبل إنشاء PDO يثبت أن invalid token لا يصل DB، lint/UI audit؛ valid POST integration مؤجل حتى تتوفر قاعدة اختبار محمية.
**القبول:** كل POST action محمي بلا تغيير field names/actions.
**شرط التوقف:** لا يمكن بناء اختبار DB آمن أو يوجد form caller غير معروف.
**Commit:** `security: enforce CSRF on class management writes`

## المرحلة 5 — إخفاء تفاصيل الأخطاء في Undo API

**الهدف:** عدم إرسال exception details للمستخدم.
**الحالة:** مكتملة في commit `ea489e9` (`security: sanitize undo failure responses`).
**النطاق:** `classes/UndoManager.php`, `api/undo.php` واختبار error contract.
**خارج النطاق:** منطق undo والجداول/allow-list ومستهلك JavaScript.
**التغيير:** logging تفصيلي، رسالة عامة ثابتة، وحماية تهيئة endpoint وفشل rollback الثانوي.
**المخاطر:** consumers تعتمد على النص القديم؛ غير متوقع لكن يجب البحث.
**Rollback:** إعادة catch السابق وإزالة catch endpoint والاختبار.
**التحقق:** اتصال زائف يرمي forced exception؛ الاستجابة لا تحتوي SQL/path/password، التفاصيل موجودة في server log، ومفاتيح failure محفوظة. success source path لم يتغير. اختبار endpoint هنا ساكن؛ فشل اتصال DB الفعلي يحتاج بيئة اختبار معزولة.
**القبول:** مسارات `Throwable` التي تبلغ catches الجديدة تعيد JSON آمنًا، وstatus/keys المتفق عليها محفوظة.
**Commit:** `security: sanitize undo failure responses`

## المرحلة 6 — Atomicity للبيانات المالية

**الحالة:** مكتملة في commit `bd1b112`؛ تستعمل الصفحة اتصال PDO نفسه للبيانات و`ActivityLog` داخل transaction واحدة، وتفشل العملية كاملة إذا فشل سجل النشاط.
**الهدف:** جعل إنشاء profile/update/audit عملية ذرية.
**النطاق:** `admin/staff_financial_data.php` واختبار integration على staging.
**خارج النطاق:** schema المالية أو UI أو RBAC.
**التغيير:** begin/commit/rollback حول writes، مع عدم بدء transaction قبل validation/read غير الضروري.
**المخاطر:** `ActivityLog` قد يستخدم connection مختلفة؛ يجب تأكيده أولًا.
**Rollback:** إزالة transaction wrapper.
**التحقق:** success، profile missing، forced update/log failure، rollback.
**القبول:** لا partial profile/data/audit.
**شرط التوقف:** ActivityLog لا يشارك PDO ولا يمكن ضمان atomic audit دون تغيير واسع.
**Commit:** `refactor: make staff finance updates atomic`

## المرحلة 7 — SchemaInspector المشترك

**الحالة:** مكتملة بصيغة `SchemaReadinessGuard` المشتركة مع اختبارات تكامل على قاعدة معزولة؛ اختير حارس readiness بدل مكوّن DDL أو framework موازٍ.
**الهدف:** إزالة تكرار table-exists دون تغيير fallback.
**النطاق الأول:** مكوّن جديد + صفحة assessment واحدة + unit test.
**خارج النطاق:** runtime DDL، باقي 17 صفحة.
**التغيير:** cache per connection/request وprepared information_schema query.
**المخاطر:** اختلاف case/schema/connection.
**Rollback:** wrapper المحلي.
**التحقق:** table exists/missing، repeated call count، target page.
**القبول:** نتائج مطابقة قبل/بعد.
**Commit:** `refactor: centralize schema existence checks`

## المرحلة 8 — Validators مشتركة

**الحالة:** مكتملة بصيغة `ProfileInputValidator` واختبارات الوحدة والتكامل، ثم استُخدمت تدريجيًا في مساري الطلاب والعاملين مع الحفاظ على العقود.
**الهدف:** توحيد national ID/mobile/landline مع wrappers.
**النطاق الأول:** class/functions جديدة + unit tests فقط؛ ثم صفحة واحدة نظيفة.
**خارج النطاق:** dirty `students.php` و`staff.php` حتى عزل التغييرات.
**المخاطر:** اختلاف رسائل/optional semantics.
**Rollback:** wrappers القديمة.
**التحقق:** blank، ASCII digits، Unicode digits policy، length، labels.
**القبول:** نفس قبول/رفض القيم الحالية ورسائل متوافقة.
**Commit:** `refactor: add tested shared identity validators`

## المرحلة 9 — إزالة runtime DDL وحدة بوحدة

**الحالة:** مكتملة للسطح النشط الممسوح؛ نُقلت تغييرات المخطط إلى migrations وأصبح strict audit يعرض `RUNTIME_DDL_FILES=0`، مع حراس readiness بدل الإنشاء وقت الطلب.
**الهدف:** migrations فقط.
**النطاق:** ملف/وحدة واحدة في كل sub-phase؛ يبدأ بعمود/جدول له migration قائم.
**خارج النطاق:** bulk removal.
**المخاطر:** installations partial تفشل بعد حذف fallback.
**التحقق:** fresh DB، DB قديمة جزئيًا، repeat migration، page smoke.
**القبول:** لا DDL في request، رسالة readiness واضحة إذا migration ناقصة.
**شرط التوقف:** لا يمكن إثبات كل deployment versions.
**Commit:** `refactor(<module>): move runtime schema changes to migration`

## المرحلة 10 — استخراج الوحدات الكبيرة

**الحالة:** مكتملة للنطاق الآمن المحدد في هذه الخطة؛ استُخرجت validators ومستودعات الطلاب وخدمات القيد ودورة حياة العاملين وحراس المخطط، ثم نُقلت قواعد تطبيع وتجهيز payload الخاصة بملفي الطلاب والعاملين إلى `StudentProfilePayload` و`StaffProfilePayload`، ونُقل snapshot نشاط العاملين إلى `StaffProfileRepository`. وفي Lesson Prep نُقل تجهيز سياق مزود AI وقوالب Canva/PowerPoint ومعالجة fallback إلى `LessonPrepPageContext`. بقيت entrypoints متوافقة عبر adapters/قوالب العرض الحالية. استمرار كِبر ملفات العرض وCSS/JavaScript المضمن دين presentation ظاهر ضمن baseline، ويتطلب خطة characterization للـDOM والمتصفح مستقلة قبل تقسيمه؛ لا يُعامل اكتمال هذا النطاق كادعاء باختفاء كل large-file debt.
**الترتيب:** Classes/Finance الصغيرة → Assessment helpers → Students/Staff → Lesson Prep.
**الشرط السابق:** characterization/HTTP tests وclean worktree للملف.
**قاعدة:** method/action واحد في كل commit مع adapter قديم.

## المرحلة 11 — التخزين الخاص للمرفقات

**الحالة:** مكتملة لنطاق مرفقات ملفات الطلاب والعاملين. نُفذت طبقة التخزين الخاص والتنزيل الإداري وdual-read، وأُنشئ snapshot خاص ثم نُسخت الملفات الثلاثة (786,853 بايت) مع SHA-256 و0 فشل. أثبت rollback dry-run أهلية الصفوف الثلاثة، وبقيت المصادر القديمة دون حذف، ثم مُنع HTTP المباشر لمجلدي المرفقات القديمة. الأنواع الأخرى من uploads خارج نطاق مرفقات profile ولا تُعامل تلقائيًا بنفس السياسة.
**المطلوب:** inventory، authorization matrix، download controller، dual-read، copy/checksum migration، rollback، ثم deny direct access.

## المرحلة 12 — التحقق النهائي والتنظيف المثبت

**الحالة:** مكتملة في commit `526cc7e` (`docs: close safe architecture hardening phases`)؛ لم يحدث حذف لأن عدم الاستخدام لم يُثبت لأي ملف.
**الهدف:** rescan، tests، docs sync، quality rescore، references/deprecations، commit series review.
**الحذف:** فقط ببرهان عدم الاستخدام ومرحلة مستقلة.
**Commit:** `docs: close safe architecture hardening phases`
