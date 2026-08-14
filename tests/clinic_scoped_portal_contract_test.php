<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/admin/student_clinic.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_clinic_datatable.php');
$query = (string)file_get_contents($root . '/classes/ClinicListDataTableQuery.php');
$activation = (string)file_get_contents($root . '/database/migrations/20260719_doctor_clinic_portal_activation.php');

$checks = [
    'admin_page_uses_shared_scope_context' => strpos($page, 'ScopedStaffPortalContext.php') !== false
        && strpos($page, '$portalContext->allowedClassIds()') !== false,
    'all_clinic_writes_assert_scope' => substr_count($page, '$portalContext->assertStudentAllowed(') >= 3
        && substr_count($page, '$assertVisitAllowed(') >= 2,
    'student_and_filter_lists_are_scoped' => strpos($page, '$studentClassColumn') !== false
        && strpos($page, 'array_fill_keys($allowedClassIds') !== false,
    'datatable_queries_fail_closed_on_empty_scope' => strpos($query, "if (\$allowedClassIds === [])") !== false
        && strpos($query, "\$where[] = '1 = 0'") !== false,
    'health_and_visits_share_scope_constraint' => substr_count($query, 'appendClassScope(') >= 3,
    'specialist_is_visits_only_read_only' => strpos($page, '$activeTab = \'visits\';') !== false
        && strpos($page, 'هذا الحساب مخول بعرض سجل الزيارات فقط') !== false
        && strpos($endpoint, "\$type === 'health' && \$portalContext->role() === 'specialist'") !== false,
    'datatable_actions_follow_manage_permission' => strpos($query, 'bool $canManage = true') !== false
        && strpos($endpoint, "\$canManage = \$portalContext->role() !== 'specialist'") !== false,
    'endpoint_keeps_admin_and_csrf_gates' => strpos($endpoint, "validateSession('admin')") !== false
        && strpos($endpoint, 'requireCsrfPost()') !== false,
    'doctor_activation_includes_page_and_endpoint' => strpos($activation, "'student_clinic.php'") !== false
        && strpos($activation, "'ajax_clinic_datatable.php'") !== false
        && strpos($activation, "['doctor', \$page]") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
