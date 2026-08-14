# عقود التكامل بين الوحدات

## 1. مبادئ

- لا SQL مباشر من وحدة إلى جداول خاصة بوحدة أخرى.
- كل عقد صغير ومملوك للجهة صاحبة البيانات.
- الاستدعاءات الكتابية تحمل `idempotencyKey` و`sourceRef`.
- فشل أثر خارجي لا يكرر القرار التجاري؛ يسجل في Outbox/External Effects ويعاد.

## 2. عقود Staff

### `StaffPortalEligibilityQuery`

```text
forUser(userId, atDate) ->
  { eligible, staffId, activeAssignmentId, capabilities[] }
```

لا يعتمد على `active_role` وحده.

### `StaffTimelineEventSource`

```text
sourceKey() -> stableSourceKey
eventsForStaff(staffId, fromInclusive, toExclusive, limit) ->
  [{ eventId, occurredAt, eventType, resourceType, resourceId, status, version? }]
```

- المصدر يعيد ملخصًا تشغيليًا فقط؛ لا يعيد سبب طلب، وصفًا طبيًا، نص شكوى، دليلًا، مرفقًا، أو قيمة مالية.
- يملك `StaffHrTimelineQuery` دمج المصادر وترتيبها ورفض تكرار `(source,eventId)` أو حدث خارج النافذة، ويعيد تحذيرًا محايدًا عند خلل مصدر بدل إخفاء النقص.
- يظل مصدر كل وحدة داخل مالكها؛ لا يستخدم Staff SQL مباشرًا إلى جداول Attendance/Finance أو إلى تفاصيل مورد حساس. صلاحية مشاهد الخط الزمني والرابط إلى المورد يعيدان الفحص في الصفحة/المالك.

### `StaffCredentialRepository`

```text
createCredential(immutableCredential, idempotencyKey) -> { record, replayed }
expiringCredentials(asOf, through, limit) -> safeExpiryRows[]
```

- مالكه `StaffDocumentExpiryService` فقط؛ يثبت الممثل الإداري ووجود `staff_profiles` داخل المعاملة قبل إنشاء السجل.
- المؤهل/التدريب/الوثيقة سجل ملحق بإصدار، وليس تحديثًا لصف قديم. اختلاف payload لنفس العامل/النوع/المفتاح يضيف سجلًا خلفًا مرتبطًا، ويستبعد إسقاط الانتهاء النسخة السابقة.
- الإيصال وإسقاط الانتهاء يعيدان المعرف والنوع والتاريخ والحالة والإصدار فقط. العنوان واسم الجهة ومرجع المرفق وبصمات payload/idempotency لا تعبر العقد.
- `notifyRecipients` يستقبل حدث انتهاء محايدًا ومفتاح idempotency يضم المعرف/تاريخ الانتهاء/حالته؛ فتح الرابط يعيد فحص صلاحية المورد.

### `StaffOrganizationCorrectionRepository` و`StaffOrganizationCorrectionImpactGateway`

```text
previewCorrection(candidate, actorId) -> { correctionId, frozenImpact, replayed }
decideCorrection(correctionId, decision, actorId, idempotencyKey) -> decisionReceipt
publishImpact({ correctionId, decisionId, direction, frozenImpact }) -> exactImpactFacts[]
```

- يثبت preview العاملين والأيام ومراجع الطلبات وفترات التقارير قبل القرار، ولا يعاد توسيع النطاق وقت الاعتماد.
- لا يقرر مقدم الطلب طلبه؛ صلاحية القرار الحية هي super-admin أو مدير HR مؤرخ، وليست قيمة role قادمة من المتصفح.
- ينشر gateway حقائق أثر Staff ملحقة فقط. لا يكتب مباشرة إلى جداول Attendance أو الطلبات أو التقارير؛ يعيد كل مالك تفويض مورده ويبني النسخة التشغيلية الجديدة من الحقيقة المحددة.
- العكس correction جديد مرتبط بالأصل ويستخدم snapshot نفسه في اتجاه معاكس؛ لا يوجد UPDATE/DELETE للتاريخ.

### `StaffAssignmentAtDateQuery`

```text
forStaff(staffId, atDate) ->
  { assignmentId, orgUnitId, jobTitleId, groupIds[], employmentStatus }
```

### `StaffAttendanceReportDimensionQuery`

```text
forAttendanceDays(dayReferences) ->
  { dimensions[dayVersionId], conflicts[] }
```

- تدخل إليه مراجع اليوم الرسمية فقط: معرف النسخة والعامل ويوم العمل ومعرف التعيين المثبت.
- يعيد التعيين والوحدة والمسمى وعضويات المجموعة السارية في يوم العمل، لا بيانات ملف العامل أو عضويته الحالية.
- تعارض التعيين أو عدم سريانه يعود كـ conflict؛ لا يملأ Attendance بعدًا تاريخيًا بالتخمين.
- لا يعيد أسماء العامل أو الأسباب أو المرفقات أو أي بيانات خاصة.

