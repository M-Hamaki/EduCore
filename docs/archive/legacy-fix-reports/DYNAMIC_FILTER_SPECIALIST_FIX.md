# إصلاح ظهور الأخصائيين في التصفية الديناميكية
**التاريخ:** 18 أكتوبر 2025

## المشكلة 🐛

عند اختيار فصل معين في صفحة تقارير الأدمن، كانت القائمة المنسدلة "المعلم/الأخصائي" تعرض **معلمي هذا الفصل فقط** دون **الأخصائيين**.

### الأعراض:
1. ❌ عند اختيار فصل → تظهر قائمة المعلمين فقط
2. ❌ الأخصائيون المسندون لهذا الفصل لا يظهرون
3. ❌ لا يمكن تصفية تقييمات الأخصائي لفصل محدد
4. ❌ النصوص لا تزال تقول "جميع المعلمين" بدلاً من "الجميع"

### الملفات المتأثرة:
1. `classes/user.php` - دالة `getTeachersByClass()`
2. `includes/ajax_handlers.php` - cases `get_all_teachers` و `get_teachers_by_class`
3. `admin/reports.php` - JavaScript الخاص بالتصفية الديناميكية

---

## السبب الجذري 🔍

### 1. في `classes/user.php` (السطر 685):
```php
public function getTeachersByClass($class_id) {
    $query = "SELECT DISTINCT u.id, u.name, u.username, u.status
              FROM users u
              JOIN user_class_access uca ON u.id = uca.user_id
              WHERE uca.class_id = :class_id
              AND u.role = 'teacher'  // ❌ معلمين فقط
              ORDER BY u.name";
    // ...
}
```

**المشكلة:** 
- الشرط `AND u.role = 'teacher'` يجلب المعلمين فقط
- يتجاهل الأخصائيين المسندين لنفس الفصل
- لا يرجع معلومات `role` للتمييز

### 2. في `includes/ajax_handlers.php` (السطر 603):
```php
case 'get_all_teachers':
    $user = new User($db);
    $teachers_array = $user->getAllByRole('teacher');  // ❌ معلمين فقط
    
    header('Content-Type: application/json');
    echo json_encode($teachers_array);
    break;
```

**المشكلة:**
- عند عدم اختيار فصل (الجميع)
- يجلب المعلمين فقط دون الأخصائيين

### 3. في `admin/reports.php` (JavaScript):
```javascript
teacherSelect.html('<option value="">-- جميع المعلمين --</option>');
// ❌ نص مضلل، يجب أن يكون "الجميع"

var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
// ❌ لا يوجد role badge للتمييز بين المعلم والأخصائي
```

---

## الحل ✅

### 1. تعديل دالة `getTeachersByClass()` في `classes/user.php`

**قبل:**
```php
public function getTeachersByClass($class_id) {
    $query = "SELECT DISTINCT u.id, u.name, u.username, u.status
              FROM " . $this->table_name . " u
              JOIN user_class_access uca ON u.id = uca.user_id
              WHERE uca.class_id = :class_id
              AND u.role = 'teacher'
              ORDER BY u.name";
    
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':class_id', $class_id);
    $stmt->execute();
    
    $teachers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $teachers[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'username' => $row['username'],
            'status' => $row['status']
        ];
    }
    
    return $teachers;
}
```

**بعد:**
```php
public function getTeachersByClass($class_id) {
    $query = "SELECT DISTINCT u.id, u.name, u.username, u.role, u.status
              FROM " . $this->table_name . " u
              JOIN user_class_access uca ON u.id = uca.user_id
              WHERE uca.class_id = :class_id
              AND u.role IN ('teacher', 'specialist')
              ORDER BY u.name";
    
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':class_id', $class_id);
    $stmt->execute();
    
    $teachers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $teachers[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'username' => $row['username'],
            'role' => $row['role'],
            'status' => $row['status']
        ];
    }
    
    return $teachers;
}
```

**التحسينات:**
- ✅ استخدام `IN ('teacher', 'specialist')` بدلاً من `= 'teacher'`
- ✅ إضافة `u.role` في SELECT
- ✅ إرجاع `role` في المصفوفة

### 2. تعديل `get_all_teachers` في `includes/ajax_handlers.php`

**قبل:**
```php
case 'get_all_teachers':
    $user = new User($db);
    $teachers_array = $user->getAllByRole('teacher');
    
    header('Content-Type: application/json');
    echo json_encode($teachers_array);
    break;
```

**بعد:**
```php
case 'get_all_teachers':
    $user = new User($db);
    $teachers_array = $user->getAllByRole('teacher');
    $specialists_array = $user->getAllByRole('specialist');
    $all_array = array_merge($teachers_array, $specialists_array);
    
    header('Content-Type: application/json');
    echo json_encode($all_array);
    break;
```

**التحسينات:**
- ✅ جلب المعلمين والأخصائيين
- ✅ دمجهم في مصفوفة واحدة
- ✅ إرجاع القائمة الكاملة

### 3. تحديث JavaScript في `admin/reports.php`

