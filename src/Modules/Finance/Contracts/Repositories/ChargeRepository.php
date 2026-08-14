<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for student charges and charge installments (domain detail).
 * The sub-ledger is the truth source; this repository holds operational detail.
 */
interface ChargeRepository
{
    public function createCharge(array $fields): int;

    public function findById(int $id): ?array;

    /** Lock a charge before concurrent discount/allocation decisions. */
    public function lockById(int $id): ?array;

    public function findInstallmentForCharge(int $installmentId, int $chargeId): ?array;

    public function findByRequestId(int $studentAccountId, string $requestId): ?array;

    public function addInstallment(int $chargeId, string $name, string $netAmount, ?string $dueDate, int $displayOrder): int;

    public function installmentsForCharge(int $chargeId): array;

    /** @return list<array<string,mixed>> */
    public function installmentsForAccount(int $studentAccountId): array;

    public function installmentRemainingDue(int $installmentId): string;

    public function lockInstallmentRemainingDue(int $installmentId): string;

    public function post(int $chargeId, int $subledgerTransactionId, int $postedBy): void;
}
