<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure;

use DirectoryIterator;
use RuntimeException;

final class LocalFinanceExportStorage implements \EduCore\Modules\Finance\Contracts\FinanceExportStorage
{
    private string $directory;

    public function __construct(string $projectRoot)
    {
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $this->directory = $root . '/storage/private/finance_exports';
    }

    public function store(string $prefix, string $extension, string $contents): string
    {
        if (!in_array($extension, ['csv', 'xlsx', 'pdf'], true)) {
            throw new RuntimeException('Unsupported finance export extension.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Finance export storage directory could not be created.');
        }
        $safePrefix = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix), '-') ?: 'finance';
        $name = $safePrefix . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $this->directory . '/' . $name;
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Finance export could not be stored.');
        }
        return 'private:finance_exports/' . $name;
    }

    public function delete(string $relativeRef): void
    {
        $path = $this->resolve($relativeRef);
        if (is_file($path) && !unlink($path)) {
            error_log('Failed to delete temporary finance export: ' . $relativeRef);
        }
    }

    public function cleanupOlderThan(int $unixTimestamp): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $deleted = 0;
        foreach (new DirectoryIterator($this->directory) as $file) {
            if (!$file->isFile() || preg_match('/^[A-Za-z0-9_-]+-[a-f0-9]{32}\.(csv|xlsx|pdf)$/', $file->getFilename()) !== 1 || $file->getMTime() >= $unixTimestamp) {
                continue;
            }
            if (@unlink($file->getPathname())) {
                ++$deleted;
            } else {
                error_log('Failed to clean expired finance export: ' . $file->getFilename());
            }
        }
        return $deleted;
    }

    public function exists(string $relativeRef): bool
    {
        return is_file($this->resolve($relativeRef));
    }

    public function read(string $relativeRef): string
    {
        $path = $this->resolve($relativeRef);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException('Finance export was not found or has expired.');
        }
        return $contents;
    }

    private function resolve(string $relativeRef): string
    {
        if (!preg_match('#^private:finance_exports/[A-Za-z0-9_-]+-[a-f0-9]{32}\.(csv|xlsx|pdf)$#', $relativeRef)) {
            throw new RuntimeException('Invalid finance export reference.');
        }
        return $this->directory . '/' . basename(substr($relativeRef, strlen('private:finance_exports/')));
    }
}
