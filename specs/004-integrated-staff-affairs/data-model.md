# نموذج البيانات المنطقي — منظومة شؤون العاملين

**الحالة:** تصميم منطقي قبل التنفيذ؛ أسماء الجداول النهائية تُراجع عند إنشاء migrations.
**المبدأ:** بيانات المصدر غير قابلة للمحو، والسياسات مؤرخة، والنتائج المشتقة قابلة لإعادة الاحتساب والتفسير.

## 1. خريطة الملكية

| المجال | المالك | مسؤولياته |
|---|---|---|
| ملف العامل والهيكل | `Staff` | الهوية الوظيفية، التعيين، الوحدة، المسمى، المديرون، المجموعات |
| الحضور والبصمة | `Attendance` | السياسات، التقويم، الأحداث الخام، نتيجة اليوم، الإقفال والتقارير |
| الأذونات والإجازات والتأديب وارتق | `Staff` | الطلبات والسياسات والموافقات والسجلات الرسمية |
| التأثير المالي | `Finance` | تفسير حقائق HR إلى عناصر مالية واعتمادها وترحيلها |
| التدقيق | `Operations/Audit` | before/after، الأحداث الرسمية، batch، الحجب والتعارض |
| الإشعارات | عقد مشترك | صندوق وارد فردي، outbox، إعادة المحاولة، روابط آمنة |

## 2. الهيكل التنظيمي

### `staff_org_units`

- `id`, `code`, `name`, `unit_type`, `parent_id`
- `valid_from`, `valid_to`, `status`
- `created_by`, `created_at`, `updated_at`
- قيد: لا دورة في شجرة الوحدات، والكود فريد داخل فترة سريانه.

### `staff_job_titles`

- `id`, `code`, `name`, `active_from`, `active_to`, `status`
- المسمى كيان مرجعي؛ النص القديم يبقى للتوافق خلال الترحيل.

### `staff_assignments`

- `id`, `staff_user_id`, `org_unit_id`, `job_title_id`
- `employment_status`, `work_fraction`, `valid_from`, `valid_to`
- `source`, `version`, `created_by`
- قيد: لا تعيينين أساسيين متداخلين للعامل إلا إذا سمحت سياسة عمل متعددة صراحة.

### `staff_manager_assignments`

- `id`, `subject_type` (`staff`, `org_unit`), `subject_id`
- `manager_user_id`, `manager_kind` (`direct`, `administrative`, `hr`)
- `priority`, `valid_from`, `valid_to`
- قيد: المدير لا يدير نفسه، ولا دورة في سلسلة الإدارة، ولا تعارض متساوٍ للنطاق نفسه.

### `staff_policy_groups` و`staff_policy_group_memberships`

- المجموعة: `id`, `code`, `name`, `purpose`, `status`
- العضوية: `group_id`, `staff_user_id`, `valid_from`, `valid_to`
- المجموعات صريحة ولا تُستخدم بديلًا مبهمًا عن القوة أو المسمى.

### `staff_delegations`

- `delegator_user_id`, `delegate_user_id`, `scope_type`, `scope_id`
- `request_types`, `valid_from`, `valid_to`, `reason`, `status`
- قيد: لا تفويض ذاتي، ولا سلسلة تفويض غير محدودة، وكل قرار يحفظ `acting_for`.

## 3. الدوام والتقويم

### `staff_schedule_policies`

- هوية السياسة: `id`, `code`, `name`, `status`.

### `staff_schedule_policy_versions`

- `id`, `policy_id`, `version_no`, `state` (`draft`, `published`, `retired`)
- `valid_from`, `valid_to`, `timezone`, `rounding_rule`
- `published_by`, `published_at`
- النسخة المنشورة immutable.
- فترات السريان ذات نوع `DATETIME` نصف مفتوحة: `[valid_from, valid_to)`؛ تعرض الواجهة آخر يوم شاملًا ثم تخزنه كبداية اليوم التالي، حتى لا يسقط يوم النهاية أو تتداخل نسختان عند الحد نفسه.

### `staff_schedule_days`

