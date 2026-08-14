<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/system_code_analytics.php');

$authPosition = strpos($page, "Utilities::validateSession('admin')");
$postPosition = strpos($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'");

$checks = [
    'auth_precedes_refresh_post' => $authPosition !== false
        && $postPosition !== false
        && $authPosition < $postPosition,
    'refresh_post_uses_csrf' => strpos($page, "hash_equals((string) \$_SESSION['csrf_token']") !== false
        && strpos($page, 'name="csrf_token"') !== false,
    'shared_file_cache_is_reused' => strpos($page, "require_once '../classes/FileCache.php';") !== false
        && strpos($page, '$analyticsCache->remember(') !== false
        && strpos($page, '$analyticsCacheTtl = 600;') !== false,
    'manual_refresh_invalidates_cache' => strpos($page, "'refresh_analysis'") !== false
        && strpos($page, '$analyticsCache->forget($analyticsCacheKey);') !== false,
    'large_non_source_trees_are_excluded' => strpos($page, "'vendor'") !== false
        && strpos($page, "'phpmyadmin'") !== false
        && strpos($page, "'storage'") !== false
        && strpos($page, "'node_modules'") !== false,
    'line_counting_uses_chunked_reads' => strpos($page, 'fread($handle, 1024 * 1024)') !== false
        && strpos($page, 'substr_count($chunk, "\\n")') !== false
        && strpos($page, 'fgets(') === false,
    'scanner_does_not_follow_symbolic_links' => strpos($page, 'is_link($path)') !== false,
    'refresh_button_does_not_rescan_on_reload' => strpos($page, 'window.location.reload') === false
        && strpos($page, 'إعادة التحليل') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
