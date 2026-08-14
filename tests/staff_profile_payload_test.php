<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/StaffProfilePayload.php';

$checks = [];
$checks['compose'] = StaffProfilePayload::composeNameParts([' محمد ', '', ' أحمد ']) === 'محمد أحمد';
$checks['compound_split'] = StaffProfilePayload::splitNameParts('عبد الرحمن أحمد علي')[0] === 'عبد الرحمن';
$normalized = StaffProfilePayload::normalizeForm([
    'name_parts_ar_touched' => '1', 'full_name_ar_parts' => ['محمد', 'علي'],
    'name_parts_en_touched' => '0', 'full_name_en' => '  Mohamed Ali ',
    'marital_status' => 'single', 'number_of_children' => '3',
]);
$checks['normalize'] = $normalized['full_name_ar'] === 'محمد علي'
    && $normalized['full_name_en'] === 'Mohamed Ali' && $normalized['number_of_children'] === '0';
$checks['phones'] = json_decode(StaffProfilePayload::extraPhones([
    'staff_mobile_numbers' => ['0100', ''], 'staff_mobile_notes' => ['أساسي', ''],
]) ?? '', true) === [['type' => 'mobile', 'number' => '0100', 'note' => 'أساسي']];
$checks['extra_data'] = json_decode(StaffProfilePayload::extraData([
    'additional_data_labels' => [''], 'additional_data_values' => ['قيمة'],
]) ?? '', true) === [['label' => 'بيان إضافي', 'value' => 'قيمة']];
$checks['no_activity'] = StaffProfilePayload::activityDetails(['name' => 'أ'], ['name' => 'أ']) === null;
$checks['activity_change'] = isset(StaffProfilePayload::activityDetails(['name' => 'أ'], ['name' => 'ب'])['changes']['name']);

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
