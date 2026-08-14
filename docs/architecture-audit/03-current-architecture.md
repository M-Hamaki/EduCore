# المعمارية الحالية ومسارات الطلب

## 1. النمط الفعلي

المشروع **Modular Monolith بحسب الدور والميزة** من ناحية النشر، لكنه يستخدم داخليًا مزيجًا من:

- **Page Controller:** كل ملف PHP يمثل URL ويتولى الطلب والعرض.
- **Transaction Script:** منطق POST وSQL والتحقق موجود متسلسلًا داخل الصفحة.
- **Service Layer ناشئة:** فئات مثل `AssessmentEngine`, `StaffAttendanceService`, `StaffLeaveService`.
- **Table Data Gateway/Active-Record-like:** فئات مثل `User` و`ClassRoom` تحمل state وتنفذ SQL.
- **Server-rendered views:** HTML في ملف الـcontroller نفسه، مع JavaScript محلي كبير.

لا يوجد MVC كامل، ولا Router مركزي، ولا Repository layer موحدة.

## 2. دورة الطلب المعتادة

1. Apache يصل إلى ملف PHP مباشر.
2. الملف يضم `config/database.php`, `classes/utilities.php`, `includes/session_config.php` وغيرها.
3. الصفحة المحمية تستدعي `Utilities::validateSession(<role>)`؛ بعض المسارات القديمة تعتمد على header متأخر أو تحقق مختلف.
4. يتم إنشاء `Database` وPDO.
5. GET يحمّل البيانات ويعرض HTML، أو POST ينفذ transaction script.
6. المسارات المتوافقة تستخدم PRG ورسائل session؛ غيرها يعيد JSON أو يطبع الرد مباشرة.
7. العرض يكتمل عبر header/footer مشتركين أو HTML مستقل في صفحات الدخول.

## 3. المصادقة والتفويض

### الجلسة

`includes/session_config.php` يحدد خصائص cookie، يطبق idle timeout وتجديد session ID، وينشئ `csrf_token`. يوجد timeout آخر داخل `Utilities::validateSession()` بقيمة مختلفة؛ هذا تكرار في ملكية سياسة الجلسة.

### الدور

`Utilities::validateSession()` يدعم:

- الأدوار الأساسية.
- `super_admin` كبديل admin.
- supervisor يعمل بوضع teacher أو specialist.
- أدوار admin-like ديناميكية عبر `staff_roles` و`staff_role_pages`.

التقييمات تضيف نموذج صلاحيات أكثر دقة عبر `assessment_permissions`. لذلك التفويض الحالي **مختلط** بين role checks وpage allow-list وdomain permissions.

## 4. مسار 1 — تسجيل الدخول التقليدي

| العنصر | التنفيذ المؤكد |
|---|---|
| Entry | `login.php` |
| Session/CSRF | `includes/session_config.php` ثم مقارنة `$_POST['csrf_token']` |
| DB | `config/database.php` |
| Credential lookup | `User::usernameExists()` في `classes/user.php` يقرأ `users` ويمنع حسابات غير نشطة/منقولة/متخرجة |
| Credential decision | `PasswordAuthenticator` يجعل `password_hash` ملزمًا، ويرقي legacy الناجح خلال نافذة البيئة |
| Student scope | join من `users` إلى `classes`, `grades`, `stages` للتحقق من صفحة المرحلة |
| Session success | تجديد ID ثم `user_id`, `name`, `role`, `is_supervisor`, وأحيانًا `class_id`, `student_stage` |
| Audit | `Utilities::logAction()` و`ActivityLog::logLogin()` |
| Redirect | `Utilities::getDashboardUrl()` |
| Failure | رسائل للحساب المعطل/المنقول/المتخرج أو بيانات غير صحيحة |

### ملاحظات

