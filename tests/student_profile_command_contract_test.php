<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Students/StudentProfileCommandService.php');
$checks = [
    'transaction' => strpos($source, 'beginTransaction') !== false && strpos($source, 'commit()') !== false && strpos($source, 'rollBack()') !== false,
    'optimistic_lock' => strpos($source, "SELECT updated_at FROM student_profiles") !== false && strpos($source, 'record_version') !== false,
    'both_paths' => strpos($source, 'private function update(') !== false && strpos($source, 'private function create(') !== false,
    'owned_collaborators' => strpos($source, '$this->mapper->') !== false && strpos($source, '$this->guardians->') !== false && strpos($source, '$this->lifecycle->') !== false,
    'audit_and_undo' => strpos($source, 'ActivityLog::logCreate') !== false
        && strpos($source, 'ActivityLog::logUpdate') !== false
        && strpos($source, 'recordCompositeUpdate(') !== false,
    'redirect_result' => strpos($source, "'saved_base_page'") !== false && strpos($source, "'message'") !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
