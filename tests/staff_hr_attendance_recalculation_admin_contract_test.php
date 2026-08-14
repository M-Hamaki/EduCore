<?php

declare(strict_types=1);

$page = (string) file_get_contents(dirname(__DIR__) . '/admin/hr_attendance_exceptions.php');

$checks = [
    'admin_auth_precedes_post_processing' => strpos($page, "Utilities::validateSession('admin')") !== false
        && strpos($page, "REQUEST_METHOD") > strpos($page, "Utilities::validateSession('admin')"),
    'recalculation_form_owns_csrf_and_idempotency' => strpos($page, 'id="attendanceRecalculationForm"') !== false
        && strpos($page, 'name="csrf_token"') !== false
        && strpos($page, 'name="idempotency_key"') !== false,
    'server_owns_the_trigger_code' => strpos($page, "'MANUAL_HR_REVIEW'") !== false
        && strpos($page, 'name="trigger_code"') === false,
    'official_service_owns_the_write' => strpos($page, '$recalculationService = $factory->attendanceRecalculationService()') !== false
        && strpos($page, '$recalculationService->recalculate(') !== false
        && strpos($page, '$recalculationService->calculateInitial(') !== false,
    'technical_errors_are_logged_not_rendered' => strpos($page, 'error_log($reference') !== false
        && strpos($page, 'لم تُعرض أي تفاصيل تقنية') !== false,
    'ui_explains_successor_history' => strpos($page, 'نسخة رسمية لاحقة') !== false
        && strpos($page, 'لا يعدّل البصمات الخام') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
