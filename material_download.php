<?php

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use EduCore\Modules\PublicPortal\Infrastructure\LegacyMaterialCatalogAdapter;

$materialId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($materialId) || $materialId <= 0) {
    http_response_code(404);
    exit('المادة المطلوبة غير موجودة.');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(503);
    exit('تعذر الاتصال بالخدمة حالياً.');
}

// The adapter rechecks the existing publication rules on every request:
// active stage, active grade, enabled material, and downloadable material.
$material = (new LegacyMaterialCatalogAdapter($db))->findDownloadableMaterial($materialId);
if ($material === null) {
    http_response_code(404);
    exit('المادة المطلوبة غير متاحة للتحميل.');
}

$storedName = (string) ($material['file_name'] ?? '');
if ($storedName === '' || basename($storedName) !== $storedName) {
    error_log('Rejected unsafe public material filename for material #' . $materialId);
    http_response_code(404);
    exit('الملف غير متاح.');
}

$storageRoot = realpath(__DIR__ . '/uploads/materials');
$filePath = $storageRoot !== false ? realpath($storageRoot . DIRECTORY_SEPARATOR . $storedName) : false;
if ($storageRoot === false || $filePath === false || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('الملف غير موجود.');
}
$rootPrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!str_starts_with($filePath, $rootPrefix)) {
    error_log('Rejected material path outside storage root for material #' . $materialId);
    http_response_code(404);
    exit('الملف غير متاح.');
}

$downloadName = trim((string) ($material['original_file_name'] ?? ''));
if ($downloadName === '' || basename($downloadName) !== $downloadName) {
    $downloadName = 'material-' . $materialId;
}
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($filePath);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="material-' . $materialId . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string) filesize($filePath));
header('Cache-Control: private, no-store, max-age=0');
readfile($filePath);
exit;
