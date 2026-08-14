# المعمارية المستهدفة

## 1. القرار

اعتماد **Pragmatic Modular Monolith** على PHP الحالي، مع إبقاء URLs الحالية كواجهات توافق رفيعة، وإدخال طبقات داخلية تدريجيًا.

لا تتطلب المعمارية المستهدفة Laravel/Symfony، ولا ORM، ولا container معقد. يمكن استخدام Composer PSR-4 للكود الجديد فقط، مع بقاء classmap القديم أثناء الانتقال.

## 2. المبادئ

1. نقطة الدخول تنسق الطلب ولا تنفذ قواعد أعمال كبيرة.
2. Application Service تملك use case والمعاملة.
3. Domain policy/validator تملك القواعد القابلة للاختبار.
4. Repository/Data Gateway يملك SQL والاستمرارية.
5. View تملك العرض فقط.
6. Shared لا يحتوي قواعد أعمال تخص وحدة بعينها.
7. migrations هي المسار الوحيد لتغيير schema.
8. كل مسار كتابة يملك auth + authorization + CSRF + validation + transaction decision + audit.
9. التوافق مع URL/field/session/response الحالي افتراضيًا إلزامي.

## 3. الطبقات

### Entry Points / Presentation

- تبقى `admin/*.php`, `teacher/*.php`, `student/*.php` وغيرها URLs فعلية.
- كل ملف جديد أو مهاجر: يبني Request DTO، يستدعي authorization/service، ثم view/redirect/JSON responder.
- لا SQL ولا DDL ولا business loops طويلة.

### Application

- Use-case services مثل `SaveClassService`, `RecordMarksService`, `UpdateStaffFinanceService`.
- تملك ترتيب الخطوات وحدود transaction والـaudit.
- لا تطبع HTML ولا تقرأ `$_POST` مباشرة.

### Domain

- Validators وPolicies وقيم مثل mark status، employment status، enrollment transition.
- بلا PDO أو session أو HTTP.
- تُختبر وحدويًا.

### Infrastructure

- PDO repositories، file storage، clock، logger، encryption، external APIs.
- لا تقرر صلاحية عمل تجاري؛ تنفذ عقودًا واضحة.

### Shared Kernel

- Authenticated user context.
- CSRF/request/response/errors.
- transaction runner.
- schema inspector read-only أثناء التوافق.
- validation primitives العامة فقط.

## 4. حدود الوحدات المستهدفة

- `IdentityAccess`
- `AcademicStructure`
- `Students`
- `StaffHr`
- `AssessmentReporting`
- `Attendance`
- `BehaviorEvaluation`
- `Finance`
- `Transport`
- `ClinicLibrary`
- `LearningContent`
- `Notifications`
- `OperationsAudit`

التواصل بين الوحدات يتم عبر Application Services أو read-only contracts. لا تصل وحدة إلى ملفات Presentation لوحدة أخرى.

## 5. دورة الطلب المستهدفة

1. entrypoint يحمّل bootstrap واحدًا.
2. bootstrap ينشئ session وrequest context وPDO والخدمات المشتركة.
3. authentication يثبت الهوية.
4. authorization policy تتحقق server-side من الصفحة والـscope.
5. CSRF يتحقق لكل طلب تغيير.
6. validator يبني DTO صالحًا أو ValidationException.
7. service ينفذ use case داخل transaction عند تعدد الكتابات.
8. repository ينفذ prepared SQL.
9. audit يسجل حدثًا منظمًا بلا secrets.
10. responder يعيد PRG/HTML أو JSON contract ثابتًا.
11. error mapper يسجل التفاصيل ويعيد رسالة آمنة.

## 6. المصادقة

- مسار login تقليدي واحد عبر `PasswordAuthenticator` adapter فوق التخزين الحالي.
- `password_hash` هو القرار الأساسي عند وجوده.
- legacy encrypted password يُستخدم فقط للتحقق/الترقية المصرح بها، لا كمقارنة دائمة بديلة.
- session keys الحالية تبقى متوافقة أثناء الانتقال.
- SSO يبقى adapter مستقلًا لكنه ينشئ نفس AuthenticatedUserContext النهائي.
- redirects تستخدم `SITE_URL`/مسار موثوق، لا Host header مباشرة.

## 7. التفويض

واجهة واحدة مثل `AuthorizationService` تجمع دون كسر:

