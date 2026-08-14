# دليل ميزة "قريباً" - إخفاء زر التحميل
**التاريخ:** 14 أكتوبر 2025

---

## 🎯 نظرة عامة

الآن يمكنك **إظهار اسم المادة** مع إخفاء زر التحميل وإظهار شارة "**قريباً**" بدلاً منه.

---

## 📊 المستويات الثلاثة للمواد

| الحالة | الوصف | الظهور |
|--------|-------|--------|
| **عادي** (افتراضي) | المادة تظهر مع زر تحميل | ✅ الاسم + 🔽 تحميل |
| **enabled: false** | المادة معطلة تماماً | ❌ لا تظهر نهائياً |
| **downloadable: false** | المادة تظهر بدون تحميل | ✅ الاسم + ⏰ قريباً |

---

## 💻 طريقة الاستخدام

### **1️⃣ مادة عادية (بزر تحميل):**

```json
{
    "name": "Math",
    "file": "Math G4 - T1 - 2026.pdf"
}
```

**النتيجة:**
```
| Math | [🔽 تحميل] |
```

---

### **2️⃣ مادة معطلة تماماً:**

```json
{
    "name": "ICT",
    "file": "ICT G4 - T1 - 2026.pdf",
    "enabled": false
}
```

**النتيجة:**
```
(لا تظهر في الجدول نهائياً)
```

---

### **3️⃣ مادة تظهر بدون تحميل (قريباً):**

```json
{
    "name": "Science",
    "file": "Science G4 - T1 - 2026.pdf",
    "downloadable": false
}
```

**النتيجة:**
```
| Science | [⏰ قريباً] |
```

---

## 🎨 المظهر النهائي

### **Light Mode:**
```
┌─────────────┬──────────────┐
│ Math        │  🔽 تحميل   │  ← أزرق/بنفسجي
├─────────────┼──────────────┤
│ Science     │  ⏰ قريباً   │  ← أصفر/برتقالي (مع تأثير pulse)
├─────────────┼──────────────┤
│ English     │  🔽 تحميل   │  ← أزرق/بنفسجي
└─────────────┴──────────────┘
```

### **Dark Mode:**
```
┌─────────────┬──────────────┐
│ Math        │  🔽 تحميل   │  ← أزرق/بنفسجي فاتح
├─────────────┼──────────────┤
│ Science     │  ⏰ قريباً   │  ← أصفر/برتقالي (نفس اللون)
├─────────────┼──────────────┤
│ English     │  🔽 تحميل   │  ← أزرق/بنفسجي فاتح
└─────────────┴──────────────┘
```

---

## 📝 أمثلة عملية

### **مثال 1: Prim 4 - Term 1**

```json
"term1": [
    {
        "name": "Arabic",
        "file": "Arabic G4 - T1 - 2026.pdf"
    },
    {
        "name": "English AL",
        "file": "English al G4 - T1 - 2026.pdf"
    },
    {
        "name": "Math",
        "file": "Math G4 - T1 - 2026.pdf"
    },
    {
        "name": "Science",
        "file": "Science G4 - T1 - 2026.pdf",
        "downloadable": false
    },
    {
        "name": "ICT",
        "file": "ICT G4 - T1 - 2026.pdf",
        "enabled": false
    }
]
```

**النتيجة:**
- ✅ **Arabic** → يظهر مع زر تحميل
- ✅ **English AL** → يظهر مع زر تحميل
- ✅ **Math** → يظهر مع زر تحميل
- ⏰ **Science** → يظهر مع شارة "قريباً"
- ❌ **ICT** → لا يظهر نهائياً

---

## 🎨 التنسيقات

### **شارة "قريباً":**

```css
.coming-soon-badge {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    color: #78350f;
    padding: 0.6rem 1.5rem;
    border-radius: 10px;
    font-weight: 600;
    box-shadow: 0 4px 10px rgba(251, 191, 36, 0.3);
}
```

### **تأثير Pulse على الأيقونة:**

```css
.coming-soon-badge i {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
```

**النتيجة:** أيقونة الساعة ⏰ تومض برفق كل ثانيتين

---

## 🔄 سيناريوهات الاستخدام

### **السيناريو 1: مادة لم يتم رفع الملف بعد**

