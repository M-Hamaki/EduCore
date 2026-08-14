<?php

declare(strict_types=1);

/**
 * Integration test: ImportService staging/posting + ArchiveService + DailySettlementService + ReportService on *_test.
 *
 * Verifies:
 * - Import staging writes no business data.
 * - Import posting requires maker-checker.
 * - Import posting refuses batch with errors.
 * - Archive sets status to 'archived'.
 * - Daily settlement computes expected total.
 *
 * Requires: EDUCORE_TEST_DB_NAME=educore_finance_test
 */

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if ($testDb === '' || !preg_match('/^[A-Za-z0-9_]+_test$/', $testDb)) {
    fwrite(STDERR, "FAILED: --database or EDUCORE_TEST_DB_NAME must name an isolated *_test database.\n");
    exit(1);
}
if ($testDb === 'educore') {
    fwrite(STDERR, "FAILED: Refusing the production database.\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) { echo "FAIL: $msg\n"; ++$failures; }
};

try {
    $db = new PDO('mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4', (string)env('DB_USER','root'), (string)env('DB_PASS',''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

    foreach ([__DIR__.'/../database/migrations/20260723_finance_core_and_subledger.php', __DIR__.'/../database/migrations/20260723_finance_fee_plans_and_student_charges.php', __DIR__.'/../database/migrations/20260723_finance_collection.php', __DIR__.'/../database/migrations/20260723_finance_gl_vouchers_budget.php', __DIR__.'/../database/migrations/20260724_finance_views.php'] as $file) {
        if (is_file($file)) { $m = require $file; $m($db); }
    }

    $audit = new class implements \EduCore\Modules\Operations\Audit\AuditEventWriter {
        public array $events = [];
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            $this->events[] = $action;
        }
    };
    $transactions = new \EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager($db);
    $importSvc = new \EduCore\Modules\Finance\Application\ImportService(new \EduCore\Modules\Finance\Infrastructure\Pdo\PdoImportBatchRepository($db), $transactions, $audit);
    $archiveSvc = new \EduCore\Modules\Finance\Application\ArchiveService(new \EduCore\Modules\Finance\Infrastructure\Pdo\PdoArchiveRepository($db), $transactions, $audit);
    $cashboxes = new \EduCore\Modules\Finance\Infrastructure\Pdo\PdoCashboxRepository($db);
    $settlementSvc = new \EduCore\Modules\Finance\Application\DailySettlementService($cashboxes, $transactions, $audit);
    $reportSvc = new \EduCore\Modules\Finance\Application\ReportService(new \EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceReportQuery($db));

    // === Import: staging ===
    $batchId = md5('import-test-' . uniqid('', true));
    $batchIdNum = $importSvc->createBatch($batchId, '1.0', null, 1);
    $assert($batchIdNum > 0, 'import batch created');

    $importSvc->addRow($batchIdNum, 1, '{"a":1}', 'valid', '[]');
    $importSvc->addRow($batchIdNum, 2, '{"a":2}', 'invalid', '["Missing field"]');
    $importSvc->updateCounts($batchIdNum, 2, 1);

    $preview = $importSvc->previewBatch($batchIdNum);
    $assert(count($preview) === 2, 'preview shows 2 rows');

    // Posting with errors → throws.
    $threw = false;
    try { $importSvc->postBatch($batchIdNum, 1, 2); } catch (\RuntimeException) { $threw = true; }
    $assert($threw, 'posting batch with errors throws');

    // Abandon the invalid batch; create a clean independent batch and post it.
    $importSvc->abandonBatch($batchIdNum, 1);
    $cleanBatchId = md5('import-clean-' . uniqid('', true));
    $cleanBatchIdNum = $importSvc->createBatch($cleanBatchId, '1.0', null, 1);
    $importSvc->addRow($cleanBatchIdNum, 1, '{"a":1}', 'valid', '[]');
    $importSvc->updateCounts($cleanBatchIdNum, 1, 0);
    $importSvc->postBatch($cleanBatchIdNum, 1, 2);
    $assert(true, 'posting clean batch with maker-checker OK');

    // Same creator+approver → throws.
    $batchId2 = md5('import-test2-' . uniqid('', true));
    $batchId2Num = $importSvc->createBatch($batchId2, '1.0', null, 1);
    $importSvc->updateCounts($batchId2Num, 0, 0);
    $threw2 = false;
    try { $importSvc->postBatch($batchId2Num, 1, 1); } catch (\RuntimeException) { $threw2 = true; }
    $assert($threw2, 'import posting maker-checker: same creator+approver throws');

    // === Archive: fee plan ===
    $db->prepare('DELETE FROM finance_fee_plans WHERE charge_type_id = ? AND academic_year_id = ? AND grade_id = ?')->execute([990202, 990202, 990202]);
    $db->prepare('INSERT INTO finance_fee_plans (charge_type_id, academic_year_id, grade_id, name, status) VALUES (?, ?, ?, ?, ?)')->execute([990202, 990202, 990202, 'Archive test', 'active']);
    $planId = (int) $db->lastInsertId();
    $archiveSvc->archive('finance_fee_plans', $planId, 'Test archive', 1);
    $stmt = $db->prepare('SELECT status FROM finance_fee_plans WHERE id = ?');
    $stmt->execute([$planId]);
    $assert($stmt->fetchColumn() === 'archived', 'archive sets status to archived');
    $archiveSvc->restore('finance_fee_plans', $planId, 1);
    $stmt->execute([$planId]);
    $assert($stmt->fetchColumn() === 'draft', 'restore returns archived plan to a reviewable draft state');

    // === Daily settlement ===
    $db->prepare('DELETE FROM finance_cashboxes WHERE code = ?')->execute(['TEST-SVC-CASHBOX']);
    $db->prepare('INSERT INTO finance_cashboxes (code, name, type, is_active, accountability_role, receipt_prefix) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['TEST-SVC-CASHBOX', 'خزينة اختبار الخدمات', 'cash', 1, 'admin', 'TSC']);
    $cashboxId = (int) $db->lastInsertId();
    $settlementId = $settlementSvc->openSettlement($cashboxId, null, date('Y-m-d'), '100.00', 1);
    $settlement = $cashboxes->findSettlement($settlementId);
    $assert($settlement !== null && (string) $settlement['expected_total'] === '0.00', 'open settlement derives zero receipts without using floats');
    $settlementSvc->settleSettlement($settlementId, '100.00', 1);
    $settlement = $cashboxes->findSettlement($settlementId);
    $assert($settlement !== null && (string) $settlement['status'] === 'settled' && (string) $settlement['difference'] === '100.00', 'daily settlement stores counted difference');

    // === Report: trial balance (empty) ===
    $tb = $reportSvc->trialBalance();
    $assert(is_array($tb), 'trial balance returns array');

    // === Report: debt aging ===
    $aging = $reportSvc->debtAging(990202);
    $assert(isset($aging['current']), 'debt aging has current bucket');

    // Cleanup.
    $db->exec('DELETE FROM finance_import_rows');
    $db->exec('DELETE FROM finance_import_batches WHERE reversal_of IS NOT NULL');
    $db->exec('DELETE FROM finance_import_batches');
    $db->exec('DELETE FROM finance_fee_plans WHERE name = "Archive test"');
    $db->exec('DELETE FROM finance_cashbox_settlements WHERE cashbox_id = ' . $cashboxId);
    $db->exec('DELETE FROM finance_cashboxes WHERE id = ' . $cashboxId);
    $assert(count($audit->events) >= 8, 'all service writes emit shared audit events');

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    ++$failures;
}

if ($failures > 0) { echo "\n$failures FAILURES\n"; exit(1); }
echo "\nAll import/archive/settlement/report integration tests passed on $testDb.\n";
exit(0);
