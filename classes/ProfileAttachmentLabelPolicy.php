<?php

declare(strict_types=1);

final class ProfileAttachmentLabelPolicy
{
    public const PROFILE_IMAGE_LABEL = 'الصورة الشخصية';
    public const MAX_LENGTH = 120;

    public static function normalizeEditableLabel(string $label): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($label));
        $normalized = is_string($normalized) ? $normalized : '';

        if ($normalized === '') {
            throw new InvalidArgumentException('يرجى إدخال اسم المرفق.');
        }
        if (mb_strlen($normalized, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidArgumentException('اسم المرفق يجب ألا يتجاوز ' . self::MAX_LENGTH . ' حرفاً.');
        }
        if ($normalized === self::PROFILE_IMAGE_LABEL) {
            throw new InvalidArgumentException('اسم "الصورة الشخصية" محجوز ولا يمكن استخدامه لمرفق إضافي.');
        }

        return $normalized;
    }

    public static function assertCurrentLabelIsEditable(string $currentLabel): void
    {
        if (trim($currentLabel) === self::PROFILE_IMAGE_LABEL) {
            throw new InvalidArgumentException('لا يمكن تغيير اسم مرفق الصورة الشخصية.');
        }
    }

    public static function labelForUpload(string $requestedLabel, string $originalName): string
    {
        if (trim($requestedLabel) !== '') {
            return self::normalizeEditableLabel($requestedLabel);
        }

        $defaultLabel = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $defaultLabel = preg_replace('/\s+/u', ' ', trim((string) $defaultLabel));
        $defaultLabel = is_string($defaultLabel) && $defaultLabel !== '' ? $defaultLabel : 'مرفق';
        if ($defaultLabel === self::PROFILE_IMAGE_LABEL) {
            $defaultLabel = 'مرفق - ' . $defaultLabel;
        }
        if (mb_strlen($defaultLabel, 'UTF-8') > self::MAX_LENGTH) {
            $defaultLabel = mb_substr($defaultLabel, 0, self::MAX_LENGTH, 'UTF-8');
        }

        return $defaultLabel;
    }

    public static function downloadName(string $label, string $originalName): string
    {
        $safeOriginalName = basename(str_replace('\\', '/', $originalName));
        $extension = strtolower(pathinfo($safeOriginalName, PATHINFO_EXTENSION));
        $baseLabel = trim($label);
        if ($baseLabel === '') {
            return $safeOriginalName !== '' ? $safeOriginalName : 'attachment';
        }

        $baseLabel = preg_replace('/[\x00-\x1F\x7F<>:"\/\\|?*]+/u', '-', $baseLabel);
        $baseLabel = trim(is_string($baseLabel) ? $baseLabel : '', " .-\t\n\r\0\x0B");
        if ($baseLabel === '') {
            $baseLabel = 'attachment';
        }

        if ($extension === '') {
            return $baseLabel;
        }
        $suffix = '.' . $extension;
        if (str_ends_with(strtolower($baseLabel), $suffix)) {
            $baseLabel = substr($baseLabel, 0, -strlen($suffix));
        }

        return $baseLabel . $suffix;
    }
}
