# Microsoft Teams SSO Setup Guide

هذا الدليل يشرح إعداد Microsoft SSO وTeams لنشر EduCore عام. استبدل `school.example.com` بنطاق المؤسسة، ولا تضع أسرار Entra أو بيانات الإنتاج في المستودع.

## المتطلبات

- تسجيل تطبيق في Microsoft Entra ID.
- حسابات أو مجموعات المؤسسة التي ستستخدم SSO.
- HTTPS في الإنتاج.
- قيم `AZURE_CLIENT_ID` و`AZURE_TENANT_ID` و`AZURE_CLIENT_SECRET` في `.env` فقط.

## Redirect URIs

سجّل القيم التالية بعد استبدال النطاق:

```text
https://school.example.com/auth/microsoft_callback.php
https://school.example.com/auth/teams_sso.php
```

وفي `.env`:

```dotenv
MICROSOFT_SSO_ENV=production
AZURE_REDIRECT_URI=https://school.example.com/auth/microsoft_callback.php
AZURE_TEAMS_REDIRECT_URI=https://school.example.com/auth/teams_sso.php
TEAMS_APP_ID_URI=api://school.example.com/your-client-id
```

تأكد من تطابق القيم حرفيًا مع تسجيل Entra. لا تعتمد على fallback خاص بمؤسسة أو نطاق بعينه.

## الصلاحيات والتحقق

امنح أقل صلاحيات ممكنة، وراجع موافقة المشرف. يتحقق التطبيق من التوقيع والجهة المصدرة والجمهور والمستأجر وانتهاء الصلاحية، ثم يطابق هوية Microsoft مع الحساب المحلي وفق سياسة النشر.

## Teams manifest

1. انسخ `teams/manifest.example.json` إلى `teams/manifest.json` محليًا.
2. غيّر `id` و`webApplicationInfo.id` إلى App ID الخاص بالمؤسسة.
3. غيّر `websiteUrl` و`privacyUrl` و`termsOfUseUrl` و`contentUrl` والنطاقات الصالحة.
4. أنشئ حزمة Teams من النسخة المحلية فقط. الملف الإنتاجي متجاهل في Git عمدًا.

## التطوير المحلي

يمكن استخدام loopback فقط في التطوير المحلي:

```dotenv
MICROSOFT_SSO_ENV=local
AZURE_LOCAL_REDIRECT_URI=http://localhost/EduCore/auth/microsoft_callback.php
AZURE_LOCAL_TEAMS_REDIRECT_URI=http://localhost/EduCore/auth/teams_sso.php
AZURE_LOCAL_TEAMS_APP_ID_URI=api://localhost/your-local-client-id
```

لـ tunnel تطويري، اضبط callbacks صريحة وآمنة بدل السماح لـ Host header باختيار عنوان عشوائي.

## الاختبار

```bash
php tests/microsoft_sso_environment_test.php
```

يجب أن يظهر:

```text
MICROSOFT_SSO_ENVIRONMENT_TEST_PASSED
```

## التشغيل الآمن

- لا تضع `AZURE_CLIENT_SECRET` أو أي token في Git أو screenshots أو issues.
- لا تستخدم بيانات طلاب أو عاملين حقيقية في الاختبارات.
- راجع `SECURITY.md` قبل الإبلاغ عن مشكلة في SSO.
