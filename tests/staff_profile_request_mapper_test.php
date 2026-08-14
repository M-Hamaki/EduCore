<?php

require_once __DIR__ . '/../classes/ProfileInputValidator.php';
require_once __DIR__ . '/../classes/StaffProfilePayload.php';
require_once __DIR__ . '/../classes/StaffEmploymentLifecycleService.php';
require_once __DIR__ . '/../classes/StaffProfileRequestMapper.php';

$mapper = new StaffProfileRequestMapper(
    new StaffEmploymentLifecycleService(new PDO('sqlite::memory:'))
);

$mapped = $mapper->map([
    'full_name_ar' => '  أحمد محمد علي  ',
    'religion' => 'other',
    'religion_other' => 'أخرى مخصصة',
    'departments' => ['إداري', 'أخرى', 'غير مسموح'],
    'departments_other' => 'تقنية، جودة',
    'admin_notes' => "ملاحظة\n[CONTACT_META_START]قديم[CONTACT_META_END]\n",
    'staff_mobile_numbers' => [''],
], ['إداري', 'خدمات معاونة']);

$checks = [
    'name_trimmed' => $mapped['name'] === 'أحمد محمد علي',
    'allowed_and_custom_departments' => $mapped['profile']['department'] === 'إداري,تقنية,جودة',
    'other_value_mapped' => $mapped['profile']['religion'] === 'أخرى مخصصة',
    'legacy_note_blocks_removed' => $mapped['profile']['admin_notes'] === 'ملاحظة',
    'employment_contract_returned' => isset($mapped['status_events'], $mapped['job_movements']),
];

try {
    $mapper->map([], []);
    $checks['name_required'] = false;
} catch (InvalidArgumentException $exception) {
    $checks['name_required'] = $exception->getMessage() === 'اسم الموظف باللغة العربية إلزامي.';
}

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
