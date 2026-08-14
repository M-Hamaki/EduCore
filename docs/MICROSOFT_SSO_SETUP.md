# دليل إعداد Microsoft Teams SSO لموقع DMLS School
# Microsoft Teams SSO Setup Guide for DMLS School Portal

---

## 📋 نظرة عامة

هذا الدليل يشرح كيفية تفعيل تسجيل الدخول التلقائي (SSO) للطلاب عند دخولهم لموقع المدرسة من داخل Microsoft Teams.

### ما سيحدث بعد الإعداد:
1. ✅ الطالب يفتح تطبيق DMLS داخل Teams
2. ✅ يتم تسجيل دخوله تلقائياً بدون كتابة اسم المستخدم وكلمة المرور
3. ✅ يدخل مباشرة إلى لوحة التحكم الخاصة به

### السلوك الحالي وحدود المطابقة

- تطبيق Teams يطلب الرمز تلقائياً مرة واحدة، ويرسله إلى الخادم عبر POST من نفس origin، ثم ينتقل إلى لوحة الدور عند النجاح.
- إذا انتهت المهلة أو فشل الرمز أو لم يكن الحساب مرتبطاً، ينتقل المستخدم إلى البوابة الموحدة التي تعرض Microsoft اليدوي واسم المستخدم/كلمة المرور والضيف واختصار المواد.
- الدخول الصامت لا ينشئ ربطاً. يجب أن يكون `azure_id` محفوظاً مسبقاً، وأن يطابق بريد Microsoft الموثق كلاً من `users.email` و`users.username` عند كل دخول.
- إذا تغيّر بريد Microsoft، يتوقف الدخول حتى يصحح الأدمن البريد واسم المستخدم في النظام؛ لا يكفي Microsoft ID القديم.
- الحساب المعطل يرى سبب الأدمن وحده إن كان مكتوباً، أو الرسالة العامة وحدها إن كان السبب فارغاً، وينطبق ذلك على Teams وMicrosoft والدخول اليدوي.
- فيديو المقدمة متخطى تماماً داخل Teams.

أعلام التشغيل غير السرية:

```env
UNIFIED_ACCESS_PORTAL_ENABLED=true
TEAMS_AUTO_SSO_ENABLED=true
```

---

## 🔧 الخطوات المطلوبة

### الخطوة 1: إنشاء Client Secret في Azure ⚠️ (مهم جداً)

1. **افتح Azure Portal:**
   - اذهب إلى: https://portal.azure.com
   - سجّل دخول بحساب المسؤول

2. **اذهب إلى App Registration:**
   - من القائمة الجانبية: **Microsoft Entra ID**
   - ثم: **App registrations**
   - اختر: **School Website SSO**

3. **إنشاء Client Secret:**
   - من القائمة الجانبية: **Certificates & secrets**
   - اضغط: **New client secret**
   - أدخل وصف: `DMLS Portal SSO Secret`
   - اختر المدة: **24 months** (أو ما يناسبك)
   - اضغط: **Add**

4. **⚠️ مهم جداً - انسخ القيمة فوراً:**
   - ستظهر قيمة الـ Secret مرة واحدة فقط
   - انسخها فوراً واحتفظ بها
   - إذا أغلقت الصفحة لن تستطيع رؤيتها مرة أخرى

5. **أضف القيمة في ملف `.env`:**
   ```env
   AZURE_CLIENT_ID=...
   AZURE_TENANT_ID=...
   AZURE_CLIENT_SECRET=...
   ```
   لا تضع السر داخل `config/azure_sso.php` ولا ترفعه إلى Git.

---

### الخطوة 2: إضافة Redirect URIs في Azure

1. **في نفس App Registration:**
   - من القائمة الجانبية: **Authentication**
   - تحت **Web** → **Redirect URIs**

2. **أضف هذه الروابط:**
   ```
   https://portal.dmls.edu.eg/auth/microsoft_callback.php
   https://portal.dmls.edu.eg/auth/teams_sso.php
   http://localhost/EduCore/auth/microsoft_callback.php
   http://localhost/EduCore/auth/teams_sso.php
   ```

