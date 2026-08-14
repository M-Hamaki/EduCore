# سجل تنفيذ بوابة الوصول الموحدة

**التاريخ:** 2026-08-11
**الحالة:** التنفيذ المحلي مكتمل وظيفياً؛ تحقق Entra/Teams الحقيقي والنشر على staging/production خارج مساحة العمل.

## حماية شجرة العمل والنطاق

- بدأت المهمة فوق شجرة عمل كبيرة تحتوي تغييرات كثيرة مملوكة للمستخدم، منها ميزات الحسابات وStaff-HR وFinance وتحديثات vendor. لم تُنفذ reset/clean/stage/commit ولم تُحذف أو تُستبدل هذه الأعمال.
- اقتصر التنفيذ على مداخل البوابة والدخول وMicrosoft/Teams، وحدة `src/Modules/PublicPortal`، أصول الواجهة العامة، صفحة إعداد الأدمن، اختبارات الميزة، ووثائقها.
- لم تُغيّر `.specify/feature.json` لأن الملف كان متغيراً مسبقاً وتبديله ليس شرط تشغيل للميزة.
- لم تُستخدم قاعدة `educore` لاختبار كتابة، ولم تُشغل migration؛ أثبت الفحص أن الميزة لا تحتاج schema جديدة.

## العقود والقرارات المثبتة

- `index.php` بطاقة دخول واحدة بلا مراحل؛ `login.php` يحافظ على أسماء حقول POST والجلسات ووجهات الأدوار ويهمل `stage` للمصادقة.
- `public_portal.php` يبقى مسار تراجع قديم خلف العلم، ويتحول إلى البوابة الموحدة عندما يكون العلم مفعلاً.
- ألغي وضع الضيف لاحقاً بطلب المستخدم: لا زر ولا صفحة خدمات ولا إعداد `public_portal_services` فعال. المسارات القديمة تحول إلى مركز المواد دون كتابة إعداد.
- `materials.enabled` و`materials.downloadable` هما قرارا النشر الموجودان؛ لا projection ولا migration مكررة.
- اختصار المواد هو خيار الوصول العام الوحيد، و`materials.php` يحافظ على المقدمة ثم يحول إلى `student/materials/`. القائمة والتنزيل يعيدان استخدام `enabled` و`downloadable` مع نشاط المرحلة والصف دون مفتاح خدمة منفصل.
- Teams الصامت يقبل حساباً مرتبطاً فقط، ولا auto-link، ويعيد فحص Microsoft ID والبريد واسم المستخدم والحالة والدور. تغير البريد أو غياب الربط يعيد البوابة.
- السبب المخصص لتعطيل الحساب يظهر وحده، والسبب الفارغ ينتج الرسالة العامة وحدها، في اليدوي وMicrosoft وTeams والجلسة.
- المقدمة: أول زيارة ثم كل 15 يوماً، تخطي Teams، وعودة allow-listed إلى البوابة أو المواد.

## التحقق المنفذ

- تعديل إلغاء الضيف: نجحت صياغة جميع ملفات PHP المعدلة، واختبارات `public_portal_view_test`, `public_portal_domain_test`, `public_portal_foundation_contract_test`, `public_materials_policy_test`, `public_portal_security_contract_test`, `unified_public_portal_ui_contract_test`, `public_portal_accessibility_contract_test`, و`public_portal_audit_coverage_contract_test`.
- نجح QA المتصفح بعد التعديل: لا يظهر زر ضيف، يظهر النص المعتمد للزر الوحيد، وينتهي التحويل عند `student/materials/`، وتعمل رحلة الفصل الدراسي ثم المرحلة ثم الصف وتعرض مواد قاعدة البيانات بلا أخطاء console.
- نجح `architecture-audit --strict` بلا regressions، و`audit-write-coverage` بنتيجة `AUDIT_REVIEW_REQUIRED=0`، و`admin-ui-audit` بنتيجة `UI_AUDIT_ISSUES=0`، كما نجح `git diff --check` لنطاق التعديل.
- PHP syntax: جميع ملفات PHP الجديدة والمعدلة في نطاق الميزة اجتازت `php -l` أثناء التنفيذ.
- اختبارات ناجحة: `public_portal_domain_test`, `public_portal_view_test`, `public_portal_foundation_contract_test`, `public_portal_security_contract_test`, `unified_public_portal_ui_contract_test`, `teams_auto_sso_contract_test`, `microsoft_sso_identity_match_test`, `microsoft_sso_environment_test`, `microsoft_sso_session_failure_test`, `student_login_access_policy_test`, و`student_password_login_denial_test`.
- QA متصفح محلي السابق أثبت البطاقة الموحدة وRTL والوضع الداكن. بعد تعديل المتطلبات ألغي زر الضيف والكتالوج المنفصل وأصبح رابط المواد يحول إلى واجهة `student/materials/` نفسها.

