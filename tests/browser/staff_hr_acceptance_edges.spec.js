'use strict';

const { validateJourneyDefinitions } = require('./staff_hr_acceptance_runner');

const journeys = [
    { id: 'Q18', title: 'الإذن لا يثبت الحضور', personas: ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager'], mutates: true, actions: ['approve_permission_without_punches', 'recalculate_unattended_day', 'verify_absence_and_coverage_separate'] },
    { id: 'Q19', title: 'اعتراض وتصحيح حضور', personas: ['worker_standard', 'direct_manager', 'hr_manager'], mutates: true, actions: ['submit_attendance_adjustment', 'attempt_self_approval', 'approve_attendance_adjustment', 'verify_new_official_day_version'] },
    { id: 'Q20', title: 'تضارب البصمة والأحداث المتأخرة', personas: ['hr_manager'], mutates: true, actions: ['attempt_overlapping_biometric_identity', 'reuse_identity_after_end_date', 'import_delayed_drifted_events', 'verify_raw_history_and_period_lock'] },
    { id: 'Q21', title: 'استراحة ووردية مقسمة وتبديل وإضافي', personas: ['worker_standard', 'worker_teacher', 'hr_manager', 'direct_manager'], mutates: true, actions: ['publish_split_shift', 'approve_temporary_swap', 'record_unapproved_and_approved_overtime', 'verify_split_shift_calculation'] },
    { id: 'Q22', title: 'وسيلة حضور بديلة', personas: ['worker_standard', 'direct_manager'], mutates: true, actions: ['grant_temporary_alternative_attendance', 'attempt_self_reviewed_entry', 'approve_alternative_entry', 'verify_expired_method_rejected'] },
    { id: 'Q23', title: 'actor مكرر ونصاب وتعادل', personas: ['worker_standard', 'direct_manager', 'administrative_manager'], mutates: true, actions: ['publish_duplicate_actor_workflow', 'cast_quorum_and_tied_votes', 'submit_all_stage_rejection', 'verify_actor_counted_once'] },
    { id: 'Q24', title: 'نقل أو انتهاء خدمة أثناء الطلب', personas: ['worker_standard', 'direct_manager', 'hr_manager'], mutates: true, actions: ['submit_future_dated_request', 'transfer_worker_and_manager', 'end_service_with_pending_request', 'verify_access_revalidation_and_quota_release'] },
    { id: 'Q25', title: 'تعديل بعد الإقفال والرواتب', personas: ['hr_manager', 'finance_operator'], mutates: true, actions: ['close_attendance_period_and_dispatch_fact', 'approve_late_coverage_change', 'reverse_leave_after_close', 'verify_reopen_or_idempotent_finance_reversal'] },
    { id: 'Q26', title: 'تصحيح تنظيمي بأثر رجعي', personas: ['hr_manager', 'super_admin'], mutates: true, actions: ['preview_retroactive_organization_correction', 'approve_scoped_correction', 'verify_only_impacted_days_recalculated', 'cancel_correction_and_verify_history'] },
    { id: 'Q27', title: 'حد التشغيل وفترة الحظر', personas: ['worker_teacher', 'worker_specialist', 'hr_manager'], mutates: true, actions: ['submit_competing_staffing_leave_requests', 'attempt_blackout_leave', 'approve_reasoned_staffing_override', 'verify_balance_changes_only_after_outcome'] },
    { id: 'Q28', title: 'شكوى عاجلة وجماعية وسحب', personas: ['worker_standard', 'direct_manager', 'protection_officer'], mutates: true, actions: ['submit_immediate_risk_complaint', 'add_collective_parties_and_link_ticket', 'request_withdrawal_after_investigation', 'verify_protection_route_and_original_retention'] },
    { id: 'Q29', title: 'إجراء احترازي وإعادة فتح قضية', personas: ['hr_manager', 'worker_standard'], mutates: true, actions: ['apply_temporary_interim_measure', 'close_discipline_case', 'add_new_evidence_and_reopen', 'verify_prior_decision_and_reversal_history'] },
    { id: 'Q30', title: 'استئناف الترحيل والكتابة أثناء التحويل', personas: ['super_admin'], mutates: true, actions: ['interrupt_migration_after_checkpoint', 'resume_same_migration_batch', 'exercise_capture_and_freeze_modes', 'verify_reconciliation_failure_rolls_reader_back'] },
];

validateJourneyDefinitions(journeys);
module.exports = journeys;