- `policy_version_id`, `weekday`
- `is_working_day`, `start_time`, `end_time`, `end_day_offset`
- `required_minutes`, `late_grace_minutes`, `early_grace_minutes`
- يدعم أكثر من فترة عبر `staff_schedule_segments` عند الوردية المنقسمة.
- المقطع يحدد `segment_type` (`work`, `paid_break`, `unpaid_break`, `on_call`) وأثره على الدقائق المطلوبة.

### `staff_schedule_scopes`

- `policy_version_id`, `scope_type` (`global`, `org_unit`, `job_title`, `group`, `staff`)
- `scope_id`, `priority`, `valid_from`, `valid_to`
- قيد النشر: لا tie غير محسوم عند درجة الأولوية نفسها.
- يتبع نطاق السياسة عقد الفترة النصف مفتوحة نفسه الخاص بنسخة الدوام.

### `staff_calendar_exceptions`

- `calendar_date`, `scope_type`, `scope_id`
- `exception_type` (`holiday`, `closure`, `partial_day`, `makeup_day`, `override`)
- `schedule_policy_version_id`, `reason`, `status`

### `staff_schedule_change_requests`

- `staff_user_id`, `change_type` (`temporary_shift`, `shift_swap`, `overtime`, `alternative_attendance`)
- `from_at`, `to_at`, `counterpart_staff_id` nullable
- `requested_schedule_version_id`, `reason`, `workflow_instance_id`, `status`
- `approved_schedule_snapshot`, `lock_version`
- قيد: تبديل الوردية لا يصبح نافذًا إلا بعد قبول الطرف الآخر عندما تتطلب السياسة واعتماد المدير، ولا يغيّر السياسة الأساسية.

### `staff_attendance_entry_methods`

- `code`, `name`, `method_type` (`biometric`, `manual_verified`, `device_fallback`, `access_log`)
- `requires_reason`, `requires_attachment`, `allowed_scope`, `status`
- الوسيلة البديلة لا تمنح حضورًا تلقائيًا؛ تنتج حدثًا موثقًا خاضعًا للمراجعة.

## 4. البصمة ونتيجة الحضور

### `staff_biometric_import_batches`

- `id`, `source_type`, `device_id`, `file_fingerprint`
- `started_at`, `finished_at`, `status`, `row_counts`, `error_summary`

### `staff_biometric_identity_mappings`

- `biometric_identity`, `staff_user_id`, `device_id`
- `valid_from`, `valid_to`, `source`, `confirmed_by`, `retired_reason`
- قيد: رقم البصمة مستقل عن `employee_code`، ولا يُدمجان في حقل واحد.
- قيد: لا mapping متداخل لنفس `(device_id, biometric_identity)`، وإعادة الاستخدام لاحقًا لا تغير ارتباط الأحداث القديمة.

### `staff_biometric_events`

- `id`, `batch_id`, `device_id`, `external_event_key`
- `biometric_identity`, `staff_user_id` nullable
- `device_event_at`, `received_at`, `normalized_event_at_utc`, `event_at_local`
- `clock_offset_seconds`, `clock_status`, `event_type`, `raw_hash`, `raw_payload_ref`
- `link_status`, `link_reason`, `processing_order`
- immutable؛ منع التكرار بمفتاح الجهاز الخارجي أو fingerprint موثق.

### `staff_attendance_runs`

- `id`, `engine_version`, `mode` (`shadow`, `official`, `recalculation`)
- `range_from`, `range_to`, `cutoff_at`, `initiated_by`, `status`
- `source_fingerprint`, `summary`, `supersedes_run_id`

### `staff_attendance_day_versions`

- `id`, `staff_user_id`, `work_date`, `version_no`, `run_id`
- snapshots: `assignment_id`, `schedule_policy_version_id`, `calendar_exception_id`
- المتوقع: `expected_start`, `expected_end`, `required_minutes`
- الفعلي: `first_in`, `last_out`, `worked_minutes`
- التغطية: `covered_late_minutes`, `covered_early_minutes`, `mission_minutes`, `leave_minutes`
- المخالفة: `late_minutes`, `early_leave_minutes`, `missing_minutes`
- `status`, `is_official`, `supersedes_id`, `calculated_at`
- قيد: نسخة رسمية واحدة فقط للعامل/يوم؛ النسخ القديمة لا تُحذف.

### `staff_attendance_segments`

