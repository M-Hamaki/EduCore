<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/StudentProfilePayload.php';
require_once dirname(__DIR__) . '/classes/StudentGuardianService.php';

$reflection = new ReflectionClass(StudentGuardianService::class);
$service = $reflection->newInstanceWithoutConstructor();
[$guardians, $missing] = $service->prepare(42, [[
    'guardian_name' => '',
    'relationship' => 'mother',
    'religion' => 'أخرى',
    'religion_other' => 'مخصصة',
    'nationality' => 'أخرى',
    'nationality_other' => 'سودانية',
    'extra_mobile_numbers' => ['010'],
    'extra_mobile_notes' => ['أساسي'],
    'extra_data_labels' => ['عمل'],
    'extra_data_values' => ['طبيبة'],
]]);
$guardian = $guardians[0];
$checks = [
    'student_id' => $guardian['student_id'] === 42,
    'missing_name_fallback' => $guardian['guardian_name'] === 'ولي أمر بدون اسم' && $missing === ['الأم'],
    'custom_religion' => $guardian['religion'] === 'مخصصة',
    'custom_nationality' => $guardian['nationality'] === 'سودانية',
    'extra_phones' => json_decode($guardian['extra_phones'], true)[0]['number'] === '010',
    'extra_data' => json_decode($guardian['extra_data'], true)[0]['value'] === 'طبيبة',
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
