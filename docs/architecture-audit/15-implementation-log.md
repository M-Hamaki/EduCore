# سجل التنفيذ

## 2026-08-11 — منفذ إجراءات قبول Staff-HR عبر الواجهة (T182)

- أضيف منفذ same-origin يغطي أسماء الإجراءات الـ126 المعرفة في Q01–Q33، ويستخدم جلسات الأدوار وحقول CSRF المولدة من النماذج الفعلية دون تسجيل الرموز أو كلمات المرور في الأدلة.
- رُبطت الرحلات المتاحة ببوابة `staff_hr_portal.php` وصندوق الموافقات وتقارير الحضور ودفتر العامل المالي وسجل HR، مع إرسال نماذج الإذن وارتق والاعتماد من الحقول التي عرضها الخادم.
- كل إجراء لا يملك route أو حقلًا أو نتيجة متاحة يفشل مغلقًا بحالة `blocked` وسبب ثابت؛ عمليات الرفع غير الموصولة لا تُحاكى ولا تتجاوز `FileUploadGuard`.
- تحقق اختبار Node المعزول من تغطية كل أسماء الإجراءات، واستخراج النموذج وCSRF ونوع الإذن من HTML، ومنع تنفيذ الإجراء غير المعلن. نجح `git diff --check` للملفين الجديدين.
- بقي التشغيل الفعلي لكل الرحلات مشروطًا بقاعدة قبول `_test` كاملة وجلسات بيانات T181؛ لا كتابة تمت على `educore`.

## 2026-07-18 — قرارات ترحيل الطلاب الصريحة (v2)

- أضيفت migration إضافية `20260719_academic_promotion_decisions.php` لمدرسة واحدة: قواعد انتقال الصفوف حسب زوج العامين، قرارات الطلاب الدائمة، أعلام الصف/الحساب التجريبي، سعة الفصل، وربط قيد الهدف بالمصدر والقرار. بعد اجتياز الاختبارات طُبقت وحدها على `educore` وسُجلت؛ بقيت `20260716_student_archiving.php` غير مطبقة.
- أزيل اعتماد الترحيل على ترتيب الصفوف والفصول. الناجح ينتقل وفق ID صريح، الراسب يبقى في صفه بلا فصل مع عداد إعادة، والمتخرج/المنقول/المنسحب/المعلق/التجريبي لا يحصل على قيد هدف.
- تنسخ الفصول كمسودات غير نشطة مع السعة، ويبدأ كل قيد ناجح/راسب بلا فصل حتى التسكين اللاحق. أصبحت موانع المعاينة مجمعة، وأضيفت عناصر إدارة قابلة للعكس للصفوف والحسابات التجريبية والاستثناءات.
- نجحت عقود القواعد والقرارات والبيانات التجريبية، واختبار شامل لكل النتائج مع التحقق والرجوع، ودورة backup/restore/execute/verify/rollback/activate على قاعدة `_test` معزولة.
- قياس إعداد 1000 قرار على قاعدة `_test` استغرق `0.3885s` وزيادة ذاكرة `4,194,304` بايت؛ جميع القرارات حُفظت وحوسبت دون تخطٍ.
- كشف فحص Chrome حد `max_input_vars` لأن الصفحة كانت تسمي قائمة القرار لكل طالب. عُدل النموذج ليعرض كل الطلاب لكنه يسمي ويرسل الاستثناءات المختارة فقط، ولا يعيد ملء الاختيار من قرار `system/rule`؛ تحقق المتصفح من 1,251 قائمة ظاهرة و0 حقول مسماة افتراضيًا وغياب التحذير.
- أُنشئت واستعيدت نسخة قبل migration. بعد التركيب رفض الحارس نسخة أصبحت قديمة عند تغير القواعد/القرارات، ثم أُنشئت النسخة النهائية بعد ثباتها واستعيدت بنجاح: `verified`، 210 جداول، 177 ملفًا، 93,458,030 بايت، ونجح `assertUsableVerifiedReceipt`. لم ينفذ rollover إنتاجي.
- نجح strict architecture audit بلا regressions. بقيت بوابة write coverage تعرض ملفين سابقين خارج نطاق التهيئة (`admin/includes/official_document_page.php`, `admin/statements.php`) للمراجعة؛ لا يضاف لهما تصنيف لتغطية هذا التغيير.

## 2026-07-18 — تهيئة عام آمنة ونسخة تعافٍ مجرّبة

- أضيفت migration إيصالات التعافي وتشغيلات التهيئة وmanifest الصفوف. بعد نجاح حزمة التعافي المجربة، طُبقت `20260718_safe_year_rollover.php` وحدها على `educore` في 2026-07-18 وسُجلت في `schema_migrations`؛ بقيت migration أرشفة الطلاب المعلقة خارج النطاق ولم تُطبق.
- أضيفت حزمة تشمل SQL وملفات البيانات وبيان SHA-256 وبصمة محتوى لكل جدول، مع استعادة إلزامية إلى قاعدة `_test` جديدة وتنظيف القاعدة التي أنشأها التشغيل فقط.
- استبدلت خيارات النسخ بسياسة ثابتة: تقويم وفصول وقيود وإسنادات وبنية تقييم كمسودات، مع منع نقل الحضور والدرجات والتقييمات والتقارير والمدفوعات والنقل.
- أضيف preflight يفشل عند أي طالب متخطى أو هدف غير فارغ، وتنفيذ ذري، وmanifest دائم، وتحقق مستقل للأعداد والمراجع وحالة المسودات، ورجوع موجّه، وتفعيل يقفل المصدر.
- أضيف `AcademicYearWriteGuard` إلى الملاك المؤكدين للحضور، والتقييم السلوكي، ورصد/مراجعة/نشر الدرجات، ومدفوعات الرسوم.
- نجحت استعادة حقيقية للحزمة واختبار التهيئة ودورة الرجوع/إعادة التشغيل/التفعيل على ثلاث قواعد منفصلة منتهية بـ`_test`. حُذفت قواعد الاختبار بعد النجاح، ولم تُكتب بيانات أعمال أو schema إلى `educore`.
- أُنشئت حزمة نهائية من نسخة كاملة للبيانات الحالية مع `uploads` و`storage/private`، ثم استُعيدت في قاعدة اختبار ثانية وقورنت البصمات: 208 جداول، 177 ملفًا، SHA-256 `97e1899c2a91c3cd4686a51bfa55dc958b190d6c13e569e8bb4b7d0abdd29d2f`، وحجم 93,324,608 بايت. بقيت الحزمة في `storage/backups/recovery/` وحُذفت قاعدتا الإثبات المؤقتتان.
- نجح lint لجميع 695 ملف PHP، ونجحت بوابات upload policy وarchitecture strict وwrite coverage (`AUDIT_REVIEW_REQUIRED=0`) وفحص الفروق المحصور. بوابة `composer quality` وصلت إلى admin UI فقط ثم توقفت بسبب `legacy_modals=1` في `admin/student_data_completeness.php`، وهي صفحة سابقة خارج نطاق التهيئة ولم تُعدّل لإخفاء التنبيه.

## 2026-07-16 — بدء تطوير محرك التدقيق والتراجع الشامل

