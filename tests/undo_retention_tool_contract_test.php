<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/tools/cleanup_undo_retention.php');
$checks = [
    'retention_cleanup_is_cli_only' => strpos($source, "PHP_SAPI !== 'cli'") !== false,
    'retention_cleanup_defaults_to_dry_run' => strpos($source, "\$apply = in_array('--apply'") !== false
        && strpos($source, "if (!\$apply) exit(0)") !== false,
    'apply_requires_exact_database' => strpos($source, '--database=<exact connected database>') !== false
        && strpos($source, '$databaseArgument !== $connected') !== false,
    'cleanup_is_transactional' => strpos($source, '$db->beginTransaction()') !== false
        && strpos($source, '$db->rollBack()') !== false,
    'retention_and_per_user_cap_are_enforced' => strpos($source, 'UndoManager::retentionHours()') !== false
        && strpos($source, 'HAVING COUNT(*) > 500') !== false,
];
foreach ($checks as $name => $passed) echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
