<?php

declare(strict_types=1);

/**
 * Contract test for US3: receipt granularity + unapplied credit + refund semantics.
 *
 * Verifies:
 * - receipt.amount = SUM(allocations) + overpayment
 * - Each allocation posts a negative STUDENT_OUTSTANDING_DUE delta
 * - Overpayment posts a positive STUDENT_UNAPPLIED_CREDIT delta
 * - refund_allocation restores outstanding_due (+amount)
 * - refund_unapplied_credit shrinks credit (−amount on unapplied_credit)
 * - original + reversal = 0
 *
 * Run: php tests/finance_receipt_granularity_contract_test.php
 */

require_once __DIR__ . '/../src/Modules/Finance/Domain/Money.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/SignedMoneyDelta.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

// ============================================================
// Scenario: receipt 1000 EGP, 3 installments (300+300+300=900) + overpayment 100
// ============================================================
$receiptAmount = Money::fromDecimalString('1000.00');
$alloc1 = Money::fromDecimalString('300.00');
$alloc2 = Money::fromDecimalString('300.00');
$alloc3 = Money::fromDecimalString('300.00');
$overpayment = Money::fromDecimalString('100.00');

$totalAllocations = $alloc1->add($alloc2)->add($alloc3);
$assert($totalAllocations->toDatabaseString() === '900.00', 'total allocations = 900.00');

$components = $totalAllocations->add($overpayment);
$assert($receiptAmount->equals($components), 'receipt.amount = allocations + overpayment (1000)');