- أضيفت خطة النظام الكاملة في `16-audit-undo-system-roadmap.md` وخط أساس التغطية في `17-audit-write-coverage-baseline.md`.
- أضيفت وحدة عمليات التدقيق: `AuditContext`, `AuditPolicyRegistry`, و`EntityChangeTracker`.
- أصبح `ActivityLog` يدعم correlation وbatch ونتيجة العملية والمسار وuser-agent وربط حدث التراجع، مع حجب الحقول الحساسة وحساب الفروق التلقائي.
- أصبح `UndoManager` يطبق السياسة المركزية، ويمنع الحزم المختلطة والتراجع المكرر، ويقفل سجلات المحرك والكيان، ويفحص السجل المفقود أو المعدل أو المعرف المعاد استخدامه، ويسجل منفذ التراجع ووقته والحدث المقابل.
- صُنفت المدفوعات كـreversal-only، ومُنع الاسترجاع المباشر لحذف سجلات بيانات الاعتماد لأن snapshots الآمنة لا تخزن الأسرار.
- أضيفت migration إضافية `20260716_audit_undo_engine_v2.php` للأعمدة والفهارس؛ لم تُشغل على قاعدة `educore`.
- أضيف فاحص read-only لنقاط الكتابة. بعد موجة API الأولى: 150 ملفًا مرشحًا، 64 تعلن تسجيلًا، و86 تحتاج تصنيفًا أو ترحيلًا.
- انتقلت تعديلات ملفات الطلاب والعاملين المركبة إلى `AuditService` الذي يربط حدث النشاط بسجلات batch داخل transaction المستدعي. إنشاء الموظف يستخدم الخدمة نفسها.
- أصبحت قراءة الإشعار واشتراك push وإلغاؤه وإعادة ترتيب الصفوف أحداثًا مدققة داخل معاملات، وأضيف CSRF لواجهتي push دون تسجيل endpoint أو مفاتيح الاشتراك.
- تعرض صفحة الإصدارات الحالات الفعلية (`قابل للتراجع`، `مسجل فقط`، `انتهت المهلة`، `تم التراجع`) ولا تعرض سلة المحذوفات عناصر غير مؤهلة.
- حُمّلت حماية المسودات في footers الإدارة والمعلم والأخصائي. بوابة الطالب لا تملك footer مشتركًا مثبتًا بعد وتبقى ضمن موجة التوحيد.
- اجتازت اختبارات العقود الجديدة والحالية وفحوص PHP syntax. اختبارات MySQL التكاملية مؤجلة لعدم تهيئة `EDUCORE_TEST_DB_NAME` المنتهية بـ`_test`.
- rollback التشغيلي يبدأ بتعطيل تنفيذ التراجع؛ migration إضافية ولا تحذف بيانات قائمة.
- أُغلقت تغطية الكتابة الآلية عند `150` ملفًا مرشحًا: `121` تدقيقًا معلنًا، `14` تفويضًا موثقًا، `15` false positive موثقًا، و`0` مراجعات متبقية.
- شملت الموجة النهائية `AcademicYear`, `NewYearWizard`, `AssessmentEngine`, `CanvaIntegration`, `ClassRoom`, `User`, و`UserProfileStore`، بما في ذلك CRUD والتعيينات والأوصياء والأشقاء والنقل وترقية بيانات الدخول دون تسجيل الأسرار.
- استُخرج `UserAuditSupport` و`UserProfileFacadeTrait` لإبقاء `classes/user.php` تحت حد الحجم المعماري دون تغيير API العام؛ عادت بوابة strict إلى `ARCHITECTURE_AUDIT_REGRESSIONS=0`.
- صُحح تطبيع `ActivityLog.result`: الأحداث بلا context صريح تستخدم `success` دون الوصول إلى مفتاح مفقود، وثُبت ذلك بعقد واختبار دخول hash-first.
- أضيفت أداة CLI محمية لنسخ المخطط فقط إلى قاعدة `_test`. لا تنسخ بيانات الأعمال وترفض production والهدف غير المنتهي بـ`_test`.
- نجحت دورة تراجع فعلية معزولة للإنشاء والتعديل والحذف، ونجح منع batch جزئي عند تعديل لاحق، ونجح rollback للعملية الأصلية ولسجل undo عند تعطل مخزن النشاط.
- نجح قياس 100 حدث بمتوسط `0.672ms` وP95 `1.266ms` في الجولة المباشرة، ثم متوسط `0.615ms` وP95 `0.530ms` ضمن المجموعة الكاملة؛ الميزانية المعتمدة `30ms` متوسط و`75ms` P95 للاختبار المحلي.
- وُحد إشعار التراجع في `includes/undo_toast.php` و`assets/js/undo-toast.js` للإدارة والمعلم والأخصائي، مع CSRF وهروب نصوص الخادم؛ يحتفظ إغلاق المودال بالمسودة ولا يستخدم سكربت المسودات `confirm()` المتصفح، ويفشل اختبار التغطية إذا ظهرت استمارة بيانات مؤهلة في دور بلا الطبقة المشتركة.
- أضيف اختبار تزامن بثلاثة اتصالات: محاولة التراجع أثناء قفل سجل المحرك تفشل بلا تغيير، ثم تنجح بعد تحرير القفل. كما ثبت رفض التراجع المكرر وتجاوز مستخدم آخر لنطاق العملية.
- شُغلت المجموعة الكاملة: `TEST_FILES=137` و`TEST_FAILURES=0` على `educore_audit_test` المعزولة، ونجح lint لـ`633` ملف PHP.
- أضيف تنظيف retention خارج مسار الطلب في `tools/cleanup_undo_retention.php`: dry-run افتراضي، و`--apply` يتطلب اسم قاعدة مطابقًا للاتصال، ويحذف snapshots المنتهية ويتقيد بآخر 500 سجل لكل منفذ داخل transaction؛ لا يحذف سجل الأعمال `activity_logs`.

## 2026-07-13 — حدود التخزين الخاص لمرفقات الطلاب والعاملين

- أضيف `ProfileAttachmentStorage`: كتابة خاصة للمرفقات الجديدة وقراءة مزدوجة للأسماء الخاصة والقديمة.
- أضيف controller تنزيل إداري موحّد مع MIME allow-list و`nosniff` وprivate/no-store cache policy.
- أزيلت روابط `uploads/students/attachments` و`uploads/staff/attachments` المباشرة من صفحات الإدارة النشطة ومخرجات JavaScript.
- أضيفت أداة ترحيل dry-run/snapshot-gated تنسخ ولا تنقل، تتحقق بـSHA-256، تحدث الصف بشرط optimistic، وتكتب manifest داخليًا مع إبقاء المصدر للrollback.
- أنشئ snapshot خاص، ثم رُحلت 3 ملفات قديمة (786,853 بايت) بنجاح: 3 migrated، 0 missing، 0 failed. تحقق rollback dry-run من 3 eligible و0 skipped/failed.
- بعد إثبات أن كل الصفوف أصبحت `private:` أضيف منع HTTP مباشر لمجلدي المرفقات القديمة؛ يظل controller الإداري قادرًا على dual-read عبر filesystem.
- أضيفت أداة rollback مستقلة تتحقق من قاعدة manifest وchecksum ووجود المصدر القديم ثم تستعيد الاسم بشرط transaction/optimistic update، ولا تحذف النسخة الخاصة.
- نجحت 41 ملفات اختبار، lint لـ449 ملف PHP، architecture strict audit، وadmin UI audit.

## 2026-07-13 — Atomicity للبيانات المالية واختبار الإغلاق

- ربطت `admin/staff_financial_data.php` سجل النشاط باتصال PDO نفسه، وأصبحت إنشاء profile وتحديثه وكتابة audit عملية ذرية واحدة مع rollback آمن.
- أضيف اختبار عقد لترتيب auth/CSRF/transaction/update/audit/commit/rollback.
- شُغّلت 38 ملفات اختبار على `educore_test` المعزولة: `TEST_FAILURES=0`.
- نجح lint لـ443 ملف PHP، ونجح architecture strict audit وdocumentation audit وadmin UI audit.
- بقيت مرحلة نقل المرفقات الخاصة غير منفذة لأن سياسة الوصول/الاحتفاظ/التشفير وحجم بيانات الإنتاج غير مثبتة؛ لم تُمس بيانات إنتاج أو روابط عامة.

## 2026-07-13 — إغلاق خط أساس CSRF ومسارات الهوية العامة

- حماية إرسال نتائج الأنشطة والاختبارات العامة وحفظ تقدم الاختبار برمز جلسة CSRF.
- حماية تسجيل الدخول التقليدي، رجوع الطالب، إدارة طلاب الأخصائي، وتبديل وضع المشرف.
- إيقاف Teams Context غير الموقّع وإلزام Microsoft token بالتوقيع و`iss` و`aud` و`tid` و`exp`.
- إضافة بيان استثناءات محدود ومنتهي الصلاحية لمسارات القراءة فقط وتبادل bearer token الموثق.
- تحديث strict audit لرفض الاستثناءات التالفة أو المنتهية أو غير المطابقة.
- النتيجة: `RUNTIME_DDL_FILES=0` و`POST_WITHOUT_EXPLICIT_CSRF_CANDIDATES=0` و`ARCHITECTURE_AUDIT_REGRESSIONS=0`.

## 2026-07-13 — بدء تفكيك صفحات الطلاب والموظفين