## نتائج الإغلاق

- `tools/php_lint.php`: نجح؛ 1646 ملف PHP و0 أخطاء.
- اختبارات الميزة: نجحت جميعها، بما فيها اختبار SQLite المعزول لسياسة قائمة/تنزيل المواد العامة.
- `tools/audit_write_coverage.php`: نجح؛ `AUDIT_REVIEW_REQUIRED=0` بعد تسجيل تفويض صفحة الإعداد إلى مالك الكتابة المدقق.
- `tools/audit_admin_ui.php`: نجح؛ `UI_AUDIT_ISSUES=0`.
- اختبارات التوثيق وDataTables ومشاركة الدروس وFileUploadGuard/Storage/APP_URL/ProfileAttachment: نجحت.
- لم يكن executable الخاص بـComposer متاحاً في PATH، لذلك شغلت أوامر سكربت `quality` نفسها مباشرة بPHP/Node.
- التدقيق المعماري العام انتهى بمخالفة واحدة خارج نطاق الميزة: `admin/staff_accounts.php` تجاوز حد 2000 سطر في تغييرات موجودة مسبقاً. لم يضف PublicPortal runtime DDL أو CSRF candidate أو internal-directory exposure.
- تدقيق الرفع العام انتهى بمخالفة واحدة خارج نطاق الميزة: `admin/school_profile.php` يحتوي `@unlink($logoDir . $logoName)` غير مصنف في manifest الحالي. بقية اختبارات الرفع نجحت، ولم تضف الميزة uploader أو mover جديداً.

## نشر وتشغيل آمن

1. انشر الكود و`vendor` المتوافقين معاً، وضع القيمتين `UNIFIED_ACCESS_PORTAL_ENABLED=false` و`TEAMS_AUTO_SSO_ENABLED=false` أولاً.
2. سجل Redirect URIs حرفياً في Microsoft Entra؛ خطأ `AADSTS50011` لا يمكن إصلاحه من PHP وحده.
3. فعّل البوابة واختبر اليدوي وMicrosoft وزر المواد المباشر والمقدمة، ثم فعّل Teams لحسابات تجريبية: مطابق، غير مرتبط، بريد متغير، ومعطل.
4. راجع المواد من `admin/materials_center.php`: `enabled=1` يجعلها ظاهرة للطلاب والزوار، و`downloadable=1` يسمح بتنزيلها.

## التراجع

- أوقف `TEAMS_AUTO_SSO_ENABLED` لإرجاع مستخدم Teams إلى طرق الدخول التفاعلية.
- عطّل المادة أو إتاحة تنزيلها من `admin/materials_center.php` لإيقاف ظهورها أو تنزيلها للطلاب والزوار.
- اضبط `UNIFIED_ACCESS_PORTAL_ENABLED=false` للعودة المؤقتة إلى `public_portal.php` القديم.
- لا توجد migration أو بيانات جديدة تحتاج إسقاطاً؛ أي صف سابق باسم `public_portal_services` خامل ويمكن إبقاؤه دون أثر.

## خطوات خارج مساحة العمل

- تسجيل Redirect URIs المحلي والإنتاجي في Entra والتحقق من manifest والمستأجر الفعلي.
- smoke test داخل تطبيق Teams الحقيقي وعلى staging/production.
- مراقبة سجلات SSO بعد التفعيل من دون تسجيل tokens أو كلمات مرور.
