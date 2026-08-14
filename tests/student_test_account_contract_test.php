<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mapper = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileRequestMapper.php');
$command = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$store = (string) file_get_contents($root . '/classes/UserProfileStore.php');
$form = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$scripts = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_scripts.php');
$accounts = (string) file_get_contents($root . '/admin/student_accounts.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentAccountClassificationService.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260719_student_test_account_ownership.php');
$rollover = (string) file_get_contents($root . '/classes/NewYearRolloverService.php');

$checks = [
    'profile_request_does_not_own_classification' => strpos($mapper, 'is_test_account') === false,
    'profile_store_does_not_own_classification' => strpos($store, 'is_test_account') === false,
    'profile_ui_has_no_classification_control' => strpos($form, 'is_test_account') === false
        && strpos($scripts, 'is_test_account') === false,
    'persisted_account_controls_missing_grade_exception' => strpos($command, "FROM users") !== false
        && strpos($command, "COALESCE(is_test_account, 0)") !== false
        && strpos($command, 'اختيار الصف إلزامي') !== false,
    'accounts_page_is_only_management_ui' => strpos($accounts, 'set_test_account') !== false
        && strpos($accounts, 'openTestAccountModal') !== false
        && strpos($accounts, 'حساب تجريبي') !== false,
    'classification_write_is_transactional_and_audited' => strpos($service, 'beginTransaction') !== false
        && strpos($service, "UPDATE users SET is_test_account") !== false
        && strpos($service, 'recordUpdate') !== false,
    'migration_copies_verifies_then_drops_profile_field' => strpos($migration, 'SET u.is_test_account = 1') !== false
        && strpos($migration, '$unmigrated') !== false
        && strpos($migration, 'DROP COLUMN is_test_account') !== false,
    'rollover_reads_account_classification' => strpos($rollover, 'COALESCE(u.is_test_account, 0) AS is_test_account') !== false
        && strpos($rollover, "['users', 'is_test_account']") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
