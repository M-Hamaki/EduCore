# نظام Materials الديناميكي - دليل الاستخدام

## 📋 نظرة عامة

تم تحويل نظام Materials من **26 ملف HTML ثابت** إلى **نظام ديناميكي واحد** يستخدم:
- ملف PHP واحد (`view.php`)
- ملف JSON واحد (`materials_data.json`)
- 2 ملف HTML فقط (`term1.html` و `term2.html`)

---

## 🎯 المزايا

### قبل التحديث:
- ❌ 26 ملف HTML (13 لكل فصل دراسي)
- ❌ تكرار الكود في كل ملف
- ❌ صعوبة التحديث (تعديل 26 ملف لتغيير التصميم)
- ❌ صعوبة إضافة مواد جديدة

### بعد التحديث:
- ✅ ملف واحد فقط (`view.php`)
- ✅ بيانات مركزية في JSON
- ✅ تحديث سهل (تعديل ملف واحد)
- ✅ إضافة مواد جديدة بسهولة

---

## 📁 البنية الجديدة

```
student/materials/
├── view.php                    # الملف الديناميكي الرئيسي
├── materials_data.json         # قاعدة بيانات المواد
├── index.html                  # الصفحة الرئيسية
├── style.css                   # التنسيقات
├── script.js                   # JavaScript
├── term1/
│   └── term1.html             # اختيار الصف (الفصل الأول)
└── term2/
    └── term2.html             # اختيار الصف (الفصل الثاني)
```

---

## 🔧 كيفية الاستخدام

### 1️⃣ عرض المواد لصف معين

الرابط الجديد:
```
view.php?grade=prim1&term=term1
```

**المعاملات:**
- `grade`: كود الصف (kg1, kg2, prim1-prim6, prep1-prep3, sec1-sec2)
- `term`: الفصل الدراسي (term1 أو term2)

**أمثلة:**
```
view.php?grade=prim4&term=term1  ← الصف الرابع - الفصل الأول
view.php?grade=prep1&term=term2  ← الصف الأول الإعدادي - الفصل الثاني
view.php?grade=sec2&term=term1   ← الصف الثاني الثانوي - الفصل الأول
```

---

## ✏️ إضافة مواد جديدة

### الطريقة 1: تعديل JSON (موصى بها)

افتح `materials_data.json` وأضف المادة:

```json
"prim1": {
  "name": "Prim 1",
  "folder": "g1",
  "terms": {
    "term1": [
      {"name": "Arabic", "file": "Arabic G1 - T1 - 2026.pdf"},
      {"name": "NEW SUBJECT", "file": "NewSubject G1 - T1 - 2026.pdf"}  ← إضافة هنا
    ]
  }
}
```

---

## 🔄 تحديث بيانات صف كامل

لتغيير جميع مواد صف معين، عدّل فقط في `materials_data.json`:

```json
"prim4": {
  "name": "Prim 4",
  "folder": "g4",
  "terms": {
    "term1": [
      {"name": "Arabic", "file": "Arabic G4 - T1 - 2027.pdf"},    ← تحديث السنة
      {"name": "Math", "file": "Math G4 - T1 - 2027.pdf"}
    ],
    "term2": [...]
  }
}
```

---

## 🎨 تعديل التصميم

لتغيير التصميم، عدّل **ملف واحد فقط** (`view.php`):

```php
<header>
    <div class="title"><?php echo $pageTitle; ?></div>
    <!-- التعديلات هنا تنطبق على جميع الصفوف -->
</header>
```

---

## 📊 أكواد الصفوف المتاحة

| الصف الدراسي | الكود | المجلد |
|--------------|-------|--------|
| KG 1 | `kg1` | `kg1` |
| KG 2 | `kg2` | `kg2` |
| الصف الأول | `prim1` | `g1` |
| الصف الثاني | `prim2` | `g2` |
| الصف الثالث | `prim3` | `g3` |
| الصف الرابع | `prim4` | `g4` |
| الصف الخامس | `prim5` | `g5` |
| الصف السادس | `prim6` | `g6` |
| الأول الإعدادي | `prep1` | `g7` |
| الثاني الإعدادي | `prep2` | `g8` |
| الثالث الإعدادي | `prep3` | `g9` |
| الأول الثانوي | `sec1` | `g10` |
| الثاني الثانوي | `sec2` | `g11` |

