<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentBulkCreateService.php');

$checks = [
    'post_action_retained' => strpos($page, "isset(\$_POST['add_students_bulk'])") !== false,
    'input_names_retained' => strpos($page, "\$_POST['bulk_students']") !== false
        && strpos($page, "\$_POST['bulk_default_class_id']") !== false,
    'delegates_to_service' => strpos($page, '$studentBulkCreateService->create(') !== false,
    'success_contract_retained' => strpos($page, "\$_SESSION['success_message'] = 'تمت إضافة '") !== false,
    'failure_contract_retained' => strpos($page, "\$_SESSION['error_message'] = 'خطأ في الإضافة الجماعية: '") !== false
        && strpos($page, "\$_SESSION['student_bulk_old_input']") !== false,
    'redirect_contract_retained' => strpos($page, "header('Location: ' . \$studentsBasePage . \$backQuery);") !== false
        && strpos($page, "http_build_query(\$bulkRedirectParams)") !== false,
    'transaction_owned_by_service' => strpos($service, '$this->db->beginTransaction();') !== false
        && strpos($service, '$this->db->commit();') !== false
        && strpos($service, '$this->db->rollBack();') !== false,
    'related_writes_retained' => strpos($service, 'saveStudentProfile(') !== false
        && strpos($service, 'syncEnrollmentStatus(') !== false
        && strpos($service, 'ActivityLog::logCreate(') !== false
        && strpos($service, 'UndoManager::logInsert(') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