- role checks الحالية.
- supervisor effective mode.
- custom admin page allow-list.
- assessment permission scopes.

الواجهة لا تلغي الجداول أو المفاتيح الحالية؛ تقدم contract واحدًا للـcontrollers. كل سياسة جديدة تُختبر بمصفوفة أدوار وscopes.

## 8. التحقق

- `SharedValidation`: primitives مثل required/int/date/mobile/nationalId/decimal/upload metadata.
- Validators خاصة بالوحدة مثل `StudentProfileValidator`, `MarkInputValidator`.
- لا يُستخدم `htmlspecialchars` كـinput sanitizer؛ escaping يتم عند العرض.
- DTOs تحتفظ بالقيمة المجالّية ولا تعرف HTML.

## 9. الوصول للبيانات

- PDO يبقى التقنية المعتمدة.
- Repository واحد لا يعني واحدًا لكل جدول بالضرورة؛ يفضّل repository لكل aggregate/use case.
- SQL القراءة المعقدة للتقارير يمكن أن يعيش في Query Service/Read Model.
- لا ORM إلزامي.
- `SchemaInspector` مؤقت للتوافق مع installations جزئية، لكنه لا ينشئ schema.

## 10. المعاملات

تُستخدم transaction عندما:

- توجد كتابتان أو أكثر يجب أن تنجحا معًا.
- write + audit جزء من نفس ضمان سلامة البيانات.
- bulk operations.
- انتقال حالة يلمس أكثر من جدول.

لا يوضع network/API call طويل داخل DB transaction. يُستخدم outbox/event record إذا احتاج التدفق لاحقًا.

## 11. الأخطاء

- Exceptions داخلية مصنفة: Validation, Authorization, Conflict, NotFound, Infrastructure.
- HTML: flash message آمنة مع PRG.
- JSON: `{success:false, code, message}` وHTTP status ثابت.
- تفاصيل PDO/stack تُرسل إلى logger فقط.
- request/correlation ID يربط رسالة المستخدم بالسجل.

## 12. التسجيل

- Diagnostic log: `error_log` أو logger، بلا passwords/tokens/PII غير لازمة.
- Security audit: login, reveal, permission denial, sensitive export.
- Business audit: grade changes, finance changes, enrollment lifecycle.
- لا كتابة مزدوجة غير مبررة؛ يمكن adapters مؤقتة أثناء الانتقال.

## 13. الملفات والرفع

- metadata في DB.
- المحتوى الحساس في `storage/private/` خارج direct web access.
- تنزيل عبر controller يتحقق من entity permission ويضبط Content-Type/Disposition.
- أسماء عشوائية + extension/MIME/size checks الحالية تُحفظ.
- انتقال تدريجي يقرأ القديم والجديد حتى ترحيل كل الملفات.

## 14. الاختبارات

- `tests/Unit`: بلا DB التطبيق.
- `tests/Integration`: قاعدة اختبار اسمها صريحًا، مع guard يمنع `educore`/production.
- `tests/Http`: request contracts وauth/CSRF/roles.
- `tests/Architecture`: ممنوعات DDL/SQL/view/dependencies.
- characterization tests قبل استخراج أي God page.

## 15. لماذا هذا أنسب من البدائل؟

| البديل | سبب الرفض |
|---|---|
| إبقاء الوضع كما هو | يزيد التكرار والانحراف الأمني مع كل صفحة جديدة |
| إعادة كتابة كاملة | خطر فقدان قواعد أعمال ضمنية وتوقف طويل وتكلفة تحقق ضخمة |
| Framework كبير | لا يعالج تلقائيًا business boundaries ويتطلب migration متزامنًا واسعًا |
| Microservices | لا توجد حاجة نشر/استقلال بيانات تبرر كلفة الشبكة والاتساق والتشغيل |
| طبقات/Interfaces لكل شيء | overengineering لفريق ونظام PHP تقليدي؛ نحتاج عقودًا عند الحدود فقط |

## 16. استراتيجية التوافق

- Strangler pattern داخل monolith: endpoint القديم يستدعي الخدمة الجديدة.
- لا نقل URL في أول extraction.
- wrappers قديمة تحمل أسماء الدوال الحالية وتفوض للمكوّن الجديد.
- dual-read فقط عند migrations الحساسة وبمدة محددة.
- feature flags فقط عندما يكون rollback عبر adapter غير كافٍ.
