# دليل رفع النظام على السيرفر الأونلاين 🚀

## 📋 الملفات التي تحتاج تعديل بيانات الاتصال بقاعدة البيانات

### ✅ **الملف الرئيسي (الأهم)**

#### 1. `config/database.php` ⭐ **الملف الرئيسي**
```php
class Database {
    private $host = 'localhost';           // غير إلى: اسم المضيف من لوحة التحكم
    private $db_name = 'rewards_system';   // غير إلى: اسم قاعدة البيانات الرئيسية
    private $username = 'root';            // غير إلى: اسم مستخدم قاعدة البيانات
    private $password = '';                // غير إلى: كلمة مرور قاعدة البيانات
```

---

### ✅ **ملفات نظام التقارير الشهرية للطلاب**

#### 2. `student/reports/student_grades_new.php`
**السطر 78-80:**
```php
$servername = "localhost";  // غير إلى: اسم المضيف
$username = "root";         // غير إلى: اسم المستخدم
$password = "";             // غير إلى: كلمة المرور
```

#### 3. `student/reports/auto_login.php`
**السطر 468-470:**
```php
$servername = "localhost";  // غير إلى: اسم المضيف
$db_username = "root";      // غير إلى: اسم المستخدم
$db_password = "";          // غير إلى: كلمة المرور
```

#### 4. `student/reports/check_databases.php`
**السطر 6-8:**
```php
$servername = "localhost";  // غير إلى: اسم المضيف
$username = "root";         // غير إلى: اسم المستخدم
$password = "";             // غير إلى: كلمة المرور
```

---

### ⚠️ **ملفات اختيارية (للصيانة والترحيل فقط)**

#### 5. `migrate_evaluations.php`
**السطر 13-15:**
```php
$host = 'localhost';        // غير إلى: اسم المضيف
$username = 'root';         // غير إلى: اسم المستخدم
$password = '';             // غير إلى: كلمة المرور
```

#### 6. `show_columns.php` (ملف اختبار)
**السطر 3:**
```php
$conn = new mysqli("localhost", "root", "", "students_db456_apr");
// غير إلى:
$conn = new mysqli("HOST", "USERNAME", "PASSWORD", "DATABASE");
```

---

## 🗄️ **قواعد البيانات المطلوبة**

### قاعدة البيانات الرئيسية:
- **الاسم:** `rewards_system`
- **تحتوي على:** جميع بيانات النظام (المستخدمين، الصفوف، التقييمات، إلخ)

### قواعد بيانات التقارير الشهرية:
يتم قراءة أسماء قواعد البيانات من ملف الإعدادات:
- **الملف:** `config/reports_config.json`
- **مثال:** `students_db123_oct`, `students_db456_nov`, إلخ

---

## 📝 **خطوات الرفع على السيرفر**

### 1️⃣ **تصدير قواعد البيانات من XAMPP**
```bash
# من phpMyAdmin أو من terminal:
mysqldump -u root -p rewards_system > rewards_system.sql
mysqldump -u root -p students_db123_oct > students_db123_oct.sql
# كرر لكل قاعدة بيانات تقارير شهرية
```

### 2️⃣ **رفع الملفات على السيرفر**
- ارفع مجلد `rewards` بالكامل عبر FTP أو File Manager
- تأكد من رفع مجلد `vendor` (مكتبات Composer)

### 3️⃣ **إنشاء قواعد البيانات على السيرفر**
من لوحة تحكم السيرفر (cPanel/Plesk/DirectAdmin):
1. أنشئ قاعدة بيانات جديدة: `rewards_system`
2. أنشئ مستخدم قاعدة بيانات
3. امنح المستخدم جميع الصلاحيات على القاعدة
4. استورد ملف `.sql` في القاعدة
5. كرر نفس الخطوات لقواعد التقارير الشهرية

