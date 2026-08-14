<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $maker = 8101; $checker = 8102; $mappingVersion = 88101;
    $keys = [
        'post' => md5('approval-voucher-post'), 'reverse' => md5('approval-voucher-reverse'),
        'reject' => md5('approval-voucher-reject'), 'import' => md5('approval-import-post'),
        'import_reverse' => md5('approval-import-reverse'), 'batch' => md5('approval-import-batch'),
    ];

    $cleanup = static function () use ($db, $keys, $mappingVersion): void {
        $requestKeys = [$keys['post'], $keys['reverse'], $keys['reject'], $keys['import'], $keys['import_reverse']];
        $quotedKeys = implode(',', array_map([$db, 'quote'], $requestKeys));
        $db->exec('DELETE FROM finance_approval_requests WHERE request_key IN (' . $quotedKeys . ')');

        $originalBatchIds = $db->query('SELECT id FROM finance_import_batches WHERE batch_id = ' . $db->quote($keys['batch']))->fetchAll(PDO::FETCH_COLUMN);
        if ($originalBatchIds !== []) {
            $originalIds = implode(',', array_map('intval', $originalBatchIds));
            $reversalBatchIds = $db->query('SELECT id FROM finance_import_batches WHERE reversal_of IN (' . $originalIds . ')')->fetchAll(PDO::FETCH_COLUMN);
            $allBatchIds = array_merge($originalBatchIds, $reversalBatchIds);
            $allIds = implode(',', array_map('intval', $allBatchIds));
            $db->exec('DELETE FROM finance_import_rows WHERE import_batch_id IN (' . $allIds . ')');
            if ($reversalBatchIds !== []) { $db->exec('DELETE FROM finance_import_batches WHERE id IN (' . implode(',', array_map('intval', $reversalBatchIds)) . ')'); }
            $db->exec('DELETE FROM finance_import_batches WHERE id IN (' . $originalIds . ')');
        }

        $cashboxIds = $db->query("SELECT id FROM finance_cashboxes WHERE code = 'TEST-APPROVAL-CASH'")->fetchAll(PDO::FETCH_COLUMN);
        if ($cashboxIds !== []) {
            $cashIds = implode(',', array_map('intval', $cashboxIds));
            $voucherIds = $db->query('SELECT id FROM finance_vouchers WHERE cashbox_id IN (' . $cashIds . ')')->fetchAll(PDO::FETCH_COLUMN);
            if ($voucherIds !== []) {
                $voucherList = implode(',', array_map('intval', $voucherIds));
                $journalIds = $db->query("SELECT id FROM accounting_journal_entries WHERE source_ref_id IN ({$voucherList}) AND source_type IN ('voucher','voucher_reversal')")->fetchAll(PDO::FETCH_COLUMN);
                if ($journalIds !== []) {
                    $journalList = implode(',', array_map('intval', $journalIds));
                    $db->exec('DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (' . $journalList . ')');
                    $db->exec('DELETE FROM accounting_journal_entries WHERE reversal_of IS NOT NULL AND id IN (' . $journalList . ')');
                    $db->exec('DELETE FROM accounting_journal_entries WHERE id IN (' . $journalList . ')');
                }
                $db->exec('DELETE FROM finance_voucher_lines WHERE voucher_id IN (' . $voucherList . ')');
                $db->exec('DELETE FROM finance_vouchers WHERE reversal_of IS NOT NULL AND id IN (' . $voucherList . ')');
                $db->exec('DELETE FROM finance_vouchers WHERE id IN (' . $voucherList . ')');
            }
        }
        $db->prepare('DELETE FROM accounting_account_mapping_headers WHERE version_number = ?')->execute([$mappingVersion]);
        $db->prepare("DELETE FROM finance_cashboxes WHERE code = 'TEST-APPROVAL-CASH'")->execute();
    };
    $cleanup();

    $accountInsert = $db->prepare('INSERT INTO accounting_accounts (code, name_ar, type, is_control_account) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)');
    $accountInsert->execute(['TEST-APPROVAL-EXP', 'مصروف اعتماد اختباري', 'expense']); $expenseAccount = (int) $db->lastInsertId();
    $accountInsert->execute(['TEST-APPROVAL-CASH', 'خزينة اعتماد اختبارية', 'asset']); $cashAccount = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO finance_cashboxes (code, name, type, is_active, accountability_role, receipt_prefix) VALUES (?, ?, ?, 1, ?, ?)')->execute(['TEST-APPROVAL-CASH', 'خزينة اعتماد اختبارية', 'cash', 'admin', 'AC']);
    $cashboxId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO accounting_account_mapping_headers (version_number, effective_from, status, created_by) VALUES (?, ?, ?, ?)')->execute([$mappingVersion, '2026-01-01', 'active', $maker]);
    $mappingHeaderId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO accounting_account_mapping_lines (mapping_header_id, operation_type, selector_cashbox_id, selector_voucher_type, debit_account_id, credit_account_id, specificity_score, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$mappingHeaderId, 'voucher', $cashboxId, 'expense', $expenseAccount, $cashAccount, 2, 100]);

    $audit = new class implements AuditEventWriter {
        public int $events = 0;
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void { ++$this->events; }
    };
    $factory = new FinanceServiceFactory($db, $audit);
    $workflow = $factory->approvalWorkflowService();

    $postRequestId = $workflow->request('voucher_post', [
        'voucher_type' => 'expense', 'cashbox_id' => $cashboxId, 'amount' => '125.00',
        'entry_date' => '2026-07-26', 'description' => 'اختبار اعتماد سند',
    ], $maker, $keys['post']);
    $assert($workflow->request('voucher_post', ['voucher_type' => 'expense'], $maker, $keys['post']) === $postRequestId, 'approval request retry is idempotent');
    $sameActorRejected = false;
    try { $workflow->approve($postRequestId, $maker); } catch (Throwable) { $sameActorRejected = true; }
    $assert($sameActorRejected, 'maker cannot approve the same request');
    $postResult = $workflow->approve($postRequestId, $checker);
    $voucherId = (int) $postResult['result_ref_id'];
    $assert($postResult['result_ref_type'] === 'finance_voucher' && $voucherId > 0, 'checker approval executes voucher posting');
    $status = $db->query('SELECT status FROM finance_approval_requests WHERE id = ' . $postRequestId)->fetchColumn();
    $assert($status === 'approved', 'approved request is persisted');

    $reverseRequestId = $workflow->request('voucher_reverse', ['voucher_id' => $voucherId, 'entry_date' => '2026-07-26', 'reason' => 'اختبار عكس معتمد'], $maker, $keys['reverse']);
    $reverseResult = $workflow->approve($reverseRequestId, $checker);
    $reversalId = (int) $reverseResult['result_ref_id'];
    $reversalOf = $db->query('SELECT reversal_of FROM finance_vouchers WHERE id = ' . $reversalId)->fetchColumn();
    $assert((int) $reversalOf === $voucherId, 'approved reversal creates a linked opposite voucher');

    $rejectRequestId = $workflow->request('voucher_post', [
        'voucher_type' => 'expense', 'cashbox_id' => $cashboxId, 'amount' => '50.00', 'entry_date' => '2026-07-26',
    ], $maker, $keys['reject']);
    $workflow->reject($rejectRequestId, $checker, 'بيانات غير مكتملة');
    $rejected = $db->query('SELECT status, decision_reason FROM finance_approval_requests WHERE id = ' . $rejectRequestId)->fetch(PDO::FETCH_ASSOC);
    $assert((string) $rejected['status'] === 'rejected' && (string) $rejected['decision_reason'] === 'بيانات غير مكتملة', 'rejection persists checker reason without business write');

    $imports = $factory->importService();
    $batchId = $imports->createBatch($keys['batch'], '1.0', null, $maker, 'vouchers');
    $imports->stagePayload($batchId, 1, ['voucher_type' => 'expense', 'cashbox_id' => $cashboxId, 'amount' => '75.00', 'entry_date' => '2026-07-26', 'description' => 'سند مستورد معتمد']);
    $imports->updateCounts($batchId, 1, 0);
    $importRequestId = $workflow->request('import_post', ['batch_id' => $batchId], $maker, $keys['import']);
    $importResult = $workflow->approve($importRequestId, $checker);
    $assert($importResult['result_ref_type'] === 'finance_import_batch' && (int) $importResult['result_ref_id'] === $batchId, 'approved import posts the staged batch');
    $assert($db->query('SELECT status FROM finance_import_batches WHERE id = ' . $batchId)->fetchColumn() === 'posted', 'approved import state is posted');
    $importReverseRequestId = $workflow->request('import_reverse', ['batch_id' => $batchId], $maker, $keys['import_reverse']);
    $importReverseResult = $workflow->approve($importReverseRequestId, $checker);
    $assert((int) $importReverseResult['result_ref_id'] > 0 && $db->query('SELECT status FROM finance_import_batches WHERE id = ' . $batchId)->fetchColumn() === 'reversed', 'approved import reversal preserves and reverses the original batch');
    $assert($audit->events >= 18, 'approval and executed finance writes are audited');
    $cleanup();
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance approval workflow integration test PASSED on {$testDb}.\n";
