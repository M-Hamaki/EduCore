# دليل البدء لاحقاً

> **تعديل 2026-08-11:** ألغي وضع الضيف. اختبر زر المواد المباشر و`student/materials/` بدلاً من سيناريوهات خدمة/صفحة الضيف المذكورة تاريخياً أدناه.

هذا الدليل لا يعني بدء التنفيذ الآن. الغرض أن تكون الخطوة الأولى لاحقاً واضحة وآمنة.

## 1. قبل تفعيل هذه الميزة في Spec Kit

1. أنهِ أو احفظ العمل الجاري المرتبط بـ`specs/004-integrated-staff-affairs`.
2. راجع `git status` ولا تستخدم reset/clean.
3. أنشئ فرعاً مستقلاً فقط بعد موافقة المستخدم، مثل `codex/005-unified-access-portal`.
4. حدّث `.specify/feature.json` عمداً إلى `specs/005-unified-access-portal` عند بدء التنفيذ فقط، لا أثناء التخطيط الحالي.
5. أعد قراءة `AGENTS.md` و`docs/architecture.md` و`docs/database.md` و`docs/architecture-decisions.md` و`docs/file-upload-standard.md`.

## 2. جرد وحماية شجرة العمل

شغّل في PowerShell من `C:\xampp\htdocs\EduCore`:

```powershell
git status --short
git diff --check
```

أنشئ قائمة in-scope/out-of-scope. إذا كان hunk مطلوباً في `index.php` أو `login.php` أو `classes/MicrosoftSSO.php` متداخلاً مع تعديل غير مكتمل، توقف وافصل العمل بالتنسيق مع المستخدم.

## 3. قاعدة بيانات اختبار معزولة

- لا تستخدم قاعدة `educore` الفعلية للاختبارات الكتابية أو migrations التجريبية.
- أنشئ قاعدة اختبار منفصلة وبيانات حسابات وهمية تشمل الأدوار والحالات التالية:
  - مرتبط ومطابق.
  - Microsoft ID مطابق والبريد متغير.
  - البريد مطابق لكن ID مفقود.
  - حسابان متعارضان.
  - حساب معطل بسبب مخصص.
  - حساب معطل بلا سبب.
- وثق أمر migration وdown/rollback الفعليين بعد التأكد من convention الموجودة.

## 4. فحص إلزامي قبل schema

افحص من القرص:

- `admin/materials_center.php`
- كل entrypoint فعلي داخل `student/materials/`
- migrations التي أنشأت جدول المواد.
- download helpers والسياسات الحالية.
- audit policy registry ومسار undo.

اكتب النتيجة في `research.md`:

- إن وُجد معنى «عام للضيف» صريح وآمن، أعد استخدامه.
- إن لم يوجد، نفذ projection `public_material_publications` من `data-model.md`.
- لا تضف حقلاً أو logger أو download helper مكرراً.

## 5. إعداد Microsoft Entra قبل اختبار OAuth الحقيقي

في App Registration ذي Client ID المستخدم في البيئة، أضف/تحقق من Redirect URIs حرفياً:

```text
http://localhost/EduCore/auth/microsoft_callback.php
https://portal.dmls.edu.eg/auth/microsoft_callback.php
```

ثم طابق أي Teams redirect ما زال مستخدماً مع:

```text
AZURE_LOCAL_TEAMS_REDIRECT_URI
AZURE_TEAMS_REDIRECT_URI
```

ملاحظات:

- `AADSTS50011` يعني أن URI المرسل غير مسجل حرفياً في Entra؛ تعديل PHP وحده لا يكفي.
- لا تضع Client Secret في الكود أو `.env.example`.
- اختبار Teams الصامت الحقيقي يحتاج manifest/domain/resource متطابقاً وحساباً تجريبياً داخل المستأجر.

## 6. ترتيب التنفيذ

نفذ [tasks.md](tasks.md) بالترتيب:

1. التوصيف والحماية.
2. البنية الداخلية والبيانات.
3. البوابة الموحدة.
4. الضيف والمواد.
5. Teams التلقائي وfallback.
6. المقدمة.
7. الاختبارات والإطلاق التدريجي.

لا تفعّل `UNIFIED_ACCESS_PORTAL_ENABLED` أو `TEAMS_AUTO_SSO_ENABLED` قبل اجتياز اختبارات المرحلة.

## 7. مصفوفة التحقق اليدوي

| البيئة | السيناريو | النتيجة |
|---|---|---|
| Local browser | `index.php` أول مرة | فيديو ثم بوابة بلا مراحل |
| Local browser | دخول يدوي | لوحة الدور |
| Local OAuth | زر Microsoft | callback المحلي المسجل |
| Production browser | زر Microsoft | callback الإنتاجي |
| Teams linked | فتح التطبيق | لوحة مباشرة بلا فيديو/نقرة |
| Teams unlinked | فتح التطبيق | بوابة موحدة بلا حلقة |
| Teams changed email | فتح التطبيق | رفض صامت ثم بوابة |
| Guest | خدمة مفعلة/معطلة | تطابق واجهة وخادم |
| Direct materials | أول مرة | فيديو ثم المواد |
| Disabled account | سبب مخصص/فارغ | المخصص وحده/العام وحده |

## 8. أوامر الجودة قبل التسليم

استخدم PHP المحلي المعتمد للمشروع واختبارات الميزة، ثم:

```powershell
composer audit-write-coverage
composer architecture-audit
composer quality
git diff --check
git status --short
```

راجع كل diff يدوياً. لا توسع audit baseline ولا reclassify gate لمجرد النجاح.

## 9. النشر والتراجع

1. خذ نسخة قاعدة بيانات وفق إجراء التشغيل المعتمد.
2. انشر migrations الإضافية والكود والأعلام `false`.
3. شغّل smoke tests للبوابة القديمة والجديدة مع العلم مغلقاً.
4. فعّل `UNIFIED_ACCESS_PORTAL_ENABLED` لمجموعة اختبار.
5. فعّل `TEAMS_AUTO_SSO_ENABLED` بعد نجاح حسابات Teams المطابقة وغير المطابقة.
6. راقب أخطاء SSO و403/404 للمواد وحلقات redirect دون تسجيل tokens.

عند المشكلة:

- أوقف `TEAMS_AUTO_SSO_ENABLED` أولاً مع بقاء طرق الدخول.
- عطّل خدمة المواد من الإعداد إذا كان الخطر في النشر العام.
- أوقف `UNIFIED_ACCESS_PORTAL_ENABLED` عند عيب واجهة/توافق شامل.
- لا تسقط schema في rollback الفوري؛ احتفظ به لإصلاح أو إصدار تنظيف مراجع.