- ثُبت خط أساس ملفات الطلاب والموظفين والاستيراد التفصيلي في commit `d37ff3b` مع اختبارات توصيف.
- أضيف `tests/bootstrap_test_database.php`؛ يرفض أي اختبار كتابي ما لم يكن اسم القاعدة الصريح منتهيًا بـ`_test` ومطابقًا لـ`SELECT DATABASE()`.
- أُنشئت `educore_test` محليًا من مخطط `educore` فقط بلا بيانات أعمال، ونُسخ سجل migrations التقني لتجنب إعادة تشغيل migrations قديمة غير idempotent فوق مخطط حديث.
- استُخرج التحقق المشترك إلى `ProfileInputValidator` في commit `2f1e0ed`، وحُذفت نسخ الرقم القومي والهاتف وتاريخ الميلاد من الصفحتين.
- استُخرجت دورة قيد الطالب والنقل وقفل التقييم وتحديث فصل الدرجات إلى `StudentEnrollmentService`؛ بقيت `admin/students.php` نقطة الدخول المتوافقة.
- استُخرجت قراءات الاسم والفصل ولقطة النشاط والتحقق من هدف الطالب إلى `StudentProfileRepository`.
- كشف اختبار التكامل أن `User::splitDisplayName()` و`joinNameParts()` كانتا مستهلكتين لكن غير موجودتين؛ أضيف العقدان وثُبت اتساق أسماء الطالب والموظف ومشتقات عمر الطالب عند حفظ الملف.
- أزيل تشغيل migration و`ALTER TABLE` من `admin/staff.php`. أضيفت migration `20260713_staff_profile_legacy_columns.php` و`StaffSchemaGuard` للجاهزية القرائية، وانخفض دين runtime DDL المتوقع ملفًا واحدًا.
- استُخرج تطبيع ومزامنة حالات العامل والحركات الوظيفية وملخص الملف إلى `StaffEmploymentLifecycleService` مع تمرير المنفذ صراحة بدل اعتماد الجلسة داخل الخدمة.
- نجح اختبار سياسة مستقل واختبار تكامل يحفظ الحالة والحركة والملخص داخل transaction مرتجعة على `educore_test`.
- نُقل إنشاء `staff_roles` و`staff_role_pages` وتحويل `users.role` من `admin/staff_accounts.php` إلى migration، وأضيف حارس جاهزية واختبار تكامل معزول.
- جُمعت مخططات الدوام وتدقيق الحضور والبصمة وكود الموظف وأرصدة الإجازات في migration HR واحدة؛ تحولت دوال `ensure*` إلى تحقق قرائي عبر `HrSchemaGuard` دون كسر مستهلكيها.
- نُقلت مخططات Canva وقوالب PowerPoint وأعمدة محتوى الدروس إلى migration واحدة؛ أضيف `SchemaReadinessGuard` وحُذف DDL الميت من طلب توليد الدرس.
- نُقلت مخططات أجهزة البصمة والمواد وصورة الجدول وتقدم الاختبار إلى migration تشغيلية، وأضيف تحقق admin/CSRF المبكر حيث كان ناقصًا.
- نُقلت ترقيات المكتبة وجدولا التراجع وسلة المحذوفات إلى migration، وتحولت تهيئة `UndoManager` إلى تحقق قرائي يعاد لكل اتصال صريح.
- استبدلت جداول نسخ التقييمات الديناميكية بخدمة snapshots ثابتة ومعاملات DML، مع استيراد نسخ الجداول القديمة دون حذفها وحماية CSRF مبكرة لصفحة التصفير.
- أوقف MySQL EVENT القديم للنسخ داخل مخطط الإنتاج لعدم وجود مستهلك ولأنه لا يمثل نسخة مستقلة؛ بقي adapter قرائي ومسار SQL backup الفعلي.
- أضيف حارس CSRF مبكر ورموز داخل جميع نماذج ثماني صفحات admin كتابية، مع اختبار عقد يتحقق من ترتيب المصادقة/الحارس قبل قاعدة البيانات ومن كل نموذج POST.
- وُحّدت حماية endpoints الحساسة والمشتركة: كشف كلمات المرور، ربط الصفوف، الاستيراد، إعدادات المدرسة والنسخ، إغلاق الإشعارات وإعادة الترتيب؛ وأصبح المدقق يتعرف على الحارسين المركزيين `requireCsrfToken` و`adminImportBootstrap` بدل عدّهما ديونًا كاذبة.
- حُميت عشرة endpoints JSON للمعلم بالحارس الداعم لـJSON، وأرسلت صفحات الدرس token في جميع callers؛ كما حُميت صفحات الحضور والتقييم والتدريب والأرشيف مع نقل المصادقة قبل اتصال قاعدة البيانات حيث كانت متأخرة.
- نجحت اختبارات الخدمة والمدقق، واختبارات render للطلاب في حالتي القائمة والإضافة، واختبار هوية الطالب على `educore_test`.
- اكتمل فصل عرض صفحة الطلاب دون تغيير DOM: نُقل عرض الملف الشخصي ونموذج الإضافة/التعديل والقائمة ومودالاتها وسكربت النموذج الديناميكي إلى fragments داخلية محمية تحت `classes/Presentation/Students`، وبقي `admin/students.php` نقطة الدخول ومالك الطلب.
- أُعيد عقد `students_offset` إلى `StudentListPageQuery` ونقطة الدخول بعد أن كشف الفصل تحذير متغير غير معرّف في ترقيم صفوف القائمة.
- بدأ تفكيك `admin/staff.php` باستخراج التحقق وبناء payload المتكرر بين الإضافة والتعديل إلى `StaffProfileRequestMapper` الذي لا يقرأ superglobals.
- نُقلت معاملتا إنشاء وتعديل العامل والقفل التفاؤلي ومزامنة الحالة والتدقيق والتراجع إلى `StaffProfileCommandService`؛ بقيت الصفحة مالكة HTTP والجلسة والتحويل.
- نُقلت إدارة صورة العامل وحذف مرفقاته إلى `StaffAttachmentService`، ووُحّد تحقق الهدف وقراءة الاسم في `StaffProfileRepository`؛ تحديث الصورة يقفل الصف ويحذف الصورة القديمة بعد commit.
- نُقل حذف العامل وتنظيف تعييناته ومنع الحذف المرتبط بالتقييمات والتدقيق إلى `StaffDeletionService` بمعاملة مرتجعة؛ بقيت الرسالة والتحويل في الصفحة.
- نُقلت قراءات edit/view والتاريخ الوظيفي والمرفقات وسجل النشاط إلى `StaffProfilePageQuery` و`StaffListPageQuery`، ونُقل اشتقاق تسمية الحالة الاجتماعية بعد تعريف قاموس العرض لمنع متغير غير معرّف.
- اكتمل فصل عرض العامل إلى fragments محمية للملف والنموذج وسكربت النموذج والقائمة وسكربتات الصفحة، ونُقلت قراءة صفوف القائمة وقيم فلاترها إلى `StaffListPageQuery`؛ انخفضت نقطة الدخول إلى نحو 400 سطر، ولا يتجاوز أي fragment جديد حد 2000 سطر.
- بدأ تفكيك `classes/user.php`: نُقل SQL ملفات الطلاب والعاملين والأوصياء والقرابة والتاريخ الأكاديمي إلى `UserProfileStore`، وبقيت كل الدوال العامة في `User` كواجهة توافقية؛ انخفض الملف إلى أقل من 2000 سطر.
- فُصل قالب الامتحان الكبير إلى `ExamTemplateRenderer` بمدخلات config صريحة، وبقي `ExamGenerator` واجهة التوليد المتوافقة؛ أصبح كلا الملفين دون حد 2000 سطر.
- فُكك `includes/ajax_handlers.php` إلى نقطة دخول أمنية بحجم يقارب 200 سطر وأربع مجموعات handlers محمية للتقارير والاستعلامات/العلاقات والتقييمات والخدمات، مع إبقاء جميع actions وعقود الاستجابة.
- بدأت هجرة modular monolith الفعلية بوحدة الطلاب: أضيف PSR-4 لـ`src/`، ونُقل تنفيذ خدمات/استعلامات/عرض الطلاب إلى `src/Modules/Students`، مع ملفات `class_alias` توافقية في `classes/` واختبار حدود مستقل وخطة رجوع بلا تغيير schema أو URLs.
- تبعت وحدة ملف العاملين النمط المثبت: نُقلت خدمات profile/attachment/deletion وpage queries وعروض `staff.php` إلى `src/Modules/Staff` مع aliases توافقية، بينما بقيت lifecycle/attendance/leave/accounts/finance خارج نطاق الدفعة وبمالكيها الحاليين.
- لم تُنسخ بيانات إنتاج ولم يحدث push.

## 2026-07-12 — بداية التدقيق وخط الأساس

### النطاق

- قراءة تعليمات المستخدم المرفقة كاملة.
- قراءة `AGENTS.md` والوثائق الأساسية وComposer وأدوات الاختبار.
- فحص Git والحجم والبنية.
- تتبع ستة workflows من الكود الحالي.
- لا تعديل لبيانات قاعدة البيانات.

### حالة Git قبل المرحلة

- الفرع: `main`.
- متقدم محليًا عن `origin/main` بعملية واحدة.
- توجد 14 ملفات tracked معدلة و8 ملفات untracked سابقة للمرحلة.
- الملفات المتسخة الرئيسية تشمل students/staff/User وCSS/JS وproject memory؛ لم تُلمس في مرحلة الوثائق.
- `git diff --check` كان يفشل قبل المرحلة بسبب trailing whitespace في تغييرات سابقة، خصوصًا `admin/calculation_tools.php`, `admin/class_lists.php`, `admin/relationship_discovery.php`, `admin/siblings.php`, `includes/print_template.php`.

### الفحوص المنفذة

| الأمر | النتيجة |
|---|---|
| `git status --short --branch` | baseline dirty مسجل |
| `git branch --show-current` | `main` |
| `git log -5 --oneline` | تمت مراجعته؛ آخر commit `379150c3` |
| `php -v` | PHP 8.2.12 |
| `php tools/php_lint.php` | 396 ملف، 0 failures |
| `php tools/audit_admin_ui.php` | 117 admin PHP، 0 issues |
| `composer validate --no-check-publish` | صالح |
| `composer audit --no-interaction` | لم يكتمل: timeout إلى Packagist؛ لا حكم أمني |
| `tests/assessment_engine_unit_test.php` | PASS لكل الحالات المطبوعة |
| `tests/password_security_test.php` | PASS لكل الحالات المطبوعة؛ اختبار utility لا login integration |
| `tests/student_current_age_test.php` | PASS |
| `tests/profile_excel_import_template_test.php` | PASS |

### اختبارات لم تُشغّل عمدًا

- اختبارات التكامل التي تفتح معاملات على قاعدة `educore` أو تستدعي endpoints قد تكتب audit rows.
- migrations وseed/repair tools.
- السبب: لا توجد قاعدة staging منفصلة مثبتة، والمطلوب عدم تعديل production data.

### الملفات المنشأة