- `User::login()` و`User::verifyPassword()` يستخدمان adapter واحدًا، وفشل hash الموجود لا يرجع إلى الغلاف القديم.
- إغلاق legacy fallback يتم عبر `PASSWORD_LEGACY_LOGIN_ENABLED=false` بعد قياس الحسابات النشطة بلا hash.
- مقارنة CSRF في `login.php` تستخدم `!==` لا `hash_equals()`.

## 5. مسار 2 — تسجيل الخروج

| العنصر | التنفيذ المؤكد |
|---|---|
| Entry | `logout.php` عبر GET عمليًا |
| Auth | لا يشترط login؛ يسجل action إن وُجد `user_id` |
| DB | يكتب `action_logs` للمستخدمين غير external |
| Session | `session_start()` ثم `session_destroy()` |
| Redirect | يبني URL من protocol و`HTTP_HOST` ثم يوجه إلى index أو external login/Teams |

### ملاحظات

- لا يوجد CSRF logout؛ الأثر الأساسي forced logout.
- لا يمسح cookie صراحة كما يفعل timeout في `session_config.php`.
- من الأفضل استخدام URL موثوق من config بدل بناء redirect من `HTTP_HOST` غير الموثوق.

## 6. مسار 3 — CRUD للفصول

| العنصر | التنفيذ المؤكد |
|---|---|
| Entry | `admin/classes.php` |
| Auth | `Utilities::validateSession('admin')` قبل POST |
| CSRF | `requireCsrfPost()` بعد auth وقبل DB/POST؛ token موجود في نماذج add/edit/delete/toggle |
| Model/Gateway | `classes/classroom.php` (`ClassRoom`) |
| Create/update | `classes`؛ ربط اختياري بـ`academic_year_id` |
| Delete guard | `student_enrollments` أو `users`, ثم `evaluations` داخل `ClassRoom::delete()` |
| Audit/undo | `ActivityLog`, `UndoManager` |
| Response | PRG إلى `classes.php` ورسائل session |

### ملاحظات

- الصفحة تنفذ write أثناء GET العادي لترتيب `display_order` إذا وجدت قيمًا صفرية؛ هذا side effect غير متوقع للعرض.
- التحقق من المدخلات يعتمد أساسًا على `strip_tags/htmlspecialchars` داخل `ClassRoom`، وليس Validator لعقد الاسم/grade.
- `htmlspecialchars` خاص بالإخراج، وليس آلية صحيحة لتطبيع بيانات المجال قبل التخزين.
- حماية CSRF لهذا workflow أضيفت في commit `cc8c222` واختبار العقد يحمي ترتيب auth → CSRF → DB؛ إعادة الترتيب عبر `api/reorder.php` endpoint مستقل وما زال ضمن الجرد اليدوي.

## 7. مسار 4 — رصد درجات المعلم

| العنصر | التنفيذ المؤكد |
|---|---|
| Entry | `teacher/assessment_marks.php` |
| Auth/CSRF | `validateSession('teacher')` ثم `requireCsrfPost()` |
| Scope | نافذة مفتوحة + العام الحالي + تعيين teacher/subject/grade/class + `can_record` |
| Student scope | `student_enrollments` للعام الحالي مع fallback سابق |
| Locks | `AssessmentEngine::getLockedStudentIds()` |
| Validation | `AssessmentEngine::normalizeMarkInput()` وحدود max/absence |
| Data | `student_marks`, `student_mark_audit` |
| Delete permission | `assessment_permissions` عبر `userHasAnyPermissionRole()` |
| Atomicity | transaction واحدة لكل الدفعة مع rollback عند أي خطأ |
| Audit | mark audit داخل transaction ثم `ActivityLog` بعد commit |
| Response | PRG مع window/class محفوظين |

هذا هو أفضل مثال حالي على workflow حساس قريب من المعمارية المستهدفة، رغم أن الصفحة ما زالت تحتوي helpers وSQL كثيرًا كان يمكن نقله إلى service/repository.

## 8. مسار 5 — البيانات المالية للعاملين

