<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Queries;

/**
 * Finance-owned read boundary for translating legacy public contracts.
 *
 * It may read the retained legacy Finance tables during cutover, but callers
 * must never use those rows as the new ledger balance truth.
 */
interface LegacyFinanceReadQuery
{
    public function chargeTypeId(string $code): ?int;

    /** @return array<string,mixed>|null */
    public function feePlan(int $chargeTypeId, int $academicYearId, ?int $gradeId): ?array;

    /** @return array<string,mixed>|null */
    public function feePlanById(int $planId): ?array;

    /** @return array<string,mixed>|null */
    public function activeFeePlanVersion(int $planId): ?array;

    /** @return list<array<string,mixed>> */
    public function feePlanInstallments(int $versionId): array;

    /** @return array{grade_id:int,academic_year:string}|null */
    public function legacyFeeStructureCoordinates(int $legacyId): ?array;

    /** @return array<string,mixed>|null */
    public function legacyOtherDiscount(int $legacyId): ?array;

    /** @return array<string,mixed>|null */
    public function studentAccount(int $studentId, int $academicYearId): ?array;

    /** @return array<string,mixed>|null */
    public function activeStudentCharge(int $studentId, int $academicYearId, int $chargeTypeId): ?array;

    /** @return list<array<string,mixed>> */
    public function chargeInstallments(int $chargeId): array;

    /** @return list<array<string,mixed>> */
    public function studentReceipts(int $studentAccountId): array;

    /** @return array<string,mixed> */
    public function studentTotals(int $studentAccountId): array;

    /** @return list<array<string,mixed>> */
    public function studentDiscounts(int $studentAccountId): array;

    /** @return list<array<string,mixed>> */
    public function priorYearBalances(int $studentId, int $academicYearId): array;

    /** @return array<string,mixed>|null */
    public function receiptByLegacyPaymentId(int $legacyPaymentId): ?array;

    /** @return array<string,mixed>|null */
    public function receipt(int $receiptId): ?array;

    public function soleActiveCashboxId(): ?int;

    /** @return array<string,mixed>|null */
    public function activeStaffContract(int $staffId): ?array;

    /** @return array<string,mixed>|null */
    public function staffContract(int $contractId): ?array;

    /** @return list<array<string,mixed>> */
    public function staffContractComponents(int $contractId): array;

    public function payrollComponentId(string $code): ?int;
}
