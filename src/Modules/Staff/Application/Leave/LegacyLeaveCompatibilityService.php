<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use EduCore\Modules\Staff\Contracts\LegacyLeaveGateway;
use InvalidArgumentException;

/**
 * Application owner for the stable admin leave and balance entrypoints.
 *
 * This is an intentional migration adapter: it preserves the routes, field
 * names, and audited legacy records while preventing presentation code from
 * constructing StaffLeaveService or invoking runtime schema checks.
 */
final class LegacyLeaveCompatibilityService
{
    /** @var list<string> */
    private const ALLOWED_ROLES = ['teacher', 'specialist', 'admin', 'all'];

    public function __construct(private LegacyLeaveGateway $gateway)
    {
    }

    /** @return list<array<string,mixed>> */
    public function getActiveStaffList(): array
    {
        return $this->gateway->activeStaffList();
    }

    /** @return array<string,mixed>|null */
    public function getLeaveById(int $leaveId): ?array
    {
        return $leaveId > 0 ? $this->gateway->leaveById($leaveId) : null;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function getLeaves(array $filters = []): array
    {
        return $this->gateway->leaves($filters);
    }

    /** @return array{leave_stats_map:array<string,array<string,mixed>>,status_stats:array<string,int>,total:int} */
    public function getLeaveStats(): array
    {
        return $this->gateway->leaveStats();
    }

    /** @param array<string,string> $leaveTypes @return list<string> */
    public function getDeductibleTypes(array $leaveTypes): array
    {
        return $this->gateway->deductibleTypes($leaveTypes);
    }

    /** @return list<array{label:string,months_from:int,months_to:int|null,balance:float|int}> */
    public function getLeaveBalancePolicy(): array
    {
        return $this->gateway->leaveBalancePolicy();
    }

    /**
     * @param list<string> $deductibleTypes
     * @return list<array<string,mixed>>
     */
    public function getAnnualLeaveBalanceRows(
        int $year,
        array $deductibleTypes,
        ?int $userId = null,
        string $role = 'teacher'
    ): array {
        $this->assertYear($year);
        $this->assertRole($role);

        return $this->gateway->annualLeaveBalanceRows($year, $deductibleTypes, $userId, $role);
    }

    /** @param list<string> $selectedDeductTypes @param array<string,string> $leaveTypes */
    public function saveDeductibleTypes(array $selectedDeductTypes, array $leaveTypes): void
    {
        $this->gateway->saveDeductibleTypes($selectedDeductTypes, $leaveTypes);
    }

    /** @param array<string,mixed> $data */
    public function saveLeave(array $data, int $actorId, ?int $leaveId = null): int
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('تعذر التحقق من المستخدم المنفذ للعملية. أعد تسجيل الدخول ثم حاول مرة أخرى.');
        }
        if ($leaveId !== null && $leaveId <= 0) {
            throw new InvalidArgumentException('تعذر تحديد الإجازة المطلوب تعديلها.');
        }

        return $this->gateway->saveLeave($data, $actorId, $leaveId);
    }

    public function deleteLeave(int $leaveId): bool
    {
        if ($leaveId <= 0) {
            throw new InvalidArgumentException('تعذر تحديد الإجازة المطلوب حذفها.');
        }

        return $this->gateway->deleteLeave($leaveId);
    }

    /** @param list<array<string,mixed>> $tiers */
    public function saveLeaveBalancePolicy(array $tiers): void
    {
        $this->gateway->saveLeaveBalancePolicy($tiers);
    }

    /** @param list<string> $deductibleTypes */
    public function applyLeaveBalancePolicy(
        int $year,
        array $deductibleTypes,
        string $role = 'teacher',
        ?int $userId = null
    ): int {
        $this->assertYear($year);
        $this->assertRole($role);
        if ($userId !== null && $userId <= 0) {
            throw new InvalidArgumentException('العامل المحدد غير صالح.');
        }

        return $this->gateway->applyLeaveBalancePolicy($year, $deductibleTypes, $role, $userId);
    }

    public function updateAnnualLeaveBalance(int $userId, float $balance, string $notes = ''): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('العامل المحدد غير صالح.');
        }
        if ($balance < 0) {
            throw new InvalidArgumentException('رصيد الإجازات يجب أن يكون صفراً أو أكبر.');
        }

        $this->gateway->updateAnnualLeaveBalance($userId, $balance, $notes);
    }

    private function assertYear(int $year): void
    {
        if ($year < 2020 || $year > 2100) {
            throw new InvalidArgumentException('سنة رصيد الإجازات غير صالحة.');
        }
    }

    private function assertRole(string $role): void
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('فئة العاملين المختارة غير صالحة.');
        }
    }
}