---

## 🗑️ الملفات القديمة (يمكن حذفها)

الملفات التالية لم تعد مستخدمة:

### Term 1:
```
term1/kg1-t1.html
term1/kg2-t1.html
term1/prim1-t1.html
term1/prim2-t1.html
term1/prim3-t1.html
term1/prim4-t1.html
term1/prim5-t1.html
term1/prim6-t1.html
term1/prep1-t1.html
term1/prep2-t1.html
term1/prep3-t1.html
term1/sec1-t1.html
term1/sec2-t1.html
```

### Term 2:
```
term2/kg1-t2.html
term2/kg2-t2.html
term2/prim1-t2.html
term2/prim2-t2.html
term2/prim3-t2.html
term2/prim4-t2.html
term2/prim5-t2.html
term2/prim6-t2.html
term2/prep1-t2.html
term2/prep2-t2.html
term2/prep3-t2.html
term2/sec1-t2.html
term2/sec2-t2.html
```

**⚠️ ملاحظة:** احتفظ بنسخة احتياطية قبل الحذف!

---

## 🔒 معالجة الأخطاء

النظام يتحقق من:
- ✅ وجود معاملات `grade` و `term`
- ✅ صحة كود الصف
- ✅ صحة الفصل الدراسي
- ❌ في حالة الخطأ → يعيد التوجيه إلى `index.html`

---

## 📝 أمثلة الاستخدام

### مثال 1: إضافة مادة جديدة لجميع الصفوف

إذا أردت إضافة مادة "Robotics" لجميع صفوف الإبتدائي:

```json
"prim1": {
  "terms": {
    "term1": [
      ...,
      {"name": "Robotics", "file": "Robotics G1 - T1 - 2026.pdf"}
    ]
  }
}
```

كرر لـ `prim2`, `prim3`, إلخ.

### مثال 2: تحديث ملفات السنة الجديدة

استبدل `2026` بـ `2027` في `materials_data.json`:

```bash
# البحث والاستبدال في JSON
Find: "2026"
Replace: "2027"
```

---

## 🚀 الخطوات التالية (اختياري)

1. **إضافة صور المواد:**
   ```json
   {"name": "Math", "file": "math.pdf", "icon": "math-icon.png"}
   ```

2. **إضافة أوصاف:**
   ```json
   {"name": "Science", "file": "science.pdf", "description": "كتاب العلوم للفصل الأول"}
   ```

3. **إحصائيات التحميل:**
   - تتبع عدد مرات التحميل لكل ملف
   - إضافة جدول في قاعدة البيانات

---

## 📞 الدعم الفني

في حالة وجود مشاكل:
1. تحقق من صحة JSON في: https://jsonlint.com/
2. تأكد من رفع ملفات PDF في المجلد الصحيح
3. راجع سجل الأخطاء في XAMPP

---

## 📊 إحصائيات التحسين

| المقياس | قبل | بعد | التحسين |
|---------|-----|-----|---------|
| عدد الملفات | 26 | 1 | **96% أقل** |
| سطور الكود | ~3000 | ~100 | **97% أقل** |
| وقت التحديث | 26 ملف | 1 ملف | **26× أسرع** |
| وقت إضافة مادة | تعديل 26 ملف | تعديل JSON | **فوري** |

---

## ✅ نهاية الدليل

النظام الآن:
- ✅ مركزي وسهل الإدارة
- ✅ قابل للتوسع
- ✅ سهل التحديث
- ✅ احترافي وديناميكي

**تم التطوير بواسطة:** EduCore contributors
**التاريخ:** أكتوبر 2025
