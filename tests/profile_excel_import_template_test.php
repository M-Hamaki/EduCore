<?php

require_once __DIR__ . '/../admin/includes/profile_excel_import.php';

$studentSheets = profile_import_template_sheets('student');
$staffSheets = profile_import_template_sheets('staff');
$dateErrors = [];
$invalidDate = profile_import_date('2026-02-30', 'test', 2, $dateErrors);
$phoneErrors = [];
$normalizedPhone = profile_import_validate_mobile('010 1234 5678', 'test', 2, $phoneErrors);

$expectations = [
    'student_main_sheet' => isset($studentSheets['الطلاب'])
        && in_array('student_code', $studentSheets['الطلاب'], true)
        && in_array('class_name', $studentSheets['الطلاب'], true),
    'student_related_sheets' => isset($studentSheets['أولياء_الأمور'], $studentSheets['هواتف_إضافية']),
    'staff_main_sheet' => isset($staffSheets['العاملون'])
        && in_array('employee_code', $staffSheets['العاملون'], true)
        && in_array('admin_notes', $staffSheets['العاملون'], true),
    'staff_related_sheets' => isset($staffSheets['هواتف_إضافية'], $staffSheets['بيانات_إضافية'], $staffSheets['الحالات_الوظيفية'], $staffSheets['الحركات_الوظيفية']),
    'credentials_excluded' => !in_array('username', $studentSheets['الطلاب'], true)
        && !in_array('password', $studentSheets['الطلاب'], true)
        && !in_array('username', $staffSheets['العاملون'], true)
        && !in_array('password', $staffSheets['العاملون'], true),
    'financial_excluded' => !in_array('basic_salary', $staffSheets['العاملون'], true)
        && !in_array('net_salary', $staffSheets['العاملون'], true),
    'strict_dates' => $invalidDate === null && !empty($dateErrors),
    'mobile_normalization' => $normalizedPhone === '01012345678'
        && empty($phoneErrors),
];

$failed = [];
foreach ($expectations as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
