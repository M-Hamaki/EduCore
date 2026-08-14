# خطة التنفيذ: بوابة الوصول الموحدة والدخول التلقائي عبر Teams

> **تعديل متطلبات في 2026-08-11:** ألغي وضع الضيف وكتالوج خدماته وإعداداته. التنفيذ الحالي يعرض زر مواد مباشر واحد، ويحافظ `materials.php` على المقدمة ثم يحول إلى `student/materials/`. تتحكم أعلام `enabled/downloadable` الحالية في مركز المواد في العرض والتنزيل للطلاب والزوار. أي أقسام لاحقة تصف كتالوج أو إعداد خدمات الضيف هي سجل للخطة السابقة وقد استبدلها هذا القرار.

**الميزة:** `005-unified-access-portal`  
**الفرع المقترح لاحقاً:** `codex/005-unified-access-portal`  
**المواصفات:** [spec.md](spec.md)  
**تاريخ الخطة:** 2026-08-11

## الملخص التنفيذي

يُنفذ التغيير كامتداد تدريجي للـ modular monolith الحالي، لا كنظام مصادقة جديد. تظل نقاط الدخول والجلسات والأدوار الحالية هي المرجع، بينما تُستخرج سياسات العرض العام والضيف والمقدمة إلى وحدة داخلية صغيرة. تستخدم الصفحة الرئيسية مكوّن دخول مشتركاً، ويبقى `login.php` محولاً/معالجاً متوافقاً مع الطلبات القديمة. يبدأ غلاف Teams محاولة الرمز تلقائياً، ويستخدم نفس خدمة Microsoft SSO الحالية مع تشديد التطابق في كل دخول. الخدمات العامة تُدار من قائمة ثابتة ويُفرض تفعيلها على الخادم، والمواد العامة لا تصبح متاحة لمجرد وجود ملف في مركز المواد.

بدأ التنفيذ في 2026-08-11 فوق شجرة عمل موجودة وكبيرة مع الحفاظ على كل التغييرات السابقة. سجل التنفيذ والقرارات الفعلية ونتائج الاختبارات في `implementation-notes.md`؛ لم تُغيّر `.specify/feature.json` لأن تفعيل ملف مواصفات آخر ليس شرط تشغيل للتطبيق وقد يتداخل مع عمل المستخدم القائم.

## السياق التقني

| البند | القرار |
|---|---|
| المنصة | PHP 8.0+، PDO، MariaDB/MySQL، Bootstrap 5 RTL، JavaScript عادي |
| نمط النظام | modular monolith تدريجي؛ لا Router أو Auth stack جديد |
| نقاط الدخول العامة | `index.php`، `login.php` كمدخل متوافق، ونقاط دخول عامة صغيرة للضيف والمواد |
| SSO | إعادة استخدام `classes/MicrosoftSSO.php` ومسارات `auth/` الحالية |
| Teams | غلاف `teams/app.html` يطلب token فور التهيئة ويرسله للخادم من نفس origin |
| هوية Teams | Microsoft ID مرتبط + بريد Microsoft موثق يطابق `users.email` و`users.username` بعد تطبيع البريد |
| الخدمات العامة | كتالوج مفاتيح ثابت في الكود + إعداد `public_portal_services` داخل جدول `settings` القائم |
| نشر المواد للضيف | إعادة استخدام `materials.enabled` و`materials.downloadable` مع إتاحة الخدمة default-deny |
| المقدمة | مؤشر متصفح مدته 15 يوماً مع fallback جلسة، وتخطي إلزامي داخل Teams |
| الأثر التدقيقي | خدمة Audit المشتركة؛ فشل التسجيل يلغي تغيير الإعدادات |
| التراجع | أعلام تشغيل + تغييرات schema إضافية غير مدمرة + بقاء نقاط الدخول القديمة |

## فحص الدستور والمعمارية

### قبل التنفيذ

- [ ] قراءة `AGENTS.md` والوثائق المعمارية المرتبطة من القرص في جلسة التنفيذ.
- [ ] تشغيل `git status` وتسجيل كل ملف متغير مسبقاً؛ التوقف عند تداخل غير قابل للفصل.
- [ ] إضافة اختبارات characterization قبل تقسيم `index.php` أو `login.php` أو `teams/app.html`.
- [ ] إثبات مخطط `materials` وسياسة التنزيل الحالية قبل تصميم migration نشر المواد.
- [ ] استخدام قاعدة بيانات اختبار معزولة؛ يمنع تشغيل migrations أو اختبارات كتابة على `educore` الفعلية.
- [ ] تحديد عناوين Entra Redirect URI في المحلي والإنتاج واختبار المطابقة الحرفية.

