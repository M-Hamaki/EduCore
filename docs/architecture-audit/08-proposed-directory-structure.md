# بنية المجلدات المقترحة

## حالة الخريطة عند الإغلاق الآمن

- هذه الشجرة **هدف تدريجي وليست بنية منفذة بالكامل**. لم يُنشأ `src/` أو `views/` لمجرد اكتمال الرسم، لأن ذلك سيصنع مجلدات/تجريدات بلا use case حقيقي.
- لم يُنقل أو يُقسّم أو يُحذف أو يُهمل أي ملف تشغيل خلال الدفعات الآمنة؛ بقيت URLs وentrypoints و`classes/` و`includes/` متوافقة.
- خريطة القديم إلى الجديد أدناه ما زالت خطة strangler: يبدأ النقل فقط مع workflow موصوف واختبارات عقد وrollback، وتُحدّث الخريطة في commit التنفيذ نفسه.

## 1. الشجرة المستهدفة

```text
EduCore/
├── admin/                     # Entry-point adapters الحالية (URLs محفوظة)
├── teacher/                   # Entry-point adapters الحالية
├── student/                   # Entry-point adapters الحالية
├── specialist/
├── supervisor/
├── external/
├── api/                       # JSON entrypoints فقط
├── ajax/                      # Legacy adapters؛ يقل تدريجيًا
├── auth/                      # SSO entrypoints/adapters
├── src/
│   ├── Shared/
│   │   ├── Auth/
│   │   ├── Authorization/
│   │   ├── Database/
│   │   ├── Http/
│   │   ├── Validation/
│   │   ├── Logging/
│   │   └── Files/
│   └── Modules/
│       ├── IdentityAccess/
│       ├── AcademicStructure/
│       ├── Students/
│       ├── StaffHr/
│       ├── AssessmentReporting/
│       ├── Attendance/
│       ├── BehaviorEvaluation/
│       ├── Finance/
│       ├── Transport/
│       ├── ClinicLibrary/
│       ├── LearningContent/
│       ├── Notifications/
│       └── OperationsAudit/
├── views/
│   ├── admin/
│   ├── teacher/
│   ├── student/
│   └── shared/
├── config/
├── database/
│   ├── migrations/
│   └── schema/
├── assets/
├── storage/
│   ├── private/
│   ├── exports/
│   ├── cache/
│   └── logs/
├── uploads/                   # Legacy public storage أثناء الانتقال فقط
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Http/
│   ├── Architecture/
│   └── Fixtures/
├── tools/                     # CLI-only
├── docs/
├── classes/                   # Legacy compatibility أثناء الانتقال
├── includes/                  # Legacy shared includes أثناء الانتقال
├── AGENTS.md
└── composer.json
```

`public/` كـdocument root منفصل هو هدف نشر لاحق عالي المخاطر، وليس نقلًا تلقائيًا في المرحلة الأولى، لأن URLs الحالية وربط XAMPP يجب أن يبقيا مستقرين.

## 2. تركيب الوحدة

```text
src/Modules/Students/
├── Application/
│   ├── SaveStudentProfile.php
│   └── TransferStudent.php
├── Domain/
│   ├── StudentProfileData.php
│   ├── StudentProfileValidator.php
│   └── EnrollmentPolicy.php
├── Infrastructure/
│   ├── PdoStudentRepository.php
│   └── PrivateStudentAttachmentStorage.php
└── Presentation/
    └── StudentProfileViewModel.php
```

لا يلزم إنشاء كل مجلد مسبقًا. يُنشأ عند أول use case حقيقي، لا لمجرد اكتمال الشجرة.

## 3. مسؤوليات وقواعد المجلدات

