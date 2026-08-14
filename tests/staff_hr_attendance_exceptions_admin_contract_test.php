<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/hr_attendance_exceptions.php');
$query = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/PdoAttendanceExceptionQuery.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$indexOf = static function (string $needle, string $haystack): int {
    $index = strpos($haystack, $needle);

    return $index === false ? PHP_INT_MAX : $index;
};

$assert(is_file($root . '/admin/hr_attendance_exceptions.php'), 'exception-review page exists at the documented admin route');
$assert(
    $indexOf("Utilities::validateSession('admin');", $page) < $indexOf('new Database()', $page),
    'exception-review page validates admin session before database access'
);
$assert(
    $indexOf("Utilities::validateSession('admin');", $page) < $indexOf('$_GET', $page),
    'exception-review page validates admin session before reading filters'
);
$assert(str_contains($page, 'attendanceExceptionQuery()'), 'admin route reads through the attendance exception-query service');
$assert(!str_contains($page, 'SELECT '), 'admin route does not add page-local SQL');
$assert(str_contains($page, 'admin-page-heading'), 'admin route follows the canonical page-heading structure');
$assert(str_contains($page, 'admin-filter-bar'), 'admin route uses the centralized list filter surface');
$assert(str_contains($page, 'admin-list-surface'), 'admin route uses the centralized list surface');
$assert(str_contains($page, 'stat-card'), 'admin route uses centralized statistic cards');
$assert(!str_contains($page, '<style'), 'admin route does not introduce page-local styles');
$assert(!str_contains($page, 'method="POST"'), 'review-only route does not expose a write form');
$assert(str_contains($page, 'لا تعدّل البصمات الخام'), 'admin route explains that review does not mutate evidence');
$assert(str_contains($page, "require_once '../includes/admin_footer.php';"), 'admin route retains the shared admin footer');
$assert(str_contains($header, "'hr_attendance_exceptions.php'"), 'attendance exception route is discoverable in the staff attendance navigation');
$assert(str_contains($header, 'مركز الاستثناءات'), 'navigation gives the exception route a clear Arabic label');

$assert(!str_contains($query, 'raw_payload_ref'), 'PDO exception projection never selects or exposes raw payload references');
$assert(!str_contains($query, 'biometric_identity'), 'PDO exception projection never selects or exposes biometric identities');
$assert(!str_contains($query, 'attachment_ref'), 'PDO exception projection never selects or exposes private attachment references');
$assert(!str_contains($query, 'link_reason'), 'PDO exception projection never exposes internal matching reasons');
$assert(str_contains($query, 'staff_attendance_reason_lines'), 'PDO exception projection includes classified shadow comparison differences');
$assert(str_contains($query, 'NOT EXISTS ('), 'PDO exception projection chooses the latest immutable day version rather than arbitrary history');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance exception admin contract failure(s).\n");
    exit(1);
}

echo "Attendance exception admin contracts passed.\n";
