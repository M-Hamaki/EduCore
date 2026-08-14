# بنية المشروع وملكية المجلدات

تصف هذه الوثيقة حدود المجلدات المعتمدة للكود الجديد ومسار الانتقال من البنية الحالية. وهي لا تعني أن جميع المجلدات المستهدفة موجودة الآن، ولا تسمح بنقل الملفات القديمة دفعة واحدة. يبقى `AGENTS.md` في جذر المشروع المصدر الملزم للقواعد، بينما تشرح هذه الوثيقة كيفية تطبيقها على بنية المستودع.

## مبادئ تنظيمية

- EduCore يتجه تدريجيًا إلى **Modular Monolith عملي** مع نشر واحد وقاعدة بيانات مشتركة.
- تبقى أسماء الملفات وURLs الحالية مستقرة؛ تتحول صفحات الأدوار ونقاط API/AJAX تدريجيًا إلى adapters رفيعة.
- لا يُنشأ مجلد أو abstraction لمجرد اكتمال شجرة مقترحة؛ يُنشأ عند استخراج use case حقيقي ومختبر.
- حدود الأعمال أهم من التشابه التقني: منطق الطلاب يبقى في وحدة الطلاب؛ بيانات الموظف الوظيفية تملكها `StaffHr`، بينما قواعد حساب/دفع الرواتب تملكها `Finance` ويتواصلان بعقد موثق.
- ملكية جدول قاعدة البيانات الكاملة لم تُثبت بعد. لا يُفترض امتلاك وحدة لجدول مشترك دون دليل أو قرار معماري موثق.

## سطح HTTP الحالي

المشروع موجود حاليًا تحت XAMPP `htdocs` ولا يملك Front Controller أو document root منفصلًا. لذلك اسم ملف PHP هو route في معظم الحالات.

| التصنيف | المواقع | القاعدة |
|---|---|---|
| صفحات عامة | ملفات الجذر العامة مثل `index.php`, `login.php`, `public_portal.php`, `privacy.php`, `terms.php`, `verify_certificate.php` | تبقى نقاط دخول مباشرة، وتنسق الطلب فقط قدر الإمكان |
| الوصول العام للمواد | `materials.php`, `student/materials/`, `material_download.php`؛ و`guest.php` تحويل توافق فقط | يعرض نفس واجهة ومواد الطلاب المفعّلة بلا تسجيل دخول؛ لا يوجد وضع أو دور ضيف |
| صفحات حسب الدور | `admin/`, `teacher/`, `student/`, `specialist/`, `supervisor/`, `external/` | URLs عامة متوافقة؛ الكود الجديد فيها adapter للـHTTP وليس موطنًا لمنطق الأعمال |
| مصادقة واتحاد هوية | `auth/`, `teams/` | نقاط دخول/تكامل SSO وTeams فقط، مع إعادة استخدام سياق المصادقة والسياسات المشتركة |
| JSON/AJAX | `api/`, `ajax/`, `admin/ajax/`, `teacher/ajax/` | endpoint واضح بعقد استجابة، ومصادقة/تفويض/CSRF server-side بحسب العملية |
| استثناء قديم نشط | `includes/ajax_handlers.php` | endpoint عام فعليًا أثناء الانتقال؛ لا تُضاف endpoints جديدة إلى `includes/` |
| ملفات ثابتة | `assets/` | CSS وJavaScript وصور وبيانات frontend عامة مثل JSON الترجمة؛ لا أسرار ولا PHP تنفيذي |

إنشاء `public/` كجذر ويب منفصل هدف نشر لاحق يحتاج خطة توافق واختبار إعداد الخادم. ليس مسموحًا نقل نقاط الدخول إليه آليًا ضمن refactor عادي.

## المجلدات الداخلية وحدود الوصول

| المجلد | الملكية والمسؤولية | حالة الوصول المباشر عبر HTTP |
|---|---|---|
| `classes/` | فئات legacy للتوافق إلى أن تُستخرج مسؤولياتها تدريجيًا | داخلي ومحمي؛ لا endpoint جديد |
| `src/` | كود PSR-4 للوحدات المنقولة تدريجيًا، ومنها `Modules/Students` و`Modules/Staff` و`Modules/PublicPortal`؛ تبقى نقاط HTTP خارجية كـadapters | داخلي ومحمي؛ لا endpoint مباشر |
| `config/` | تحميل البيئة واتصال قاعدة البيانات وإعدادات الخدمات | داخلي ومحمي؛ لا تُعرض الأسرار أو ملفات الإعداد |
| `database/` | schema وmigrations المؤرخة | داخلي ومحمي؛ التشغيل من CLI موثق فقط |
| `tools/` | lint، audits، migration/repair/seed commands | داخلي ومحمي وCLI-only؛ الأوامر الكتابية تحتاج guards ويفضل أن تكون dry-run افتراضيًا |
| `tests/` | اختبارات deterministic وfixtures | داخلي ومحمي؛ لا تعتمد على بيانات production |
| `storage/` | تخزين خاص، exports، cache، logs وbackups | داخلي ومحمي؛ لا PHP تنفيذي ولا روابط مباشرة للملفات الحساسة |
| `scratch/`, `tmp/` | مواد تشخيصية أو مؤقتة بانتظار إثبات الاستخدام والحذف الآمن | داخلي ومحمي؛ ليست مكانًا لكود دائم |
| `archive/` | كود تاريخي غير نشط | تنفيذ PHP/الامتدادات الحساسة محجوب وفق `.htaccess` الحالي، لكن الملفات الساكنة قد تبقى قابلة للطلب؛ لا يُستورد منه اعتماد جديد ولا يُعامل مرجعًا معماريًا |
| `docs/` | وثائق التشغيل والهندسة والقرارات | ليست API عامة ولا بديلًا عن `AGENTS.md`؛ الوثائق التاريخية لا تُعامل حقيقة حالية بلا تحقق |

