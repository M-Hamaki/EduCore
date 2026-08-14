# قواعد المعمارية الملزمة

هذه القواعد تصف الاتجاه المعتمد لهذا التدقيق. تصبح دائمة عند دمجها في `AGENTS.md` بعد مراجعة عدم التعارض.

## 1. التوافق أولًا

- لا تغيّر URL أو form `name/id/action` أو session key أو JSON field أو schema بلا migration وخطة توافق.
- ملفات role folders تبقى entrypoints خلال الانتقال.
- لا business-rule change تحت اسم refactor.

## 2. مكان المسؤوليات

- Entrypoint/controller: قراءة الطلب، auth/authorization/CSRF، DTO، service، response.
- Application service: workflow وtransaction وaudit orchestration.
- Domain: validation/policy/state transitions بلا HTTP/PDO.
- Repository/query service: SQL فقط ومعه mapping واضح.
- View: rendering/escaping فقط، بلا query أو write.
- Shared: primitives/infrastructure عامة، لا قواعد طلاب أو درجات أو رواتب.

## 3. اتجاه الاعتماد

- Presentation → Application → Domain/contracts.
- Infrastructure → contracts وPDO/APIs.
- Domain لا يعتمد على Presentation/Infrastructure.
- وحدة لا تضم صفحة من وحدة أخرى ولا تصل إلى internals الخاصة بها.
- التواصل المتقاطع عبر service/query contract موثق.

## 4. المصادقة والتفويض

- server-side دائمًا وقبل معالجة POST/GET sensitive.
- لا مسار login موازٍ بلا قرار معماري واختبارات.
- لا تعتمد على إخفاء زر.
- session keys الحالية محمية كتوافق عام.
- كل use case حساس يحدد policy/scope لا role string فقط عند وجود آلية صلاحيات أدق.

## 5. CSRF وHTTP

- كل POST/PUT/PATCH/DELETE أو GET يغير state يتطلب CSRF server-side.
- GET لا يغير state.
- مقارنة التوكن بـ`hash_equals()`.
- JSON endpoints تستخدم status codes وعقد خطأ موحد.
- redirects تُبنى من config أو path موثوق لا user-controlled Host/Input.

## 6. قاعدة البيانات

- PDO prepared statements.
- لا DDL خارج migrations، باستثناء migration runner/installer موثق وغير متاح للعامة.
- لا SQL في View.
- transaction لكل multi-write invariant.
- لا production data في الاختبار أو الإصلاح دون تصريح صريح وbackup.
- كل migration لها preconditions، idempotence حيث يلزم، rollback أو خطة restore.

## 7. التحقق

- لا تكرر validator قبل البحث عن الموجود.
- input validation منفصل عن output escaping.
- module-specific rules تبقى داخل الوحدة.
- upload: extension + MIME + size + randomized name + private storage + authorization.

## 8. الأخطاء والتسجيل

- لا تعرض exception/SQL/path/stack للمستخدم.
- سجل التفاصيل server-side بلا secrets/passwords/tokens.
- business/security audit لا يستبدل diagnostic log.
- لا تبتلع Exception إلا مع fallback موثق ومراقب.

## 9. الاختبارات

- unit بلا DB التطبيق.
- integration لا يعمل إلا على DB اختبار مع guard.
- characterization قبل تقسيم legacy page.
- auth/authorization/CSRF لكل role/scope متأثر.
- lint + relevant tests + diff review + rollback proof.

## 10. Git

- احمِ dirty changes.
- لا reset/clean/force.
- concern واحد في commit.
- لا stage لملف متسخ سابقًا دون فصل hunks وإثبات الملكية.
- لا push دون طلب.

## 11. ممنوعات الانحراف

- framework/router/bootstrap/auth/validation/logging بديل بالتوازي.
- helper/service مكرر قبل search.
- root application folder جديد بلا ADR.
- DDL runtime.
- direct cross-module DB access في كود جديد إذا وُجد contract معتمد.
- refactor + feature/UI cleanup في نفس المرحلة.
- حذف/نقل بالاسم فقط.

## 12. تعريف الإنجاز

- السلوك المتوافق مثبت.
- اختبارات وصياغة ناجحة.
- authorization/CSRF verified.
- لا secrets أو unrelated diff.
- docs/ADR محدثة عند تغيير الحدود.
- rollback عملي.
- لا pattern موازٍ جديد.
- `composer architecture-audit` ناجح لأي تغيير يمس PHP أو حدود الويب أو baseline.

## 13. بوابة التدقيق المعماري

- شغّل `composer architecture-audit` قبل إغلاق أي تغيير يمس PHP أو حدود الويب أو baseline.
- findings في وضع report مخزون دين ومؤشرات مراجعة، وليست إثباتًا تلقائيًا لثغرة؛ خصوصًا CSRF candidates تحتاج مراجعة يدوية.
- baseline آلية ratchet وليست allow-list دائمة؛ لا تُضاف إليها مخالفة جديدة لمجرد تمرير strict.
- أي توسيع للـbaseline يحتاج تغييرًا منفصلًا مع دليل وسبب وقرار موثق.
- عند إصلاح مخالفة قائمة، تُحذف من baseline في نفس التغيير حتى لا تصبح قابلة للعودة بصمت.

## 14. شروط التوقف

- business rule أو permission غير واضح.
- route/include/caller غير محسوم.
- production data قد تتأثر.
- dirty overlap لا يمكن فصله.
- test لا يغطي الخطر.
- rollback غير مضمون.
- التغيير يتطلب تجاوز هذه القواعد.
