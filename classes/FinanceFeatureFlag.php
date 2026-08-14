<?php

declare(strict_types=1);

/**
 * Finance feature flag for gradual cutover.
 *
 * Modes: 'off' → 'shadow' → 'display' → 'execute'
 * - off: Finance module disabled; legacy pages operate as-is.
 * - shadow: Finance services run in parallel, computing balances from sub-ledger and comparing with legacy (no writes to legacy).
 * - display: Finance balances shown alongside legacy for comparison.
 * - execute: Finance module is the active source of truth; legacy pages delegate to Finance services.
 *
 * Controlled by env('FINANCE_LEDGER_MODE', 'off').
 */
final class FinanceFeatureFlag
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_DISPLAY = 'display';
    public const MODE_EXECUTE = 'execute';

    private static ?string $override = null;

    public static function mode(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }
        return (string) (function_exists('env') ? env('FINANCE_LEDGER_MODE', self::MODE_OFF) : self::MODE_OFF);
    }

    public static function isEnabled(): bool
    {
        return self::mode() !== self::MODE_OFF;
    }

    public static function isShadow(): bool
    {
        return self::mode() === self::MODE_SHADOW;
    }

    public static function isDisplay(): bool
    {
        return self::mode() === self::MODE_DISPLAY;
    }

    public static function isExecute(): bool
    {
        return self::mode() === self::MODE_EXECUTE;
    }

    /**
     * Override mode for testing.
     */
    public static function setOverride(?string $mode): void
    {
        if ($mode !== null && !in_array($mode, [self::MODE_OFF, self::MODE_SHADOW, self::MODE_DISPLAY, self::MODE_EXECUTE], true)) {
            throw new InvalidArgumentException('Invalid finance mode: ' . $mode);
        }
        self::$override = $mode;
    }
}
