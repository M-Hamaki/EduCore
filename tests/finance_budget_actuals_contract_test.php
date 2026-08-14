<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\BudgetService;
use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\ReportService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoBudgetRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceReportQuery;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') {
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
    $db = new PDO('mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4', (string) env('DB_USER', 'root'), (string) env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $yearId = 77077;
    $db->exec("DELETE FROM finance_budget_lines WHERE budget_version_id IN (SELECT id FROM finance_budget_versions WHERE budget_id IN (SELECT id FROM finance_budgets WHERE academic_year_id = {$yearId}))");
    $db->exec("DELETE FROM finance_budget_versions WHERE budget_id IN (SELECT id FROM finance_budgets WHERE academic_year_id = {$yearId})");
    $db->exec("DELETE FROM finance_budgets WHERE academic_year_id = {$yearId}");
    $entryIds = $db->query("SELECT id FROM accounting_journal_entries WHERE source_idempotency_key IN ('" . md5('budget-actual-posted-77077') . "','" . md5('budget-actual-draft-77077') . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($entryIds !== []) {
        $entryList = implode(',', array_map('intval', $entryIds));
        $db->exec("DELETE FROM accounting_journal_lines WHERE journal_entry_id IN ({$entryList})");
        $db->exec("DELETE FROM accounting_journal_entries WHERE id IN ({$entryList})");
    }
    $db->exec("DELETE FROM finance_periods WHERE academic_year_id = {$yearId}");

    $accountInsert = $db->prepare('INSERT INTO accounting_accounts (code, name_ar, type, is_control_account) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $accountInsert->execute(['TEST-BUDGET-EXP-77077', 'مصروف ميزانية اختبار', 'expense']);
    $expenseAccountId = (int) $db->lastInsertId();
    $accountInsert->execute(['TEST-BUDGET-CASH-77077', 'نقدية ميزانية اختبار', 'asset']);
    $cashAccountId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO finance_periods (academic_year_id, name, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')->execute([$yearId, 'BUDGET-77077', '2026-07-01', '2026-07-31', 'open']);
    $periodId = (int) $db->lastInsertId();

    $audit = new class implements AuditEventWriter {
        public int $events = 0;
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void { ++$this->events; }
    };
    $transactions = new PdoFinanceTransactionManager($db);
    $budgetRepository = new PdoBudgetRepository($db);
    $budgetService = new BudgetService($budgetRepository, $transactions, $audit);
    $journalRepository = new PdoJournalEntryRepository($db);
    $journalService = new JournalEntryService($journalRepository, new PdoAccountMappingLineRepository($db), new AccountMappingPolicy(), new ControlAccountService(new PdoControlAccountRepository($db), new PdoSubledgerLineRepository($db)));
    $reportService = new ReportService(new PdoFinanceReportQuery($db));

    $glBefore = (int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn();
    $subledgerBefore = (int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn();
    $budgetId = $budgetService->createBudget($yearId, 'ميزانية اختبار 77077', 1);
    $versionId = $budgetService->createVersion($budgetId, 1);
    $budgetService->addLine($versionId, $expenseAccountId, null, $periodId, Money::fromDecimalString('1000.00'));
    $budgetService->reviewBudget($budgetId, 3);
    $budgetService->approveBudget($budgetId, 2);
    $budgetService->lockBudget($budgetId, 2);
    $assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn() === $glBefore, 'budget planning writes create no GL entries');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_subledger_transactions')->fetchColumn() === $subledgerBefore, 'budget planning writes create no party sub-ledger transactions');

    $zero = Money::zero();
    $journalService->postPureGlOperation('budget_actual_test', 77077, md5('budget-actual-posted-77077'), $periodId, '2026-07-25', [
        ['account_id' => $expenseAccountId, 'debit' => Money::fromDecimalString('250.00'), 'credit' => $zero],
        ['account_id' => $cashAccountId, 'debit' => $zero, 'credit' => Money::fromDecimalString('250.00')],
    ], 1);
    $draftId = $journalRepository->create('JE-BUDGET-DRAFT-77077', $periodId, '2026-07-25', 'budget_actual_test', 77078, md5('budget-actual-draft-77077'), null, 1);
    $journalRepository->addLine($draftId, $expenseAccountId, null, '900.00', '0.00', null, null, null);
    $journalRepository->addLine($draftId, $cashAccountId, null, '0.00', '900.00', null, null, null);

    $assert($budgetService->actualForLine($expenseAccountId, null, $periodId) === '250.00', 'budget actual uses posted GL only and ignores draft journals');
    $profitAndLoss = $reportService->profitAndLoss($periodId);
    $assert(count($profitAndLoss) === 1 && (string) $profitAndLoss[0]['type'] === 'expense' && (string) $profitAndLoss[0]['amount'] === '250.00', 'P&L report is derived from posted revenue and expense GL lines');
    $cashFlow = $reportService->cashFlow($periodId);
    $cashRows = array_values(array_filter($cashFlow, static fn (array $row): bool => (int) $row['account_id'] === $cashAccountId));
    $assert(count($cashRows) === 1 && (string) $cashRows[0]['net_cash_change'] === '-250.00', 'cash-flow report exposes posted asset movement from GL');
    $trialBalance = $reportService->trialBalance($periodId);
    $trialRows = array_values(array_filter($trialBalance, static fn (array $row): bool => in_array((int) $row['account_id'], [$expenseAccountId, $cashAccountId], true)));
    $assert(count($trialRows) === 2, 'trial balance includes both sides of the posted journal');
    $report = $reportService->budgetVsActual($versionId);
    $assert(count($report) === 1 && (string) $report[0]['actual_amount'] === '250.00' && (string) $report[0]['variance'] === '750.00', 'budget-vs-actual report is derived from posted GL');

    $version2 = $budgetService->reviseBudget($budgetId, 1);
    $budgetService->addLine($version2, $expenseAccountId, null, $periodId, Money::fromDecimalString('1200.00'));
    $budgetService->reviewBudget($budgetId, 3);
    $budgetService->approveBudget($budgetId, 2);
    $budgetService->lockBudget($budgetId, 2);
    $statuses = $db->query('SELECT status FROM finance_budget_versions WHERE budget_id = ' . $budgetId . ' ORDER BY version_number')->fetchAll(PDO::FETCH_COLUMN);
    $assert($statuses === ['superseded', 'active'], 'revised budget supersedes the old version and activates the new version');
    $assert((string) $db->query('SELECT status FROM finance_budgets WHERE id = ' . $budgetId)->fetchColumn() === 'locked', 'budget completes draft-review-approve-lock and revision lifecycle');
    $assert($audit->events >= 11, 'every budget lifecycle write uses the shared audit writer');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}
echo "Finance budget actuals contract test PASSED on {$database}.\n";
