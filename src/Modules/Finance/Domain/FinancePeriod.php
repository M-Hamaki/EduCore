<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain;

use InvalidArgumentException;
use RuntimeException;

final class FinancePeriod
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const REOPENED = 'reopened';

    public function __construct(
        private int $id,
        private int $academicYearId,
        private string $status
    ) {
        if ($id <= 0 || $academicYearId <= 0) {
            throw new InvalidArgumentException('Finance period identifiers must be positive.');
        }
        if (!in_array($status, [self::OPEN, self::CLOSED, self::REOPENED], true)) {
            throw new InvalidArgumentException('Unsupported finance period status.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function academicYearId(): int
    {
        return $this->academicYearId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isWritable(): bool
    {
        return $this->status !== self::CLOSED;
    }

    public function assertWritable(): void
    {
        if (!$this->isWritable()) {
            throw new RuntimeException('The finance period is closed.');
        }
    }
}
