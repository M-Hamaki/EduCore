<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$materials = (string)file_get_contents($root . '/admin/materials_center.php');
$timetable = (string)file_get_contents($root . '/admin/timetable.php');
$schoolProfile = (string)file_get_contents($root . '/admin/school_profile.php');
$pptTemplates = (string)file_get_contents($root . '/admin/lesson_ppt_templates.php');
$config = (string)file_get_contents($root . '/config/database.php');
$boundary = (string)file_get_contents($root . '/uploads/.htaccess');
$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);

foreach (['APP_URL', "env('APP_URL'", 'SITE_URL'] as $needle) {
    if (strpos($config, $needle) === false) {
        $failures[] = 'config:' . $needle;
    }
}
if (($composer['require']['ext-fileinfo'] ?? null) !== '*') {
    $failures[] = 'composer:ext-fileinfo';
}
if (strpos($materials, 'window.location.origin') !== false
    || strpos($materials, '$supervisorPreviewBaseUrl = APP_URL') === false
    || strpos($materials, "new URL('../student/materials/supervisor_preview.php', window.location.href)") === false
    || strpos($materials, 'supervisor_link_mode') === false
    || strpos($materials, 'educore_materials_link_mode') === false) {
    $failures[] = 'environment_independent_material_link';
}
foreach (['Options -Indexes -ExecCGI', 'RemoveHandler', 'FilesMatch', 'Require all denied'] as $needle) {
    if (strpos($boundary, $needle) === false) {
        $failures[] = 'uploads_boundary:' . $needle;
    }
}
foreach ([$materials, $timetable, $schoolProfile, $pptTemplates] as $index => $source) {
    if (strpos($source, 'FileUploadGuard::validate') === false) {
        $failures[] = 'missing_upload_guard:' . $index;
    }
}
if (strpos($pptTemplates, '$db->rollBack();') === false
    || strpos($pptTemplates, 'ppt_template_delete_stored_path($storedPath);') === false) {
    $failures[] = 'bulk_template_rollback_cleanup';
}
if (strpos($materials, '@unlink($target_path);') === false
    || strpos($timetable, '@unlink($dest_path);') === false) {
    $failures[] = 'database_failure_file_cleanup';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: upload links, boundaries, validation, and rollback contracts are present.\n";
