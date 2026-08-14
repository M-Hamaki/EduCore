# تحديث نظام eBook - تطبيق تنسيق Materials
**التاريخ:** 14 أكتوبر 2025

---

## 🎯 الهدف

تطبيق نفس تنسيق نظام Materials والبوابة على نظام eBook ليكون:
- ✅ موحد مع Materials
- ✅ Dark Mode
- ✅ Responsive
- ✅ احترافي

---

## 📁 هيكل نظام eBook

### **الملفات الحالية:**
```
student/ebook/
├── index.html              ← الصفحة الرئيسية (اختيار الترم)
├── term1.html              ← صفحة الترم الأول (اختيار الصف)
├── term2.html              ← صفحة الترم الثاني (اختيار الصف)
├── prim1.html              ← كتب الصف الأول
├── prim2.html              ← كتب الصف الثاني
├── prim3.html              ← كتب الصف الثالث
├── prim4.html              ← كتب الصف الرابع
├── prim5.html              ← كتب الخامس
├── prim6.html              ← كتب السادس
├── prim1-t2.html           ← كتب الأول - ترم 2
├── prim2-t2.html           ← كتب الثاني - ترم 2
├── prim3-t2.html           ← كتب الثالث - ترم 2
├── prim4-t2.html           ← كتب الرابع - ترم 2
├── prim5-t2.html           ← كتب الخامس - ترم 2
├── prim6-t2.html           ← كتب السادس - ترم 2
├── style.css               ← التنسيق القديم
├── script.js               ← سكريبت قديم
└── ebook.jpg               ← صورة

المجموع: 15 ملف HTML
```

---

## ✨ التحديثات المطبقة

### **1. الملفات الجديدة:**
- ✅ `ebook-portal-style.css` - نسخة من materials-portal-style.css
- ✅ `ebook-portal-theme.js` - نسخة من materials-portal-theme.js

### **2. الملفات المحدثة:**
- ✅ `index.html` - تطبيق التصميم الجديد

### **3. الملفات المطلوب تحديثها:**
- [ ] term1.html
- [ ] term2.html  
- [ ] prim1.html → prim6.html (6 ملفات)
- [ ] prim1-t2.html → prim6-t2.html (6 ملفات)

**المجموع:** 14 ملف HTML

---

## 🎨 المميزات الجديدة

### **Design:**
- ✅ نفس تصميم Materials
- ✅ نفس الألوان (#667eea → #764ba2)
- ✅ نفس الخطوط (Tajawal)
- ✅ نفس البطاقات والظلال

### **Features:**
- ✅ Dark Mode مع حفظ الاختيار
- ✅ Responsive (Mobile, Tablet, Desktop)
- ✅ أيقونات Font Awesome
- ✅ تأثيرات حركية
- ✅ Footer موحد مع أيقونات التواصل

### **Navigation:**
- ✅ روابط موحدة
- ✅ زر العودة للبوابة
- ✅ لوجو قابل للنقر

---

## 📋 خطة التنفيذ

### **المرحلة 1: الملفات الرئيسية** ✅
- [x] index.html
- [ ] term1.html
- [ ] term2.html

### **المرحلة 2: ملفات الصفوف - Term 1**
- [ ] prim1.html
- [ ] prim2.html
- [ ] prim3.html
- [ ] prim4.html
- [ ] prim5.html
- [ ] prim6.html

### **المرحلة 3: ملفات الصفوف - Term 2**
- [ ] prim1-t2.html
- [ ] prim2-t2.html
- [ ] prim3-t2.html
- [ ] prim4-t2.html
- [ ] prim5-t2.html
- [ ] prim6-t2.html

### **المرحلة 4: التنظيف**
- [ ] نقل style.css و script.js القديمين للأرشيف

---

## 🎯 الخطوات التالية

1. تحديث term1.html و term2.html
2. تحديث جميع ملفات الصفوف (12 ملف)
3. اختبار Dark Mode
4. اختبار Responsive
5. نقل الملفات القديمة للأرشيف

---

**الحالة:** قيد التنفيذ
**التقدم:** 1 من 15 ملف (7%)