// ============================================================
// Sub-ledger lines simulation
// ============================================================
$lines = [];
// Allocation lines: negative STUDENT_OUTSTANDING_DUE
$lines[] = ['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('-' . $alloc1->toDatabaseString())];
$lines[] = ['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('-' . $alloc2->toDatabaseString())];
$lines[] = ['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('-' . $alloc3->toDatabaseString())];
// Overpayment line: positive STUDENT_UNAPPLIED_CREDIT
$lines[] = ['bucket' => 'STUDENT_UNAPPLIED_CREDIT', 'delta' => SignedMoneyDelta::fromDecimalString($overpayment->toDatabaseString())];

$assert(count($lines) === 4, 'receipt = 4 sub-ledger lines (3 allocations + 1 overpayment)');

// Sum of OUTSTANDING_DUE deltas = -900
$outstandingSum = SignedMoneyDelta::zero();
foreach ($lines as $l) {
    if ($l['bucket'] === 'STUDENT_OUTSTANDING_DUE') {
        $outstandingSum = $outstandingSum->add($l['delta']);
    }
}
$assert($outstandingSum->toDatabaseString() === '-900.00', 'outstanding_due sum = -900.00');

// Sum of UNAPPLIED_CREDIT deltas = +100
$creditSum = SignedMoneyDelta::zero();
foreach ($lines as $l) {
    if ($l['bucket'] === 'STUDENT_UNAPPLIED_CREDIT') {
        $creditSum = $creditSum->add($l['delta']);
    }
}
$assert($creditSum->toDatabaseString() === '100.00', 'unapplied_credit sum = +100.00');

// ============================================================
// Refund semantics: allocation_refund restores outstanding_due
// ============================================================
$refundAllocationAmount = Money::fromDecimalString('300.00'); // refund one allocation
$refundAllocDelta = SignedMoneyDelta::fromDecimalString($refundAllocationAmount->toDatabaseString()); // +300 on OUTSTANDING_DUE
$assert($refundAllocDelta->isPositive(), 'refund_allocation delta is positive (+300)');
$assert($refundAllocDelta->toDatabaseString() === '300.00', 'refund_allocation = +300.00');

// After refund: outstanding_due = -900 + 300 = -600 (still reduced by 600)
$outstandingAfterRefund = $outstandingSum->add($refundAllocDelta);
$assert($outstandingAfterRefund->toDatabaseString() === '-600.00', 'outstanding after refund_allocation = -600');

// ============================================================
// Refund semantics: refund_unapplied_credit shrinks credit
// ============================================================
$refundCreditAmount = Money::fromDecimalString('100.00');
$refundCreditDelta = SignedMoneyDelta::fromDecimalString('-' . $refundCreditAmount->toDatabaseString()); // -100 on UNAPPLIED_CREDIT
$assert($refundCreditDelta->isNegative(), 'refund_unapplied_credit delta is negative (-100)');
$assert($refundCreditDelta->toDatabaseString() === '-100.00', 'refund_unapplied_credit = -100.00');

// After refund: unapplied_credit = 100 - 100 = 0
$creditAfterRefund = $creditSum->add($refundCreditDelta);
$assert($creditAfterRefund->isZero(), 'unapplied_credit after refund = 0');

// ============================================================
// Original + reversal = 0 (receipt reversal)
// ============================================================
$receiptNetDelta = $outstandingSum->add($creditSum); // -900 + 100 = -800
$receiptReversalNet = $receiptNetDelta->negate(); // +800
$net = $receiptNetDelta->add($receiptReversalNet);
$assert($net->isZero(), 'receipt original + reversal = 0');

// ============================================================
// Allocation <= installment remaining_due
// ============================================================
$installmentNet = Money::fromDecimalString('300.00');
$assert(!$alloc1->greaterThan($installmentNet), 'allocation (300) <= installment (300)');

$tooMuch = Money::fromDecimalString('350.00');
$assert($tooMuch->greaterThan($installmentNet), 'over-allocation detected (350 > 300)');

// ============================================================
// Result
// ============================================================
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}

// Execute the contract against the real repositories and isolated test schema.
require __DIR__ . '/finance_allocation_integration_test.php';

$granularChargeId = $charges->createCharge([
    'student_account_id' => $studentAccountId,
    'charge_type_id' => 1,
    'direction' => 'debit',
    'gross_amount' => '30.00',
    'discount_amount' => '0.00',
    'adjustment_amount' => '0.00',
    'net_due' => '30.00',
    'source' => 'manual',
    'academic_year_id' => $academicYearId,
    'request_id' => md5('granular-charge'),
]);
$granularInstallments = [];
foreach (['الأول', 'الثاني', 'الثالث'] as $index => $name) {
    $granularInstallments[] = $charges->addInstallment($granularChargeId, $name, '10.00', '2027-03-0' . ($index + 1), $index + 1);
}
$granularChargeKey = md5('granular-charge-posting');
$granularChargeTx = $posting->postPartyOperation(
    'student', $studentId, (string) $academicYearId, 'charge', $granularChargeId, $granularChargeKey,
    [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('30.00')]],
    'student_charge', '2026-07-27',
    [
        ['account_id' => $ids['TEST-ALLOC-REC'], 'debit' => Money::fromDecimalString('30.00'), 'credit' => Money::zero()],
        ['account_id' => $ids['TEST-ALLOC-REV'], 'debit' => Money::zero(), 'credit' => Money::fromDecimalString('30.00')],
    ],
    1
);
$charges->post($granularChargeId, $granularChargeTx, 1);
$granularAllocations = array_map(static fn (int $installmentId): array => [
    'installment_id' => $installmentId,
    'amount' => Money::fromDecimalString('10.00'),
], $granularInstallments);
$granularReceiptId = $receiptService->postReceipt(
    $studentAccountId,
    $studentId,
    $cashboxId,
    $academicYearId,
    Money::fromDecimalString('35.00'),
    'cash',
    md5('granular-receipt'),
    $granularAllocations,
    Money::fromDecimalString('5.00'),
    1,
    '2026-07-27'
);
$granularReceipt = $receiptRepo->findById($granularReceiptId);
$granularTxId = (int) ($granularReceipt['subledger_transaction_id'] ?? 0);
$assert($granularTxId > 0, 'granular receipt owns one generic sub-ledger transaction');
$assert((int) $db->query('SELECT COUNT(*) FROM finance_subledger_lines WHERE transaction_id = ' . $granularTxId)->fetchColumn() === 4, 'three allocations plus overpayment create exactly four bucket lines');
$assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE subledger_transaction_id = ' . $granularTxId . ' AND status = \'posted\'')->fetchColumn() === 1, 'granular receipt owns exactly one linked posted GL journal');
$assert((int) $db->query('SELECT COUNT(*) FROM finance_payment_allocations WHERE receipt_id = ' . $granularReceiptId . ' AND subledger_transaction_id = ' . $granularTxId)->fetchColumn() === 3, 'all three allocations share the receipt transaction');
$assert((int) $db->query('SELECT COUNT(*) FROM finance_unapplied_credits WHERE receipt_id = ' . $granularReceiptId . ' AND subledger_transaction_id = ' . $granularTxId)->fetchColumn() === 1, 'overpayment detail shares the receipt transaction');
$journalBalance = (string) $db->query('SELECT SUM(l.debit) - SUM(l.credit) FROM accounting_journal_lines l JOIN accounting_journal_entries e ON e.id = l.journal_entry_id WHERE e.subledger_transaction_id = ' . $granularTxId)->fetchColumn();
$assert($journalBalance === '0.00', 'granular receipt GL journal is balanced');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} receipt granularity failure(s).\n");
    exit(1);
}

echo "All US3 receipt granularity contracts passed against the isolated database.\n";
