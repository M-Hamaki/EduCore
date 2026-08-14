<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\SubledgerPostingService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') {
    fwrite(STDERR, "FAILED: rollback drill requires an isolated *_test database.\n");
    exit(1);
}
$db = new PDO(
    'mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4',
    (string) env('DB_USER', env('DB_USERNAME', 'root')),
    (string) env('DB_PASS', env('DB_PASSWORD_LOCAL', '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$partyId = 990093;
$cleanup = static function (PDO $db) use ($partyId): void {
    $db->exec('DELETE jl FROM accounting_journal_lines jl JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = \'student\' AND sa.party_id = ' . $partyId);
    $db->exec('DELETE je FROM accounting_journal_entries je JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = \'student\' AND sa.party_id = ' . $partyId . ' AND je.reversal_of IS NOT NULL');
    $db->exec('DELETE je FROM accounting_journal_entries je JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = \'student\' AND sa.party_id = ' . $partyId);
    $db->exec('DELETE sl FROM finance_subledger_lines sl JOIN finance_subledger_transactions st ON st.id = sl.transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = \'student\' AND sa.party_id = ' . $partyId);
    $db->exec('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = \'student\' AND sa.party_id = ' . $partyId . ' AND st.reversal_of IS NOT NULL');
    $db->exec('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_type = \'student\' AND sa.party_id = ' . $partyId);
    $db->exec('DELETE FROM finance_subledger_accounts WHERE party_type = \'student\' AND party_id = ' . $partyId);
};

$cleanup($db);
$accountInsert = $db->prepare('INSERT INTO accounting_accounts (code, name_ar, type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)');
$accountInsert->execute(['ROLLBACK-AR', 'ذمم تجربة التراجع', 'asset']);
$receivableId = (int) $db->lastInsertId();
$accountInsert->execute(['ROLLBACK-REV', 'إيراد تجربة التراجع', 'revenue']);
$revenueId = (int) $db->lastInsertId();

$audit = new class implements AuditEventWriter {
    public int $events = 0;
    public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void { ++$this->events; }
};
$accounts = new PdoSubledgerAccountRepository($db);
$lines = new PdoSubledgerLineRepository($db);
$posting = new SubledgerPostingService(
    new PdoFinanceTransactionManager($db),
    $accounts,
    new PdoSubledgerTransactionRepository($db),
    $lines,
    new JournalEntryService(new PdoJournalEntryRepository($db), new PdoAccountMappingLineRepository($db), new AccountMappingPolicy(), new ControlAccountService(new PdoControlAccountRepository($db), $lines)),
    $audit
);
$amount = Money::fromDecimalString('125.00');
$zero = Money::zero();

try {
    $originalId = $posting->postPartyOperation(
        'student', $partyId, 'ROLLBACK_YEAR', 'opening_balance', 990093,
        md5('rollback-drill-original'),
        [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('125.00')]],
        'student_charge', date('Y-m-d'),
        [['account_id' => $receivableId, 'debit' => $amount, 'credit' => $zero], ['account_id' => $revenueId, 'debit' => $zero, 'credit' => $amount]],
        1
    );
    $reversalId = $posting->postReversal(
        $originalId, md5('rollback-drill-reversal'), date('Y-m-d'),
        [['account_id' => $revenueId, 'debit' => $amount, 'credit' => $zero], ['account_id' => $receivableId, 'debit' => $zero, 'credit' => $amount]],
        2
    );
    $account = $accounts->findOrCreate('student', $partyId, 'ROLLBACK_YEAR');
    if ($posting->bucketBalance((int) $account['id'], 'STUDENT_OUTSTANDING_DUE') !== '0.00') {
        throw new RuntimeException('Sub-ledger balance did not return to zero.');
    }
    $netGl = $db->query('SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM accounting_journal_lines jl JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id WHERE je.subledger_transaction_id IN (' . $originalId . ',' . $reversalId . ')')->fetchColumn();
    if ((string) $netGl !== '0.00') { throw new RuntimeException('GL did not return to zero.'); }
    if ($audit->events !== 2) { throw new RuntimeException('Original and reversal audit events were not both recorded.'); }
    $link = (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions WHERE id = ' . $reversalId . ' AND reversal_of = ' . $originalId . ' AND status = \'posted\'')->fetchColumn();
    if ($link !== 1) { throw new RuntimeException('Reversal is not linked to the immutable original.'); }
} finally {
    $cleanup($db);
}

$remaining = (int) $db->query("SELECT COUNT(*) FROM finance_subledger_accounts WHERE party_type = 'student' AND party_id = {$partyId}")->fetchColumn();
if ($remaining !== 0) { fwrite(STDERR, "FAILED: rollback drill fixtures were not removed.\n"); exit(1); }

echo "Finance rollback drill PASSED on {$database}: sub-ledger and GL returned to zero through a linked reversal.\n";
