<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tool = (string)file_get_contents($root . '/tools/check_upload_runtime.php');
$documentation = (string)file_get_contents($root . '/docs/file-upload-standard.md');
$failures = [];

foreach ([
    "PHP_SAPI !== 'cli'",
    "extension_loaded('fileinfo')",
    "extension_loaded('pdo_mysql')",
    "ini_get('upload_max_filesize')",
    "ini_get('post_max_size')",
    "ini_get('upload_tmp_dir')",
    "'storage/private/profile_attachments/student'",
    "'storage/private/profile_attachments/staff'",
    "'uploads/staff'",
    "env('APP_URL'",
    'UPLOAD_RUNTIME_ERRORS=',
] as $needle) {
    if (strpos($tool, $needle) === false) {
        $failures[] = 'tool:' . $needle;
    }
}

if (strpos($documentation, 'composer upload-runtime-check') === false) {
    $failures[] = 'documentation:upload-runtime-check';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: upload runtime preflight covers PHP, limits, storage permissions, and deployment URL.\n";

