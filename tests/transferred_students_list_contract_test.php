<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/transferred_students.php');
$endpoint = (string) file_get_contents($root . '/admin/ajax_derived_students_datatable.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/DerivedStudentListDataTableQuery.php');

$checks = [
    'normal_list_request_is_independent_from_students_entrypoint' => strpos($page, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')") !== false
        && strpos($page, "require __DIR__ . '/students.php'") > strpos($page, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')")
        && strpos($page, '$db = (new Database())->getConnection();') > strpos($page, "require __DIR__ . '/students.php'"),
    'legacy_profile_routes_remain_compatible' => strpos($page, "['add', 'edit', 'view']") !== false
        && strpos($page, "\$profileParams['student_scope'] = 'transferred';") !== false
        && strpos($page, "header('Location: students.php?'") !== false,
    'admin_auth_precedes_database_queries' => strpos($page, "Utilities::validateSession('admin');") !== false
        && strpos($page, "Utilities::validateSession('admin');") < strpos($page, '$db = (new Database())->getConnection();'),
    'legacy_post_adapter_is_csrf_protected' => strpos($page, 'requireCsrfPost();') !== false
        && strpos($page, 'requireCsrfPost();') < strpos($page, "require __DIR__ . '/students.php'"),
    'page_uses_transferred_server_side_list' => strpos($page, "list: 'transferred'") !== false
        && strpos($page, "url: 'ajax_derived_students_datatable.php'") !== false,
    'page_keeps_transfer_identity_and_fields' => strpos($page, 'المنقولون من المدرسة') !== false
        && strpos($page, 'الجهة المنقول إليها') !== false
        && strpos($page, 'تاريخ النقل') !== false,
    'endpoint_is_authenticated_and_csrf_protected' => strpos($endpoint, "Utilities::validateSession('admin');") !== false
        && strpos($endpoint, 'requireCsrfPost();') !== false,
    'endpoint_allowlists_transferred_list' => strpos($endpoint, "\$list === 'transferred'") !== false
        && strpos($endpoint, 'loadTransferredStudents($_POST, AcademicYear::currentId($db))') !== false,
    'query_filters_current_year_transferred_enrollments' => strpos($query, "se.enrollment_status = 'transferred'") !== false
        && strpos($query, "'se.academic_year_id = ?'") !== false,
    'query_joins_external_transfer_record' => strpos($query, 'student_external_transfers setr ON setr.student_id = u.id') !== false,
    'query_supports_transfer_destination_filter' => strpos($query, "'setr.destination LIKE ?'") !== false,
    'edit_action_keeps_shared_profile_owner_and_scope' => strpos($query, "students.php?action=edit&id=") !== false
        && strpos($query, "&student_scope=transferred") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Transferred students list contract failures:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Transferred students list contracts passed.\n";
