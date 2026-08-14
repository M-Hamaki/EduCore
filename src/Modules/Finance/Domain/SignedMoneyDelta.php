<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain;

/**
 * Signed money delta for sub-ledger lines.
 *
 * Unlike Money (which is always non-negative), a SignedMoneyDelta can be
 * positive (debit / increases due) or negative (credit / decreases due).
 * Used by finance_subledger_lines.amount_delta.
 */
final class SignedMoneyDelta
{
    private const MINOR_PER_MAJOR = 100;
    private const SCALE = 2;

    private function __construct(
        private readonly int $minorUnits
    ) {
    }

    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    public static function fromDecimalString(string $decimal): self
    {
        $decimal = trim($decimal);
        if ($decimal === '') {
            return new self(0);
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $decimal);
        if ($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') {
            throw new \InvalidArgumentException('Invalid signed decimal string: ' . $decimal);
        }

        $negative = str_starts_with($clean, '-');
        $abs = ltrim($clean, '-');
        $parts = explode('.', $abs);
        $major = (int) $parts[0];
        $fractional = $parts[1] ?? '';
        $fractional = str_pad(substr($fractional, 0, self::SCALE), self::SCALE, '0');

        $minor = ($major * self::MINOR_PER_MAJOR) + (int) $fractional;
        return new self($negative ? -$minor : $minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function toMinorUnits(): int
    {
        return $this->minorUnits;
    }

    public function toDatabaseString(): string
    {
        $abs = abs($this->minorUnits);
        $major = intdiv($abs, self::MINOR_PER_MAJOR);
        $minor = $abs % self::MINOR_PER_MAJOR;
        $sign = $this->minorUnits < 0 ? '-' : '';
        return sprintf('%s%d.%02d', $sign, $major, $minor);
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits);
    }

    public function add(self $other): self
    {
        return new self($this->minorUnits + $other->minorUnits);
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }
}