- فترات العمل والبصمات المتطابقة داخل اليوم، لتغطية الوردية المنقسمة والمأمورية وسط اليوم.

### `staff_attendance_reason_lines`

- `day_version_id`, `reason_code`, `from_at`, `to_at`, `minutes`
- `source_type`, `source_id`, `explanation`
- أساس التفسير والتقارير التفصيلية.

### `staff_attendance_adjustments`

- `staff_user_id`, `work_date`, `requester_id`, `requester_kind` (`self`, `manager`, `hr`), `reason`
- `before_version_id`, `proposed_values`, `workflow_instance_id`
- `status`, `submitted_at`, `approved_version_id`, `resolution_comment`
- لا تعدل الحدث الخام؛ تنشئ نسخة يوم جديدة عند الاعتماد.

## 5. أنواع الأذونات والسياسات

### `staff_permission_types`

- `id`, `code` (`late_arrival`, `early_leave`, `mission`, `other`)
- `name`, `coverage_behavior`, `requires_custom_label`
- `requires_attachment`, `allow_retroactive`, `status`

### `staff_permission_policy_versions`

- `permission_type_id`, `version_no`, `state`, `valid_from`, `valid_to`
- `max_requests_per_month`, `max_minutes_per_request`, `max_minutes_per_month`
- `min_notice_minutes`, `retroactive_limit_days`

### `staff_permission_policy_scopes`

- نفس نطاق وأولوية الدوام: عام/قوة/مسمى/مجموعة/عامل.

### `staff_permission_requests`

- `id`, `staff_user_id`, `permission_type_id`
- `from_at`, `to_at`, `requested_minutes`, `custom_label`, `reason`
- snapshots: `policy_version_id`, `workflow_version_id`, `assignment_id`
- `status`, `submitted_at`, `decided_at`, `lock_version`
- `supersedes_id` للإلغاء أو التصحيح.
- قيد: الفترة موجبة، ولا تداخل غير مسموح مع إذن/إجازة أخرى.

### `staff_permission_quota_accounts`

- `staff_user_id`, `permission_type_id`, `period_key` (`YYYY-MM`)
- `reserved_count`, `consumed_count`, `reserved_minutes`, `consumed_minutes`
- cache مضبوط من دفتر الحركات، لا مصدر الحقيقة.

### `staff_permission_quota_movements`

- `account_id`, `request_id`, `movement_type`
- `count_delta`, `minutes_delta`, `idempotency_key`, `created_at`
- الأنواع: `reserve`, `consume`, `release`, `adjust`, `reverse`.

## 6. محرك الموافقات

### `staff_approval_workflows` و`staff_approval_workflow_versions`

- القالب حسب نوع الطلب والنطاق.
- النسخة تحدد `state`, `valid_from/to`, وقواعد الإلغاء والتصعيد.

### `staff_approval_stages`

- `workflow_version_id`, `sequence_no`, `name`
- `resolver_type` (`direct_manager`, `admin_manager`, `named_users`, `role_scope`)
- `decision_mode` (`sequential`, `any_one`, `all`, `quorum`)
- `sla_minutes`, `on_timeout`, `self_approval_rule`, `same_actor_rule`
- `quorum_count`, `tie_rule`, `rejection_rule`

### `staff_approval_instances`

- `resource_type`, `resource_id`, `workflow_version_id`
- `status`, `current_sequence`, `started_at`, `completed_at`
- snapshot لا يُعاد بناؤه بعد الإرسال.

### `staff_approval_steps` و`staff_approval_assignees`

- الخطوة المثبتة، المعتمدون المؤهلون، وقت الاستحقاق، وحالة الخطوة.

### `staff_approval_decisions`

- `step_id`, `actor_user_id`, `acting_for_user_id` nullable
- `decision`, `comment`, `decided_at`, `idempotency_key`
- immutable؛ قرار واحد فاعل لكل actor/step.

### `staff_approval_escalation_events`

- `step_id`, `event_type`, `from_assignee`, `to_assignee`, `reason`, `created_at`

## 7. الإجازات

### `staff_leave_types`

- نوع الإجازة، الوحدة (`day`, `hour`)، المستندات المطلوبة، أثر الأجر كـ fact code فقط، وحالة التفعيل.

