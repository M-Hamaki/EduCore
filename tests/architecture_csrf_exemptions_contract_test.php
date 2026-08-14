<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/tools/architecture_csrf_exemptions.json'), true);
$failures = [];

if (($manifest['schema_version'] ?? null) !== 1 || !is_array($manifest['exemptions'] ?? null)) {
    $failures[] = 'manifest_schema';
}

$paths = [];
foreach (($manifest['exemptions'] ?? []) as $entry) {
    $path = $entry['path'] ?? '';
    if ($path === '' || isset($paths[$path])) {
        $failures[] = 'unique_paths';
    }
    $paths[$path] = true;
    if (($entry['review_by'] ?? '') < date('Y-m-d')) {
        $failures[] = 'expired:' . $path;
    }
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/audit_architecture.php') . ' --strict --json';
exec($command, $output, $exitCode);
$audit = json_decode(implode("\n", $output), true);
if ($exitCode !== 0 || !is_array($audit)) {
    $failures[] = 'strict_audit_execution';
} else {
    if (($audit['findings']['post_without_explicit_csrf_candidates'] ?? null) !== []) {
        $failures[] = 'unreviewed_candidates';
    }
    if (($audit['csrf_exemption_errors'] ?? null) !== []) {
        $failures[] = 'invalid_exemptions';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', array_unique($failures)) . PHP_EOL);
    exit(1);
}

echo "PASS: architecture CSRF exemptions are narrow, current, and audit-enforced.\n";
