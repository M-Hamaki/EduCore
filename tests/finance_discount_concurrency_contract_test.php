<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Infrastructure\Pdo\PdoDiscountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}

$connect = static fn (): PDO => new PDO(
    'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
    (string) env('DB_USER', 'root'),
    (string) env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {
    $dbA = $connect();
    $dbB = $connect();
    $yearId = 991040;
    $dbA->prepare('DELETE FROM finance_discount_rules WHERE academic_year_id = ?')->execute([$yearId]);
    $insert = $dbA->prepare('INSERT INTO finance_discount_rules (code, name_ar, effective_from, status, academic_year_id, scope_charge_type_key, version_number) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['sibling', 'تزامن أ', '2026-09-01', 'draft', $yearId, 'ALL', 1]);
    $ruleA = (int) $dbA->lastInsertId();
    $insert->execute(['sibling', 'تزامن ب', '2026-09-01', 'draft', $yearId, 'ALL', 2]);
    $ruleB = (int) $dbA->lastInsertId();

    $dbA->beginTransaction();
    $lock = $dbA->prepare('SELECT id FROM finance_discount_rules WHERE code = ? AND academic_year_id = ? AND scope_charge_type_key = ? ORDER BY id FOR UPDATE');
    $lock->execute(['sibling', $yearId, 'ALL']);
    $lock->fetchAll(PDO::FETCH_COLUMN);

    $dbB->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $secondFailed = false;
    try {
        (new PdoFinanceTransactionManager($dbB))->transactional(
            static fn () => (new PdoDiscountRepository($dbB))->activateRule($ruleB, 2)
        );
    } catch (PDOException) {
        $secondFailed = true;
    }

    (new PdoDiscountRepository($dbA))->activateRule($ruleA, 1);
    $dbA->commit();
    $count = $dbA->prepare("SELECT COUNT(*) FROM finance_discount_rules WHERE academic_year_id = ? AND scope_charge_type_key = 'ALL' AND status = 'active'");
    $count->execute([$yearId]);
    if (!$secondFailed || (int) $count->fetchColumn() !== 1) {
        fwrite(STDERR, "FAILED: concurrent activations were not serialized to one active rule.\n");
        exit(1);
    }
} catch (Throwable $exception) {
    if (isset($dbA) && $dbA instanceof PDO && $dbA->inTransaction()) {
        $dbA->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $exception->getMessage() . "\n");
    exit(1);
}

echo "Discount concurrency contract test PASSED on {$testDb}.\n";
