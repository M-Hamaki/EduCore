<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/includes/admin_header.php');
$failures = 0;

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: {$message}\n";
        ++$failures;
    }
};

$assert(
    str_contains($source, 'id="academicYearDropdown"')
        && str_contains($source, 'data-bs-toggle="dropdown"'),
    'academic-year selector remains a Bootstrap dropdown'
);
$assert(
    str_contains($source, "title=\"<?php echo htmlspecialchars('العام الدراسي:"),
    'academic-year selector keeps its native explanatory title'
);
$assert(
    !str_contains($source, 'new bootstrap.Tooltip(ayBtn'),
    'academic-year dropdown is not bound to a conflicting Tooltip instance'
);

if ($failures > 0) {
    exit(1);
}

echo "Academic-year dropdown avoids conflicting Bootstrap instances.\n";

