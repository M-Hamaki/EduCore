# تحليل الفجوات

## جدول الفجوات

| المجال | الحالي | المستهدف | الفجوة | التغيير | الأولوية | الثقة | الخطر | الحجم | الاعتماديات | التحقق |
|---|---|---|---|---|---|---|---|---|---|---|
| Document root | المشروع كله تحت `htdocs` | public files فقط أو deny صارم | source/tools/data مرشحة للوصول | حماية فورية ثم public docroot لاحق | P0 | Medium-High | صغير للحماية/كبير للنقل | S/L | Apache config | HTTP 403 + app smoke |
| Password auth | hash-first + legacy upgrade flag | إغلاق fallback وإلغاء reversible reveal | قياس cutover وقرار الاسترجاع | monitor ثم تعطيل flag؛ قرار reveal مستقل | P0 | High | Medium | M | production metrics/policy | staging login matrix |
| CSRF | مختلط | server-side لكل write | صفحات POST غير متسقة | audit + page batches | P0 | High جزئيًا | Medium | M | form contracts | 419 + workflows |
| Authorization | role/page/domain نماذج منفصلة | facade وسياسات موحدة | صعوبة إثبات الصلاحية | adapters ومصفوفة policies | P1 | High | High | L | staff roles, assessment | role/scope matrix |
| Controllers | Page controllers ضخمة | رفيعة | business/SQL/render مختلط | extraction use case by use case | P1 | High | High | L | characterization tests | behavior parity |
| SQL | pages + classes | repositories/query services | انتشار وتكرار | نقل بعد تثبيت DTO/service | P1 | High | Medium | L | module boundaries | integration queries |
| Schema | migrations + runtime DDL | migrations فقط | drift/locks | migrations guarded ثم إزالة ensures | P0 | High | High | L | DB versions | fresh/partial/repeat |
| Validation | دوال محلية مكررة | shared/module validators | إصلاحات غير متسقة | unit-tested validators + wrappers | P1 | High | Medium | M | field contracts | boundary cases |
| Error response | exception text أو swallow | safe mapper + logging | leaks/no diagnosis | typed exceptions ورسائل عامة | P0 | High | Low-Medium | M | endpoint contracts | forced failures |
| Transactions | متفاوتة | use-case decision واضح | partial writes | transaction manager/services | P1 | High | Medium | M | audit semantics | rollback tests |
| Logging | 3+ آليات | policy/contract | duplicate/gaps/PII risk | categorize + adapters | P2 | Medium | High | M | business needs | audit assertions |
| Attachments | direct public links | private authorized download | PII bypass | dual-read migration | P0 | High | High | L | all consumers/files | role download matrix |
| Frontend | assets + inline آلاف الأسطر | module assets | صعوبة اختبار/CSP | extract stable functions/DOM contracts | P2 | High | Medium-High | L | browser QA | visual/function tests |
| Tests | scripts مختلطة | classified runner + guarded DB | تشغيل غير موحد وخطر DB | Unit/Integration/Http/Architecture | P0 | High | Low-Medium | M | staging DB | runner refusal/success |
| Docs | كثيرة ومتعارضة | canonical synchronized set | drift | AGENTS + architecture docs + checklist | P0 | High | Low | M | final implementation | conflict audit |

## مقارنة الإغلاق الآمن بالهدف

