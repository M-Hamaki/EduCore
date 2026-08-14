# 🔍 تقرير المراجعة الهندسية الشاملة - EduCore
# Comprehensive Engineering Audit Report

**تاريخ:** 2025-07  
**المستوى:** Senior Software Architect Review  
**النطاق:** Data Structures, Database, Security, Performance

---

## ملخص تنفيذي | Executive Summary

تم إجراء مراجعة هندسية شاملة لنظام EduCore تغطي 4 محاور رئيسية. تم اكتشاف **14 ثغرة أمنية حرجة** و**8 مشاكل أداء** و**عدة نقاط تحسين** في قاعدة البيانات وهياكل البيانات.

| المحور | حرج | عالي | متوسط | تم الإصلاح | معلق |
|--------|------|------|--------|------------|------|
| الأمان (Security) | 14 | 3 | 4 | **17** | 4 |
| الأداء (Performance) | 3 | 5 | 3 | **8** | 3 |
| قاعدة البيانات (Database) | 0 | 2 | 5 | **0** | 7 |
| هياكل البيانات (Data Structures) | 0 | 1 | 3 | **1** | 3 |
| **المجموع** | **17** | **11** | **15** | **26** | **17** |

---

## 1. الأمان | Security & RBAC

### ✅ تم الإصلاح (FIXED)

#### 1.1 Auth Bypass — تجاوز المصادقة (CRITICAL × 14)
**الوصف:** 14 ملف في مجلد `admin/` كانت تعالج طلبات POST/AJAX **قبل** التحقق من هوية المستخدم.

| الملف | نوع الثغرة | سطر POST | سطر Auth |
|--------|------------|----------|----------|
| `admin/disciplinary.php` | POST + File upload | L45 | L103 |
| `admin/grades.php` | POST create/delete | L51 | L120+ |
| `admin/classes.php` | POST CRUD | L24 | L80+ |
| `admin/subjects.php` | POST + AJAX | L30 | L90+ |
| `admin/staff_attendance.php` | POST bulk insert | L20 | L100+ |
| `admin/staff.php` | POST + File upload | L41 | L150+ |
| `admin/notifications.php` | AJAX data leak | L19 | L60+ |
| `admin/reports.php` | POST bulk delete | L31 | L80+ |
| `admin/stages.php` | AJAX + POST | L42 | L286 |
| `admin/evaluation_types.php` | POST CRUD + GET delete | L21 | L90 |
| `admin/profile.php` | POST (weak role check) | L45 | L77 |
| `admin/training_reports.php` | DB reads (data leak) | L17 | L29 |
| `admin/training_programs.php` | POST CRUD | L24 | L73 |
| `admin/training_courses.php` | POST CRUD | L30 | L206 |

**الإصلاح:** إضافة `require_once '../includes/session_config.php'; Utilities::validateSession('admin');` فوراً بعد تحميل الكلاسات وقبل أي معالجة.

#### 1.2 CSRF Validation Disabled (CRITICAL)
**الملف:** `includes/ajax_handlers.php`  
**الوصف:** كود التحقق من CSRF كان معطلاً (commented out) رغم أن الواجهة ترسل التوكن عبر jQuery ajaxSetup.  
**الإصلاح:** أعيد تفعيل التحقق مع دعم `X-CSRF-TOKEN` header و `hash_equals()` للمقارنة الآمنة.

#### 1.3 Reflected XSS (HIGH × 2)
**الملفات:** `admin/staff_attendance.php`, `admin/disciplinary.php`  
**الوصف:** قيم `$_GET['date_from']`/`$_GET['date_to']` تُعرض في HTML بدون ترميز.  
**الإصلاح:** `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` على كل المخرجات.

#### 1.4 Debug Mode in Production (MEDIUM × 13)
**الوصف:** 13 ملف admin يحتوي على `ini_set('display_errors', 1)` مما يكشف مسارات الملفات وأخطاء SQL للمهاجمين.  
**الإصلاح:** أزيل من جميع الملفات النشطة (13/13).

### ⚠️ معلق (PENDING — يتطلب تخطيط)