```json
{
    "name": "French",
    "file": "French G4 - T1 - 2026.pdf",
    "downloadable": false
}
```

**الفائدة:**
- الطلاب يعرفون أن المادة موجودة
- يفهمون أنها قيد التحضير
- لا يحاولون البحث عنها

---

### **السيناريو 2: مادة قيد المراجعة**

```json
{
    "name": "German",
    "file": "German G4 - T1 - 2026.pdf",
    "downloadable": false
}
```

**الرسالة:** "الملف قيد المراجعة، سيتم رفعه قريباً"

---

### **السيناريو 3: مادة ملغاة نهائياً**

```json
{
    "name": "OldSubject",
    "file": "OldSubject.pdf",
    "enabled": false
}
```

**الفائدة:** المادة تختفي تماماً من القائمة

---

## 📋 مقارنة الخصائص

| الخاصية | القيمة | السلوك |
|---------|-------|--------|
| `enabled` | `true` (افتراضي) | المادة تظهر |
| `enabled` | `false` | المادة **لا تظهر** نهائياً |
| `downloadable` | `true` (افتراضي) | زر تحميل يظهر |
| `downloadable` | `false` | شارة "قريباً" تظهر |

---

## 🎯 ملاحظات مهمة

### **1. الأولوية:**
```
enabled: false  >  downloadable: false
```
- إذا كانت `enabled: false` → المادة **لا تظهر** (حتى لو كانت `downloadable: false`)

### **2. الحالة الافتراضية:**
```json
{
    "name": "Math",
    "file": "Math.pdf"
}
```
**يعادل:**
```json
{
    "name": "Math",
    "file": "Math.pdf",
    "enabled": true,
    "downloadable": true
}
```

### **3. الجمع بين الخصائص:**

❌ **خطأ - لا معنى له:**
```json
{
    "name": "Subject",
    "file": "Subject.pdf",
    "enabled": false,
    "downloadable": false
}
```
(المادة معطلة أصلاً، `downloadable` لا فائدة منها)

✅ **صحيح:**
```json
{
    "name": "Subject",
    "file": "Subject.pdf",
    "downloadable": false
}
```

---

## 🚀 خطوات التفعيل

### **لإخفاء زر التحميل:**

1. افتح `materials_data.json`
2. ابحث عن المادة المطلوبة
3. أضف `"downloadable": false`
4. احفظ الملف

**مثال:**
```json
{
    "name": "Science",
    "file": "Science G4 - T1 - 2026.pdf",
    "downloadable": false    ← أضف هذا السطر
}
```

### **لاستعادة زر التحميل:**

**الخيار 1:** غيّر `false` إلى `true`:
```json
"downloadable": true
```

**الخيار 2:** احذف السطر تماماً:
```json
{
    "name": "Science",
    "file": "Science G4 - T1 - 2026.pdf"
}
```

---

## ✅ التحديثات المنفذة

### **الملفات المعدلة:**

1. **view.php**
   - إضافة منطق `downloadable`
   - عرض شارة "قريباً" إذا كانت `downloadable: false`

2. **materials-portal-style.css**
   - تنسيق `.coming-soon-badge`
   - تأثير `pulse` على الأيقونة
   - دعم Dark Mode

---

## 🎨 الألوان

### **شارة "قريباً":**
- **Background:** `linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)`
- **Text Color:** `#78350f` (بني غامق)
- **Shadow:** `rgba(251, 191, 36, 0.3)`

### **تأثيرات:**
- ✨ **Pulse animation** على الأيقونة (2 ثانية)
- 🎨 **نفس الألوان** في Light و Dark Mode

---

## 📚 الخلاصة

| السؤال | الإجابة |
|--------|---------|
| **كيف أخفي مادة تماماً؟** | `"enabled": false` |
| **كيف أظهر مادة بدون تحميل؟** | `"downloadable": false` |
| **كيف أعيد زر التحميل؟** | احذف `"downloadable"` أو اجعلها `true` |
| **هل يمكن الجمع بينهما؟** | نعم، لكن `enabled: false` لها الأولوية |

---

**آخر تحديث:** 14 أكتوبر 2025
**الإصدار:** 2.0
**النظام:** EduCore - Materials System
