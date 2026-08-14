<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Modules/Staff/StaffAcademicScopeService.php');
$adapter = (string)file_get_contents($root . '/src/Modules/Staff/SpecialistAcademicScopeService.php');
$migration = (string)file_get_contents($root . '/database/migrations/20260719_specialist_academic_scope.php');
$genericMigration = (string)file_get_contents($root . '/database/migrations/20260719_scoped_staff_portals.php');
$accounts = (string)file_get_contents($root . '/admin/staff_accounts.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_staff_scope.php');
$retiredEndpoint = (string)file_get_contents($root . '/admin/ajax_specialist_scope.php');
$policy = (string)file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'scope_is_annual_and_supports_grades_and_classes' => strpos($migration, 'specialist_grade_assignments') !== false
        && strpos($migration, 'specialist_class_assignments') !== false
        && strpos($migration, 'academic_year_id') !== false,
    'legacy_assignments_are_migrated_once' => strpos($migration, 'specialist_classes') !== false
        && strpos($migration, 'INSERT IGNORE INTO specialist_class_assignments') !== false,
    'legacy_reads_use_annual_compatibility_view' => strpos($migration, 'specialist_active_classes') !== false,
    'generic_service_is_limited_to_scoped_roles' => strpos($service, "['specialist', 'doctor', 'librarian']") !== false
        && strpos($service, 'roleRequiresScope') !== false,
    'scope_unions_grade_and_direct_classes' => strpos($service, 'allowedClassIds') !== false
        && strpos($service, 'grade_id IN') !== false,
    'specialist_service_is_compatibility_only' => strpos($adapter, 'extends StaffAcademicScopeService') !== false,
    'replacement_is_audited' => strpos($service, 'recordReplacement') !== false,
    'generic_scope_is_backfilled_without_activating_pages' => strpos($genericMigration, 'staff_grade_assignments') !== false
        && strpos($genericMigration, 'staff_class_assignments') !== false
        && strpos($genericMigration, 'staff_role_pages') === false,
    'new_tables_have_audit_policies' => strpos($policy, "'staff_grade_assignments'") !== false
        && strpos($policy, "'staff_class_assignments'") !== false,
    'admin_endpoint_is_protected' => strpos($endpoint, "validateSession('admin')") !== false
        && strpos($endpoint, 'requireCsrfPost()') !== false,
    'scope_is_embedded_in_role_access_modal' => strpos($accounts, 'id="roleAccessModal"') !== false
        && strpos($accounts, 'id="staffScopeSection"') !== false
        && strpos($accounts, 'name="action" value="update_role_access"') !== false
        && strpos($accounts, 'specialistScopeModal') === false,
    'role_access_save_owns_per_role_scope_write' => strpos($accounts, 'replaceAssignments(') !== false
        && strpos($accounts, 'removeRoleAssignments(') !== false
        && strpos($accounts, "scopes[' + roleKey + '][grade_ids][]") !== false
        && strpos($accounts, "scopes[' + roleKey + '][class_ids][]") !== false,
    'ajax_endpoint_is_read_only_lookup' => strpos($endpoint, "(\$_POST['action'] ?? 'get') !== 'get'") !== false
        && strpos($endpoint, 'replaceAssignments(') === false,
    'old_specialist_endpoint_is_retired' => strpos($retiredEndpoint, 'http_response_code(410)') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
