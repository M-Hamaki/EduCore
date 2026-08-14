<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/ProfileAttachmentLabelPolicy.php';
require_once '../includes/session_config.php';

Utilities::validateSession('admin');

$entityType = (string)($_GET['entity'] ?? '');
$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tables = [
    'student' => 'student_attachments',
    'staff' => 'staff_attachments',
];

if (!isset($tables[$entityType]) || !$attachmentId || $attachmentId < 1) {
    http_response_code(400);
    exit('طلب مرفق غير صالح.');
}

$db = (new Database())->getConnection();
$stmt = $db->prepare("SELECT id, user_id, file_name, original_name, label, file_type FROM {$tables[$entityType]} WHERE id = ? LIMIT 1");
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attachment) {
    http_response_code(404);
    exit('المرفق غير موجود.');
}

$storage = new ProfileAttachmentStorage();
$path = $storage->absolutePath($entityType, (string)$attachment['file_name']);
if ($path === null) {
    http_response_code(404);
    exit('ملف المرفق غير موجود.');
}

$detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
$allowedMimes = [
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg', 'image/png', 'image/webp',
];
if (!in_array($detectedMime, $allowedMimes, true)) {
    http_response_code(415);
    exit('نوع الملف المخزن غير مسموح.');
}

$originalName = basename((string)($attachment['original_name'] ?: 'attachment'));
$downloadName = ProfileAttachmentLabelPolicy::downloadName((string)($attachment['label'] ?? ''), $originalName);
$disposition = str_starts_with($detectedMime, 'image/') || $detectedMime === 'application/pdf' ? 'inline' : 'attachment';

header('Content-Type: ' . $detectedMime);
header('Content-Length: ' . (string)filesize($path));
header("Content-Disposition: {$disposition}; filename*=UTF-8''" . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($path);
