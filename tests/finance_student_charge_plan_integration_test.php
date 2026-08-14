<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\FeePlanService;
use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\StudentChargeService;
use EduCore\Modules\Finance\Application\SubledgerPostingService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoChargeRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFeePlanRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStudentContractRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStudentFinanceAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Students\Contracts\StudentEnrollmentQuery;
use EduCore\Modules\Transport\Contracts\BusSubscriptionQuery;

$options = getopt('', ['database:']);
$databaseName = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName) || $databaseName === 'educore') {
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
        'mysql:host=localhost;dbname=' . $databaseName . ';charset=utf8mb4',
        (string) env('DB_USER', env('DB_USERNAME', 'root')),
        (string) env('DB_PASS', env('DB_PASSWORD_LOCAL', '')),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $studentId = 990101;
    $academicYearId = 990101;
    $gradeId = 990101;
    $chargeTypeId = 990101;
    $mappingVersion = 990101;

    $studentAccountIds = $db->query(
        'SELECT id FROM finance_student_accounts WHERE student_id = ' . $studentId
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($studentAccountIds !== []) {
        $ids = implode(',', array_map('intval', $studentAccountIds));
        $db->exec('DELETE FROM finance_charge_installments WHERE student_charge_id IN (SELECT id FROM finance_student_charges WHERE student_account_id IN (' . $ids . '))');
        $db->exec('DELETE FROM finance_student_charges WHERE student_account_id IN (' . $ids . ')');
        $db->exec('DELETE FROM finance_student_contracts WHERE student_account_id IN (' . $ids . ')');
        $db->exec('DELETE FROM finance_student_accounts WHERE id IN (' . $ids . ')');
    }
    $subledgerIds = $db->query(
        "SELECT id FROM finance_subledger_accounts WHERE party_type = 'student' AND party_id = {$studentId}"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($subledgerIds !== []) {
        $ids = implode(',', array_map('intval', $subledgerIds));
        $transactionIds = $db->query(
            'SELECT id FROM finance_subledger_transactions WHERE subledger_account_id IN (' . $ids . ')'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($transactionIds !== []) {
            $transactionList = implode(',', array_map('intval', $transactionIds));
            $entryIds = $db->query(
                'SELECT id FROM accounting_journal_entries WHERE subledger_transaction_id IN (' . $transactionList . ')'
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($entryIds !== []) {
                $entryList = implode(',', array_map('intval', $entryIds));
                $db->exec('DELETE FROM accounting_journal_lines WHERE journal_entry_id IN (' . $entryList . ')');
                $db->exec('DELETE FROM accounting_journal_entries WHERE id IN (' . $entryList . ')');
            }
            $db->exec('DELETE FROM finance_subledger_lines WHERE transaction_id IN (' . $transactionList . ')');
            $db->exec('DELETE FROM finance_subledger_transactions WHERE id IN (' . $transactionList . ')');
        }
        $db->exec('DELETE FROM finance_subledger_accounts WHERE id IN (' . $ids . ')');
    }
    $db->prepare('DELETE FROM accounting_account_mapping_headers WHERE version_number = ?')->execute([$mappingVersion]);
    $db->prepare('DELETE FROM finance_fee_plans WHERE charge_type_id = ? AND academic_year_id = ? AND grade_id = ?')
        ->execute([$chargeTypeId, $academicYearId, $gradeId]);

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)'
    );
    $accountInsert->execute(['TEST-STUDENT-REC-990101', 'ذمم طلاب اختبار الخطة', 'asset']);
    $receivableAccountId = (int) $db->lastInsertId();
    $accountInsert->execute(['TEST-STUDENT-REV-990101', 'إيراد رسوم اختبار الخطة', 'revenue']);
    $revenueAccountId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO accounting_account_mapping_headers (version_number, effective_from, status, created_by)
         VALUES (?, ?, ?, ?)'
    )->execute([$mappingVersion, '2026-01-01', 'active', 1]);
    $mappingHeaderId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO accounting_account_mapping_lines
            (mapping_header_id, operation_type, selector_charge_type_id, debit_account_id, credit_account_id, specificity_score, priority)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$mappingHeaderId, 'student_charge', $chargeTypeId, $receivableAccountId, $revenueAccountId, 1000, 1000]);

    $audit = new class implements AuditEventWriter {
        public array $events = [];

        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            $this->events[] = compact('action', 'entityType', 'recordId', 'details');
        }
    };
    $enrollments = new class($studentId, $academicYearId, $gradeId) implements StudentEnrollmentQuery {
        public function __construct(private int $studentId, private int $academicYearId, private int $gradeId)
        {
        }

        public function enrollmentOf(int $studentId, int $academicYearId): ?array
        {
            if ($studentId !== $this->studentId || $academicYearId !== $this->academicYearId) {
                return null;
            }

            return ['student_id' => $studentId, 'grade_id' => $this->gradeId, 'enrollment_status' => 'active'];
        }

        public function familyGroupOf(int $studentId, int $academicYearId): array
        {
            return [['student_id' => $studentId, 'enrollment_date' => '2026-01-01']];
        }
    };
    $busSubscriptions = new class implements BusSubscriptionQuery {
        public bool $active = false;

        public function subscriptionOf(int $studentId, int $academicYearId): ?array
        {
            return $this->active ? ['bus_id' => 1, 'academic_year_id' => $academicYearId] : null;
        }
    };

    $transactionManager = new PdoFinanceTransactionManager($db);
    $feePlanRepository = new PdoFeePlanRepository($db);
    $feePlans = new FeePlanService($feePlanRepository, $transactionManager, $audit);
    $planId = $feePlans->createPlan($chargeTypeId, $academicYearId, $gradeId, 'خطة اختبار الرسوم', 1);
    $versionId = $feePlans->createVersion($planId, '2026-09-01', [
        ['name' => 'القسط الأول', 'gross_amount' => Money::fromDecimalString('600.00'), 'due_date' => '2026-09-15'],
        ['name' => 'القسط الثاني', 'gross_amount' => Money::fromDecimalString('400.00'), 'due_date' => '2027-01-15'],
    ], 1);
    $feePlans->activateVersion($versionId, 1);

    $subledgerAccounts = new PdoSubledgerAccountRepository($db);
    $subledgerLines = new PdoSubledgerLineRepository($db);
    $journals = new JournalEntryService(
        new PdoJournalEntryRepository($db),
        new PdoAccountMappingLineRepository($db),
        new AccountMappingPolicy(),
        new ControlAccountService(new PdoControlAccountRepository($db), $subledgerLines)
    );
    $posting = new SubledgerPostingService(
        $transactionManager,
        $subledgerAccounts,
        new PdoSubledgerTransactionRepository($db),
        $subledgerLines,
        $journals,
        $audit
    );
    $charges = new StudentChargeService(
        new PdoChargeRepository($db),
        $posting,
        $journals,
        $transactionManager,
        new PdoStudentFinanceAccountRepository($db),
        new PdoStudentContractRepository($db),
        $subledgerAccounts,
        $feePlanRepository,
        $enrollments,
        $busSubscriptions,
        $audit
    );

    $busRejected = false;
    try {
        $charges->createChargeFromActivePlan($studentId, $academicYearId, $chargeTypeId, 1, true, md5('bus-rejected'));
    } catch (RuntimeException) {
        $busRejected = true;
    }
    $assert($busRejected, 'bus charge is rejected without an active subscription');

    $busSubscriptions->active = true;
    $requestId = md5('student-plan-charge-990101');
    $chargeId = $charges->createChargeFromActivePlan($studentId, $academicYearId, $chargeTypeId, 1, true, $requestId);
    $retryId = $charges->createChargeFromActivePlan($studentId, $academicYearId, $chargeTypeId, 1, true, $requestId);
    $assert($chargeId > 0 && $retryId === $chargeId, 'plan charge retry is idempotent');

    $charge = $db->query('SELECT * FROM finance_student_charges WHERE id = ' . $chargeId)->fetch(PDO::FETCH_ASSOC);
    $assert($charge !== false && $charge['status'] === 'posted', 'student charge is posted');
    $assert((string) $charge['gross_amount'] === '1000.00' && (string) $charge['net_due'] === '1000.00', 'charge amount equals the active plan total');
    $assert((int) $charge['student_contract_id'] > 0, 'charge references an immutable student contract snapshot');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_student_charges WHERE request_id = ' . $db->quote($requestId))->fetchColumn() === 1, 'idempotent retry creates no duplicate charge');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_charge_installments WHERE student_charge_id = ' . $chargeId)->fetchColumn() === 2, 'plan installments are copied to the charge');
    $assert((string) $db->query('SELECT COALESCE(SUM(net_amount), 0.00) FROM finance_charge_installments WHERE student_charge_id = ' . $chargeId)->fetchColumn() === '1000.00', 'charge installment total equals net due');
    $assert((int) $db->query('SELECT COUNT(*) FROM finance_student_accounts WHERE student_id = ' . $studentId . ' AND academic_year_id = ' . $academicYearId)->fetchColumn() === 1, 'student finance account is stable per academic year');
    $assert((int) $db->query("SELECT COUNT(*) FROM finance_subledger_accounts WHERE party_type = 'student' AND party_id = {$studentId} AND scope_key = '{$academicYearId}'")->fetchColumn() === 1, 'student subledger account is stable per academic year');
    $assert((int) $db->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE subledger_transaction_id = ' . (int) $charge['subledger_transaction_id'] . " AND status = 'posted'")->fetchColumn() === 1, 'charge has exactly one posted GL journal');
    $assert($feePlans->isVersionUsed($versionId), 'plan version is marked used after contract creation');

    $immutable = false;
    try {
        $feePlans->assertVersionEditable($versionId);
    } catch (RuntimeException) {
        $immutable = true;
    }
    $assert($immutable, 'used fee-plan version is immutable');
    $assert(count($audit->events) >= 5, 'plan, contract, and posting writes emit audit events');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Student active-plan charge integration test PASSED on {$databaseName}.\n";
