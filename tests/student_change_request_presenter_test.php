<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Students/Presentation/StudentChangeRequestPresenter.php';

use EduCore\Modules\Students\Presentation\StudentChangeRequestPresenter;

$presenter = new StudentChangeRequestPresenter([
    'first_name_ar' => 'الاسم الأول بالعربية',
    'extra_phones' => 'أرقام الاتصال الإضافية',
    'extra_data' => 'البيانات الإضافية',
    'external_transfer' => 'بيانات النقل الخارجي',
]);

$historicalRows = $presenter->diffRows(
    [
        '__format' => 'full_profile_v1',
        'display' => [
            'first_name_ar' => 'آدم',
            'extra_phones' => ['01012345678', '01112345678'],
            'extra_data' => ['social_media_contact' => '', 'social_media_work_address' => ''],
            'external_transfer' => [],
        ],
    ],
    [
        '__format' => 'full_profile_v1',
        'request' => [
            'first_name_ar' => 'ادم',
            'student_extra_phones_present' => '1',
            'student_extra_data_present' => '1',
            'student_external_transfer_present' => '1',
            'educational_guardianship' => '',
            'external_transfer_date' => '2026-07-19',
        ],
        'display' => [
            'first_name_ar' => 'ادم',
            'extra_phones' => [],
            'extra_data' => [],
            'external_transfer' => [
                'transfer_destination' => '', 'external_transfer_date' => '',
                'external_transfer_reason' => '', 'external_transfer_notes' => '',
            ],
        ],
    ]
);

$extraRows = $presenter->diffRows(
    ['__format' => 'full_profile_v1', 'display' => ['extra_data' => [['label' => 'الهواية', 'value' => 'الرسم']]]],
    [
        '__format' => 'full_profile_v1',
        'request' => ['student_extra_data_present' => '1', 'student_extra_data_touched' => '1'],
        'display' => ['extra_data' => [['label' => 'الهواية', 'value' => 'القراءة']]],
    ]
);

$phoneRows = $presenter->diffRows(
    ['__format' => 'full_profile_v1', 'display' => ['extra_phones' => [['type' => 'mobile', 'number' => '01012345678']]]],
    [
        '__format' => 'full_profile_v1',
        'request' => ['student_extra_phones_present' => '1', 'student_extra_phones_touched' => '1'],
        'display' => ['extra_phones' => []],
    ]
);

$checks = [
    'historical_false_composite_changes_are_hidden' => count($historicalRows) === 1
        && ($historicalRows[0]['label'] ?? '') === 'الاسم الأول بالعربية',
    'only_actual_scalar_values_are_rendered' => ($historicalRows[0]['before'] ?? '') === 'آدم'
        && ($historicalRows[0]['after'] ?? '') === 'ادم',
    'nested_extra_data_is_rendered_as_specific_field' => count($extraRows) === 1
        && str_contains((string) ($extraRows[0]['label'] ?? ''), 'الهواية')
        && ($extraRows[0]['before'] ?? '') === 'الرسم'
        && ($extraRows[0]['after'] ?? '') === 'القراءة',
    'explicitly_cleared_phone_group_is_rendered' => count($phoneRows) === 1
        && ($phoneRows[0]['label'] ?? '') === 'أرقام الاتصال الإضافية'
        && ($phoneRows[0]['after'] ?? '') === 'لا توجد',
    'raw_json_is_not_exposed' => !str_contains(json_encode([$historicalRows, $extraRows], JSON_UNESCAPED_UNICODE) ?: '', 'social_media_contact'),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
