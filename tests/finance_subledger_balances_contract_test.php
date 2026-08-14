<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;

$options = getopt('', ['database:']);
$databaseName = (string) ($options['database'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName)) {
    fwrite(STDERR, "FAILED: --database must identify an isolated *_test database.\n");
    exit(1);
}

$db = new PDO('mysql:host=localhost;dbname=' . $databaseName . ';charset=utf8mb4', (string) env('DB_USER', env('DB_USERNAME', 'root')), (string) env('DB_PASS', env('DB_PASSWORD_LOCAL', '')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$accounts = new PdoSubledgerAccountRepository($db);
$transactions = new PdoSubledgerTransactionRepository($db);
$staffId = 990002;

$db->prepare('DELETE sl FROM finance_subledger_lines sl JOIN finance_subledger_transactions st ON st.id = sl.transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = ? AND sa.party_id = ?')->execute(['staff', $staffId]);
$db->prepare('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = ? AND sa.party_id = ? AND st.reversal_of IS NOT NULL')->execute(['staff', $staffId]);
$db->prepare('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = ? AND sa.party_id = ?')->execute(['staff', $staffId]);
$db->prepare('DELETE FROM finance_subledger_accounts WHERE party_type = ? AND party_id = ?')->execute(['staff', $staffId]);

$staffAccount = $accounts->findOrCreate('staff', $staffId, 'STAFF_GLOBAL');
$sameAccount = $accounts->findOrCreate('staff', $staffId, 'STAFF_GLOBAL');
if ((int) $staffAccount['id'] !== (int) $sameAccount['id']) {
    throw new RuntimeException('STAFF_GLOBAL account is not stable.');
}
try {
    $accounts->findOrCreate('staff', $staffId, '2026');
    throw new RuntimeException('Non-global staff scope was accepted.');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() === 'Non-global staff scope was accepted.') {
        throw $expected;
    }
}

$originalId = $transactions->createTransaction((int) $staffAccount['id'], 'advance_issue', 1, md5('balance-original-' . uniqid('', true)), null, null, 1);
$transactions->addLine($originalId, 1, 'STAFF_ADVANCE_RECEIVABLE', SignedMoneyDelta::fromDecimalString('250.00'));
$transactions->post($originalId, 1);
$reversalId = $transactions->createReversal($originalId, md5('balance-reversal-' . uniqid('', true)), 2);
$transactions->addLine($reversalId, 1, 'STAFF_ADVANCE_RECEIVABLE', SignedMoneyDelta::fromDecimalString('-250.00'));
$transactions->post($reversalId, 2);

if (!$transactions->isReversed($originalId) || $transactions->bucketBalance((int) $staffAccount['id'], 'STAFF_ADVANCE_RECEIVABLE') !== '0.00') {
    throw new RuntimeException('Derived reversal or original+reversal balance invariant failed.');
}

$legacyTables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('finance_student_ledger','finance_student_ledger_entries','student_finance_ledger')")->fetchAll(PDO::FETCH_COLUMN);
if ($legacyTables !== []) {
    throw new RuntimeException('Parallel student-specific ledger schema exists.');
}

$viewStmt = $db->query("SHOW CREATE VIEW v_staff_subledger_balances");
$view = $viewStmt->fetch(PDO::FETCH_NUM);
if (!$view || !str_contains((string) ($view[1] ?? ''), 'STAFF_GLOBAL')) {
    throw new RuntimeException('Staff balance view does not enforce STAFF_GLOBAL.');
}

$db->prepare('DELETE sl FROM finance_subledger_lines sl JOIN finance_subledger_transactions st ON st.id = sl.transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = ? AND sa.party_id = ?')->execute(['staff', $staffId]);
$db->prepare('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = ? AND sa.party_id = ? AND st.reversal_of IS NOT NULL')->execute(['staff', $staffId]);
$db->prepare('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = ? AND sa.party_id = ?')->execute(['staff', $staffId]);
$db->prepare('DELETE FROM finance_subledger_accounts WHERE party_type = ? AND party_id = ?')->execute(['staff', $staffId]);

echo "Finance student/staff sub-ledger balance contract PASSED on {$databaseName}.\n";
