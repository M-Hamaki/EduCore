<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/FileUploadGuard.php';

$failures = [];
$tempFiles = [];

$makeFile = static function (string $contents) use (&$tempFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'educore_upload_test_');
    if ($path === false) {
        throw new RuntimeException('Unable to create temporary test file.');
    }
    file_put_contents($path, $contents);
    $tempFiles[] = $path;
    return $path;
};

$pdf = $makeFile("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
$valid = FileUploadGuard::validate([
    'name' => 'lesson.v2.pdf',
    'tmp_name' => $pdf,
    'size' => filesize($pdf),
    'error' => UPLOAD_ERR_OK,
], ['pdf' => ['application/pdf']], 1024 * 1024);
if ($valid['extension'] !== 'pdf' || $valid['mime'] !== 'application/pdf') {
    $failures[] = 'valid_pdf';
}

try {
    FileUploadGuard::validate([
        'name' => 'lesson.php.pdf',
        'tmp_name' => $pdf,
        'size' => filesize($pdf),
        'error' => UPLOAD_ERR_OK,
    ], ['pdf' => ['application/pdf']], 1024 * 1024);
    $failures[] = 'dangerous_double_extension';
} catch (InvalidArgumentException $e) {
}

try {
    FileUploadGuard::validate([
        'name' => 'large.pdf',
        'tmp_name' => $pdf,
        'size' => 1025,
        'error' => UPLOAD_ERR_OK,
    ], ['pdf' => ['application/pdf']], 1024);
    $failures[] = 'oversized_file';
} catch (InvalidArgumentException $e) {
}

try {
    FileUploadGuard::validate([
        'name' => 'partial.pdf',
        'tmp_name' => $pdf,
        'size' => filesize($pdf),
        'error' => UPLOAD_ERR_PARTIAL,
    ], ['pdf' => ['application/pdf']], 1024 * 1024);
    $failures[] = 'partial_upload';
} catch (InvalidArgumentException $e) {
}

$generatedNames = [];
for ($index = 0; $index < 500; $index++) {
    $generatedNames[] = FileUploadGuard::randomFileName('material', 'pdf');
}
if (count(array_unique($generatedNames)) !== count($generatedNames)) {
    $failures[] = 'concurrent_name_collision';
}

try {
    FileUploadGuard::assertSafeOriginalName('photo.php.jpg', ['jpg', 'jpeg', 'png']);
    $failures[] = 'dangerous_image_double_extension';
} catch (InvalidArgumentException $e) {
}

$fake = $makeFile('<?php echo "unsafe";');
try {
    FileUploadGuard::validate([
        'name' => 'document.pdf',
        'tmp_name' => $fake,
        'size' => filesize($fake),
        'error' => UPLOAD_ERR_OK,
    ], ['pdf' => ['application/pdf']], 1024 * 1024);
    $failures[] = 'fake_mime';
} catch (InvalidArgumentException $e) {
}

foreach ($tempFiles as $tempFile) {
    @unlink($tempFile);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: upload validation rejects dangerous names and mismatched content.\n";
