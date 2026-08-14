<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\BusFeeScheduleRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;

final class BusFeeScheduleService
{
    public function __construct(
        private BusFeeScheduleRepository $schedules,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function createAndActivate(
        int $academicYearId,
        ?string $subscriptionKey,
        ?string $legacyZoneKey,
        string $zoneName,
        Money $amount,
        array $installments,
        ?string $notes,
        string $effectiveFrom,
        int $createdBy
    ): int {
        if ($academicYearId <= 0 || $createdBy <= 0 || trim($zoneName) === ''
            || $amount->isZero() || preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1) {
            throw new InvalidArgumentException('Bus fee schedule data is incomplete.');
        }
        $sum = Money::zero();
        foreach ($installments as $installment) {
            if (!isset($installment['amount']) || !$installment['amount'] instanceof Money) {
                throw new InvalidArgumentException('Every bus installment requires a Money amount.');
            }
            $sum = $sum->add($installment['amount']);
        }
        if ($installments !== [] && !$sum->equals($amount)) {
            throw new InvalidArgumentException('Bus installment total must equal the schedule amount.');
        }
        return $this->transactions->transactional(function () use ($academicYearId, $subscriptionKey, $legacyZoneKey, $zoneName, $amount, $installments, $notes, $effectiveFrom, $createdBy): int {
            $payload = array_map(static fn (array $row): array => [
                'name' => trim((string) ($row['name'] ?? '')),
                'amount' => $row['amount']->toDatabaseString(),
                'due_date' => ($row['due_date'] ?? '') ?: null,
            ], $installments);
            $id = $this->schedules->createVersion([
                'academic_year_id' => $academicYearId,
                'transport_subscription_key' => $subscriptionKey,
                'legacy_zone_key' => $legacyZoneKey,
                'zone_name' => trim($zoneName),
                'amount' => $amount->toDatabaseString(),
                'installments_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $notes === null ? null : trim($notes),
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'created_by' => $createdBy,
            ]);
            $this->schedules->activate($id);
            $this->audit->recordEvent('finance_bus_fee_schedule_activate', 'finance_bus_fee_schedule', $id, $zoneName, [
                'academic_year_id' => $academicYearId,
                'amount' => $amount->toDatabaseString(),
                'created_by' => $createdBy,
            ]);
            return $id;
        });
    }

    public function archive(int $academicYearId, string $legacyZoneKey, int $archivedBy): void
    {
        $this->transactions->transactional(function () use ($academicYearId, $legacyZoneKey, $archivedBy): void {
            $this->schedules->archiveByLegacyKey($academicYearId, $legacyZoneKey);
            $this->audit->recordEvent('finance_bus_fee_schedule_archive', 'finance_bus_fee_schedule', null, $legacyZoneKey, [
                'academic_year_id' => $academicYearId,
                'archived_by' => $archivedBy,
            ]);
        });
    }

    /** @return array<string,mixed>|null */
    public function activeByLegacyKey(int $academicYearId, string $legacyZoneKey): ?array
    {
        return $this->schedules->findActiveByLegacyKey($academicYearId, $legacyZoneKey);
    }
}
