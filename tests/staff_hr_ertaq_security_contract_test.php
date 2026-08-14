<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Staff\Infrastructure\LocalErtaqAttachmentStorage;

$failures = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $work, string $expectedClass, string $message) use (&$failures, &$assertions): void {
    ++$assertions;
    try {
        $work();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if (!($exception instanceof $expectedClass)) {
            fwrite(STDERR, "FAIL: {$message} (got " . $exception::class . ")\n");
            ++$failures;
        }
    }
};

$tempRoot = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'educore_ertaq_attachment_' . bin2hex(random_bytes(8));
$pdfOne = $tempRoot . DIRECTORY_SEPARATOR . 'one.pdf';
$pdfTwo = $tempRoot . DIRECTORY_SEPARATOR . 'two.pdf';
$textFile = $tempRoot . DIRECTORY_SEPARATOR . 'spoof.txt';
if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
    fwrite(STDERR, "FAIL: unable to create isolated attachment test directory\n");
    exit(1);
}

try {
    $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    file_put_contents($pdfOne, $pdfBytes);
    file_put_contents($pdfTwo, $pdfBytes);
    file_put_contents($textFile, 'not a PDF');
    $storage = new LocalErtaqAttachmentStorage(
        $tempRoot,
        static fn (string $source, string $destination): bool => copy($source, $destination)
    );
    $validFile = static fn (string $tmpName, string $name = 'private.pdf', ?int $size = null): array => [
        'name' => $name,
        'tmp_name' => $tmpName,
        'size' => $size ?? (int) filesize($tmpName),
        'error' => UPLOAD_ERR_OK,
    ];

    $first = $storage->storeUploadedFile($validFile($pdfOne));
    $second = $storage->storeUploadedFile($validFile($pdfTwo));
    $assert(
        $first['storage_ref'] !== $second['storage_ref']
            && str_starts_with($first['storage_ref'], 'private:ertaq_attachments/')
            && is_file($storage->absolutePath($first['storage_ref']))
            && is_file($storage->absolutePath($second['storage_ref']))
            && !str_contains($storage->absolutePath($first['storage_ref']), '/uploads/'),
        'two valid private uploads receive distinct random non-public storage references'
    );
    $assert(
        $storage->delete($first['storage_ref'])
            && $storage->delete($second['storage_ref'])
            && !is_file($storage->absolutePath($first['storage_ref'])),
        'private storage cleanup accepts only its normalized reference and removes the selected file'
    );
    $assertThrows(
        static fn (): array => $storage->storeUploadedFile($validFile($pdfOne, 'payload.php.pdf')),
        InvalidArgumentException::class,
        'dangerous double extension is rejected even when the bytes are a valid PDF'
    );
    $assertThrows(
        static fn (): array => $storage->storeUploadedFile($validFile($textFile, 'spoof.pdf')),
        InvalidArgumentException::class,
        'extension spoofing is rejected by detected MIME rather than browser name'
    );
    $assertThrows(
        static fn (): array => $storage->storeUploadedFile($validFile($pdfOne, 'large.pdf', 10485761)),
        InvalidArgumentException::class,
        'declared size above the explicit ten-megabyte limit is rejected before storage'
    );
    $assertThrows(
        static fn (): array => $storage->storeUploadedFile([
            'name' => 'partial.pdf',
            'tmp_name' => $pdfOne,
            'size' => (int) filesize($pdfOne),
            'error' => UPLOAD_ERR_PARTIAL,
        ]),
        InvalidArgumentException::class,
        'PHP upload error state is rejected before detected MIME or filesystem movement'
    );
    $assertThrows(
        static fn (): string => $storage->absolutePath('private:ertaq_attachments/../../webshell.php'),
        RuntimeException::class,
        'private storage reference parser rejects traversal and executable names'
    );

    $root = dirname(__DIR__);
    $manifest = json_decode((string) file_get_contents($root . '/tools/upload_policy_manifest.json'), true);
    $serviceSource = (string) file_get_contents(
        $root . '/src/Modules/Staff/Application/Ertaq/ErtaqAttachmentNotificationService.php'
    );
    $repositorySource = (string) file_get_contents(
        $root . '/src/Modules/Staff/Infrastructure/PdoErtaqAttachmentNotificationRepository.php'
    );
    $migration = (string) file_get_contents(
        $root . '/database/migrations/20260809_staff_hr_ertaq_private_attachments.php'
    );
    $assert(
        is_array($manifest)
            && isset($manifest['reviewed_paths']['src/Modules/Staff/Infrastructure/LocalErtaqAttachmentStorage.php'])
            && str_contains($serviceSource, 'لديك تحديث جديد في منصة ارتق.')
            && str_contains($serviceSource, 'StaffNotificationPort')
            && !str_contains($serviceSource, 'http://')
            && !str_contains($serviceSource, 'https://'),
        'notification contract is reviewed and service-owned text remains neutral without an external route literal'
    );
    $assert(
        !str_contains($repositorySource, 'SELECT *')
            && !str_contains($repositorySource, 'body_cipher_or_text')
            && !str_contains($repositorySource, 'subject')
            && str_contains($migration, 'trg_staff_resource_attachment_no_delete'),
        'attachment persistence reads no message body or ticket subject and schema keeps private metadata append-only'
    );
} finally {
    $storedDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'ertaq_attachments';
    if (is_dir($storedDirectory)) {
        foreach (glob($storedDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $storedFile) {
            if (is_file($storedFile)) {
                @unlink($storedFile);
            }
        }
        @rmdir($storedDirectory);
    }
    $privateDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private';
    $storageDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'storage';
    if (is_dir($privateDirectory)) {
        @rmdir($privateDirectory);
    }
    if (is_dir($storageDirectory)) {
        @rmdir($storageDirectory);
    }
    foreach ([$pdfOne, $pdfTwo, $textFile] as $sourceFile) {
        if (is_file($sourceFile)) {
            @unlink($sourceFile);
        }
    }
    @rmdir($tempRoot);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq security contract test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_security_contract_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