- `docs/architecture-audit/01-executive-summary.md`
- `docs/architecture-audit/02-repository-map.md`
- `docs/architecture-audit/03-current-architecture.md`
- `docs/architecture-audit/04-structure-findings.md`
- `docs/architecture-audit/05-modules-and-dependencies.md`
- `docs/architecture-audit/06-quality-assessment.md`
- `docs/architecture-audit/07-target-architecture.md`
- `docs/architecture-audit/08-proposed-directory-structure.md`
- `docs/architecture-audit/09-architecture-diagrams.md`
- `docs/architecture-audit/10-gap-analysis.md`
- `docs/architecture-audit/11-implementation-roadmap.md`
- `docs/architecture-audit/12-architecture-rules.md`
- `docs/architecture-audit/13-risk-register.md`
- `docs/architecture-audit/14-decisions-and-assumptions.md`
- `docs/architecture-audit/15-implementation-log.md`

### ما لم يتغير

- لا PHP/CSS/JS/schema/config تطبيقي.
- لا `.env` أو secrets.
- لا بيانات ولا migrations.
- لا push remote.
- لا commit حتى اكتمال تحقق مرحلة الوثائق.

### تعطل الوكلاء الفرعيين

حاولت ثلاثة تدقيقات read-only متوازية، لكنها لم تبدأ عملًا فعليًا بسبب حد استخدام الأداة. لم تعدل أي ملف، واستُكمل التدقيق محليًا.

### القرار التالي

تحقق حزمة الوثائق، ثم commit مركز إن بقي diff مقتصرًا عليها. بعدها تبدأ المرحلة الآمنة التالية فقط إذا نجحت بوابة القرار.

## 2026-07-12 — إغلاق المرحلة 0

- Commit: `0cebaad9` (`docs: add evidence-based architecture audit`).
- احتوى commit على ملفات `docs/architecture-audit/01-15` فقط.
- نجح `git diff --cached --check` قبل commit.
- لم يحدث push ولم تتغير بيانات.

## 2026-07-12 — المرحلة 1: حماية حدود الويب

### النطاق المنفذ

- أضيف منع HTTP مباشر إلى:
  - `classes/.htaccess`
  - `config/.htaccess`
  - `database/.htaccess`
  - `tools/.htaccess`
  - `tests/.htaccess`
  - `scratch/.htaccess`
  - `tmp/.htaccess`
  - `storage/.htaccess`
- أضيف حارس `PHP_SAPI === 'cli'` قبل تحميل قاعدة البيانات في `tools/run_migrations.php`.
- أضيف `tests/internal_web_boundary_test.php` للتحقق الساكن من ملفات الحماية وترتيب حارس migrations.

### قرارات النطاق

- لم يُحظر `includes/` لأن البحث أثبت 54 مرجعًا مباشرًا إلى `includes/ajax_handlers.php` من صفحات وJavaScript نشطة.
- لم يُحظر `uploads/` لأن مرفقات وصورًا ومواد حالية تُقدّم مباشرة، ونقلها يحتاج مرحلة تخزين خاص.
- لم يُعدل `vendor/` أو `phpmyadmin/` لأنهما ignored/third-party ويحتاجان document-root أو vhost policy، لا commit داخل dependency tree.
- لم يُغير `admin/`, `teacher/`, `student/`, `specialist/`, `api/`, `ajax/`, `auth/`.

### التحقق

- قبل المرحلة: وثيقة تحت الجذر أعادت HTTP 200، وملف داخل `archive/` المحمي أعاد 403؛ هذا أثبت تفعيل `.htaccess` محليًا.
- بعد المرحلة: ملفات آمنة ممثلة من المجلدات الثمانية أعادت HTTP 403.
- `login.php?stage=primary` أعاد 200، و`admin/classes.php` بلا جلسة أعاد 302، ما يثبت أن entrypoints الرئيسية بقيت تعمل.
- `php -l tools/run_migrations.php`: ناجح.
- `tools/php_lint.php`: 396 ملفًا، صفر failures.
- `tools/audit_admin_ui.php`: `UI_AUDIT_ISSUES=0`.
- لم يُشغّل `tools/run_migrations.php` عبر CLI حتى لا تُطبق migrations على قاعدة `educore` الحالية.

### البيانات والتراجع

- لا قراءة/كتابة migration أو seed أو repair.
- Rollback: حذف ملفات `.htaccess` الثمانية، اختبار المرحلة، وحارس CLI المحدود.
- Commit المرحلة: `9faf9d3` (`security: protect internal web boundaries`).
- نجح `tests/internal_web_boundary_test.php`، ولم يحدث push أو تعديل بيانات.

## 2026-07-12 — المرحلة 2: فاحص الامتثال المعماري

### النطاق المنفذ

- أضيف `tools/audit_architecture.php` كأداة CLI-only، باكتشاف recursive من جذر المشروع واستبعادات صريحة للمجلدات غير التشغيلية والطرف الثالث.
- أضيف `tools/architecture_audit_baseline.json` كخط أساس مراجَع للدين الحالي.
- أضيف `tests/architecture_audit_test.php`.
- أضيف Composer script باسم `architecture-audit` يعمل في الوضع الصارم.

### النتائج

- `PHP_FILES_SCANNED=275`.
- حد الملف الكبير: 2000 سطر.
- `LARGE_PHP_FILES=10`.
- `RUNTIME_DDL_FILES=18`.
- `POST_WITHOUT_EXPLICIT_CSRF_CANDIDATES=51`؛ هذه مؤشرات مراجعة وليست ثغرات مثبتة آليًا.
- `UNPROTECTED_INTERNAL_DIRECTORIES=0`.
- `UNREADABLE_PHP_FILES=0`.
- report وstrict كلاهما: `ARCHITECTURE_AUDIT_REGRESSIONS=0`.

### التحقق

- `php -l` للأداة والاختبار: ناجح.
- `tests/architecture_audit_test.php`: نجحت 12 بوابة، ومنها اكتشاف regression جديد ورفض baseline المفقودة والتالفة.
- `composer validate --no-check-publish`: ناجح عبر Composer؛ لم يكن الأمر متاحًا مباشرة في `PATH` لتلك الجلسة.
- `composer architecture-audit`: ناجح، وصفر regressions.
- `tools/php_lint.php`: 399 ملفًا، صفر failures.
- `tools/audit_admin_ui.php`: `ADMIN_PHP_FILES=117` و`UI_AUDIT_ISSUES=0`.
- المراجعة المستقلة: لا blockers؛ أكدت بقاء auth وCSRF قبل DB، وثبات مسارات النجاح وعقد المستهلكين، وسلامة rollback الثانوي.
- `tests/internal_web_boundary_test.php`: كل البوابات التسع PASS.
- الفاحص لا يقرأ `.env` أو قاعدة البيانات ولا يعدّل بيانات.

### حدود الفاحص وقرار النطاق

- فحص CSRF heuristic على مستوى الملف؛ لا يستبدل code review أو اختبار الطلبات.
- baseline تمنع دخول أسماء ملفات جديدة إلى مجموعات الدين الحالية، ولا تقيس زيادة الدين داخل ملف مسموح؛ أي توسيع يحتاج مراجعة منفصلة ولا يُستخدم لإخفاء regression.
- استُبعدت `phpmyadmin/`, `vendor/`, `archive/`, migrations، tests/tools، والتخزين/الرفع من مسح source التشغيلي؛ المجلدات التطبيقية الجديدة تُكتشف تلقائيًا ما لم تُستبعد صراحة.

### الأمان والتراجع

- لا migration أو seed أو repair أو اتصال DB.
- Rollback: حذف الأداة وbaseline والاختبار وإزالة Composer script، ثم إعادة الوثائق التابعة.
- Commit المرحلة: `554a3ec` (`chore: add architecture regression audit`).

## 2026-07-12 — المرحلة 3: التعليمات المعمارية الدائمة

### النطاق المنفذ

- أضيف قسم Architecture Governance وروابط الوثائق إلى `AGENTS.md`، وصُحح الحد الأدنى إلى PHP 8.0 وفق `composer.json`.
- صُحح `README.md`: أزيل ادعاء hash-first وCSRF الكامل، ووثقت الحماية والديون كما هي، وأضيف ترتيب قراءة وأوامر لمساعدي البرمجة.
- حُدث `docs/architecture.md` ليفصل الواقع page-controller/transaction-script عن هدف pragmatic modular monolith.
- أضيفت `docs/project-structure.md`, `docs/architecture-decisions.md`, و`docs/ai-change-checklist.md`، وحُدث `docs/coding-rules.md` ببوابة موجزة.
- استُبدل قالب `.specify/memory/constitution.md` بدستور EduCore v1.0.0، وحُدثت قوالب plan/spec/tasks التابعة.
- أضيف `tests/architecture_documentation_test.php` مع Composer script باسم `documentation-audit` لحماية أولوية التعليمات وتطابق المنصة والدستور والقوالب والحقائق الأمنية الأساسية.

### قرارات النطاق