3. **اضغط Save**

في الوضع `auto` يختار الكود الإعداد حسب المضيف: `localhost`, `127.0.0.1`, و`::1` تستخدم مفاتيح `AZURE_LOCAL_*`، وأي مضيف آخر يستخدم مفاتيح الإنتاج `AZURE_*`. اضبط الإنتاج صراحة على `MICROSOFT_SSO_ENV=production`. يمكن ترك بيانات اعتماد Local فارغة لإعادة استخدام App Registration نفسه، أو استخدام تسجيل تطوير مستقل وهو الأفضل. زر Microsoft العادي يدعم callback من `http://localhost`.

لاختبار Teams محلياً استخدم HTTPS Dev Tunnel وmanifest تطوير، واضبط `MICROSOFT_SSO_ENV=local` مع `AZURE_LOCAL_REDIRECT_URI` و`AZURE_LOCAL_TEAMS_REDIRECT_URI` كاملين على نطاق الـTunnel؛ لا يشتق الكود callback من اسم Tunnel أو Host غير loopback حتى لا يتحكم Host غير موثوق في وجهة OAuth.

مطابقة الهوية تفشل مغلقة: يجب أن يساوي البريد الموثق القادم من Microsoft كلاً من `users.email` و`users.username` بعد تجاهل اختلاف حالة الأحرف. إذا كان `azure_id` مربوطاً ثم تغيّر بريد Microsoft، يُرفض الدخول ولا ينتقل الربط إلى حساب آخر؛ يعيد المسؤول مطابقة البريد واسم المستخدم أولاً. كما يُرفض أي تكرار ملتبس للبريد أو `azure_id`.

```env
MICROSOFT_SSO_ENV=production
AZURE_REDIRECT_URI=https://portal.dmls.edu.eg/auth/microsoft_callback.php
AZURE_TEAMS_REDIRECT_URI=https://portal.dmls.edu.eg/auth/teams_sso.php
AZURE_LOCAL_REDIRECT_URI=http://localhost/EduCore/auth/microsoft_callback.php
AZURE_LOCAL_TEAMS_REDIRECT_URI=http://localhost/EduCore/auth/teams_sso.php
```

---

### الخطوة 3: إعداد Application ID URI (لـ Teams SSO)

1. **في App Registration:**
   - من القائمة الجانبية: **Expose an API**
   - اضغط: **Set** بجانب Application ID URI

2. **أدخل القيمة:**
   ```
   api://portal.dmls.edu.eg/3328f82e-1290-456f-aece-96f2623c1385
   ```

3. **إضافة Scope:**
   - اضغط: **Add a scope**
   - Scope name: `access_as_user`
   - Who can consent: **Admins and users**
   - Admin consent display name: `Access DMLS Portal as user`
   - Admin consent description: `Allows the app to access DMLS Portal on behalf of the signed-in user`
   - User consent display name: `Access DMLS Portal`
   - User consent description: `Allow the app to access DMLS Portal on your behalf`
   - State: **Enabled**
   - اضغط: **Add scope**

---

### الخطوة 4: إضافة API Permissions

1. **في App Registration:**
   - من القائمة الجانبية: **API permissions**
   - اضغط: **Add a permission**

2. **أضف هذه الصلاحيات:**
   - **Microsoft Graph:**
     - `openid` (Delegated)
     - `profile` (Delegated)
     - `email` (Delegated)
     - `User.Read` (Delegated)

3. **منح موافقة المسؤول:**
   - اضغط: **Grant admin consent for [Tenant Name]**
   - اضغط: **Yes**

---

### الخطوة 5: تحديث قاعدة البيانات

1. **افتح phpMyAdmin:**
   - اذهب إلى: http://localhost/phpmyadmin
   - أو: https://portal.dmls.edu.eg/phpmyadmin

2. **اختر قاعدة البيانات:** `rewards_system`

3. **اذهب لتبويب SQL**

4. **الصق محتوى الملف:** `add_microsoft_sso.sql`

5. **اضغط Go**

---

### الخطوة 6: تثبيت مكتبة PHP-JWT

1. **افتح Terminal/CMD**

