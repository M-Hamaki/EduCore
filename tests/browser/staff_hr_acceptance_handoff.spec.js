'use strict';

const { validateJourneyDefinitions } = require('./staff_hr_acceptance_runner');

const journeys = [
    { id: 'Q31', title: 'عزل وبذر حزمة القبول', personas: ['super_admin'], mutates: true, actions: ['attempt_seed_on_refused_target', 'seed_acceptance_dataset_twice', 'verify_manifest_counts_checksum_and_synthetic_data'] },
    { id: 'Q32', title: 'رحلة واجهة كاملة متعددة الأدوار', personas: ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager', 'finance_operator', 'super_admin'], mutates: true, actions: ['create_worker_requests_and_ertaq_message', 'complete_manager_decisions', 'recalculate_and_report_as_hr', 'inspect_finance_fact_without_sensitive_data', 'audit_without_self_approval', 'verify_cross_role_totals_balances_and_scope'] },
    { id: 'Q33', title: 'ترك البيانات واستعادة baseline', personas: ['super_admin', 'worker_standard', 'direct_manager', 'hr_manager'], mutates: true, actions: ['capture_last_successful_dataset_receipt', 'replay_worker_approval_report_journey', 'mutate_manifest_owned_demo_rows', 'restore_manifest_owned_baseline', 'reseed_and_verify_post_restore_journey'] },
];

validateJourneyDefinitions(journeys);
module.exports = journeys;
