<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/audit_architecture.php';

/** @return array{status:int,data:array} */
function runArchitectureAudit(string $tool, array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    $decoded = json_decode(implode(PHP_EOL, $output), true);
    return [
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

$report = runArchitectureAudit($tool, ['--json']);
$strict = runArchitectureAudit($tool, ['--strict', '--json']);

$regressionAudit = ['status' => -1, 'data' => []];
$missingBaselineAudit = ['status' => -1, 'data' => []];
$invalidBaselineAudit = ['status' => -1, 'data' => []];
$removedCategory = null;
$removedPath = null;
$baselinePath = $root . '/tools/architecture_audit_baseline.json';
$baseline = json_decode((string) file_get_contents($baselinePath), true);
$temporaryBaseline = tempnam(sys_get_temp_dir(), 'educore_arch_');
if (is_array($baseline) && is_string($temporaryBaseline)) {
    $removedCategory = null;
    $removedPath = null;
    foreach (['large_php_files', 'runtime_ddl_files', 'post_without_explicit_csrf_candidates'] as $category) {
        if (!empty($baseline[$category])) {
            $removedCategory = $category;
            $removedPath = reset($baseline[$category]);
            $baseline[$category] = array_values(array_filter(
                $baseline[$category],
                static function (string $path) use ($removedPath): bool {
                    return $path !== $removedPath;
                }
            ));
            break;
        }
    }
    $regressionProbePath = null;
    if ($removedCategory === null) {
        $removedCategory = 'large_php_files';
        $removedPath = '_architecture_regression_probe.php';
        $regressionProbePath = $root . '/' . $removedPath;
        file_put_contents($regressionProbePath, "<?php\n" . str_repeat("// regression probe\n", 2001));
    }
    file_put_contents(
        $temporaryBaseline,
        json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $regressionAudit = runArchitectureAudit(
        $tool,
        ['--strict', '--json', '--baseline=' . $temporaryBaseline]
    );
    if (is_string($regressionProbePath) && is_file($regressionProbePath)) {
        unlink($regressionProbePath);
    }
    unlink($temporaryBaseline);

    $missingBaselineAudit = runArchitectureAudit(
        $tool,
        ['--strict', '--json', '--baseline=' . $temporaryBaseline]
    );

    file_put_contents($temporaryBaseline, '{invalid json');
    $invalidBaselineAudit = runArchitectureAudit(
        $tool,
        ['--strict', '--json', '--baseline=' . $temporaryBaseline]
    );
    unlink($temporaryBaseline);
}

$reportFindings = $report['data']['findings'] ?? [];
$checks = [
    'report_exits_zero' => $report['status'] === 0,
    'scans_active_php' => (int) ($report['data']['php_files_scanned'] ?? 0) >= 250,
    'uses_recursive_scope_discovery' => ($report['data']['scan_strategy'] ?? null)
        === 'root_recursive_with_explicit_exclusions',
    'finding_categories_present' => isset(
        $reportFindings['large_php_files'],
        $reportFindings['runtime_ddl_files'],
        $reportFindings['post_without_explicit_csrf_candidates'],
        $reportFindings['unprotected_internal_directories'],
        $reportFindings['unreadable_php_files']
    ),
    'internal_boundaries_protected' => empty($reportFindings['unprotected_internal_directories'] ?? ['missing']),
    'all_scanned_php_readable' => empty($reportFindings['unreadable_php_files'] ?? ['missing']),
    'strict_exits_zero' => $strict['status'] === 0,
    'strict_baseline_valid' => array_key_exists('baseline_error', $strict['data'])
        && $strict['data']['baseline_error'] === null,
    'strict_has_no_regressions' => (int) ($strict['data']['regression_count'] ?? -1) === 0,
    'strict_detects_new_regression' => $removedCategory !== null
        && $removedPath !== null
        && $regressionAudit['status'] === 1
        && in_array(
            $removedPath,
            $regressionAudit['data']['regressions'][$removedCategory] ?? [],
            true
        ),
    'strict_rejects_missing_baseline' => $missingBaselineAudit['status'] === 1
        && ($missingBaselineAudit['data']['baseline_error'] ?? null) === 'missing_baseline',
    'strict_rejects_invalid_baseline' => $invalidBaselineAudit['status'] === 1
        && ($invalidBaselineAudit['data']['baseline_error'] ?? null) === 'invalid_baseline_json',
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