2. **اذهب لمجلد المشروع:**
   ```bash
   cd c:\xampp\htdocs\portal
   ```

3. **شغّل الأمر:**
   ```bash
   composer update
   ```

---

### الخطوة 7: تحديث تطبيق Teams

1. **اذهب إلى Teams Admin Center:**
   - https://admin.teams.microsoft.com

2. **Manage apps → DMLS**

3. **اضغط Upload file**

4. **أنشئ ملف ZIP يحتوي على:**
   - `manifest.json` (من مجلد `teams/`)
   - `color.png` (أيقونة ملونة 192x192)
   - `outline.png` (أيقونة outline 32x32)

5. **ارفع الملف**

---

## 📁 الملفات المُنشأة

| الملف | الوصف |
|-------|-------|
| `config/azure_sso.php` | إعدادات Azure AD |
| `classes/MicrosoftSSO.php` | كلاس المصادقة |
| `auth/microsoft_login.php` | بدء تسجيل الدخول |
| `auth/microsoft_callback.php` | استقبال الرد من Microsoft |
| `auth/teams_sso.php` | معالجة SSO من Teams |
| `auth/teams_token_handler.php` | معالجة التوكن |
| `teams/app.html` | صفحة التطبيق داخل Teams |
| `teams/manifest.json` | ملف manifest لـ Teams |
| `add_microsoft_sso.sql` | تحديث قاعدة البيانات |

---

## 🔗 الروابط المهمة

| الرابط | الوصف |
|--------|-------|
| `https://portal.dmls.edu.eg/auth/microsoft_login.php` | تسجيل الدخول عبر Microsoft |
| `https://portal.dmls.edu.eg/auth/teams_sso.php` | SSO من Teams |
| `https://portal.dmls.edu.eg/teams/app.html` | صفحة التطبيق داخل Teams |

---

## ⚙️ معلومات Azure App Registration

```
Display Name:        School Website SSO
Application ID:      3328f82e-1290-456f-aece-96f2623c1385
Directory (Tenant):  c039a6b7-fca3-4da7-a7dd-c96b5c96551e
Object ID:           5e733d4c-0d38-4de6-9ae3-20e2ed42378b
```

---

## 🔍 استكشاف الأخطاء

### الخطأ: "AADSTS50011: The redirect URI does not match"
**الحل:** تأكد من إضافة Redirect URIs بالضبط كما في الخطوة 2

### الخطأ: "invalid_client"
**الحل:** تأكد من صحة Client Secret في ملف `config/azure_sso.php`

### الخطأ: "الحساب غير موجود"
**الحل:** تأكد من أن:
- البريد الإلكتروني أو اسم المستخدم في موقع المدرسة يتطابق مع Microsoft
- أو قم بتحديث بيانات الطالب لتشمل البريد الإلكتروني

### الخطأ: "consent_required"
**الحل:** اذهب لـ API permissions واضغط "Grant admin consent"

---

## 📱 اختبار النظام

### اختبار من المتصفح:
1. افتح: `https://portal.dmls.edu.eg/auth/microsoft_login.php`
2. سجّل دخول بحساب طالب
3. يجب أن يتم تسجيل الدخول ويُوجَّه للوحة التحكم

### اختبار من Teams:
1. افتح Microsoft Teams
2. اضغط على تطبيق DMLS
3. يجب أن يتم تسجيل الدخول تلقائياً

---

## 🔐 ملاحظات أمنية

1. **لا تشارك Client Secret** مع أي شخص
2. **غيّر SSO_DEBUG_MODE إلى false** في الإنتاج
3. **استخدم HTTPS دائماً** (موجود بالفعل)
4. **راجع سجلات الدخول** بانتظام

---

## 📞 الدعم

إذا واجهت أي مشكلة:
1. راجع سجلات الأخطاء في: `c:\xampp\php\logs\php_error.log`
2. تأكد من صحة جميع الإعدادات في Azure Portal
3. تحقق من اتصال الإنترنت والـ SSL

---

**تم الإعداد بواسطة:** نظام بوابة المدرسة  
**التاريخ:** يناير 2026  
**الإصدار:** 1.0
