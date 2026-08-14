<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\SubledgerPostingService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$databaseName = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName) || $databaseName === 'educore') {
    fwrite(STDERR, "FAILED: EDUCORE_TEST_DB_NAME must name an isolated *_test database.\n");
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
        'mysql:host=localhost;dbname=' . $databaseName . ';charset=utf8mb4',
        (string) env('DB_USER', env('DB_USERNAME', 'root')),
        (string) env('DB_PASS', env('DB_PASSWORD_LOCAL', '')),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $partyId = 990001;
    $db->exec('DELETE jl FROM accounting_journal_lines jl JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_id = ' . $partyId);
    $db->exec('DELETE je FROM accounting_journal_entries je JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_id = ' . $partyId . ' AND je.reversal_of IS NOT NULL');
    $db->exec('DELETE je FROM accounting_journal_entries je JOIN finance_subledger_transactions st ON st.id = je.subledger_transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_id = ' . $partyId);
    $db->exec('DELETE sl FROM finance_subledger_lines sl JOIN finance_subledger_transactions st ON st.id = sl.transaction_id JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_id = ' . $partyId);
    $db->exec('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_id = ' . $partyId . ' AND st.reversal_of IS NOT NULL');
    $db->exec('DELETE st FROM finance_subledger_transactions st JOIN finance_subledger_accounts sa ON sa.id = st.subledger_account_id WHERE sa.party_id = ' . $partyId);
    $db->exec('DELETE FROM finance_subledger_accounts WHERE party_id = ' . $partyId);

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)'
    );
    $accountInsert->execute(['TEST-AR', 'ذمم طلاب اختبار', 'asset']);
    $receivableAccountId = (int) $db->lastInsertId();
    $accountInsert->execute(['TEST-CASH', 'نقدية اختبار', 'asset']);
    $cashAccountId = (int) $db->lastInsertId();
    $accountInsert->execute(['TEST-REV', 'إيراد اختبار', 'revenue']);
    $revenueAccountId = (int) $db->lastInsertId();

    $audit = new class implements AuditEventWriter {
        public int $events = 0;
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            ++$this->events;
        }
    };

    $accounts = new PdoSubledgerAccountRepository($db);
    $transactions = new PdoSubledgerTransactionRepository($db);
    $lines = new PdoSubledgerLineRepository($db);
    $journals = new JournalEntryService(
        new PdoJournalEntryRepository($db),
        new PdoAccountMappingLineRepository($db),
        new AccountMappingPolicy(),
        new ControlAccountService(new PdoControlAccountRepository($db), $lines)
    );
    $posting = new SubledgerPostingService(
        new PdoFinanceTransactionManager($db),
        $accounts,
        $transactions,
        $lines,
        $journals,
        $audit
    );

    $amount = Money::fromDecimalString('1000.00');
    $zero = Money::zero();
    $chargeKey = md5('atomic-charge-' . uniqid('', true));
    $chargeId = $posting->postPartyOperation(
        'student', $partyId, '2026', 'charge', 101, $chargeKey,
        [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('1000.00')]],
        'student_charge', '2026-07-25',
        [
            ['account_id' => $receivableAccountId, 'debit' => $amount, 'credit' => $zero],
            ['account_id' => $revenueAccountId, 'debit' => $zero, 'credit' => $amount],
        ],
        1
    );
    $assert($chargeId > 0, 'charge transaction created');

    $linked = $db->prepare('SELECT id, status FROM accounting_journal_entries WHERE subledger_transaction_id = ? AND source_idempotency_key = ?');
    $linked->execute([$chargeId, $chargeKey]);
    $chargeJournal = $linked->fetch(PDO::FETCH_ASSOC);
    $assert($chargeJournal !== false && $chargeJournal['status'] === 'posted', 'charge has one linked posted journal');

    $retryId = $posting->postPartyOperation(
        'student', $partyId, '2026', 'charge', 101, $chargeKey,
        [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('1000.00')]],
        'student_charge', '2026-07-25',
        [
            ['account_id' => $receivableAccountId, 'debit' => $amount, 'credit' => $zero],
            ['account_id' => $revenueAccountId, 'debit' => $zero, 'credit' => $amount],
        ],
        1
    );
    $assert($retryId === $chargeId, 'idempotent retry returns original transaction');

    $receiptAmount = Money::fromDecimalString('600.00');
    $receiptKey = md5('atomic-receipt-' . uniqid('', true));
    $receiptId = $posting->postPartyOperation(
        'student', $partyId, '2026', 'receipt', 102, $receiptKey,
        [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromDecimalString('-600.00')]],
        'receipt', '2026-07-25',
        [
            ['account_id' => $cashAccountId, 'debit' => $receiptAmount, 'credit' => $zero],
            ['account_id' => $receivableAccountId, 'debit' => $zero, 'credit' => $receiptAmount],
        ],
        1
    );

    $reversalKey = md5('atomic-reversal-' . uniqid('', true));
    $reversalId = $posting->postReversal(
        $receiptId,
        $reversalKey,
        '2026-07-25',
        [
            ['account_id' => $receivableAccountId, 'debit' => $receiptAmount, 'credit' => $zero],
            ['account_id' => $cashAccountId, 'debit' => $zero, 'credit' => $receiptAmount],
        ],
        2
    );
    $assert($reversalId > 0, 'reversal transaction created');

    $account = $accounts->findOrCreate('student', $partyId, '2026');
    $assert($posting->bucketBalance((int) $account['id'], 'STUDENT_OUTSTANDING_DUE') === '1000.00', 'original plus reversal restores exact balance');
    $assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE subledger_transaction_id IN (' . $chargeId . ',' . $receiptId . ',' . $reversalId . ')')->fetchColumn() === 3, 'each party transaction has exactly one GL journal');
    $assert($audit->events === 3, 'mandatory audit emitted once per new atomic operation');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Finance atomic sub-ledger/GL integration test PASSED on {$databaseName}.\n";
