<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure;

use InvalidArgumentException;
use RuntimeException;

final class FinanceImportFileParser
{
    private const MAX_ROWS = 10000;

    /** @return array{schema_version:string,headers:list<string>,rows:list<array<string,string>>} */
    public function parse(string $absolutePath, string $extension): array
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Finance import file was not found.');
        }
        $matrix = match (strtolower($extension)) {
            'csv' => $this->csvRows($absolutePath),
            'xlsx' => $this->xlsxRows($absolutePath),
            default => throw new InvalidArgumentException('Unsupported finance import format.'),
        };
        if (count($matrix) < 2 || strtolower(trim((string) ($matrix[0][0] ?? ''))) !== 'schema_version') {
            throw new InvalidArgumentException('Import template must start with schema_version.');
        }
        $schemaVersion = trim((string) ($matrix[0][1] ?? ''));
        if (!preg_match('/^\d+\.\d+$/', $schemaVersion)) {
            throw new InvalidArgumentException('Import schema version is invalid.');
        }
        $headers = array_map(static fn (mixed $value): string => trim((string) $value), $matrix[1]);
        $headers = array_values(array_filter($headers, static fn (string $value): bool => $value !== ''));
        if ($headers === [] || count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('Import headers are empty or duplicated.');
        }
        $rows = [];
        foreach (array_slice($matrix, 2) as $values) {
            $values = array_slice(array_pad(array_map(static fn (mixed $value): string => trim((string) $value), $values), count($headers), ''), 0, count($headers));
            if (count(array_filter($values, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($headers, $values);
            if (count($rows) > self::MAX_ROWS) {
                throw new InvalidArgumentException('Finance import exceeds the maximum row count.');
            }
        }
        return ['schema_version' => $schemaVersion, 'headers' => $headers, 'rows' => $rows];
    }

    /** @return list<list<string>> */
    private function csvRows(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false || !mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('CSV import must use UTF-8 encoding.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV import could not be opened.');
        }
        $rows = [];
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if ($rows === [] && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $rows[] = array_map('strval', $row);
                if (count($rows) > self::MAX_ROWS + 2) {
                    throw new InvalidArgumentException('Finance import exceeds the maximum row count.');
                }
            }
        } finally {
            fclose($handle);
        }
        return $rows;
    }

    /** @return list<list<string>> */
    private function xlsxRows(string $path): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('PhpSpreadsheet is required for XLSX finance imports.');
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        try {
            return $spreadsheet->getActiveSheet()->toArray('', true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
