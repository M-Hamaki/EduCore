# تحديث نظام التقارير - إضافة فلتر الصف الدراسي

## 📅 تاريخ التحديث: 2025-11-10

---

## 🎯 الهدف من التحديث

إضافة فلتر **الصف الدراسي (Grade)** إلى صفحة التقارير لتتمكن من تصفية التقييمات حسب الصف بالإضافة إلى الفصل.

---

## 📊 التغييرات في قاعدة البيانات

### الاستعلامات المحدثة:

#### قبل التحديث:
```sql
SELECT e.id, s.name as student_name, t.name as teacher_name, c.name as class_name, ...
FROM evaluations e
JOIN users s ON e.student_id = s.id
JOIN users t ON e.teacher_id = t.id
JOIN classes c ON e.class_id = c.id
WHERE e.class_id = :class_id
```

#### بعد التحديث:
```sql
SELECT e.id, s.name as student_name, t.name as teacher_name, 
       g.grade_name, c.name as class_name, ...
FROM evaluations e
JOIN users s ON e.student_id = s.id
JOIN users t ON e.teacher_id = t.id
JOIN classes c ON e.class_id = c.id
LEFT JOIN grades g ON c.grade_id = g.id
WHERE c.grade_id = :grade_id  -- فلتر جديد
  AND e.class_id = :class_id
```

---

## 📁 الملفات المعدلة

### 1️⃣ `admin/reports.php`

#### التغييرات:
- ✅ إضافة متغير `$filter_grade` لاستقبال الفلتر
- ✅ إضافة استعلام لجلب قائمة الصفوف الدراسية
- ✅ تحديث جميع الاستعلامات لتشمل `grades` table
- ✅ إضافة عمود "الصف" في جدول النتائج
- ✅ إضافة حقل "الصف" في تصدير Excel
- ✅ إضافة dropdown للصف الدراسي في نموذج الفلتر

#### كود جلب الصفوف:
```php
// Prepare grades options
$grades_query = "SELECT id, grade_name, grade_code FROM grades ORDER BY grade_order";
$grades_stmt = $db->prepare($grades_query);
$grades_stmt->execute();
$grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
```

#### تحديث الاستعلام الرئيسي:
```php
$query = "SELECT e.id, e.date_created, 
          s.name as student_name, 
          t.name as teacher_name,
          c.name as class_name,
          g.grade_name,  -- إضافة جديدة
          et.name as evaluation_name, 
          ...
          FROM evaluations e
          JOIN users s ON e.student_id = s.id
          JOIN users t ON e.teacher_id = t.id
          JOIN classes c ON e.class_id = c.id
          LEFT JOIN grades g ON c.grade_id = g.id  -- إضافة جديدة
          JOIN evaluation_types et ON e.evaluation_type_id = et.id
          WHERE 1=1";

if ($filter_grade) {  -- إضافة جديدة
    $query .= " AND c.grade_id = :grade_id";
    $params[':grade_id'] = $filter_grade;
}
```

#### واجهة المستخدم:
```html
<!-- فلتر الصف الدراسي -->
<div class="col-lg-3 col-md-6">
    <label for="grade_id" class="form-label fw-bold">
        <i class="fas fa-layer-group me-1 text-primary"></i>الصف الدراسي
    </label>
    <select class="form-select" id="grade_id" name="grade_id">
        <option value="">-- جميع الصفوف --</option>
        <?php
        foreach ($grades as $grade) {
            $selected = ($filter_grade == $grade['id']) ? 'selected' : '';
            echo '<option value="' . $grade['id'] . '" ' . $selected . '>' 
                 . htmlspecialchars($grade['grade_name']) . '</option>';
        }
        ?>
    </select>
</div>
```

#### جدول النتائج:
```html
<thead>
    <tr>
        <th><input type="checkbox" id="masterCheckbox"></th>
        <th>الرقم</th>
        <th>الطالب</th>
        <th>المعلم</th>
        <th>الصف</th>        <!-- عمود جديد -->
        <th>الفصل</th>
        <th>التقييم</th>
        <th>النوع</th>
        <th>النقاط</th>
        <th>التاريخ</th>
        <th>الإجراءات</th>
    </tr>
</thead>
```

