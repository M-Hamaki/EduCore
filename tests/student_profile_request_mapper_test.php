<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/encryption.php';
require_once dirname(__DIR__) . '/classes/user.php';
require_once dirname(__DIR__) . '/classes/ProfileInputValidator.php';
require_once dirname(__DIR__) . '/classes/StudentProfilePayload.php';
require_once dirname(__DIR__) . '/classes/StudentProfileRequestMapper.php';

$mapper = new StudentProfileRequestMapper();
$post = $mapper->normalizeAndValidate([
    'first_name_ar' => 'محمد',
    'second_name_ar' => 'أحمد',
    'nationality' => 'other',
    'nationality_other' => 'سوداني',
    'religion' => 'other',
    'religion_other' => 'ديانة مخصصة',
    'graduate_grade_id' => '7',
    'guardians' => [],
    'additional_data_labels' => ['هواية'],
    'additional_data_values' => ['رسم'],
    'educational_guardianship' => 'father',
]);
$profile = $mapper->profileData($post);
$extra = json_decode($profile['extra_data'] ?? '', true);

$checks = [
    'name_fields' => $profile['first_name_ar'] === 'محمد' && $profile['second_name_ar'] === 'أحمد',
    'graduate_grade_fallback' => $profile['grade_id'] === '7',
    'custom_nationality' => $profile['nationality'] === 'سوداني',
    'custom_religion_note' => str_contains($profile['notes'], 'ديانة مخصصة'),
    'search_key' => $profile['search_key_ar'] !== '',
    'extra_data_and_guardianship' => count($extra) === 2 && $extra[1]['label'] === '__educational_guardianship',
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
