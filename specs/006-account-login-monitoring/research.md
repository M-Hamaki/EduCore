# Research: مراقبة الدخول وأمان الحسابات

**Date**: 2026-08-14

## Confirmed Current-State Evidence

- `users` لا يملك `last_login_at` في المخطط المجمع؛ لديه حالة الحساب وحقول تعطيل الدخول فقط (`database_complete.sql`).
- `AccountListDataTableQuery::loadStudents()` و`loadStaff()` لا يختاران أو يعرضان آخر دخول.
- `login.php` يستدعي حالياً `Utilities::logAction('login', ...)` ثم `ActivityLog::logLogin(...)` للنجاح نفسه، فينتج تكرار داخل الطلب.
- Microsoft يسجل `microsoft_login` داخل `MicrosoftSSO`, ويضيف callback اسماً آخر `microsoft_sso_login`; مسارات Teams لا تمر دائماً بنفس الإضافة.
- `activity_logs` يحتوي هوية الفاعل/الهدف والوقت وIP وrequest metadata، لكنه سجل عام طويل العمر وليس read model مناسباً لقوائم الحسابات أو محاولات username غير موجود.
- `last_activity` موجود في session فقط، ويحدث في `includes/session_config.php` و`Utilities::validateSession()`؛ لا يبقى بعد انتهاء الجلسة ولا يثبت خروج المستخدم.
- `logout.php` يسجل الخروج اليدوي، بينما idle timeout يدمر الجلسة دون حدث خروج معياري.
- `PasswordAuthenticator` و`User::login()` يدعمان hash upgrade تدريجياً، لكن صفحات الحسابات ما زالت تميز كلمات قابلة للكشف وتوفر كشفاً/تصديراً توافقياً.
- `StudentLoginAccessPolicy` هو القرار المشترك لحالة الطالب، وADR-075 يمنع كشف سبب خاص قبل إثبات الاعتماد/هوية Microsoft.
- ADR-076 يقرر أن فشل ربط Microsoft أو التدقيق يمسح الجلسة الجزئية ويفشل الدخول مغلقاً.

## Decision 1 — Dedicated security evidence plus a fast projection

**Decision**: استخدام مخزن أحداث مصادقة append-only وإسقاط `authentication_user_state`، بدلاً من إضافة subquery على `activity_logs` في كل تحميل أو الاكتفاء بحقل `users.last_login_at`.

**Rationale**:

- event store يحفظ النجاح والفشل والمنع والطريقة والسبب والجلسة مع سياسة احتفاظ مستقلة.
- projection يقدم join بمفتاح المستخدم لقوائم DataTables ويمنع مسح سجل كبير.
- محاولة اسم غير موجود لا تملك `user_id` صالحاً لـ`activity_logs`, وتحتاج subject pseudonym لا مستخدماً وهمياً.
- state فقط لا يكفي للتحقيق أو throttle، وactivity log فقط لا يناسب retention/performance.

**Alternatives considered**:

- `users.last_login_at` فقط: بسيط لكنه يفقد التاريخ والفشل والطريقة والجلسات، ويخلط telemetry بصف المستخدم.
- `MAX(activity_logs.created_at)` في كل قائمة: أسرع في التطوير لكنه غير موحد الأسماء، لا يحل التكرار، ويزداد بطئاً مع النمو.
- استعمال `activity_logs` وحده لكل الأحداث: يخلط retention الأمني بالتدقيق العام ولا يمثل مجهول المستخدم بأمان.

## Decision 2 — One shared audit entrypoint, not a parallel logger

**Decision**: إضافة عقد مصادقة إلى `AuditService`; كل entrypoint يمرر outcome للخدمة، ولا يكتب إلى event/state مباشرة. `authentication_events` مورد مسجل في `AuditPolicyRegistry`، والحدث المعروف المستخدم ينتج صفاً عاماً واحداً في `activity_logs` بالـrequest ID نفسه.

**Rationale**: يلتزم عقد التدقيق الإلزامي ويزيل page-local logging والتكرار. يحتفظ النظام بسجل عام قابل للبحث وبسجل أمني ذي retention مناسب من نقطة كتابة واحدة.

**Alternatives considered**:

- logger جديد داخل `login.php`: مرفوض لأنه يكرر المعمارية.
- direct repository calls من كل route: مرفوض لأنها تنتج اختلاف أسماء ومعاملات.
- صفان متطابقان في `activity_logs`: مرفوض؛ التكرار الحالي أحد أسباب الخطة.

## Decision 3 — Canonical outcomes and reason codes

**Decision**: تخزين `method`, `outcome`, و`reason_code` من قوائم مغلقة، مع `request_id` وidempotency key، وعدم تخزين النص المعروض كحقيقة أمنية.