### أثناء التنفيذ

- [ ] Presentation تستدعي Application services؛ لا تُنقل سياسات الهوية أو الضيف إلى JavaScript.
- [ ] Domain/Contracts لا تعتمد على HTTP أو PDO أو session.
- [ ] لا وصول جديد إلى internals وحدة المواد دون `MaterialCatalogQuery` موثق.
- [ ] لا DDL وقت الطلب؛ كل schema في `database/migrations/`.
- [ ] لا CSS زر داخل الصفحة؛ الواجهة العامة تستخدم ملف CSS مركزياً جديداً وتحافظ على `buttons.css`.
- [ ] كل كتابة إعدادات تمر بالتدقيق المشترك وفي معاملة واحدة.

### قبل الإغلاق

- [ ] اختبارات المصادقة والأدوار والتعطيل وSSO والضيف والمقدمة والتنزيل العام.
- [ ] `composer audit-write-coverage` بنتيجة `AUDIT_REVIEW_REQUIRED=0`.
- [ ] `composer architecture-audit` دون توسيع baseline لإخفاء دين جديد.
- [ ] `composer quality` و`git diff --check` ومراجعة diff scoped.
- [ ] تحديث ADR والذاكرة ووثيقة Microsoft SSO وخطة النشر/التراجع.

## خط الأساس الذي يجب الحفاظ عليه

- الشكل العام والشعار والخلفية والتذييل والوضع الداكن في `index.php`.
- أسماء حقول الدخول والعقود الحالية، وتحويلات لوحات الأدوار ومفاتيح الجلسة.
- نقاط Microsoft الحالية وإعداد اختيار callback بين local وproduction.
- سياسة تعطيل الحساب الموجودة: سبب مخصص = نص الأدمن فقط، سبب فارغ = الرسالة العامة فقط، للفردي والجماعي وكل طرق الدخول.
- مركز المواد وسير رفع الملفات الحالي؛ لا تُنقل الملفات ولا تُغيّر مسارات التخزين كجزء من هذه الميزة.
- الروابط القديمة التي تحمل `stage`؛ تُعامل كمدخلات توافقية ولا تعيد بطاقات المراحل.
- كل التغييرات الموجودة حالياً في شجرة العمل، وخصوصاً `.specify/feature.json` وميزات `003` و`004` وأعمال SSO الحالية.

## البنية المستهدفة

```text
Browser / Teams shell
        |
        v
Public entrypoints (index.php, login.php, guest.php, materials.php)
        |
        v
PublicPortal application services --------------------+
        |                                              |
        v                                              v
Policies/contracts                           Existing MicrosoftSSO
        |                                              |
        v                                              v
PDO repositories + Materials query adapter     Existing sessions/roles
        |
        v
public service settings / explicit material publication
```

### الملفات الجديدة المتوقعة

```text
src/Modules/PublicPortal/
├── Application/
│   ├── GetPublicPortalView.php
│   ├── GetGuestServices.php
│   ├── UpdateGuestServices.php
│   └── GetPublicMaterials.php
├── Contracts/
│   ├── PublicPortalRepository.php
│   └── MaterialCatalogQuery.php
├── Domain/
│   ├── PublicServiceCatalog.php
│   ├── GuestAccessPolicy.php
│   └── IntroVisitPolicy.php
└── Infrastructure/
    ├── PdoPublicPortalRepository.php
    └── LegacyMaterialCatalogAdapter.php

includes/public_login_portal.php
config/public_portal.php
assets/css/public-portal.css
assets/js/public-portal.js
guest.php
materials.php
material_download.php
admin/public_portal_settings.php
```

تظل هذه أسماء مستهدفة. إذا أثبت فحص بداية التنفيذ وجود عقد مطابق بالفعل، يُعاد استخدامه بدلاً من إنشاء نسخة مكررة ويُسجل ذلك في `research.md` وADR.

## تدفق الطلبات المستهدف

### زيارة متصفح عادي

1. `index.php` يحدد السياق دون منح صلاحية.
2. `IntroVisitPolicy` يقرر العرض من مؤشر 15 يوماً.
3. إن لزم الفيديو، ينتقل إلى `intro_youtube.php` مع وجهة عودة داخلية مسموح بها.
4. بعده تعرض الصفحة بطاقة الدخول المشتركة والخدمات العامة المفعلة.
5. الدخول اليدوي يظل عبر المعالج الحالي بعد إزالة اعتماد المرحلة.

### فتح رابط المواد مباشرة

1. `materials.php` يمر أولاً بسياسة المقدمة للزائر العادي.
2. وجهة العودة معرف مسار داخلي معروف، لا URL خام من المستخدم.
3. الصفحة تعرض فقط منشورات المواد العامة.
4. `material_download.php` يعيد التحقق من النشر والحالة وقت التنزيل.

