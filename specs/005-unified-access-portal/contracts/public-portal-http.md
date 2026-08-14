# عقد HTTP للبوابة العامة

## مبادئ ثابتة

- المسارات الحالية تبقى صالحة أثناء الانتقال.
- لا تعتمد المصادقة على `stage`.
- كل redirect يظل داخل origin النظام ومن allow-list.
- رسائل الخطأ لا تحتوي token أو stack trace أو SQL أو معرفات داخلية.
- الواجهة قد تخفي خدمة معطلة، لكن الخادم هو الذي يمنع الوصول فعلياً.

## `GET /index.php`

### المدخلات التوافقية

| المعامل | المعنى | القرار |
|---|---|---|
| `stage` | رابط قديم | يقبل ويُهمل؛ لا يعرض مرحلة |
| `from_teams=1` | سياق عرض Teams | يتخطى المقدمة فقط ولا يمنح جلسة |
| `skip_intro=1` | توافق داخلي | يقبل فقط وفق سياسة المقدمة، ولا يمنح صلاحية |
| `error` | كود عرض عام | يُحوّل إلى رسالة allow-listed |

### النتائج

- جلسة صالحة: `302` إلى لوحة الدور الحالية.
- زائر يحتاج المقدمة: `302` إلى `intro_youtube.php?destination=portal`.
- زائر عادي: `200` وبطاقة الدخول الموحدة.
- أعلام الميزة متوقفة: السلوك legacy المؤقت وفق خطة التراجع.

## `POST /login.php`

يحافظ على أسماء الحقول الحالية:

```text
username=<string>
password=<string>
csrf_token=<existing contract, if currently required>
stage=<optional legacy, ignored for auth>
```

### النتائج

- نجاح: الجلسة الحالية ثم `302` إلى لوحة الدور.
- بيانات غير صحيحة: عودة إلى البوابة الموحدة مع flash عام.
- حساب معطل بسبب مخصص: عودة مع نص الأدمن وحده.
- حساب معطل بلا سبب: عودة مع الرسالة العامة وحدها.
- لا يعيد `login.php` رسم بوابة مختلفة أو أسماء مراحل بعد اكتمال الانتقال.

## `GET /login.php`

- يحول إلى `/index.php` مع نقل كود flash آمن عند الحاجة.
- يقبل `stage` توافقياً ولا يعرض اسم المرحلة.

## `GET /guest.php`

### النتائج

- تحويل `302` إلى `materials.php` فقط للتوافق مع الروابط المحفوظة.
- لا يعرض صفحة أو خدمات، ولا ينشئ session دور `guest` ولا صف مستخدم.

## `GET /materials.php`

### المدخلات

فلاتر توافق allow-listed مثل `grade_id` و`term` فقط. لا يقبل مسار ملف.

### القرار

1. سياسة المقدمة مستوفاة أو تم الرجوع منها.
2. الوجهة محلية ثابتة داخل `student/materials/`.

### النتائج

- `302`: إلى `intro_youtube.php?destination=materials` في أول زيارة عادية.
- `302`: إلى `student/materials/` أو `student/materials/view.php` بعد استيفاء المقدمة.

## `GET /material_download.php?id=<opaque-or-integer-id>`

### التحقق الإلزامي وقت الطلب

- المعرف صالح.
- المادة `enabled=1` و`downloadable=1` ومرحلتها وصفها نشطان.
- الملف ضمن التخزين المصنف المسموح وعقد التنزيل الحالي.

### النتائج

- `200`: stream آمن مع Content-Type وContent-Disposition مضبوطين.
- `404`: أي فشل publication/وجود/سياسة.
- لا redirect مباشر إلى مسار filesystem أو `uploads/` إذا كان عقد المشروع يتطلب controller.

## `GET /intro_youtube.php?destination=<key>`

### مفاتيح الوجهة

| المفتاح | المسار |
|---|---|
| `portal` | `/index.php` |
| `materials` | `/materials.php` |

- أي مفتاح آخر يعود إلى `portal` أو يُرفض.
- لا يقبل `return_url` خارجياً.
- عند الإكمال/التخطي المعتمد يضبط مؤشر 15 يوماً ثم يحول إلى المسار.
- سياق Teams يتخطى الصفحة قبل التشغيل.

## `GET|POST /admin/public_portal_settings.php`

### العقد الحالي

- `GET` يتحقق أولاً عبر `Utilities::validateSession('admin')` ثم يحول `302` إلى `materials_center.php`.
- لا يقبل POST ولا يكتب إعداداً؛ إدارة `enabled/downloadable` تبقى لدى مركز المواد الحالي وتدقيقه.