| المجلد | مسموح | ممنوع | تسمية |
|---|---|---|---|
| role folders | request coordination، auth call، DTO mapping، responder | SQL/DDL/business algorithms كبيرة | URL الحالي يبقى |
| `src/Shared/Auth` | user context/session adapters | business role rules الخاصة بوحدة | PascalCase classes |
| `src/Shared/Authorization` | policy facade/adapters | HTML أو SQL عشوائي خارج repositories | `*Policy`, `AuthorizationService` |
| `src/Shared/Database` | connection/transaction/schema read inspection | DDL runtime | `Pdo*`, `TransactionManager` |
| `src/Shared/Validation` | قواعد عامة قابلة لإعادة الاستخدام | قواعد grade/staff/student الخاصة | `*Rule`, `ValidationException` |
| `src/Modules/*/Application` | use cases/transactions/audit orchestration | superglobals/HTML | فعل + اسم use case |
| `src/Modules/*/Domain` | values/policies/validators | PDO/session/filesystem | أسماء مجال واضحة |
| `src/Modules/*/Infrastructure` | PDO/API/files implementations | presentation | `Pdo*Repository`, `*Gateway` |
| `views/` | escaping/rendering | DB writes/queries | حسب role/feature |
| `database/migrations` | schema changes guarded | request rendering | timestamp + description |
| `storage/private` | محتوى حساس | PHP executable | opaque generated names |
| `tools/` | commands CLI موثقة | HTTP entrypoints | فعل واضح و`--dry-run` افتراضيًا |
| `tests/` | deterministic test code | production data assumptions | `*Test.php` أو runner convention |

## 4. اتجاهات الاعتماد

- role entrypoints → `src/Shared` و`src/Modules/*/Application`.
- Application → Domain + repository interfaces/shared contracts.
- Infrastructure → interfaces + PDO/external libraries.
- Domain → لا يعتمد على Infrastructure أو Presentation.
- View → ViewModel/escaped primitives فقط.
- وحدة إلى وحدة → Application contract، لا include لصفحة ولا SQL على internals دون قرار موثق.

## 5. خريطة القديم إلى الجديد

| الحالي | الهدف | طريقة الانتقال |
|---|---|---|
| `classes/utilities.php` | عدة مكونات Shared | wrappers تبقى حتى انتقال المستهلكين |
| `classes/user.php` | Identity + Students + Staff repositories/services | استخراج method groups دون نقل class دفعة واحدة |
| `classes/classroom.php` | AcademicStructure | adapter يحافظ على `ClassRoom` |
| `classes/AssessmentEngine.php` | AssessmentReporting Application/Domain/Infrastructure | تقسيم داخلي بعد اختبارات، مع facade باسم قديم |
| `includes/session_config.php` | Shared/Auth bootstrap adapter | يبقى include العام ويستدعي implementation جديدًا |
| `includes/csrf.php` | Shared/Http/Csrf | حفظ الدوال الحالية كwrappers |
| `includes/ajax_handlers.php` | endpoint adapters حسب الوحدة | نقل action واحد في كل دفعة |
| SQL داخل role pages | module repositories/query services | extraction use case by use case |
| HTML داخل role pages | `views/<role>/<feature>` | include template بعد تثبيت variables |
| inline JS/CSS | `assets/js/modules`, `assets/css` | استخراج دون تغيير DOM IDs أولًا |
| public attachments | `storage/private` + download endpoint | dual-read ومهاجر قابل للتراجع |
| runtime DDL | migrations | migration guarded ثم حذف ensure code |
| `scratch/`, `tmp/` | خارج docroot أو archive المحمي | إثبات عدم الاستخدام قبل الحذف |

## 6. ملفات لا تُنقل بثقة منخفضة

- أي ملف في `archive/` أو `scratch/` قبل link/include/runtime audit.
- `teacher/lesson_prep.php` قبل characterization tests.
- `admin/students.php` و`admin/staff.php` أثناء وجود تغييرات غير ملتزم بها.
- SSO endpoints قبل test tenant/staging.
- report/grade legacy redirects قبل matrix links كاملة.

## 7. Composer أثناء الانتقال

إضافة مستقبلية مقترحة، بعد قبول المرحلة:

```json
{
  "autoload": {
    "classmap": ["classes/"],
    "psr-4": {"EduCore\\": "src/"}
  }
}
```

لا يُحذف classmap حتى تصبح كل الفئات القديمة غير مستخدمة ومثبتة باختبارات.