**Rationale**: النصوص تتغير والترجمة لا تصلح للتجميع. reason codes تسمح بعقود ثابتة وتنبيه بلا تسريب. OWASP يوصي باتساق application logging وتسجيل نجاح وفشل المصادقة.

**Primary source**: [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html).

**Alternatives considered**:

- تخزين رسائل exceptions أو رسائل عربية حرة: مرفوض لتسريب معلومات وعدم استقرار التجميع.
- action name مختلف لكل route: مرفوض لأنه سبب النقص الحالي.

## Decision 4 — Successful login fails closed if mandatory evidence fails

**Decision**: لا تصبح الجلسة قابلة للاستخدام إذا فشل حفظ outcome/state/session intent، وتُمسح أي جلسة جزئية. الفشل نفسه يعرض رسالة عامة.

**Rationale**: يتسق مع ADR-076 وFuture Write/Audit contract. استمرار نجاح لا يمكن إثباته يكسر آخر دخول والتحقيق والثقة في النظام.

**Alternatives considered**:

- السماح بالدخول مع `error_log` فقط: يحسن availability لكنه يجعل السجل غير موثوق ويخالف قرار SSO الحالي.
- تسجيل async غير مضمون: لا توجد outbox/auth infrastructure مؤكدة، وقد تضيع الأحداث الحرجة.

## Decision 5 — Progressive throttling without automatic permanent lock

**Decision**: account/source counters، نافذة 15 دقيقة، عتبة أولى 5 إخفاقات، backoff يبدأ 30 ثانية ويتضاعف حتى 15 دقيقة، وتنبيه بعد 20؛ لا permanent lock آلي. تظل القيم قابلة للضبط وتبدأ observe-only.

**Rationale**:

- NIST يطلب rate limiting للمحاولات الفاشلة ويعتبر الحد 100 سقفاً لا هدفاً.
- OWASP يوصي بربط عداد الحساب بالحساب، ويحذر من trade-off lockout/denial-of-service.
- progressive delay يقلل التخمين ويتيح التعافي التلقائي للمستخدم الشرعي.

**Primary sources**:

- [NIST SP 800-63B, Authentication and Lifecycle Management](https://pages.nist.gov/800-63-4/sp800-63b.html).
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).

**Alternatives considered**:

- قفل دائم بعد خمس محاولات: مرفوض لأنه يسمح لمهاجم بعمل DoS لحساب معروف.
- IP-only limit: مرفوض لأنه يتجاوزه توزيع المصادر ويضر شبكات المدرسة المشتركة.
- CAPTCHA من أول إصدار: مؤجل لعدم وجود dependency حالية ولأنه يضيف تعقيد UX/خصوصية.

## Decision 6 — Generic errors until identity is proven

**Decision**: username غير موجود وكلمة مرور خاطئة يعيدان response متقارباً ورسالة واحدة. سبب التعطيل الخاص يظهر فقط بعد إثبات الاعتماد/هوية Microsoft كما يقرر ADR-075.

**Rationale**: يمنع account enumeration ويحافظ على سياسة الطالب المعتمدة.

**Primary source**: [OWASP Authentication Cheat Sheet — Authentication Responses](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).

**Alternatives considered**:

- رسالة «المستخدم غير موجود»: مرفوضة لتسريب الحسابات.
- إخفاء سبب التعطيل حتى بعد كلمة صحيحة: لا يحافظ على السلوك المعتمد للمستخدم الحقيقي.

## Decision 7 — Session registry stores only a pseudonymous session key

**Decision**: الاحتفاظ بـHMAC/hash لمعرف الجلسة، لا session ID أو cookie خام. يسجل start/last_seen/end/revoke، وتبقى جلسات PHP آلية التنفيذ الحالية.

**Rationale**: يسمح بالجرد والإلغاء دون تحويل قاعدة البيانات إلى مخزن session secrets. إدارة الجلسة مرتبطة بالمصادقة والتحكم في الوصول، لكن لا حاجة لإعادة بناء session handler.

**Primary source**: [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).

**Alternatives considered**:

- تخزين session ID خام: مرفوض لأنه bearer secret.
- استبدال PHP session handler بقاعدة البيانات: خارج النطاق وعالي المخاطر.
- اعتبار browser close logout: مرفوض لأنه غير قابل للإثبات من الخادم.

## Decision 8 — `unknown_historical` is distinct from `never`

**Decision**: كل حساب موجود عند cutover يبدأ `unknown_historical` ما لم يُستخرج نجاح موثوق. الحساب المنشأ بعد cutover يبدأ `never`. لا يُستنتج التاريخ من غياب log قد يكون حُذف.