### `ManagerHierarchyAtDateQuery`

```text
resolve(staffId, managerKind, atDate) ->
  { managerId, assignmentId, delegation?, conflicts[] }
```

- التعارض أو الغياب نتيجة صريحة، لا اختيار أول صف.

### `StaffAccessEligibilityQuery`

```text
assertCurrentAccess(userId, capability, resourceRef, atInstant) ->
  { allowed, staffStatus, relationshipVersion, reason }
```

- يستدعى في كل طلب محمي، ولا يعتمد على نطاق الصفحة المحمل عند تسجيل الدخول.
- انتهاء الخدمة أو علاقة المدير أو التفويض يمنع الوصول في الطلب التالي.

### `ApprovalWorkflowResolutionGateway`

```text
resolveForResource(resourceType, staffId, minimumContext, effectiveAt, resolvedAt) ->
  { workflowVersionId, snapshot }
```

- يملكه نطاق الموافقات داخل `Staff` حتى تظل قراءة المديرين والتفويضات
  والتعادل ومنع الاعتماد الذاتي في مالك واحد.
- طلب الإذن وطلب الإجازة يمرران هوية المورد والتعيين المؤرخ والحقول اللازمة
  للمسار فقط؛ لا يمرر السبب أو المرجع الطبي أو المرفق أو لقطة سياسة كاملة.
- النسخة واللقطة الناتجتان أدلة إرسال غير قابلة للتبديل، ثم ينشئ
  `ApprovalWorkflowSubmissionGateway` الـinstance الدائم في المعاملة نفسها.

## 3. عقود Attendance

### `EffectiveScheduleQuery`

يعيد نسخة السياسة وسبب الأولوية ونافذة يوم العمل.

### `LeaveWorkdayCalendarQuery`

```text
daysIntersecting(staffId, fromAt, toAt, requestTimezone) ->
  { workDate, status, requiredMinutes, workingIntervals[],
    schedulePolicyVersionId, calendarExceptionId, conflicts[] }
```

- يملكه `Attendance` ويحوّل الوردية المنشورة والاستثناءات إلى فترات عمل
  دنيا قابلة للاحتساب فقط؛ لا يعيد JSON السياسة أو البصمات أو الأسباب.
- يعيد كل يوم مدني داخل الطلب وأي يوم عمل سابق تتداخل ورديته العابرة لمنتصف
  الليل مع الفترة نصف المفتوحة للطلب، حتى لا تضيع ساعة من وردية ليلية.
- النتيجة غير المحلولة صريحة ومانعة؛ لا يخمّن `Staff` يوم عمل أو عطلة.
- يستهلكه `Staff` في حساب مسودة الإجازة فقط، ولا يكتب به تغطية أو نتيجة حضور.

### `ApprovedCoverageQuery`

يعيد تغطيات الإذن والإجازة المعتمدة بمصدرها.

### `AttendanceReportQuery`

```text
query(filters, dimensions, page, asOfVersion?) ->
  { rows, totals, denominator, officialVersion, warnings }
```

الفلاتر: عامل، قوة، وحدة، مسمى، مجموعة، فترة، حالة، نوع مخالفة.
الأبعاد التنظيمية snapshot عند اليوم، لا الوضع الحالي فقط.

## 4. عقد المالية

المالك: `Finance`.

### `PayrollImpactGateway`

```text
submitFacts(
  effectKey,
  staffId,
  factType,
  units,
  effectivePeriod,
  sourceRef,
  metadata
) -> { accepted, status, financeReference }
```

حقائق مقترحة:

- `unpaid_absence_days`
- `uncovered_late_minutes`
- `approved_discipline_deduction_units`
- `reversal`

قواعد:

- HR لا يرسل مبلغًا نهائيًا ولا يكتب payroll.
- المالية تحدد المبلغ والسياسة ومراحل maker-checker والإقفال والعكس.
- `effectKey` يمنع التنفيذ المكرر.
- إلغاء قرار بعد ترحيل راتب ينتج fact عكسي، لا حذف القيد.
- إذا كانت فترة الراتب مقفلة، تعيد Finance حالة `locked` ومرجع إجراء العكس/الفترة التالية؛ لا يحاول HR تعديلها.
- عامل الإرسال يختار فقط الآثار المستحقة التابعة لـ`leave_request` والمتجهة إلى `finance` ثم يحجز كل أثر بتحديث شرطي؛ انتهاء عقد معالجة قد يعيد الإرسال بالمفتاح نفسه، ولذلك تبقى Finance مسؤولة عن idempotency النهائي.
- التنفيذ الفعلي يتطلب بوابة `PayrollImpactGateway` يركبها مالك Finance صراحةً؛ غيابها لا يسمح لشؤون العاملين بإنشاء قيد أو حساب مبلغ بديل.

## 5. عقد الإشعارات

### `NotificationPort`

