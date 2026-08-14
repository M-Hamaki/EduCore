<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\ImportService;
use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\VoucherImportOperation;
use EduCore\Modules\Finance\Application\VoucherService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoBankAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoCashboxRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoImportBatchRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoVoucherRepository;
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
    $mappingVersion = 55055;
    $importBatchKey = md5('voucher-import-batch');
    $importReversalKey = md5('voucher-import-reversal-batch');
    $importRequest = md5($importBatchKey . ':1');
    $importReversalRequest = md5($importReversalKey . ':1');
    $requestIds = [$importReversalRequest, $importRequest, md5('voucher-expense-reversal'), md5('voucher-expense'), md5('voucher-income'), md5('voucher-transfer'), md5('voucher-control-bypass')];
    foreach ($requestIds as $requestId) {
        $entryIds = $db->query('SELECT id FROM accounting_journal_entries WHERE source_idempotency_key = ' . $db->quote($requestId))->fetchAll(PDO::FETCH_COLUMN);
        if ($entryIds !== []) {
            $entryList = implode(',', array_map('intval', $entryIds));
            $db->exec('DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (' . $entryList . ')');
            $db->exec('DELETE FROM accounting_journal_entries WHERE id IN (' . $entryList . ')');
        }
        $voucherIds = $db->query('SELECT id FROM finance_vouchers WHERE request_id = ' . $db->quote($requestId))->fetchAll(PDO::FETCH_COLUMN);
        if ($voucherIds !== []) {
            $voucherList = implode(',', array_map('intval', $voucherIds));
            $db->exec('DELETE FROM finance_voucher_lines WHERE voucher_id IN (' . $voucherList . ')');
            $db->exec('DELETE FROM finance_vouchers WHERE id IN (' . $voucherList . ')');
        }
    }
    $importBatchIds = $db->query("SELECT id FROM finance_import_batches WHERE batch_id IN (" . $db->quote($importBatchKey) . ',' . $db->quote($importReversalKey) . ')')->fetchAll(PDO::FETCH_COLUMN);
    if ($importBatchIds !== []) {
        $importBatchList = implode(',', array_map('intval', $importBatchIds));
        $db->exec('DELETE FROM finance_import_rows WHERE import_batch_id IN (' . $importBatchList . ')');
        $db->exec('DELETE FROM finance_import_batches WHERE id IN (' . $importBatchList . ') AND reversal_of IS NOT NULL');
        $db->exec('DELETE FROM finance_import_batches WHERE id IN (' . $importBatchList . ')');
    }
    $db->prepare('DELETE FROM accounting_account_mapping_headers WHERE version_number = ?')->execute([$mappingVersion]);
    $db->prepare("DELETE FROM finance_cashboxes WHERE code IN ('TEST-VOUCHER-A','TEST-VOUCHER-B')")->execute();

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type, is_control_account) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type), is_control_account = VALUES(is_control_account)'
    );
    $accountIds = [];
    foreach ([
        ['TEST-VOUCHER-EXP', 'مصروف سند اختبار', 'expense', 0],
        ['TEST-VOUCHER-INC', 'إيراد سند اختبار', 'revenue', 0],
        ['TEST-VOUCHER-CASH-A', 'خزينة سند أ', 'asset', 0],
        ['TEST-VOUCHER-CASH-B', 'خزينة سند ب', 'asset', 0],
        ['TEST-VOUCHER-CONTROL', 'حساب رقابي طلاب اختبار', 'asset', 1],
    ] as [$code, $name, $type, $isControl]) {
        $accountInsert->execute([$code, $name, $type, $isControl]);
        $accountIds[$code] = (int) $db->lastInsertId();
    }
    $db->prepare('DELETE FROM accounting_control_accounts WHERE account_id = ?')->execute([$accountIds['TEST-VOUCHER-CONTROL']]);
    $db->prepare('INSERT INTO accounting_control_accounts (account_id, sub_ledger_type, normal_balance, reconciliation_tolerance) VALUES (?, ?, ?, ?)')
        ->execute([$accountIds['TEST-VOUCHER-CONTROL'], 'student', 'debit', '0.00']);

    $cashboxInsert = $db->prepare('INSERT INTO finance_cashboxes (code, name, type, is_active, accountability_role, receipt_prefix) VALUES (?, ?, ?, ?, ?, ?)');
    $cashboxInsert->execute(['TEST-VOUCHER-A', 'خزينة سند أ', 'cash', 1, 'admin', 'VA']);
    $cashboxA = (int) $db->lastInsertId();
    $cashboxInsert->execute(['TEST-VOUCHER-B', 'خزينة سند ب', 'cash', 1, 'admin', 'VB']);
    $cashboxB = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO accounting_account_mapping_headers (version_number, effective_from, status, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$mappingVersion, '2026-01-01', 'active', 1]);
    $headerId = (int) $db->lastInsertId();
    $mappingInsert = $db->prepare(
        'INSERT INTO accounting_account_mapping_lines
            (mapping_header_id, operation_type, selector_cashbox_id, selector_voucher_type, debit_account_id, credit_account_id, specificity_score, priority)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mappingInsert->execute([$headerId, 'voucher', $cashboxA, 'expense', $accountIds['TEST-VOUCHER-EXP'], $accountIds['TEST-VOUCHER-CASH-A'], 2, 100]);
    $mappingInsert->execute([$headerId, 'voucher', $cashboxA, 'other_income', $accountIds['TEST-VOUCHER-CASH-A'], $accountIds['TEST-VOUCHER-INC'], 2, 100]);
    $mappingInsert->execute([$headerId, 'voucher_transfer_out', $cashboxA, null, $accountIds['TEST-VOUCHER-CASH-B'], $accountIds['TEST-VOUCHER-CASH-A'], 1, 100]);
    $mappingInsert->execute([$headerId, 'voucher_transfer_in', $cashboxB, null, $accountIds['TEST-VOUCHER-CASH-B'], $accountIds['TEST-VOUCHER-CASH-A'], 1, 100]);

    $audit = new class implements AuditEventWriter {
        public int $events = 0;
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            ++$this->events;
        }
    };
    $controlAccounts = new ControlAccountService(new PdoControlAccountRepository($db), new PdoSubledgerLineRepository($db));
    $journals = new JournalEntryService(new PdoJournalEntryRepository($db), new PdoAccountMappingLineRepository($db), new AccountMappingPolicy(), $controlAccounts);
    $cashboxRepository = new PdoCashboxRepository($db);
    $service = new VoucherService(new PdoVoucherRepository($db), $journals, new PdoFinanceTransactionManager($db), $audit, $cashboxRepository, new PdoBankAccountRepository($db));
    $subledgerCountBefore = (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn();

    $expenseRequest = md5('voucher-expense');
    $expenseId = $service->postVoucher('expense', $cashboxA, null, null, null, Money::fromDecimalString('500.00'), null, '2026-07-25', null, 'أدوات مكتبية', 1, 2, $expenseRequest);
    $assert($service->postVoucher('expense', $cashboxA, null, null, null, Money::fromDecimalString('500.00'), null, '2026-07-25', null, 'أدوات مكتبية', 1, 2, $expenseRequest) === $expenseId, 'voucher retry is idempotent');
    $incomeId = $service->postVoucher('other_income', $cashboxA, null, null, null, Money::fromDecimalString('200.00'), null, '2026-07-25', null, 'إيراد نشاط', 1, 2, md5('voucher-income'));
    $transferId = $service->postVoucher('cash_transfer', null, $cashboxA, $cashboxB, null, Money::fromDecimalString('100.00'), null, '2026-07-25', null, 'تحويل بين الخزن', 1, 2, md5('voucher-transfer'));
    $assert($expenseId > 0 && $incomeId > 0 && $transferId > 0, 'expense, income, and transfer vouchers are posted');
    $expenseReversalRequest = md5('voucher-expense-reversal');
    $expenseReversalId = $service->reverseVoucher($expenseId, '2026-07-26', 'إلغاء سند مصروف مسجل بالخطأ', 1, 2, $expenseReversalRequest);
    $assert($service->reverseVoucher($expenseId, '2026-07-26', 'إلغاء سند مصروف مسجل بالخطأ', 1, 2, $expenseReversalRequest) === $expenseReversalId, 'voucher reversal retry is idempotent');
    $expenseOriginal = $db->query('SELECT status FROM finance_vouchers WHERE id = ' . $expenseId)->fetchColumn();
    $expenseReversal = $db->query('SELECT reversal_of, status FROM finance_vouchers WHERE id = ' . $expenseReversalId)->fetch(PDO::FETCH_ASSOC);
    $assert($expenseOriginal === 'posted' && (int) $expenseReversal['reversal_of'] === $expenseId && (string) $expenseReversal['status'] === 'posted', 'original voucher remains posted and reversal is a new linked posted voucher');
    $assert($cashboxRepository->expectedReceiptTotal($cashboxA, '2026-07-25') === '-400.00', 'daily settlement includes expense, income, and outgoing transfer movements for the holding cashbox');
    $assert($cashboxRepository->expectedReceiptTotal($cashboxA, '2026-07-26') === '500.00', 'voucher reversal affects the settlement date as an opposite cash movement');
    $assert($cashboxRepository->expectedReceiptTotal($cashboxB, '2026-07-25') === '100.00', 'daily settlement includes incoming cash transfer movement');

    $importService = new ImportService(new PdoImportBatchRepository($db), new PdoFinanceTransactionManager($db), $audit, [new VoucherImportOperation($service)]);
    $importBatchId = $importService->createBatch($importBatchKey, '1.0', 'private:finance_imports/vouchers.csv', 1, 'vouchers');
    $voucherCountBeforePreview = (int) $db->query('SELECT COUNT(*) FROM finance_vouchers')->fetchColumn();
    $importService->stagePayload($importBatchId, 1, [
        'voucher_type' => 'expense',
        'cashbox_id' => $cashboxA,
        'amount' => '75.00',
        'entry_date' => '2026-07-25',
        'description' => 'سند مستورد للاختبار',
    ]);
    $importService->updateCounts($importBatchId, 1, 0);
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_vouchers')->fetchColumn() === $voucherCountBeforePreview, 'import preview stages rows without business writes');
    $importService->postBatch($importBatchId, 1, 2);
    $importedVoucher = $db->query('SELECT * FROM finance_vouchers WHERE request_id = ' . $db->quote($importRequest))->fetch(PDO::FETCH_ASSOC);
    $assert($importedVoucher !== false && (string) $importedVoucher['status'] === 'posted', 'approved import posts its voucher row atomically');
    $importReversalId = $importService->reverseBatch($importBatchId, 1, 2, $importReversalKey);
    $assert($importService->reverseBatch($importBatchId, 1, 2, $importReversalKey) === $importReversalId, 'import reversal batch retry is idempotent');
    $importStatuses = $db->query('SELECT status, reversal_of FROM finance_import_batches WHERE id IN (' . $importBatchId . ',' . $importReversalId . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assert((string) $importStatuses[0]['status'] === 'reversed' && (string) $importStatuses[1]['status'] === 'posted' && (int) $importStatuses[1]['reversal_of'] === $importBatchId, 'posted import is corrected by a linked reversal batch without deletion');
    $importedVoucherReversal = $db->query('SELECT * FROM finance_vouchers WHERE request_id = ' . $db->quote($importReversalRequest))->fetch(PDO::FETCH_ASSOC);
    $assert($importedVoucherReversal !== false && (int) $importedVoucherReversal['reversal_of'] === (int) $importedVoucher['id'], 'import reversal batch creates an opposite voucher record');

    $sameCashboxRejected = false;
    try {
        $service->postVoucher('cash_transfer', null, $cashboxA, $cashboxA, null, Money::fromDecimalString('1.00'), null, '2026-07-25', null, null, 1, 2, md5('voucher-same-cashbox'));
    } catch (InvalidArgumentException) {
        $sameCashboxRejected = true;
    }
    $assert($sameCashboxRejected, 'cash transfer rejects identical source and destination');

    $controlBypassRejected = false;
    try {
        $journals->postPureGlOperation('manual', 1, md5('voucher-control-bypass'), null, '2026-07-25', [
            ['account_id' => $accountIds['TEST-VOUCHER-CONTROL'], 'debit' => Money::fromDecimalString('10.00'), 'credit' => Money::zero()],
            ['account_id' => $accountIds['TEST-VOUCHER-CASH-A'], 'debit' => Money::zero(), 'credit' => Money::fromDecimalString('10.00')],
        ], 1);
    } catch (RuntimeException) {
        $controlBypassRejected = true;
    }
    $assert($controlBypassRejected, 'pure GL control-account bypass is rejected');

    $subledgerCountAfter = (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn();
    $assert($subledgerCountAfter === $subledgerCountBefore, 'pure GL vouchers create zero party subledger transactions');
    $voucherIds = implode(',', [$expenseId, $incomeId, $transferId, (int) $importedVoucher['id']]);
    $assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE source_type = "voucher" AND source_ref_id IN (' . $voucherIds . ') AND subledger_transaction_id IS NULL AND status = "posted"')->fetchColumn() === 4, 'each direct or imported voucher has one posted NULL-link GL journal');
    $assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE source_type = "voucher_reversal" AND source_ref_id IN (' . $expenseReversalId . ',' . (int) $importedVoucherReversal['id'] . ') AND reversal_of IS NOT NULL AND subledger_transaction_id IS NULL AND status = "posted"')->fetchColumn() === 2, 'each direct or imported voucher reversal has one opposite posted NULL-link GL journal');
    $expenseNetRows = (int) $db->query('SELECT COUNT(*) FROM (SELECT jl.account_id FROM accounting_journal_lines jl JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id WHERE je.source_idempotency_key IN (' . $db->quote($expenseRequest) . ',' . $db->quote($expenseReversalRequest) . ') GROUP BY jl.account_id HAVING SUM(jl.debit - jl.credit) <> 0) x')->fetchColumn();
    $assert($expenseNetRows === 0, 'original voucher plus reversal has zero net GL effect by account');
    $assert((int) $db->query('SELECT COUNT(*) FROM (SELECT je.id FROM accounting_journal_entries je JOIN accounting_journal_lines jl ON jl.journal_entry_id = je.id WHERE je.source_type = "voucher" AND je.source_ref_id IN (' . $voucherIds . ') GROUP BY je.id HAVING ROUND(SUM(jl.debit),2) <> ROUND(SUM(jl.credit),2)) x')->fetchColumn() === 0, 'all voucher journals are balanced');
    $assert($audit->events === 11, 'voucher and import staging/posting/reversal writes emit mandatory audit events');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Voucher/pure-GL/control-account integration test PASSED on {$testDb}.\n";
