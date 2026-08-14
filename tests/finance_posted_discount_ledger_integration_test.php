<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoChargeRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
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
    $db->beginTransaction();

    $studentId = 997026;
    $academicYearId = 997026;
    $maker = 101;
    $checker = 102;

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)'
    );
    $accountInsert->execute(['TEST-DISC-EXP', 'خصومات طلاب اختبارية', 'expense']);
    $discountExpenseId = (int) $db->lastInsertId();
    $accountInsert->execute(['TEST-DISC-AR', 'ذمم طلاب اختبارية', 'asset']);
    $studentReceivableId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO accounting_account_mapping_headers
            (version_number, effective_from, status, created_by)
         VALUES (?, ?, ?, ?)'
    )->execute([997026, '2026-01-01', 'active', $maker]);
    $mappingHeaderId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO accounting_account_mapping_lines
            (mapping_header_id, operation_type, debit_account_id, credit_account_id, specificity_score, priority)
         VALUES (?, ?, ?, ?, 0, 100)'
    )->execute([$mappingHeaderId, 'student_discount', $discountExpenseId, $studentReceivableId]);

    $subledgerAccounts = new PdoSubledgerAccountRepository($db);
    $subledger = $subledgerAccounts->findOrCreate('student', $studentId, (string) $academicYearId);
    $db->prepare(
        'INSERT INTO finance_student_accounts
            (student_id, academic_year_id, currency, status, subledger_account_id)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$studentId, $academicYearId, 'EGP', 'active', (int) $subledger['id']]);
    $studentAccountId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO finance_student_charges
            (student_account_id, charge_type_id, gross_amount, discount_amount, net_due,
             due_date, source, academic_year_id, status, request_id, posted_at, posted_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    )->execute([
        $studentAccountId,
        1,
        '500.00',
        '0.00',
        '500.00',
        '2026-10-15',
        'manual',
        $academicYearId,
        'posted',
        md5('posted-discount-charge'),
        $maker,
    ]);
    $chargeId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO finance_charge_installments
            (student_charge_id, installment_name, net_amount, due_date, display_order)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$chargeId, 'قسط خصم لاحق', '500.00', '2026-10-15', 1]);
    $installmentId = (int) $db->lastInsertId();

    $transactions = new PdoSubledgerTransactionRepository($db);
    $chargeTxId = $transactions->createTransaction(
        (int) $subledger['id'],
        'charge',
        $chargeId,
        md5('posted-discount-opening-charge'),
        null,
        null,
        $maker
    );
    $transactions->addLine(
        $chargeTxId,
        1,
        'STUDENT_OUTSTANDING_DUE',
        SignedMoneyDelta::fromDecimalString('500.00'),
        'Opening posted charge',
        $installmentId
    );
    $transactions->post($chargeTxId, $maker);
    $db->prepare(
        'UPDATE finance_student_charges SET subledger_transaction_id = ? WHERE id = ?'
    )->execute([$chargeTxId, $chargeId]);

    $audit = new class implements AuditEventWriter {
        public array $events = [];
        public function recordEvent(
            string $action,
            ?string $entityType,
            mixed $recordId,
            ?string $name,
            array $details = [],
            array $context = []
        ): void {
            $this->events[] = [$action, $entityType, $recordId, $details];
        }
    };
    $discounts = (new FinanceServiceFactory($db, $audit))->discountService();
    $ruleId = $discounts->createRuleVersion(
        'manual',
        $academicYearId,
        'tuition',
        'خصم لاحق اختباري',
        1,
        false,
        null,
        '2026-01-01',
        $maker
    );
    $discounts->activateRule($ruleId, $checker);
    $awardId = $discounts->createAward(
        $studentAccountId,
        $ruleId,
        Money::fromDecimalString('600.00'),
        'اعتماد خصم بعد ترحيل المصروف',
        $maker,
        null
    );
    $discounts->approveAward($awardId, $checker);

    $requestId = md5('posted-discount-application');
    $applicationId = $discounts->applyDiscount(
        $awardId,
        $chargeId,
        $installmentId,
        Money::fromDecimalString('120.00'),
        $requestId,
        '2026-07-26'
    );
    $retryId = $discounts->applyDiscount(
        $awardId,
        $chargeId,
        $installmentId,
        Money::fromDecimalString('120.00'),
        $requestId,
        '2026-07-26'
    );

    $charges = new PdoChargeRepository($db);
    $assert($applicationId === $retryId, 'posted discount retry is idempotent');
    $assert($transactions->bucketBalance((int) $subledger['id'], 'STUDENT_OUTSTANDING_DUE') === '380.00', 'posted discount reduces the unified student balance');
    $assert($charges->installmentRemainingDue($installmentId) === '380.00', 'posted discount reduces the targeted installment due');
    $application = $db->query(
        'SELECT * FROM finance_discount_applications WHERE id = ' . $applicationId
    )->fetch(PDO::FETCH_ASSOC);
    $assert((string) $application['applied_amount'] === '120.00', 'discount application preserves the approved amount');
    $assert((string) $application['ledger_effect_amount'] === '120.00', 'post-charge application records its ledger effect');
    $assert((int) $application['adjustment_id'] > 0 && (int) $application['subledger_transaction_id'] > 0, 'application links its immutable adjustment and sub-ledger transaction');
    $adjustment = $db->query(
        'SELECT * FROM finance_adjustments WHERE id = ' . (int) $application['adjustment_id']
    )->fetch(PDO::FETCH_ASSOC);
    $assert((string) $adjustment['signed_amount'] === '-120.00' && (string) $adjustment['source'] === 'credit_note', 'approved discount creates a posted credit-note adjustment');
    $journalNet = $db->query(
        'SELECT SUM(l.debit - l.credit)
         FROM accounting_journal_lines l
         JOIN accounting_journal_entries e ON e.id = l.journal_entry_id
         WHERE e.subledger_transaction_id = ' . (int) $application['subledger_transaction_id']
    )->fetchColumn();
    $assert((string) $journalNet === '0.00', 'posted discount creates a balanced GL journal');

    $overDiscountRejected = false;
    try {
        $discounts->applyDiscount(
            $awardId,
            $chargeId,
            $installmentId,
            Money::fromDecimalString('381.00'),
            md5('posted-discount-over-application'),
            '2026-07-26'
        );
    } catch (RuntimeException) {
        $overDiscountRejected = true;
    }
    $assert($overDiscountRejected, 'discount above the remaining installment due is rejected');

    $db->rollBack();
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Posted discount ledger integration test PASSED on {$testDb}.\n";
