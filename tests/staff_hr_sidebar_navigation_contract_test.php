<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$headerPath = $root . '/includes/admin_header.php';
$header = (string) file_get_contents($headerPath);
$normalizedHeader = (string) preg_replace('/\s+/u', ' ', $header);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$newStaffPages = [
    'hr_center.php' => 'مركز شؤون العاملين',
    'hr_organization.php' => 'الهيكل والتعيينات',
    'hr_policy_calendar.php' => 'سياسات الدوام والتقويم',
    'hr_approval_workflows.php' => 'مسارات الاعتماد والتفويض',
    'hr_attendance_exceptions.php' => 'مركز الاستثناءات',
    'hr_ertaq.php' => 'منصة ارتق',
    'hr_audit.php' => 'سجل مراجعة شؤون العاملين',
];

foreach ($newStaffPages as $page => $label) {
    $assert(is_file($root . '/admin/' . $page), 'Missing Staff HR admin page: ' . $page);
    $assert(str_contains($header, 'href="' . $page . '"'), 'Staff HR sidebar does not link to ' . $page);
    $assert(str_contains($normalizedHeader, $label), 'Staff HR sidebar does not expose the expected Arabic label for ' . $page);
    $assert(
        str_contains($header, '$staffCurrentPage == \'' . $page . '\''),
        'Staff HR sidebar does not mark ' . $page . ' as the active page'
    );
}

foreach (['hr_organization.php', 'hr_policy_calendar.php', 'hr_approval_workflows.php', 'hr_ertaq.php', 'hr_audit.php'] as $page) {
    $assert(
        str_contains($header, "'" . $page . "'"),
        'Staff HR parent menu does not include ' . $page . ' in its active-page registry'
    );
}

$assert(
    str_contains($header, 'الإدارة المتكاملة'),
    'Staff HR sidebar does not visually group the new integrated administration pages'
);

if ($failures !== []) {
    fwrite(STDERR, "Staff HR sidebar navigation contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Staff HR sidebar navigation contract passed for ' . count($newStaffPages) . " pages.\n";
