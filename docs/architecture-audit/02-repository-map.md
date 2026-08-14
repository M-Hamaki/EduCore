# خريطة المستودع

## 1. حدود المستودع

- الجذر المؤكد: `C:\xampp\htdocs\EduCore`.
- بيئة التشغيل المحلية: XAMPP، وPHP CLI عند `C:\xampp\php\php.exe`.
- جذر المشروع موجود تحت `htdocs`؛ لذلك كل مجلد غير محمي داخله يُعامل كمرشح للوصول المباشر عبر الويب.
- الفرع وقت خط الأساس: `main`، ومتقدم محليًا عن `origin/main` بعملية واحدة.
- كانت الشجرة غير نظيفة قبل هذا التدقيق. التغييرات السابقة محمية ولا تدخل في نطاق commits المعمارية تلقائيًا.

## 2. الحجم التقريبي للكود النشط

أظهر الجرد في 2026-07-12:

| المجموعة | ملفات PHP | أسطر |
|---|---:|---:|
| `admin/` | 127 | 86,852 |
| `teacher/` | 45 | 31,375 |
| `classes/` | 35 | 21,823 |
| `includes/` | 19 | 6,103 |
| `specialist/` | 4 | 5,016 |
| `student/` | 12 | 4,848 |
| `auth/` | 5 | 1,114 |
| `supervisor/` | 1 | 647 |
| `external/` | 1 | 631 |
| `ajax/` | 3 | 394 |
| `api/` | 5 | 322 |

هذه الأرقام لا تشمل `archive/`, `vendor/`, `phpmyadmin/`، ولا تعني أن كل سطر مستخدم فعليًا.

## 3. الملفات الأعلى خطورة بالحجم

| الملف | الأسطر وقت الجرد | الدور المعماري الفعلي |
|---|---:|---|
| `teacher/lesson_prep.php` | 9,026 | View ضخمة + JavaScript + تنسيق + تنسيق عقود AJAX/AI وتصدير |
| `admin/students.php` | 7,154 | Page controller + profiles + imports + attachments + HTML/JS |
| `admin/staff.php` | 5,040 | Page controller + HR profile + lifecycle + uploads + HTML/JS |
| `classes/ExamGenerator.php` | 2,760 | توليد اختبارات متعددة المسؤوليات |
| `teacher/lesson_view.php` | 2,715 | عرض وتفاعل درس |
| `classes/user.php` | 2,694 | هوية + حسابات + طلاب + عاملون + تسجيلات + بحث + علاقات |
| `admin/index.php` | 2,521 | Dashboard واستعلامات إحصائية متعددة |
| `includes/ajax_handlers.php` | 2,031 | Dispatcher مركزي لعدة إجراءات غير مترابطة |

## 4. المجلدات الرئيسية ومسؤوليتها الحالية

| المجلد | المسؤولية الحالية | ملاحظات الحدود |
|---|---|---|
| `admin/` | صفحات الإدارة لكل الوحدات | معظم الملفات Page Controllers، وبعضها ينفذ SQL وDDL وعرضًا في الملف نفسه |
| `teacher/` | بوابة المعلم والدروس والتقييمات والحضور | يحتوي صفحات وAJAX ووظائف AI كبيرة |
| `student/` | بوابة الطالب والتقارير والمواد والجدول | بعض التقارير تحت `student/reports/` |
| `specialist/` | بوابة الأخصائي والتحليلات والطلاب | تعتمد على `Utilities` وتصل للجداول مباشرة |
| `supervisor/` | اختيار وضع المشرف | التحويل الفعلي بين teacher/specialist يعتمد على session |
| `external/` | بوابة المعلم الخارجي | مسار هوية منفصل جزئيًا |
| `auth/` | Microsoft/Teams SSO | نقطة حساسة مستقلة عن تسجيل الدخول التقليدي |
| `api/` | JSON endpoints مستقلة | لكل ملف عقده الأمني والاستجابي الخاص |
| `ajax/` | AJAX مشترك | لا توجد طبقة endpoint موحدة لكل الأدوار |
| `admin/ajax/`, `teacher/ajax/` | AJAX خاص بالدور | تفاوت في auth/CSRF/error contracts |
| `classes/` | خليط Models/Services/Utilities/Gateways | ليس طبقة Domain صافية؛ أغلب الفئات تستخدم PDO مباشرة |
| `includes/` | session, CSRF, headers/footers, helpers, dispatcher | Infrastructure وعرض مختلطان في المجلد نفسه |
| `config/` | environment, DB, AI, SSO, encryption | مصدر مشترك داخل web root ومحمي الآن من HTTP المباشر بـ`.htaccess`؛ يلزم إثبات إعداد production |
| `database/migrations/` | migrations المؤرخة | المسار المقصود لتغييرات schema، لكنه ليس المسار الوحيد حاليًا |
| `assets/` | CSS/JS/images المشتركة | توجد أيضًا CSS/JS ضخمة داخل الصفحات |
| `uploads/` | ملفات المستخدم والتصدير والمحتوى | موجود داخل web root؛ بعض مرفقات الأفراد تُربط مباشرة |
| `storage/` | backups/exports/templates | محمي من HTTP المباشر، لكنه غير مستخدم لكل الملفات الحساسة |
| `tests/` | اختبارات وحدوية وتكاملية scripts | محمي من HTTP؛ لا يوجد test runner موحد، وبعض الاختبارات يتصل بقاعدة `educore` |
| `tools/` | lint, migrations, إصلاحات، seed | محمي من HTTP؛ migration runner له CLI guard، وباقي أدوات الكتابة تحتاج جردًا مستقلًا |
| `docs/` | وثائق تشغيل وميزات وعمارة | بها وثائق مفيدة وأخرى تاريخية أو متعارضة |
| `archive/` | ملفات قديمة محمية بـ`.htaccess` | الحماية موجودة؛ لا يُعتمد على محتواه كعمارة نشطة |
| `scratch/`, `tmp/` | تشخيص ونسخ مؤقتة | داخل web root لكنهما محميان من HTTP المباشر؛ لا يُحذف المحتوى قبل إثبات عدم الاستخدام |

