<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceExportRenderer;
use EduCore\Modules\Finance\Contracts\FinanceExportStorage;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;

final class ExportService
{
    private const EXTENSIONS = ['csv', 'xlsx', 'pdf'];

    public function __construct(
        private AuditEventWriter $audit,
        private ?FinanceExportRenderer $renderer = null,
        private ?FinanceExportStorage $storage = null
    )
    {
    }

    public function logExport(string $reportType, string $filtersJson, int $rowCount, int $exportedBy): void
    {
        if (trim($reportType) === '' || $rowCount < 0 || $exportedBy <= 0 || (json_decode($filtersJson, true) === null && json_last_error() !== JSON_ERROR_NONE)) {
            throw new InvalidArgumentException('Export filters must be valid JSON.');
        }
        $this->audit->recordEvent('finance_export', 'finance_report', null, $reportType, ['filters' => json_decode($filtersJson, true), 'row_count' => $rowCount, 'exported_by' => $exportedBy]);
    }

    public function tempFilePath(string $prefix, string $extension): string
    {
        $extension = strtolower($extension);
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Unsupported finance export extension.');
        }
        $safePrefix = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix), '-');
        if ($safePrefix === '') {
            $safePrefix = 'finance';
        }
        return 'private:finance_exports/' . $safePrefix . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $requestedColumns @param list<string> $allowedColumns */
    public function export(string $reportType, array $rows, array $requestedColumns, array $allowedColumns, array $filters, int $exportedBy, string $format): string
    {
        $format = strtolower($format);
        if ($this->renderer === null || $this->storage === null || !in_array($format, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Finance export infrastructure or format is unavailable.');
        }
        if ($requestedColumns === [] || count($requestedColumns) !== count(array_unique($requestedColumns))) {
            throw new InvalidArgumentException('Export columns must be non-empty and unique.');
        }
        foreach ($requestedColumns as $column) {
            if (!in_array($column, $allowedColumns, true)) {
                throw new InvalidArgumentException('Export contains a column outside the caller permission scope.');
            }
        }
        $scopedRows = array_map(static function (array $row) use ($requestedColumns): array {
            $scoped = [];
            foreach ($requestedColumns as $column) { $scoped[$column] = $row[$column] ?? ''; }
            return $scoped;
        }, $rows);
        $contents = $this->renderer->render($format, $requestedColumns, $scopedRows);
        $relativeRef = $this->storage->store($reportType, $format, $contents);
        try {
            $this->logExport($reportType, json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), count($scopedRows), $exportedBy);
        } catch (\Throwable $error) {
            $this->storage->delete($relativeRef);
            throw $error;
        }
        return $relativeRef;
    }

    public function cleanupExpired(?int $now = null): int
    {
        if ($this->storage === null) {
            throw new InvalidArgumentException('Finance export storage is unavailable.');
        }
        $deleted = $this->storage->cleanupOlderThan(($now ?? time()) - 86400);
        if ($deleted > 0) {
            $this->audit->recordEvent('finance_export_cleanup', 'finance_export_temp', null, null, ['deleted_count' => $deleted, 'retention_hours' => 24]);
        }
        return $deleted;
    }

    /** @return array{contents:string,extension:string,filename:string} */
    public function download(string $relativeRef, int $downloadedBy): array
    {
        if ($this->storage === null || $downloadedBy <= 0 || !$this->storage->exists($relativeRef)) {
            throw new InvalidArgumentException('Finance export was not found or has expired.');
        }
        if (preg_match('#^private:finance_exports/([A-Za-z0-9_-]+-[a-f0-9]{32})\.(csv|xlsx|pdf)$#', $relativeRef, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid finance export reference.');
        }
        $contents = $this->storage->read($relativeRef);
        $this->audit->recordEvent('finance_export_download', 'finance_export_temp', null, $matches[1], ['downloaded_by' => $downloadedBy, 'extension' => $matches[2]]);
        return ['contents' => $contents, 'extension' => $matches[2], 'filename' => $matches[1] . '.' . $matches[2]];
    }
}