### `staff_leave_policy_versions` و`staff_leave_policy_scopes`

- قواعد الاستحقاق، الترحيل، الانتهاء، الحد الأقصى، التجزئة، التداخل، وفترة الإخطار.
- قواعد التشغيل: حد العاملين الأدنى، فترات الحظر، نسبة الغياب القصوى، وصاحب صلاحية التجاوز.

### `staff_leave_requests` و`staff_leave_request_days`

- الطلب يحفظ الفترة والسياسة ومسار الاعتماد والسبب والمرفقات.
- الأيام تفصل العمل/العطلة والجزئي والوحدة المستهلكة وأثر كل يوم.

### `staff_leave_balance_accounts`

- `staff_user_id`, `leave_type_id`, `entitlement_period`
- caches: `available`, `reserved`, `consumed`.

### `staff_leave_balance_movements`

- `grant`, `accrue`, `reserve`, `consume`, `release`, `carry`, `expire`, `adjust`, `reverse`
- `units_delta`, `source_type`, `source_id`, `idempotency_key`
- قيد: لا رصيد سالب إلا بسياسة صريحة معتمدة.

### `staff_return_to_work_events`

- `leave_request_id`, `actual_return_at`, `fitness_status`, `document_id`, `recorded_by`
- البيانات الصحية محجوبة في التدقيق ولا تظهر إلا لصلاحية محددة.

## 8. التأديب والتظلمات

### الكيانات

- `staff_discipline_incidents`: الواقعة الأصلية.
- `staff_discipline_cases`: رقم القضية، العامل، التصنيف، الحالة، السرية.
- `staff_discipline_case_parties`: عدة عاملين/شهود/مشتكين/مشكو في حقهم مع دور كل طرف ونطاق رؤيته.
- `staff_discipline_investigations`: المحققون، الجلسات، النتائج.
- `staff_discipline_evidence`: مراجع مرفقات خاصة وسلسلة الحيازة.
- `staff_discipline_interim_measures`: إجراء احترازي، مدته، مبرره، موافقاته، أثر الوصول، وحالة المراجعة.
- `staff_discipline_decisions`: القرار والتسبيب وتاريخ النفاذ.
- `staff_discipline_appeals`: التظلم والمراجع والنتيجة.
- `staff_discipline_executions`: إثبات التنفيذ أو الإيقاف أو العكس.
- `staff_discipline_finance_effects`: مفاتيح حقائق مرسلة للمالية وحالتها.
- `staff_discipline_reopen_events`: الدليل الجديد، قرار إعادة الفتح، القضية/القرار السابق، وسلسلة النسخ.

## 9. نظام «ارتق»

### `staff_ertaq_tickets`

- `ticket_no`, `requester_user_id`
- `type` (`complaint`, `suggestion`, `inquiry`, `other`)
- `classification`, `confidentiality_level`, `priority`, `risk_level`
- `subject`, `status`, `sla_policy_id`
- `urgent_route_id`, `withdrawal_requested_at`, `created_at`, `closed_at`, `lock_version`

### `staff_ertaq_messages`

- `ticket_id`, `sender_user_id`, `message_type`, `body_cipher_or_text`
- `visibility` (`requester`, `assigned_team`, `restricted`)
- `created_at`; لا حذف مادي بعد الإرسال.

### `staff_ertaq_assignments`, `staff_ertaq_watchers`, `staff_ertaq_sla_events`

- توجيه ومسؤولية وتصعيد ومراقبة مهلة.
- تحويل شكوى إلى قضية تأديبية يتم برابط resource ولا ينسخ المحتوى الحساس.

### `staff_ertaq_parties` و`staff_ertaq_ticket_links`

- الأطراف: `ticket_id`, `user_id` nullable، `party_role`, `visibility`, `conflict_status`.
- الروابط: شكوى جماعية/مكررة/مرتبطة أو مبادرة تحسين أو قضية مشتقة، دون دمج المحتوى السري تلقائيًا.
- طلب السحب بعد بدء التحقيق يسجل حدثًا ولا يحذف التذكرة أو الأطراف.

### `staff_ertaq_urgent_events`

