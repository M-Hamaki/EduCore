<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure;

use RuntimeException;

require_once dirname(__DIR__, 4) . '/classes/FileUploadGuard.php';

final class LocalFinanceImportStorage
{
    private const MAX_BYTES = 10485760;
    private const MIME_MAP = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    private string $directory;

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $this->directory = $this->projectRoot . '/storage/private/finance_imports';
    }

    /** @return array{relative_ref:string,absolute_path:string,original_name:string,extension:string,mime:string,size:int} */
    public function storeUploadedFile(array $file): array
    {
        $validated = \FileUploadGuard::validate($file, self::MIME_MAP, self::MAX_BYTES);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Finance import storage directory could not be created.');
        }
        $storedName = \FileUploadGuard::randomFileName('finance_import', $validated['extension']);
        $absolutePath = $this->directory . '/' . $storedName;
        if (!move_uploaded_file($validated['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Finance import upload could not be stored.');
        }
        return [
            'relative_ref' => 'private:finance_imports/' . $storedName,
            'absolute_path' => $absolutePath,
            'original_name' => $validated['original_name'],
            'extension' => $validated['extension'],
            'mime' => $validated['mime'],
            'size' => $validated['size'],
        ];
    }

    public function delete(string $relativeRef): void
    {
        $path = $this->absolutePath($relativeRef);
        if (is_file($path) && !unlink($path)) {
            error_log('Failed to delete finance import file: ' . $relativeRef);
        }
    }

    public function absolutePath(string $relativeRef): string
    {
        if (!preg_match('#^private:finance_imports/[A-Za-z0-9_-]+\.(csv|xlsx)$#', $relativeRef)) {
            throw new RuntimeException('Invalid finance import storage reference.');
        }
        return $this->projectRoot . '/storage/private/' . substr($relativeRef, strlen('private:'));
    }
}
