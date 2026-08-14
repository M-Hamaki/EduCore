<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pagePath = $root . '/admin/classes.php';
$source = (string) file_get_contents($pagePath);

$authPosition = strpos($source, "Utilities::validateSession('admin');");
$csrfGuardPosition = strpos($source, 'requireCsrfPost();');
$databasePosition = strpos($source, '$database = new Database();');
$postHandlerPosition = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");

$expectedActions = [
    "isset(\$_POST['add_class'])",
    "isset(\$_POST['edit_class'])",
    "\$_POST['action'] == 'delete_class'",
    "\$_POST['action'] == 'toggle_class_status'",
];

$allActionsPresent = true;
foreach ($expectedActions as $action) {
    if (strpos($source, $action) === false) {
        $allActionsPresent = false;
        break;
    }
}

$postForms = [];
preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>.*?<\/form>/is', $source, $postFormMatches);
$postForms = $postFormMatches[0] ?? [];
$localWriteFormCount = 0;
$tokenizedLocalWriteFormCount = 0;
foreach ($postForms as $formSource) {
    if (strpos($formSource, 'action="import_classes.php"') !== false) {
        continue;
    }
    $localWriteFormCount++;
    if (strpos($formSource, '<?php echo csrfField(); ?>') !== false) {
        $tokenizedLocalWriteFormCount++;
    }
}

$guardSafelyPrecedesDatabase = $csrfGuardPosition !== false
    && $databasePosition !== false
    && $csrfGuardPosition < $databasePosition;
$invalidTokenRejected = false;
if ($guardSafelyPrecedesDatabase) {
    $runnerPath = tempnam(sys_get_temp_dir(), 'educore_csrf_');
    if (is_string($runnerPath)) {
        $runnerSource = '<?php' . PHP_EOL
            . '$root = ' . var_export($root, true) . ';' . PHP_EOL
            . "require \$root . '/includes/session_config.php';" . PHP_EOL
            . "\$_SESSION['user_id'] = 1;" . PHP_EOL
            . "\$_SESSION['role'] = 'admin';" . PHP_EOL
            . "\$_SESSION['last_activity'] = time();" . PHP_EOL
            . "\$_SESSION['csrf_token'] = 'expected-token';" . PHP_EOL
            . "\$_SERVER['REQUEST_METHOD'] = 'POST';" . PHP_EOL
            . "\$_SERVER['REQUEST_URI'] = '/EduCore/admin/classes.php';" . PHP_EOL
            . "\$_POST = ['csrf_token' => 'invalid-token'];" . PHP_EOL
            . "register_shutdown_function(static function (): void { echo PHP_EOL . 'CSRF_STATUS=' . http_response_code(); });" . PHP_EOL
            . "requireCsrfPost();" . PHP_EOL
            . "echo 'GUARD_RETURNED';" . PHP_EOL;
        file_put_contents($runnerPath, $runnerSource);
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $status);
        unlink($runnerPath);
        $combinedOutput = implode(PHP_EOL, $output);
        $invalidTokenRejected = $status === 0
            && strpos($combinedOutput, 'CSRF_STATUS=419') !== false
            && strpos($combinedOutput, 'GUARD_RETURNED') === false;
    }
}

$checks = [
    'page_exists' => is_file($pagePath) && $source !== '',
    'auth_precedes_csrf_guard' => $authPosition !== false
        && $csrfGuardPosition !== false
        && $authPosition < $csrfGuardPosition,
    'csrf_guard_precedes_database_work' => $guardSafelyPrecedesDatabase,
    'csrf_guard_precedes_post_handler' => $csrfGuardPosition !== false
        && $postHandlerPosition !== false
        && $csrfGuardPosition < $postHandlerPosition,
    'all_class_write_actions_retained' => $allActionsPresent,
    'four_local_write_forms_have_tokens' => substr_count($source, '<?php echo csrfField(); ?>') === 4,
    'tokens_are_inside_each_local_write_form' => $localWriteFormCount === 4
        && $tokenizedLocalWriteFormCount === 4,
    'separate_import_token_retained' => strpos(
        $source,
        'value="<?php echo htmlspecialchars($_SESSION[\'csrf_token\']); ?>"'
    ) !== false,
    'invalid_token_helper_returns_419' => $invalidTokenRejected,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
