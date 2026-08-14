<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/FileUploadGuard.php';
require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Infrastructure\FinanceImportFileParser;

$root = dirname(__DIR__);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};
$rejects = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); $assert(false, $message); } catch (InvalidArgumentException) { $assert(true, $message); }
};

$temp = tempnam(sys_get_temp_dir(), 'finance-upload-');
if ($temp === false) { throw new RuntimeException('Temporary upload fixture could not be created.'); }
file_put_contents($temp, "schema_version,1.0\nvoucher_type,amount\nexpense,10.00\n");
$size = filesize($temp);
$mimeMap = ['csv' => ['text/plain', 'text/csv']];

try {
    $valid = FileUploadGuard::validate(['error' => UPLOAD_ERR_OK, 'name' => 'سندات.csv', 'tmp_name' => $temp, 'size' => $size], $mimeMap, 1024);
    $assert($valid['extension'] === 'csv' && $valid['size'] === $size, 'real MIME and Arabic display name are accepted for CSV');
    $rejects(static fn () => FileUploadGuard::validate(['error' => UPLOAD_ERR_OK, 'name' => 'evil.php.csv', 'tmp_name' => $temp, 'size' => $size], $mimeMap, 1024), 'dangerous double extension is rejected');
    $rejects(static fn () => FileUploadGuard::validate(['error' => UPLOAD_ERR_OK, 'name' => 'fake.xlsx', 'tmp_name' => $temp, 'size' => $size], ['xlsx' => ['application/zip']], 1024), 'spoofed XLSX MIME is rejected');
    $rejects(static fn () => FileUploadGuard::validate(['error' => UPLOAD_ERR_OK, 'name' => 'large.csv', 'tmp_name' => $temp, 'size' => $size], $mimeMap, 1), 'oversized import is rejected');
    $rejects(static fn () => FileUploadGuard::validate(['error' => UPLOAD_ERR_PARTIAL, 'name' => 'partial.csv', 'tmp_name' => $temp, 'size' => $size], $mimeMap, 1024), 'partial upload error is rejected');
    $nameA = FileUploadGuard::randomFileName('finance_import', 'csv');
    $nameB = FileUploadGuard::randomFileName('finance_import', 'csv');
    $assert($nameA !== $nameB && preg_match('/^finance_import_[a-f0-9]{32}\.csv$/', $nameA) === 1, 'stored import names are collision-resistant and unrelated to display names');

    $parsed = (new FinanceImportFileParser())->parse($temp, 'csv');
    $assert($parsed['schema_version'] === '1.0' && $parsed['headers'] === ['voucher_type', 'amount'] && count($parsed['rows']) === 1, 'CSV template schema and rows parse without business writes');

    $manifest = json_decode((string) file_get_contents($root . '/tools/upload_policy_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $storagePath = 'src/Modules/Finance/Infrastructure/LocalFinanceImportStorage.php';
    $assert(isset($manifest['reviewed_paths'][$storagePath]), 'finance import storage path is classified in upload policy manifest');
    $storageSource = (string) file_get_contents($root . '/' . $storagePath);
    foreach (['FileUploadGuard::validate', 'move_uploaded_file(', 'private:finance_imports/', 'delete('] as $marker) {
        $assert(str_contains($storageSource, $marker), 'finance import storage contains required marker ' . $marker);
    }
    $assert(str_contains($storageSource, 'unlink(') && str_contains($storageSource, 'error_log('), 'stored-file rollback and cleanup failures are handled explicitly');
} finally {
    @unlink($temp);
}

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance import upload safety contract PASSED.\n";
