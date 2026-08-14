# نظام EduCore — إدارة التعليم والتقييم

نظام ويب متكامل لإدارة النظام التعليمي، يشمل: التقييم الصفي (مشابه لـ ClassDojo)، إدارة المستخدمين (أدمن، معلم، مشرف، أخصائي، طالب)، الجداول الدراسية، الحضور والانصراف، نظام المكافآت، التقارير، ودعم SSO عبر Microsoft Azure AD و Teams. مع دعم كامل للغة العربية (RTL) وتصميم متجاوب.

## المميزات الرئيسية

- دعم كامل للغة العربية (RTL) وتصميم متجاوب لجميع الأجهزة
- خمسة أدوار للمستخدمين: **أدمن، معلم، مشرف، أخصائي، طالب**
- إدارة الفصول الدراسية والمراحل التعليمية
- إدارة المستخدمين مع استيراد/تصدير Excel
- نظام تقييم صفي إيجابي وسلبي مع تقارير متقدمة
- جدول الحصص الدراسية
- نظام الحضور والانصراف (يدوي + أجهزة بصمة ZKTeco)
- ربط بالأجهزة البيومترية (ZKTeco) عبر TCP/UDP
- نظام النقل المدرسي (الحافلات)
- نظام الإجازات وموازنة الإجازات للموظفين
- نظام تدريب الموظفين
- نظام المكافآت والعقوبات (تقييم صفي + سلوك)
- دعم Microsoft SSO (Azure AD + Microsoft Teams)
- تكامل الذكاء الاصطناعي (Google Gemini / Ollama المحلي)
- بحث الصور من APIs (Pixabay, Unsplash, Pexels)
- نظام نسخ احتياطي لقاعدة البيانات
- نظام إشعارات داخلية
- بوابات منفصلة: أدمن، معلم، مشرف، أخصائي، طالب، معلم خارجي

## متطلبات النظام

- **PHP 8.0 أو أحدث** وفق القيد التنفيذي في `composer.json`
- MariaDB / MySQL 5.7 أو أحدث
- دعم وظائف PDO و OpenSSL و mbstring في PHP
- مكتبات Composer المطلوبة:
  - `phpoffice/phpspreadsheet` — ملفات Excel
  - `phpoffice/phppresentation` — ملفات PowerPoint
  - `dompdf/dompdf` — توليد PDF
  - `firebase/php-jwt` — توكنات JWT لـ Microsoft SSO
  - `minishlink/web-push` — إشعارات Push

## التثبيت

### 1. استنساخ المشروع

```bash
git clone <repository-url>
cd EduCore
```

### 2. تثبيت اعتماديات Composer

```bash
composer install
```

### 3. إعداد ملف البيئة (.env)

انسخ ملف القالب واملأ القيم حسب بيئتك:

```bash
cp .env.example .env
```

عدّل `.env` ليتضمن:
- **إعدادات قاعدة البيانات:** `DB_HOST`, `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD`
- **رابط الموقع:** `SITE_URL`
- **بيئة التطبيق:** `APP_ENV` (`production` للإنتاج، `development` للتطوير المحلي)
- **مفتاح التشفير:** `ENCRYPTION_KEY_HEX` — مطلوب لتشفير كلمات المرور
- **API Keys** اختيارية: Gemini AI، صور، YouTube، Microsoft SSO

> **ملاحظة:** النظام يقرأ كل الإعدادات من `.env` عبر `config/env_loader.php`. لا حاجة لتعديل `config/database.php` يدوياً.

### 4. إعداد قاعدة البيانات

1. أنشئ قاعدة بيانات جديدة باسم `educore`
2. استورد ملف `database_complete.sql`:

```sql
mysql -u username -p educore < database_complete.sql
```

أو استخدم phpMyAdmin لاستيراد الملف.

### 5. تكوين الصلاحيات

تأكد من أن المجلدات التالية قابلة للكتابة:

```
uploads/
uploads/imports/
uploads/exports/
uploads/templates/
```

```bash
chmod -R 755 uploads/
```

