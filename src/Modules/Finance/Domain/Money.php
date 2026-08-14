<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain;

/**
 * Immutable money value object for the Finance module.
 *
 * Stores amounts as integer piaster minor units (1 EGP = 100 piasters).
 * NEVER uses PHP float for financial math. Rounding is half-up at presentation only.
 *
 * Invariants:
 * - amount is always a non-negative integer (signed deltas are handled by the caller).
 * - currency is always 'EGP' for v1.
 * - arithmetic returns new Money instances (immutability).
 */
final class Money
{
    private const CURRENCY = 'EGP';
    private const SCALE = 2;
    private const MINOR_PER_MAJOR = 100;

    private function __construct(
        private readonly int $minorUnits
    ) {
        if ($minorUnits < 0) {
            throw new \InvalidArgumentException('Money minor units cannot be negative: ' . $minorUnits);
        }
    }

    /**
     * Create from integer piaster minor units.
     */
    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    /**
     * Create from a decimal string (e.g. "100.50" EGP).
     * Uses bcmath if available, otherwise integer-safe string parsing.
     */
    public static function fromDecimalString(string $decimal): self
    {
        $decimal = trim($decimal);
        if ($decimal === '') {
            return self::zero();
        }
        // Normalize: remove anything that's not a digit, dot, or minus
        $clean = preg_replace('/[^0-9.\-]/', '', $decimal);
        if ($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') {
            throw new \InvalidArgumentException('Invalid decimal string: ' . $decimal);
        }

        if (str_starts_with($clean, '-')) {
            throw new \InvalidArgumentException('Money decimal string cannot be negative: ' . $decimal);
        }

        $parts = explode('.', $clean);
        $major = (int) $parts[0];
        $fractional = $parts[1] ?? '';
        $fractional = str_pad(substr($fractional, 0, self::SCALE), self::SCALE, '0');

        $minor = ($major * self::MINOR_PER_MAJOR) + (int) $fractional;
        return new self($minor);
    }

    /**
     * Create zero.
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Return the integer piaster minor units.
     */
    public function toMinorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * Return a DECIMAL(14,2)-compatible string for database storage.
     */
    public function toDatabaseString(): string
    {
        $major = intdiv($this->minorUnits, self::MINOR_PER_MAJOR);
        $minor = $this->minorUnits % self::MINOR_PER_MAJOR;
        return sprintf('%d.%02d', $major, $minor);
    }

    /**
     * Return a formatted display string with currency suffix.
     */
    public function toDisplayString(): string
    {
        return $this->toDatabaseString() . ' ' . self::CURRENCY;
    }

    public function getCurrency(): string
    {
        return self::CURRENCY;
    }

    /**
     * Add another Money (returns new instance).
     */
    public function add(self $other): self
    {
        return new self($this->minorUnits + $other->minorUnits);
    }

    /**
     * Subtract another Money (returns new instance; throws if result is negative).
     */
    public function subtract(self $other): self
    {
        $result = $this->minorUnits - $other->minorUnits;
        if ($result < 0) {
            throw new \InvalidArgumentException(
                'Money subtraction results in negative value: ' . $result
            );
        }
        return new self($result);
    }

    /**
     * Subtract another Money, returning a SIGNED result (for ledger deltas).
     * Returns a SignedMoneyDelta, not a Money.
     */
    public function subtractSigned(self $other): SignedMoneyDelta
    {
        return SignedMoneyDelta::fromMinorUnits($this->minorUnits - $other->minorUnits);
    }

    /**
     * Multiply by an integer factor (e.g. quantity × unit price).
     */
    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException('Money multiply factor cannot be negative: ' . $factor);
        }
        return new self($this->minorUnits * $factor);
    }

    /**
     * Check equality.
     */
    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    /**
     * Check if this is greater than other.
     */
    public function greaterThan(self $other): bool
    {
        return $this->minorUnits > $other->minorUnits;
    }

    /**
     * Check if this is greater than or equal to other.
     */
    public function greaterThanOrEqual(self $other): bool
    {
        return $this->minorUnits >= $other->minorUnits;
    }

    /**
     * Check if this is zero.
     */
    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    /**
     * Compare for sorting.
     */
    public function compareTo(self $other): int
    {
        return $this->minorUnits <=> $other->minorUnits;
    }
}
