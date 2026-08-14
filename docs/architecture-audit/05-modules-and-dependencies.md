# الوحدات والاعتماديات

## 1. جرد وحدات الأعمال

| الوحدة | الغرض | نقاط دخول ممثلة | خدمات/فئات | جداول ممثلة | أدوار |
|---|---|---|---|---|---|
| الهوية والوصول | login/logout/accounts/SSO/RBAC | `login.php`, `logout.php`, `auth/*`, `admin/*_accounts.php` | `User`, `Utilities`, `MicrosoftSSO`, `StaffPermissionService` | `users`, `staff_roles`, `staff_role_pages`, SSO/audit tables | جميع الأدوار |
| الهيكل الأكاديمي | الأعوام والمراحل والصفوف والفصول والمواد | `admin/academic_years.php`, `stages.php`, `grades.php`, `classes.php`, `subjects.php` | `AcademicYear`, `ClassRoom` | `academic_years`, `stages`, `grades`, `classes`, `subjects` | admin/admin-like |
| الطلاب ودورة القيد | profiles/accounts/enrollment/transfer/graduation/siblings | `admin/students.php`, `student_accounts.php`, `graduate_students.php`, `siblings.php` | `User`, `StudentEnrollment` | `student_profiles`, `student_enrollments`, `student_siblings`, transfers, attachments | admin, student, specialist |
| العاملون والموارد البشرية | profile/account/status/job/attendance/leave/training | `admin/staff.php`, `staff_accounts.php`, `staff_attendance.php`, `leaves.php` | `User`, `StaffAttendanceService`, `StaffLeaveService`, `Training` | `staff_profiles`, status/movements, attendance, shifts, leave, training | admin/admin-like, staff |
| التقييمات والتقارير | schemes/windows/marks/review/publish | `admin/assessment_*.php`, `teacher/assessment_*.php`, `student/reports/published_reports.php` | `AssessmentEngine` | `assessment_*`, `student_marks`, `student_mark_audit`, `report_*`, `published_*` | admin, teacher, student |
| التقييم السلوكي | نقاط وأنواع تقييم وتقارير | `admin/evaluation_*`, `teacher/evaluations.php` | `Evaluation`, `EvaluationType` | `evaluations`, `evaluation_types` | admin, teacher, specialist, student |
| الحضور | حضور الطلاب والعاملين والبصمة | `admin/attendance.php`, `teacher/attendance.php`, `admin/staff_attendance.php` | `StaffAttendanceService`, `ZKTecoDevice` | attendance/biometric tables | admin, teacher, staff |
| المالية | المصروفات والمدفوعات ورواتب العاملين | `admin/fee_*.php`, `admin/staff_financial_data.php` | لا توجد service مالية موحدة مؤكدة | fee tables, `staff_profiles` financial columns | admin/admin-like |
| النقل | الحافلات والتعيينات والقوائم | `admin/buses.php`, `student_buses.php`, `bus_staff.php` | لا توجد service حدودية مؤكدة | buses, assignments, bus_staff | admin |
| العيادة والمكتبة | زيارات وكتب وإعارات وغرامات | `admin/student_clinic.php`, `admin/library.php` | لا توجد service موحدة مؤكدة | clinic/library tables | admin/specialist حسب الصفحة |
| الدروس والمحتوى والاختبارات AI | lesson prep/archive/exam/materials/AI/Canva | `teacher/lesson_prep.php`, `teacher/ajax/*`, `admin/materials_center.php` | AI providers, `LessonGenerator`, `ExamGenerator`, `CanvaIntegration` | ai lessons/exams/materials/templates | teacher, admin, student |
| الإشعارات | notifications, push subscriptions | `admin/notifications.php`, `api/push_*` | `PushNotification` | notifications, targets, reads, subscriptions | عدة أدوار |
| التشغيل والتدقيق | backups, undo, activity, migrations | `admin/manage_backups.php`, `api/undo.php`, `tools/*` | backup classes, `UndoManager`, `ActivityLog` | logs, undo/recycle, schema migrations | admin/CLI |

هذه قائمة وظيفية وليست ادعاء بملكية حصرية لكل جدول؛ الملكية الكاملة لبعض الجداول **غير مؤكدة بعد**.

## 2. طبقات التطبيق الحالية

| الطبقة الفعلية | المجلدات | المشكلة |
|---|---|---|
| Presentation + Controller | role folders وroot | مختلطان في الملف نفسه |
| Application/Business | أجزاء داخل الصفحات + بعض `classes/` | لا توجد حدود موحدة |
| Data Access | PDO داخل الصفحات و`classes/` | SQL منتشر وليس وراء repositories |
| Shared Infrastructure | `config/`, `includes/`, `classes/` | مجلد `classes/` يجمع domain وinfra وutilities |
| Frontend | `assets/` + inline JS/CSS | inline code كبير يضعف reuse/testability |

