<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/user.php';

$today = new DateTimeImmutable('today');
$birth = $today->sub(new DateInterval('P10Y2M3D'));
$age = User::calculateCurrentAge($birth->format('Y-m-d'));
$futureAge = User::calculateCurrentAge($today->add(new DateInterval('P1D'))->format('Y-m-d'));

$results = [
    'exact_current_age' => $age !== null
        && $age['years'] === 10
        && $age['months'] === 2
        && $age['days'] === 3
        && empty($age['is_future']),
    'future_birth_flagged' => $futureAge !== null && !empty($futureAge['is_future']),
    'invalid_birth_rejected' => User::calculateCurrentAge('not-a-date') === null,
];

$failed = array_keys(array_filter($results, static function ($passed) {
    return !$passed;
}));

foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit($failed ? 1 : 0);
