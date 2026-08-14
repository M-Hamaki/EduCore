# Data Model: مراقبة الدخول وأمان الحسابات

**Status**: تصميم Phase 1؛ أسماء migrations النهائية قد تتغير، لكن الملكية والعلاقات والقيود جزء من العقد.

## 1. `authentication_events`

سجل أمني append-only للنتيجة النهائية لمحاولة مصادقة أو حدث جلسة/اعتماد. يُكتب حصراً من خلال عقد `AuditService` المشترك.

### Fields

| Field | Meaning | Rules |
|---|---|---|
| `id` | معرف تسلسلي كبير | Primary key، لا يعاد استخدامه |
| `request_id` | معرف الطلب المدقق | 32 hex وفق `AuditContext`; مطلوب للأحداث HTTP |
| `idempotency_key` | منع replay/callback duplicates | nullable للأحداث غير القابلة للإعادة، unique عندما يوجد |
| `user_id` | حساب `users` المثبت | nullable لاسم غير موجود؛ FK `SET NULL` عند الحذف الفيزيائي |
| `subject_hash` | بصمة keyed للمعرف المدخل | nullable عند وجود `user_id`; لا تخزن username الخام |
| `event_name` | نوع الحدث | قائمة مغلقة: `login_attempt`, `session_started`, `role_selected`, `logout`, `session_expired`, `session_revoked`, `credential_changed`, `retention_run` |
| `outcome` | النتيجة | `success`, `failure`, `denied`, `error` |
| `method` | وسيلة المصادقة | `password`, `microsoft_interactive`, `microsoft_silent`, `teams_silent`, nullable لحدث لا يحتاج طريقة |
| `reason_code` | سبب معياري غير سري | قائمة مغلقة يملكها Accounts؛ لا exception text |
| `primary_role` | الدور الأساسي المثبت وقت الحدث | nullable؛ قيمة خادمية فقط |
| `active_role` | الدور المختار إن كان معلوماً | nullable؛ `role_selected` يحدث منفصلاً |
| `session_key_hash` | ربط مستعار بجلسة registry | nullable؛ HMAC/hash فقط، ليس session ID |
| `ip_address` | عنوان normalized للتحقيق | nullable؛ IPv4/IPv6، حساس ومحدود العرض/الاحتفاظ |
| `user_agent_hash` | بصمة User-Agent | nullable؛ لا يخزن النص الخام |
| `client_family` | ملخص متصفح/عميل | قائمة/نص محدود الطول من parser داخلي |
| `device_type` | نوع جهاز تقريبي | `desktop`, `mobile`, `tablet`, `unknown` |
| `occurred_at_utc` | وقت الحدث | UTC، يحدد من طبقة واحدة متسقة |
| `details` | JSON محدود | allow-list فقط؛ يمر على redaction ولا يحوي أسراراً |
| `redacted_at_utc` | وقت تنقيح الشبكة/الجهاز | nullable؛ يثبت مرحلة retention |
| `retention_batch_id` | batch الذي نقح/حذف ما يمكن إثباته | nullable؛ معرف تدقيق لا secret |
| `created_at_utc` | وقت إدراج الصف | UTC، immutable |

### Invariants

- يلزم واحد على الأقل من `user_id` أو `subject_hash` لأحداث محاولات الدخول.
- عند وجود `user_id` لا تُحفظ قيمة username/email في `details`.
- لا update/delete من application paths؛ الاستثناء الوحيد أداة retention المالكة التي تنقح حقولاً محددة ثم تحذف بعد المدة.
- `(request_id, event_name, outcome)` أو `idempotency_key` يمنع تكرار النتيجة النهائية؛ اختيار القيد النهائي يثبت باختبار MariaDB والتدفقات المعاد إرسالها.
- `reason_code` لا يحتوي رسالة قاعدة/شبكة/IdP أو نصاً كتبه مستخدم.
- بيانات الشبكة والجهاز تصبح NULL عند التنقيح، بينما تبقى الطريقة والنتيجة والوقت.

