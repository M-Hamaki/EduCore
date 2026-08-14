<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$query = (string)file_get_contents($root . '/classes/AssessmentTeacherAssignmentListQuery.php');
$page = (string)file_get_contents($root . '/admin/assessment_teacher_assignments.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_assessment_teacher_assignments_datatable.php');
$styles = (string)file_get_contents($root . '/assets/css/admin-unified.css');

$checks = [
    'query_fetches_assignments_for_page_window_only' => strpos($query, 'assignmentsForStaff') !== false && strpos($query, 'teacher_id IN') !== false,
    'query_lists_teacher_membership_only' => substr_count($query, "ura.role_key = 'teacher'") >= 3
        && strpos($query, 'user_role_assignments') !== false,
    'query_supports_filters_and_bounded_length' => strpos($query, "'job_title'") !== false && strpos($query, "'stage_id'") !== false && strpos($query, 'min($requestedLength, 500)') !== false,
    'query_preserves_grade_to_class_groups' => strpos($query, 'g.grade_name') !== false
        && strpos($query, "'class_groups'") !== false,
    'query_loads_active_staff_roles_for_assignment_rows' => strpos($query, 'GROUP_CONCAT(role_key ORDER BY is_primary DESC') !== false
        && strpos($query, 'ura.role_keys') !== false,
    'endpoint_is_admin_and_csrf_protected' => strpos($endpoint, "validateSession('admin')") !== false && strpos($endpoint, 'requireCsrfPost()') !== false,
    'endpoint_renders_grouped_class_rows' => strpos($endpoint, 'teacher-assignment-class-group-title') !== false
        && strpos($endpoint, 'teacher-assignment-class-group-items') !== false
        && strpos($endpoint, 'fa-check-circle text-primary') !== false,
    'endpoint_compacts_class_column_by_default' => strpos($endpoint, 'teacher-assignment-class-dropdown') !== false
        && strpos($endpoint, '$classMenuModifier') !== false
        && strpos($endpoint, "'--single'") !== false
        && strpos($endpoint, "'--wide'") !== false
        && strpos($endpoint, 'teacher-assignment-subject-dropdown') !== false
        && strpos($endpoint, 'fa-book me-1') !== false
        && strpos($endpoint, 'teacher-assignment-class-summary-count') !== false
        && strpos($endpoint, 'teacher-assignment-class-summary-stages') !== false
        && strpos($endpoint, 'teacher-assignment-class-summary-grades') !== false
        && strpos($endpoint, 'teacher-assignment-class-summary-classes') !== false
        && strpos($endpoint, 'dropdown-menu') !== false
        && strpos($endpoint, 'teacher-assignment-class-summary-action') === false
        && strpos($endpoint, '<details') === false,
    'endpoint_renders_teacher_roles' => strpos($endpoint, 'teacher-assignment-role-cell') !== false
        && strpos($endpoint, 'roleLabels') !== false
        && strpos($endpoint, 'role_keys') !== false
        && strpos($endpoint, 'teacher-assignment-permission-cell') !== false,
    'grouped_class_styles_are_centralized' => strpos($styles, '.teacher-assignment-class-dropdown') !== false
        && strpos($styles, '.teacher-assignment-class-group-title') !== false
        && strpos($styles, '.teacher-assignment-class-group-copy') !== false
        && strpos($styles, '.teacher-assignment-class-summary-grades') !== false
        && strpos($styles, '.teacher-assignment-class-summary-stages') !== false
        && strpos($styles, '.teacher-assignment-class-summary-classes') !== false
        && strpos($styles, 'teacher-assignment-class-dropdown--single') !== false
        && strpos($styles, 'teacher-assignment-class-dropdown--wide') !== false
        && strpos($styles, 'teacher-assignment-subject-dropdown') !== false
        && strpos($styles, '.teacher-assignment-class-dropdown') !== false
        && strpos($styles, 'teacher-assignment-class-dropdown:hover .dropdown-menu') !== false
        && strpos($styles, '.teacher-assignment-role-cell') !== false,
    'page_uses_shared_server_table' => strpos($page, 'AdminServerSideTable.init') !== false && strpos($page, 'ajax_assessment_teacher_assignments_datatable.php') !== false,
    'page_places_roles_and_permissions' => strpos($page, "<th>المسمى الوظيفي</th>\n                        <th>الدور</th>") !== false
        && strpos($page, "<th>التعيينات</th>\n                        <th>صلاحيات الحساب</th>") !== false
        && strpos($page, "'col_roles' => 'الدور'") !== false
        && strpos($page, "'col_classes' => 'التعيينات'") !== false
        && strpos($page, "'col_permissions' => 'صلاحيات الحساب'") !== false
        && strpos($page, 'col_permissions: 7') !== false
        && strpos($page, 'col_actions: 8') !== false,
    'page_uses_delegated_assignment_actions' => strpos($page, "getElementById('teacherAssignmentsTable').addEventListener('click'") !== false,
    'page_does_not_render_all_staff_rows' => strpos($page, 'foreach ($staffRows as') === false,
    'save_handler_rejects_non_teacher_membership' => strpos($page, "ura.role_key = 'teacher'") !== false
        && strpos($page, 'user_role_assignments') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