**Rationale**: غياب الدليل ليس دليلاً على عدم الدخول. كما أن `users` لا يملك تاريخ إنشاء مؤكداً في baseline، فلا يمكن تصنيف القديم بأمان.

**Alternatives considered**:

- عرض كل NULL كـ«لم يدخل»: مرفوض لأنه يقدم معلومة غير صحيحة.
- backfill counts بتجميع أحداث متقاربة زمنياً: مرفوض لأنه heuristic غير قابل للدفاع.

## Decision 9 — UTC internally, Cairo time for display

**Decision**: timestamps stored/compared in UTC، والواجهة تعرض التاريخ الدقيق في timezone المدرسة مع relative text مساعد.

**Rationale**: يمنع التباس daylight saving واختلاف web/DB server. relative text وحده لا يكفي للتحقيق.

**Alternatives considered**:

- تخزين local server time بلا timezone: مرفوض لصعوبة المقارنة والترحيل.
- relative-only: مرفوض للتدقيق والحوادث.

## Decision 10 — Hash-only credential destination and no periodic forced rotation

**Decision**: الاعتمادات الجديدة وإعادات التعيين hash-only، مع temporary password displayed once و`must_change_password`. لا تغيير دوري إلا compromise أو اعتماد مؤقت. legacy reveal/export يُنهى خلف flag بعد اكتمال التحويل.

**Rationale**:

- OWASP يوصي بالتجزئة التكيفية بدلاً من التشفير/النص، وArgon2id حيث يتوفر مع fallback legacy مدروس.
- NIST يوصي salted password hashing، ولا يوصي بتغيير دوري اعتباطي، ويطلب rate limiting.
- النظام يملك `PasswordAuthenticator` وhash upgrade، لذا المسار incremental لا rewrite.

**Primary sources**:

- [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html).
- [NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html).
- [OWASP Forgot Password Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

**Alternatives considered**:

- الاحتفاظ بكلمات قابلة للكشف دائماً: مرفوض لأثر الاختراق والتصدير.
- حذف ciphertext فوراً: مرفوض لأنه يكسر حسابات لم تتحول ويمنع rollback.
- تغيير كل 30/60/90 يوماً: مرفوض دون دليل compromise ويزيد السلوكيات الضعيفة.

## Decision 11 — Privacy-tiered retention

**Decision**: تفاصيل IP/device مقيدة، تعرض masked، تُنقح بعد 30 يوماً، وتحذف الأحداث بعد 180 يوماً افتراضياً. state summary يبقى بلا بيانات شبكة. أداة retention CLI محروسة ومدققة.

**Rationale**: تحقق التوازن بين التحقيق وتقليل البيانات. OWASP يوصي بتحديد logging/monitoring خلال التصميم بما يتناسب مع الخطر وليس logging بلا حدود.

**Primary source**: [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html).

**Alternatives considered**:

- احتفاظ دائم بكل IP/User-Agent: مرفوض للخصوصية والحجم.
- حذف كل التفاصيل فوراً: يزيل قيمة التحقيق في account takeover.
- تخزين User-Agent الخام: مرفوض؛ يكفي hash + coarse summary.

## Decision 12 — Feature flags and staged enforcement

**Decision**: observability → shadow comparison → UI → throttle observe → throttle enforce → sessions → credential hardening، بأعلام مستقلة.

**Rationale**: يقلل blast radius ويحافظ على rollback متوافق. التغيير يمس auth/session/schema، وهي مناطق محمية في `AGENTS.md`.

**Alternatives considered**:

- big-bang rollout: مرفوض لصعوبة فصل فشل schema/logging/throttle/session/UI.
- UI أولاً من logs الحالية: مرفوض لأنه يثبت بيانات غير موحدة كعقد جديد.

## Resolved Unknowns and Remaining Production Gates

### Resolved by default

- الصلاحية الحساسة: `super_admin` فقط في الإصدار الأول.
- retention: 30 يوم للتفاصيل الكاملة/180 يوم للأحداث، قابل للضبط.
- throttle: progressive temporary backoff، لا permanent lock.
- password rotation: temporary/compromise فقط، لا periodic.
- external notifications وMFA: خارج النطاق.

### Must be confirmed before implementation/production, not design clarifications

- طوبولوجيا الإنتاج، عدد web nodes، ومكان حفظ PHP sessions.
- trusted proxy configuration ومصدر client IP الصحيح.
- الحجم الفعلي لـ`users` و`activity_logs` واختيار batch sizes.
- مالك اعتماد retention وحق الاطلاع على الشبكة داخل المدرسة.
- حالة migrations الفعلية على البيئة المستهدفة.
- ملكية التغييرات الحالية المتداخلة في ملفات auth/accounts.
