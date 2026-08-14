# فهرس EduCore

هذا الفهرس يوجّه المستخدمين والمساهمين إلى التوثيق العام. لا يحتوي المستودع العام على نطاق نشر أو حساب أو وسيلة اتصال خاصة بمؤسسة بعينها.

## البدء السريع

- [README](../README.md) — التعريف، المميزات، التثبيت، والاختبارات.
- [CONTRIBUTING](../CONTRIBUTING.md) — إعداد بيئة التطوير وقواعد Pull Requests.
- [SECURITY](../SECURITY.md) — الإبلاغ الخاص عن الثغرات وحماية بيانات المدارس.
- [ROADMAP](../ROADMAP.md) — اتجاهات الذكاء الاصطناعي والهندسة والمجتمع.

## الإعداد والتكاملات

- [Microsoft SSO وTeams](MICROSOFT_SSO_SETUP.md)
- `.env.example` — قالب الإعدادات مع قيم placeholder.
- `teams/manifest.example.json` — قالب Teams عام؛ أنشئ `teams/manifest.json` محليًا ولا تلتزم به.
- `docs/database.md` — نمط الوصول إلى قاعدة البيانات ونقاط المخطط.
- `docs/file-upload-standard.md` — عقد الرفع والتخزين والرجوع والتحقق.

## المعمارية والجودة

- `docs/architecture.md` — الشكل العام للنظام والحدود.
- `docs/project-structure.md` — ملكية المجلدات ونقاط الدخول.
- `docs/architecture-decisions.md` — القرارات المعتمدة.
- `docs/project-memory.md` — ذاكرة عامة مختصرة للمشروع.
- `docs/architecture-audit/` — أدلة التدقيق وخطة المعالجة العامة.

## البيانات والخصوصية

- استخدم قاعدة اختبار معزولة وبيانات اصطناعية بالكامل.
- خصص `terms.php` و`privacy.php` لكل مؤسسة وولايتها القضائية قبل الإنتاج.
- لا ترفع `.env` أو النسخ الاحتياطية أو الملفات المرفوعة أو سجلات الإنتاج.
- لا ترسل بيانات الطلاب أو العاملين في issues أو Pull Requests أو تقارير الأعطال.

## الدعم

بيانات الدعم تخص كل deployment وتُضبط من `SUPPORT_EMAIL` و`SUPPORT_PHONE` في `.env`. لا تُضاف بيانات اتصال خاصة بالمشغل إلى المستودع العام.
