<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/LessonPptTemplateLibrary.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$deleteStart = strpos($source, 'public function delete');
$chooseStart = strpos($source, 'public function chooseBestTemplate', $deleteStart ?: 0);
$deleteBody = ($deleteStart !== false && $chooseStart !== false) ? substr($source, $deleteStart, $chooseStart - $deleteStart) : '';

$checks = [
    'template_table_is_registered' => strpos($policy, "'lesson_ppt_templates'") !== false,
    'template_file_delete_blocks_direct_undo' => preg_match("/NON_RESTORABLE_DELETE_TABLES[\\s\\S]*'lesson_ppt_templates'/", $policy) === 1,
    'save_is_locked_atomic_and_audited' => strpos($source, 'lesson_ppt_templates WHERE id = ? FOR UPDATE') !== false
        && strpos($source, "recordInsert('lesson_ppt_template'") !== false
        && strpos($source, "recordUpdate('lesson_ppt_template'") !== false,
    'delete_audit_commits_before_file_cleanup' => strpos($deleteBody, 'recordDelete(') !== false
        && strpos($deleteBody, 'commit()') < strpos($deleteBody, '@unlink'),
    'write_failures_roll_back' => substr_count($source, 'rollBack()') >= 2,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
