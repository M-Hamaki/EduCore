<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/StaffHrFeatureFlags.php';

use EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags;

$modes = [
    'off' => [false, false, false, true],
    'shadow' => [true, false, false, true],
    'compare' => [true, false, false, true],
    'display' => [true, true, false, true],
    'official' => [true, true, true, false],
];

$failed = [];
foreach ($modes as $mode => $expected) {
    $flags = new StaffHrFeatureFlags($mode);
    $actual = [
        $flags->calculatesNewResults(),
        $flags->exposesNewResults(),
        $flags->usesNewResultsAsOfficial(),
        $flags->usesLegacyFallback(),
    ];
    $passed = $flags->mode() === $mode && $actual === $expected;
    echo 'mode_' . $mode . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = 'mode_' . $mode;
    }
}

$invalidRejected = false;
try {
    new StaffHrFeatureFlags('enabled');
} catch (InvalidArgumentException $error) {
    $invalidRejected = true;
}
echo 'invalid_mode_rejected:' . ($invalidRejected ? 'PASS' : 'FAIL') . PHP_EOL;
if (!$invalidRejected) {
    $failed[] = 'invalid_mode_rejected';
}

putenv('STAFF_HR_MODE');
$defaultFlags = StaffHrFeatureFlags::fromEnvironment();
$defaultOff = $defaultFlags->mode() === StaffHrFeatureFlags::MODE_OFF;
echo 'default_is_off:' . ($defaultOff ? 'PASS' : 'FAIL') . PHP_EOL;
if (!$defaultOff) {
    $failed[] = 'default_is_off';
}

exit($failed ? 1 : 0);

