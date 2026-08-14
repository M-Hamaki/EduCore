<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\LegacyLeaveGateway;
use PDO;

/**
 * Infrastructure adapter around the existing audited StaffLeaveService.
 *
 * The adapter deliberately contains no SQL or schema mutation. It is the
 * single temporary bridge for the two legacy admin leave routes until their
 * records are migrated through an explicit rollout.
 */
final class StaffLeaveLegacyGateway implements LegacyLeaveGateway
{
    private \StaffLeaveService $legacy;

    public function __construct(PDO $db)
    {
        require_once dirname(__DIR__, 4) . '/classes/StaffLeaveService.php';
        $this->legacy = new \StaffLeaveService($db);
    }

    public function activeStaffList(): array
    {
        return $this->legacy->getActiveStaffList();
    }

    public function leaveById(int $leaveId): ?array
    {
        return $this->legacy->getLeaveById($leaveId);
    }

    public function leaves(array $filters = []): array
    {
        return $this->legacy->getLeaves($filters);
    }

    public function leaveStats(): array
    {
        return $this->legacy->getLeaveStats();
    }

    public function deductibleTypes(array $leaveTypes): array
    {
        return $this->legacy->getDeductibleTypes($leaveTypes);
    }

    public function leaveBalancePolicy(): array
    {
        return $this->legacy->getLeaveBalancePolicy();
    }

    public function annualLeaveBalanceRows(
        int $year,
        array $deductibleTypes,
        ?int $userId = null,
        string $role = 'teacher'
    ): array {
        return $this->legacy->getAnnualLeaveBalanceRows($year, $deductibleTypes, $userId, $role);
    }

    public function saveDeductibleTypes(array $selectedDeductTypes, array $leaveTypes): void
    {
        $this->legacy->saveDeductibleTypes($selectedDeductTypes, $leaveTypes);
    }

    public function saveLeave(array $data, int $actorId, ?int $leaveId = null): int
    {
        return $this->legacy->saveLeave($data, $actorId, $leaveId);
    }

    public function deleteLeave(int $leaveId): bool
    {
        return $this->legacy->deleteLeave($leaveId);
    }

    public function saveLeaveBalancePolicy(array $tiers): void
    {
        $this->legacy->saveLeaveBalancePolicy($tiers);
    }

    public function applyLeaveBalancePolicy(
        int $year,
        array $deductibleTypes,
        string $role = 'teacher',
        ?int $userId = null
    ): int {
        return $this->legacy->applyLeaveBalancePolicy($year, $deductibleTypes, $role, $userId);
    }

    public function updateAnnualLeaveBalance(int $userId, float $balance, string $notes = ''): void
    {
        $this->legacy->updateAnnualLeaveBalance($userId, $balance, $notes);
    }
}
