# نظام الهيكل التنظيمي للصفوف والفصول
## Grades and Classes Hierarchical System

تم إنشاء نظام تنظيمي متدرج لتحسين إدارة الصفوف والفصول في النظام.

---

## 📋 الملفات الجديدة

### 1. `admin/grades.php`
صفحة إدارية كاملة لإدارة الصفوف الدراسية مع الوظائف التالية:
- ✅ عرض جميع الصفوف الدراسية في شكل بطاقات
- ✅ إضافة صف دراسي جديد
- ✅ تعديل بيانات صف دراسي
- ✅ حذف صف دراسي (مع الحماية من الحذف إذا كان يحتوي على فصول)
- ✅ عرض عدد الفصول لكل صف
- ✅ ترتيب الصفوف حسب الترتيب المحدد

### 2. `add_grades_system.sql`
ملف SQL لتنفيذ التحديثات على قاعدة البيانات:
```sql
-- إنشاء جدول الصفوف الدراسية
CREATE TABLE grades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    grade_name VARCHAR(100) NOT NULL,
    grade_code VARCHAR(20) UNIQUE NOT NULL,
    grade_order INT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- إضافة عمود grade_id إلى جدول الفصول
ALTER TABLE classes ADD COLUMN grade_id INT NULL;

-- إضافة المفتاح الأجنبي
ALTER TABLE classes 
ADD CONSTRAINT fk_classes_grade 
FOREIGN KEY (grade_id) REFERENCES grades(id) 
ON DELETE SET NULL ON UPDATE CASCADE;

-- إدراج البيانات الافتراضية
INSERT INTO grades (grade_name, grade_code, grade_order, description) VALUES
('الصف الأول الابتدائي', 'prim1', 1, 'الصف الأول من المرحلة الابتدائية'),
('الصف الثاني الابتدائي', 'prim2', 2, 'الصف الثاني من المرحلة الابتدائية'),
... إلخ
```

---

## 🔧 الملفات المعدلة

### 1. `admin/classes.php`
تم تحديث صفحة إدارة الفصول:

#### ✨ الميزات الجديدة:
- إضافة قائمة منسدلة لاختيار الصف الدراسي عند إضافة/تعديل فصل
- عرض الصف الدراسي لكل فصل في الجدول
- ترتيب الفصول حسب الصف الدراسي أولاً
- تحسين الاستعلامات لعرض اسم الصف مع كل فصل

#### التعديلات:
```php
// إضافة grade_id عند الإنشاء والتعديل
$class->grade_id = !empty($_POST['grade_id']) ? $_POST['grade_id'] : null;

// استعلام محسّن لعرض الفصول مع الصفوف
$query = "SELECT c.id, c.name, c.grade_id, g.grade_name, 
          COUNT(DISTINCT s.id) as student_count
          FROM classes c
          LEFT JOIN grades g ON c.grade_id = g.id
          LEFT JOIN students s ON c.id = s.class_id
          GROUP BY c.id
          ORDER BY g.grade_order, c.name";
```

### 2. `classes/classroom.php`
تم تحديث كلاس إدارة الفصول:

#### التعديلات:
```php
// إضافة خاصية grade_id
public $grade_id;

// تحديث create()
$query = "INSERT INTO classes SET name = :name, grade_id = :grade_id";

// تحديث update()
$query = "UPDATE classes SET name = :name, grade_id = :grade_id WHERE id = :id";

// تحديث readOne()
$query = "SELECT id, name, grade_id FROM classes WHERE id = ?";
```

### 3. `includes/admin_header.php`
تم تحديث قائمة التنقل:

#### التعديلات:
- تحويل "الفصول" من رابط مفرد إلى قائمة منسدلة
- إضافة رابط "الصفوف الدراسية" (grades.php)
- إضافة رابط "الفصول" (classes.php)
- تحسين الأيقونات والألوان

```php
<!-- قائمة الصفوف والفصول -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" ...>
        <i class="fas fa-school"></i>
        <span>الصفوف والفصول</span>
    </a>
    <ul class="dropdown-menu">
        <li>
            <a class="dropdown-item" href="grades.php">
                <i class="fas fa-layer-group me-2 text-info"></i> الصفوف الدراسية
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="classes.php">
                <i class="fas fa-door-open me-2 text-primary"></i> الفصول
            </a>
        </li>
    </ul>
</li>
```

---

## 🗄️ بنية قاعدة البيانات الجديدة

