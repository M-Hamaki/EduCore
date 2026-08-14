<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$report = (string)file_get_contents($root . '/admin/evaluation_reports.php');
$reset = (string)file_get_contents($root . '/admin/reset_points.php');
$manage = (string)file_get_contents($root . '/admin/manage_backups.php');
$sources = $report . PHP_EOL . $reset . PHP_EOL . $manage;

$beforeDatabase = static function (string $source, string $needle): bool {
    $position = strpos($source, $needle);
    $database = strpos($source, '$database = new Database();');
    return $position !== false && $database !== false && $position < $database;
};

$checks = [
    'pages_use_backup_service' => substr_count($sources, 'EvaluationBackupService') >= 6,
    'pages_have_no_runtime_ddl' => !preg_match('/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TABLE|EVENT|PROCEDURE)\b/i', $sources),
    'report_auth_before_database' => $beforeDatabase($report, "Utilities::validateSession('admin');"),
    'report_csrf_before_database' => $beforeDatabase($report, 'requireCsrfPost();'),
    'reset_auth_before_database' => $beforeDatabase($reset, "Utilities::validateSession('super_admin');"),
    'reset_csrf_before_database' => $beforeDatabase($reset, 'requireCsrfPost();'),
    'reset_form_has_csrf' => strpos($reset, '<?php echo csrfField(); ?>') !== false,
    'manage_auth_before_database' => $beforeDatabase($manage, "Utilities::validateSession('admin');"),
    'manage_forms_keep_csrf' => substr_count($manage, 'name="csrf_token"') === 2,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