### الاستثناءات المرحلية

- `includes/` مجلد مختلط legacy، ويحتوي session/CSRF وheaders/footers إضافة إلى dispatcher عام مستخدم. لذلك لا يُحجب المجلد كله قبل فصل المستهلكين. يمنع وضع endpoint أو business service جديد داخله.
- `uploads/` تخزين عام legacy، وبعض الروابط الحالية تعتمد عليه. لا يُحجب دفعة واحدة قبل inventory وauthorization matrix وdual-read migration. أي ملف حساس جديد يذهب إلى `storage/private/` ويُقدَّم عبر download endpoint مصرح.
- بقاء هذين الاستثناءين لا يعني أنهما مكانان مقبولان لتصميم جديد؛ هما دين انتقالي مسجل.

## البنية المستهدفة للكود الجديد

```text
EduCore/
├── admin|teacher|student|specialist|supervisor|external/  # URL adapters
├── api|ajax|auth/                                         # HTTP adapters
├── src/
│   ├── Shared/
│   │   ├── Auth/
│   │   ├── Authorization/
│   │   ├── Database/
│   │   ├── Http/
│   │   ├── Validation/
│   │   ├── Logging/
│   │   └── Files/
│   └── Modules/<Module>/
│       ├── Application/
│       ├── Domain/
│       ├── Infrastructure/
│       └── Presentation/
├── views/<role|shared>/
├── assets/
├── database/migrations/
├── storage/private/
├── tests/
└── tools/
```

فُعّل PSR-4 لمساحة `EduCore\\` وبدأ التنفيذ الفعلي بوحدات `src/Modules/Students` و`src/Modules/Staff` و`src/Modules/BehaviorEvaluation`. تبقى بقية الشجرة اتجاهًا مستهدفًا، ولا تُنشأ وحدة جديدة قبل استخراج workflow حقيقي وتثبيته باختبارات وعقد رجوع.

## ملكية الطبقات

| المكان | ما يملكه | ما لا يملكه |
|---|---|---|
| نقاط الدخول الحالية | قراءة الطلب، bootstrap، auth/authorization، CSRF، تحويل المدخل إلى DTO، استدعاء use case، وبناء الاستجابة | SQL/DDL، خوارزميات أعمال كبيرة، أو HTML/JavaScript ضخم جديد |
| `src/Modules/*/Application` | use cases، تنسيق workflow، حدود transaction، وتنظيم audit | superglobals، HTML، أو تفاصيل PDO المباشرة |
| `src/Modules/*/Domain` | قيم المجال، validators الخاصة بالوحدة، policies وانتقالات الحالة | HTTP/session، PDO، filesystem، أو مكتبات العرض |
| `src/Modules/*/Infrastructure` | تنفيذ repositories وPDO وواجهات الملفات وAPIs الخارجية | rendering أو تغيير قواعد المجال |
| `src/Modules/*/Presentation` | ViewModels وتحويل نتائج use case إلى بيانات عرض | استعلامات وكتابات قاعدة البيانات |
| `src/Shared/*` | primitives وعقود وبنية تحتية مشتركة فعلًا بين وحدات متعددة | قواعد طلاب أو درجات أو رواتب خاصة بوحدة واحدة |
| `views/` | rendering وoutput escaping فقط | query، write، authorization decision، أو validation أعمال |
| `database/migrations/` | كل تغيير schema جديد مع preconditions وخطة rollback/restore | rendering أو تنفيذ أثناء request عادي |
| `tests/` | Unit/Integration/HTTP/Architecture tests وfixtures معزولة | افتراض أن قاعدة `educore` قاعدة اختبار آمنة |

## وحدات الأعمال المستهدفة

تُستخدم الأسماء التالية لتجميع use cases عند استخراجها، ولا تفرض نقلًا جماعيًا للكود القديم:

