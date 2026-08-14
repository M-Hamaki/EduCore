<?php

require_once __DIR__ . '/bootstrap_test_database.php';

$mode = $argv[1] ?? 'list';
$db = educoreTestDatabase();
$staffId = 0;
if ($mode === 'edit') {
    $staffId = (int) $db->query(
        "SELECT u.id
         FROM users u
         INNER JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE u.role IS NULL OR u.role NOT IN ('admin', 'student')
         ORDER BY u.id
         LIMIT 1"
    )->fetchColumn();
    if ($staffId <= 0) {
        echo "SKIP: no staff profile available for edit render" . PHP_EOL;
        exit(0);
    }
}

session_id('staff-modal-render-' . $mode);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['name'] = 'Render Test Admin';
$_SESSION['role'] = 'admin';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = str_repeat('b', 64);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/EduCore/admin/staff.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/EduCore/admin/staff.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_GET = [];
if ($mode === 'add') {
    $_GET['action'] = 'add';
} elseif ($mode === 'edit') {
    $_GET['action'] = 'edit';
    $_GET['id'] = $staffId;
}

chdir(__DIR__ . '/../admin');
ob_start();
require __DIR__ . '/../admin/staff.php';
$html = ob_get_clean();

$expectations = [
    'list_or_empty_state_present' => strpos($html, 'id="staffTable"') !== false
        || strpos($html, 'لا يوجد موظفين') !== false,
    'add_button_present' => strpos($html, 'staff.php?action=add') !== false,
    'detailed_import_template_present' => strpos($html, 'download_profile_template=staff') !== false
        && strpos($html, 'الحالات والحركات الوظيفية') !== false,
    'credential_fields_absent' => strpos($html, 'name="username"') === false
        && strpos($html, 'name="password"') === false,
    'financial_controls_absent' => strpos($html, 'basic_salary') === false
        && strpos($html, 'advances_data') === false
        && strpos($html, 'calc_net_salary') === false,
    'related_finance_leave_summary_absent' => strpos(
        $html,
        'بيانات مرتبطة بملف العامل'
    ) === false
        && strpos($html, 'finance_staff_contracts.php?staff_id=') === false
        && strpos($html, 'leave_balances.php?role=all') === false,
    'no_session_tab_restore' => strpos($html, 'staff_active_tab') === false,
    'profile_script_has_no_php_signature' => strpos($html, 'function assert_staff_target(PDO') === false,
];

if ($mode === 'list') {
    $expectations['profile_modal_absent'] = substr_count($html, 'id="staffProfileModal"') === 0;
} else {
    preg_match('~<form[^>]*id="staffForm"[^>]*>.*?</form>~s', $html, $profileFormMatch);
    $profileFormHtml = $profileFormMatch[0] ?? '';
    $expectations['profile_modal_once'] = substr_count($html, 'id="staffProfileModal"') === 1;
    $expectations['profile_form_once'] = substr_count($html, 'id="staffForm"') === 1;
    $expectations['profile_modal_auto_show_present'] = strpos(
        $html,
        'staffProfileModalInstance.show();'
    ) !== false;
    $expectations['five_profile_tabs'] = substr_count($profileFormHtml, 'data-bs-target="#pane-') === 5;
    $expectations['tab_scroll_container_once'] = substr_count($html, 'class="staff-profile-tab-scroll flex-grow-1 overflow-auto"') === 1;
    $expectations['profile_csrf_present'] = strpos($profileFormHtml, 'name="csrf_token"') !== false;
    $expectations['header_admin_note_present'] = strpos($profileFormHtml, 'id="staff_admin_notes"') !== false
        && strpos($profileFormHtml, 'name="admin_notes"') !== false;
    $expectations['default_tab_is_basic'] = preg_match('~id="active_tab_input"\s+value="basic"~', $profileFormHtml) === 1;
    $expectations['account_controls_absent'] = strpos($profileFormHtml, 'view_staff_password') === false
        && strpos($profileFormHtml, 'revealUserPassword') === false;
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