### Indexes

- `(user_id, occurred_at_utc DESC, id DESC)` لسجل الحساب.
- `(event_name, outcome, occurred_at_utc)` للرصد والاحتفاظ.
- `(subject_hash, occurred_at_utc)` لـsource/unknown throttle ضمن retention.
- `(request_id)` وunique idempotency index.
- `(session_key_hash, occurred_at_utc)` لأحداث الجلسة.
- `(occurred_at_utc, redacted_at_utc)` لمهمة الاحتفاظ.

## 2. `authentication_user_state`

إسقاط سريع صف واحد لكل `users.id`. ليس سجل تدقيق مستقلاً؛ يُحدثه مالك outcome في المعاملة نفسها ويُعاد بناؤه من الأحداث الموثوقة عند الحاجة.

### Fields

| Field | Meaning | Rules |
|---|---|---|
| `user_id` | هوية المستخدم | PK/FK إلى `users`؛ cascade policy يراجع مع سياسة الأرشفة |
| `history_status` | موثوقية التاريخ | `unknown_historical`, `never`, `observed` |
| `tracking_started_at_utc` | بداية ضمان التتبع | لا يتغير بعد الإنشاء |
| `last_success_at_utc` | آخر جلسة نجحت | nullable، monotonic |
| `previous_success_at_utc` | النجاح السابق | nullable؛ يحدّث فقط عند نجاح أحدث |
| `last_success_method` | طريقة آخر نجاح | nullable، من canonical methods |
| `last_failure_at_utc` | آخر فشل اعتماد | nullable، monotonic |
| `last_denied_at_utc` | آخر منع سياسة | nullable؛ منفصل عن invalid credentials |
| `consecutive_failures` | عداد throttle للحساب | >=0، يعاد بعد النجاح المناسب |
| `failure_window_started_at_utc` | بداية نافذة العداد | nullable |
| `throttle_until_utc` | انتهاء الانتظار | nullable؛ لا قفل دائم |
| `last_seen_at_utc` | آخر heartbeat تقريبي | nullable؛ update محدود |
| `active_session_count` | إسقاط مساعد | >=0، يعاد حسابه من sessions عند التعارض |
| `version` | optimistic concurrency | يزيد مع تحديثات الحالة الأمنية |
| `updated_at_utc` | آخر تحديث | UTC |

### State transitions

```text
existing at cutover -> unknown_historical
unknown_historical + trusted backfill success -> observed
new account -> never
never/unknown_historical + completed login success -> observed
observed + newer completed login success -> observed (last -> previous)
any + failure -> same history status; update failure counter/window
any + success -> same/observed; reset relevant failure counter
```

### Invariants

- لا يتحول `observed` إلى `never` أو `unknown_historical`.
- `last_success_at_utc >= previous_success_at_utc` عند وجودهما.
- event أقدم لا يخفض أي `last_*` timestamp؛ تستخدم مقارنة monotonic داخل transaction.
- `active_session_count` cache وليس أساس التفويض؛ القرار الفعلي من session rows.

### Indexes

- `(history_status, last_success_at_utc)` لفلاتر «لم يدخل/قديم».
- `(last_success_at_utc)` للفرز.
- `(throttle_until_utc)` للتشغيل والتنظيف.
- لا يحتاج join list إلا PK `user_id`.

## 3. `authentication_source_throttles`

حالة مؤقتة لحماية محاولات المصادر والمعرفات غير المحلولة دون إنشاء مستخدم وهمي.

### Fields

