<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

interface LegacyStaffShiftAuditWriter
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function defaultSettingsChanged(array $before, array $after): void;

    /** @param array<string, mixed> $after */
    public function overrideCreated(int $id, string $staffName, array $after): void;

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function overrideUpdated(int $id, string $staffName, array $before, array $after): void;

    /** @param array<string, mixed> $before */
    public function overrideDeleted(int $id, string $staffName, array $before): void;
}
