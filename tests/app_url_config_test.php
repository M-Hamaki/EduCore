<?php

declare(strict_types=1);

putenv('APP_URL=https://school.example.test/EduCore/');
$_ENV['APP_URL'] = 'https://school.example.test/EduCore/';
$_SERVER['APP_URL'] = 'https://school.example.test/EduCore/';

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/template_helper.php';

$failures = [];
if (!defined('APP_URL') || APP_URL !== 'https://school.example.test/EduCore') {
    $failures[] = 'app_url_normalization';
}
if (!defined('SITE_URL') || SITE_URL !== APP_URL) {
    $failures[] = 'site_url_compatibility';
}
$materialPreview = APP_URL . '/student/materials/supervisor_preview.php?grade=all&term=term1';
if ($materialPreview !== 'https://school.example.test/EduCore/student/materials/supervisor_preview.php?grade=all&term=term1') {
    $failures[] = 'https_material_preview_url';
}
$projectRoot = realpath(dirname(__DIR__));
$adminScript = $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'students.php';
if (request_app_base_path('/Educore/admin/students.php', $adminScript) !== '/Educore') {
    $failures[] = 'request_subdirectory_base_path';
}
if (request_app_base_path('/admin/students.php', $adminScript) !== '') {
    $failures[] = 'request_document_root_base_path';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: APP_URL produces deployment-independent HTTPS links.\n";
