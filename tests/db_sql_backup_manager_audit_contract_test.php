<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/classes/DbSqlBackupManager.php');
$start = strpos($source, 'private static function storeSchedulerOutcome');
$end = strpos($source, 'private static function setLastStatus', $start ?: 0);
$body = ($start !== false && $end !== false) ? substr($source, $start, $end - $start) : '';

$checks = [
    'enable_and_disable_share_outcome_owner' => substr_count($source, 'self::storeSchedulerOutcome(') === 2,
    'scheduler_status_and_audit_are_atomic' => strpos($body, 'beginTransaction()') !== false
        && strpos($body, 'self::setLastStatus(') !== false
        && strpos($body, 'recordEvent(') !== false
        && strpos($body, 'commit()') !== false
        && strpos($body, 'rollBack()') !== false,
    'external_operation_is_explicitly_non_undoable' => strpos($body, "'external_effect' => true") !== false
        && strpos($body, "'direct_undo_available' => false") !== false,
    'audit_excludes_command_and_raw_output' => strpos($body, '$cmd') === false
        && strpos($body, "'status' =>") === false
        && strpos($body, "'output' =>") === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