#### أ. تحديث النصوص الأولية:
**قبل:**
```javascript
teacherSelect.html('<option value="">-- جميع المعلمين --</option>');
```

**بعد:**
```javascript
teacherSelect.html('<option value="">-- الجميع --</option>');
```

#### ب. تحديث عند اختيار فصل:
**قبل:**
```javascript
// Update teachers list
console.log('Admin: Loading teachers for class', classId);
teacherSelect.html('<option value="">جاري تحميل المعلمين...</option>');
// ...
success: function(teachers) {
    console.log('Admin: Teachers AJAX success:', teachers);
    teacherSelect.html('<option value="">-- جميع المعلمين --</option>');
    if (teachers && teachers.length > 0) {
        teachers.forEach(function(teacher) {
            var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
            teacherSelect.append(
                $('<option></option>')
                    .attr('value', teacher.id)
                    .text(teacher.name + ' ' + statusBadge)
            );
        });
    } else {
        teacherSelect.append('<option value="">لا يوجد معلمين في هذا الفصل</option>');
    }
}
```

**بعد:**
```javascript
// Update teachers and specialists list
console.log('Admin: Loading teachers and specialists for class', classId);
teacherSelect.html('<option value="">جاري التحميل...</option>');
// ...
success: function(teachers) {
    console.log('Admin: Teachers AJAX success:', teachers);
    teacherSelect.html('<option value="">-- الجميع --</option>');
    if (teachers && teachers.length > 0) {
        console.log('Admin: Adding', teachers.length, 'teachers/specialists to dropdown');
        teachers.forEach(function(teacher) {
            var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
            var roleBadge = teacher.role === 'specialist' ? ' [أخصائي]' : ' [معلم]';
            teacherSelect.append(
                $('<option></option>')
                    .attr('value', teacher.id)
                    .text(teacher.name + roleBadge + ' ' + statusBadge)
            );
        });
    } else {
        console.log('Admin: No teachers/specialists found for this class');
        teacherSelect.append('<option value="">لا يوجد معلمين/أخصائيين في هذا الفصل</option>');
    }
}
```

#### ج. تحديث عند عدم اختيار فصل:
**قبل:**
```javascript
// Get all teachers
$.ajax({
    // ...
    success: function(teachers) {
        console.log('Admin: Get all teachers success:', teachers);
        if (teachers && teachers.length > 0) {
            teachers.forEach(function(teacher) {
                var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
                teacherSelect.append(
                    $('<option></option>')
                        .attr('value', teacher.id)
                        .text(teacher.name + ' ' + statusBadge)
                );
            });
        }
    }
});
```

**بعد:**
```javascript
// Get all teachers and specialists
$.ajax({
    // ...
    success: function(teachers) {
        console.log('Admin: Get all teachers/specialists success:', teachers);
        if (teachers && teachers.length > 0) {
            teachers.forEach(function(teacher) {
                var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
                var roleBadge = teacher.role === 'specialist' ? ' [أخصائي]' : ' [معلم]';
                teacherSelect.append(
                    $('<option></option>')
                        .attr('value', teacher.id)
                        .text(teacher.name + roleBadge + ' ' + statusBadge)
                );
            });
        }
    }
});
```

---

## السلوك الجديد 🎯

### سيناريو 1: اختيار فصل معين
```
المستخدم يختار: الصف الأول أ
↓
AJAX → get_teachers_by_class (class_id=5)
↓
SQL: WHERE class_id=5 AND role IN ('teacher','specialist')
↓
النتيجة:
-- الجميع --
أحمد محمد [معلم] (نشط)
محمود حسن [أخصائي] (نشط)  ← الآن يظهر!
```

### سيناريو 2: عدم اختيار فصل (الكل)
```
المستخدم يختار: -- جميع الفصول --
↓
AJAX → get_all_teachers
↓
array_merge(teachers, specialists)
↓
النتيجة:
-- الجميع --
أحمد محمد [معلم] (نشط)
سارة علي [معلم] (نشط)
محمود حسن [أخصائي] (نشط)
فاطمة خالد [أخصائي] (نشط)
```

---

## المقارنة: قبل وبعد 📊

| الحالة | قبل ❌ | بعد ✅ |
|--------|--------|--------|
| **اختيار فصل** | معلمو الفصل فقط | معلمو + أخصائيو الفصل |
| **عدم اختيار فصل** | جميع المعلمين فقط | جميع المعلمين + الأخصائيين |
| **التمييز بالـ badge** | لا يوجد | [معلم] / [أخصائي] |
| **النص الافتراضي** | "-- جميع المعلمين --" | "-- الجميع --" |
| **رسالة التحميل** | "جاري تحميل المعلمين..." | "جاري التحميل..." |
| **رسالة عدم الوجود** | "لا يوجد معلمين" | "لا يوجد معلمين/أخصائيين" |

---

## اختبار التغييرات 🧪

### Test Case 1: اختيار فصل معين
```
✓ افتح: admin/reports.php
✓ اختر فصل من القائمة المنسدلة "الفصل"
✓ انتظر تحميل القائمة المنسدلة "المعلم/الأخصائي"
✓ تحقق من ظهور المعلمين مع badge [معلم]
✓ تحقق من ظهور الأخصائيين مع badge [أخصائي]
✓ تحقق من أن الجميع مسندون لهذا الفصل
```

