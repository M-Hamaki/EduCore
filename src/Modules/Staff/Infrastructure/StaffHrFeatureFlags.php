<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

final class StaffHrFeatureFlags
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_COMPARE = 'compare';
    public const MODE_DISPLAY = 'display';
    public const MODE_OFFICIAL = 'official';

    private const MODES = [
        self::MODE_OFF,
        self::MODE_SHADOW,
        self::MODE_COMPARE,
        self::MODE_DISPLAY,
        self::MODE_OFFICIAL,
    ];

    public function __construct(private string $mode = self::MODE_OFF)
    {
        $this->mode = strtolower(trim($mode));
        if (!in_array($this->mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Invalid staff HR rollout mode.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self((string) (getenv('STAFF_HR_MODE') ?: self::MODE_OFF));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function calculatesNewResults(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    public function exposesNewResults(): bool
    {
        return in_array($this->mode, [self::MODE_DISPLAY, self::MODE_OFFICIAL], true);
    }

    public function usesNewResultsAsOfficial(): bool
    {
        return $this->mode === self::MODE_OFFICIAL;
    }

    public function usesLegacyFallback(): bool
    {
        return $this->mode !== self::MODE_OFFICIAL;
    }
}

