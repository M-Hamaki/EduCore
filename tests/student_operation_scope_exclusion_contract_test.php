<?php

declare(strict_types=1);

function studentScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$query = file_get_contents(__DIR__ . '/../src/Modules/Students/StudentOperationLogQuery.php');
studentScopeAssert(is_string($query), 'Student operation query must be readable.');

foreach (['student_account', 'users', 'student_mark', 'student_mark_class_move', 'assessment_student_lock', 'evaluation', 'evaluation_type', 'student_evaluation'] as $type) {
    studentScopeAssert(strpos($query, "'{$type}'") !== false, "Excluded target type {$type} must be declared.");
}
studentScopeAssert(strpos($query, 'COALESCE(al.target_type') !== false && strpos($query, 'NOT IN (') !== false, 'Excluded domains must be removed even when emitted from an owned Student Affairs route.');
studentScopeAssert(strpos($query, 'COALESCE(al.action') !== false && strpos($query, 'NOT LIKE ?') !== false, 'Evaluation actions using a generic student target must be excluded.');
studentScopeAssert(strpos($query, "'evaluation_%'") !== false, 'Evaluation action prefix must be excluded.');
studentScopeAssert(strpos($query, "'student_accounts.php'") === false, 'Student account page must not be an owned Student Affairs log route.');
studentScopeAssert(strpos($query, "'ajax_student_accounts_datatable.php'") === false, 'Student account datatable route must be excluded.');
studentScopeAssert(strpos($query, "'ajax_bulk_student_accounts.php'") === false, 'Bulk student account route must be excluded.');

fwrite(STDOUT, "student_operation_scope_exclusion_contract_test: OK\n");
