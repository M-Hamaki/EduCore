<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expectations = [
    'admin/biometric_devices.php' => 4,
    'admin/timetable.php' => 6,
];

$checks = [];
foreach ($expectations as $relativePath => $expectedForms) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    $auth = strpos($source, "Utilities::validateSession('admin');");
    $csrf = strpos($source, 'requireCsrfPost();');
    $database = strpos($source, '$database = new Database();');
    preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>.*?<\/form>/is', $source, $matches);
    $forms = $matches[0] ?? [];
    $tokenized = array_filter(
        $forms,
        static fn (string $form): bool => strpos($form, '<?php echo csrfField(); ?>') !== false
    );

    $prefix = str_replace(['/', '.php'], ['_', ''], $relativePath);
    $checks[$prefix . '_auth_csrf_before_database'] = $auth !== false
        && $csrf !== false
        && $database !== false
        && $auth < $csrf
        && $csrf < $database;
    $checks[$prefix . '_all_post_forms_tokenized'] = count($forms) === $expectedForms
        && count($tokenized) === $expectedForms;
}

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
