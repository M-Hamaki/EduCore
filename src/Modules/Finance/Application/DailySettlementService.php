<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\CashboxRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use RuntimeException;

final class DailySettlementService
{
    public function __construct(
        private CashboxRepository $cashboxes,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function openSettlement(int $cashboxId, ?int $periodId, string $date, string $openingFloat, int $openedBy): int
    {
        Money::fromDecimalString($openingFloat);
        if ($cashboxId <= 0 || $openedBy <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException('Invalid cashbox settlement context.');
        }
        return $this->transactions->transactional(function () use ($cashboxId, $periodId, $date, $openingFloat, $openedBy): int {
            $expected = $this->cashboxes->expectedReceiptTotal($cashboxId, $date);
            $id = $this->cashboxes->createSettlement($cashboxId, $periodId, $date, $openingFloat, $expected, '0.00');
            $this->audit->recordEvent('finance_settlement_open', 'finance_cashbox_settlement', $id, $date, ['cashbox_id' => $cashboxId, 'expected_total' => $expected, 'opened_by' => $openedBy]);
            return $id;
        });
    }

    public function settleSettlement(int $settlementId, string $countedTotal, int $settledBy): void
    {
        $counted = Money::fromDecimalString($countedTotal);
        $this->transactions->transactional(function () use ($settlementId, $counted, $settledBy): void {
            $settlement = $this->cashboxes->findSettlement($settlementId);
            if ($settlement === null || (string) $settlement['status'] !== 'open') {
                throw new RuntimeException('Open cashbox settlement not found.');
            }
            $expected = Money::fromDecimalString((string) $settlement['expected_total']);
            $difference = SignedMoneyDelta::fromMinorUnits($counted->toMinorUnits() - $expected->toMinorUnits());
            $this->cashboxes->settleSettlement($settlementId, $counted->toDatabaseString(), $difference->toDatabaseString(), $settledBy);
            $this->audit->recordEvent('finance_settlement_close', 'finance_cashbox_settlement', $settlementId, (string) $settlement['settlement_date'], ['counted_total' => $counted->toDatabaseString(), 'difference' => $difference->toDatabaseString(), 'settled_by' => $settledBy]);
        });
    }

    public function expectedTotal(int $cashboxId, string $date): string
    {
        return $this->cashboxes->expectedReceiptTotal($cashboxId, $date);
    }
}