| Field | Meaning | Rules |
|---|---|---|
| `scope_key_hash` | HMAC لمفتاح النطاق | PK؛ يبنى من source prefix + subject hash/version |
| `scope_type` | نوع النطاق | `source`, `unknown_subject`, `source_subject` |
| `window_started_at_utc` | بداية النافذة | UTC |
| `failure_count` | عدد الإخفاقات | >=0 |
| `throttle_until_utc` | نهاية التأخير | nullable |
| `last_failure_at_utc` | آخر إخفاق | UTC |
| `expires_at_utc` | حذف الحالة | بعد نافذة قصيرة؛ ليست history |
| `version` | concurrency | optimistic update/row lock |

### Invariants

- لا IP أو username خام.
- صفوف هذا الجدول cache محدود العمر، بينما evidence في `authentication_events`.
- التحديث ذري تحت concurrency؛ لا lost increments.

## 4. `authentication_sessions`

Registry metadata لجلسات PHP، لا يخزن محتوى الجلسة أو bearer secret.

### Fields

| Field | Meaning | Rules |
|---|---|---|
| `id` | معرف registry داخلي | PK |
| `user_id` | المستخدم | FK إلى `users` |
| `session_key_hash` | HMAC لمعرف الجلسة | unique، لا raw ID |
| `method` | طريقة بدء الجلسة | canonical method |
| `primary_role` | الدور الأساسي وقت البدء | قيمة خادمية |
| `active_role` | آخر دور نشط | nullable؛ update عند اختيار/تغيير الدور |
| `started_at_utc` | وقت البدء | UTC |
| `last_seen_at_utc` | heartbeat تقريبي | UTC، update كل نافذة فقط |
| `expires_at_utc` | حد صلاحية متوقع | مشتق من session policy |
| `ended_at_utc` | وقت النهاية المثبت | nullable |
| `end_reason` | سبب النهاية | `logout`, `idle_timeout`, `revoked`, `account_disabled`, `credential_changed`, `session_init_failed`, `expired` |
| `revoked_at_utc` | وقت الإلغاء | nullable |
| `revoked_by` | منفذ الإلغاء | nullable FK إلى users |
| `revoke_reason` | reason code/نص إداري محدود | لا أسرار، طول محدود، redaction policy |
| `ip_address` | عنوان البدء | حساس، retention/عرض مقيد |
| `user_agent_hash` | بصمة الجهاز | nullable |
| `client_family` | ملخص العميل | محدود |
| `device_type` | نوع الجهاز | canonical |
| `adopted_legacy` | جلسة سبقت rollout | boolean |
| `version` | concurrency | يزيد على heartbeat/end/revoke |
| `created_at_utc` / `updated_at_utc` | metadata | UTC |

### State transitions

```text
pending -> active -> ended(logout|idle_timeout|expired)
pending -> ended(session_init_failed)
active -> revoked -> ended(revoked|account_disabled|credential_changed)
legacy PHP session -> active(adopted_legacy) -> normal lifecycle
```

### Invariants

- `session_key_hash` لا يعاد استخدامه.
- لا heartbeat بعد `ended_at_utc` أو `revoked_at_utc`.
- revocation idempotent؛ التكرار لا يغير أول actor/time.
- check access يستخدم hash الحالي ويفشل إذا row revoked؛ absence أثناء compatibility لا يفشل إلا بعد انتهاء adoption window وتفعيل enforcement.
- self-current-session revocation يحتاج explicit confirmation contract.

### Indexes

- `(user_id, ended_at_utc, last_seen_at_utc DESC)` للجلسات النشطة.
- unique `(session_key_hash)` للطلب الحالي.
- `(expires_at_utc, ended_at_utc)` للتنظيف.
- `(revoked_at_utc, ended_at_utc)` لإنهاء ملغى.

## 5. Credential lifecycle additions

الخيار الأول المفضل هو حقول additive على `users` لأنها مرتبطة مباشرة بالاعتماد الموجود، بشرط مراجعة audit diff/redaction. إذا أثبت migration audit أن ذلك يسبب coupling غير مقبول، تنقل إلى جدول `authentication_credentials_state` بصف واحد لكل user دون نقل hashes في هذه المرحلة.

