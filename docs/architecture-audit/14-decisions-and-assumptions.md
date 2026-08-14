# القرارات والافتراضات

## قرارات مثبتة في هذا التدقيق

| ID | القرار | السبب | الحالة |
|---|---|---|---|
| DA-001 | لا rewrite ولا framework migration | stack كافٍ والتحسين التدريجي أقل خطرًا | معتمد |
| DA-002 | Modular Monolith هو الهدف | وحدات أعمال حقيقية ونشر واحد وقاعدة مشتركة | معتمد |
| DA-003 | URLs الحالية adapters خلال الانتقال | حماية التوافق وتقليل blast radius | معتمد |
| DA-004 | PDO يبقى؛ repositories دون ORM إلزامي | تقليل التعقيد والاستفادة من الموجود | معتمد |
| DA-005 | migrations وحدها تغيّر schema | runtime DDL خطر تشغيل واتساق | معتمد |
| DA-006 | `AGENTS.md` المصدر التعليمي النهائي | هو المصدر الجذري الموجود والمعلن | معتمد بعد تحديث المرحلة 3 |
| DA-007 | الملفات المتسخة السابقة لا تدخل commits المعمارية | حماية عمل المستخدم | معتمد |
| DA-008 | DB-writing tests لا تعمل على `educore` في التدقيق | لا production data modification | معتمد |
| DA-009 | حماية HTTP تسبق حذف/نقل الملفات القديمة | عكوسة ولا تحتاج إثبات عدم الاستخدام للحذف | معتمد |
| DA-010 | الدليل الحالي يعلو على الوثيقة التاريخية | مثال password auth يثبت ضرورة ذلك | معتمد |

## حقائق مؤكدة

- PHP 8.2.12 CLI محليًا؛ composer يطلب PHP >=8.0.
- قاعدة البيانات `educore` عبر PDO.
- لا framework أو router مركزي مؤكد.
- session/CSRF/auth helpers موجودة.
- الفرع `main` والشجرة كانت dirty قبل إنشاء audit docs.
- `classes/`, `config/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, و`storage/` محمية محليًا بـ`.htaccess` ومثبتة باختبار ساكن/HTTP؛ إعداد production ما زال غير مؤكد.
- assessment mark write يستخدم transaction وscope/audit جيدًا.
- direct links لمرفقات الطلاب والعاملين موجودة.

## افتراضات عمل مؤقتة

| ID | الافتراض | الثقة | كيف يُثبت/ينفى | أثر الخطأ |
|---|---|---|---|---|
| AS-001 | Apache يخدم جذر EduCore مباشرة في local/prod مشابه | Medium | vhost config وHTTP probes | شدة SEC-003 تتغير، لا الحاجة لحماية دفاعية |
| AS-002 | AllowOverride يسمح `.htaccess` خارج archive محليًا | High محليًا / غير مؤكد production | نجحت HTTP probes محليًا؛ يلزم staging/vhost production | قد نحتاج vhost/Nginx rules بدل الملفات في production |
| AS-003 | production topology يشبه README/XAMPP | Low-Medium | deployment config | target docroot plan يتغير |
| AS-004 | `staff_role_pages` هو المصدر الكامل لـadmin-like page access | High | schema/callers audit | authorization facade scope يتغير |
| AS-005 | لا consumer داخلي معروف يعتمد على exception text من UndoManager | High للمصدر / غير مؤكد خارجيًا | اكتمل source-consumer review واختبار العقد؛ runtime integration يحتاج DB معزولة | consumer خارجي غير موثق قد يحتاج compatibility handling |
| AS-006 | لا external route يعتمد على `scratch/students_head.php` | Medium | access logs/link/include search | لا يجوز حذف قبل الإثبات |

## معلومات غير مؤكدة بعد

- topology وweb server production.
- كل endpoints/actions وعقودها.
- كل علاقات FK وtable ownership.
- كل dynamic includes/callers للملفات القديمة.
- معدل وحجم الوصول الفعلي للمرفقات.
- سياسة business النهائية لإظهار كلمات المرور للأدمن وعلاقتها بالمصادقة hash-first.

## تعارضات وثائقية اكتُشفت وحالتها

1. ادعاء hash-first في README و`docs/PASSWORD_SECURITY.md`: **صُحح توثيقيًا**؛ كلاهما يصف الآن المقارنة المباشرة، بينما إصلاح المصادقة نفسه مؤجل.
2. اختلاف PHP 7.4/8.0: **مغلق**؛ `AGENTS.md`, README وComposer متسقة على PHP 8.0+، والـCLI المحلي 8.2.12.
3. دستور Spec Kit placeholder: **مغلق**؛ أصبح v1.0.0 مصدقًا ويصرح بأولوية `AGENTS.md`.
4. `docs/ENGINEERING_AUDIT_REPORT.md` تاريخي وبعض pending items تغيرت: **مفتوح كوثيقة تاريخية**؛ لا يستخدم كحقيقة حالية بلا إعادة تحقق.

## قرارات مؤجلة

- namespace/PSR-4 naming التفصيلي لكل وحدة.
- نقل document root إلى `public/`.
- شكل authorization interfaces النهائي.
- فصل staff finance إلى جدول مستقل.
- retention/encryption policy للمرفقات.
- توحيد audit stores.
