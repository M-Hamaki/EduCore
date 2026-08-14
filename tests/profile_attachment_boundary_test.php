<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$controller = (string)file_get_contents($root . '/admin/profile_attachment.php');
$upload = (string)file_get_contents($root . '/admin/ajax/upload_attachment.php');
$storage = (string)file_get_contents($root . '/classes/ProfileAttachmentStorage.php');
$students = (string)file_get_contents($root . '/admin/students.php');
$staff = (string)file_get_contents($root . '/admin/staff.php');
$studentFile = (string)file_get_contents($root . '/admin/student_file.php');
$officialDocument = (string)file_get_contents($root . '/admin/includes/official_document_page.php');
$staffProfileScript = (string)file_get_contents($root . '/src/Modules/Staff/Presentation/profile_form_scripts.php');
$legacyBoundaries = [
    $root . '/uploads/students/attachments/.htaccess',
    $root . '/uploads/staff/attachments/.htaccess',
];

$auth = strpos($controller, "Utilities::validateSession('admin');");
$database = strpos($controller, '$db = (new Database())->getConnection();');
if ($auth === false || $database === false || $auth >= $database) {
    $failures[] = 'download_auth_before_database';
}

foreach (['student_attachments', 'staff_attachments', 'X-Content-Type-Options: nosniff', 'Cache-Control: private'] as $needle) {
    if (strpos($controller, $needle) === false) {
        $failures[] = 'controller:' . $needle;
    }
}

foreach (["'storage'", "'private'", "'private:'", "'uploads'", 'safeFileName'] as $needle) {
    if (strpos($storage, $needle) === false) {
        $failures[] = 'storage:' . $needle;
    }
}

if (strpos($upload, 'storeUploadedFile(') === false || strpos($upload, "ProfileAttachmentStorage::adminDownloadUrl") === false) {
    $failures[] = 'upload_private_storage_contract';
}

if (strpos($upload, "dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff'") === false
    || strpos($staffProfileScript, "var downloadUrl = data.url || ('../uploads/staff/' + att.file_name);") === false) {
    $failures[] = 'staff_profile_image_project_root_contract';
}

if (strpos($officialDocument, 'ProfileAttachmentStorage::adminDownloadUrl') === false
    || strpos($officialDocument, 'AS profile_image_id') === false
    || strpos($officialDocument, '<img src="../uploads/') !== false) {
    $failures[] = 'official_document_authorized_profile_image';
}

foreach (['admin/students.php' => $students, 'admin/staff.php' => $staff, 'admin/student_file.php' => $studentFile] as $path => $source) {
    if (preg_match('~uploads/(?:students|staff)/attachments~', $source)) {
        $failures[] = $path . ':direct_public_attachment_url';
    }
    if (strpos($source, 'ProfileAttachmentStorage') === false) {
        $failures[] = $path . ':missing_authorized_download_url';
    }
}

foreach ($legacyBoundaries as $boundary) {
    $rules = is_file($boundary) ? (string)file_get_contents($boundary) : '';
    if (strpos($rules, 'Require all denied') === false || strpos($rules, 'Deny from all') === false) {
        $failures[] = 'legacy_attachment_boundary:' . $boundary;
    }
}

require_once $root . '/classes/ProfileAttachmentStorage.php';
$url = ProfileAttachmentStorage::adminDownloadUrl('student', 42);
if ($url !== 'profile_attachment.php?entity=student&id=42') {
    $failures[] = 'download_url_contract';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: profile attachments use private writes, authorized downloads, and legacy dual-read.\n";