```text
notifyRecipients(
  eventKey,
  recipientIds,
  secureRoute,
  neutralText,
  metadata,
  idempotencyKey
) -> NotificationReceipt
```

- ينشئ Inbox أولًا ثم Outbox لـ Push.
- النص الحساس لا يوضع في Push أو عنوان Inbox.
- فتح `secureRoute` يعيد فحص authorization.
- دعم read/unread وretry وحالة التسليم.

أحداث أساسية:

- `staff.request.submitted`
- `staff.approval.assigned`
- `staff.request.approved|rejected|cancelled`
- `staff.leave.balance.adjusted`
- `staff.discipline.response_required|decision_issued|appeal_due`
- `staff.ertaq.assigned|reply|resolved|sla_overdue`
- `staff.credential.expiry`

## 6. عقد التدقيق

كل write owner يستدعي الخدمة المشتركة داخل المعاملة نفسها:

```text
record(actor, entityType, entityId, action, before, after, context)
```

السياسة لكل كيان تحدد:

- هل يسمح direct undo أم correction/reversal فقط.
- نطاق الفاعل.
- retention وlegal hold.
- الحقول المحجوبة.
- سلوك التعارض.

### `SystemActivityLogQuery` (Operations-owned read projection)

```text
load({ targetTypePrefix?, action?, dateFrom?, dateTo?, search? }, tab, limit, offset)
  -> { rows, total, undoneTotal }
```

- واجهة HR تمرر فقط prefix ثابتًا `staff_` ولا تنشئ SQL خاصًا بجدول `activity_logs`.
- prefix غير المتوافق يفشل مغلقًا ولا يتحول إلى wildcard. يعرض سطح HR حقول التشغيل فقط ولا يعرض `details` أو payloads المخزنة.
- `UndoManager` لا يعبر هذا العقد؛ التراجع يظل مملوكًا للسطح والسياسة المركزيين.

حقول تُحجب على الأقل:

- الهوية الوطنية والبيانات الصحية.
- نصوص ومرفقات ارتق السرية.
- إفادات وأدلة التحقيق.
- مفاتيح الأجهزة والرموز والأسرار.

## 7. عقد المرفقات

- الرفع عبر `FileUploadGuard`.
- التخزين في `storage/private/`.
- قاعدة البيانات تحفظ identifier/relative path فقط.
- التنزيل عبر متحكم يعيد فحص نوع المورد وصلاحية المستخدم.
- عند فشل DB يُزال الملف الجديد؛ عند الاستبدال تعتمد الإشارة الجديدة قبل حذف القديمة.
- الملفات الخاضعة لـ legal hold لا تحذف بانتهاء retention العادي.

## 8. اتساق المعاملات والأحداث

- داخل الوحدة: business write + audit + outbox في معاملة واحدة.
- بين الوحدات: Outbox/Inbox وidempotency، لا معاملة موزعة.
- فشل المالية أو Push يترك external effect قابلًا لإعادة المحاولة.
- الفشل الدائم ينتقل إلى قائمة تشغيل مع سبب واضح ولا يخفى.

## 8.1 عقد نافذة التحويل

### `MigrationCutoverCoordinator`

```text
openWindow(mode, sourceWatermark, approvedBy, idempotencyKey, rollbackDeadline?) -> CutoverWindow
beginBatch(windowId, migrationKey, sourceWatermark, actorId, idempotencyKey, manifest) -> Batch
checkpoint(windowId, batchId, resumeToken, cumulativeCounts, checksum, actorId) -> Checkpoint
quarantine(batchId, sourceType, sourceKey, reasonCode, payloadHash, actorId) -> ExceptionReceipt
recordConcurrentLegacyWrite(windowId, sourceWatermark, payloadHash, actorId) -> CaptureReceipt
completeBatch(batchId, targetWatermark, actorId) -> Batch
closeWindow(windowId, reconciliation, actorId) -> Result
rollbackWindow(windowId, reason, actorId, migrationOwnedRollback) -> Result
```

- `mode` يحدد صراحة هل الكتابة legacy فقط أو new فقط أو capture منضبط.
- إعادة `checkpoint` بالمفتاح نفسه لا تكرر صفوفًا.
- لا يصبح `new_only` رسميًا قبل مصالحة الصفوف والـchecksums والاستثناءات.
- الانقطاع يستأنف من `resumeToken` ولا يعيد كتابة الدفعات المكتملة.
- rollback يمرر manifest المعرفات scalar إلى executor خاص بالترحيل؛ لا يقبل المنسق اسم جدول أو شرط حذف عامًا من مستخدم/صفحة.

## 9. التوافق

- لا تضاف methods إلى عقد مستعمل إذا كانت تكسر adapters الحالية؛ ينشأ عقد مؤرخ جديد.
- الصفحات القديمة تقرأ عبر adapter إلى العقود الجديدة، مع feature flag.
- حالات legacy تتحول عبر mapping موثق، وتظل القيم المتوقعة في JSON/POST متوافقة خلال المرحلة الانتقالية.