### فتح تطبيق Teams

1. `teams/app.html` يهيئ Teams SDK ويعرض حالة تحميل قصيرة.
2. يطلب `getAuthToken()` تلقائياً مرة واحدة مع مهلة زمنية.
3. يرسل الرمز عبر HTTPS POST إلى `auth/teams_token_handler.php` من نفس origin؛ لا يُخزن في localStorage ولا يُرسل عبر query string.
4. الخادم يتحقق من التوقيع والجمهور والمستأجر والانتهاء، ثم يستخرج البريد الموثق وMicrosoft ID.
5. خدمة SSO تحدد حساباً واحداً وتعيد فحص الربط وتطابق البريد مع البريد واسم المستخدم والحالة والدور.
6. النجاح ينشئ الجلسة الحالية ويعيد وجهة الدور؛ الفشل يعيد `fallback_url` للبوابة الموحدة مع كود عام.
7. الفيديو يُتخطى دائماً، ولا تحدث محاولة تلقائية ثانية في نفس التحميل لمنع الحلقة.

## مراحل التنفيذ

### المرحلة 0 — الحماية والتوصيف

- جرد شجرة العمل وتحديد الملفات المتداخلة.
- characterization للاستخدام الحالي لـ`index.php` و`login.php` وTeams وسبب التعطيل.
- توثيق مخطط المواد والتنزيل وإعدادات الأدمن والتدقيق الحالية.
- إنشاء قاعدة اختبار معزولة وخطة rollback للـ migrations.

### المرحلة 1 — الأساس الداخلي والبيانات

- إضافة وحدة PublicPortal وعقودها الأصغر.
- إضافة إعدادات الخدمات العامة عبر جدول `settings` القائم، وإعادة استخدام مؤشري نشر المواد المؤكدين.
- تسجيل سياسات التدقيق/undo/redaction قبل أي واجهة كتابة.
- إضافة أعلام التشغيل دون تغيير السلوك الافتراضي أثناء التطوير.

### المرحلة 2 — البوابة الموحدة

- استخراج بطاقة الدخول المشتركة من السلوك الحالي، لا نسخ Auth logic.
- نقل الهوية البصرية إلى `assets/css/public-portal.css` تدريجياً مع الحفاظ على الشكل.
- جعل `index.php` يعرض البوابة، و`login.php` يحافظ على POST/GET القديم دون مرحلة.
- إبقاء معاملات المرحلة مقبولة ومهملة توافقياً.

### المرحلة 3 — الضيف والمواد العامة

- إنشاء صفحة الضيف من كتالوج الخادم.
- إضافة إعداد أدمن للتفعيل والترتيب والنشر العام للمواد.
- إضافة قائمة وتنزيل عام يعيدان فحص السياسة في كل طلب.
- اختبار enumeration والوصول المباشر والملفات المحذوفة أو الخاصة.

### المرحلة 4 — Teams SSO التلقائي

- إضافة اختبارات التطابق المستمر أولاً.
- جعل غلاف Teams يبدأ SSO آلياً ويعرض loading/fallback.
- منع auto-link في المسار الصامت والحلقات وإرسال الرموز إلى الطرف الأمامي.
- الحفاظ على زر Microsoft التفاعلي كبديل.

### المرحلة 5 — فيديو المقدمة

- تطبيق 15 يوماً بمؤشر متصفح صالح ومدخل جلسة احتياطي.
- تخطي Teams دائماً.
- دعم عودة المواد بقائمة وجهات مسموح بها.

### المرحلة 6 — التقوية والإطلاق التدريجي

- تشغيل الاختبارات وبوابات الجودة والتدقيق.
- نشر schema والكود والأعلام في وضع off أولاً.
- تجربة الأدمن ثم حسابات Teams تجريبية ثم نسبة/مجموعة محدودة.
- تفعيل البوابة الموحدة، ثم Teams auto SSO بصورة مستقلة.

## الأعلام وخطة التراجع

| العلم | القيمة الافتراضية أثناء النشر | وظيفة التراجع |
|---|---:|---|
| `UNIFIED_ACCESS_PORTAL_ENABLED` | `true` بعد القبول | يعيد مدخل الصفحة العام إلى `public_portal.php` القديم مؤقتاً عند ضبطه `false` |
| `TEAMS_AUTO_SSO_ENABLED` | `false` | يوقف المحاولة الصامتة ويبقي البوابة وخياراتها |

