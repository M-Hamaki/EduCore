'use strict';

const { validateJourneyDefinitions } = require('./staff_hr_acceptance_runner');

const journeys = [
    { id: 'Q01', title: 'أولوية الدوام ورفض التعادل', personas: ['super_admin'], mutates: true, actions: ['publish_scoped_schedules', 'resolve_worker_schedules', 'verify_equal_rank_conflict'] },
    { id: 'Q02', title: 'السياسة المؤرخة لا تغير الماضي', personas: ['super_admin', 'hr_manager'], mutates: true, actions: ['publish_successor_schedule', 'reopen_historical_report', 'verify_historical_policy_version'] },
    { id: 'Q03', title: 'وردية ليلية وعطلة', personas: ['hr_manager', 'worker_standard'], mutates: true, actions: ['record_overnight_punches', 'calculate_overnight_day', 'verify_holiday_denominator'] },
    { id: 'Q04', title: 'إذن حضور متأخر', personas: ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager'], mutates: true, actions: ['submit_late_arrival_permission', 'approve_permission_stages', 'record_late_arrival_punches', 'verify_late_coverage_minutes'] },
    { id: 'Q05', title: 'إذن انصراف مبكر', personas: ['worker_standard', 'direct_manager', 'administrative_manager'], mutates: true, actions: ['submit_early_leave_permission', 'approve_permission_stages', 'record_early_departure_punches', 'verify_early_coverage_minutes'] },
    { id: 'Q06', title: 'مأمورية وبصمات متعددة', personas: ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager'], mutates: true, actions: ['submit_mission_permission', 'approve_permission_stages', 'record_split_presence_punches', 'verify_mission_is_separate_from_presence'] },
    { id: 'Q07', title: 'بصمة مكررة وناقصة وغير معروفة', personas: ['hr_manager'], mutates: true, actions: ['import_duplicate_and_unknown_punches', 'open_attendance_exceptions', 'verify_raw_event_idempotency'] },
    { id: 'Q08', title: 'اعتماد متعدد المراحل', personas: ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager'], mutates: true, actions: ['submit_three_stage_request', 'attempt_out_of_order_decision', 'complete_ordered_decisions', 'verify_final_coverage_once'] },
    { id: 'Q09', title: 'تفويض وتعارض مصالح', personas: ['worker_standard', 'direct_manager', 'delegate_manager', 'protection_officer'], mutates: true, actions: ['publish_temporary_delegation', 'decide_as_delegate', 'expire_delegation', 'verify_conflicted_manager_excluded'] },
    { id: 'Q10', title: 'سباق آخر حصة', personas: ['worker_standard', 'worker_teacher', 'direct_manager', 'hr_manager'], mutates: true, actions: ['submit_concurrent_last_quota_requests', 'verify_only_one_reservation', 'reject_reserved_request', 'verify_quota_release_and_retry'] },
    { id: 'Q11', title: 'رصيد وإجازة عابرة للفترة', personas: ['worker_standard', 'hr_manager'], mutates: true, actions: ['create_opening_leave_balance', 'submit_competing_leave_requests', 'submit_cross_year_leave', 'verify_leave_ledger_invariants'] },
    { id: 'Q12', title: 'مرفق صحي خاص', personas: ['worker_standard', 'hr_manager'], mutates: true, actions: ['upload_valid_medical_pdf', 'attempt_unsafe_medical_uploads', 'force_attachment_metadata_failure', 'verify_private_file_rollback'] },
    { id: 'Q13', title: 'قضية وتأديب وتظلم', personas: ['hr_manager', 'worker_standard', 'finance_operator'], mutates: true, actions: ['open_discipline_case', 'complete_separated_investigation_and_decision', 'submit_discipline_appeal', 'verify_original_decision_and_finance_fact'] },
    { id: 'Q14', title: 'ارتق والسرية', personas: ['worker_standard', 'direct_manager', 'protection_officer'], mutates: true, actions: ['submit_confidential_ertaq_complaint', 'submit_normal_ertaq_suggestion', 'attempt_conflicted_ticket_access', 'verify_neutral_notification_and_immutability'] },
    { id: 'Q15', title: 'التقارير والتصدير الآمن', personas: ['hr_manager', 'direct_manager'], mutates: false, actions: ['open_individual_and_group_reports', 'drill_into_report_totals', 'export_formula_safe_csv', 'verify_report_denominator_and_scope'] },
    { id: 'Q16', title: 'خدمة ذاتية لمعلم وأخصائي', personas: ['worker_teacher', 'worker_specialist'], mutates: false, actions: ['open_teacher_staff_self_service', 'open_specialist_staff_self_service', 'attempt_other_worker_scope', 'verify_role_independent_portal_scope'] },
    { id: 'Q17', title: 'رسائل عربية مفهومة', personas: ['worker_standard', 'hr_manager'], mutates: true, actions: ['trigger_reviewed_domain_errors', 'inspect_user_error_messages', 'verify_no_technical_error_leak'] },
];

validateJourneyDefinitions(journeys);
module.exports = journeys;
