<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expectedForms = [
    'admin/activities_monitor.php' => 2,
    'admin/ai_settings.php' => 3,
    'admin/external_teachers.php' => 5,
    'admin/grades.php' => 5,
    'admin/notifications.php' => 9,
    'admin/profile.php' => 2,
    'admin/stages.php' => 5,
    'admin/student_buses.php' => 1,
];

$checks = [];
foreach ($expectedForms as $relativePath => $expectedCount) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    $auth = strpos($source, "Utilities::validateSession('admin');");
    $guard = strpos($source, 'requireCsrfPost();');
    $database = strpos($source, '$database = new Database();');
    preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>.*?<\/form>/is', $source, $matches);
    $forms = $matches[0] ?? [];
    $tokenized = array_filter(
        $forms,
        static fn (string $form): bool => strpos($form, '<?php echo csrfField(); ?>') !== false
            || strpos($form, 'name="csrf_token"') !== false
    );
    $key = str_replace(['/', '.php'], ['_', ''], $relativePath);
    $checks[$key . '_guard_order'] = $auth !== false && $guard !== false && $database !== false
        && $auth < $guard && $guard < $database;
    $checks[$key . '_forms_tokenized'] = count($forms) === $expectedCount
        && count($tokenized) === $expectedCount;
}

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