### Proposed fields

| Field | Meaning | Rules |
|---|---|---|
| `password_changed_at` | آخر تغيير فعلي | nullable للحساب القديم غير المعروف |
| `must_change_password` | اعتماد مؤقت/compromise | boolean، default false |
| `credential_version` | إبطال جلسات مرتبطة باعتماد قديم | integer >=1، يزيد عند reset/change |
| `legacy_password_state` | حالة التحويل | `revealable`, `hash_ready`, `hash_only`; يمكن اشتقاقها إن كان ذلك آمناً ولا تخزن مزدوجاً |

### Invariants

- لا تخزن temporary plaintext في أي حقل.
- تعيين password جديد يحدث hash + lifecycle fields + audit + session revocation coordination.
- `must_change_password` لا يزال true حتى نجاح تغيير من المستخدم، لا بمجرد عرض النموذج.
- لا يسمح للمستخدم بالخروج إلى لوحة الدور قبل التغيير الإلزامي، لكن يسمح بـlogout.
- لا يخفض `credential_version` في rollback.

## 6. `authentication_retention_runs`

إيصال CLI لأعمال التنقيح والحذف، دون نسخ محتوى الأحداث.

### Fields

| Field | Meaning | Rules |
|---|---|---|
| `id` / `batch_id` | هوية التشغيل | unique |
| `mode` | `dry_run` أو `apply` | لا يختلطان |
| `database_name` | قاعدة الهدف | لا credentials |
| `redact_before_utc` | cutoff التنقيح | مثبت عند البداية |
| `delete_before_utc` | cutoff الحذف | مثبت عند البداية |
| `last_event_id` | checkpoint | monotonic |
| `scanned_count` | مفحوص | monotonic |
| `redacted_count` | منقح | monotonic |
| `deleted_count` | محذوف | monotonic |
| `status` | `running`, `completed`, `failed` | transition controlled |
| `checksum` | بصمة parameters/counts | لا row data |
| `started_by` | actor/CLI operator id إن توفر | nullable، لا secret |
| `started_at_utc` / `completed_at_utc` | timeline | UTC |
| `failure_code` | سبب معياري | لا stack trace |

### Invariants

- apply يفشل قبل الكتابة إذا database guard أو backup marker غير صالح.
- resume يعيد نفس cutoffs/checksum ولا يقبل عدادات أقل.
- حذف الأحداث غير قابل للتراجع من التطبيق؛ receipt نفسه append-only بعد completion.

## 7. Relationships

```text
users 1 ── 1 authentication_user_state
users 1 ── * authentication_events (nullable after physical deletion)
users 1 ── * authentication_sessions
users 1 ── * authentication_sessions.revoked_by
authentication_sessions 1 ── * authentication_events (by session_key_hash, logical link)
authentication_retention_runs 1 ── * authentication_events (temporary batch marker)
authentication_source_throttles     (pseudonymous, no direct user FK)
```

## 8. Data ownership and deletion

- Accounts/Authentication owns vocabulary, throttle, state and session lifecycle.
- Operations/Audit owns the shared recording entrypoint, redaction policy and security-evidence persistence contract.
- Admin presentation consumes safe query contracts only.
- User logical archive does not erase events immediately; retention and privacy policy determine lifecycle.
- Physical deletion uses `SET NULL` for event user reference where required, keeps non-identifying evidence until retention, and removes/ends active session rows according to the deletion service transaction.
- Test accounts remain marked through `users.is_test_account`; no duplicate flag in auth tables.

## 9. Migration order

1. Event/state/source-throttle tables + policy/indexes.
2. Initialize state rows as `unknown_historical` in bounded migration or separate guarded initializer; heavy history backfill remains CLI.
3. Session registry after event/state service is stable.
4. Credential lifecycle fields after session revocation contract exists.
5. No DROP/rename in this feature. Final legacy password column removal is a future migration after evidence and rollback window.