### Test Case 2: عدم اختيار فصل
```
✓ افتح: admin/reports.php
✓ اترك "الفصل" على "-- جميع الفصول --"
✓ انتظر تحميل القوائم
✓ تحقق من ظهور جميع المعلمين والأخصائيين
✓ تحقق من وجود badges صحيحة
```

### Test Case 3: التصفية المركبة
```
✓ اختر فصل + أخصائي محدد من هذا الفصل
✓ اضغط "عرض التقرير"
✓ تحقق من ظهور تقييمات هذا الأخصائي لهذا الفصل فقط
```

### Test Case 4: تبديل الفصول
```
✓ اختر "الصف الأول أ"
✓ لاحظ القائمة (معلمو وأخصائيو الصف الأول أ)
✓ اختر "الصف الثاني ب"
✓ لاحظ القائمة (معلمو وأخصائيو الصف الثاني ب)
✓ تحقق من تحديث القائمة بشكل صحيح
```

---

## التفاصيل التقنية 📋

### جدول `user_class_access`:
```sql
CREATE TABLE user_class_access (
    user_id INT,      -- يمكن أن يكون معلم أو أخصائي
    class_id INT,
    PRIMARY KEY (user_id, class_id)
);
```

### استعلام SQL المحسّن:
```sql
SELECT DISTINCT u.id, u.name, u.username, u.role, u.status
FROM users u
JOIN user_class_access uca ON u.id = uca.user_id
WHERE uca.class_id = :class_id
AND u.role IN ('teacher', 'specialist')  -- المفتاح!
ORDER BY u.name
```

### Flow Chart:
```
اختيار فصل
    ↓
onChange event
    ↓
AJAX call → ajax_handlers.php
    ↓
case 'get_teachers_by_class'
    ↓
getTeachersByClass($class_id)
    ↓
SQL query (teacher + specialist)
    ↓
JSON response with 'role' field
    ↓
JavaScript loop + role badge
    ↓
تحديث القائمة المنسدلة
```

---

## الملاحظات الهامة ⚠️

### 1. التوافق مع الكود الحالي:
- ✅ لم يتم تغيير اسم الدالة `getTeachersByClass()`
- ✅ لم يتم تغيير المعاملات (parameters)
- ✅ البيانات المرجعة متوافقة (أضفنا `role` فقط)
- ✅ لا حاجة لتعديل ملفات أخرى

### 2. الأداء:
- ✅ استعلام واحد بدلاً من اثنين
- ✅ استخدام `IN` بدلاً من `OR` (أفضل للأداء)
- ✅ DISTINCT لتجنب التكرار

### 3. الأمان:
- ✅ Prepared statements (`:class_id`)
- ✅ Type casting في PHP
- ✅ تحقق من الصلاحيات موجود مسبقاً

---

## الملفات المعدلة 📂

### 1. classes/user.php
- **السطور:** 680-703
- **التعديل:** إضافة `role` في SELECT و شرط IN

### 2. includes/ajax_handlers.php
- **السطور:** 601-609
- **التعديل:** دمج المعلمين والأخصائيين

### 3. admin/reports.php
- **السطور:** 954, 999-1034, 1070-1091
- **التعديل:** إضافة role badges وتحديث النصوص

### عدد الأسطر:
- **المحذوفة:** ~15
- **المضافة:** ~25
- **الصافي:** +10 أسطر

---

## الفوائد المتحققة 🎉

### 1. الدقة:
- ✅ عرض جميع المسؤولين عن الفصل (معلمين + أخصائيين)
- ✅ تصفية دقيقة حسب الفصل
- ✅ بيانات كاملة للتحليل

### 2. وضوح الواجهة:
- ✅ badges تميز بين الأدوار
- ✅ نصوص واضحة وشاملة
- ✅ رسائل محدثة

### 3. الاتساق:
- ✅ متوافق مع تعديل القائمة الثابتة السابق
- ✅ نفس الـ badges في كل مكان
- ✅ تجربة موحدة

### 4. المرونة:
- ✅ سهولة إضافة أدوار جديدة مستقبلاً
- ✅ كود قابل للصيانة
- ✅ توثيق واضح

---

## الخلاصة 📊

تم إصلاح التصفية الديناميكية لعرض الأخصائيين مع المعلمين:

### التغييرات الرئيسية:
1. ✅ تعديل SQL لجلب teacher + specialist
2. ✅ إضافة role في البيانات المرجعة
3. ✅ تحديث JavaScript لعرض badges
4. ✅ تحديث النصوص لتكون شاملة

### الملفات:
- `classes/user.php` - 1 دالة معدلة
- `includes/ajax_handlers.php` - 1 case معدل
- `admin/reports.php` - 3 أماكن معدلة

### النتيجة:
الآن عند اختيار فصل، تظهر قائمة كاملة بالمعلمين والأخصائيين المسندين لهذا الفصل مع تمييز واضح! 🎉