- `AGENTS.md` بقي المصدر الأعلى؛ الدستور مهايئ Spec Kit ولا يتجاوزه.
- لا يوجد `.specify/extensions.yml` ولا `.specify/templates/commands/`، لذلك لا hooks أو command templates مطلوبة.
- لم تُنشأ ملفات Claude/Gemini/Copilot/Cursor/Windsurf لأن أي صيغة تحميل خاصة بها غير مثبتة في المستودع.
- لم يُعدل `docs/project-memory.md` لأنه متسخ مسبقًا ومملوك لتغييرات أخرى.
- لا PHP تطبيقي أو schema أو CSS/JS أو بيانات تغيرت.

### التحقق

- الدستور لا يحتوي placeholders، وإصداره `1.0.0` بتاريخ اعتماد وتعديل `2026-07-12`.
- `composer documentation-audit`: 21/21 PASS، ومذكور ضمن أوامر README والمعمارية؛ يغطي أيضًا عقد التواصل بين الوحدات وحدود الفاحص وشرط PSR-4.
- `composer validate --no-check-publish`: ناجح عبر Composer.
- `composer lint`: 400 ملف PHP، صفر failures.
- `composer architecture-audit`: 275 ملفًا تطبيقيًا، صفر regressions، وصفر ملفات غير مقروءة.
- `tests/architecture_audit_test.php`: 12/12 PASS.
- `tests/internal_web_boundary_test.php`: 9/9 PASS.
- `tools/audit_admin_ui.php`: `ADMIN_PHP_FILES=117` و`UI_AUDIT_ISSUES=0`.
- بقيت مراجعة diff المستقلة و`git diff --cached --check` قبل commit.

### التراجع

- Revert وثائق/قوالب المرحلة والاختبار فقط؛ لا rollback بيانات.
- Commit المرحلة: `09872ad` (`docs: establish permanent architecture guardrails`).

## 2026-07-12 — المرحلة 4: حماية CSRF لصفحة الفصول

### النطاق المنفذ

- أضيف `requireCsrfPost()` مباشرة بعد `Utilities::validateSession('admin')` في `admin/classes.php`، قبل إنشاء PDO أو تهيئة الترتيب أو قراءة POST.
- أضيف `csrfField()` إلى نماذج الإضافة والتعديل والحذف وتغيير الحالة، دون تغيير action أو `name` أو `id`.
- بقي نموذج الاستيراد المنفصل إلى `import_classes.php` ورمز CSRF الموجود فيه دون تغيير.
- أضيف `tests/classes_csrf_contract_test.php` كاختبار ساكن لا يفتح قاعدة البيانات.
- حُذف `admin/classes.php` من architecture baseline بعد زوال المرشح، وحُدث R-004.

### النتائج والتحقق

- `php -l admin/classes.php`: ناجح.
- `php -l tests/classes_csrf_contract_test.php`: ناجح.
- اختبار العقد: 9/9 PASS، ويثبت ساكنًا ترتيب auth → CSRF → DB/POST وبقاء الإجراءات ووضع token داخل كل نموذج، ويختبر helper منفردًا في subprocess فيعيد 419 بلا تحميل الصفحة أو إعداد DB.
- `tools/audit_architecture.php --strict`: مرشحو POST بلا CSRF صريح انخفضوا من 51 إلى 50، وصفر regressions.
- `tests/architecture_audit_test.php`: 12/12 PASS.
- `composer validate --no-check-publish`: ناجح.
- `composer lint`: 401 ملف PHP، صفر failures.
- `composer documentation-audit`: 21/21 PASS.
- `tools/audit_admin_ui.php`: `ADMIN_PHP_FILES=117` و`UI_AUDIT_ISSUES=0`.
- مراجعة مستقلة بعد عزل subprocess عن الصفحة/DB: APPROVE بلا blockers.

### قرارات الأمان والنطاق

- لم يُرسل POST صالح إلى الصفحة لأن ذلك يحتاج اتصال DB وقد يكتب ترتيبًا أو بيانات؛ لا توجد قاعدة staging منفصلة مثبتة.
- helper يرفض invalid token بـ419 في subprocess معزول، وترتيب المصدر يضع استدعاءه قبل إنشاء `Database`.
- طلب إعادة ترتيب الصفوف يذهب إلى `api/reorder.php`؛ هذا endpoint مستقل وما زال مرشح CSRF في baseline وخارج نطاق handlers المحلية لهذه الدفعة.
- لا SQL أو PRG أو ActivityLog/Undo أو HTML بصري تغير.
- لا migration أو data write أو push.

### التراجع

- Revert حارس الصفحة وحقول CSRF الأربعة والاختبار، وإعادة مدخل baseline/R-004؛ لا rollback بيانات.
- Commit المرحلة: `cc8c222` (`security: enforce CSRF on class management writes`).

## 2026-07-12 — المرحلة 5: إخفاء تفاصيل فشل Undo

### النطاق المنفذ

- عُدّل catch النهائي في `UndoManager::undo()` ليعيد نفس عقد `success/message` برسالة عربية عامة ثابتة دون `Exception::getMessage()`.
- بقي `error_log()` محتفظًا بالتفاصيل server-side للتشخيص.
- أصبح catch يقبل `Throwable` ويحمي فحص transaction والrollback داخل try ثانوي، فلا يسبب خطأ آخر إذا وقع الفشل قبل تعيين `$db` أو أثناء التراجع عن المعاملة.
- غُلّفت تهيئة/dispatch في `api/undo.php` بـcatch عام يسجل التفاصيل ويعيد JSON بعقد `success/message` ورسالة عامة وHTTP 500.
- أضيف `tests/undo_error_contract_test.php` بوصلات زائفة تنفذ DDL no-op وترمي استثناءات عند bootstrap و`prepare()` و`inTransaction()` و`rollBack()`؛ لا config أو PDO أو قاعدة فعلية.

### التحقق

- `php -l classes/UndoManager.php`: ناجح.
- `php -l api/undo.php`: ناجح.
- `php -l tests/undo_error_contract_test.php`: ناجح.
- اختبار العقد: 11/11 PASS.
- مفاتيح failure بقيت `success` ثم `message`، ولم يتغير success path أو مستهلك `includes/admin_footer.php`.
- الاستثناء التجريبي يتضمن `SQLSTATE`, password ومسار Windows؛ لا يظهر أي منها في result، ويظهر كاملًا في ملف log مؤقت.
- فشل تهيئة الجدول، وفشل `inTransaction()`, وفشل `rollBack()` كلها تعيد الرسالة العامة وتُسجل server-side.
- `composer validate --no-check-publish`: ناجح.
- `composer lint`: فحص 402 ملف PHP، صفر failures.
- `composer architecture-audit`: فحص 275 ملفًا تطبيقيًا؛ 10 ملفات كبيرة، و18 ملف runtime DDL، و50 مرشح CSRF، وصفر regressions أو ملفات غير مقروءة أو مجلدات داخلية غير محمية.
- `composer documentation-audit`: 21/21 PASS.
- `tests/architecture_audit_test.php`: 12/12 PASS.
- `tests/classes_csrf_contract_test.php`: 9/9 PASS.
- `tests/internal_web_boundary_test.php`: 9/9 PASS.
- `tools/audit_admin_ui.php`: `ADMIN_PHP_FILES=117` و`UI_AUDIT_ISSUES=0`.

### النطاق والأمان

- لم تُشغّل `UndoManager::setDb()` على PDO؛ الاختبار لا يقرأ `.env` ولا يفتح DB.
- runtime DDL القديم في `ensureTable()` لم يُغير وهو ما زال دينًا في architecture baseline.
- اختبار endpoint ساكن ولا يفتح اتصالًا حقيقيًا. فرع `Database::getConnection()` القديم الذي يستعمل `die()` عند تفعيل `display_errors` لا يمكن لـcatch اعتراضه؛ سياسة الإنتاج تفرض تعطيل العرض، وتبقى إزالة response-level `die()` واختبار فشل الاتصال الفعلي لمرحلة error policy مع قاعدة اختبار معزولة.
- لا schema/data/API keys/JavaScript تغير؛ أضيف HTTP 500 فقط لمسار exception غير المعالج سابقًا.
- Rollback: إعادة catches السابقة وحذف الاختبار وتحديث R-009؛ لا rollback بيانات.
- المراجعة المستقلة: لا blockers؛ أكدت بقاء auth وCSRF قبل DB، وثبات success/consumer contracts، وسلامة rollback الثانوي، وسجلت قيد `Database::getConnection()` أعلاه.
- Commit المرحلة: `ea489e9` (`security: sanitize undo failure responses`).

## 2026-07-13 — المرحلة 12: التحقق النهائي وإغلاق الدفعات الآمنة

### مطابقة التنفيذ بالهدف

- أعيدت مراجعة المعمارية الحالية والمستهدفة وخريطة المستودع/الانتقال والرسومات والفجوات والدرجات والمخاطر والافتراضات.
- بقي الهدف **pragmatic modular monolith تدريجيًا**؛ لم تُنشأ شجرة `src/` أو تجريدات غير مستخدمة، ولم يحدث framework/router/auth rewrite.
- لم تُنقل أو تُقسّم أو تُحذف أو تُعلّم deprecated أي ملفات تشغيلية؛ بقيت URLs ونماذج الطلب والجلسات والعقود العامة كما هي.
- صُحح `docs/PASSWORD_SECURITY.md` كي يصف مسار `User::login()` الفعلي بدل ادعاء hash-first، وأضيفت له بوابة drift دائمة.
- وُسع `AGENTS.md` بمهمة المشروع والمناطق الحساسة وعملية ما قبل التغيير وسلامة Git وتعريف الاكتمال وشروط التوقف.