- `ticket_id`, `risk_type`, `routed_team_id`, `routed_at`, `acknowledged_at`, `resolution_ref`
- لا يعتمد على التصعيد الدوري؛ ينشأ عند تصنيف الخطر ويمنع الأطراف المتعارضة من الرؤية.

## 10. المرفقات والإشعارات والتأثيرات الخارجية

### `staff_resource_attachments`

- `resource_type`, `resource_id`, `file_id/private_path`
- `original_name`, `mime`, `size`, `classification`
- `uploaded_by`, `retention_until`, `legal_hold`
- لا URL مطلق ولا مسار Windows داخل قاعدة البيانات.

### `user_notification_inbox`

- `recipient_user_id`, `event_key`, `neutral_text`, `secure_route`
- `created_at`, `read_at`, `archived_at`

### `notification_outbox`

- `event_key`, `recipient_user_id`, `payload`, `attempts`, `next_attempt_at`, `status`
- فريد على `(event_key, recipient_user_id)` لمنع التكرار.

### `staff_external_effects`

- `effect_key`, `resource_type`, `resource_id`
- `target_module`, `fact_type`, `units`, `effective_period`
- `status`, `result_ref`, `last_error`, `attempts`
- لا تُخزن مبالغ الرواتب؛ المالية تملك التحويل المالي.

## 11. آلات الحالات

### الإذن/الإجازة/تعديل الحضور

`draft → submitted → pending_approval → approved | rejected`

- من `approved`: `cancellation_pending → cancelled` أو `correction_pending → superseded`.
- لا يعود سجل رسمي إلى draft.
- الطلب المنتهي قبل اكتمال الاعتماد يمكن أن يصبح `expired` حسب السياسة، مع تحرير الحصة.

### خطوة الاعتماد

`waiting → active → approved | rejected | skipped | expired`

- لا تُفعّل الخطوة التالية قبل تحقق قرار المرحلة الحالية.

### قضية التأديب

`reported → triage → under_investigation → pending_decision → decided`

- من `decided`: `appeal_pending → upheld | amended | revoked`.
- الإغلاق بعد توثيق التنفيذ أو عدم وجود إجراء.
- الإجراء الاحترازي حالة موازية مؤقتة لا تعني الإدانة، وله مراجعة وانتهاء مستقلان.
- `closed → reopened` مسموح فقط بدليل جديد وقرار مخول، مع إبقاء القرار السابق.

### تذكرة ارتق

`new → triaged → assigned → in_progress → awaiting_requester | resolved → closed`

- `reopened` يعيدها إلى `assigned/in_progress` مع حدث SLA جديد.
- `urgent_protected` مسار موازٍ يقيّد الرؤية ويطلب إقرار استلام من فريق الحماية.
- `withdrawal_requested` لا يصبح حذفًا، ويقرر المعالج هل تتوقف المعالجة أم تستمر لالتزام قانوني/وقائي.

### تشغيل الحضور

`queued → running → completed | completed_with_exceptions | failed`

- النسخة الرسمية لا تُستبدل إلا بتشغيل لاحق يسجل `supersedes`.

## 12. القيود غير القابلة للكسر

- حدث البصمة الخام، قرار الاعتماد، وحركة الرصيد لا تُحدث ولا تُحذف.
- لا يثبت الإذن الجزئي حضورًا ولا يسد بصمة مفقودة خارج التغطية.
- لا تتداخل هوية بصمة واحدة لعاملين، ووقت الجهاز ووقت الاستلام كلاهما محفوظان.
- نسخة رسمية واحدة فقط لنتيجة العامل في يوم العمل.
- لا سياسة منشورة متعارضة في الدرجة نفسها.
- لا self-approval، ولا يحتسب actor نفسه في مرحلتين إلا بسياسة صريحة، وكل تفويض يحفظ صاحب الصلاحية الأصلي.
- الحصة والرصيد لا يصبحان سالبين إلا بسياسة صريحة مسجلة.
- كل business write وسجل التدقيق في معاملة واحدة حيث يمكن.
- المحتوى الصحي والتأديبي والشكاوى السرية محجوب في السجلات والإشعارات.
- التقارير تجمع فقط الأيام المؤهلة للعمل وتعرض المقام المستخدم للنسب.
- تصدير CSV يمنع formula injection.

