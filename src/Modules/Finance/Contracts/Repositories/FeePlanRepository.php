<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for fee plans, versions, and installments.
 */
interface FeePlanRepository
{
    public function createPlan(int $chargeTypeId, int $academicYearId, ?int $gradeId, string $name, int $createdBy): int;
    public function nextVersionNumber(int $feePlanId): int;
    public function findActivePlan(int $chargeTypeId, int $academicYearId, ?int $gradeId): ?array;

    public function findPlan(int $chargeTypeId, int $academicYearId, ?int $gradeId): ?array;

    public function findPlanById(int $feePlanId): ?array;

    public function createVersion(int $feePlanId, int $versionNumber, ?string $snapshotJson, string $effectiveFrom): int;

    public function findVersion(int $versionId): ?array;

    public function findActiveVersion(int $feePlanId): ?array;

    public function supersedeVersion(int $versionId): void;

    public function activateVersion(int $versionId): void;

    public function isVersionUsed(int $versionId): bool;

    public function isPlanUsed(int $feePlanId): bool;

    public function archivePlan(int $feePlanId): void;

    public function addInstallment(int $versionId, string $name, string $grossAmount, ?string $dueDate, int $displayOrder): int;

    public function installmentsForVersion(int $versionId): array;
}
