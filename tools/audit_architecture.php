<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$excludedDirectories = [
    '.git',
    '.specify',
    '.zcode',
    'archive',
    'database',
    'docs',
    'node_modules',
    'phpmyadmin',
    'scratch',
    'storage',
    'tests',
    'tmp',
    'tools',
    'uploads',
    'vendor',
];
$protectedDirectories = [
    'classes',
    'config',
    'database',
    'tools',
    'tests',
    'scratch',
    'tmp',
    'storage',
];
$strict = in_array('--strict', $argv, true);
$jsonOutput = in_array('--json', $argv, true);
$baselinePath = __DIR__ . '/architecture_audit_baseline.json';
$csrfExemptionsPath = __DIR__ . '/architecture_csrf_exemptions.json';
foreach ($argv as $argument) {
    if (strpos($argument, '--baseline=') === 0) {
        $baselinePath = substr($argument, strlen('--baseline='));
    }
}
$largeFileThreshold = 2000;

/** @return list<string> */
function architecture_php_files(string $root, array $excludedDirectories): array
{
    $files = [];
    $rootIterator = new DirectoryIterator($root);
    foreach ($rootIterator as $entry) {
        if ($entry->isDot() || $entry->isLink()) {
            continue;
        }

        if ($entry->isDir()) {
            if (in_array($entry->getFilename(), $excludedDirectories, true)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($entry->getPathname(), FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
            continue;
        }

        if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
            $files[] = $entry->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return array_values(array_unique($files));
}

function architecture_relative_path(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function architecture_line_count(string $source): int
{
    if ($source === '') {
        return 0;
    }
    $lineBreaks = substr_count($source, "\n");
    return substr($source, -1) === "\n" ? $lineBreaks : $lineBreaks + 1;
}

function architecture_contains_runtime_ddl(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        if (preg_match('/\b(?:CREATE\s+(?:TEMPORARY\s+)?|ALTER\s+|DROP\s+|TRUNCATE\s+)TABLE\b/i', $token[1])) {
            return true;
        }
    }
    return false;
}

function architecture_handles_post(string $source): bool
{
    return strpos($source, '$_POST') !== false
        || stripos($source, 'INPUT_POST') !== false
        || (strpos($source, 'REQUEST_METHOD') !== false && preg_match('/[\'\"]POST[\'\"]/', $source) === 1);
}

function architecture_has_explicit_csrf(string $source): bool
{
    if (strpos($source, 'requireCsrfPost(') !== false
        || strpos($source, 'requireCsrfToken(') !== false
        || strpos($source, 'adminImportBootstrap(') !== false) {
        return true;
    }
    return stripos($source, 'hash_equals') !== false
        && (stripos($source, 'csrf_token') !== false
            || stripos($source, 'X_CSRF_TOKEN') !== false
            || stripos($source, 'X-CSRF-TOKEN') !== false);
}

/** @return list<string> */
function architecture_unprotected_directories(string $root, array $directories): array
{
    $missing = [];
    foreach ($directories as $directory) {
        $path = $root . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . '.htaccess';
        $source = is_file($path) ? (string) @file_get_contents($path) : '';
        if ($source === '' || strpos($source, 'Require all denied') === false || strpos($source, 'Deny from all') === false) {
            $missing[] = $directory;
        }
    }
    sort($missing, SORT_STRING);
    return $missing;
}

$files = architecture_php_files($root, $excludedDirectories);
$largeFiles = [];
$runtimeDdlFiles = [];
$postWithoutCsrf = [];
$unreadablePhpFiles = [];

foreach ($files as $path) {
    $relative = architecture_relative_path($root, $path);
    $source = @file_get_contents($path);
    if ($source === false) {
        $unreadablePhpFiles[] = $relative;
        continue;
    }
    $lineCount = architecture_line_count($source);
    if ($lineCount > $largeFileThreshold) {
        $largeFiles[$relative] = $lineCount;
    }
    if (architecture_contains_runtime_ddl($source)) {
        $runtimeDdlFiles[] = $relative;
    }
    if (architecture_handles_post($source) && !architecture_has_explicit_csrf($source)) {
        $postWithoutCsrf[] = $relative;
    }
}

ksort($largeFiles, SORT_STRING);
sort($runtimeDdlFiles, SORT_STRING);
sort($postWithoutCsrf, SORT_STRING);
sort($unreadablePhpFiles, SORT_STRING);

$csrfExemptions = [];
$csrfExemptionErrors = [];
if (!is_file($csrfExemptionsPath)) {
    $csrfExemptionErrors[] = 'missing_manifest';
} else {
    $csrfExemptionSource = @file_get_contents($csrfExemptionsPath);
    $csrfExemptionData = $csrfExemptionSource === false ? null : json_decode($csrfExemptionSource, true);
    if (!is_array($csrfExemptionData) || ($csrfExemptionData['schema_version'] ?? null) !== 1
        || !is_array($csrfExemptionData['exemptions'] ?? null)) {
        $csrfExemptionErrors[] = 'invalid_manifest';
    } else {
        $allowedCategories = ['read_only_post', 'verified_bearer_token_exchange'];
        foreach ($csrfExemptionData['exemptions'] as $index => $entry) {
            $prefix = 'entry_' . $index;
            if (!is_array($entry)) {
                $csrfExemptionErrors[] = $prefix . ':not_an_object';
                continue;
            }
            $path = $entry['path'] ?? '';
            $reviewBy = $entry['review_by'] ?? '';
            $valid = is_string($path) && $path !== ''
                && in_array($entry['category'] ?? '', $allowedCategories, true)
                && is_string($entry['reason'] ?? null) && trim($entry['reason']) !== ''
                && is_string($entry['protection'] ?? null) && trim($entry['protection']) !== ''
                && is_string($entry['owner'] ?? null) && trim($entry['owner']) !== ''
                && is_string($reviewBy) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewBy) === 1;
            if (!$valid) {
                $csrfExemptionErrors[] = $prefix . ':invalid_schema';
                continue;
            }
            if ($reviewBy < date('Y-m-d')) {
                $csrfExemptionErrors[] = $path . ':expired';
                continue;
            }
            if (isset($csrfExemptions[$path])) {
                $csrfExemptionErrors[] = $path . ':duplicate';
                continue;
            }
            if (!in_array($path, $postWithoutCsrf, true)) {
                $csrfExemptionErrors[] = $path . ':not_a_current_candidate';
                continue;
            }
            $csrfExemptions[$path] = $entry;
        }
    }
}

$postWithoutCsrf = array_values(array_diff($postWithoutCsrf, array_keys($csrfExemptions)));
sort($postWithoutCsrf, SORT_STRING);

$findings = [
    'large_php_files' => $largeFiles,
    'runtime_ddl_files' => $runtimeDdlFiles,
    'post_without_explicit_csrf_candidates' => $postWithoutCsrf,
    'unprotected_internal_directories' => architecture_unprotected_directories($root, $protectedDirectories),
    'unreadable_php_files' => $unreadablePhpFiles,
];

$baseline = [];
$baselineError = null;
if (is_file($baselinePath)) {
    $baselineSource = @file_get_contents($baselinePath);
    if ($baselineSource === false) {
        $baselineError = 'unreadable_baseline';
        $decoded = null;
    } else {
        $decoded = json_decode($baselineSource, true);
    }
    $requiredCategories = [
        'large_php_files',
        'runtime_ddl_files',
        'post_without_explicit_csrf_candidates',
    ];
    $validSchema = is_array($decoded);
    foreach ($requiredCategories as $category) {
        if (!is_array($decoded[$category] ?? null)) {
            $validSchema = false;
        }
    }
    if ($baselineError !== null) {
        // Preserve the more specific read error.
    } elseif ($validSchema) {
        $baseline = $decoded;
    } else {
        $baselineError = is_array($decoded) ? 'invalid_baseline_schema' : 'invalid_baseline_json';
    }
} elseif ($strict) {
    $baselineError = 'missing_baseline';
}

$regressions = [];
if ($strict && $baselineError === null) {
    foreach (['large_php_files', 'runtime_ddl_files', 'post_without_explicit_csrf_candidates'] as $category) {
        $current = $category === 'large_php_files'
            ? array_keys($findings[$category])
            : $findings[$category];
        $allowed = is_array($baseline[$category] ?? null) ? $baseline[$category] : [];
        $newItems = array_values(array_diff($current, $allowed));
        if ($newItems) {
            $regressions[$category] = $newItems;
        }
    }
    if ($findings['unprotected_internal_directories']) {
        $regressions['unprotected_internal_directories'] = $findings['unprotected_internal_directories'];
    }
    if ($findings['unreadable_php_files']) {
        $regressions['unreadable_php_files'] = $findings['unreadable_php_files'];
    }
    if ($csrfExemptionErrors) {
        $regressions['invalid_csrf_exemptions'] = $csrfExemptionErrors;
    }
}

$result = [
    'mode' => $strict ? 'strict' : 'report',
    'php_files_scanned' => count($files),
    'scan_strategy' => 'root_recursive_with_explicit_exclusions',
    'excluded_directories' => $excludedDirectories,
    'large_file_threshold' => $largeFileThreshold,
    'findings' => $findings,
    'baseline_error' => $baselineError,
    'csrf_exemptions' => array_keys($csrfExemptions),
    'csrf_exemption_errors' => $csrfExemptionErrors,
    'regressions' => $regressions,
    'regression_count' => array_sum(array_map('count', $regressions)),
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'ARCHITECTURE_AUDIT_MODE=' . $result['mode'] . PHP_EOL;
    echo 'PHP_FILES_SCANNED=' . $result['php_files_scanned'] . PHP_EOL;
    echo 'LARGE_FILE_THRESHOLD=' . $largeFileThreshold . PHP_EOL;
    foreach ($findings as $category => $items) {
        echo strtoupper($category) . '=' . count($items) . PHP_EOL;
        foreach ($items as $path => $value) {
            echo '  ' . (is_int($path) ? $value : ($path . ' lines=' . $value)) . PHP_EOL;
        }
    }
    if ($baselineError !== null) {
        echo 'BASELINE_ERROR=' . $baselineError . PHP_EOL;
    }
    echo 'REVIEWED_CSRF_EXEMPTIONS=' . count($csrfExemptions) . PHP_EOL;
    echo 'INVALID_CSRF_EXEMPTIONS=' . count($csrfExemptionErrors) . PHP_EOL;
    foreach ($csrfExemptionErrors as $error) {
        echo '  csrf_exemption_error:' . $error . PHP_EOL;
    }
    echo 'ARCHITECTURE_AUDIT_REGRESSIONS=' . $result['regression_count'] . PHP_EOL;
    foreach ($regressions as $category => $items) {
        foreach ($items as $item) {
            echo '  regression:' . $category . ':' . $item . PHP_EOL;
        }
    }
}

$auditIncomplete = !empty($findings['unreadable_php_files']) || !empty($csrfExemptionErrors);
exit(($strict && ($baselineError !== null || $result['regression_count'] > 0)) || $auditIncomplete ? 1 : 0);