## 13. سجل تشغيل الترحيل

### `staff_hr_migration_batches`

- `id`, `migration_key`, `source_watermark`, `target_watermark`
- `started_at`, `checkpoint_at`, `completed_at`, `status`
- `read_count`, `write_count`, `skip_count`, `error_count`, `checksum`
- `resume_token`, `idempotency_key`, `cutover_window_id`

### `staff_hr_migration_exceptions`

- `batch_id`, `source_type`, `source_key`, `reason_code`, `payload_hash`
- `resolution_status`, `resolved_by`, `resolved_at`
- السجلات الغامضة أو المتعارضة تعزل للمراجعة ولا تُخمن.

### `staff_hr_cutover_windows`

- `opened_at`, `write_mode` (`capture`, `freeze`, `legacy_only`, `new_only`)
- `closed_at`, `approved_by`, `rollback_deadline`, `reconciliation_status`
- يمنع dual-write اليدوي؛ كل كتابة أثناء النافذة لها مسار معلوم ومصالحة.

## 14. حزمة القبول التجريبية

هذه موارد اختبار/تسليم في قاعدة قبول معزولة، وليست جداول إنتاج إلزامية.

### `AcceptanceDatasetManifest`

- `dataset_key`, `version`, `target_database`, `environment_marker`
- `created_at`, `baseline_checksum`, `scenario_catalog_version`
- `entity_refs` لكل سجل تابع للحزمة، و`seed_idempotency_key`
- قيد: لا تحميل أو استعادة قبل إثبات أن الهدف `_test` أو demo مصرح به صراحة.

### `AcceptancePersona`

- `persona_key`, `display_name`, `role_memberships`, `staff_assignment_ref`
- `expected_capabilities`, `credential_delivery_status`
- لا تحفظ كلمة المرور أو secret داخل manifest أو تقرير التسليم.

### `AcceptanceScenarioEvidence`

- `scenario_id`, `persona_key`, `started_at`, `completed_at`
- `result` (`passed`, `failed`, `blocked`), `step_results`, `artifact_refs`, `issue_ref`
- الأدلة لا تحتوي بيانات حساسة أو tokens، وتبقى مرتبطة بإصدار الحزمة.

### قواعد baseline والاستعادة

- baseline يملك فقط الكيانات المسجلة في manifest.
- الاستعادة تعكس/تعيد إنشاء كيانات الحزمة بالترتيب، ولا تستخدم حذفًا واسعًا أو `TRUNCATE`.
- إعادة التحميل بالمفتاح والإصدار نفسيهما idempotent.
- بعد تجربة المستخدم يمكن استعادة baseline أو الاحتفاظ بالتغييرات مع snapshot جديد واضح.

## 15. خريطة الترحيل من القديم

| المصدر القديم | الوجهة | أسلوب الترحيل |
|---|---|---|
| مفاتيح `staff_shift_*` | سياسة دوام عامة v1 | نسخ مرة واحدة مع إبقاء adapter |
| `staff_shift_overrides` | نطاق سياسة `staff` | backfill مؤرخ من تاريخ الترحيل، دون اختلاق تاريخ سابق |
| `staff_profiles.department/job_title` | وحدات ومسميات وتعيينات | مطابقة صريحة؛ القيم الغامضة لقائمة مراجعة |
| `staff_biometric_logs` | أحداث بصمة خام | استيراد idempotent مع hash ومصالحة counts |
| `staff_attendance` | نسخة يوم baseline | تعليمها `legacy_baseline` لا حذف الأصل |
| `staff_permissions` | طلبات أذونات | تحويل الحالة والـ approved_by إلى instance legacy من مرحلة واحدة |
| `staff_leaves` | طلبات إجازة | تحويل الحالة؛ المرفقات وفق معيار التخزين الخاص |
| `annual_leave_balance` | حركة opening balance | cache قديم للقراءة حتى اكتمال المصالحة |
| `staff_disciplinary` | قضايا مستوردة | حالة `legacy_imported` مع حفظ المرجع |

كل backfill يسجل batch ومعايير المصالحة وعدد الصفوف والاستثناءات، ولا يُحذف المصدر أثناء هذه الخطة.
