<?php

declare(strict_types=1);

final class FileUploadGuard
{
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'shtml', 'htaccess',
    ];

    /**
     * @param array<string, mixed> $file
     * @param array<string, list<string>> $allowedMimeByExtension
     * @return array{extension:string,mime:string,size:int,original_name:string,tmp_name:string}
     */
    public static function validate(array $file, array $allowedMimeByExtension, int $maxBytes): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::uploadErrorMessage($error));
        }

        $originalName = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $tmpName = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($originalName === '' || $tmpName === '' || $size <= 0) {
            throw new InvalidArgumentException('الملف المرفوع فارغ أو غير صالح.');
        }
        if ($size > $maxBytes) {
            throw new InvalidArgumentException('حجم الملف يتجاوز الحد الأقصى المسموح به.');
        }

        $parts = explode('.', strtolower($originalName));
        $extension = count($parts) > 1 ? (string)array_pop($parts) : '';
        if ($extension === '' || !array_key_exists($extension, $allowedMimeByExtension)) {
            throw new InvalidArgumentException('نوع الملف غير مسموح.');
        }
        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new InvalidArgumentException('اسم الملف يحتوي على امتداد مزدوج خطر.');
            }
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)($finfo->file($tmpName) ?: 'application/octet-stream');
        if (!in_array($mime, $allowedMimeByExtension[$extension], true)) {
            throw new InvalidArgumentException('محتوى الملف لا يطابق نوعه المسموح.');
        }

        return [
            'extension' => $extension,
            'mime' => $mime,
            'size' => $size,
            'original_name' => $originalName,
            'tmp_name' => $tmpName,
        ];
    }

    public static function randomFileName(string $prefix, string $extension): string
    {
        $safePrefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix) ?: 'upload';
        return $safePrefix . '_' . bin2hex(random_bytes(16)) . '.' . strtolower($extension);
    }

    /** @param list<string> $allowedExtensions */
    public static function assertSafeOriginalName(string $originalName, array $allowedExtensions): string
    {
        $baseName = basename(str_replace('\\', '/', $originalName));
        $parts = explode('.', strtolower($baseName));
        $extension = count($parts) > 1 ? (string)array_pop($parts) : '';
        if ($baseName === '' || $baseName !== $originalName || !in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException('نوع الملف غير مسموح.');
        }
        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new InvalidArgumentException('اسم الملف يحتوي على امتداد مزدوج خطر.');
            }
        }
        return $extension;
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الملف يتجاوز الحد المسموح به من الخادم.',
            UPLOAD_ERR_PARTIAL => 'لم يكتمل رفع الملف. يرجى إعادة المحاولة.',
            UPLOAD_ERR_NO_FILE => 'يرجى اختيار ملف للرفع.',
            default => 'حدث خطأ أثناء رفع الملف.',
        };
    }
}
