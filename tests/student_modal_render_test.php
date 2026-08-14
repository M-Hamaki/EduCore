<?php

require_once __DIR__ . '/bootstrap_test_database.php';

$mode = $argv[1] ?? 'list';
$db = educoreTestDatabase();
$studentId = 0;
if ($mode === 'edit' || $mode === 'view') {
    $studentId = (int) $db->query("SELECT id FROM users WHERE role = 'student' ORDER BY id LIMIT 1")->fetchColumn();
    if ($studentId <= 0) {
        echo "SKIP: no student available for " . $mode . " render" . PHP_EOL;
        exit(0);
    }
}

session_id('student-modal-render-' . $mode);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['name'] = 'Render Test Admin';
$_SESSION['role'] = 'admin';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = str_repeat('a', 64);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/EduCore/admin/students.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/EduCore/admin/students.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_GET = [];
if ($mode === 'add') {
    $_GET['action'] = 'add';
} elseif ($mode === 'edit') {
    $_GET['action'] = 'edit';
    $_GET['id'] = $studentId;
} elseif ($mode === 'view') {
    $_GET['action'] = 'view';
    $_GET['id'] = $studentId;
}

chdir(__DIR__ . '/../admin');
ob_start();
require __DIR__ . '/../admin/students.php';
$html = ob_get_clean();
$bulkFormHtml = '';
preg_match('~<form[^>]*id="bulkAddStudentsForm"[^>]*>.*?</form>~s', $html, $bulkFormMatch);
$bulkFormHtml = $bulkFormMatch[0] ?? '';
$importFormHtml = '';
preg_match('~<form[^>]*enctype="multipart/form-data"[^>]*>.*?</form>~s', $html, $importFormMatch);
$importFormHtml = $importFormMatch[0] ?? '';
$archiveFormHtml = '';
preg_match('~<div[^>]*id="archiveStudentModal"[^>]*>.*?<form[^>]*>.*?</form>~s', $html, $archiveFormMatch);
$archiveFormHtml = $archiveFormMatch[0] ?? '';

$expectations = [
    'list_surface' => substr_count($html, 'class="admin-list-surface"') > 0,
    'bulk_button' => strpos($html, 'data-bs-target="#bulkAddStudentsModal"') !== false,
    'bulk_modal_once' => substr_count($html, 'id="bulkAddStudentsModal"') === 1,
    'bulk_csrf_present' => strpos($bulkFormHtml, 'name="csrf_token"') !== false,
    'import_csrf_present' => strpos($importFormHtml, 'name="csrf_token"') !== false,
    'archive_csrf_present' => strpos($archiveFormHtml, 'name="archive_student"') !== false
        && strpos($archiveFormHtml, 'name="csrf_token"') !== false,
    'account_controls_absent' => strpos($html, 'toggleStudentStatusModal') === false
        && strpos($html, 'toggle-student') === false,
    'credential_fields_absent' => strpos($html, 'name="username"') === false
        && strpos($html, 'name="password"') === false,
    'current_age_column_and_setting_present' => strpos($html, 'class="col-current-age d-none">العمر الحالي</th>') !== false
        && strpos($html, 'id="chk_current_age"') !== false,
    'legacy_quick_actions_absent' => strpos($html, 'name="add_student"') === false
        && strpos($html, 'name="edit_student"') === false,
    'no_session_tab_restore' => strpos($html, 'student_active_tab') === false,
];

if ($mode === 'list' || $mode === 'view') {
    $expectations['profile_modal_absent'] = substr_count($html, 'id="studentProfileModal"') === 0;
    if ($mode === 'view') {
        $expectations['profile_view_present'] = strpos($html, 'الملف الشخصي للطالب') !== false;
        $expectations['profile_view_edit_link_present'] = strpos($html, 'action=edit&amp;id=' . $studentId) !== false
            || strpos($html, 'action=edit&id=' . $studentId) !== false;
    }
} else {
    preg_match('~<form[^>]*id="studentProfileForm"[^>]*>.*?</form>~s', $html, $profileFormMatch);
    $profileFormHtml = $profileFormMatch[0] ?? '';
    $expectations['profile_modal_once'] = substr_count($html, 'id="studentProfileModal"') === 1;
    $expectations['profile_form_once'] = substr_count($html, 'id="studentProfileForm"') === 1;
    $expectations['six_profile_tabs'] = substr_count($html, 'data-bs-target="#pane-') === 6;
    $expectations['tab_scroll_container_once'] = substr_count($html, 'class="student-profile-tab-scroll flex-grow-1 overflow-auto"') === 1;
    $expectations['profile_csrf_present'] = strpos($profileFormHtml, 'name="csrf_token"') !== false;
    $expectations['current_age_field_present'] = strpos($profileFormHtml, 'id="current_age_display"') !== false;
    $expectations['default_tab_is_basic'] = preg_match('~id="active_tab_input"\s+value="basic"~', $profileFormHtml) === 1;
    $expectedClass = $mode === 'edit' ? 'admin-modal-edit' : 'admin-modal-create';
    $expectations['operation_class'] = strpos($html, $expectedClass) !== false;
}

$failed = array_keys(array_filter($expectations, static function ($passed) {
    return !$passed;
}));

foreach ($expectations as $name => $passed) {
    echo $mode . ':' . $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit($failed ? 1 : 0);
