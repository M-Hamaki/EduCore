<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/class_lists.php');
$commands = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$enrollment = (string) file_get_contents($root . '/src/Modules/Students/StudentEnrollment.php');
$enrollmentService = (string) file_get_contents($root . '/src/Modules/Students/StudentEnrollmentService.php');

$checks = [
    'entrypoint_delegates_without_partial_legacy_writes' => strpos($page, 'applyClassTransfer(') !== false
        && strpos($page, 'UPDATE users SET class_id = ? WHERE id = ?') === false
        && strpos($page, 'UPDATE student_profiles SET grade_id = ? WHERE user_id = ?') === false
        && strpos($page, 'UPDATE student_enrollments SET class_id = ?') === false,
    'bulk_transfer_is_one_atomic_batch' => strpos($page, '$db->beginTransaction()') !== false
        && strpos($page, '$undoBatchId = UndoManager::newBatchId()') !== false
        && strpos($page, '$db->commit()') !== false
        && strpos($page, '$db->rollBack()') !== false,
    'service_locks_and_guards_current_enrollment' => strpos($commands, 'new AcademicYearWriteGuard($this->db)') !== false
        && strpos($commands, '->assertWritable($academicYearId)') !== false
        && strpos($commands, "enrollment_status = 'enrolled'") !== false
        && strpos($commands, 'LIMIT 1 FOR UPDATE') !== false
        && strpos($commands, 'assertManageableStudent($studentId)') !== false,
    'all_related_resources_share_batch' => strpos($commands, "'student_transfers',") !== false
        && substr_count($commands, '$batchId') >= 6
        && strpos($enrollment, '?string $batchId = null') !== false
        && strpos($enrollment, "'تحديث قيد الطالب السنوي', \$batchId") !== false
        && strpos($enrollmentService, '$batchId ?: bin2hex(random_bytes(16))') !== false,
    'database_failures_are_not_exposed' => strpos($page, '$e instanceof PDOException') !== false
        && strpos($page, 'لم تُحفظ أي تغييرات جزئية') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
