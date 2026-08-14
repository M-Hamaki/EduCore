<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface BusFeeScheduleRepository
{
    /** @return array<string,mixed>|null */
    public function findActiveByLegacyKey(int $academicYearId, string $legacyZoneKey): ?array;

    /** @return array<string,mixed>|null */
    public function findActiveBySubscriptionKey(int $academicYearId, string $subscriptionKey, string $atDate): ?array;

    public function createVersion(array $fields): int;

    public function activate(int $scheduleId): void;

    public function archiveByLegacyKey(int $academicYearId, string $legacyZoneKey): void;
}
