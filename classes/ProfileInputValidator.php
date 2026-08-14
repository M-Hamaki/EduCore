<?php

final class ProfileInputValidator
{
    public static function nationalId(?string $value, string $label): void
    {
        self::optionalDigits($value, 14, "{$label} يجب أن يكون 14 رقمًا.");
    }

    public static function mobile(?string $value, string $label): void
    {
        self::optionalDigits($value, 11, "{$label} يجب أن يكون 11 رقمًا.");
    }

    public static function landline(?string $value, string $label): void
    {
        $value = trim((string) $value);
        if ($value !== '' && !preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException("{$label} يجب أن يحتوي على أرقام فقط.");
        }
    }

    public static function birthDate(?string $value, string $subjectLabel): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if (!$date || $hasErrors || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("تاريخ ميلاد {$subjectLabel} غير صالح.");
        }
        if ($date > new DateTimeImmutable('today')) {
            throw new InvalidArgumentException("تاريخ ميلاد {$subjectLabel} لا يمكن أن يكون في المستقبل.");
        }
    }

    private static function optionalDigits(?string $value, int $length, string $message): void
    {
        $value = trim((string) $value);
        if ($value !== '' && !preg_match('/^\d{' . $length . '}$/', $value)) {
            throw new InvalidArgumentException($message);
        }
    }
}
