<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Students/StudentChangeFieldPolicy.php';

use EduCore\Modules\Students\StudentChangeFieldPolicy;

$untouched = StudentChangeFieldPolicy::omitUntouchedCompositeGroups([
    'first_name_ar' => 'آدم',
    'student_extra_phones_present' => '1',
    'student_extra_phones_touched' => '0',
    'student_mobile_numbers' => ['01012345678'],
    'student_external_transfer_present' => '1',
    'student_external_transfer_touched' => '0',
    'external_transfer_date' => '2026-07-19',
]);

$touched = StudentChangeFieldPolicy::omitUntouchedCompositeGroups([
    'student_extra_phones_present' => '1',
    'student_extra_phones_touched' => '1',
    'student_mobile_numbers' => [],
]);

$ambiguousLegacy = StudentChangeFieldPolicy::omitUntouchedCompositeGroups([
    'student_extra_phones_present' => '1',
    'student_mobile_numbers' => ['01012345678'],
]);

$filteredDisplay = StudentChangeFieldPolicy::filterUntouchedCompositeDisplay(
    ['first_name_ar' => 'ادم', 'extra_phones' => []],
    ['first_name_ar' => 'ادم']
);

$checks = [
    'scalar_change_is_preserved' => ($untouched['first_name_ar'] ?? '') === 'آدم',
    'untouched_phone_payload_is_omitted' => !array_key_exists('student_extra_phones_present', $untouched)
        && !array_key_exists('student_mobile_numbers', $untouched),
    'untouched_external_default_is_omitted' => !array_key_exists('student_external_transfer_present', $untouched)
        && !array_key_exists('external_transfer_date', $untouched),
    'explicit_clear_intent_is_preserved' => array_key_exists('student_extra_phones_present', $touched)
        && array_key_exists('student_mobile_numbers', $touched),
    'ambiguous_legacy_composite_is_fail_closed' => !array_key_exists('student_extra_phones_present', $ambiguousLegacy)
        && !array_key_exists('student_mobile_numbers', $ambiguousLegacy),
    'audit_display_keeps_only_proven_changes' => array_keys($filteredDisplay) === ['first_name_ar'],
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
