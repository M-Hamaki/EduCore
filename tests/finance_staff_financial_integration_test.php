<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\PayrollRunService;
use EduCore\Modules\Finance\Application\StaffAdvanceService;
use EduCore\Modules\Finance\Application\StaffCompensationService;
use EduCore\Modules\Finance\Application\SubledgerPostingService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Domain\Policy\PayrollCalculationPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoCashboxRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoPayrollRunRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStaffAdvanceMovementRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStaffAdvanceRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStaffCompensationRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
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
    $staffId = 66066;
    $mappingVersion = 66066;

    $itemIds = $db->query('SELECT id FROM payroll_run_items WHERE staff_id = ' . $staffId)->fetchAll(PDO::FETCH_COLUMN);
    if ($itemIds !== []) {
        $itemList = implode(',', array_map('intval', $itemIds));
        $db->exec('DELETE FROM payroll_payments WHERE payroll_run_item_id IN (' . $itemList . ') AND reversal_of IS NOT NULL');
        $db->exec('DELETE FROM payroll_payments WHERE payroll_run_item_id IN (' . $itemList . ')');
        $db->exec('DELETE FROM staff_advance_movements WHERE payroll_run_item_id IN (' . $itemList . ')');
        $db->exec('DELETE FROM payroll_item_components WHERE payroll_run_item_id IN (' . $itemList . ')');
        $db->exec('DELETE FROM payroll_run_items WHERE id IN (' . $itemList . ') AND reversal_of IS NOT NULL');
        $db->exec('DELETE FROM payroll_run_items WHERE id IN (' . $itemList . ')');
    }
    $advanceIds = $db->query('SELECT id FROM staff_advances WHERE staff_id = ' . $staffId)->fetchAll(PDO::FETCH_COLUMN);
    if ($advanceIds !== []) {
        $advanceList = implode(',', array_map('intval', $advanceIds));
        $db->exec('DELETE FROM staff_advance_movements WHERE staff_advance_id IN (' . $advanceList . ') AND reversal_of IS NOT NULL');
        $db->exec('DELETE FROM staff_advance_movements WHERE staff_advance_id IN (' . $advanceList . ')');
        $db->exec('DELETE FROM staff_advance_installments WHERE staff_advance_id IN (' . $advanceList . ')');
        $db->exec('DELETE FROM staff_advances WHERE id IN (' . $advanceList . ')');
    }
    $db->exec('DELETE FROM staff_compensation_contract_components WHERE contract_id IN (SELECT id FROM staff_compensation_contracts WHERE staff_id = ' . $staffId . ')');
    $db->exec('DELETE FROM staff_compensation_contracts WHERE staff_id = ' . $staffId);

    $subledgerIds = $db->query("SELECT id FROM finance_subledger_accounts WHERE party_type = 'staff' AND party_id = {$staffId}")->fetchAll(PDO::FETCH_COLUMN);
    if ($subledgerIds !== []) {
        $subledgerList = implode(',', array_map('intval', $subledgerIds));
        $transactionIds = $db->query('SELECT id FROM finance_subledger_transactions WHERE subledger_account_id IN (' . $subledgerList . ')')->fetchAll(PDO::FETCH_COLUMN);
        if ($transactionIds !== []) {
            $transactionList = implode(',', array_map('intval', $transactionIds));
            $entryIds = $db->query('SELECT id FROM accounting_journal_entries WHERE subledger_transaction_id IN (' . $transactionList . ')')->fetchAll(PDO::FETCH_COLUMN);
            if ($entryIds !== []) {
                $entryList = implode(',', array_map('intval', $entryIds));
                $db->exec('DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (' . $entryList . ')');
                $db->exec('DELETE FROM accounting_journal_entries WHERE id IN (' . $entryList . ') AND reversal_of IS NOT NULL');
                $db->exec('DELETE FROM accounting_journal_entries WHERE id IN (' . $entryList . ')');
            }
            $db->exec('DELETE FROM finance_subledger_lines WHERE transaction_id IN (' . $transactionList . ')');
            $db->exec('DELETE FROM finance_subledger_transactions WHERE id IN (' . $transactionList . ') AND reversal_of IS NOT NULL');
            $db->exec('DELETE FROM finance_subledger_transactions WHERE id IN (' . $transactionList . ')');
        }
        $db->exec('DELETE FROM finance_subledger_accounts WHERE id IN (' . $subledgerList . ')');
    }
    $periodIds = $db->query("SELECT id FROM finance_periods WHERE name = 'STAFF-TEST-66066'")->fetchAll(PDO::FETCH_COLUMN);
    if ($periodIds !== []) {
        $periodList = implode(',', array_map('intval', $periodIds));
        $payrollPeriodIds = $db->query('SELECT id FROM payroll_periods WHERE finance_period_id IN (' . $periodList . ')')->fetchAll(PDO::FETCH_COLUMN);
        if ($payrollPeriodIds !== []) {
            $payrollPeriodList = implode(',', array_map('intval', $payrollPeriodIds));
            $db->exec('DELETE FROM payroll_runs WHERE payroll_period_id IN (' . $payrollPeriodList . ') AND reversal_of IS NOT NULL');
            $db->exec('DELETE FROM payroll_runs WHERE payroll_period_id IN (' . $payrollPeriodList . ')');
            $db->exec('DELETE FROM payroll_periods WHERE id IN (' . $payrollPeriodList . ')');
        }
        $db->exec('DELETE FROM finance_periods WHERE id IN (' . $periodList . ')');
    }
    $db->prepare('DELETE FROM accounting_account_mapping_headers WHERE version_number = ?')->execute([$mappingVersion]);
    $db->prepare('DELETE FROM finance_cashboxes WHERE code = ?')->execute(['TEST-STAFF-CASH']);

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)'
    );
    $accountIds = [];
    foreach ([
        ['TEST-STAFF-SALARY', 'مصروف رواتب اختبار', 'expense'],
        ['TEST-STAFF-TAX', 'ضرائب مستحقة اختبار', 'liability'],
        ['TEST-STAFF-PAYABLE', 'رواتب مستحقة اختبار', 'liability'],
        ['TEST-STAFF-CASH', 'نقدية رواتب اختبار', 'asset'],
        ['TEST-STAFF-ADVANCE', 'سلف عاملين اختبار', 'asset'],
        ['TEST-STAFF-ADV-CLEAR', 'وسيط خصم سلف اختبار', 'liability'],
        ['TEST-STAFF-WRITEOFF', 'إعدام سلف اختبار', 'expense'],
    ] as [$code, $name, $type]) {
        $accountInsert->execute([$code, $name, $type]);
        $accountIds[$code] = (int) $db->lastInsertId();
    }
    $db->prepare('INSERT INTO finance_cashboxes (code, name, type, is_active, accountability_role, receipt_prefix) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['TEST-STAFF-CASH', 'خزينة رواتب اختبار', 'cash', 1, 'admin', 'TSP']);
    $cashboxId = (int) $db->lastInsertId();

    $componentRows = $db->query("SELECT id, code FROM payroll_components WHERE code IN ('basic','allowance_fixed','tax','advance')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $componentIds = array_flip($componentRows);
    foreach (['basic', 'allowance_fixed', 'tax', 'advance'] as $code) {
        if (!isset($componentIds[$code])) {
            throw new RuntimeException('Required seeded payroll component is missing: ' . $code);
        }
    }

    $db->prepare('INSERT INTO accounting_account_mapping_headers (version_number, effective_from, status, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$mappingVersion, '2026-01-01', 'active', 1]);
    $headerId = (int) $db->lastInsertId();
    $mappingInsert = $db->prepare(
        'INSERT INTO accounting_account_mapping_lines
            (mapping_header_id, operation_type, selector_payroll_component_id, selector_payment_method, selector_cashbox_id, debit_account_id, credit_account_id, specificity_score, priority)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mappingInsert->execute([$headerId, 'payroll_component', $componentIds['basic'], null, null, $accountIds['TEST-STAFF-SALARY'], $accountIds['TEST-STAFF-PAYABLE'], 1, 100]);
    $mappingInsert->execute([$headerId, 'payroll_component', $componentIds['allowance_fixed'], null, null, $accountIds['TEST-STAFF-SALARY'], $accountIds['TEST-STAFF-PAYABLE'], 1, 100]);
    $mappingInsert->execute([$headerId, 'payroll_component', $componentIds['tax'], null, null, $accountIds['TEST-STAFF-SALARY'], $accountIds['TEST-STAFF-TAX'], 1, 100]);
    $mappingInsert->execute([$headerId, 'payroll_component', $componentIds['advance'], null, null, $accountIds['TEST-STAFF-SALARY'], $accountIds['TEST-STAFF-ADV-CLEAR'], 1, 100]);
    $mappingInsert->execute([$headerId, 'payroll_run_item_posting', null, null, null, $accountIds['TEST-STAFF-SALARY'], $accountIds['TEST-STAFF-PAYABLE'], 0, 100]);
    $mappingInsert->execute([$headerId, 'payroll_payment', null, 'cash', $cashboxId, $accountIds['TEST-STAFF-PAYABLE'], $accountIds['TEST-STAFF-CASH'], 2, 100]);
    $mappingInsert->execute([$headerId, 'advance_issue', null, null, null, $accountIds['TEST-STAFF-ADVANCE'], $accountIds['TEST-STAFF-CASH'], 0, 100]);
    $mappingInsert->execute([$headerId, 'advance_cash_repayment', null, null, $cashboxId, $accountIds['TEST-STAFF-CASH'], $accountIds['TEST-STAFF-ADVANCE'], 1, 100]);
    $mappingInsert->execute([$headerId, 'advance_payroll_deduction', null, null, null, $accountIds['TEST-STAFF-ADV-CLEAR'], $accountIds['TEST-STAFF-ADVANCE'], 0, 100]);
    $mappingInsert->execute([$headerId, 'advance_write_off', null, null, null, $accountIds['TEST-STAFF-WRITEOFF'], $accountIds['TEST-STAFF-ADVANCE'], 0, 100]);

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
    $posting = new SubledgerPostingService($transactions, $subledgerAccounts, new PdoSubledgerTransactionRepository($db), $subledgerLines, $journals, $audit);

    $compensationRepository = new PdoStaffCompensationRepository($db);
    $compensationService = new StaffCompensationService($compensationRepository, $transactions, $audit);
    $contractId = $compensationService->createDraft($staffId, '2026-07-01', 'business_decision', 'confirmed', [
        ['component_id' => $componentIds['basic'], 'amount' => Money::fromDecimalString('5000.00'), 'direction' => 'earning'],
        ['component_id' => $componentIds['allowance_fixed'], 'amount' => Money::fromDecimalString('1000.00'), 'direction' => 'earning'],
        ['component_id' => $componentIds['tax'], 'amount' => Money::fromDecimalString('500.00'), 'direction' => 'deduction'],
        ['component_id' => $componentIds['advance'], 'amount' => Money::fromDecimalString('200.00'), 'direction' => 'deduction'],
    ], 1);
    $compensationService->activate($contractId, 2);

    $advanceService = new StaffAdvanceService(new PdoStaffAdvanceRepository($db), new PdoStaffAdvanceMovementRepository($db), $posting, $journals, $transactions, $audit);
    $advanceRequest = md5('staff-advance-66066');
    $advanceId = $advanceService->issueAdvance($staffId, Money::fromDecimalString('1000.00'), '2026-07-01', 'سلفة اختبار', 1, $advanceRequest);
    $assert($advanceService->issueAdvance($staffId, Money::fromDecimalString('1000.00'), '2026-07-01', 'سلفة اختبار', 1, $advanceRequest) === $advanceId, 'advance issue is idempotent');

    $db->prepare('INSERT INTO finance_periods (academic_year_id, name, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([66066, 'STAFF-TEST-66066', '2026-07-01', '2026-07-31', 'open']);
    $financePeriodId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO payroll_periods (finance_period_id, start_date, end_date, pay_date, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$financePeriodId, '2026-07-01', '2026-07-31', '2026-07-31', 'open']);
    $payrollPeriodId = (int) $db->lastInsertId();

    $payrollRepository = new PdoPayrollRunRepository($db);
    $payrollService = new PayrollRunService($payrollRepository, $posting, $journals, new PayrollCalculationPolicy(), $transactions, new PdoCashboxRepository($db), $audit, $compensationRepository);
    $runId = $payrollService->createRun($payrollPeriodId, 1, false, md5('payroll-run-66066'));
    $payrollService->markCalculated($runId, 1);
    $payrollService->reviewRun($runId, 3);
    $payrollService->approveRun($runId, 2);
    $itemId = $payrollService->postPayrollItem($runId, $staffId, '2026-07-31', 1, 2, md5('payroll-item-66066'));
    $payslip = $payrollService->payslip($itemId);
    $assert((string) $payslip['gross'] === '6000.00' && (string) $payslip['total_deductions'] === '700.00' && (string) $payslip['net'] === '5300.00', 'server computes gross, deductions, and net from the active contract');
    $assert(str_starts_with((string) $payslip['payslip_ref_number'], 'PAY-') && (string) $payslip['payment_status'] === 'unpaid', 'frozen payslip has a reference and unpaid state');
    $assert(str_contains((string) $payslip['contract_snapshot_json'], '2026-07-31'), 'payslip freezes the effective contract snapshot');
    $payrollService->finalizeRun($runId, 1, 2);
    $paymentId = $payrollService->postPayment($itemId, $staffId, $cashboxId, Money::fromDecimalString('5300.00'), 'cash', 1, 2, md5('payroll-payment-66066'));
    $payslip = $payrollService->payslip($itemId);
    $assert((string) $payslip['payment_status'] === 'paid', 'full payroll payment marks payslip paid');

    $paymentReversalRequest = md5('payroll-payment-reversal-66066');
    $paymentReversalId = $payrollService->reversePayment($paymentId, $staffId, 1, 2, 'إلغاء دفعة راتب مسجلة بالخطأ', $paymentReversalRequest);
    $assert($payrollService->reversePayment($paymentId, $staffId, 1, 2, 'إلغاء دفعة راتب مسجلة بالخطأ', $paymentReversalRequest) === $paymentReversalId, 'payroll payment reversal retry is idempotent');
    $assert((string) $payrollService->payslip($itemId)['payment_status'] === 'unpaid', 'payment reversal restores the frozen payslip to unpaid');
    $payrollService->postPayment($itemId, $staffId, $cashboxId, Money::fromDecimalString('5300.00'), 'cash', 1, 2, md5('payroll-payment-repost-66066'));
    $assert((string) $payrollService->payslip($itemId)['payment_status'] === 'paid', 'a new payment can settle the salary after reversal');

    $cashRepaymentId = $advanceService->recordCashRepayment($advanceId, $staffId, $cashboxId, Money::fromDecimalString('200.00'), 1, md5('advance-cash-repayment-66066'));
    $assert($posting->bucketBalance((int) $subledgerAccounts->findOrCreate('staff', $staffId, 'STAFF_GLOBAL')['id'], 'STAFF_ADVANCE_RECEIVABLE') === '800.00', 'cash repayment reduces the advance receivable');
    $cashReversalRequest = md5('advance-cash-reversal-66066');
    $cashReversalId = $advanceService->reverseMovement($cashRepaymentId, $staffId, 1, 2, 'إلغاء سداد نقدي مسجل بالخطأ', $cashReversalRequest);
    $assert($advanceService->reverseMovement($cashRepaymentId, $staffId, 1, 2, 'إلغاء سداد نقدي مسجل بالخطأ', $cashReversalRequest) === $cashReversalId, 'advance movement reversal retry is idempotent');
    $assert($posting->bucketBalance((int) $subledgerAccounts->findOrCreate('staff', $staffId, 'STAFF_GLOBAL')['id'], 'STAFF_ADVANCE_RECEIVABLE') === '1000.00', 'cash repayment original plus reversal restores the advance receivable');

    $deductionRequest = md5('advance-deduction-66066');
    $movementId = $advanceService->recordPayrollDeduction($advanceId, $staffId, $itemId, Money::fromDecimalString('200.00'), 1, $deductionRequest);
    $assert($advanceService->recordPayrollDeduction($advanceId, $staffId, $itemId, Money::fromDecimalString('200.00'), 1, $deductionRequest) === $movementId, 'advance payroll deduction is idempotent');
    $advanceService->writeOffAdvance($advanceId, $staffId, Money::fromDecimalString('800.00'), 1, 2, 'تسوية معتمدة', md5('advance-writeoff-66066'));
    $overWriteOffRejected = false;
    try {
        $advanceService->writeOffAdvance($advanceId, $staffId, Money::fromDecimalString('1.00'), 1, 2, 'زيادة', md5('advance-overwriteoff-66066'));
    } catch (RuntimeException) {
        $overWriteOffRejected = true;
    }
    $assert($overWriteOffRejected, 'advance over-write-off is rejected under lock');

    $staffSubledger = $subledgerAccounts->findOrCreate('staff', $staffId, 'STAFF_GLOBAL');
    $assert($posting->bucketBalance((int) $staffSubledger['id'], 'STAFF_PAYROLL_PAYABLE') === '0.00', 'payroll posting and payment net to zero payable');
    $assert($posting->bucketBalance((int) $staffSubledger['id'], 'STAFF_ADVANCE_RECEIVABLE') === '0.00', 'advance 1000 minus deduction 200 minus write-off 800 nets to zero');
    $assert((int) $db->query("SELECT COUNT(*) FROM finance_subledger_accounts WHERE party_type = 'staff' AND party_id = {$staffId} AND scope_key = 'STAFF_GLOBAL'")->fetchColumn() === 1, 'staff uses one stable STAFF_GLOBAL account');

    $reversibleRunId = $payrollService->createRun($payrollPeriodId, 1, false, md5('payroll-run-reversible-66066'));
    $payrollService->markCalculated($reversibleRunId, 1);
    $payrollService->reviewRun($reversibleRunId, 3);
    $payrollService->approveRun($reversibleRunId, 2);
    $reversibleItemId = $payrollService->postPayrollItem($reversibleRunId, $staffId, '2026-07-31', 1, 2, md5('payroll-item-reversible-66066'));
    $payrollService->finalizeRun($reversibleRunId, 1, 2);
    $assert($posting->bucketBalance((int) $staffSubledger['id'], 'STAFF_PAYROLL_PAYABLE') === '5300.00', 'second posted unpaid run increases payroll payable');
    $reversalRequest = md5('payroll-run-reversal-66066');
    $reversalRunId = $payrollService->reverseRun($reversibleRunId, 1, 2, 'إلغاء تشغيل تجريبي خاطئ', $reversalRequest);
    $assert($payrollService->reverseRun($reversibleRunId, 1, 2, 'إلغاء تشغيل تجريبي خاطئ', $reversalRequest) === $reversalRunId, 'payroll run reversal retry is idempotent');
    $assert($posting->bucketBalance((int) $staffSubledger['id'], 'STAFF_PAYROLL_PAYABLE') === '0.00', 'payroll run original plus reversal nets to zero');
    $reversalRun = $db->query('SELECT * FROM payroll_runs WHERE id = ' . $reversalRunId)->fetch(PDO::FETCH_ASSOC);
    $reversalItem = $db->query('SELECT * FROM payroll_run_items WHERE payroll_run_id = ' . $reversalRunId)->fetch(PDO::FETCH_ASSOC);
    $assert((int) $reversalRun['reversal_of'] === $reversibleRunId && (string) $reversalRun['status'] === 'posted', 'reversal is a new posted run linked to the original');
    $assert((int) $reversalItem['reversal_of'] === $reversibleItemId && (string) $reversalItem['net'] === '-5300.00', 'reversal item is an immutable opposite signed copy');
    $reversalTx = $db->query('SELECT reversal_of FROM finance_subledger_transactions WHERE id = ' . (int) $reversalItem['subledger_transaction_id'])->fetchColumn();
    $originalItemTx = $db->query('SELECT subledger_transaction_id FROM payroll_run_items WHERE id = ' . $reversibleItemId)->fetchColumn();
    $assert((int) $reversalTx === (int) $originalItemTx, 'reversal item links to a new opposite sub-ledger transaction');
    $sameActorReversalRejected = false;
    try {
        $payrollService->reverseRun($runId, 1, 1, 'مرفوض', md5('payroll-run-same-actor-66066'));
    } catch (RuntimeException) {
        $sameActorReversalRejected = true;
    }
    $assert($sameActorReversalRejected, 'payroll reversal enforces maker-checker');

    $replacementContractId = $compensationService->createDraft($staffId, '2026-07-15', 'business_decision', 'confirmed', [
        ['component_id' => $componentIds['basic'], 'amount' => Money::fromDecimalString('5500.00'), 'direction' => 'earning'],
        ['component_id' => $componentIds['allowance_fixed'], 'amount' => Money::fromDecimalString('1000.00'), 'direction' => 'earning'],
        ['component_id' => $componentIds['tax'], 'amount' => Money::fromDecimalString('500.00'), 'direction' => 'deduction'],
        ['component_id' => $componentIds['advance'], 'amount' => Money::fromDecimalString('200.00'), 'direction' => 'deduction'],
    ], 1);
    $compensationService->activate($replacementContractId, 2);
    $settlementRunId = $payrollService->createRun($payrollPeriodId, 1, true, md5('payroll-settlement-run-66066'));
    $payrollService->markCalculated($settlementRunId, 1);
    $payrollService->reviewRun($settlementRunId, 3);
    $payrollService->approveRun($settlementRunId, 2);
    $settlementItemId = $payrollService->postRetroactiveSettlementItem($settlementRunId, $itemId, '2026-07-31', 1, 2, md5('payroll-settlement-item-66066'));
    $settlementPayslip = $payrollService->payslip($settlementItemId);
    $assert((string) $settlementPayslip['gross'] === '500.00' && (string) $settlementPayslip['net'] === '500.00', 'retroactive salary difference is a separate signed settlement item');
    $assert((string) $payrollService->payslip($itemId)['net'] === '5300.00', 'the original frozen payslip remains unchanged after contract replacement');
    $assert($posting->bucketBalance((int) $staffSubledger['id'], 'STAFF_PAYROLL_PAYABLE') === '500.00', 'settlement posts the retroactive difference into payroll payable');
    $payrollService->finalizeRun($settlementRunId, 1, 2);
    $payrollService->postPayment($settlementItemId, $staffId, $cashboxId, Money::fromDecimalString('500.00'), 'cash', 1, 2, md5('payroll-settlement-payment-66066'));
    $assert($posting->bucketBalance((int) $staffSubledger['id'], 'STAFF_PAYROLL_PAYABLE') === '0.00', 'settlement payment clears the payroll payable bucket');

    $imbalanced = (int) $db->query(
        "SELECT COUNT(*) FROM (
            SELECT je.id FROM accounting_journal_entries je
            JOIN accounting_journal_lines jl ON jl.journal_entry_id = je.id
            WHERE je.subledger_transaction_id IN (SELECT id FROM finance_subledger_transactions WHERE subledger_account_id = " . (int) $staffSubledger['id'] . ")
            GROUP BY je.id HAVING ROUND(SUM(jl.debit), 2) <> ROUND(SUM(jl.credit), 2)
        ) broken"
    )->fetchColumn();
    $assert($imbalanced === 0, 'every staff party operation has a balanced linked GL journal');
    $assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE subledger_transaction_id IN (SELECT id FROM finance_subledger_transactions WHERE subledger_account_id = ' . (int) $staffSubledger['id'] . ')')->fetchColumn() === 13, 'thirteen staff operations create thirteen linked journals including payment, advance, run, and settlement reversals');
    $assert($audit->events >= 20, 'contract, payroll workflow, staff postings, and reversals use shared audit events');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Staff contract/payroll/advance integration test PASSED on {$testDb}.\n";