#### DataTables Configuration:
```javascript
columns: [
    { data: 0, orderable: false }, // checkbox
    { data: 1 }, // id
    { data: 2 }, // student
    { data: 3 }, // teacher
    { data: 4 }, // grade    -- إضافة جديدة
    { data: 5 }, // class
    { data: 6 }, // evaluation
    { data: 7 }, // type
    { data: 8 }, // points
    { data: 9 }, // date
    { data: 10, orderable: false } // actions
]
```

#### Excel Export:
```php
$header_row = ['الرقم', 'الطالب', 'المعلم', 'الصف', 'الفصل', 'التقييم', 'النوع', 'النقاط', 'السبب', 'التاريخ'];

$data_row = [
    $row['id'],
    $row['student_name'],
    $row['teacher_name'],
    $row['grade_name'] ?: 'غير محدد',  // إضافة جديدة
    $row['class_name'],
    $row['evaluation_name'],
    ...
];
```

---

### 2️⃣ `includes/ajax_handlers.php`

#### التغييرات:
- ✅ تحديث mapping الأعمدة لـ DataTables
- ✅ إضافة فلتر `$filter_grade`
- ✅ تحديث FROM clause لإضافة LEFT JOIN مع grades
- ✅ إضافة grade_name في SELECT
- ✅ إضافة grade_name في البحث (LIKE)
- ✅ إضافة عمود الصف في البيانات المرسلة

#### كود التحديث:
```php
// Columns mapping: [checkbox], id, student, teacher, grade, class, evaluation, type, points, date, actions
$columns = [
    0 => 'checkbox',
    1 => 'e.id',
    2 => 's.name',
    3 => 't.name',
    4 => 'g.grade_name',  // إضافة جديدة
    5 => 'c.name',
    6 => 'et.name',
    7 => 'display_type',
    8 => 'display_points',
    9 => 'e.date_created',
    10 => 'actions'
];

// Filters
$filter_grade = $_GET['grade_id'] ?? null;  // إضافة جديدة
$filter_class = $_GET['class_id'] ?? null;

// FROM clause
$from = " FROM evaluations e
          JOIN users s ON e.student_id = s.id
          JOIN users t ON e.teacher_id = t.id
          JOIN classes c ON e.class_id = c.id
          LEFT JOIN grades g ON c.grade_id = g.id  -- إضافة جديدة
          JOIN evaluation_types et ON e.evaluation_type_id = et.id
          WHERE 1=1";

// Apply filters
if (!empty($filter_grade)) {  // إضافة جديدة
    $from .= " AND c.grade_id = :grade_id"; 
    $params[':grade_id'] = (int)$filter_grade; 
}

// Search
if ($searchValue !== '') {
    $searchSql = " AND (s.name LIKE :q OR t.name LIKE :q 
                   OR g.grade_name LIKE :q  -- إضافة جديدة
                   OR c.name LIKE :q OR et.name LIKE :q OR e.reason LIKE :q)";
    $params[':q'] = '%' . $searchValue . '%';
}

// SELECT
$select = "SELECT e.id, e.date_created,
                  s.name AS student_name,
                  t.name AS teacher_name,
                  g.grade_name,  -- إضافة جديدة
                  c.name AS class_name,
                  ...";

// Data array
$data[] = [
    $checkbox,
    $row['id'],
    htmlspecialchars($row['student_name']),
    htmlspecialchars($row['teacher_name']),
    !empty($row['grade_name']) ? htmlspecialchars($row['grade_name']) : '<span class="text-muted">غير محدد</span>',  // إضافة جديدة
    htmlspecialchars($row['class_name']),
    ...
];
```

---

## 🎨 التحسينات في واجهة المستخدم

### 1. ترتيب الفلاتر:
```
┌─────────────────────────────────────────────────────┐
│  الصف الأول:                                        │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐│
│  │ الصف     │ │ الفصل    │ │ المعلم   │ │ التقييم  ││
│  │ الدراسي  │ │          │ │/الأخصائي│ │          ││
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘│
│                                                      │
│  الصف الثاني:                                       │
│  ┌──────────────────────────────────────────────────┐│
│  │              الطالب (عرض كامل)                  ││
│  └──────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────┘
```

