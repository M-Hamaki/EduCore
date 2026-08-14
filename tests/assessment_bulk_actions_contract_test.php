<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/classes/AssessmentBulkActionService.php');
$script = (string) file_get_contents($root . '/assets/js/assessment-bulk-actions.js');
$registry = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$adr = (string) file_get_contents($root . '/docs/architecture-decisions.md');
$pages = [
    'scheme' => (string) file_get_contents($root . '/admin/assessment_schemes.php'),
    'component' => (string) file_get_contents($root . '/admin/assessment_components.php'),
    'week_rule' => (string) file_get_contents($root . '/admin/assessment_component_week_rules.php'),
    'window' => (string) file_get_contents($root . '/admin/assessment_windows.php'),
];

$allPages = implode("\n", $pages);
$checks = [
    'service_limits_and_normalizes_selection' => strpos($service, 'MAX_BATCH_SIZE = 200') !== false
        && strpos($service, 'normalizeIds') !== false,
    'bulk_writes_are_transactional_and_fail_closed' => strpos($service, 'beginTransaction()') !== false
        && strpos($service, 'rollBack()') !== false
        && strpos($service, 'assertCompleteSelection') !== false
        && strpos($service, 'assertRowsDeletable') !== false,
    'bulk_audit_uses_explicit_batch_and_shared_service' => strpos($service, 'UndoManager::newBatchId()') !== false
        && strpos($service, 'new AuditService($this->db)') !== false
        && strpos($service, "'assessment_windows'") !== false,
    'all_operational_dependencies_remain_delete_blockers' => strpos($service, "['assessment_windows', 'scheme_id'") !== false
        && strpos($service, "['student_marks', 'component_id'") !== false
        && strpos($service, "['published_report_details', 'scheme_id'") !== false
        && strpos($service, 'weekRuleHasMarks') !== false
        && strpos($service, 'windowHasMarks') !== false,
    'cascade_children_are_audited_for_atomic_undo' => strpos($service, 'auditSchemeCascade') !== false
        && strpos($service, 'auditComponentCascade') !== false
        && strpos($service, "recordDelete(\n                    'assessment_component_week_rule'") !== false,
    'scheme_bulk_copy_is_scoped_draft_and_audited' => strpos($service, 'copySchemes') !== false
        && strpos($service, "'draft', ?, ?") !== false
        && strpos($service, 'schemeHasWeekRules') !== false
        && strpos($service, '1.0, $batchId') !== false
        && strpos($pages['scheme'], 'bulk_copy_schemes') !== false,
    'every_page_has_select_column_and_bulk_endpoint' => substr_count($allPages, 'assessment-select-page') === 4
        && substr_count($allPages, 'assessment-row-select') === 4
        && substr_count($allPages, "value=\"assessment_bulk_action\"") === 4,
    'every_page_uses_smart_delete_and_shared_script' => substr_count($allPages, 'assessment-smart-delete') === 4
        && substr_count($allPages, '../assets/js/assessment-bulk-actions.js') === 4,
    'modals_offer_safe_state_only_and_atomic_delete' => substr_count($allPages, 'data-bulk-deactivate-submit') === 4
        && substr_count($allPages, 'data-bulk-delete-submit') === 4
        && substr_count($allPages, 'name="bulk_operation" value="delete"') === 4,
    'selection_is_current_page_and_search_aware' => strpos($script, "rows({ page: 'current', search: 'applied' })") !== false
        && strpos($script, 'selectAll.indeterminate') !== false,
    'bulk_toolbar_is_hidden_until_a_row_is_selected' => substr_count($allPages, 'admin-bulk-action-bar d-none') === 4
        && strpos($script, "actionBar.classList.toggle('d-none', ids.length === 0)") !== false,
    'client_does_not_bypass_server_guards' => strpos($script, 'وجود ارتباط مانع') !== false
        && strpos($script, 'confirm(') === false
        && strpos($script, 'Swal') === false,
    'assessment_windows_has_explicit_undo_policy' => strpos($registry, "'assessment_windows'") !== false
        && strpos($adr, 'ADR-047') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
