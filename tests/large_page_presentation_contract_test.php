<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$attendanceSource = (string) file_get_contents($root . '/admin/attendance.php');
$attendancePartial = $root . '/classes/Presentation/Attendance/edit_autoload.php';
if (is_file($attendancePartial)) {
    $attendanceSource .= (string) file_get_contents($attendancePartial);
}

$calculationSource = (string) file_get_contents($root . '/admin/calculation_tools.php');
$calculationPartial = $root . '/classes/Presentation/CalculationTools/script_bootstrap.php';
if (is_file($calculationPartial)) {
    $calculationSource .= (string) file_get_contents($calculationPartial);
}

$checks = [
    'attendance_edit_values_are_applied' => strpos($attendanceSource, "stageSelect.value") !== false
        && strpos($attendanceSource, 'filterRecordGrades') !== false
        && strpos($attendanceSource, 'filterRecordClasses') !== false
        && strpos($attendanceSource, 'loadStudentsForRecord') !== false,
    'calculation_date_libraries_are_loaded' => strpos($calculationSource, 'air-datepicker@3.5.0') !== false
        && strpos($calculationSource, 'moment-hijri@2.1.2') !== false,
    'calculation_scope_data_is_exposed_to_scripts' => strpos($calculationSource, 'const dbStages =') !== false
        && strpos($calculationSource, 'const dbGrades =') !== false
        && strpos($calculationSource, 'const dbClasses =') !== false
        && strpos($calculationSource, 'const dbStudents =') !== false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "Large-page presentation contract test passed.\n";
