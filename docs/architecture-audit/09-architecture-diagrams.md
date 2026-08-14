# الرسومات المعمارية

## 1. سياق النظام الحالي

```mermaid
flowchart LR
    Admin["مسؤول / دور إداري"] --> Web["EduCore PHP على Apache/XAMPP"]
    Teacher["معلم / مشرف"] --> Web
    Student["طالب"] --> Web
    Specialist["أخصائي"] --> Web
    External["معلم خارجي"] --> Web
    Web --> DB[("MySQL / MariaDB: educore")]
    Web --> Microsoft["Microsoft Entra / Teams SSO"]
    Web --> AI["Gemini / Ollama / خدمات محتوى"]
    Web --> Push["Web Push"]
    Web --> Files["uploads/ وstorage/"]
```

## 2. مكونات التطبيق الحالي

```mermaid
flowchart TB
    Browser["Browser"] --> Routes["ملفات PHP مباشرة حسب الدور"]
    Routes --> Includes["includes/: session, CSRF, headers, helpers"]
    Routes --> Classes["classes/: models + services + utilities"]
    Routes --> PDO["PDO مباشر داخل صفحات كثيرة"]
    Includes --> Config["config/: env, DB, encryption, SSO"]
    Classes --> Config
    Classes --> PDO
    PDO --> DB[("MySQL")]
    Routes --> Assets["assets/ + inline CSS/JS"]
    Routes --> Uploads["uploads/"]
```

## 3. دورة الطلب الحالية

```mermaid
sequenceDiagram
    participant B as Browser
    participant P as PHP page/endpoint
    participant S as Session/Utilities
    participant D as PDO/DB
    participant V as HTML or JSON
    B->>P: GET أو POST مباشر إلى ملف
    P->>S: include session + validateSession
    alt مسار يطبق CSRF
        P->>S: requireCsrfPost أو تحقق يدوي
    else مسار قديم
        Note over P,S: قد لا يوجد تحقق server-side متسق
    end
    P->>D: SQL مباشر أو عبر class/service
    D-->>P: بيانات أو نتيجة كتابة
    alt PRG
        P-->>B: Redirect + session flash
    else JSON
        P-->>B: JSON خاص بالendpoint
    else HTML
        P->>V: include header/render/footer
        V-->>B: HTML + scripts
    end
```

## 4. اعتماديات الوحدات الحالية

```mermaid
flowchart LR
    Identity["Identity / Access"] --> Shared["Utilities + Session + DB"]
    Students["Students"] --> Identity
    Students --> Academic["Academic Structure"]
    Staff["Staff / HR"] --> Identity
    Assessment["Assessment / Reports"] --> Identity
    Assessment --> Academic
    Assessment --> Students
    Attendance["Attendance"] --> Identity
    Attendance --> Students
    Finance["Finance"] --> Identity
    Finance --> Students
    Finance --> Staff
    Transport["Transport"] --> Students
    Learning["Learning / AI"] --> Identity
    Learning --> Academic
    Notifications["Notifications"] --> Identity
    Notifications --> Academic
    Operations["Audit / Undo / Backup"] --> Shared
    Shared --> DB[("Shared PDO database")]
```

الأسهم تمثل قراءة/اعتماد فعليًا شائعًا، ولا تدعي ملكية حصرية لكل جدول.

## 5. تدفق المصادقة والتفويض الحالي

```mermaid
flowchart TD
    Request["طلب"] --> Session["session_config.php"]
    Session --> Logged{"user_id موجود؟"}
    Logged -- لا --> Login["index.php / login.php"]
    Logged -- نعم --> Role["Utilities::validateSession(role)"]
    Role --> Basic{"الدور مطابق؟"}
    Basic -- نعم --> Allow["السماح"]
    Basic -- لا --> Effective["Supervisor effective mode"]
    Effective --> Custom["Custom admin role + staff_role_pages"]
    Custom --> Domain["صلاحيات مجال إضافية عند الحاجة"]
    Domain --> AssessmentPerm["assessment_permissions مثالًا"]
    AssessmentPerm --> Allow
    Custom --> Deny["Redirect / منع"]
```

## 6. المعمارية المستهدفة

```mermaid
flowchart TB
    Entry["URL adapters الحالية"] --> Http["Shared HTTP/Auth/CSRF/Error"]
    Http --> App["Module Application Service"]
    App --> Domain["Domain Policy + Validator"]
    App --> Repo["Repository interface"]
    Infra["PDO / Files / External API adapters"] --> Repo
    Infra --> DB[("MySQL")]
    App --> Audit["Audit contract"]
    Entry --> View["View / JSON responder"]
    App --> ViewModel["ViewModel / Result DTO"]
    ViewModel --> View
    Shared["Shared Kernel صغير"] --> Http
    Shared --> App
    Shared --> Infra
```

## 7. دورة الطلب المستهدفة

```mermaid
sequenceDiagram
    participant B as Browser
    participant E as Entrypoint adapter
    participant H as HTTP/Auth layer
    participant A as Application Service
    participant R as Repository
    participant D as Database
    participant O as Audit/Error responder
    B->>E: الطلب بنفس URL والعقد الحالي
    E->>H: authenticate + authorize + CSRF
    H->>A: validated DTO + UserContext
    A->>R: use-case operations
    R->>D: prepared SQL داخل transaction عند الحاجة
    D-->>R: result
    R-->>A: entities/result
    A->>O: audit event + public result
    O-->>E: safe response/redirect
    E-->>B: HTML أو JSON متوافق
```

## 8. قواعد اعتماد الوحدات المستهدفة

```mermaid
flowchart LR
    Presentation["Presentation / Entrypoints"] --> Application["Application"]
    Application --> Domain["Domain"]
    Application --> Contracts["Repository and Shared contracts"]
    Infrastructure["Infrastructure"] --> Contracts
    Infrastructure --> External["PDO / Files / APIs"]
    Presentation --> SharedHttp["Shared HTTP"]
    Domain -. ممنوع .-> Infrastructure
    Domain -. ممنوع .-> Presentation
    Infrastructure -. ممنوع .-> Presentation
    View["Views"] -. ممنوع .-> External
```

## 9. مراحل الانتقال

```mermaid
flowchart LR
    P0["0 ✓ خط أساس ووثائق"] --> P1["1 ✓ حماية حدود الويب"]
    P1 --> P2["2 ✓ فاحص معماري صارم"]
    P2 --> P3["3 ✓ تعليمات ودستور دائم"]
    P3 --> P4["4 ✓ CSRF للفصول"]
    P4 --> P5["5 ✓ أخطاء Undo عامة"]
    P5 --> P12["12 ✓ تحقق وإغلاق الدفعات الآمنة"]
    P12 --> D["مؤجل: Auth / Finance / Schema / Validators / God pages / Attachments"]
    D --> F["تنفيذ مستقبلي: workflow واحد + staging + rollback"]
```

## تحقق الصياغة

كل رسم يستخدم أسماء مكونات مثبتة أو أسماء مستهدفة معرفة صراحة في وثيقة المعمارية المستهدفة. العلاقات غير المؤكدة لم تُعرض كحقائق تفصيلية.

أعيدت مراجعة الرسومات في 2026-07-13. لم تتغير المكونات أو نقاط الدخول لأن الدفعات الآمنة لم تنقل ملفات؛ حُدث رسم المراحل وحده لتمييز المنفذ فعليًا عن العمل المؤجل.
