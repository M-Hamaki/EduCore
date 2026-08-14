<?php

declare(strict_types=1);

require_once __DIR__ . '/safe_rollover_integration_fixture.php';

$results = runSafeRolloverIntegration(true);
$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);

