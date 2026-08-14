<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

interface LegacyStaffShiftRepository
{
    /** @return array<string, mixed> */
    public function viewData(): array;

    /** @return array<string, string> */
    public function lockDefaultSettings(): array;

    public function upsertDefaultSetting(string $key, string $value, string $description): void;

    public function isEligibleActiveStaff(int $userId): bool;

    /** @return array<string, mixed>|null */
    public function lockOverrideByUser(int $userId): ?array;

    /** @param array<string, mixed> $values */
    public function storeOverride(array $values): void;

    /** @return array<string, mixed>|null */
    public function findOverrideByUser(int $userId): ?array;

    /** @return array<string, mixed>|null */
    public function lockOverrideById(int $id): ?array;

    public function deleteOverride(int $id): void;
}
