<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;

final class ManualJournalService
{
    public function __construct(private JournalEntryService $journals, private FinancePeriodService $periods, private FinanceTransactionManager $transactions, private AuditEventWriter $audit) {}

    public function post(int $academicYearId, int $financePeriodId, string $entryDate, array $rawLines, string $description, int $requestedBy, int $approvedBy, string $idempotencyKey): int
    {
        FinanceAuthorization::assertMakerChecker('manual_journal_post', $requestedBy, $approvedBy);
        $lines = $this->normalizeLines($rawLines);
        $this->assertBalanced($lines);
        $this->periods->assertWritable($academicYearId, $financePeriodId);
        if (!$this->isDate($entryDate) || !preg_match('/^[a-f0-9]{32}$/i', $idempotencyKey)) { throw new InvalidArgumentException('Manual journal date or idempotency key is invalid.'); }
        return $this->transactions->transactional(function () use ($financePeriodId, $entryDate, $lines, $description, $requestedBy, $approvedBy, $idempotencyKey): int {
            $id = $this->journals->postPureGlOperation('manual', null, $idempotencyKey, $financePeriodId, $entryDate, $lines, $requestedBy);
            $this->audit->recordEvent('finance_manual_journal_post', 'accounting_journal_entry', $id, null, ['requested_by' => $requestedBy, 'approved_by' => $approvedBy, 'description' => trim($description)]);
            return $id;
        });
    }

    public function reverse(string $originalIdempotencyKey, string $entryDate, string $reason, int $requestedBy, int $approvedBy, string $reversalIdempotencyKey): int
    {
        FinanceAuthorization::assertMakerChecker('manual_journal_reverse', $requestedBy, $approvedBy);
        if (trim($reason) === '' || !$this->isDate($entryDate) || !preg_match('/^[a-f0-9]{32}$/i', $originalIdempotencyKey) || !preg_match('/^[a-f0-9]{32}$/i', $reversalIdempotencyKey)) { throw new InvalidArgumentException('Manual journal reversal data is invalid.'); }
        return $this->transactions->transactional(function () use ($originalIdempotencyKey, $entryDate, $reason, $requestedBy, $approvedBy, $reversalIdempotencyKey): int {
            $id = $this->journals->postPureGlReversal($originalIdempotencyKey, null, $reversalIdempotencyKey, $entryDate, $requestedBy);
            $this->audit->recordEvent('finance_manual_journal_reverse', 'accounting_journal_entry', $id, null, ['requested_by' => $requestedBy, 'approved_by' => $approvedBy, 'reason' => trim($reason)]);
            return $id;
        });
    }

    private function normalizeLines(array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $raw) {
            if (!is_array($raw)) { continue; }
            $accountId = (int) ($raw['account_id'] ?? 0);
            $debitText = trim((string) ($raw['debit'] ?? '0')) ?: '0';
            $creditText = trim((string) ($raw['credit'] ?? '0')) ?: '0';
            if ($accountId <= 0 || preg_match('/^\d+(?:\.\d{1,2})?$/', $debitText) !== 1 || preg_match('/^\d+(?:\.\d{1,2})?$/', $creditText) !== 1) { throw new InvalidArgumentException('Manual journal line is invalid.'); }
            $debit = Money::fromDecimalString($debitText);
            $credit = Money::fromDecimalString($creditText);
            if ((!$debit->isZero() && !$credit->isZero()) || ($debit->isZero() && $credit->isZero())) { throw new InvalidArgumentException('Each journal line must contain exactly one debit or credit amount.'); }
            $lines[] = ['account_id' => $accountId, 'cost_center_id' => (int) ($raw['cost_center_id'] ?? 0) ?: null, 'debit' => $debit, 'credit' => $credit, 'description' => trim((string) ($raw['description'] ?? '')) ?: null];
        }
        if (count($lines) < 2) { throw new InvalidArgumentException('Manual journal requires at least two lines.'); }
        return $lines;
    }

    private function assertBalanced(array $lines): void
    {
        $debit = 0; $credit = 0;
        foreach ($lines as $line) { $debit += $line['debit']->toMinorUnits(); $credit += $line['credit']->toMinorUnits(); }
        if ($debit <= 0 || $debit !== $credit) { throw new InvalidArgumentException('Manual journal debits and credits must balance exactly.'); }
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
