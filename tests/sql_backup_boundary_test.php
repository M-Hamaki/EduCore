<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manager = (string) file_get_contents($root . '/classes/DbSqlBackupManager.php');
$tool = (string) file_get_contents($root . '/tools/backup_db_sql.php');
$page = (string) file_get_contents($root . '/admin/sql_backups.php');
$toolsBoundary = (string) file_get_contents($root . '/tools/.htaccess');

$results = [
    'scheduler_targets_internal_cli_tool' => strpos(
        $manager,
        "'tools' . DIRECTORY_SEPARATOR . 'backup_db_sql.php'"
    ) !== false,
    'scheduler_refuses_missing_tool' => strpos($manager, "throw new RuntimeException('SQL backup CLI script is missing.')") !== false,
    'tool_is_cli_only' => strpos($tool, "PHP_SAPI !== 'cli'") !== false && strpos($tool, "http_response_code(403)") !== false,
    'tool_uses_argument_array' => strpos($tool, 'proc_open($cmd,') !== false
        && strpos($tool, '$cmd = array_merge([$mysqldump], $args);') !== false,
    'database_password_uses_child_environment' => strpos($tool, "\$env['MYSQL_PWD']") !== false,
    'default_dump_is_private_storage' => substr_count($page, "'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'sql'") === 2,
    'tools_http_boundary_remains_denied' => strpos($toolsBoundary, 'Require all denied') !== false,
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