- لا توجد migration لهذه الميزة؛ إعداد الخدمات محفوظ في جدول `settings` الحالي.
- تعطيل العلم لا يحذف إعدادات الخدمات أو سجلات التدقيق.
- عند عيب أمني في المواد، تعطَّل خدمة `materials` من إعدادات الأدمن فوراً، ثم يُوقف علم البوابة عند الحاجة.
- rollback الكود يحافظ على schema الإضافي حتى إصدار تنظيف منفصل ومراجع.

## أمن وخصوصية

- التحقق من token على الخادم فقط، مع allow-list للمستأجر والجمهور.
- المقارنة المستمرة للبريد تمنع استخدام Microsoft ID قديم بعد تغيير البريد.
- لا auto-link ولا اختيار أول نتيجة عند التعدد.
- لا token في URL أو logs أو audit أو localStorage.
- لا `postMessage('*')`؛ وإن بقي postMessage لسبب توافق، يتحقق الطرفان من `origin` و`source`.
- وجهات العودة للمقدمة والخطأ مفاتيح داخلية معروفة، لا redirect URL من المستخدم.
- الضيف بلا صف مستخدم وبلا session دور؛ كل endpoint عام يطبق policy صريحة.
- التنزيل العام يعيد فحص publication والحالة والملف، ويضبط Content-Disposition وMIME وفق المسار الآمن الحالي.
- رسائل فشل الربط عامة، بينما رسالة التعطيل تتبع نص الأدمن المطلوب فقط.

## إستراتيجية الاختبار

| المجال | اختبارات مطلوبة |
|---|---|
| الواجهة العامة | visual/DOM contract، RTL، responsive، dark mode، عدم وجود مراحل |
| الدخول اليدوي | صحيح/خطأ/معطل/دور غير صالح/جلسة موجودة |
| Microsoft | local/prod callback selection، فشل آمن، AuditService غير متاح |
| Teams | success، timeout، denial، invalid aud/iss/tid/exp/signature، mismatch، missing link، duplicate account |
| التعطيل | فردي/جماعي × يدوي/Microsoft/Teams/session × سبب مخصص/فارغ |
| الضيف | enabled/disabled/direct URL/unknown service/no services |
| المواد | list/download/private/deleted/disabled service/path traversal/enumeration |
| المقدمة | first/<15/=15/>15/Teams/direct materials/cookies blocked/bad return target |
| الإدارة | auth-before-processing، CSRF، validation، audit atomicity، concurrency |
| التوافق | `login.php?stage=...`، `index.php?from_teams=1`، العقود الحالية ووجهات الأدوار |

## خطة إعداد Microsoft Entra الخارجية

يجب قبل الاختبار الحقيقي تسجيل كل Redirect URI مطابق حرفياً لما يرسله النظام، على الأقل:

- `http://localhost/EduCore/auth/microsoft_callback.php`
- `https://portal.dmls.edu.eg/auth/microsoft_callback.php`
- أي Teams redirect فعلي ما زالت تستخدمه إعدادات `AZURE_LOCAL_TEAMS_REDIRECT_URI` و`AZURE_TEAMS_REDIRECT_URI` بعد مراجعة التدفق النهائي.

هذه خطوة في Entra Portal وليست migration أو تعديل كود. فشلها ينتج `AADSTS50011` حتى لو كان اختيار البيئة في PHP صحيحاً.

## المخاطر والمعالجات

| الخطر | المعالجة |
|---|---|
| كشف مواد غير مقصودة | نشر صريح default-deny + تحقق خادم في القائمة والتنزيل |
| كسر صفحات الدخول القديمة | adapter وعقود characterization ومعاملات مرحلة مهملة |
| حلقة Teams fallback | محاولة تلقائية واحدة + marker ذاكرة للتبويب + fallback ثابت |
| سرقة/تسريب token | POST same-origin، عدم التخزين/التسجيل، CSP/origin checks |
| تغيير بريد Microsoft | إعادة فحص البريد مع email وusername في كل دخول |
| تداخل مع تغييرات حالية | preflight diff، تغيير صغير لكل مرحلة، توقف عند overlap |
| فشل AuditService | dependency/fallback آمن للاستخدام غير الكتابي، وفشل مغلق عند كتابة الإعدادات |
| عدم تطابق local/prod | مصفوفة إعداد واختبارات callback، وأعلام تشغيل مستقلة |
| تكرار بنية موجودة | بحث إلزامي في بداية التنفيذ وإعادة استخدام العقود المؤكدة |

## المخرجات المرتبطة

- [البحث والقرارات](research.md)
- [نموذج البيانات](data-model.md)
- [عقود HTTP والواجهة](contracts/)
- [دليل البدء لاحقاً](quickstart.md)
- [مهام التنفيذ](tasks.md)