## 3. مصفوفة الاعتماديات الحالية

الرموز: **مباشر** = اعتماد فعلي شائع، **جزئي** = عبر بعض المسارات، **ممنوع مستهدفًا** = موجود أو محتمل لكنه يجب ألا يستمر.

| المصدر ↓ / الهدف → | Shared auth/http | Services | PDO/DB | وحدات أخرى | Views/assets |
|---|---|---|---|---|---|
| Entry pages | مباشر | مباشر/جزئي | مباشر بكثرة | مباشر عبر الجداول | مباشر |
| Services/classes | جزئي | مباشر بين بعض الفئات | مباشر | مباشر عبر جداول وفئات | يجب ألا يعتمد، لكن بعض التوليد يخرج HTML |
| AJAX/API | مباشر وغير متسق | جزئي | مباشر | مباشر | JSON فقط غالبًا |
| Views داخل pages | نفس الملف | نفس الملف | موجود فعليًا | موجود | مباشر |
| Shared utilities | session/config | بعض الخدمات | مباشر أحيانًا | معرفة أدوار وجداول | HTML في بعض helpers |

## 4. الاعتماديات المشتركة عالية التأثير

| المكوّن | المستهلكون | خطر التغيير |
|---|---|---|
| `config/database.php` | معظم PHP النشط | حرج؛ اتصال كل الوحدات |
| `includes/session_config.php` | كل المسارات المحمية تقريبًا | حرج؛ session/CSRF/timeouts |
| `classes/utilities.php` | جميع الأدوار | حرج؛ auth/redirect/RBAC/helpers/logging |
| `classes/user.php` | الهوية والطلاب والعاملون والحسابات | حرج؛ God class |
| `includes/*_header.php` | صفحات كل دور | عالٍ؛ auth متأخر وعرض/navigation |
| `assets/js/main.js` | معظم الواجهات | عالٍ؛ CSRF/DataTables/modal behavior |
| `AssessmentEngine` | admin/teacher/student assessment | عالٍ لكن حدود أوضح |
| `ActivityLog` | CRUD ووحدات متعددة | عالٍ؛ contract وتنسيق التفاصيل |
| `UndoManager` | CRUD وAPI/recycle bin | عالٍ؛ dynamic table writes |

## 5. الوصول المتقاطع بين الوحدات

- `User` يكتب في `users`, student profiles/enrollments, staff profiles/assignments، ما يجعل الهوية وملفات الأشخاص متشابكة.
- صفحات التقييم تقرأ structure/enrollment/identity مباشرة بالإضافة إلى جداول التقييم.
- `PushNotification` يعتمد على assignments/grades/classes لتحديد المستهدفين.
- المالية الخاصة بالعامل مخزنة في `staff_profiles` بدل جدول/حد مالي مستقل.
- `UndoManager` يصل ديناميكيًا إلى جداول متعددة عبر allow-list.
- dashboards والتقارير تقرأ عدة وحدات مباشرة دون read-model واضح.

## 6. التبعيات الدائرية

لم يثبت cycle على مستوى PHP includes بصورة قاطعة من الفحص الحالي. الخطر الأكبر ليس cycle اسميًا، بل **دورات بيانات وحدود**: pages ↔ shared God classes ↔ جداول وحدات أخرى. لذلك أي ادعاء بوجود circular include محدد مؤجل حتى تحليل graph أكثر دقة.

## 7. حدود مشتركة ينبغي مركزتها

- Auth/session/CSRF/request/response.
- Authorization policies مع adapters للمفاتيح الحالية.
- Schema inspection دون DDL.
- Validation primitives (ID, phone, date, decimal, upload).
- File storage/download authorization.
- Logging/error sanitization.
- Transaction orchestration.

## 8. ما يجب أن يبقى خاصًا بالوحدة

- تطبيع الدرجات وحالات الغياب داخل Assessment.
- lifecycle للعامل داخل Staff/HR.
- enrollment/transfer/graduation داخل Students.
- قواعد الرسوم والرواتب داخل Finance لا داخل Utilities.
- قواعد تعيين المعلم للمادة/الفصل داخل Academic/Assessment boundary.

## 9. اتجاه الاعتماد المستهدف

`Entrypoint/Controller → Application Service → Domain Policy/Validator → Repository → PDO`

ويُسمح لكل الطبقات بالاعتماد على `Shared` بعقود صغيرة. لا تعتمد Domain/Application على HTML أو headers أو superglobals مباشرة، ولا تتصل View بقاعدة البيانات.
