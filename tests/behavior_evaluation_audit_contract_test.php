<?php

declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/src/Modules/BehaviorEvaluation/Evaluation.php');
$typeSource = (string)file_get_contents(dirname(__DIR__) . '/src/Modules/BehaviorEvaluation/EvaluationType.php');
$createStart = strpos($source, 'public function create()');
$createEnd = strpos($source, 'private function fetchEvaluationType', $createStart ?: 0);
$create = ($createStart !== false && $createEnd !== false)
    ? substr($source, $createStart, $createEnd - $createStart)
    : '';

$checks = [
    'evaluation_create_owns_or_joins_transaction' => strpos($create, '$ownsTransaction') !== false
        && strpos($create, 'beginTransaction()') !== false
        && strpos($create, '->commit()') !== false
        && substr_count($create, '->rollBack()') >= 4,
    'duplicate_check_is_locked_and_fail_closed' => strpos($create, 'LIMIT 1 FOR UPDATE') !== false
        && strpos($create, 'Duplicate check failed.') !== false,
    'evaluation_create_uses_central_undo_audit' => strpos($create, 'recordInsert(') !== false
        && strpos($create, "'evaluation', \$this->table_name") !== false
        && strpos($create, 'SELECT * FROM {$this->table_name} WHERE id = ?') !== false,
    'evaluation_update_is_locked_atomic_and_audited' => strpos($source, 'public function update()') !== false
        && substr_count($source, 'FOR UPDATE') >= 3
        && strpos($source, 'recordUpdate(') !== false,
    'evaluation_delete_is_locked_and_undoable' => strpos($source, 'public function delete()') !== false
        && strpos($source, 'recordDelete(') !== false
        && strpos($source, 'Evaluation delete did not affect one row.') !== false,
    'evaluation_bulk_delete_is_all_or_nothing_batch' => strpos($source, 'public function deleteAllForStudent()') !== false
        && strpos($source, 'Evaluation bulk delete count mismatch.') !== false
        && strpos($source, '$batchId = bin2hex(random_bytes(16))') !== false
        && strpos($source, "'حذف جميع تقييمات الطالب', \$batchId") !== false,
    'evaluation_type_crud_is_centrally_audited' => strpos($typeSource, 'recordInsert(') !== false
        && strpos($typeSource, 'recordUpdate(') !== false
        && strpos($typeSource, 'recordDelete(') !== false,
    'evaluation_type_update_delete_are_locked' => substr_count($typeSource, 'FOR UPDATE') >= 2
        && substr_count($typeSource, 'beginTransaction()') >= 3
        && substr_count($typeSource, '->rollBack()') >= 6,
    'evaluation_type_duplicate_message_is_preserved' => strpos($typeSource, 'اسم نوع التقييم موجود مسبقاً') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
