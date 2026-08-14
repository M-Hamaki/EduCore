<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\StaffAdvanceMovementRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StaffAdvanceRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class StaffAdvanceService
{
    public function __construct(
        private StaffAdvanceRepository $advances,
        private StaffAdvanceMovementRepository $movements,
        private SubledgerPostingService $posting,
        private JournalEntryService $journals,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function issueAdvance(int $staffId, Money $amount, string $issueDate, string $reason, int $postedBy, ?string $requestId = null): int
    {
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $existing = $this->advances->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        if ($amount->isZero() || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) !== 1) {
            throw new RuntimeException('Advance amount and issue date are required.');
        }
        return $this->transactions->transactional(function () use ($staffId, $amount, $issueDate, $reason, $postedBy, $requestId): int {
            $advanceId = $this->advances->create($staffId, $amount->toDatabaseString(), $issueDate, trim($reason), $postedBy, $requestId);
            $mapping = $this->journals->resolveAccounts('advance_issue');
            $zero = Money::zero();
            $subledgerTransactionId = $this->posting->postPartyOperation('staff', $staffId, 'STAFF_GLOBAL', 'advance_issue', $advanceId, $requestId, [['bucket' => 'STAFF_ADVANCE_RECEIVABLE', 'delta' => SignedMoneyDelta::fromMinorUnits($amount->toMinorUnits()), 'description' => 'Advance issue']], 'advance_issue', $issueDate, [
                ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId],
                ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId],
            ], $postedBy, null, $requestId);
            $this->advances->linkPosting($advanceId, $subledgerTransactionId);
            $this->audit->recordEvent('finance_staff_advance_issue', 'staff_advance', $advanceId, null, ['staff_id' => $staffId, 'amount' => $amount->toDatabaseString()], ['request_id' => $requestId]);
            return $advanceId;
        });
    }

    public function recordCashRepayment(int $advanceId, int $staffId, int $cashboxId, Money $amount, int $postedBy, ?string $requestId = null): int
    {
        return $this->postMovement($advanceId, $staffId, 'cash_repayment', $cashboxId, $amount, $postedBy, null, null, $requestId);
    }

    public function recordPayrollDeduction(int $advanceId, int $staffId, int $payrollRunItemId, Money $amount, int $postedBy, ?string $requestId = null): int
    {
        return $this->postMovement($advanceId, $staffId, 'payroll_deduction', null, $amount, $postedBy, null, null, $requestId, $payrollRunItemId);
    }

    public function writeOffAdvance(int $advanceId, int $staffId, Money $amount, int $postedBy, int $approvedBy, string $reason = '', ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('advance_write_off', $postedBy, $approvedBy);
        return $this->postMovement($advanceId, $staffId, 'write_off', null, $amount, $postedBy, $approvedBy, $reason, $requestId, null);
    }

    public function reverseMovement(int $movementId, int $staffId, int $requestedBy, int $approvedBy, string $reason, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('advance_write_off', $requestedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId) || trim($reason) === '') {
            throw new InvalidArgumentException('Advance movement reversal requires a request id and reason.');
        }
        $existing = $this->movements->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->transactions->transactional(function () use ($movementId, $staffId, $requestedBy, $approvedBy, $reason, $requestId): int {
            $original = $this->movements->lockById($movementId);
            if ($original === null || (string) $original['status'] !== 'posted' || $original['reversal_of'] !== null || $original['subledger_transaction_id'] === null) {
                throw new RuntimeException('Only an original posted advance movement can be reversed.');
            }
            $alreadyReversed = $this->movements->findByReversalOf($movementId);
            if ($alreadyReversed !== null) {
                return (int) $alreadyReversed['id'];
            }
            $advance = $this->advances->findById((int) $original['staff_advance_id']);
            if ($advance === null || (int) $advance['staff_id'] !== $staffId) {
                throw new RuntimeException('Advance movement does not belong to the staff member.');
            }
            $reversalId = $this->movements->create([
                'advance_id' => (int) $original['staff_advance_id'],
                'movement_type' => (string) $original['movement_type'],
                'amount' => (string) $original['amount'],
                'cashbox_id' => $original['cashbox_id'] === null ? null : (int) $original['cashbox_id'],
                'payroll_run_item_id' => $original['payroll_run_item_id'] === null ? null : (int) $original['payroll_run_item_id'],
                'reason' => trim($reason),
                'status' => 'posted',
                'approved_by' => $approvedBy,
                'reversal_of' => $movementId,
                'batch_id' => $original['batch_id'] ?? null,
                'request_id' => $requestId,
                'created_by' => $requestedBy,
            ]);
            $originalSubledgerId = (int) $original['subledger_transaction_id'];
            $reversalSubledgerId = $this->posting->postReversal(
                $originalSubledgerId,
                $requestId,
                date('Y-m-d'),
                $this->journals->reversalLinesForPartyOperation($originalSubledgerId),
                $requestedBy,
                $original['batch_id'] ?? null,
                $requestId
            );
            $this->movements->linkPosting($reversalId, $reversalSubledgerId);
            $this->advances->updateStatus((int) $advance['id'], 'active');
            $this->audit->recordEvent('finance_staff_advance_movement_reverse', 'staff_advance_movement', $reversalId, null, ['reversal_of' => $movementId, 'reason' => trim($reason)], ['request_id' => $requestId]);
            return $reversalId;
        });
    }

    private function postMovement(int $advanceId, int $staffId, string $movementType, ?int $cashboxId, Money $amount, int $postedBy, ?int $approvedBy, ?string $reason, ?string $requestId, ?int $payrollRunItemId = null): int
    {
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $existing = $this->movements->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->transactions->transactional(function () use ($advanceId, $staffId, $movementType, $cashboxId, $amount, $postedBy, $approvedBy, $reason, $requestId, $payrollRunItemId): int {
            $advance = $this->advances->findById($advanceId);
            if ($advance === null || (int) $advance['staff_id'] !== $staffId || $amount->isZero() || $amount->greaterThan(Money::fromDecimalString($this->advances->remaining($advanceId)))) {
                throw new RuntimeException('Advance movement exceeds the active advance balance.');
            }
            $mapping = $this->journals->resolveAccounts('advance_' . $movementType, ['cashbox_id' => $cashboxId]);
            $zero = Money::zero();
            $subledgerTransactionId = $this->posting->postPartyOperation('staff', $staffId, 'STAFF_GLOBAL', 'advance_' . $movementType, $advanceId, $requestId, [['bucket' => 'STAFF_ADVANCE_RECEIVABLE', 'delta' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits()), 'description' => $reason ?? $movementType]], 'advance_' . $movementType, date('Y-m-d'), [
                ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId],
                ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId],
            ], $postedBy, null, $requestId);
            $movementId = $this->movements->create(['advance_id' => $advanceId, 'movement_type' => $movementType, 'amount' => $amount->toDatabaseString(), 'cashbox_id' => $cashboxId, 'payroll_run_item_id' => $payrollRunItemId, 'reason' => $reason, 'approved_by' => $approvedBy, 'subledger_transaction_id' => $subledgerTransactionId, 'request_id' => $requestId, 'created_by' => $postedBy]);
            if (Money::fromDecimalString($this->advances->remaining($advanceId))->isZero()) {
                $this->advances->updateStatus($advanceId, $movementType === 'write_off' ? 'written_off' : 'repaid');
            }
            $this->audit->recordEvent('finance_staff_advance_movement_post', 'staff_advance_movement', $movementId, null, ['advance_id' => $advanceId, 'movement_type' => $movementType, 'amount' => $amount->toDatabaseString()], ['request_id' => $requestId]);
            return $movementId;
        });
    }
}