## 5. نقاط الدخول العامة

- عامة: `index.php`, `login.php`, `logout.php`, `public_portal.php`, `privacy.php`, `terms.php`, `verify_certificate.php`.
- صفحات حسب الدور: ملفات PHP المباشرة تحت `admin/`, `teacher/`, `student/`, `specialist/`, `supervisor/`, `external/`.
- SSO: ملفات `auth/*.php`.
- JSON/AJAX: `api/*.php`, `ajax/*.php`, `admin/ajax/*.php`, `teacher/ajax/*.php`، إضافة إلى dispatcher في `includes/ajax_handlers.php`.
- لا يوجد Front Controller أو Router مركزي؛ اسم الملف هو route في معظم الحالات.

## 6. البنية المشتركة

- الاتصال: `config/database.php` → `Database::getConnection()` → PDO.
- البيئة: `config/env_loader.php` و`.env`.
- الجلسة: `includes/session_config.php`.
- CSRF: `includes/csrf.php` ودعم frontend في `assets/js/main.js`.
- المصادقة والتوجيه: `classes/utilities.php`.
- الهوية والحسابات: `classes/user.php`.
- السجل والتراجع: `classes/ActivityLog.php`, `classes/UndoManager.php`.
- Header/Footer حسب الدور: `includes/*_header.php`, `includes/*_footer.php`.

## 7. المكتبات والاعتماديات

`composer.json` يطلب PHP 8.0+ وPDO/JSON/cURL، ويستخدم PhpSpreadsheet, PhpPresentation, Dompdf, JWT وWeb Push. Composer autoload الحالي `classmap` على `classes/`؛ لا يوجد PSR-4 للوحدات بعد.

لا يوجد `package.json` للتطبيق في الجذر. وجود `phpmyadmin/package.json` لا يجعله build system للمشروع.

## 8. قاعدة البيانات

- MySQL/MariaDB وPDO، بلا ORM.
- schema أولي في `database_complete.sql`.
- migrations مؤرخة في `database/migrations/` وتشغّلها `tools/run_migrations.php`.
- توجد جداول ووحدات تنشئ أو تعدل schema في runtime؛ هذا انحراف عن المسار المقصود.
- خريطة الجداول الكاملة وعلاقات FK لم تُثبت كاملة بعد؛ ما هو غير مثبت موسوم بذلك في بقية الوثائق.

## 9. الاختبارات وأدوات التحقق

- `tools/php_lint.php`: lint لكل PHP النشط باستثناء vendor/archive/phpmyadmin.
- `tools/audit_admin_ui.php`: قواعد واجهة الإدارة.
- `tools/audit_architecture.php`: فاحص read-only صارم مع baseline path-level للدين الحالي.
- `tests/architecture_audit_test.php`, `tests/internal_web_boundary_test.php`, `tests/architecture_documentation_test.php`: اختبارات حدود ومعمارية ووثائق بلا DB.
- اختبارات script-style تحت `tests/`؛ لا PHPUnit config مؤكد.
- بعض الاختبارات وحدوية/in-memory، وبعضها يفتح transaction على قاعدة التطبيق ثم rollback.
- `composer audit` موجود كسكربت، لكنه يعتمد على الاتصال الخارجي.

## 10. ملفات التوجيه للذكاء الاصطناعي

- المصدر الأعلى والوحيد للتعليمات الملزمة: `AGENTS.md`.
- `.specify/memory/constitution.md` دستور Spec Kit مصدّق v1.0.0 ومهايئ متسق؛ لا يتجاوز أولوية `AGENTS.md`.
- `.vscode/settings.json` يضبط PHP فقط ولا يحتوي قواعد معمارية.
- لم يُثبت وجود adapters فعالة لـClaude/Gemini/Copilot/Cursor؛ لا ينبغي اختراع تنسيقات غير مستخدمة دون حاجة.

## 11. تحقق خريطة الإغلاق

- راجعت الخريطة في 2026-07-13 بعد الدفعات الآمنة؛ لم تُنقل أو تُقسّم أو تُحذف أو تُهمل ملفات تشغيلية.
- ظلت نقاط الدخول وURLs والمجلدات الفعلية كما هي؛ التغييرات أضافت حواجز واختبارات ووثائق فقط، مع تعديلين محدودين داخل `admin/classes.php` وUndo.
- أرقام الحجم أعلاه تظل **خط أساس 2026-07-12** وليست جردًا لحظيًا لكل تغييرات المستخدم غير الملتزم بها؛ الفاحص النهائي هو المرجع المتكرر لفئات الدين الثلاث.
