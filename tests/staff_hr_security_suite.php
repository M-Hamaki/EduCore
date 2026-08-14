<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'idor_and_self_scope' => 'tests/staff_hr_permission_portal_contract_test.php',
    'approval_scope_and_session_expiry' => 'tests/staff_hr_approval_authorization_contract_test.php',
    'csrf_before_approval_write' => 'tests/staff_hr_approval_admin_contract_test.php',
    'confidential_ertaq_scope' => 'tests/staff_hr_ertaq_ui_contract_test.php',
    'private_ertaq_files' => 'tests/staff_hr_ertaq_security_contract_test.php',
    'private_leave_files' => 'tests/staff_hr_leave_attachment_integration_test.php',
    'scoped_formula_safe_export' => 'tests/staff_hr_attendance_export_contract_test.php',
];

$run = static function (string $file) use ($root): array {
    $process = proc_open(
        [PHP_BINARY, $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'SECURITY_SUITE_PROCESS_START_FAILED'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};

$failures = [];
foreach ($tests as $boundary => $file) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))) {
        $failures[$boundary] = 'SECURITY_SUITE_TEST_MISSING:' . $file;
        continue;
    }
    $result = $run($file);
    echo '[' . $boundary . '] ' . basename($file) . PHP_EOL;
    if ($result['stdout'] !== '') {
        echo rtrim($result['stdout']) . PHP_EOL;
    }
    if ($result['exit_code'] !== 0) {
        $failures[$boundary] = trim($result['stderr']) ?: 'SECURITY_SUITE_CHILD_FAILED';
    }
}

// The global search projection may expose the basic Staff directory only. HR
// requests, medical/discipline evidence, Ertaq subjects/messages, and audit
// payloads must never become a search source even for an administrator.
$searchRepository = (string) file_get_contents(
    $root . '/src/Modules/Search/Infrastructure/PdoGlobalSearchReadRepository.php'
);
$forbiddenSearchSources = [
    'staff_ertaq_',
    'staff_leave_attachments',
    'staff_discipline_',
    'staff_approval_',
    'activity_logs',
    'undo_log',
];
foreach ($forbiddenSearchSources as $source) {
    if (stripos($searchRepository, $source) !== false) {
        $failures['sensitive_global_search'] = 'SECURITY_SUITE_SENSITIVE_SEARCH_SOURCE:' . $source;
    }
}
if (!str_contains($searchRepository, 'INNER JOIN staff_profiles')) {
    $failures['sensitive_global_search'] = 'SECURITY_SUITE_REVIEWED_STAFF_DIRECTORY_MISSING';
}

$login = (string) file_get_contents($root . '/login.php');
$loginPortal = (string) file_get_contents($root . '/includes/public_login_portal.php');
if (!str_contains($login, 'Cache-Control: no-store')
    || !str_contains($login, 'requireCsrfPost()')
    || !str_contains($loginPortal, 'name="csrf_token"')) {
    $failures['login_session_boundary'] = 'SECURITY_SUITE_LOGIN_CACHE_OR_CSRF_MISSING';
}

echo 'STAFF_HR_SECURITY_BOUNDARIES=' . (count($tests) + 2)
    . ' FAILED=' . count($failures) . PHP_EOL;
if ($failures !== []) {
    foreach ($failures as $boundary => $message) {
        fwrite(STDERR, $boundary . ':' . $message . PHP_EOL);
    }
    exit(1);
}

echo "Staff-HR security suite passed.\n";
