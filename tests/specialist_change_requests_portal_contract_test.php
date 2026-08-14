<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/specialist_requests.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentChangeRequestService.php');
$utilities = (string) file_get_contents($root . '/classes/utilities.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$dashboard = (string) file_get_contents($root . '/admin/specialist_dashboard.php');

$authPosition = strpos($page, "Utilities::validateSession('admin')");
$databasePosition = strpos($page, '$db = (new Database())->getConnection();');

$checks = [
    'page_authenticates_before_database_access' => $authPosition !== false
        && $databasePosition !== false
        && $authPosition < $databasePosition,
    'page_is_specialist_only' => strpos($page, "\$_SESSION['role'] ?? '') !== 'specialist'") !== false,
    'page_uses_active_year_only' => strpos($page, 'AcademicYear::getCurrent($db)') !== false
        && strpos($page, 'switch_academic_year') === false,
    'query_is_owned_by_student_change_service' => strpos($page, 'listForSpecialist($specialistId, $academicYearId') !== false
        && strpos($page, 'FROM student_change_requests') === false,
    'service_filters_owner_and_year' => strpos($service, 'public function listForSpecialist(') !== false
        && strpos($service, 'WHERE scr.specialist_id = ? AND scr.academic_year_id = ?') !== false,
    'sidebar_pending_badge_uses_scoped_count' => strpos($service, 'public static function pendingCount(') !== false
        && strpos($header, 'StudentChangeRequestService::pendingCount(') !== false
        && strpos($header, "\$__sessionRole === 'specialist'") !== false
        && strpos($header, '$_currentAcademicYearId') !== false
        && strpos($header, 'aria-label="طلبات قيد المراجعة"') !== false,
    'visible_request_numbers_are_sequential' => strpos($page, '$requestRowNumber = 1;') !== false
        && strpos($page, '<td><?php echo $requestRowNumber++; ?></td>') !== false
        && strpos($page, "<td><?php echo (int) (\$row['id'] ?? 0); ?></td>") === false,
    'page_is_read_only' => strpos($page, "REQUEST_METHOD") === false
        && strpos($page, 'approve_request') === false
        && strpos($page, 'reject_request') === false
        && strpos($page, '<form method="post"') === false,
    'page_shows_human_diffs_and_review_reason' => strpos($page, 'StudentChangeRequestPresenter') !== false
        && strpos($page, "rejection_reason") !== false
        && strpos($page, "reviewer_name") !== false
        && strpos($page, "reviewed_at") !== false,
    'page_uses_shared_admin_ui_without_local_css' => strpos($page, "require_once '../includes/admin_header.php';") !== false
        && strpos($page, "require_once '../includes/admin_footer.php';") !== false
        && strpos($page, 'class="admin-filter-bar"') !== false
        && strpos($page, 'class="admin-list-surface"') !== false
        && strpos($page, '<style>') === false,
    'specialist_receives_mandatory_route' => strpos($utilities, "\$pages[] = 'specialist_requests.php';") !== false,
    'sidebar_exposes_requests_instead_of_admin_review' => strpos($header, "\$_SESSION['role'] ?? '') === 'specialist'") !== false
        && strpos($header, 'href="specialist_requests.php"') !== false
        && strpos($header, '>طلباتي<span class="badge') !== false,
    'dashboard_exposes_requests_service' => strpos($dashboard, "['specialist_requests.php', 'fa-paper-plane', 'طلباتي'") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
