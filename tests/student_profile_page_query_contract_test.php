<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/StudentProfilePageQuery.php');
$checks = [
    'edit_and_view_queries' => strpos($query, 'public function editData(') !== false
        && strpos($query, 'public function viewData(') !== false,
    'page_delegates' => strpos($page, '$studentProfilePageQuery->editData(') !== false
        && strpos($page, '$studentProfilePageQuery->viewData(') !== false,
    'credential_free_reads' => substr_count($query, 'readOneWithoutCredentials()') === 2,
    'edit_related_data' => strpos($query, 'getStudentAcademicHistory(') !== false
        && strpos($query, 'student_external_transfers') !== false,
    'view_related_data' => strpos($query, 'student_kinships') !== false
        && strpos($query, "'class_name'") !== false,
    'private_attachment_rows' => strpos($query, 'student_attachments') !== false
        && strpos($query, 'ORDER BY uploaded_at DESC') !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