### 6. إنشاء حساب المسؤول

شغّل سكربت التثبيت من المتصفح أو أنشئ الحساب يدوياً. **لا يوجد حساب افتراضي جاهز** — كلمة المرور يختارها المُثبّت.

## الاستخدام

1. ادخل إلى النظام عبر المتصفح
2. سجّل الدخول بحساب المسؤول الذي أنشأته أثناء التثبيت
3. أعدّد النظام من خلال:
   - إضافة المراحل التعليمية والفصول الدراسية
   - إضافة المواد الدراسية
   - إضافة أنواع التقييمات
   - إضافة المعلمين والمشرفين والأخصائيين والطلاب

## الأدوار والصلاحيات

| الدور | الصلاحيات |
|---|---|
| **المسؤول (Admin)** | إدارة كاملة: فصول، مستخدمين، تقييمات، تقارير، إعدادات، نسخ احتياطي، سجلات النظام |
| **المعلم (Teacher)** | عرض الفصول المسندة، إضافة تقييمات للطلاب، حضور، إعداد دروس AI |
| **المشرف (Supervisor)** | عرض الفصول المسندة، تقييمات، تحميل تقارير Excel، متابعة الحضور |
| **الأخصائي (Specialist)** | إدارة الطلاب المسجلين لديه، تقييمات، متابعة الحضور |
| **الطالب (Student)** | عرض تقييماته، مجموع النقاط، الحضور، المناهج والكتب الإلكترونية |
| **المعلم الخارجي** | بوابة مستقلة لتسجيل الدخول والتسجيل (جدول `external_teachers`) |

## هيكل المشروع

```
EduCore/
├── admin/             # لوحة تحكم الأدمن (كل صفحات الإدارة)
├── api/               # واجهات API (AJAX endpoints)
├── assets/            # CSS, JavaScript, الصور
│   ├── css/
│   ├── js/
│   └── img/
├── auth/              # Microsoft SSO (Azure AD, Teams)
├── classes/           # فئات PHP (User, Database, MicrosoftSSO, WebImageSearch...)
├── config/           # إعدادات: database.php, encryption.php, env_loader.php
├── docs/              # وثائق المشروع
├── external/          # بوابة المعلمين الخارجيين
├── includes/          # ملفات مشتركة (session_config, template_helper, ajax_handlers)
├── specialist/        # لوحة تحكم الأخصائي
├── student/           # لوحة تحكم الطالب + تقارير الدرجات
├── supervisor/        # لوحة تحكم المشرف
├── teacher/           # لوحة تحكم المعلم
├── teams/             # دعم Microsoft Teams
├── uploads/           # ملفات مُحمّلة (صور، Excel، PDFs)
├── vendor/            # مكتبات Composer
├── archive/           # أرشيف (ملفات قديمة، محمية بـ .htaccess)
├── .env               # إعدادات البيئة (غير مُلتزم في Git)
├── .env.example       # قالب الإعدادات
├── composer.json      # إعدادات Composer
├── database_complete.sql  # هيكل قاعدة البيانات
├── index.php          # الصفحة الرئيسية (اختيار المرحلة)
└── login.php          # صفحة تسجيل الدخول
```

## الأمان

### الحماية المطبّقة والمؤكدة حاليًا

