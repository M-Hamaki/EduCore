<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Domain\Leave;

use InvalidArgumentException;

/**
 * Exact three-decimal leave-unit arithmetic.
 *
 * Database DECIMAL(12,3) values are represented as signed integer
 * thousandths inside the application so balance calculations never rely on
 * binary floating point.
 */
final class LeaveUnits
{
    public const SCALE = 1000;

    public static function fromDecimal(mixed $value, bool $allowNegative, string $error): int
    {
        if (is_int($value)) {
            if (!$allowNegative && $value < 0) {
                throw new InvalidArgumentException($error);
            }

            return self::wholeToMilli($value, $error);
        }
        $raw = trim((string) $value);
        $pattern = $allowNegative
            ? '/^(-?)([0-9]+)(?:\.([0-9]{1,3}))?$/'
            : '/^([0-9]+)(?:\.([0-9]{1,3}))?$/';
        if (preg_match($pattern, $raw, $matches) !== 1) {
            throw new InvalidArgumentException($error);
        }

        if ($allowNegative) {
            $negative = ($matches[1] ?? '') === '-';
            $whole = (int) ($matches[2] ?? 0);
            $fraction = str_pad($matches[3] ?? '', 3, '0');
        } else {
            $negative = false;
            $whole = (int) ($matches[1] ?? 0);
            $fraction = str_pad($matches[2] ?? '', 3, '0');
        }
        $milli = self::wholeToMilli($whole, $error) + (int) $fraction;

        return $negative ? -$milli : $milli;
    }

    public static function nullableFromDecimal(mixed $value, bool $allowNegative, string $error): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return self::fromDecimal($value, $allowNegative, $error);
    }

    public static function fractionMilli(int $numerator, int $denominator, string $error): int
    {
        if ($numerator <= 0 || $denominator <= 0) {
            throw new InvalidArgumentException($error);
        }
        if ($numerator > intdiv(PHP_INT_MAX, self::SCALE)) {
            throw new InvalidArgumentException($error);
        }

        return intdiv($numerator * self::SCALE + intdiv($denominator, 2), $denominator);
    }

    public static function format(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $absolute = abs($value);

        return $sign . intdiv($absolute, self::SCALE) . '.' . str_pad(
            (string) ($absolute % self::SCALE),
            3,
            '0',
            STR_PAD_LEFT
        );
    }

    private static function wholeToMilli(int $whole, string $error): int
    {
        if ($whole > intdiv(PHP_INT_MAX, self::SCALE)) {
            throw new InvalidArgumentException($error);
        }

        return $whole * self::SCALE;
    }
}