| العنصر | التنفيذ المؤكد |
|---|---|
| Entry | `admin/staff_financial_data.php` |
| Auth | admin أو admin-like له الصفحة في `staff_role_pages` |
| CSRF | `hash_equals()` يدوي |
| Validation | أرقام غير سالبة + تنظيف JSON lists |
| Data | `users`, `staff_profiles` |
| Write | إنشاء profile إن غاب، ثم update للحقول المالية |
| Audit | `ActivityLog::logUpdate('staff_financial', ...)` |
| Response | PRG مع `staff_id` |

### ملاحظات

- إنشاء profile والتحديث وتسجيل activity ليست داخل transaction؛ فشل خطوة لاحقة قد يترك حالة جزئية.
- لا يظهر Permission key خاص بالرواتب؛ التحكم هو page-level لا field/domain-level.
- الرسالة تعرض نص exception للمستخدم، ما قد يكشف تفاصيل غير مناسبة إذا جاء الاستثناء من PDO.

## 9. مسار 6 — API التراجع

| العنصر | التنفيذ المؤكد |
|---|---|
| Entry | `api/undo.php` |
| Auth | وجود `$_SESSION['user_id']` |
| CSRF | مطلوب لـPOST ويستخدم `hash_equals()` |
| Contract | JSON، مع 401/403/405/400، و500 لمسارات `Throwable` التي تبلغ catch العام |
| Read | `action=check` يجلب آخر سجل من `undo_log` للمستخدم |
| Write | `action=undo` → `UndoManager::undo($userId)` |
| Atomicity | transaction حول restore/delete/update ووسم `undo_log` |
| Conflict | يقارن new_data بالحالة الحالية قبل التراجع |
| Tables | allow-list داخل `UndoManager`, إضافة إلى `undo_log` و`recycle_bin` |

### ملاحظات

- `UndoManager::undo()` ومسارات `Throwable` التي تبلغ catch في `api/undo.php` تعيد الآن رسالة عامة مع إبقاء التفاصيل في server log؛ فشل endpoint غير المعالج يعيد HTTP 500 بعقد `success/message`.
- فرع `Database::getConnection()` الذي يستخدم `die()` عند تفعيل `display_errors` لا يمكن اعتراضه داخل endpoint، ويبقى دين error-policy منفصلًا؛ production يجب أن يبقي عرض الأخطاء معطلًا.
- `ensureTable()` ينشئ جداول في runtime، وهو خلط بين التشغيل وschema migration.

## 10. API/AJAX مقارنة بالصفحات العادية

- الصفحات العادية تميل إلى redirect ورسائل session وHTML.
- endpoints تعيد JSON، لكن status codes وشكل الخطأ وبدء الجلسة ليست موحدة.
- لا يوجد middleware واحد يفرض auth/CSRF/content-type/error contract.
- frontend العام يضيف CSRF لبعض الطلبات، لكن الأمان يجب أن يبقى server-side في كل endpoint.

## 11. معالجة الأخطاء والتسجيل

- يوجد `error_log()` في عدة مواضع و`ActivityLog`/`action_logs`/domain audits.
- بعض الصفحات تلتقط `Throwable` وتعرض `$e->getMessage()` للمستخدم.
- بعض DDL runtime يبتلع الاستثناء تمامًا.
- لا توجد Error Handler مركزية مؤكدة ولا correlation/request ID.

## 12. حدود مؤكدة وغير مؤكدة

- مخطط النشر الإنتاجي خارج XAMPP: **غير مؤكد**.
- هل توجد قواعد Apache أعلى الجذر تحمي المجلدات: **غير مؤكد**؛ لا يجوز افتراضها.
- خريطة endpoints كاملة: **لم تكتمل بعد**؛ الجرد يغطي الأنماط والمخاطر الرئيسية.
- خريطة كل FK وtable owner: **غير مؤكدة بالكامل**.
