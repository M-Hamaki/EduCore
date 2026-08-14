# 🎯 نظام Materials الديناميكي - Quick Start

## ما تم إنجازه؟

تم تحويل **26 ملف HTML ثابت** إلى **نظام ديناميكي واحد** 🚀

---

## 📂 الملفات الجديدة

1. **view.php** - الملف الديناميكي الرئيسي
2. **materials_data.json** - قاعدة بيانات المواد
3. **DYNAMIC_MATERIALS_GUIDE.md** - الدليل الكامل
4. **archive_old_files.ps1** - سكريبت الأرشفة

---

## ⚡ كيفية الاستخدام (3 خطوات)

### 1️⃣ اختبر النظام الجديد
افتح في المتصفح:
```
http://localhost/rewards1/student/materials/view.php?grade=prim1&term=term1
```

### 2️⃣ أرشف الملفات القديمة
في PowerShell:
```powershell
cd <project-root>\student\materials
.\archive_old_files.ps1
```

### 3️⃣ انتهى! 🎉
النظام الآن يعمل بملف واحد فقط.

---

## ✏️ إضافة مادة جديدة

افتح `materials_data.json` وأضف:
```json
{"name": "NEW SUBJECT", "file": "NewSubject G1 - T1 - 2026.pdf"}
```

---

## 🔗 الروابط الجديدة

| الصف | الفصل 1 | الفصل 2 |
|------|---------|---------|
| Prim 1 | `view.php?grade=prim1&term=term1` | `view.php?grade=prim1&term=term2` |
| Prim 4 | `view.php?grade=prim4&term=term1` | `view.php?grade=prim4&term=term2` |
| Prep 1 | `view.php?grade=prep1&term=term1` | `view.php?grade=prep1&term=term2` |

---

## 📊 النتائج

| قبل | بعد | التحسين |
|-----|-----|---------|
| 26 ملف | 1 ملف | 96% ↓ |
| ~3000 سطر | ~100 سطر | 97% ↓ |
| تحديث صعب | تحديث سهل | 26× أسرع |

---

## 📖 للمزيد

راجع **DYNAMIC_MATERIALS_GUIDE.md** للدليل الكامل.

---

✅ **النظام جاهز للاستخدام!**
