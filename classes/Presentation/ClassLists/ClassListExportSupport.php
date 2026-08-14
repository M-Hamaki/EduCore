<?php

final class ClassListExportSupport
{
    public static function safeFileBase(string $value, string $fallback = 'export'): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', trim($value)) ?? '';
        $value = trim($value, " .\t\n\r\0\x0B");
        $value = mb_substr($value, 0, 100);
        return $value !== '' ? $value : $fallback;
    }

    public static function safeWorksheetTitle(string $value, string $fallback = 'Sheet'): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\[\]:*?\/\\\\]+/u', '_', trim($value)) ?? '';
        $value = trim($value, "' ");
        $value = mb_substr($value, 0, 31);
        return $value !== '' ? $value : $fallback;
    }

    public static function safeCsvValue(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[\t ]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    public static function setSpreadsheetText(object $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit(
            $cell,
            (string) $value,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
    }

    public static function sendDownloadHeaders(string $contentType, string $fileName): void
    {
        $fileName = self::safeFileBase($fileName, 'export');
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $fileName) ?: 'export';
        header('Content-Type: ' . $contentType);
        header(
            'Content-Disposition: attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\''
            . rawurlencode($fileName)
        );
    }
}
