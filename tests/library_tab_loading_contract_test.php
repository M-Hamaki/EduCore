<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/admin/library.php');
$checks = [
    'loads_student_selector_only_when_needed' => strpos($source, "in_array(\$activeTab, ['loans', 'fines'], true)") !== false,
    'loads_book_selector_only_when_needed' => strpos($source, "\$activeTab === 'books' || \$activeTab === 'loans'") !== false,
    'uses_sql_counts_after_conditional_loading' => strpos($source, 'SELECT COUNT(*) AS total_books') !== false && strpos($source, 'SELECT COUNT(*) FROM library_loans') !== false,
];
$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
