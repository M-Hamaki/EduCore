<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pagePath = $root . '/admin/staff_shifts.php';
$source = (string) file_get_contents($pagePath);
$presentationSource = (string) file_get_contents($root . '/admin/hr_policy_calendar.php');
$compatibilityStart = strpos($presentationSource, 'if ($staffShiftCompatibilityMode):');
$compatibilityEnd = strpos($presentationSource, "return; endif;", $compatibilityStart ?: 0);
$presentation = ($compatibilityStart !== false && $compatibilityEnd !== false)
    ? substr($presentationSource, $compatibilityStart, $compatibilityEnd - $compatibilityStart)
    : '';

$authPosition = strpos($source, "Utilities::validateSession('admin');");
$csrfGuardPosition = strpos($source, 'requireCsrfPost();');
$databasePosition = strpos($source, '$database = new Database();');
$postHandlerPosition = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");

preg_match_all('/<form\b[^>]*method=["\']POST["\'][^>]*>.*?<\/form>/is', $presentation, $matches);
$postForms = $matches[0] ?? [];
$tokenizedForms = array_filter(
    $postForms,
    static fn (string $form): bool => strpos($form, '<?php echo csrfField(); ?>') !== false
);

$checks = [
    'page_exists' => is_file($pagePath) && $source !== '',
    'csrf_helper_loaded' => strpos($source, "require_once '../includes/csrf.php';") !== false,
    'auth_precedes_csrf_guard' => $authPosition !== false
        && $csrfGuardPosition !== false
        && $authPosition < $csrfGuardPosition,
    'csrf_guard_precedes_database_work' => $csrfGuardPosition !== false
        && $databasePosition !== false
        && $csrfGuardPosition < $databasePosition,
    'csrf_guard_precedes_post_handler' => $csrfGuardPosition !== false
        && $postHandlerPosition !== false
        && $csrfGuardPosition < $postHandlerPosition,
    'three_post_forms_retained' => count($postForms) === 3,
    'every_post_form_has_token' => count($tokenizedForms) === 3,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
