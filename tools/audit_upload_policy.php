<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$strict = in_array('--strict', $argv, true);
$json = in_array('--json', $argv, true);
$manifestPath = __DIR__ . '/upload_policy_manifest.json';

$excluded = ['.git', '.github', 'archive', 'database', 'docs', 'node_modules', 'phpmyadmin', 'scratch', 'storage', 'tests', 'tmp', 'tools', 'uploads', 'vendor'];
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $top = explode('/', $relative, 2)[0];
    if (!in_array($top, $excluded, true)) {
        $phpFiles[$relative] = $file->getPathname();
    }
}
ksort($phpFiles, SORT_STRING);

$errors = [];
$manifestSource = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
$manifest = $manifestSource === false ? null : json_decode($manifestSource, true);
$reviewed = is_array($manifest) ? ($manifest['reviewed_paths'] ?? null) : null;
if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1 || !is_array($reviewed)) {
    $errors['manifest'][] = 'missing_or_invalid_manifest';
    $reviewed = [];
}

$allowedClassifications = ['database_backed', 'storage_adapter', 'transient_processing'];
foreach ($reviewed as $relative => $contract) {
    if (!is_string($relative) || !is_array($contract)
        || !in_array($contract['classification'] ?? null, $allowedClassifications, true)
        || !is_array($contract['required_markers'] ?? null)) {
        $errors['manifest'][] = (string)$relative . ':invalid_contract';
        continue;
    }
    $path = $root . '/' . $relative;
    $source = is_file($path) ? file_get_contents($path) : false;
    if ($source === false) {
        $errors['manifest'][] = $relative . ':missing_or_unreadable';
        continue;
    }
    foreach ($contract['required_markers'] as $marker) {
        if (!is_string($marker) || $marker === '' || strpos($source, $marker) === false) {
            $errors['contract_markers'][] = $relative . ':' . (is_string($marker) ? $marker : 'invalid_marker');
        }
    }
}

$directMovers = [];
$environmentSpecificPaths = [];
foreach ($phpFiles as $relative => $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        $errors['unreadable_php'][] = $relative;
        continue;
    }
    if (strpos($source, 'move_uploaded_file(') !== false) {
        $directMovers[] = $relative;
        if (!isset($reviewed[$relative])) {
            $errors['unreviewed_upload_paths'][] = $relative;
        }
    }
    if (preg_match('~https?://(?:localhost|127\.0\.0\.1)[^\s\'"<>]*(?:uploads|student/materials)~i', $source)
        || preg_match('~[A-Z]:\\\\[^\r\n\'";]*\\\\uploads\\\\~i', $source)) {
        $environmentSpecificPaths[] = $relative;
    }
}

$requiredFiles = [
    'AGENTS.md' => ['File Upload And Storage — MANDATORY', 'docs/file-upload-standard.md', 'composer upload-policy-audit'],
    'classes/FileUploadGuard.php' => ['final class FileUploadGuard', 'FILEINFO_MIME_TYPE', 'DANGEROUS_EXTENSIONS'],
    'uploads/.htaccess' => ['Options -Indexes -ExecCGI', 'RemoveHandler', 'Require all denied'],
    '.env.example' => ['APP_URL='],
    'config/database.php' => ["env('APP_URL'", "define('APP_URL'"],
    '.gitignore' => ['!/uploads/.htaccess'],
    'docs/file-upload-standard.md' => ['New workflow checklist', 'Database and filesystem sequence'],
    'composer.json' => ['"upload-policy-audit"', '"quality"', 'tools/audit_upload_policy.php --strict'],
    '.github/workflows/quality.yml' => ['composer quality', 'pull_request:', 'composer install'],
];
foreach ($requiredFiles as $relative => $markers) {
    $source = is_file($root . '/' . $relative) ? file_get_contents($root . '/' . $relative) : false;
    if ($source === false) {
        $errors['required_files'][] = $relative . ':missing';
        continue;
    }
    foreach ($markers as $marker) {
        if (strpos($source, $marker) === false) {
            $errors['required_files'][] = $relative . ':' . $marker;
        }
    }
}
if ($environmentSpecificPaths) {
    $errors['environment_specific_upload_paths'] = $environmentSpecificPaths;
}

foreach ($errors as &$items) {
    $items = array_values(array_unique($items));
    sort($items, SORT_STRING);
}
unset($items);
sort($directMovers, SORT_STRING);

$result = [
    'mode' => $strict ? 'strict' : 'report',
    'php_files_scanned' => count($phpFiles),
    'reviewed_upload_paths' => count($reviewed),
    'direct_upload_movers' => $directMovers,
    'errors' => $errors,
    'error_count' => array_sum(array_map('count', $errors)),
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'UPLOAD_POLICY_AUDIT_MODE=' . $result['mode'] . PHP_EOL;
    echo 'PHP_FILES_SCANNED=' . $result['php_files_scanned'] . PHP_EOL;
    echo 'REVIEWED_UPLOAD_PATHS=' . $result['reviewed_upload_paths'] . PHP_EOL;
    echo 'DIRECT_UPLOAD_MOVERS=' . count($directMovers) . PHP_EOL;
    foreach ($errors as $category => $items) {
        echo strtoupper($category) . '=' . count($items) . PHP_EOL;
        foreach ($items as $item) {
            echo '  ' . $item . PHP_EOL;
        }
    }
    echo 'UPLOAD_POLICY_ERRORS=' . $result['error_count'] . PHP_EOL;
}

exit($strict && $result['error_count'] > 0 ? 1 : 0);
