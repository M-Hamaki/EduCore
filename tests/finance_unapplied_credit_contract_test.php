<?php

declare(strict_types=1);

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;

require __DIR__ . '/finance_allocation_integration_test.php';

$partialChargeId = $charges->createCharge([
    'student_account_id' => $studentAccountId,
    'charge_type_id' => 1,
    'direction' => 'debit',
    'gross_amount' => '60.00',
    'discount_amount' => '0.00',
    'adjustment_amount' => '0.00',
    'net_due' => '60.00',
    'source' => 'manual',
    'academic_year_id' => $academicYearId,
    'request_id' => md5('partial-credit-charge'),
]);
$partialInstallmentId = $charges->addInstallment($partialChargeId, 'قسط تطبيق الرصيد', '60.00', '2027-02-01', 1);
$partialChargeKey = md5('partial-credit-charge-posting');
$partialChargeTx = $posting->postPartyOperation(
    'student', $studentId, (string) $academicYearId, 'charge', $partialChargeId, $partialChargeKey,
    [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('60.00'), 'installment_id' => $partialInstallmentId]],
    'student_charge', '2026-07-27',
    [
        ['account_id' => $ids['TEST-ALLOC-REC'], 'debit' => Money::fromDecimalString('60.00'), 'credit' => Money::zero()],
        ['account_id' => $ids['TEST-ALLOC-REV'], 'debit' => Money::zero(), 'credit' => Money::fromDecimalString('60.00')],
    ],
    1
);
$charges->post($partialChargeId, $partialChargeTx, 1);
$applicationId = $allocationPlanner->applyUnappliedCredit($creditId, $partialInstallmentId, Money::fromDecimalString('25.00'), $studentId, $academicYearId, 1, md5('partial-credit-application'));
$assert($applicationId > 0, 'partial unapplied-credit application is persisted independently');
$assert($credits->remaining($creditId) === '75.00', 'partial application leaves the remaining unapplied credit available');
$assert($charges->installmentRemainingDue($partialInstallmentId) === '35.00', 'partial application reduces only the selected installment');
$application = $db->query('SELECT * FROM finance_unapplied_credit_applications WHERE id = ' . $applicationId)->fetch(PDO::FETCH_ASSOC);
$assert($application !== false && (string) $application['applied_amount'] === '25.00' && (string) $application['status'] === 'applied', 'application detail preserves amount and applied status');
$assert((int) $application['subledger_transaction_id'] !== (int) $creditOnly['subledger_transaction_id'], 'credit application owns a separate generic sub-ledger transaction');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} unapplied-credit contract failure(s).\n");
    exit(1);
}

echo "Unapplied-credit creation and partial application contract passed.\n";