### 4️⃣ **تحديث بيانات الاتصال**
عدّل الملفات المذكورة أعلاه بالبيانات الجديدة:
```php
$servername = "localhost";           // أو اسم المضيف الخاص بك
$username = "your_db_username";      // من لوحة التحكم
$password = "your_db_password";      // من لوحة التحكم
$dbname = "your_db_name";           // من لوحة التحكم
```

### 5️⃣ **ضبط الصلاحيات**
```bash
# تأكد من صلاحيات المجلدات
chmod 755 uploads/
chmod 644 config/*.php
chmod 644 config/*.json
```

### 6️⃣ **اختبار النظام**
- [ ] تسجيل دخول المدير: `yoursite.com/admin/`
- [ ] تسجيل دخول المعلم: `yoursite.com/teacher/`
- [ ] تسجيل دخول الطالب: `yoursite.com/student/`
- [ ] اختبار التقارير الشهرية: `yoursite.com/student/reports/`
- [ ] اختبار استيراد Excel للطلاب

---

## ⚡ **ملاحظات مهمة**

### 🔒 الأمان:
1. **غيّر كلمات المرور:** لا تستخدم `root` بدون كلمة مرور
2. **استخدم HTTPS:** تأكد من تفعيل SSL على السيرفر
3. **حماية الملفات الحساسة:**
   ```apache
   # في ملف .htaccess
   <Files "database.php">
       Order Allow,Deny
       Deny from all
   </Files>
   ```

### 📦 المكتبات المطلوبة:
- **PHP >= 7.4**
- **MySQL >= 5.7**
- **Extensions:** mysqli, PDO, zip, mbstring
- **Composer:** لتثبيت PhpSpreadsheet

### 🔄 إذا لم يعمل Composer على السيرفر:
ارفع مجلد `vendor` كاملاً من جهازك المحلي

---

## 🆘 **حل المشاكل الشائعة**

### ❌ خطأ: "Access denied for user"
```php
// تأكد من:
1. اسم المستخدم وكلمة المرور صحيحة
2. المستخدم له صلاحيات على القاعدة
3. السماح بالاتصال من localhost أو %
```

### ❌ خطأ: "Unknown database"
```php
// تأكد من:
1. إنشاء قاعدة البيانات على السيرفر
2. اسم القاعدة مطابق تماماً (حساس لحالة الأحرف)
3. استيراد ملف SQL بنجاح
```

### ❌ خطأ: "Can't connect to MySQL server"
```php
// تأكد من:
1. اسم المضيف صحيح (localhost أو IP محدد)
2. خدمة MySQL تعمل على السيرفر
3. البورت صحيح (عادة 3306)
```

---

## 📞 **بيانات الاتصال من لوحة التحكم**

عند رفع النظام، ستحتاج للحصول على هذه البيانات من لوحة تحكم الاستضافة:

| البيان | مثال | من أين؟ |
|--------|------|---------|
| اسم المضيف | `localhost` أو `mysql.example.com` | MySQL Databases → Hostname |
| اسم المستخدم | `cpanel_rewards` | MySQL Databases → Current Users |
| كلمة المرور | `********` | عند إنشاء المستخدم |
| اسم القاعدة | `cpanel_rewards` | MySQL Databases → Current Databases |

---

## ✅ **قائمة التحقق النهائية**

- [ ] تصدير جميع قواعد البيانات من XAMPP
- [ ] رفع جميع ملفات المشروع على السيرفر
- [ ] إنشاء قواعد البيانات على السيرفر
- [ ] استيراد ملفات SQL في قواعد البيانات
- [ ] تحديث `config/database.php`
- [ ] تحديث `student/reports/student_grades_new.php`
- [ ] تحديث `student/reports/auto_login.php`
- [ ] تحديث `student/reports/check_databases.php`
- [ ] اختبار تسجيل الدخول لجميع الأنواع
- [ ] اختبار التقارير الشهرية
- [ ] اختبار استيراد Excel
- [ ] تفعيل HTTPS وشهادة SSL

---

**🎉 بالتوفيق في رفع النظام!**

*تم إنشاء هذا الملف تلقائياً - آخر تحديث: نوفمبر 2025*
