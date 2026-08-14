<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$json = in_array('--json', $argv, true);
$summaryOnly = in_array('--summary', $argv, true);
$includeTests = in_array('--include-tests', $argv, true);
$classifications = require __DIR__ . '/audit_write_coverage_classifications.php';
$scanRoots = ['admin', 'api', 'ajax', 'teacher', 'student', 'specialist', 'supervisor', 'external_teacher', 'classes', 'src'];
$areaOption = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--area=') === 0) {
        $areaOption = substr($argument, strlen('--area='));
        break;
    }
}
if ($areaOption !== null) {
    if (!in_array($areaOption, $scanRoots, true)) {
        fwrite(STDERR, 'Unknown audit coverage area: ' . $areaOption . PHP_EOL);
        exit(2);
    }
    $scanRoots = [$areaOption];
}
$excludedSegments = ['archive', 'vendor', 'storage', 'tmp', 'scratch'];
if (!$includeTests) {
    $excludedSegments[] = 'tests';
}

$writePattern = '/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\b/i';
$auditPattern = '/(?:\bActivityLog::(?:log|logCreate|logUpdate|logDelete|logImport|logStatusChange|logSettings|logReset)|->(?:recordInsert|recordUpdate|recordDelete|recordCompositeUpdate|recordReplacement|recordEvent))\s*\(/s';
$undoPattern = '/\bUndoManager::(?:logInsert|logUpdate|logDelete)\s*\(/';
$servicePattern = '/\b(?:->|::)(?:save|create|insert|update|delete|remove|restore|publish|approve|reject|assign|detach|attach)\s*\(/i';

$rows = [];
foreach ($scanRoots as $relativeRoot) {
    $directory = $root . DIRECTORY_SEPARATOR . $relativeRoot;
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
        $segments = explode('/', $relative);
        if (array_intersect($excludedSegments, $segments)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $sqlWrites = preg_match_all($writePattern, $source);
        $serviceWrites = preg_match_all($servicePattern, $source);
        if ($sqlWrites === 0 && $serviceWrites === 0) {
            continue;
        }

        $auditCalls = preg_match_all($auditPattern, $source);
        $undoCalls = preg_match_all($undoPattern, $source);
        $classification = $classifications[$relative] ?? null;
        $coverage = $auditCalls > 0
            ? 'declared'
            : (is_array($classification) ? (string)($classification['type'] ?? 'review_required') : 'review_required');
        $rows[] = [
            'file' => $relative,
            'sql_write_markers' => $sqlWrites,
            'service_write_markers' => $serviceWrites,
            'audit_calls' => $auditCalls,
            'undo_calls' => $undoCalls,
            'coverage' => $coverage,
            'classification' => $classification,
        ];
    }
}

usort($rows, static fn(array $left, array $right): int => strcmp($left['file'], $right['file']));
$review = array_values(array_filter(
    $rows,
    static fn(array $row): bool => $row['coverage'] === 'review_required'
));
$byArea = [];
foreach ($rows as $row) {
    $area = explode('/', $row['file'], 2)[0];
    if (!isset($byArea[$area])) {
        $byArea[$area] = [
            'candidates' => 0,
            'declared' => 0,
            'delegated' => 0,
            'false_positive' => 0,
            'review_required' => 0,
        ];
    }
    $byArea[$area]['candidates']++;
    $bucket = array_key_exists($row['coverage'], $byArea[$area])
        ? $row['coverage']
        : 'review_required';
    $byArea[$area][$bucket]++;
}
ksort($byArea);

$report = [
    'generated_at' => gmdate('c'),
    'scope' => $scanRoots,
    'candidate_write_files' => count($rows),
    'declared_audit_files' => count(array_filter($rows, static fn(array $row): bool => $row['coverage'] === 'declared')),
    'delegated_files' => count(array_filter($rows, static fn(array $row): bool => $row['coverage'] === 'delegated')),
    'false_positive_files' => count(array_filter($rows, static fn(array $row): bool => $row['coverage'] === 'false_positive')),
    'review_required_files' => count($review),
    'by_area' => $byArea,
    'rows' => $rows,
    'limitations' => [
        'Static markers are inventory evidence, not proof that every runtime branch logs successfully.',
        'Dynamic SQL and writes hidden behind unrecognized method names require manual or runtime coverage.',
        'A declared audit call must still be verified for transaction placement, outcome, and sensitive-data policy.',
    ],
];

$exitCode = $report['review_required_files'] > 0 ? 1 : 0;

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

echo 'AUDIT_WRITE_CANDIDATES=' . $report['candidate_write_files'] . PHP_EOL;
echo 'AUDIT_DECLARED_FILES=' . $report['declared_audit_files'] . PHP_EOL;
echo 'AUDIT_DELEGATED_FILES=' . $report['delegated_files'] . PHP_EOL;
echo 'AUDIT_FALSE_POSITIVE_FILES=' . $report['false_positive_files'] . PHP_EOL;
echo 'AUDIT_REVIEW_REQUIRED=' . $report['review_required_files'] . PHP_EOL;
foreach ($byArea as $area => $counts) {
    echo 'AREA ' . $area
        . ' candidates=' . $counts['candidates']
        . ' declared=' . $counts['declared']
        . ' delegated=' . $counts['delegated']
        . ' false_positive=' . $counts['false_positive']
        . ' review=' . $counts['review_required'] . PHP_EOL;
}
if ($summaryOnly) {
    exit($exitCode);
}
foreach ($review as $row) {
    echo 'REVIEW ' . $row['file']
        . ' sql=' . $row['sql_write_markers']
        . ' service=' . $row['service_write_markers'] . PHP_EOL;
}

exit($exitCode);
