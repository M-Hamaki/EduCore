<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/StudentProfileLifecycleService.php';

$reflection = new ReflectionClass(StudentProfileLifecycleService::class);
$service = $reflection->newInstanceWithoutConstructor();
$checks = [
    'enrolled_status' => $service->accountStatus('enrolled', 'new', 'active') === 'active',
    'graduated_status' => $service->accountStatus('enrolled', 'graduated', 'active') === 'graduated',
    'transferred_status' => $service->accountStatus('transferred', 'promoted', 'active') === 'inactive',
    'discontinued_status' => $service->accountStatus('discontinued', 'retained', 'active') === 'inactive',
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