### مراجعة سلسلة commits

- `0cebaad9` — `docs: add evidence-based architecture audit`.
- `9faf9d3` — `security: protect internal web boundaries`.
- `554a3ec` — `chore: add architecture regression audit`.
- `09872ad` — `docs: establish permanent architecture guardrails`.
- `cc8c222` — `security: enforce CSRF on class management writes`.
- `ea489e9` — `security: sanitize undo failure responses`.
- `526cc7e` — `docs: close safe architecture hardening phases`.

راجعت أسماء الملفات وملخص كل commit؛ كل دفعة ركزت concern واحدًا، ولم يدخل أي ملف من تغييرات المستخدم السابقة في هذه السلسلة.

### بوابة التحقق النهائية

- `composer validate --no-check-publish`: ناجح.
- `composer lint`: 402 ملف PHP، صفر failures.
- `composer architecture-audit`: 275 ملفًا؛ 10 ملفات كبيرة، 18 ملف runtime DDL، 50 مرشح CSRF، وصفر regressions أو ملفات غير مقروءة أو مجلدات داخلية غير محمية.
- `composer documentation-audit`: 25/25 PASS.
- `tools/audit_admin_ui.php`: `ADMIN_PHP_FILES=117` و`UI_AUDIT_ISSUES=0`.
- `composer security-audit`: اكتمل بلا security vulnerability advisories؛ هذه لقطة زمنية لا ضمان دائم.
- نجحت scripts الآمنة التسعة: documentation، internal boundary، password utility، profile Excel template، current age، AssessmentEngine على SQLite memory، architecture audit، classes CSRF، وUndo error contract.
- النتائج المركزة: architecture 12/12، web boundary 9/9، classes CSRF 9/9، Undo 11/11، password utility 7/7، profile template 8/8، current age 3/3؛ كل حالات AssessmentEngine المطبوعة PASS.
- لم تُشغّل سبعة اختبارات تتصل بقاعدة حقيقية أو قد تكتب/تغير schema: password legacy/reveal/storage integrations، profile consistency، student identity، وstaff/student modal render.
- `git diff --check` scoped لملفات الإغلاق يمر. الفحص العام يستمر في إظهار trailing whitespace داخل تغييرات المستخدم السابقة في `admin/calculation_tools.php`, `admin/class_lists.php`, `admin/relationship_discovery.php`, و`admin/siblings.php`؛ لم تُعدل.

### النتيجة والديون المؤجلة

- تحسن المتوسط التفسيري من 4.0 إلى 4.5/10 بفضل الحواجز وقابلية الإثبات، لا بسبب ادعاء إزالة God pages أو الاقتران.
- تبقى المصادقة hash-first، private attachments، finance atomicity، 18 runtime DDL، 50 مرشح CSRF، 10 ملفات كبيرة، RBAC، staging test guard، وglobal error policy أعمالًا مؤجلة في سجل المخاطر/الفجوات.
- لا production data أو migration/seed/repair تغير، ولا DB-writing test شُغّل، ولا remote push حدث.
- Commit توثيق الإغلاق: `526cc7e` (`docs: close safe architecture hardening phases`).

## 2026-07-13 — المرحلة 10: استخراج payload لملفي الطلاب والعاملين

### التنفيذ

- أضيف `StudentProfilePayload` كحد مملوك لتكوين الأسماء، وتطبيع أولياء الأمور وصلات القرابة، والوصاية التعليمية، والهواتف والبيانات الإضافية، وتقسيم أسماء الإضافة الجماعية، وتفاصيل سجل النشاط.
- أضيف `StaffProfilePayload` لتطبيع نموذج العامل، وبناء الأسماء والهواتف والبيانات الإضافية وبيانات التوظيف وتفاصيل النشاط.
- أضيف `StaffProfileRepository` لاستعلام snapshot سجل النشاط بدل إبقاء SQL داخل helper الصفحة.
- بقيت أسماء الدوال العامة الموجودة في `admin/students.php` و`admin/staff.php` كواجهات توافق رفيعة، ولذلك لم تتغير URLs أو أسماء الحقول أو POST actions أو session/JSON contracts.
- أضيف اختبارا وحدة مستقلان للحدود المستخرجة، مع إبقاء اختبارات render الحالية كحماية توصيفية للصفحتين.

### التحقق المرحلي

- lint للصنفين والـrepository والصفحتين: ناجح.
- `student_profile_payload_test.php`: 8/8 PASS.
- `staff_profile_payload_test.php`: 7/7 PASS.
- اختبارات render للطلاب والعاملين على `educore_test`: PASS.
- لا schema أو بيانات إنتاج أو مسارات HTTP تغيرت؛ rollback هو إعادة أجسام adapters وحذف الأصناف والاختبارات الجديدة.

## 2026-07-13 — المرحلة 10: حد سياق صفحة Lesson Prep

### التنفيذ والتحقق

- أضيف `LessonPrepPageContext` لتجميع توفر مفتاح AI وقوالب Canva المدعومة وقوالب PowerPoint الداخلية، مع fallback فارغ عند فشل أي تكامل.
- أبقت `teacher/lesson_prep.php` المصادقة وترتيب إنشاء PDO ومتغيرات القالب وأسماءها كما هي، واستبدلت orchestration المضمنة باستدعاء الحد الجديد عبر callables صغيرة للتكاملات الحالية.
- اختبار الوحدة يغطي توفر المفتاح، فلترة Canva، القوالب الداخلية، وفشل التكاملات الثلاثة دون الحاجة إلى شبكة أو قاعدة إنتاج.
- lint للصنف والصفحة: ناجح؛ `lesson_prep_page_context_test.php`: 4/4 PASS؛ عقد CSRF للمعلم: PASS.
- لم يُفصل CSS/JavaScript المضمن لأن ذلك يغيّر حد presentation واسعًا يعتمد على DOM وPHP interpolation؛ سُجل كدين يحتاج browser characterization مستقلة بدل تقسيم جماعي غير آمن.

## 2026-07-14 — تفكيك Students: خدمة الإضافة الجماعية

- أضيف `StudentBulkCreateService` كحد تطبيقي يملك تصفية صفوف الإضافة الجماعية والتحقق من الفصل والهوية والهاتف والتاريخ، ثم إنشاء المستخدم والملف والقيد وسجلي Activity/Undo داخل transaction واحدة.
- بقي `admin/students.php` مالكًا لتصنيف HTTP والجلسة ورسائل النجاح/الفشل والـredirect، وبقيت أسماء `add_students_bulk` و`bulk_students` و`bulk_default_class_id` كما هي.
- أضيف اختبار وحدة لحدود النطاق والعدد، واختبار عقد ساكن يثبت بقاء action والمدخلات والرسائل والتحويلات وrelated writes والمعاملة.
- rollback: إعادة جسم handler السابق وحذف الخدمة والاختبارين؛ لا schema أو migration أو تغيير بيانات مطلوب.

## 2026-07-14 — تفكيك Students: خدمة الحذف

- أضيف `StudentDeletionService` لامتلاك تحقق دور الهدف وحذف المستخدم وActivityLog وUndo داخل transaction واحدة.
- بقي action `delete_student` ومدخل `user_id` ورسائل الجلسة والredirect في `admin/students.php` دون تغيير.
- أضيف اختبار عقد يثبت guard والمعاملة وسجلي التدقيق والتراجع وعقد الصفحة.

## 2026-07-14 — تفكيك Students: خدمة المرفقات

- أضيف `StudentAttachmentService` لامتلاك تحقق upload، والتخزين الخاص، وصفوف `student_attachments`، والحذف، وسجل النشاط مع تنظيف الملف إذا فشل حفظ الصف.
- بقيت action names والرسائل والتحويل إلى تبويب المرفقات داخل `admin/students.php`.
- أضيف اختبار وحدة يثبت رسائل تحقق الاسم والملف والامتداد والحجم قبل أي وصول للتخزين أو قاعدة البيانات.

## 2026-07-14 — تفكيك Students: خدمة العلاقات

- أضيف `StudentRelationshipService` لربط وفصل الأشقاء وصلات القرابة، مع guard للطالبين، والحفاظ على الربط الثنائي وعقود ActivityLog والمعاملات الموجودة لمساري الفصل.
- بقيت أسماء الأزرار والمدخلات ورسائل الجلسة والredirect إلى تبويب الأشقاء في الصفحة.
- أضيف اختبار عقد لمسارات actions والمفاتيح الدلالية والربط الثنائي والمعاملات والتدقيق.

## 2026-07-14 — تفكيك Students: خريطة طلب ملف الطالب

- أضيف `StudentProfileRequestMapper` لتوحيد تحقق بيانات الطالب وأولياء الأمور، وتطبيع الأب/الأم وصلات القرابة، وبناء payload ملف الطالب ومفاتيح البحث والعمر والهواتف والبيانات الإضافية والوصاية التعليمية.
- استُبدل التكرار بين مساري الإضافة والتعديل باستدعاء mapper واحد، مع الإبقاء على optimistic locking والمعاملة وحفظ القيد والعلاقات وسجلات Activity/Undo في handler الحالي تمهيدًا لنقله إلى command service.
- أضيف اختبار وحدة للمخرجات واختبار عقد يثبت بقاء action والقفل والمعاملة ومشتقات الملف.