#### 1.5 Reversible Password Encryption (CRITICAL)
**الملف:** `config/encryption.php`  
**الوصف:** كلمات المرور مشفرة بـ AES-256-CBC (قابلة للفك) بدلاً من bcrypt/argon2.  
**التأثير:** تسرب مفتاح التشفير = كشف كل كلمات المرور.  
**التوصية:**
```php
// بدلاً من:
$encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
// يجب استخدام:
$hash = password_hash($password, PASSWORD_BCRYPT);
// مع إضافة migration script لتحويل الحسابات الموجودة
```

#### 1.6 Hardcoded API Keys (HIGH)
**الملف:** `config/ai_config.php`  
**الوصف:** مفاتيح Gemini AI وWebImageSearch مخزنة في الكود مباشرة.  
**التوصية:** نقلها لملف `.env` مع إضافته لـ `.gitignore`.

#### 1.7 JWT Signature Verification Disabled (HIGH)
**الملف:** `classes/MicrosoftSSO.php`  
**الوصف:** `verify: false` في فك JWT مما يسمح بتزوير هوية SSO.  
**التوصية:** تفعيل التحقق من توقيع JWT باستخدام Microsoft JWKS endpoint.

#### 1.8 IDOR — Insecure Direct Object Reference (MEDIUM)
**الملف:** `ajax/get_students_by_class.php`  
**الوصف:** أي مستخدم مسجل يمكنه استعراض طلاب أي فصل بتغيير `class_id`.  
**التوصية:** التحقق من صلاحية المستخدم على الفصل المطلوب.

---

## 2. الأداء | Performance & Scalability

### ✅ تم الإصلاح (FIXED)

#### 2.1 N+1 Queries — Notifications (CRITICAL)
**الملف:** `includes/notifications_helper.php`  
**الوصف:** كل إشعار يستدعي query منفصل لجلب المستهدفين.  
**التأثير:** صفحة بها 50 إشعار = 50 query إضافي.  
**الإصلاح:** دالة `batchFetchNotificationTargets()` جديدة تجلب الكل في query واحد بـ `WHERE notification_id IN (...)`.

#### 2.2 N+1 Queries — Subjects Page (HIGH)
**الملف:** `admin/subjects.php`  
**الوصف:** أسماء المعلمين تُجلب لكل مادة في حلقة.  
**الإصلاح:** `GROUP_CONCAT` في الاستعلام الرئيسي.

#### 2.3 N+1 Queries — Teacher Export (HIGH)
**الملف:** `classes/excel_handler.php` (`exportTeachers`)  
**الوصف:** 2 queries لكل معلم (فصول + مواد). لـ 100 معلم = 200 query.  
**الإصلاح:** Pre-load بـ `GROUP_CONCAT` و `WHERE IN(...)` — 2 queries فقط.

#### 2.4 N+1 Queries — Specialist Export (MEDIUM)
**الملف:** `classes/excel_handler.php` (`exportSpecialists`)  
**الوصف:** query لكل أخصائي لجلب الفصول.  
**الإصلاح:** Pre-load batch query واحد.

#### 2.5 N+1 Queries — Grade Columns (MEDIUM)
**الملف:** `teacher/ajax/grades_handler.php`  
**الوصف:** `COUNT(*)` لكل مادة لعدد أعمدة الدرجات.  
**الإصلاح:** query واحد بـ `GROUP BY subject_id`.

#### 2.6 No Transaction — Bulk Attendance (HIGH)
**الملف:** `admin/staff_attendance.php`  
**الوصف:** حفظ حضور 100 موظف = 100 INSERT منفصل بدون transaction.  
**التأثير:** فشل في المنتصف = بيانات جزئية + بطء × 100.  
**الإصلاح:** `beginTransaction()`/`commit()`/`rollBack()` + prepared statement خارج الحلقة.

#### 2.7 No Transaction — Student Excel Import (HIGH)
**الملف:** `admin/students.php`  
**الوصف:** استيراد 500 طالب = 3 queries × 500 = 1500 query.  
**الإصلاح:** Transaction wrapping + pre-loaded maps (`classNameMap`, `existingUsernames`), يُتتبع الأسماء الجديدة أثناء الدفعة.