### 2. جدول النتائج:
```
┌──┬────┬────────┬────────┬──────────┬────────┬────────┬──────┬──────┬──────────┬────────┐
│☐ │ # │ الطالب │ المعلم │ الصف     │ الفصل  │ التقييم│ النوع│ النقاط│ التاريخ   │ الحذف  │
├──┼────┼────────┼────────┼──────────┼────────┼────────┼──────┼──────┼──────────┼────────┤
│☐ │001 │أحمد    │محمد    │الأول     │1/1    │ممتاز   │إيجابي│ +10  │2025-11-10│  🗑️   │
│☐ │002 │سارة    │فاطمة   │الثاني    │2/2    │تأخر    │سلبي  │ -5   │2025-11-10│  🗑️   │
└──┴────┴────────┴────────┴──────────┴────────┴────────┴──────┴──────┴──────────┴────────┘
```

### 3. تصدير Excel:
```
الرقم | الطالب | المعلم | الصف          | الفصل | التقييم | النوع  | النقاط | السبب | التاريخ
-----|--------|--------|--------------|-------|---------|--------|-------|-------|----------
001  | أحمد   | محمد   | الأول الابتدائي| 1/1   | ممتاز   | إيجابي | +10   | -     | 2025-11-10
002  | سارة   | فاطمة  | الثاني الابتدائي| 2/2  | تأخر    | سلبي   | -5    | -     | 2025-11-10
```

---

## 🔍 سيناريوهات الاستخدام

### سيناريو 1: عرض تقارير صف معين
```
1. المستخدم يختار "الصف الأول الابتدائي" من القائمة
2. النظام يعرض جميع التقييمات لجميع فصول الصف الأول
3. يمكن إضافة فلتر الفصل للتحديد أكثر
```

### سيناريو 2: عرض تقارير فصل معين
```
1. المستخدم يختار "الصف الثالث" ثم "فصل 3/2"
2. النظام يعرض فقط تقييمات فصل 3/2
```

### سيناريو 3: البحث عن صف
```
1. المستخدم يكتب "الأول" في مربع البحث
2. النظام يبحث في أسماء الصفوف ويعرض النتائج
```

---

## ✅ الفوائد

1. **تنظيم أفضل**: تصنيف التقارير حسب الصف الدراسي
2. **فلترة متقدمة**: إمكانية الفلترة حسب الصف أو الفصل أو كليهما
3. **تصدير شامل**: ملفات Excel تحتوي على معلومات الصف
4. **بحث محسّن**: البحث يشمل أسماء الصفوف الدراسية
5. **رؤية واضحة**: عرض الصف بجانب الفصل في الجدول

---

## 📝 ملاحظات

- ✅ جميع الفصول القديمة التي ليس لها صف ستظهر بـ "غير محدد"
- ✅ الفلترة تعمل بشكل مستقل (يمكن اختيار صف بدون فصل)
- ✅ البحث يشمل الصف والفصل والطالب والمعلم
- ✅ التصدير يحافظ على الفلاتر المحددة
- ✅ الترتيب يعمل على عمود الصف

---

## 🧪 اختبارات مقترحة

### 1. اختبار الفلترة:
```
✓ اختيار صف واحد
✓ اختيار صف + فصل
✓ اختيار فصل بدون صف
✓ إلغاء جميع الفلاتر
```

### 2. اختبار البحث:
```
✓ البحث بـ "الأول" (يجب أن يجد الصف الأول)
✓ البحث بـ "الثاني" (يجب أن يجد الصف الثاني)
✓ البحث بـ "1/1" (يجب أن يجد الفصل)
```

### 3. اختبار التصدير:
```
✓ تصدير بدون فلاتر
✓ تصدير مع فلتر الصف
✓ تصدير مع فلتر الصف والفصل
✓ التحقق من عمود "الصف" في Excel
```

---

**تم التحديث بنجاح! ✅**