## 2026-07-14 — تفكيك Students: خدمة أولياء الأمور

- أضيف `StudentGuardianService` لتجهيز وحفظ أولياء الأمور، وتحويل قيم «أخرى»، وبناء JSON الإضافي، وحفظ الاسم الافتراضي مع قائمة التنبيهات غير المانعة.
- يستخدم مسار التعديل replace داخل المعاملة الحالية، بينما يحتفظ مسار الإضافة بسلوك الإدراج دون حذف مسبق.
- أضيف اختبار وحدة لتجهيز ولي الأمر والاسم الناقص والحقول المخصصة والبيانات الإضافية.

## 2026-07-14 — تفكيك Students: دورة حالة الملف

- أضيف `StudentProfileLifecycleService` لتوحيد حالة حساب الطالب مع حالة القيد، وحفظ النقل الخارجي، ومزامنة القيد السنوي وقفل التقييم ونقل درجات الفصل عند التعديل.
- يعيد الحد `academic_year_id` وعدد الدرجات المنقولة لمسار الرسائل والتدقيق الحالي، وتبقى transaction مملوكة لأمر حفظ الملف.
- أضيف اختبار وحدة لسياسة تحويل حالات enrolled/graduated/transferred.

## 2026-07-14 — تفكيك Students: أمر حفظ الملف

- أضيف `StudentProfileCommandService` كحد تطبيقي كامل لمساري إنشاء وتعديل الطالب، ويملك transaction والقفل التفاؤلي وتحديث الهوية والملف والقيد وأولياء الأمور وسجلات Activity/Undo.
- تقلص handler `save_student_profile` في `admin/students.php` إلى تصنيف الطلب، واستدعاء الخدمة، ورسائل الجلسة والredirect مع حفظ input عند الفشل.
- أعيد توجيه اختبارات العقود السابقة إلى موضع الملكية الجديد بدل ربطها بالـentrypoint.

## 2026-07-14 — تفكيك Students: استعلام بيانات الملف

- أضيف `StudentProfilePageQuery` لقراءة بيانات التعديل والعرض التفصيلي والعلاقات والنقل والمرفقات، مع استمرار استخدام القراءة الخالية من بيانات الاعتماد.
- أصبحت الصفحة تربط مفاتيح result بمتغيرات العرض الحالية بدل تنفيذ SQL مباشر لبيانات الملف.
- أضيف اختبار عقد يثبت مساري edit/view وقراءات العلاقات والمرفقات وعدم تحميل credentials.

## 2026-07-14 — تفكيك Students: استعلام القائمة والسجل

- أضيف `StudentListPageQuery` لامتلاك ترقيم قائمة الطلاب وأسبقية فلاتر الفصل/الصف/المرحلة وقراءات الفصول والصفوف والمراحل وسجل النشاط المصفح.
- بقيت قيم render الحالية وأسماء GET وscope وترتيب SQL وعدد 100 طالب و40 سجل نشاط لكل صفحة دون تغيير.
- أصبحت الصفحة تربط result صريحًا بمتغيرات العرض، وأضيف اختبار عقد للفلاتر والترقيم والاستعلامات والسجل.

## 2026-07-15 — وحدة BehaviorEvaluation بنمط PSR-4

- نُقل تنفيذ `Evaluation` و`EvaluationType` وhandler إجراءات التقييم إلى `src/Modules/BehaviorEvaluation`.
- بقيت ملفات `classes/evaluation*.php` aliases متوافقة، وبقي مسار handler القديم adapter لكي تظل نقطة AJAX العامة وactions والحواجز وعقود JSON ثابتة.
- أضيف اختبار حدود للوحدة، ووُسع اختبار dispatch لقراءة التنفيذ الجديد وإثبات اتصال adapter به.
- لا SQL أو schema أو بيانات أو URL تغير؛ rollback موثق في README الوحدة ولا يحتاج rollback بيانات.

## 2026-07-15 — إزالة تراجعات حجم calculation_tools وclass_lists

- نُقلت تنسيقات `admin/calculation_tools.php` المتأخرة إلى `assets/css/calculation-tools.css` مع إبقاء عقود AJAX والحسابات وDOM كما هي.
- نُقلت تنسيقات `admin/class_lists.php` إلى `assets/css/class-lists.css`، ونُقل سكربت العرض الديناميكي كما هو إلى fragment داخلي `classes/Presentation/ClassLists/page_scripts.php` مع بقاء متغيرات PHP في نطاق الاستدعاء نفسه.
- أضيف اختبارا عقد ساكنان لحواجز المصادقة وCSRF/actions ووظائف الحساب والنقل والتصدير والطباعة وملكية assets وحد 2000 سطر.
- انخفضت تراجعات strict architecture audit من ملفين إلى صفر. الرجوع هو إعادة كتل CSS/JavaScript إلى نقطتي الدخول وحذف assets والاختبارين؛ لا schema أو بيانات متأثرة.

## 2026-07-15 — فصل سكربتات لوحة الإدارة

- نُقلت تفاعلات ترتيب/تحجيم أقسام `admin/index.php` ورسوم Chart.js إلى fragmentين داخليين محميين تحت `classes/Presentation/Dashboard` مع إبقاء مكتبات الطرف الثالث وترتيب تحميلها في نقطة الدخول.
- بقيت استعلامات اللوحة والصلاحيات وDOM IDs وعقود التخزين المحلي والرسوم دون تغيير، وانخفضت نقطة الدخول تحت 2000 سطر.
- أضيف اختبار عقد للبنية وترتيب Chart.js؛ rollback يعيد كتلتي script إلى الصفحة ويحذف fragmentين والاختبار، ولا يحتاج rollback بيانات.

## 2026-07-15 — فصل تنسيق تحليلات التقييم

- نُقلت كتلة CSS من `specialist/evaluation_analytics.php` إلى `assets/css/evaluation-analytics.css` دون تغيير استعلامات التقييم أو الفلاتر أو الرسوم.
- أضيف اختبار عقد للمصادقة والاعتماديات وعقد التحليلات وحد الحجم، وأزيل المسار من baseline بعد انخفاضه تحت 2000 سطر.
- rollback يعيد كتلة CSS إلى الصفحة ويحذف asset والاختبار؛ لا schema أو بيانات متأثرة.

## 2026-07-15 — تقسيم قوالب AIPrompts

- بقي `AIPrompts` وواجهته العامة كما هما، ونُقلت مجموعة قوالب الملخص والمحتوى والقصص وPowerPoint إلى trait داخلي محمي `config/AIPrompts/ContentPrompts.php`.
- يثبت اختبار العقد بقاء الطرق العامة والخاصة الأساسية وسلوك ضم المحتوى وحد الشرائح، وانخفض `config/ai_prompts.php` تحت 2000 سطر وأزيل من baseline.
- rollback يعيد الطرق إلى جسم الكلاس ويحذف trait والاختبار؛ لا config secrets أو بيانات متأثرة.

## 2026-07-15 — فصل تنسيق عرض الدرس

- نُقلت CSS المضمنة من `teacher/lesson_view.php` إلى `assets/css/lesson-view.css` مع إبقاء بوابات أدوار المعلم/الخارجي/admin ونطاق `teacher_id` وجلب الدرس وDOM وسكربتات العرض والطباعة كما هي.
- أضيف اختبار عقد للبوابات والاعتماديات وحد الحجم، وأزيل الملف من baseline بعد انخفاضه تحت 2000 سطر.
- rollback يعيد CSS إلى الصفحة ويحذف asset والاختبار؛ لا بيانات دروس أو schema متأثرة.

## 2026-07-15 — تفكيك عرض Lesson Prep وإغلاق دين الملفات الكبيرة

- بقي `teacher/lesson_prep.php` نقطة الدخول ومالك auth وتهيئة PDO وسياق API/Canva/PowerPoint، ونُقلت CSS إلى `assets/css/lesson-prep.css`.
- نُقل النموذج والسكربت الطويل كما هو إلى أربعة fragments محمية تحت `classes/Presentation/LessonPrep`، وكل ملف PHP أصبح تحت 2000 سطر مع بقاء ترتيب العرض وPHP interpolation وCSRF وDOM IDs.
- يثبت اختبار العقد الأدوار وCSRF والتكاملات والنموذج وحدود script/CSS والأحجام. أزيل آخر مسار من large-file baseline.
- rollback يعيد CSS والنموذج والسكربت إلى نقطة الدخول ويحذف fragments والاختبار؛ لا بيانات أو schema متأثرة.

## 2026-07-15 — سياسة فشل قاعدة البيانات وحراسة الاختبارات

