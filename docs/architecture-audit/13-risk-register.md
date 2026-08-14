# سجل المخاطر

| ID | الخطر | الاحتمال | الأثر | المؤشر/Trigger | التخفيف الحالي | الإجراء المطلوب | المالك المقترح | الحالة |
|---|---|---|---|---|---|---|---|---|
| R-001 | بقاء legacy fallback وغلاف reveal قابل للفك | متوسط | عالٍ | حسابات نشطة بلا hash أو تسرب مفتاح التشفير | hash-first ملزم عند وجوده، ترقية عند الدخول، flag للإغلاق، اختبارات معزولة | قياس cutover ثم تعطيل fallback؛ قرار إلغاء reveal | Security/Auth | مخفف جزئيًا |
| R-002 | direct HTTP لأدوات داخل docroot | منخفض للمجلدات المحمية، غير محسوم لـ`vendor/phpmyadmin` | حرج | طلب مباشر إلى source/tool | `classes`, `config`, `database`, `tools`, `tests`, `scratch`, `tmp`, `storage` محمية وmigration runner له CLI guard | تحقق نشر production ومعالجة `vendor/phpmyadmin` على مستوى document root/vhost | DevOps/Security | مخفف جزئيًا |
| R-003 | مرفقات PII عامة بالرابط | منخفض | عالٍ | URL leak/guess/log/referrer | تخزين خاص وتنزيل مصرح وchecksum migration ومنع HTTP للمصادر القديمة | مراقبة access logs بعد النشر | Security/Students/HR | مغلق تنفيذيًا |
| R-004 | CSRF غير متسق | منخفض | عالٍ | state change من origin خارجي | الحارس الصارم يعرض صفر مرشح غير مراجع، واختبارات العقود تمنع الانحدار | إبقاء الفاحص في بوابة التغيير | Web Security | مغلق / مراقبة |
| R-005 | runtime DDL | منخفض | عالٍ | lock/implicit commit/permission failure | كل schema changes في migrations والحارس يعرض صفر ملف runtime DDL | تشغيل migrations بإجراءات النشر | Database | مغلق تنفيذيًا |
| R-006 | God pages تكسر تغييرات غير مرتبطة | منخفض-متوسط | عالٍ | تعديل صغير بdiff ضخم | الملفات الخمسة ذات الأولوية قُسمت، والحارس يعرض صفر ملف فوق 2000 سطر | استمرار الاستخراج حسب الحاجة | Module owners | مخفف جوهريًا |
| R-007 | RBAC models تتباعد | منخفض | عالٍ | دور يصل لصفحة/فصل خاطئ | `AuthorizationFacade` ومصفوفة أدوار واختبارات صلاحيات | توسيع المصفوفة مع أي policy جديدة | Security/Product | مغلق مرحليًا |
| R-008 | partial finance/profile writes | منخفض | متوسط-عالٍ | insert ينجح وupdate/log يفشل | معاملات مشتركة للمالية والملفات والأدوار والحضور وUndo واختبارات rollback | المحافظة على نفس PDO داخل use case | Finance/HR | مغلق للنطاق |
| R-009 | exception details leak | منخفض | متوسط | PDO failure يظهر في JSON/flash | `SafeErrorPolicy` ورسائل عامة ثابتة وتسجيل server-side واختبارات فشل | توسيع السياسة مع endpoints الجديدة | Shared Platform | مغلق مرحليًا |
| R-010 | docs drift يوجه AI خطأ | متوسط بعد المرحلة 3 | عالٍ | README/AGENTS/constitution يناقض التنفيذ | حوكمة معمارية في `AGENTS.md`، وثائق مركزة، دستور Spec Kit v1.0.0، قوالب متزامنة، واختبار عقد ثابت | ربط اختبارات الوثائق والفاحص بـCI ومراجعة drift مع تغييرات الحدود | Maintainers | مخفف جوهريًا / مراقبة |
| R-011 | الاختبارات تلمس DB حقيقية | منخفض | عالٍ | تشغيل integration محليًا على `educore` | guard يرفض قاعدة الإنتاج ويشترط `APP_ENV=test` و`EDUCORE_TEST_DB_NAME` | إبقاء guard إلزاميًا | QA/Database | مغلق / مراقبة |
| R-012 | ثغرات اعتماديات Composer تظهر بعد لقطة التدقيق | منخفض في لقطة 2026-07-13 / متغير زمنيًا | عالٍ | advisory جديد أو فشل audit | `composer security-audit` اكتمل بلا advisories في الإغلاق | تكرار الفحص في CI/شبكة موثوقة وعدم اعتبار اللقطة ضمانًا دائمًا | DevOps | مراقبة |
| R-013 | scratch/tmp تكشف بيانات أو تنحرف | متوسط | عالٍ | developer يعتمد نسخة غير تشغيلية أو تختلف سياسة النشر | `scratch/` و`tmp/` محميان من HTTP ومثبتان باختبار حدود | إثبات الاستخدام ثم أرشفة/حذف النسخ غير المستخدمة والتحقق من سياسة النشر | Maintainers | مخفف جزئيًا |
| R-014 | نقل docroot يكسر URLs | متوسط | عالٍ | route/static path failure | لا نقل حاليًا | staging rewrite map | DevOps | مؤجل |
| R-015 | refactor يتداخل مع dirty worktree | منخفض حاليًا | عالٍ | نفس الملف في `git status` | commits مركزة ومراجعة scoped وحالة عمل نظيفة | تكرار فحص status قبل كل دفعة | جميع المطورين | مغلق حاليًا |
| R-016 | توسيع baseline لإخفاء انحدار أو الثقة الزائدة في فحوص heuristic | متوسط | عالٍ | إضافة finding جديد للbaseline دون إصلاح، أو اعتبار candidate حكمًا أمنيًا | strict + اختبار regression + وسم CSRF كمرشح | مراجعة منفصلة لأي توسيع، وتحديث detector/ADR عند blind spot مثبت | Maintainers/Security | مفتوح |

## سلم الاستجابة

- **حرج:** لا تنفيذ متعلق قبل mitigation/test/staging.
- **عالٍ:** مرحلة مستقلة وrollback واختبارات roles/data.
- **متوسط:** ينفذ مع module work أو quick win مثبت.
- **منخفض:** يسجل ولا يوسع النطاق تلقائيًا.

## مخاطر مقبولة مؤقتًا

- بقاء role entrypoints وlegacy `classes/` أثناء strangler migration.
- وجود direct SQL في الصفحات غير المعدلة حتى تصلها مرحلة وحدة مخططة.
- بقاء uploads legacy قابلة للقراءة خلال dual-read فقط، بعد بدء مسار الحماية المعتمد.

قبول الخطر مؤقتًا لا يعني اعتباره نمطًا مسموحًا للكود الجديد.
