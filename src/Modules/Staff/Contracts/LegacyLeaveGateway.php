<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Compatibility boundary for the stable legacy leave administration routes.
 *
 * The existing staff_leaves and balance settings remain available while the
 * self-service leave workflow is rolled out. Admin pages must use this
 * contract instead of reaching the legacy service or its schema guard
 * directly.
 */
interface LegacyLeaveGateway
{
    /** @return list<array<string,mixed>> */
    public function activeStaffList(): array;

    /** @return array<string,mixed>|null */
    public function leaveById(int $leaveId): ?array;

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function leaves(array $filters = []): array;

    /** @return array{leave_stats_map:array<string,array<string,mixed>>,status_stats:array<string,int>,total:int} */
    public function leaveStats(): array;

    /** @param array<string,string> $leaveTypes @return list<string> */
    public function deductibleTypes(array $leaveTypes): array;

    /** @return list<array{label:string,months_from:int,months_to:int|null,balance:float|int}> */
    public function leaveBalancePolicy(): array;

    /**
     * @param list<string> $deductibleTypes
     * @return list<array<string,mixed>>
     */
    public function annualLeaveBalanceRows(
        int $year,
        array $deductibleTypes,
        ?int $userId = null,
        string $role = 'teacher'
    ): array;

    /** @param list<string> $selectedDeductTypes @param array<string,string> $leaveTypes */
    public function saveDeductibleTypes(array $selectedDeductTypes, array $leaveTypes): void;

    /** @param array<string,mixed> $data */
    public function saveLeave(array $data, int $actorId, ?int $leaveId = null): int;

    public function deleteLeave(int $leaveId): bool;

    /** @param list<array<string,mixed>> $tiers */
    public function saveLeaveBalancePolicy(array $tiers): void;

    /** @param list<string> $deductibleTypes */
    public function applyLeaveBalancePolicy(
        int $year,
        array $deductibleTypes,
        string $role = 'teacher',
        ?int $userId = null
    ): int;

    public function updateAnnualLeaveBalance(int $userId, float $balance, string $notes = ''): void;
}
