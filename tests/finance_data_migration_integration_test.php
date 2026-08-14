<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

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
$run = static function (array $arguments): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../tools/finance_data_migration.php'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start finance migration CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Isolated legacy/audit fixtures; never created in a request path or production database.
    $db->exec("CREATE TABLE IF NOT EXISTS academic_years (id INT PRIMARY KEY, name VARCHAR(30) UNIQUE, is_active TINYINT DEFAULT 0, locked TINYINT DEFAULT 0, status VARCHAR(30) DEFAULT 'active') ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS student_fees (id INT PRIMARY KEY, student_id INT NOT NULL, academic_year VARCHAR(30) NOT NULL, final_amount DECIMAL(10,2) NOT NULL, balance DECIMAL(10,2) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS fee_payments (id INT PRIMARY KEY, student_fee_id INT NOT NULL, student_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL, payment_date DATE NOT NULL, payment_method VARCHAR(30) NOT NULL, receipt_number VARCHAR(50) NULL, notes TEXT NULL, received_by INT NULL) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS student_fee_balances_history (id INT PRIMARY KEY, student_id INT NOT NULL, academic_year_id INT NOT NULL, total_due DECIMAL(10,2) NOT NULL DEFAULT 0, total_paid DECIMAL(10,2) NOT NULL DEFAULT 0, balance DECIMAL(10,2) NOT NULL, carried_forward TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, user_name VARCHAR(255) NULL, user_role VARCHAR(50) NULL,
        action VARCHAR(100) NOT NULL, target_type VARCHAR(100) NULL, target_id VARCHAR(100) NULL, target_name VARCHAR(255) NULL,
        details LONGTEXT NULL, ip_address VARCHAR(64) NULL, request_id VARCHAR(64) NULL, batch_id VARCHAR(64) NULL,
        result VARCHAR(30) NULL, route VARCHAR(255) NULL, user_agent VARCHAR(500) NULL, undo_log_id BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $yearId = 991113;
    $studentId = 991113;
    $priorStudentId = 991114;
    $studentIds = [$studentId, $priorStudentId, 991115];
    $studentList = implode(',', $studentIds);
    $studentAccountIds = $db->query("SELECT id FROM finance_student_accounts WHERE student_id IN ({$studentList})")->fetchAll(PDO::FETCH_COLUMN);
    if ($studentAccountIds !== []) {
        $studentAccountList = implode(',', array_map('intval', $studentAccountIds));
        $receiptIds = $db->query("SELECT id FROM finance_receipts WHERE student_account_id IN ({$studentAccountList})")->fetchAll(PDO::FETCH_COLUMN);
        if ($receiptIds !== []) {
            $receiptList = implode(',', array_map('intval', $receiptIds));
            $db->exec("DELETE FROM finance_payment_allocations WHERE receipt_id IN ({$receiptList})");
            $db->exec("DELETE FROM finance_unapplied_credits WHERE receipt_id IN ({$receiptList})");
            $db->exec("DELETE FROM finance_receipts WHERE id IN ({$receiptList})");
        }
        $chargeIds = $db->query("SELECT id FROM finance_student_charges WHERE student_account_id IN ({$studentAccountList})")->fetchAll(PDO::FETCH_COLUMN);
        if ($chargeIds !== []) {
            $chargeList = implode(',', array_map('intval', $chargeIds));
            $db->exec("DELETE FROM finance_charge_installments WHERE student_charge_id IN ({$chargeList})");
            $db->exec("DELETE FROM finance_student_charges WHERE id IN ({$chargeList})");
        }
        $db->exec("DELETE FROM finance_student_accounts WHERE id IN ({$studentAccountList})");
    }
    $subledgerIds = $db->query("SELECT id FROM finance_subledger_accounts WHERE party_type = 'student' AND party_id IN ({$studentList})")->fetchAll(PDO::FETCH_COLUMN);
    if ($subledgerIds !== []) {
        $subledgerList = implode(',', array_map('intval', $subledgerIds));
        $transactionIds = $db->query("SELECT id FROM finance_subledger_transactions WHERE subledger_account_id IN ({$subledgerList})")->fetchAll(PDO::FETCH_COLUMN);
        if ($transactionIds !== []) {
            $transactionList = implode(',', array_map('intval', $transactionIds));
            $journalIds = $db->query("SELECT id FROM accounting_journal_entries WHERE subledger_transaction_id IN ({$transactionList})")->fetchAll(PDO::FETCH_COLUMN);
            if ($journalIds !== []) {
                $journalList = implode(',', array_map('intval', $journalIds));
                $db->exec("DELETE FROM accounting_journal_lines WHERE journal_entry_id IN ({$journalList})");
                $db->exec("DELETE FROM accounting_journal_entries WHERE id IN ({$journalList})");
            }
            $db->exec("DELETE FROM finance_subledger_lines WHERE transaction_id IN ({$transactionList})");
            $db->exec("DELETE FROM finance_subledger_transactions WHERE id IN ({$transactionList})");
        }
        $db->exec("DELETE FROM finance_subledger_accounts WHERE id IN ({$subledgerList})");
    }
    $db->prepare('DELETE FROM fee_payments WHERE id IN (?, ?)')->execute([991113, 991114]);
    $db->prepare('DELETE FROM student_fees WHERE id IN (?, ?)')->execute([991113, 991114]);
    $db->prepare('DELETE FROM student_fee_balances_history WHERE id = ?')->execute([991113]);
    $db->prepare('DELETE FROM academic_years WHERE id = ?')->execute([$yearId]);
    $db->prepare('INSERT INTO academic_years (id, name, is_active, locked, status) VALUES (?, ?, 1, 0, ?)')->execute([$yearId, '2091-2092', 'active']);
    $db->prepare('INSERT INTO student_fees (id, student_id, academic_year, final_amount, balance, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([991113, $studentId, '2091-2092', '1000.00', '400.00', '2091-09-01 08:00:00']);
    $db->prepare('INSERT INTO fee_payments (id, student_fee_id, student_id, amount, payment_date, payment_method, receipt_number, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([991113, 991113, $studentId, '600.00', '2091-10-01', 'cash', 'LEG-991113', 7]);
    $db->prepare('INSERT INTO student_fee_balances_history (id, student_id, academic_year_id, total_due, total_paid, balance, carried_forward, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
        ->execute([991113, $priorStudentId, $yearId, '200.00', '0.00', '200.00', '2091-08-31 12:00:00']);

    $upsertAccount = $db->prepare('INSERT INTO accounting_accounts (code, name_ar, type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)');
    $accountIds = [];
    foreach ([
        ['MIG-AR', 'ذمم طلاب ترحيل', 'asset'],
        ['MIG-REV', 'إيراد ترحيل', 'revenue'],
        ['MIG-CASH', 'نقدية ترحيل', 'asset'],
    ] as [$code, $name, $type]) {
        $upsertAccount->execute([$code, $name, $type]);
        $accountIds[$code] = (int) $db->lastInsertId();
    }
    $db->prepare("INSERT INTO finance_charge_types (code, name_ar, is_system) VALUES ('legacy_migration_test', 'ترحيل اختبار', 1) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")->execute();
    $chargeTypeId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO finance_cashboxes (code, name, type, is_active, accountability_role, receipt_prefix) VALUES ('MIGRATION-TEST', 'خزينة ترحيل اختبار', 'cash', 1, 'admin', 'MIG') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_active = 1")->execute();
    $cashboxId = (int) $db->lastInsertId();

    $mappingVersion = 991113;
    $db->prepare('DELETE FROM accounting_account_mapping_headers WHERE version_number = ?')->execute([$mappingVersion]);
    $db->prepare('INSERT INTO accounting_account_mapping_headers (version_number, effective_from, status, created_by) VALUES (?, ?, ?, ?)')->execute([$mappingVersion, '2091-01-01', 'active', 1]);
    $headerId = (int) $db->lastInsertId();
    $mapping = $db->prepare('INSERT INTO accounting_account_mapping_lines (mapping_header_id, operation_type, selector_charge_type_id, selector_payment_method, selector_cashbox_id, debit_account_id, credit_account_id, specificity_score, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $mapping->execute([$headerId, 'student_charge', $chargeTypeId, null, null, $accountIds['MIG-AR'], $accountIds['MIG-REV'], 1, 100]);
    $mapping->execute([$headerId, 'receipt', null, 'cash', $cashboxId, $accountIds['MIG-CASH'], $accountIds['MIG-AR'], 2, 100]);

    $common = [
        '--database=' . $testDb,
        '--charge-type-id=' . $chargeTypeId,
        '--cashbox-id=' . $cashboxId,
        '--actor-id=1',
        '--json',
    ];
    $first = $run($common);
    $assert($first['exit'] === 0, 'migration CLI succeeds: ' . trim($first['stderr']));
    $firstReport = json_decode(trim($first['stdout']), true, 512, JSON_THROW_ON_ERROR);
    $assert($firstReport['charges'] === 1 && $firstReport['receipts'] === 1 && $firstReport['prior_year_debts'] === 1, 'charge, receipt, and prior-year debt are migrated');
    $assert($firstReport['reconciled'] === 1 && $firstReport['mismatches'] === [], 'legacy balance reconciles exactly without float tolerance');

    $view = $db->prepare('SELECT outstanding_due, unapplied_credit, net_account_position FROM v_student_subledger_balances WHERE student_id = ? AND academic_year_id = ?');
    $view->execute([$studentId, $yearId]);
    $balance = $view->fetch(PDO::FETCH_ASSOC);
    $assert(($balance['net_account_position'] ?? null) === '400.00', '1000.00 charge - 600.00 receipt = 400.00');
    $assert((int) $db->query("SELECT COUNT(*) FROM finance_student_charges WHERE source = 'prior_year' AND academic_year_id = {$yearId}")->fetchColumn() === 1, 'prior-year debt is a separate opening charge in the original year');
    $assert((string) $db->query('SELECT balance FROM student_fee_balances_history WHERE id = 991113')->fetchColumn() === '200.00', 'legacy prior-year row remains unchanged');

    $unbalanced = (int) $db->query("SELECT COUNT(*) FROM (SELECT je.id FROM accounting_journal_entries je JOIN accounting_journal_lines jl ON jl.journal_entry_id = je.id WHERE je.source_idempotency_key IN ('" . md5('legacy-student-fee:991113') . "','" . md5('legacy-fee-payment:991113') . "','" . md5('legacy-prior-year-balance:991113') . "') GROUP BY je.id HAVING SUM(jl.debit) <> SUM(jl.credit)) x")->fetchColumn();
    $assert($unbalanced === 0, 'every migrated operation has one balanced GL journal');

    $countsBeforeRetry = [
        (int) $db->query('SELECT COUNT(*) FROM finance_student_charges')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM finance_receipts')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn(),
    ];
    $second = $run($common);
    $countsAfterRetry = [
        (int) $db->query('SELECT COUNT(*) FROM finance_student_charges')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM finance_receipts')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn(),
    ];
    $assert($second['exit'] === 0 && $countsAfterRetry === $countsBeforeRetry, 'migration retry is idempotent across domain, sub-ledger, and GL');

    $mismatchStudentId = 991115;
    $db->prepare('INSERT INTO student_fees (id, student_id, academic_year, final_amount, balance, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([991114, $mismatchStudentId, '2091-2092', '100.00', '99.00', '2091-09-02 08:00:00']);
    $financeCountsBeforeMismatch = [
        (int) $db->query('SELECT COUNT(*) FROM finance_student_charges')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn(),
    ];
    $beforeMismatch = (int) $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    $mismatch = $run($common);
    $afterMismatch = (int) $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    $financeCountsAfterMismatch = [
        (int) $db->query('SELECT COUNT(*) FROM finance_student_charges')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn(),
    ];
    $assert($mismatch['exit'] === 2, 'reconciliation mismatch is a distinct failure');
    $assert($beforeMismatch === $afterMismatch && $financeCountsAfterMismatch === $financeCountsBeforeMismatch, 'mismatched migration rolls back charge, sub-ledger, GL, and audit atomically');
    $missingAccount = $db->prepare('SELECT COUNT(*) FROM finance_student_accounts WHERE student_id = ?');
    $missingAccount->execute([$mismatchStudentId]);
    $assert((int) $missingAccount->fetchColumn() === 0, 'rollback leaves no partially migrated student account');
    $db->prepare('DELETE FROM student_fees WHERE id = ?')->execute([991114]);

    $productionGuard = $run(['--database=educore', '--charge-type-id=1', '--cashbox-id=1', '--actor-id=1']);
    $assert($productionGuard['exit'] === 1, 'CLI refuses the production database name before connecting');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Finance data migration integration test PASSED on {$testDb}.\n";