| المجال | حالة الإغلاق | ما أُنجز | ما يبقى قبل بلوغ الهدف |
|---|---|---|---|
| التعليمات والوثائق | مكتمل للمرحلة الآمنة | `AGENTS.md` canonical، architecture/structure/ADR/checklist، دستور وقوالب، وفحص drift | تحديثها مع كل boundary/schema/public-contract لاحق |
| حدود الويب | مكتمل ضمن النطاق المعتمد | deny للمجلدات الداخلية، CLI guards، تخزين خاص للمرفقات، واختبارات حدود | إثبات إعداد production/vhost فقط؛ فصل phpMyAdmin مستثنى بقرار المستخدم |
| فحص الانحراف | مكتمل كحاجز path-level | strict audit وbaseline واختبار regression | لا يقيس نمو الدين داخل ملف baselined؛ يلزم diff review وتطوير detector عند دليل blind spot |
| CSRF | مكتمل لحاجز المستودع الحالي | كل المسارات المرصودة اجتازت الحارس، وstrict audit يعرض صفر مرشحين غير مراجعين | إبقاء الفاحص ضمن بوابة كل تغيير جديد |
| Error response | مكتمل مرحليًا | `SafeErrorPolicy` ورسائل عامة وتسجيل server-side وعقود فشل | توسيع الأنواع المخصصة مع الوحدات الجديدة فقط |
| Password auth | مكتمل مرحليًا | Authenticator وhash authority وترقية legacy واختبارات unit/integration وhash على الكتابات الجديدة | قياس الحسابات بلا hash، تعطيل fallback، وحسم reveal القابل للعكس |
| Transactions/Finance | مكتمل للنطاق المخطط | معاملات ذرية للمالية والأدوار والحضور وUndo مع اختبارات rollback/contract | تطبيق النمط نفسه عند تعديل workflow جديد |
| Runtime DDL | مكتمل | نُقلت التغييرات إلى migrations؛ strict audit يعرض صفر ملف runtime DDL | تشغيل migrations على staging/production وفق إجراءات النشر |
| Controllers/SQL/Validation | مكتمل للملفات الخمسة ذات الأولوية | قُسمت `students.php`, `staff.php`, `user.php`, `ExamGenerator.php`, و`ajax_handlers.php` مع adapters وعقود | استخراج بقية الوحدات تدريجيًا عند الحاجة، لا إعادة كتابة |
| Attachments | مكتمل | تخزين خاص، تنزيل مصرح، migration checksum وrollback dry-run، ومنع HTTP للمصادر القديمة | مراقبة التشغيل بعد النشر |

هذه النتيجة لا تساوي اكتمال المعمارية المستهدفة؛ إنها إغلاق لكل تغيير ثبت أنه عالي الثقة وعكوس ولا يحتاج قرار أعمال أو كتابة بيانات إنتاج.

## Quick Wins

1. إنشاء وثائق التدقيق وسجل القرارات والمخاطر.
2. حماية direct HTTP للمجلدات غير العامة دون نقلها.
3. إضافة CLI guards للأدوات التي تغير schema/data.
4. منع تفاصيل الاستثناء في JSON للمسارات الواضحة.
5. إضافة architecture audit read-only إلى Composer.
6. تصنيف الاختبارات ووضع guard لقاعدة integration.

## تحسينات هيكلية آمنة

- `SchemaInspector::tableExists()` مع cache per request، ثم ترحيل صفحة واحدة في كل commit.
- validation primitives مع wrappers تحمل أسماء الدوال القديمة.
- `UpdateStaffFinanceService` لأن workflow محدود وواضح نسبيًا.
- `ClassService` لإزالة write-on-GET واحتواء CRUD بعد إضافة CSRF واختبارات.
- JSON responder/helper جديد يستخدمه endpoint جديد أولًا، لا rewrite شامل.

## تغييرات عالية المخاطر

- إصلاح password authentication على قاعدة فعلية دون fixtures/staging.
- نقل private attachments.
- إعادة تصميم RBAC.
- تقسيم `students.php`, `staff.php`, `lesson_prep.php`.
- نقل document root إلى `public/`.
- إزالة runtime DDL من كل الوحدات دفعة واحدة.

## تغييرات لا تنفذ الآن

- حذف ملفات قديمة أو نقلها لمجرد الاسم.
- Framework migration.
- ORM migration.
- Microservices.
- schema normalization واسع للمالية أو users دون business/data migration plan.
- توحيد soft-delete عالمي.

## بوابة الانتقال من Gap إلى تنفيذ

أي Gap يدخل التنفيذ فقط إذا توفرت:

1. قائمة ملفات دقيقة.
2. behavior contract حالي.
3. اختبارات تلقائية أو manual checklist قابلة للتنفيذ.
4. rollback واضح.
5. عدم تداخل مع dirty changes.
6. عدم الحاجة إلى قرار أعمال غير موثق.