- `IdentityAccess`: الحسابات، تسجيل الدخول وسياق المستخدم.
- `AcademicStructure`: المراحل والصفوف والفصول والأعوام الدراسية.
- `Students`: ملفات الطلاب والتسجيل والتحويل والعلاقات الطلابية.
- `StaffHr`: ملفات العاملين ودورة العمل والبيانات الوظيفية.
- `AssessmentReporting`: التقييمات والدرجات والتقارير الأكاديمية.
- `Attendance`: حضور الطلاب والعاملين.
- `BehaviorEvaluation`: السلوك والتقييمات المتخصصة.
- `Finance`: الرسوم والمدفوعات والرواتب والعمليات المالية.
- `Transport`: الحافلات والمسارات والاشتراكات.
- `ClinicLibrary`: العيادة والمكتبة عند وجود use cases مؤكدة.
- `LearningContent`: الدروس والتحضير والمحتوى التعليمي والميزات المساندة بالذكاء الاصطناعي.
- `Notifications`: قنوات الإشعارات وإرسالها وتتبعها.
- `OperationsAudit`: النسخ الاحتياطي والتراجع وسجلات النشاط والتشغيل.

التواصل بين وحدتين يمر دائمًا عبر Application contract أو query contract موثق. إذا لم يوجد عقد، يُعرّف أصغر عقد تملكه الوحدة أولًا؛ لا تضم وحدة صفحة PHP من وحدة أخرى ولا تصل في كود جديد مباشرة إلى internals أو جداول وحدة أخرى. أي استثناء مؤقت يخضع لـADR بمالك ومدة وخطة إزالة وتراجع.

## أين يوضع التغيير الجديد؟

1. إذا كان تعديلًا متوافقًا لنقطة URL موجودة، يبقى الملف في مكانه ويقتصر على التنسيق، ثم يستدعي use case مستخرجًا.
2. عند اعتماد دفعة استخراج فعلية تضيف PSR-4 للوحدة وتثبت التحميل والاختبارات، يوضع use case الجديد/المستخرج في `src/Modules/<Module>/Application`، وقواعده في `Domain` وتنفيذ التخزين في `Infrastructure`. قبل ذلك يبقى في service مملوك داخل `classes/` أو الموضع الحالي المثبت؛ لا يُنشأ `src/` غير قابل للتحميل.
3. primitive مشترك لا يُنقل إلى `src/Shared` إلا بعد إثبات استخدامه عبر أكثر من وحدة وعدم احتوائه قواعد مجال خاصة.
4. HTML جديد قابل للفصل يوضع في `views/` مع escaping، وCSS/JavaScript المشترك في `assets/`.
5. أي تغيير schema يوضع في migration مؤرخة ويُشغّل من CLI؛ request code يفحص الجاهزية ولا ينشئ الجداول أو الأعمدة.
6. ملف مستخدم حساس يوضع في `storage/private/` باسم مولد ويُخدم عبر authorization-aware endpoint.
7. أداة تشغيل أو إصلاح توضع في `tools/` مع CLI guard، ووضع آمن افتراضيًا، وتوثيق واضح لتأثيرها.

## محظورات مكانية

- لا DDL أو auto-create/alter داخل صفحة أو API أو service يعمل أثناء الطلب.
- لا SQL داخل View، ولا HTML أو superglobals داخل Domain/Application.
- لا business rule خاصة بوحدة داخل `Shared` لمجرد الرغبة في إعادة الاستخدام.
- لا endpoints جديدة داخل `classes/`, `config/`, `database/`, `tools/`, `tests/`, `storage/`, `scratch/`, `tmp/` أو `includes/`.
- لا PHP تنفيذي أو أسرار داخل `assets/`, `uploads/` أو مسار ملفات قابل للوصول العام.
- لا نسخة ثانية من auth، CSRF، validation، logging أو DB bootstrap قبل البحث عن العقد الموجود وتوثيق سبب الاستثناء.
- لا نقل أو حذف ملف legacy اعتمادًا على اسمه فقط؛ يجب إثبات callers/includes/links وسلوك rollback أولًا.
- لا إضافة root application folder أو نمط معماري موازٍ دون ADR مراجع.

## مسار الانتقال

- استخرج action/use case واحدًا في كل دفعة، مع إبقاء facade أو adapter القديم.
- أضف characterization tests قبل تقسيم صفحة legacy كبيرة.
- حافظ على form names، element IDs، session keys، JSON fields وURLs ما لم توجد migration وخطة توافق صريحة.
- شغّل lint والاختبارات ذات الصلة و`composer architecture-audit`؛ baseline آلية منع ارتداد على مستوى مسار الملف وليست تصريحًا دائمًا بالدين الحالي، ومفاقمة الدين داخل ملف مدرج تحتاج مراجعة diff يدوية.
- حدّث `docs/architecture-decisions.md` عند تغيير حد وحدة أو اتجاه اعتماد أو موطن مسؤولية.
