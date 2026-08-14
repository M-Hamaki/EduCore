<?php

require_once __DIR__ . '/../classes/ProfileInputValidator.php';

$results = [];
$expectValid = static function (callable $callback): bool {
    try {
        $callback();
        return true;
    } catch (Throwable $e) {
        return false;
    }
};
$expectInvalid = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
};

$results['optional_values'] = $expectValid(static function (): void {
    ProfileInputValidator::nationalId('', 'الرقم القومي');
    ProfileInputValidator::mobile(null, 'الموبايل');
    ProfileInputValidator::landline('', 'الأرضي');
});
$results['valid_digits'] = $expectValid(static function (): void {
    ProfileInputValidator::nationalId('12345678901234', 'الرقم القومي');
    ProfileInputValidator::mobile('01012345678', 'الموبايل');
    ProfileInputValidator::landline('0212345678', 'الأرضي');
});
$results['invalid_digits'] = $expectInvalid(static function (): void {
    ProfileInputValidator::mobile('01012x45678', 'الموبايل');
});
$results['valid_birth_date'] = $expectValid(static function (): void {
    ProfileInputValidator::birthDate('2000-02-29', 'الطالب');
});
$results['invalid_calendar_date'] = $expectInvalid(static function (): void {
    ProfileInputValidator::birthDate('2025-02-30', 'الطالب');
});
$results['future_birth_date'] = $expectInvalid(static function (): void {
    ProfileInputValidator::birthDate((new DateTimeImmutable('tomorrow'))->format('Y-m-d'), 'الموظف');
});

$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit($failed ? 1 : 0);
