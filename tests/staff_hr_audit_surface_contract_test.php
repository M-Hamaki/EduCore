<?php

declare(strict_types=1);

/** Static safety proof for the read-only Staff HR audit entrypoint. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$surface = (string) file_get_contents($root . '/admin/hr_audit.php');
$query = (string) file_get_contents($root . '/src/Modules/Operations/Audit/SystemActivityLogQuery.php');

$checks = [
    'audit_surface_exists_and_uses_scoped_query' => str_contains($surface, 'SystemActivityLogQuery')
        && str_contains($surface, "'target_type_prefix' => 'staff_'")
        && str_contains($surface, '$query->load($filters, $tab, $perPage'),
    'audit_surface_authenticates_before_connection' => ($authAt = strpos($surface, "Utilities::validateSession('admin')")) !== false
        && ($connectionAt = strpos($surface, '$database = new Database()')) !== false
        && $authAt < $connectionAt,
    'audit_surface_is_read_only_and_has_no_direct_sql' => !str_contains($surface, "\$_SERVER['REQUEST_METHOD'] === 'POST'")
        && !str_contains($surface, '->prepare(')
        && !str_contains($surface, 'CREATE TABLE')
        && !str_contains($surface, 'UndoManager'),
    'audit_surface_does_not_render_sensitive_details' => !str_contains($surface, "\$row['details']")
        && !str_contains($surface, "\$row['metadata']")
        && !str_contains($surface, "\$row['target_name']")
        && str_contains($surface, 'لا يكشف هذا السطح أسباب الطلبات أو المرفقات أو نصوص الشكاوى'),
    'audit_surface_uses_safe_filters_and_pagination' => str_contains($surface, 'http_build_query')
        && str_contains($surface, 'maxlength="120"')
        && str_contains($surface, 'max(1, (int) ($_GET[\'page\'] ?? 1))'),
    'audit_prefix_filter_is_parameterized_and_fails_closed' => str_contains($query, "al.target_type LIKE ?")
        && str_contains($query, "preg_match('/^[a-z][a-z0-9_]{0,63}$/', \$prefix)")
        && str_contains($query, "\$where[] = '1 = 0';")
        && str_contains($query, "!empty(\$filters['operational_search'])")
        && !str_contains($surface, 'اسم المورد'),
    'audit_surface_uses_shared_footer' => str_contains($surface, "require_once '../includes/admin_footer.php';"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
