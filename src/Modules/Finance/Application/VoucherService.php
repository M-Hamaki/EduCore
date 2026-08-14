<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\BankAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\CashboxRepository;
use EduCore\Modules\Finance\Contracts\Repositories\VoucherRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class VoucherService
{
    public function __construct(
        private VoucherRepository $vouchers,
        private JournalEntryService $journals,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit,
        private CashboxRepository $cashboxes,
        private BankAccountRepository $bankAccounts
    ) {
    }

    public function postVoucher(string $voucherType, ?int $cashboxId, ?int $sourceCashboxId, ?int $destinationCashboxId, ?int $bankAccountId, Money $amount, ?int $financePeriodId, string $entryDate, ?int $costCenterId, ?string $description, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        if (!in_array($voucherType, ['expense', 'other_income', 'cash_transfer'], true) || $amount->isZero()) {
            throw new InvalidArgumentException('Invalid voucher type or amount.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) {
            throw new InvalidArgumentException('Voucher entry date is required.');
        }
        if ($voucherType === 'cash_transfer') {
            if ($cashboxId !== null || $sourceCashboxId === null || $destinationCashboxId === null || $sourceCashboxId === $destinationCashboxId) {
                throw new InvalidArgumentException('Cash transfer requires distinct source and destination cashboxes.');
            }
        } elseif ($cashboxId === null || $sourceCashboxId !== null || $destinationCashboxId !== null) {
            throw new InvalidArgumentException('Expense and income vouchers require one cashbox.');
        }
        FinanceAuthorization::assertMakerChecker('voucher_post', $postedBy, $approvedBy);

        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId)) {
            throw new InvalidArgumentException('Voucher request id must be a 32-character hexadecimal key.');
        }
        $existing = $this->vouchers->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->transactions->transactional(function () use ($voucherType, $cashboxId, $sourceCashboxId, $destinationCashboxId, $bankAccountId, $amount, $financePeriodId, $entryDate, $costCenterId, $description, $postedBy, $approvedBy, $requestId): int {
            $zero = Money::zero();
            if ($voucherType === 'cash_transfer') {
                $sourceCashbox = $this->cashboxes->findById((int) $sourceCashboxId);
                $destinationCashbox = $this->cashboxes->findById((int) $destinationCashboxId);
                if ($sourceCashbox === null || $destinationCashbox === null || !(bool) $sourceCashbox['is_active'] || !(bool) $destinationCashbox['is_active']) {
                    throw new RuntimeException('Cash transfer requires two active cashboxes.');
                }
                $out = $this->journals->resolveAccounts('voucher_transfer_out', ['cashbox_id' => $sourceCashboxId]);
                $in = $this->journals->resolveAccounts('voucher_transfer_in', ['cashbox_id' => $destinationCashboxId]);
                $lines = [
                    ['account_id' => $in['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'cost_center_id' => $costCenterId, 'description' => $description],
                    ['account_id' => $out['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'cost_center_id' => $costCenterId, 'description' => $description],
                ];
            } else {
                $cashbox = $this->cashboxes->findById((int) $cashboxId);
                if ($cashbox === null || !(bool) $cashbox['is_active']) {
                    throw new RuntimeException('Voucher cashbox is inactive or missing.');
                }
                if ($bankAccountId !== null) {
                    $bankAccount = $this->bankAccounts->findById($bankAccountId);
                    if ($bankAccount === null || (int) $bankAccount['cashbox_id'] !== $cashboxId) {
                        throw new RuntimeException('Voucher bank account does not belong to the selected cashbox.');
                    }
                }
                $mapping = $this->journals->resolveAccounts('voucher', ['voucher_type' => $voucherType, 'cashbox_id' => $cashboxId]);
                $lines = [
                    ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'cost_center_id' => $costCenterId, 'description' => $description],
                    ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'cost_center_id' => $costCenterId, 'description' => $description],
                ];
            }
            $voucherId = $this->vouchers->create([
                'voucher_number' => 'V-' . strtoupper(substr($voucherType, 0, 3)) . '-' . strtoupper(substr($requestId, 0, 12)),
                'voucher_type' => $voucherType,
                'cashbox_id' => $cashboxId,
                'source_cashbox_id' => $sourceCashboxId,
                'destination_cashbox_id' => $destinationCashboxId,
                'bank_account_id' => $bankAccountId,
                'amount' => $amount->toDatabaseString(),
                'finance_period_id' => $financePeriodId,
                'entry_date' => $entryDate,
                'cost_center_id' => $costCenterId,
                'status' => 'posted',
                'posted_by' => $postedBy,
                'approved_by' => $approvedBy,
                'request_id' => $requestId,
                'notes' => $description,
            ]);
            foreach ($lines as $line) {
                $this->vouchers->addLine($voucherId, (int) $line['account_id'], $line['cost_center_id'] ?? $costCenterId, $line['debit']->toDatabaseString(), $line['credit']->toDatabaseString(), $line['description'] ?? null);
            }
            if (!$this->vouchers->isBalanced($voucherId)) {
                throw new RuntimeException('Persisted voucher is not balanced.');
            }
            $journalId = $this->journals->postPureGlOperation('voucher', $voucherId, $requestId, $financePeriodId, $entryDate, $lines, $postedBy);
            $this->audit->recordEvent('finance_voucher_post', 'finance_voucher', $voucherId, null, ['journal_entry_id' => $journalId, 'amount' => $amount->toDatabaseString(), 'posted_by' => $postedBy, 'approved_by' => $approvedBy]);
            return $voucherId;
        });
    }

    public function reverseVoucher(int $voucherId, string $entryDate, string $reason, int $requestedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('voucher_post', $requestedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1 || trim($reason) === '') {
            throw new InvalidArgumentException('Voucher reversal requires a request id, entry date, and reason.');
        }
        $existing = $this->vouchers->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->transactions->transactional(function () use ($voucherId, $entryDate, $reason, $requestedBy, $approvedBy, $requestId): int {
            $original = $this->vouchers->lockById($voucherId);
            if ($original === null || (string) $original['status'] !== 'posted' || $original['reversal_of'] !== null || $original['request_id'] === null) {
                throw new RuntimeException('Only an original posted voucher can be reversed.');
            }
            $alreadyReversed = $this->vouchers->findByReversalOf($voucherId);
            if ($alreadyReversed !== null) {
                return (int) $alreadyReversed['id'];
            }
            $reversalId = $this->vouchers->create([
                'voucher_number' => 'REV-' . (string) $original['voucher_number'],
                'voucher_type' => (string) $original['voucher_type'],
                'cashbox_id' => $original['cashbox_id'],
                'source_cashbox_id' => $original['source_cashbox_id'],
                'destination_cashbox_id' => $original['destination_cashbox_id'],
                'bank_account_id' => $original['bank_account_id'],
                'amount' => (string) $original['amount'],
                'finance_period_id' => $original['finance_period_id'],
                'entry_date' => $entryDate,
                'cost_center_id' => $original['cost_center_id'],
                'status' => 'posted',
                'posted_by' => $requestedBy,
                'approved_by' => $approvedBy,
                'reversal_of' => $voucherId,
                'batch_id' => $original['batch_id'],
                'request_id' => $requestId,
                'notes' => trim($reason),
                'created_by' => $requestedBy,
            ]);
            foreach ($this->vouchers->findByVoucher($voucherId) as $line) {
                $this->vouchers->addLine($reversalId, (int) $line['account_id'], $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'], (string) $line['credit'], (string) $line['debit'], 'Reversal: ' . trim((string) ($line['description'] ?? '')));
            }
            if (!$this->vouchers->isBalanced($reversalId)) {
                throw new RuntimeException('Persisted voucher reversal is not balanced.');
            }
            $journalId = $this->journals->postPureGlReversal((string) $original['request_id'], $reversalId, $requestId, $entryDate, $requestedBy, $original['batch_id'] ?? null);
            $this->audit->recordEvent('finance_voucher_reverse', 'finance_voucher', $reversalId, null, ['reversal_of' => $voucherId, 'journal_entry_id' => $journalId, 'reason' => trim($reason)], ['request_id' => $requestId]);
            return $reversalId;
        });
    }
}
