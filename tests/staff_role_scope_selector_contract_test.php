<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$accounts = (string) file_get_contents($root . '/admin/staff_accounts.php');
$modals = (string) file_get_contents($root . '/includes/staff_single_modals.php');
$styles = (string) file_get_contents($root . '/assets/css/admin-unified.css');

$checks = [
    'role_modal_uses_shared_assignment_frame' => strpos($modals, 'assessment-subject-assignment-modal-dialog') !== false
        && strpos($modals, 'assessment-subject-assignment-modal staff-role-access-modal') !== false,
    'role_modal_has_responsive_width' => strpos($modals, 'staff-role-access-modal-dialog') !== false
        && strpos($styles, '.staff-role-access-modal-dialog') !== false
        && strpos($styles, 'max-width: 960px') !== false,
    'role_modal_year_notice_is_top_level' => strpos($modals, 'staff-role-access-top-notices') !== false
        && strpos($modals, 'staff-role-access-year') !== false
        && substr_count($modals, 'نطاق الصفوف والفصول للعام الدراسي') === 1
        && strpos($styles, 'grid-template-columns: minmax(0, 1fr) minmax(13rem, auto)') !== false,
    'role_modal_sections_have_visual_hierarchy' => strpos($modals, 'staff-role-access-roles-field') !== false
        && strpos($modals, 'staff-role-access-supervisor') !== false
        && strpos($styles, '.staff-role-access-roles-label') !== false,
    'role_scope_uses_stage_grade_class_selectors' => strpos($accounts, 'assignment-stage-group') !== false
        && strpos($accounts, 'assignment-grade-card') !== false
        && strpos($accounts, 'assignment-grade-checkbox') !== false
        && strpos($accounts, 'assignment-class-checkbox') !== false,
    'role_scope_keeps_nested_post_contract' => strpos($accounts, "scopes[' + roleKey + '][grade_ids][]") !== false
        && strpos($accounts, "scopes[' + roleKey + '][class_ids][]") !== false,
    'role_scope_has_stage_and_global_actions' => strpos($accounts, 'select-assignment-stage-btn') !== false
        && strpos($accounts, 'select-staff-all-grades-btn') !== false
        && strpos($accounts, 'syncStaffScopeSection') !== false,
    'role_scope_supports_partial_classes' => strpos($accounts, 'if (input.checked) gradeInput.checked = false') !== false
        && strpos($accounts, 'assignment-class-checkbox') !== false,
    'legacy_card_renderer_removed' => strpos($accounts, 'staff-scope-group') === false,
    'staff_scope_layout_is_centralized' => strpos($styles, '.staff-role-access-modal') !== false
        && strpos($styles, '.staff-role-access-modal .staff-scope-role-list') !== false,
    'role_options_are_compact_chips' => strpos($styles, '.staff-role-access-modal #roleAccessOptions') !== false
        && strpos($styles, '.staff-role-access-modal #roleAccessOptions > .col > label') !== false
        && strpos($styles, 'min-width: 10.5rem') !== false,
    'role_scope_typography_is_readable' => strpos($styles, '.staff-role-access-modal .staff-scope-role .assignment-grade-card .assignment-grade-checkbox + label') !== false
        && strpos($styles, 'font-size: 0.96rem') !== false
        && strpos($styles, 'font-size: 0.84rem !important') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