- تُحمّل الأسرار والإعدادات من `.env` عبر `config/env_loader.php`، والملف نفسه غير ملتزم في Git.
- ينشئ `includes/session_config.php` رمز CSRF مركزيًا، وتتحقق منه مسارات الكتابة المرصودة على الخادم؛ كما يضيف `assets/js/main.js` الرمز إلى طلبات AJAX المدعومة، ويمنع التدقيق الصارم دخول مسار جديد بلا حارس أو إعفاء موثق.
- كوكي الجلسة `HttpOnly`، وتصبح `Secure` عند اتصال HTTPS. قيمة `SameSite` قابلة للضبط عبر البيئة وافتراضها الحالي `Lax`، مع مهلات خمول وتجديد دوري لمعرّف الجلسة.
- المجلدات الداخلية `classes/`, `config/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, و`storage/` محمية دفاعيًا من الوصول المباشر عبر `.htaccess`، كما أن مشغّل migrations يقبل التشغيل من CLI فقط.
- تستخدم المصادقة التقليدية `PasswordAuthenticator` بقرار hash-first؛ الحسابات القديمة بلا hash تُرقّى بعد أول دخول ناجح خلال نافذة توافق قابلة للإغلاق من البيئة.

### ديون وملاحظات لا يجوز إخفاؤها

- يبقى تخزين غلاف قابل للفك لدعم ميزة reveal الإدارية دينًا أمنيًا منفصلًا عن قرار المصادقة؛ إلغاء reveal يحتاج سياسة استعادة حسابات وموافقة تشغيلية.
- يعرض `composer architecture-audit` حاليًا صفر مرشح CSRF غير مراجع. يظل الفحص heuristic وpath-level: لا يغني عن اختبار السلوك والصلاحيات، ولا يثبت وحده غياب ثغرة داخل مسار معفى أو ديناميكي.
- حماية `.htaccess` طبقة دفاعية وليست بديلًا عن ضبط document root أو قواعد خادم الإنتاج. مخطط نشر الإنتاج وفعالية `AllowOverride` غير مؤكدين بعد.
- مرفقات ملفات الطلاب والعاملين انتقلت إلى تخزين خاص وتنزيل مفوّض؛ بقية أنواع uploads تحتاج جردًا مستقلًا قبل تعميم السياسة.
- حالة الوصول إلى `phpMyAdmin` و`vendor/` في الإنتاج تحتاج حسمًا على مستوى vhost/document root.

للتفاصيل والأدلة وخطة المعالجة التدريجية، راجع `docs/architecture.md` و`docs/architecture-audit/`.

## AI Coding Assistants

كل مساعد برمجي يعمل على المشروع يجب أن يبدأ من المصادر التالية بهذا الترتيب، وألا يعيد مسح المستودع عندما تكون الإجابة مثبتة فيها:

1. `AGENTS.md` — المصدر الإلزامي والمرجعي لتعليمات المشروع.
2. `docs/architecture.md` — الواقع المعماري الحالي والاتجاه المستهدف.
3. `docs/project-structure.md` — خريطة المجلدات والحدود العامة.
4. `docs/architecture-decisions.md` — القرارات المعمارية المعتمدة والمؤجلة.
5. `docs/project-memory.md` و`docs/database.md` — الحقائق المؤكدة والبيانات غير المحسومة.
6. `docs/coding-rules.md` و`docs/ai-change-checklist.md` — قواعد التنفيذ وقائمة التحقق قبل التسليم.
7. `docs/architecture-audit/` — الأدلة التفصيلية وسجل المخاطر وخريطة الطريق.

قبل إغلاق أي تغيير، شغّل ما ينطبق من الأوامر التالية من جذر المشروع:

```powershell
composer validate --no-interaction
composer lint
composer architecture-audit
composer documentation-audit
composer audit-write-coverage
composer admin-ui-audit
composer quality
C:\xampp\php\php.exe tests\architecture_audit_test.php
C:\xampp\php\php.exe tests\internal_web_boundary_test.php
```

أضف الاختبارات الخاصة بالوحدة أو مسار العمل المتأثر. لا تشغّل اختبارًا يكتب في قاعدة `educore` أو بيانات الإنتاج؛ اختبارات التكامل تحتاج قاعدة اختبار صريحة وguard يمنع الإنتاج. يتطلب `composer security-audit` اتصالًا بالشبكة، وفشل الاتصال بمصدر الحزم ليس حكمًا على وجود ثغرات أو عدمها.

## التطوير المستقبلي

- نظام إشعارات فورية عبر Push Notifications
- تطبيق موبايل متوافق مع النظام
- فصل نظام الدرجات عن الجدول المنفصل وتوحيده مع النظام الرئيسي

## الترخيص

هذا المشروع مرخص تحت [رخصة MIT](LICENSE).