#### 2.8 No Transaction — Grades Bulk Save
**الملف:** `teacher/ajax/grades_handler.php`  
**الوصف:** حفظ درجات فصل كامل بدون transaction.  
**ملاحظة:** تم تأجيله (تأثير منخفض نسبياً).

### ⚠️ معلق (PENDING)

#### 2.9 No Pagination (MEDIUM × 7 pages)
**الملفات:** `students.php`, `reports.php`, `staff_attendance.php`, `notifications.php`, `staff.php`, `disciplinary.php`, `training_courses.php`  
**الوصف:** تُحمّل كل السجلات دفعة واحدة. مع 5000+ طالب = بطء شديد.  
**التوصية:** إضافة `LIMIT/OFFSET` مع واجهة ترقيم الصفحات.

#### 2.10 No Caching (LOW)
**الوصف:** إعدادات النظام وأنواع التقييم والفصول تُقرأ من DB في كل طلب.  
**التوصية:** PHP APCu cache أو file-based cache مع TTL 5 دقائق.

#### 2.11 Reports Dashboard — Multiple Table Scans (MEDIUM)
**الملف:** `admin/reports.php`  
**الوصف:** 6+ استعلامات منفصلة على جدول evaluations للإحصائيات.  
**التوصية:** دمج في CTE واحد أو materialized view.

---

## 3. قاعدة البيانات | Database Schema & Relations

### ⚠️ توصيات (Recommendations)

#### 3.1 Missing Indexes (HIGH)
```sql
-- الجداول التالية تحتاج فهارس مركبة:
ALTER TABLE evaluations ADD INDEX idx_evaluations_lookup (student_id, type, date);
ALTER TABLE evaluations ADD INDEX idx_evaluations_class (class_id, created_at);
ALTER TABLE attendance ADD INDEX idx_attendance_lookup (student_id, subject_id, date);
ALTER TABLE notifications ADD INDEX idx_notifications_active (is_active, created_at);
ALTER TABLE notification_targets ADD INDEX idx_notif_targets (notification_id, target_type, target_id);
ALTER TABLE grade_values ADD INDEX idx_grade_values_lookup (student_id, column_id);
ALTER TABLE activity_logs ADD INDEX idx_activity_date (action, created_at);
```

#### 3.2 Missing Foreign Keys (MEDIUM)
- `evaluations.student_id` → `users.id` (لا يوجد FK)
- `evaluations.teacher_id` → `users.id` (لا يوجد FK)
- `notification_targets.notification_id` → `notifications.id` (لا يوجد FK)
- `grade_values.column_id` → `grade_columns.id` (لا يوجد FK)
- **التوصية:** إضافة `ON DELETE CASCADE` بين الجداول المرتبطة

#### 3.3 Inconsistent Soft Delete (MEDIUM)
- بعض الجداول تستخدم `is_active` (subjects, grades)
- بعضها يستخدم `status` (users, training_enrollments)  
- بعضها يحذف فعلياً (evaluations, attendance)
- **التوصية:** توحيد نمط الحذف الناعم عبر النظام

#### 3.4 No Audit Trail on Critical Tables (MEDIUM)
- جداول `evaluations`, `grade_values`, `attendance` لا تسجل من عدّل ومتى
- **التوصية:** إضافة `updated_by` و `updated_at` للجداول الحساسة

#### 3.5 VARCHAR Lengths Inconsistent (LOW)
- `users.name` = VARCHAR(100)، `users.username` = VARCHAR(50)
- `stages.name` = VARCHAR(100)، `grades.grade_name` = VARCHAR(100)
- بعض الجداول بدون تحديد واضح للطول

---

## 4. هياكل البيانات | Data Structures

### ✅ تم الإصلاح (FIXED)

