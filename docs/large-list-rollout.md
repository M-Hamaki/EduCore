# ترحيل القوائم الكبيرة إلى تحميل خادمي

## المعيار

تدخل القائمة هذا النمط إذا كانت تنمو مع التشغيل، أو قد تتجاوز 200 سجل، أو تجلب جميع الصفوف قبل أن يفلترها المتصفح. لا يطبق على جداول الإعدادات الصغيرة أو تفاصيل المودالات.

## المنفذ

- `admin/students.php` ومجالات الطلاب المتوافقة: تحميل خادمي عبر `StudentListDataTableQuery`.
- `admin/staff.php`: تحميل خادمي عبر `StaffListDataTableQuery`.
- `admin/new_students.php` و`admin/graduate_students.php`: تحميل خادمي عبر `DerivedStudentListDataTableQuery` مع بقاء بطاقات الملخص وفلاتر كل قائمة.
- `admin/student_accounts.php` و`admin/staff_accounts.php`: تحميل خادمي عبر `AccountListDataTableQuery`. لا يعيد الجدول كلمات المرور أو تشفيرها؛ الكشف الفردي المؤقت فقط يمر عبر `admin/ajax/get_password.php` المسجل أمنيًا.
- `admin/student_clinic.php`: تحميل خادمي لسجل الزيارات والحالة الصحية عبر `ClinicListDataTableQuery`، مع بقاء التعديل والحذف داخل المودالات ومسارات الكتابة الحالية.
- `admin/fee_payments.php`: تحميل خادمي لقائمة المدفوعات فقط؛ تبقى عمليات التحصيل والتوليد والحذف داخل مساراتها المالية المدققة.
- `admin/staff_attendance_audit.php`: تحميل خادمي لسجل التدقيق عبر `StaffAttendanceService::getAttendanceAuditDataTable()`، مع الفلاتر والبحث والبيانات قبل/بعد دون أي تعديل للسجل.
- `admin/assessment_teacher_assignments.php`: تحميل خادمي للعاملين وتعييناتهم عبر `AssessmentTeacherAssignmentListQuery`؛ يبقى نموذج الحفظ وTransaction وسجل العملية كما هي.
- `admin/student_archive.php`: تحميل خادمي للأرشيف عبر `StudentArchiveQuery::loadDataTable()`؛ تبقى حماية الاسترجاع والحذف النهائي وفترة الانتظار كما هي.
- واجهة موحدة: `assets/js/admin-server-side-table.js`؛ تحافظ على رسالة التحميل، CSRF، الفرز، البحث، والصفحات 50/100/200/500/الكل.

## قوائم راجعت ولم تُرحّل

- `admin/activity_logs.php`: يطبق ترقيمًا خادميًا مسبقًا؛ لا يجلب كامل السجل إلى DataTables.
- `admin/notifications.php`: يطبق ترقيمًا خادميًا على قائمة الإشعارات الحالية.
- `admin/staff_attendance.php`: قائمة اليوم جزء من إدخال جماعي للحضور، بينما سجلات الحضور التاريخية مرقمة من الخادم؛ لا يحول نموذج الإدخال إلى جدول Ajax.
- `admin/library.php`: خُفف التحميل بفصل بيانات التبويب الحالي عن بقية التبويبات؛ لا يرحل جدول المكتبة وحده قبل فصل قوائم اختيار الطلاب والكتب والاستعارات في المودالات إلى lookups خادمية مستقلة، حتى لا تتغير عملية الإعارة أو الإرجاع أو تحصيل الغرامة.

## بوابات كل صفحة

- endpoint إداري read-only مع جلسة الإدارة وCSRF في POST.
- لا SQL داخل view، ولا استجابة تتضمن أسرارًا أو hashes أو tokens.
- اختبار عقد للفرز، الفلاتر، أعداد `recordsTotal` و`recordsFiltered`، وإجراءات الصفوف.
- اختبار يدوي للبحث والترقيم و"الكل" وإعادة تهيئة tooltip/popover بعد draw.
- rollback: إلغاء تهيئة DataTables الخاصة بالصفحة وإعادة الاستعلام المحلي فقط؛ لا migration ولا تغيير بيانات.
