# عقد الدخول التلقائي من Teams

## المشاركون

- غلاف Teams: `teams/app.html`
- Teams JavaScript SDK
- خادم التحقق: `auth/teams_token_handler.php`
- خدمة الهوية الحالية: `classes/MicrosoftSSO.php`
- البوابة fallback: `index.php?from_teams=1&skip_intro=1`

## آلة حالات الغلاف

```text
initializing
  -> requesting_token
  -> authenticating
  -> authenticated -> dashboard
  -> fallback -> unified portal
```

- انتقال واحد فقط إلى `requesting_token` لكل تحميل.
- timeout أو رفض أو استثناء ينتقل إلى fallback ولا يعيد المحاولة تلقائياً بلا نهاية.
- زر إعادة المحاولة في fallback يبدأ محاولة جديدة بتفاعل المستخدم فقط.

## طلب token للخادم

`POST /auth/teams_token_handler.php`

```http
Content-Type: application/json
Accept: application/json
Origin: https://portal.dmls.edu.eg

{"token":"<opaque Teams SSO token>"}
```

### قواعد النقل

- same-origin فقط؛ لا CORS عام.
- لا token في query string أو localStorage أو console.
- يتحقق الخادم من `Origin`/سياق الطلب وفق البيئات المسموحة.
- يقبل الشكل legacy الحالي مؤقتاً أثناء الانتقال فقط مع الضوابط نفسها، ثم يُزال في إصدار توافق منفصل.
- `stage` إن وصل يُهمل في قرار الهوية.

## استجابة النجاح

```json
{
  "success": true,
  "redirect": "/student/dashboard.php"
}
```

- `redirect` مسار نسبي من قائمة وجهات الأدوار الحالية.
- تظل المفاتيح الأساسية متوافقة مع المستهلك الحالي.
- session cookie تُنشأ بإعدادات الجلسة الحالية قبل الرد.

## استجابة fallback

```json
{
  "success": false,
  "code": "not_linked",
  "message": "تعذر تسجيل الدخول تلقائياً. يمكنك استخدام إحدى طرق الدخول المتاحة.",
  "fallback_url": "/index.php?from_teams=1&skip_intro=1&sso=not_linked"
}
```

### الأكواد العامة

| الكود | الاستخدام | يكشف تفاصيل؟ |
|---|---|---|
| `token_unavailable` | Teams لم يصدر رمزاً | لا |
| `token_invalid` | فشل التحقق العام | لا |
| `not_linked` | Microsoft ID/البريد ناقص | لا |
| `identity_mismatch` | البريد لم يطابق email وusername | لا يذكر أيهما |
| `ambiguous_account` | لم يثبت حساب واحد | لا يذكر الحسابات |
| `account_disabled` | الحساب معطل | يعرض سياسة السبب المعتمدة |
| `temporarily_unavailable` | خطأ خدمة أو dependency | لا stack trace |

## قاعدة الحساب المعطل

- `account_disabled` مع سبب أدمن غير فارغ: `message` تساوي السبب فقط.
- سبب فارغ: `message` تساوي الرسالة العامة فقط.
- لا تضيف الواجهة Prefix مثل «سبب التعطيل:» إلى السبب المخصص.

## التحقق الخادمي

1. التوقيع والخوارزمية والمفاتيح.
2. `exp`/`nbf` وسماحية زمنية محدودة.
3. `aud` يطابق resource/client المعتمد.
4. `iss` و`tid` يطابقان المستأجر المعتمد.
5. استخراج Microsoft object ID والبريد الموثق من claim معتمدة/Graph وفق السياسة الحالية.
6. حساب محلي واحد مرتبط بنفس ID.
7. تطابق البريد الموثق مع البريد واسم المستخدم بعد trim/lowercase.
8. الحالة والدور والجلسة وسياسة التعطيل.

## fallback داخل Teams

- يحمّل الغلاف `fallback_url` في المساحة الرئيسية أو الإطار الحالي.
- تظهر طرق Microsoft اليدوي واسم المستخدم/كلمة المرور وزر المواد المباشر فقط.
- لا فيديو مقدمة.
- marker خاص بالتبويب يمنع تشغيل auto SSO مرة أخرى بسبب تحميل fallback نفسه.

## توافق المحلي والإنتاج

| البيئة | origin المتوقع |
|---|---|
| local browser test | `http://localhost` مع base path `/EduCore` |
| production Teams | `https://portal.dmls.edu.eg` |

اختبار Teams SDK الحقيقي قد يتطلب HTTPS/domain معتمداً؛ الاختبارات المحلية تفصل منطق الغلاف والتحقق عن الاعتماد الخارجي، ثم يكتمل اختبار end-to-end في مستأجر تجريبي.
