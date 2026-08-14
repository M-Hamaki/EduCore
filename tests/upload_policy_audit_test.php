<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/audit_upload_policy.php';

/** @return array{status:int,data:array} */
function runUploadPolicyAudit(string $tool): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --strict --json';
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    $data = json_decode(implode(PHP_EOL, $lines), true);
    return ['status' => $status, 'data' => is_array($data) ? $data : []];
}

$clean = runUploadPolicyAudit($tool);
$probePath = $root . '/_upload_policy_regression_probe.php';
file_put_contents($probePath, "<?php\nmove_uploaded_file(\$_FILES['file']['tmp_name'], 'uploads/probe.bin');\n");
try {
    $regression = runUploadPolicyAudit($tool);
} finally {
    @unlink($probePath);
}

$checks = [
    'current_policy_passes' => $clean['status'] === 0 && ($clean['data']['error_count'] ?? -1) === 0,
    'manifest_inventory_present' => (int)($clean['data']['reviewed_upload_paths'] ?? 0) >= 10,
    'direct_movers_discovered' => count($clean['data']['direct_upload_movers'] ?? []) >= 9,
    'new_unreviewed_uploader_fails' => $regression['status'] === 1
        && in_array('_upload_policy_regression_probe.php', $regression['data']['errors']['unreviewed_upload_paths'] ?? [], true),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed ? 1 : 0);