### جدول `grades` (الصفوف الدراسية)
```
+-------------+---------------+
| Field       | Type          |
+-------------+---------------+
| id          | INT(11)       | PRIMARY KEY
| grade_name  | VARCHAR(100)  | NOT NULL - اسم الصف
| grade_code  | VARCHAR(20)   | UNIQUE - كود الصف (prim1, prim2, etc.)
| grade_order | INT(11)       | NOT NULL - ترتيب الصف
| description | TEXT          | NULL - وصف الصف
| created_at  | TIMESTAMP     |
| updated_at  | TIMESTAMP     |
+-------------+---------------+
```

### جدول `classes` (الفصول) - محدّث
```
+----------+--------------+
| Field    | Type         |
+----------+--------------+
| id       | INT(11)      | PRIMARY KEY
| name     | VARCHAR(100) | NOT NULL
| grade_id | INT(11)      | NULL - FOREIGN KEY → grades(id)
+----------+--------------+
```

### العلاقات:
- **One-to-Many**: صف واحد يحتوي على عدة فصول
- **Cascade**: عند تحديث الصف يتم تحديث الفصول تلقائياً
- **Set NULL**: عند حذف الصف تصبح grade_id للفصول NULL

---

## 📌 كيفية التنفيذ

### الخطوة 1: تنفيذ ملف SQL
```sql
-- في phpMyAdmin أو من خلال terminal
mysql -u root -p rewards_db < add_grades_system.sql
```

### الخطوة 2: التحقق من التثبيت
1. افتح صفحة الإدارة
2. انتقل إلى "الصفوف والفصول" → "الصفوف الدراسية"
3. تحقق من وجود الصفوف الستة الافتراضية
4. انتقل إلى "الفصول" وتحقق من ظهور عمود "الصف الدراسي"

### الخطوة 3: ربط الفصول الموجودة
- إذا كانت لديك فصول موجودة بالفعل، قم بتحديد الصف الدراسي لكل منها
- استخدم زر "تعديل" بجانب كل فصل
- اختر الصف الدراسي المناسب من القائمة المنسدلة

---

## 🎯 فوائد النظام الجديد

### 1. تنظيم أفضل
- تصنيف الفصول حسب الصفوف الدراسية
- سهولة إدارة عدد كبير من الفصول

### 2. مرونة أكبر
- إمكانية إضافة صفوف جديدة بسهولة
- تعديل بيانات الصفوف دون التأثير على الفصول

### 3. تقارير محسّنة
- إمكانية استخراج تقارير على مستوى الصف
- إحصائيات دقيقة لكل صف دراسي

### 4. قابلية التوسع
- يدعم إضافة مراحل تعليمية أخرى (إعدادي، ثانوي)
- يمكن ربط الصفوف بأنظمة التقييم المختلفة

---

## 🔍 أمثلة على الاستخدام

### مثال 1: إضافة صف جديد
```
اسم الصف: الصف الأول الابتدائي
كود الصف: prim1
ترتيب الصف: 1
الوصف: الصف الأول من المرحلة الابتدائية
```

### مثال 2: إضافة فصل جديد
```
الصف الدراسي: الصف الأول الابتدائي
اسم الفصل: 1/أ
```

### مثال 3: استعلام جميع فصول صف معين
```sql
SELECT c.* FROM classes c
JOIN grades g ON c.grade_id = g.id
WHERE g.grade_code = 'prim1';
```

---

## ⚠️ ملاحظات مهمة

1. **الحذف المحمي**: لا يمكن حذف صف يحتوي على فصول
2. **الصف اختياري**: يمكن إنشاء فصل بدون تحديد صف (grade_id = NULL)
3. **التحديث التلقائي**: تم تحديث الفصول الموجودة تلقائياً بناءً على أسمائها
4. **الفرز**: الفصول تُعرض مرتبة حسب الصف الدراسي أولاً ثم الاسم

---

## 📞 الدعم والمساعدة

إذا واجهت أي مشاكل:
1. تحقق من تنفيذ ملف SQL بنجاح
2. تأكد من وجود الأذونات المناسبة
3. راجع سجل الأخطاء (error log)

---

## 📅 تاريخ التحديث
**التاريخ**: 2025
**الإصدار**: 1.0
**الحالة**: ✅ جاهز للاستخدام

---

تم بناء هذا النظام لتحسين تجربة إدارة المدارس وتسهيل العمليات الإدارية اليومية! 🎓
