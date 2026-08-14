<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/StudentEnrollmentService.php';
require_once dirname(__DIR__) . '/classes/StudentBulkCreateService.php';

$db = new PDO('sqlite::memory:');
$service = new StudentBulkCreateService($db, new StudentEnrollmentService($db));
$checks = [];

try {
    $service->create([['name' => 'أ'], ['name' => 'ب']], 1, 'graduates');
    $checks['scope_rejected'] = false;
} catch (InvalidArgumentException $e) {
    $checks['scope_rejected'] = $e->getMessage() === 'الإضافة الجماعية اليدوية متاحة للطلاب المقيدين فقط.';
}

try {
    $service->create([['name' => 'طالب واحد']], 1, 'current');
    $checks['minimum_rejected'] = false;
} catch (InvalidArgumentException $e) {
    $checks['minimum_rejected'] = $e->getMessage() === 'أدخل بيانات طالبين على الأقل للإضافة الجماعية.';
}

$tooMany = array_fill(0, 21, ['name' => 'طالب']);
try {
    $service->create($tooMany, 1, 'current');
    $checks['maximum_rejected'] = false;
} catch (InvalidArgumentException $e) {
    $checks['maximum_rejected'] = $e->getMessage() === 'الحد الأقصى للإضافة الجماعية اليدوية هو 20 طالبًا. استخدم استيراد Excel للأعداد الأكبر.';
}

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
