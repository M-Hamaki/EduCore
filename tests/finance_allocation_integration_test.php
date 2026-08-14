<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\PaymentAllocationService;
use EduCore\Modules\Finance\Application\ReceiptService;
use EduCore\Modules\Finance\Application\SubledgerPostingService;
use EduCore\Modules\Finance\Application\UnappliedCreditService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAdjustmentRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoCashboxRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoChargeRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoPaymentAllocationRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoReceiptRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoRefundRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStudentFinanceAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoUnappliedCreditRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $studentId = 77777;
    $academicYearId = 77777;
    $mappingVersion = 77777;

    $studentAccountIds = $db->query('SELECT id FROM finance_student_accounts WHERE student_id = ' . $studentId)->fetchAll(PDO::FETCH_COLUMN);
    if ($studentAccountIds !== []) {
        $accountList = implode(',', array_map('intval', $studentAccountIds));
        $receiptIds = $db->query('SELECT id FROM finance_receipts WHERE student_account_id IN (' . $accountList . ')')->fetchAll(PDO::FETCH_COLUMN);
        if ($receiptIds !== []) {
            $receiptList = implode(',', array_map('intval', $receiptIds));
            $db->exec('DELETE FROM finance_refunds WHERE reversal_of IS NOT NULL AND receipt_id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_refunds WHERE receipt_id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_unapplied_credit_applications WHERE reversal_of IS NOT NULL AND unapplied_credit_id IN (SELECT id FROM finance_unapplied_credits WHERE receipt_id IN (' . $receiptList . '))');
            $db->exec('DELETE FROM finance_unapplied_credit_applications WHERE unapplied_credit_id IN (SELECT id FROM finance_unapplied_credits WHERE receipt_id IN (' . $receiptList . '))');
            $db->exec('DELETE FROM finance_unapplied_credits WHERE reversal_of IS NOT NULL AND receipt_id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_unapplied_credits WHERE receipt_id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_payment_allocations WHERE reversal_of IS NOT NULL AND receipt_id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_payment_allocations WHERE receipt_id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_receipts WHERE reversal_of IS NOT NULL AND id IN (' . $receiptList . ')');
            $db->exec('DELETE FROM finance_receipts WHERE id IN (' . $receiptList . ')');
        }
        $db->exec('DELETE FROM finance_adjustments WHERE reversal_of IS NOT NULL AND student_account_id IN (' . $accountList . ')');
        $db->exec('DELETE FROM finance_adjustments WHERE student_account_id IN (' . $accountList . ')');
        $db->exec('DELETE FROM finance_charge_installments WHERE student_charge_id IN (SELECT id FROM finance_student_charges WHERE student_account_id IN (' . $accountList . '))');
        $db->exec('DELETE FROM finance_student_charges WHERE student_account_id IN (' . $accountList . ')');
        $db->exec('DELETE FROM finance_student_contracts WHERE student_account_id IN (' . $accountList . ')');
        $db->exec('DELETE FROM finance_student_accounts WHERE id IN (' . $accountList . ')');
    }
    $subledgerIds = $db->query("SELECT id FROM finance_subledger_accounts WHERE party_type = 'student' AND party_id = {$studentId}")->fetchAll(PDO::FETCH_COLUMN);
    if ($subledgerIds !== []) {
        $accountList = implode(',', array_map('intval', $subledgerIds));
        $transactionIds = $db->query('SELECT id FROM finance_subledger_transactions WHERE subledger_account_id IN (' . $accountList . ')')->fetchAll(PDO::FETCH_COLUMN);
        if ($transactionIds !== []) {
            $transactionList = implode(',', array_map('intval', $transactionIds));
            $entryIds = $db->query('SELECT id FROM accounting_journal_entries WHERE subledger_transaction_id IN (' . $transactionList . ')')->fetchAll(PDO::FETCH_COLUMN);
            if ($entryIds !== []) {
                $entryList = implode(',', array_map('intval', $entryIds));
                $db->exec('DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (' . $entryList . ')');
                $db->exec('DELETE FROM accounting_journal_entries WHERE reversal_of IS NOT NULL AND id IN (' . $entryList . ')');
                $db->exec('DELETE FROM accounting_journal_entries WHERE id IN (' . $entryList . ')');
            }
            $db->exec('DELETE FROM finance_subledger_lines WHERE transaction_id IN (' . $transactionList . ')');
            $db->exec('DELETE FROM finance_subledger_transactions WHERE reversal_of IS NOT NULL AND id IN (' . $transactionList . ')');
            $db->exec('DELETE FROM finance_subledger_transactions WHERE id IN (' . $transactionList . ')');
        }
        $db->exec('DELETE FROM finance_subledger_accounts WHERE id IN (' . $accountList . ')');
    }
    $db->prepare('DELETE FROM accounting_account_mapping_headers WHERE version_number = ?')->execute([$mappingVersion]);
    $db->prepare('DELETE FROM finance_cashboxes WHERE code = ?')->execute(['TEST-ALLOC-CASH']);

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)'
    );
    $ids = [];
    foreach ([
        ['TEST-ALLOC-REC', 'ذمم اختبار التحصيل', 'asset'],
        ['TEST-ALLOC-REV', 'إيراد اختبار التحصيل', 'revenue'],
        ['TEST-ALLOC-CASH', 'نقدية اختبار التحصيل', 'asset'],
        ['TEST-ALLOC-CREDIT', 'دفعات مقدمة اختبار', 'liability'],
        ['TEST-ALLOC-WRITEOFF', 'إعدام ديون اختبار', 'expense'],
    ] as [$code, $name, $type]) {
        $accountInsert->execute([$code, $name, $type]);
        $ids[$code] = (int) $db->lastInsertId();
    }

    $db->prepare('INSERT INTO accounting_account_mapping_headers (version_number, effective_from, status, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$mappingVersion, '2026-01-01', 'active', 1]);
    $headerId = (int) $db->lastInsertId();
    $mappingInsert = $db->prepare(
        'INSERT INTO accounting_account_mapping_lines
            (mapping_header_id, operation_type, selector_payment_method, selector_cashbox_id, debit_account_id, credit_account_id, specificity_score, priority)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $db->prepare('INSERT INTO finance_cashboxes (code, name, type, is_active, accountability_role, receipt_prefix) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['TEST-ALLOC-CASH', 'خزينة اختبار التحصيل', 'cash', 1, 'admin', 'TAR']);
    $cashboxId = (int) $db->lastInsertId();
    $mappingInsert->execute([$headerId, 'receipt', 'cash', $cashboxId, $ids['TEST-ALLOC-CASH'], $ids['TEST-ALLOC-REC'], 2, 100]);
    $mappingInsert->execute([$headerId, 'unapplied_credit', 'cash', $cashboxId, $ids['TEST-ALLOC-CASH'], $ids['TEST-ALLOC-CREDIT'], 2, 100]);
    $mappingInsert->execute([$headerId, 'refund_allocation', 'cash', null, $ids['TEST-ALLOC-REC'], $ids['TEST-ALLOC-CASH'], 1, 100]);
    $mappingInsert->execute([$headerId, 'refund_unapplied_credit', 'cash', null, $ids['TEST-ALLOC-CREDIT'], $ids['TEST-ALLOC-CASH'], 1, 100]);
    $mappingInsert->execute([$headerId, 'unapplied_credit_application', null, null, $ids['TEST-ALLOC-CREDIT'], $ids['TEST-ALLOC-REC'], 0, 100]);
    $mappingInsert->execute([$headerId, 'student_debt_write_off', null, null, $ids['TEST-ALLOC-WRITEOFF'], $ids['TEST-ALLOC-REC'], 0, 100]);

    $audit = new class implements AuditEventWriter {
        public int $events = 0;
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            ++$this->events;
        }
    };
    $transactions = new PdoFinanceTransactionManager($db);
    $subledgerAccounts = new PdoSubledgerAccountRepository($db);
    $subledgerLines = new PdoSubledgerLineRepository($db);
    $journals = new JournalEntryService(new PdoJournalEntryRepository($db), new PdoAccountMappingLineRepository($db), new AccountMappingPolicy(), new ControlAccountService(new PdoControlAccountRepository($db), $subledgerLines));
    $posting = new SubledgerPostingService(
        $transactions,
        $subledgerAccounts,
        new PdoSubledgerTransactionRepository($db),
        $subledgerLines,
        $journals,
        $audit
    );
    $subledger = $subledgerAccounts->findOrCreate('student', $studentId, (string) $academicYearId);
    $studentAccounts = new PdoStudentFinanceAccountRepository($db);
    $studentAccountId = $studentAccounts->findOrCreate($studentId, $academicYearId, (int) $subledger['id']);
    $charges = new PdoChargeRepository($db);
    $chargeId = $charges->createCharge([
        'student_account_id' => $studentAccountId,
        'charge_type_id' => 1,
        'direction' => 'debit',
        'gross_amount' => '1000.00',
        'discount_amount' => '0.00',
        'adjustment_amount' => '0.00',
        'net_due' => '1000.00',
        'source' => 'manual',
        'academic_year_id' => $academicYearId,
        'request_id' => md5('allocation-charge'),
    ]);
    $firstInstallmentId = $charges->addInstallment($chargeId, 'الأول', '600.00', '2026-09-01', 1);
    $secondInstallmentId = $charges->addInstallment($chargeId, 'الثاني', '400.00', '2027-01-01', 2);
    $seedKey = md5('allocation-seed-posting');
    $seedTxId = $posting->postPartyOperation(
        'student', $studentId, (string) $academicYearId, 'charge', $chargeId, $seedKey,
        [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('1000.00')]],
        'student_charge', '2026-07-25',
        [
            ['account_id' => $ids['TEST-ALLOC-REC'], 'debit' => Money::fromDecimalString('1000.00'), 'credit' => Money::zero()],
            ['account_id' => $ids['TEST-ALLOC-REV'], 'debit' => Money::zero(), 'credit' => Money::fromDecimalString('1000.00')],
        ],
        1
    );
    $charges->post($chargeId, $seedTxId, 1);

    $credits = new PdoUnappliedCreditRepository($db);
    $allocationPlanner = new PaymentAllocationService($charges, $credits, $credits, $posting, $journals, $transactions);
    $plan = $allocationPlanner->autoAllocateToOldest($studentId, $academicYearId, $chargeId, Money::fromDecimalString('1100.00'), md5('allocation-plan'), 1);
    $assert(count($plan['allocations']) === 2, 'auto allocation targets two installments');
    $assert($plan['allocations'][0]['installment_id'] === $firstInstallmentId && $plan['allocations'][0]['amount']->equals(Money::fromDecimalString('600.00')), 'oldest installment is allocated first');
    $assert($plan['allocations'][1]['installment_id'] === $secondInstallmentId && $plan['allocations'][1]['amount']->equals(Money::fromDecimalString('400.00')), 'second installment receives remaining due');
    $assert($plan['overpayment']->equals(Money::fromDecimalString('100.00')), 'overpayment is separated as unapplied credit');

    $receiptRepo = new PdoReceiptRepository($db);
    $allocationRepo = new PdoPaymentAllocationRepository($db);
    $receiptService = new ReceiptService($receiptRepo, $allocationRepo, $credits, $charges, new PdoCashboxRepository($db), $posting, $journals, $transactions);
    $requestId = md5('allocation-receipt');
    $receiptId = $receiptService->postReceipt($studentAccountId, $studentId, $cashboxId, $academicYearId, Money::fromDecimalString('1100.00'), 'cash', $requestId, $plan['allocations'], $plan['overpayment'], 1, '2026-07-25');
    $assert($receiptService->postReceipt($studentAccountId, $studentId, $cashboxId, $academicYearId, Money::fromDecimalString('1100.00'), 'cash', $requestId, $plan['allocations'], $plan['overpayment'], 1, '2026-07-25') === $receiptId, 'receipt retry is idempotent');
    $assert($charges->installmentRemainingDue($firstInstallmentId) === '0.00' && $charges->installmentRemainingDue($secondInstallmentId) === '0.00', 'allocations settle both installments');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_payment_allocations WHERE receipt_id = ' . $receiptId)->fetchColumn() === 2, 'receipt persists two signed allocations');
    $creditId = (int) $db->query('SELECT id FROM finance_unapplied_credits WHERE receipt_id = ' . $receiptId)->fetchColumn();
    $assert($creditId > 0 && $credits->remaining($creditId) === '100.00', 'overpayment creates a 100.00 unapplied credit');

    $creditOnlyKey = md5('credit-only-receipt');
    $creditOnlyReceiptId = $receiptService->postReceipt($studentAccountId, $studentId, $cashboxId, $academicYearId, Money::fromDecimalString('50.00'), 'cash', $creditOnlyKey, [], Money::fromDecimalString('50.00'), 1, '2026-07-25');
    $creditOnly = $receiptRepo->findById($creditOnlyReceiptId);
    $creditOnlyId = (int) $db->query('SELECT id FROM finance_unapplied_credits WHERE receipt_id = ' . $creditOnlyReceiptId)->fetchColumn();
    $receiptReversalKey = md5('credit-only-receipt-reversal');
    $receiptReversalId = $receiptService->reverseReceipt($creditOnlyReceiptId, $studentId, 1, 2, $receiptReversalKey, '2026-07-26');
    $assert($receiptService->reverseReceipt($creditOnlyReceiptId, $studentId, 1, 2, $receiptReversalKey, '2026-07-26') === $receiptReversalId, 'receipt reversal retry is idempotent');
    $receiptReversal = $receiptRepo->findById($receiptReversalId);
    $assert($creditOnly !== null && $receiptReversal !== null && $creditOnly['status'] === 'posted' && $receiptReversal['status'] === 'posted', 'receipt reversal preserves the posted original and adds a posted opposite record');
    $assert((int) $receiptReversal['reversal_of'] === $creditOnlyReceiptId, 'receipt reversal links to its immutable original');
    $assert((int) $receiptReversal['sequence_number'] === (int) $creditOnly['sequence_number'] + 1, 'receipt reversal receives a distinct sequential receipt number');
    $assert($credits->remaining($creditOnlyId) === '0.00', 'receipt reversal neutralizes its unapplied credit detail');
    $receiptPairNet = (string) $db->query('SELECT SUM(CASE WHEN reversal_of IS NULL THEN gross_amount ELSE -gross_amount END) FROM finance_receipts WHERE id IN (' . $creditOnlyReceiptId . ',' . $receiptReversalId . ')')->fetchColumn();
    $assert($receiptPairNet === '0.00', 'original receipt and reversal have a zero signed net');
    $receiptPairJournalNet = $db->query('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM accounting_journal_lines l JOIN accounting_journal_entries e ON e.id = l.journal_entry_id WHERE e.subledger_transaction_id IN (' . (int) $creditOnly['subledger_transaction_id'] . ',' . (int) $receiptReversal['subledger_transaction_id'] . ')')->fetchColumn();
    $assert((string) $receiptPairJournalNet === '0.00', 'receipt reversal is the exact GL opposite of the original');

    $refunds = new PdoRefundRepository($db);
    $creditService = new UnappliedCreditService($credits, $allocationRepo, $receiptRepo, $refunds, new PdoAdjustmentRepository($db), $studentAccounts, $posting, $journals, $transactions);
    $allocationId = (int) $db->query('SELECT id FROM finance_payment_allocations WHERE receipt_id = ' . $receiptId . ' ORDER BY id LIMIT 1')->fetchColumn();
    $allocationRefundId = $creditService->refundAllocation($allocationId, $receiptId, $studentId, $academicYearId, Money::fromDecimalString('200.00'), 1, 2, md5('allocation-refund'));
    $assert($charges->installmentRemainingDue($firstInstallmentId) === '200.00', 'allocation refund restores installment due');
    $creditRefundId = $creditService->refundUnappliedCredit($creditId, $studentId, $academicYearId, Money::fromDecimalString('40.00'), 1, 2, md5('credit-refund'));
    $assert($credits->remaining($creditId) === '60.00', 'unapplied-credit refund shrinks credit only');
    $writeOffId = $creditService->writeOffDebt($studentAccountId, $studentId, $academicYearId, Money::fromDecimalString('200.00'), 'تعذر التحصيل', 1, 2, md5('debt-writeoff'));
    $assert($posting->bucketBalance((int) $subledger['id'], 'STUDENT_OUTSTANDING_DUE') === '0.00', 'maker-checker write-off clears only the restored debt');
    $overWriteOffRejected = false;
    try {
        $creditService->writeOffDebt($studentAccountId, $studentId, $academicYearId, Money::fromDecimalString('1.00'), 'زيادة', 1, 2, md5('over-writeoff'));
    } catch (RuntimeException) {
        $overWriteOffRejected = true;
    }
    $assert($overWriteOffRejected, 'write-off above locked outstanding debt is rejected');
    $writeOffReversalId = $creditService->reverseWriteOff($writeOffId, $studentId, $academicYearId, 1, 2, md5('debt-writeoff-reversal'), '2026-07-26');
    $assert($creditService->reverseWriteOff($writeOffId, $studentId, $academicYearId, 1, 2, md5('debt-writeoff-reversal'), '2026-07-26') === $writeOffReversalId, 'write-off reversal retry is idempotent');
    $assert($posting->bucketBalance((int) $subledger['id'], 'STUDENT_OUTSTANDING_DUE') === '200.00', 'write-off reversal restores the exact debt');
    $allocationRefundReversalId = $creditService->reverseRefund($allocationRefundId, $studentId, $academicYearId, 1, 2, md5('allocation-refund-reversal'), '2026-07-26');
    $creditRefundReversalId = $creditService->reverseRefund($creditRefundId, $studentId, $academicYearId, 1, 2, md5('credit-refund-reversal'), '2026-07-26');
    $assert($creditService->reverseRefund($allocationRefundId, $studentId, $academicYearId, 1, 2, md5('allocation-refund-reversal'), '2026-07-26') === $allocationRefundReversalId, 'refund reversal retry is idempotent');
    $assert($posting->bucketBalance((int) $subledger['id'], 'STUDENT_OUTSTANDING_DUE') === '0.00', 'allocation refund reversal reapplies the exact collection effect');
    $assert($credits->remaining($creditId) === '100.00', 'unapplied-credit refund reversal restores the exact credit');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_refunds WHERE reversal_of IN (' . $allocationRefundId . ',' . $creditRefundId . ') AND signed_amount > 0 AND status = \'posted\'')->fetchColumn() === 2, 'refund reversals are separate positive posted records');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_adjustments WHERE id IN (' . $writeOffId . ',' . $writeOffReversalId . ') AND status = \'posted\'')->fetchColumn() === 2, 'write-off original and reversal both remain posted');
    $dependentReceiptRejected = false;
    try {
        $receiptService->reverseReceipt($receiptId, $studentId, 1, 2, md5('dependent-receipt-reversal'), '2026-07-26');
    } catch (RuntimeException) {
        $dependentReceiptRejected = true;
    }
    $assert($dependentReceiptRejected, 'receipt reversal is rejected while dependent refund history exists');
    $assert($audit->events === 10, 'every charge, collection, refund, write-off, and reversal emits one atomic audit event');

    $manualReasonRejected = false;
    try {
        $receiptService->postReceipt($studentAccountId, $studentId, $cashboxId, $academicYearId, Money::fromDecimalString('1.00'), 'cash', md5('manual-no-reason'), [], Money::fromDecimalString('1.00'), 1, '2026-07-27', 'manual');
    } catch (InvalidArgumentException) {
        $manualReasonRejected = true;
    }
    $assert($manualReasonRejected, 'manual allocation fails closed without a recorded override reason');
    $manualChargeId = $charges->createCharge([
        'student_account_id' => $studentAccountId,
        'charge_type_id' => 1,
        'direction' => 'debit',
        'gross_amount' => '20.00',
        'discount_amount' => '0.00',
        'adjustment_amount' => '0.00',
        'net_due' => '20.00',
        'source' => 'manual',
        'academic_year_id' => $academicYearId,
        'request_id' => md5('manual-allocation-charge'),
    ]);
    $manualInstallmentId = $charges->addInstallment($manualChargeId, 'قسط يدوي', '20.00', '2027-04-01', 1);
    $manualChargeKey = md5('manual-allocation-charge-posting');
    $manualChargeTx = $posting->postPartyOperation(
        'student', $studentId, (string) $academicYearId, 'charge', $manualChargeId, $manualChargeKey,
        [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('20.00'), 'installment_id' => $manualInstallmentId]],
        'student_charge', '2026-07-27',
        [
            ['account_id' => $ids['TEST-ALLOC-REC'], 'debit' => Money::fromDecimalString('20.00'), 'credit' => Money::zero()],
            ['account_id' => $ids['TEST-ALLOC-REV'], 'debit' => Money::zero(), 'credit' => Money::fromDecimalString('20.00')],
        ],
        1
    );
    $charges->post($manualChargeId, $manualChargeTx, 1);
    $manualReceiptId = $receiptService->postReceipt(
        $studentAccountId, $studentId, $cashboxId, $academicYearId,
        Money::fromDecimalString('20.00'), 'cash', md5('manual-allocation-receipt'),
        [['installment_id' => $manualInstallmentId, 'amount' => Money::fromDecimalString('20.00')]],
        Money::zero(), 1, '2026-07-27', 'manual', 'طلب ولي الأمر تخصيص القسط المحدد'
    );
    $manualReceipt = $receiptRepo->findById($manualReceiptId);
    $assert($manualReceipt !== null && str_starts_with((string) $manualReceipt['notes'], 'manual_allocation:'), 'manual override reason is persisted on the receipt');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Payment allocation/refund/write-off integration test PASSED on {$testDb}.\n";