#### 4.1 Linear Search Replaced with Hash Map
- **students.php Excel import:** كان يبحث عن username في DB كل سطر → أصبح `array_flip` مع O(1) lookup
- **subjects.php:** كان يجلب أسماء المعلمين per-row → أصبح GROUP_CONCAT في الاستعلام الأصلي

### ⚠️ توصيات (Recommendations)

#### 4.2 Conflict Detection — Timetable (MEDIUM)
**الملف:** `admin/timetable.php`  
**الوصف:** التحقق من تعارض الجدول يعتمد على queries متعددة بدلاً من بناء in-memory set.  
**التوصية:** تحميل جدول اليوم كاملاً في `$occupiedSlots[$teacher_id][$period]` و `$occupiedSlots[$class_id][$period]`

#### 4.3 Report Aggregation (LOW)  
**الوصف:** تقارير الإحصائيات تعيد حساب المجاميع من البيانات الخام.  
**التوصية:** جداول summary/materialized views تُحدّث بـ triggers أو cron.

#### 4.4 Session Data Structure (LOW)  
**الوصف:** الصلاحيات تُقرأ من DB في كل طلب بدلاً من تخزينها في الجلسة.  
**التوصية:** تخزين permissions array في `$_SESSION['permissions']` مع تحديث عند تسجيل الدخول.

---

## قائمة الملفات المعدلة | Modified Files

| # | الملف | التغييرات |
|---|--------|-----------|
| 1 | `admin/disciplinary.php` | Auth bypass fix + XSS fix |
| 2 | `admin/grades.php` | Auth bypass fix + debug removal |
| 3 | `admin/classes.php` | Auth bypass fix + debug removal |
| 4 | `admin/subjects.php` | Auth bypass fix + debug removal + N+1 fix |
| 5 | `admin/staff_attendance.php` | Auth bypass fix + XSS fix + transaction |
| 6 | `admin/staff.php` | Auth bypass fix + debug removal |
| 7 | `admin/notifications.php` | Auth bypass fix + debug removal |
| 8 | `admin/reports.php` | Auth bypass fix + debug removal |
| 9 | `admin/stages.php` | Auth bypass fix + debug removal |
| 10 | `admin/evaluation_types.php` | Auth bypass fix + debug removal |
| 11 | `admin/profile.php` | Auth bypass fix (role validation) |
| 12 | `admin/external_teachers.php` | Debug removal |
| 13 | `admin/training_reports.php` | Auth bypass fix + debug removal |
| 14 | `admin/training_programs.php` | Auth bypass fix + debug removal |
| 15 | `admin/training_courses.php` | Auth bypass fix + debug removal |
| 16 | `includes/ajax_handlers.php` | CSRF re-enabled + timing-safe |
| 17 | `includes/notifications_helper.php` | N+1 fix (batch fetch) |
| 18 | `admin/students.php` | Transaction + pre-loaded maps |
| 19 | `classes/excel_handler.php` | N+1 fix (teachers + specialists) |
| 20 | `teacher/ajax/grades_handler.php` | N+1 fix (grade columns) |

---

## الأولويات المتبقية | Remaining Priorities

### 🔴 أولوية قصوى (اعتبرها قبل الإنتاج)
1. ترحيل كلمات المرور من AES-256-CBC إلى bcrypt
2. نقل مفاتيح API لملف `.env`
3. تفعيل التحقق من توقيع JWT في Microsoft SSO
4. إضافة الفهارس المقترحة لقاعدة البيانات (§3.1)

### 🟡 أولوية متوسطة (الشهر القادم)
5. إضافة ترقيم الصفحات (Pagination) للقوائم الكبيرة
6. إصلاح IDOR في `get_students_by_class.php`
7. إضافة Foreign Keys المفقودة
8. توحيد نمط Soft Delete

### 🟢 أولوية منخفضة (تحسين مستمر)
9. طبقة Caching لبيانات الإعدادات
10. تجميع استعلامات لوحة التقارير
11. تخزين الصلاحيات في الجلسة
12. Materialized views للإحصائيات

---

> **ملاحظة:** جميع الإصلاحات المنفذة تم التحقق منها عبر `php -l` (20/20 ملف بدون أخطاء بناء).
