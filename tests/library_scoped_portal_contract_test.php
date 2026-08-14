<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/admin/library.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_library_datatable.php');
$lookup = (string)file_get_contents($root . '/admin/ajax_library_lookup.php');
$query = (string)file_get_contents($root . '/classes/LibraryListDataTableQuery.php');
$activation = (string)file_get_contents($root . '/database/migrations/20260719_librarian_portal_activation.php');

$checks = [
    'admin_page_uses_shared_scope_context' => strpos($page, 'ScopedStaffPortalContext.php') !== false
        && strpos($page, '$portalContext->allowedClassIds()') !== false,
    'borrow_and_fine_writes_assert_student_scope' => substr_count($page, '$portalContext->assertStudentAllowed(') >= 4,
    'return_and_payment_assert_owned_records' => strpos($page, '$assertLoanAllowed($loanId, true)') !== false
        && strpos($page, '$assertFineAllowed($fineId)') !== false,
    'fine_loan_must_match_selected_student' => strpos($page, "(int)\$loan['student_id'] !== \$studentId") !== false,
    'loan_return_and_fine_statistics_are_scoped' => strpos($page, '$libraryScopeClause') !== false
        && strpos($page, '$loanStatParams') !== false,
    'catalog_remains_shared' => strpos($query, "if (\$type === 'books')") !== false
        && strpos($query, "'library_books b'") !== false,
    'student_lists_fail_closed_on_empty_scope' => strpos($query, "if (\$allowedClassIds === [])") !== false
        && strpos($lookup, "if (\$classIds === [])") !== false,
    'list_and_lookup_endpoints_use_context' => strpos($endpoint, 'ScopedStaffPortalContext') !== false
        && strpos($lookup, 'ScopedStaffPortalContext') !== false
        && strpos($endpoint, "validateSession('admin')") !== false
        && strpos($lookup, 'requireCsrfPost()') !== false,
    'fine_payment_is_audited' => strpos($page, "ActivityLog::logUpdate('library_fine'") !== false,
    'librarian_activation_includes_page_and_endpoints' => strpos($activation, "'library.php'") !== false
        && strpos($activation, "'ajax_library_datatable.php'") !== false
        && strpos($activation, "'ajax_library_lookup.php'") !== false
        && strpos($activation, "['librarian', \$page]") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