- أزيل فرع `die()` الذي كان يعرض رسالة PDO عندما يكون `display_errors` مفعلاً؛ بقي عقد `getConnection()` المتوافق بإرجاع `null` عند الفشل.
- أضيف `SafeErrorPolicy` لتسجيل تشخيص منظم بمرجع عشوائي وحجب أنماط الأسرار دون خلطه مع سجلات الأمن أو الأعمال.
- أصبح اختبار endpoint كشف كلمة المرور واختبار كلمة المرور القديمة يستخدمان قاعدة `_test` الصريحة بدل قاعدة التطبيق، وأضيف رفض صريح لبيئة production.
- صحح محمّل `.env` أسبقية متغيرات العملية حتى لا تستبدل إعدادات CI/الاختبار الصريحة بقيم الملف المحلي.
- تغطي الاختبارات فشل اتصال زائفًا دون response body، ورفض الاسم المفقود و`educore` والأسماء غير المنتهية بـ`_test` وبيئة production. rollback يعيد catch القديم وإقلاع الاختبار السابق؛ لا schema أو بيانات تغيرت.

## 2026-07-15 — ذرية تعريف أدوار العاملين وصلاحيات الصفحات

- أصبح حفظ الدور المخصص وتبديل صفوف `staff_role_pages` وكتابة `ActivityLog` عملية واحدة على اتصال PDO واحد.
- يبدأ transaction بعد التحقق والقراءات اللازمة، ويفشل الحفظ كاملًا إذا فشل سجل النشاط، ويعيد catch كل الكتابات عبر rollback.
- لم تتغير أسماء actions أو الحقول أو الرسائل الناجحة أو URL/PRG. يثبت اختبار العقد ترتيب begin/write/page replacement/audit/commit/rollback؛ rollback البرمجي هو إعادة الغلاف السابق فقط ولا توجد migration.
- أُغلق كذلك مسار حذف حضور عامل: حذف `staff_attendance` وكتابة سجل التدقيق أصبحا داخل transaction واحدة، ويعرض الفشل رسالة آمنة بعد rollback بدل ترك حذف بلا audit.

## 2026-07-15 — Authorization facade ومصفوفة الأدوار

- أضيف `AuthorizationFacade` نقي لتوحيد supervisor detection وeffective role وقبول الدور المطلوب وصفحة الإدارة، وأصبحت `Utilities` تفوض هذه القرارات إليه مع بقاء redirects والجلسة وقراءة `staff_role_pages` كما هي.
- تغطي المصفوفة admin/super_admin/custom admin/teacher/specialist/student/external teacher/supervisor بنمطيه وteacher supervisor flag، إضافة إلى allowed/denied admin pages.
- لم يُنشأ جدول أو permission model موازٍ، ولم تتغير صلاحيات Assessment المتخصصة. rollback يعيد bodies الصغيرة في `Utilities` ويحذف facade واختباره؛ لا بيانات أو schema متأثرة.

## 2026-07-15 — المصادقة hash-first وترقية legacy عند الدخول

- أصبح `PasswordAuthenticator` يختبر `password_hash` أولًا ويمنع fallback عندما يوجد hash، ويدعم الحساب القديم فقط عبر علم بيئة صريح.
- يكتب أول legacy login ناجح hash، كما تكتب عمليات إنشاء/تحديث `User` وإعادة تعيين حساب الطالب أو العامل hash فورًا مع إبقاء الغلاف المشفر لتوافق reveal الحالي.
- أضيفت `User::verifyPassword()` لنقاط الإدارة الحساسة التي كانت تستدعي عقدًا غير موجود، وهي تستعمل السياسة نفسها.
- تغطي اختبارات الوحدة hash authority والـfallback flag، ويغطي اختبار تكامل على `educore_test` ترقية حساب قديم ورفض الغلاف القديم بعد أن يصبح hash موجودًا. لا migration جديدة لأن الأعمدة موجودة في migrations المؤرخة.

## 2026-07-15 — إصلاح مسار النسخ الاحتياطي SQL المجدول

- استعيد منفذ dump كأداة CLI محمية `tools/backup_db_sql.php` بدل الإشارة المكسورة إلى ملف غير موجود في الجذر، وأصبح المجدول يفشل بوضوح إذا غابت الأداة.
- انتقل المسار الافتراضي للنسخ الجديدة من `db_backups` العام إلى `storage/backups/sql` المحمي؛ الإعدادات المخصصة المحفوظة لا تتغير تلقائيًا.
- يحتفظ التنفيذ بـ`proc_open` argument array ويمرر كلمة مرور MySQL لبيئة العملية الابنة ولا يضعها في command line. اختبار العقد لا ينفذ dump ولا يكتب قاعدة بيانات. rollback يعيد مسار المجدول والافتراضي؛ لا ملفات backup حُذفت.

## 2026-07-15 — مراجعة علاقات القرابة وفصل اقتراحات الأب والأم

- أعيد تنظيم واجهتي الأشقاء والاكتشاف مع تبويبات مستقلة لاقتراحات الأب والأم والقرابات الأخرى، وأصبحت بيانات الأم الداعمة ظاهرة للمراجع دون تغيير مفتاح المرشح أو قواعد الاكتشاف.
- يرفض POST زوجًا غير موجود في نتيجة الاكتشاف الحالية، ويحصر تبويب PRG في allow-list ثابتة.
- أصبح الربط الثنائي للأشقاء ذريًا، كما أصبح الربط مع ActivityLog عملية واحدة على PDO نفسه. يثبت اختبار العقد auth/CSRF/revalidation/transaction/form tokens؛ rollback يعيد واجهة العرض وغلاف transaction دون schema أو حذف بيانات.

## 2026-07-15 — إعدادات المدرسة والطباعة ثنائية اللغة

- أضيفت حقول إنجليزية اختيارية لبيانات المدرسة والمسؤولين والحقول المخصصة عبر جدول settings القائم، دون schema جديد أو تغيير أسماء الحقول العربية.
- وسعت دوال الطباعة واجهتها بمعاملات اختيارية فقط، وتحافظ على العربية افتراضيًا، وتستخدم القيمة الإنجليزية الصريحة ثم fallback ثابتًا لا يعيد `null` للقيم غير المعروفة.
- يثبت اختبار العقد تطابق مفاتيح الحفظ والطباعة، وبقاء توقيع الطباعة القديم صالحًا، وترجمة المراحل والفallback. rollback يحذف المفاتيح/الحقول الاختيارية من العرض فقط؛ القيم المحفوظة غير مؤذية ويمكن تركها.

## 2026-07-15 — تخصيص لوحة إحصاءات الطلاب

- أصبحت مجموعات مؤشرات/رسوم/جداول إحصاءات الطلاب قابلة للإظهار والحفظ محليًا بمفتاح namespaced، مع قيم fallback صريحة عند تعذر الاستعلام وعدادات stat-card المركزية.
- يمنع sortable بدء السحب من resize handle حتى لا يتعارض تغيير الحجم مع إعادة ترتيب عناصر اللوحة.
- بقيت الاستعلامات والحقول وعقود التصدير كما هي، ولا تضيف الصفحة أنماط CSS محلية. يثبت اختبار العقد تطابق widget IDs/toggles وسلوك الحفظ؛ rollback يحذف تفضيلات العرض الجديدة فقط.

## 2026-07-15 — دفعات Undo الصريحة

- أزيل استنتاج العمليات الجماعية عبر نافذة زمنية، وأضيف `undo_log.batch_id` اختياري بفهرس مركب وهجرة idempotent.
- يستخدم النقل الجماعي وتحديثا ملف الطالب والموظف معرّف دفعة عشوائيًا واحدًا؛ السجلات بلا معرّف تتراجع منفردة.
- طُبقت الهجرة على `educore_test` فقط، ونجحت اختبارات عقد الدفعة وسياسة الخطأ وعقود خدمتي الملف. rollback يعيد الكود ثم يحذف `idx_undo_batch` و`batch_id`.

## 2026-07-15 — تفاعلات مودالات ملفات الطلاب والعاملين

- أصبحت نافذة التأكيد المشتركة تخفي المودال الأب ثم تعيده بعد الإغلاق، وتُعطّل قابلية السحب في وضع fullscreen المستجيب.
- تدعم لوحة المفاتيح Undo تخطيطات العربية والإنجليزية، وأصبح فحص حالته متاحًا بعد عمليات AJAX دون تكرار toast تلقائي.
- تملك `admin-unified.css` تخطيط/تمرير النماذج فقط، ونُقل زر الملف التدميري إلى مالكه `buttons.css`. يثبت اختبار عقد مستقل التفاعل والملكية المركزية.

## 2026-07-15 — تطبيق التحديث المحلي وإصلاح عزل migrations

- أُنشئت نسخة SQL محمية قبل النشر تحت `storage/backups/pre_deploy` بحجم 19,018,508 بايت وبصمة SHA-256 مثبتة محليًا، ثم طُبقت migrations على قاعدة `educore` المحلية.
- كشف التشغيل أن ملفي migration قديمين يستخدمان `$name` في نطاق include نفسه، فكان runner يسجل اسم عمود/جدول بدل basename. عُزل كل `require` داخل closure وأضيف اختبار عقد يمنع رجوع التصادم.
- بعد الإصلاح أصبحت migrations المعلقة صفرًا، ونجحت فحوص `undo_log.batch_id` وفهرسه وجداول snapshots والأدوار. نجح smoke test للصفحة الرئيسية والدخول وحماية صفحة الطلاب دون بيانات دخول أو كتابة بيانات أعمال.
