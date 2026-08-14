<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\DiscountService;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\DiscountCombinationPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoChargeRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoDiscountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStudentFinanceAccountRepository;
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

    $yearId = 991043;
    $db->prepare('INSERT INTO finance_student_accounts (student_id, academic_year_id, currency, status) VALUES (?, ?, ?, ?)')
        ->execute([991043, $yearId, 'EGP', 'active']);
    $studentAccountId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO finance_student_charges (student_account_id, charge_type_id, gross_amount, discount_amount, net_due, due_date, source, academic_year_id, status, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$studentAccountId, 1, '1000.00', '200.00', '800.00', '2026-10-15', 'manual', $yearId, 'posted', md5('discount-charge')]);
    $chargeId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO finance_charge_installments (student_charge_id, installment_name, net_amount, due_date) VALUES (?, ?, ?, ?)')
        ->execute([$chargeId, 'قسط اختبار الخصم', '800.00', '2026-10-15']);
    $installmentId = (int) $db->lastInsertId();

    $audit = new class implements AuditEventWriter {
        public array $events = [];
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            $this->events[] = $action;
        }
    };
    $discounts = new PdoDiscountRepository($db);
    $service = new DiscountService(
        $discounts,
        $discounts,
        $discounts,
        new PdoChargeRepository($db),
        new PdoStudentFinanceAccountRepository($db),
        new DiscountCombinationPolicy(),
        new PdoFinanceTransactionManager($db),
        $audit
    );

    $ruleId = $service->createRuleVersion('manual', $yearId, 'ALL', 'خصم يدوي', 1, false, null, '2026-09-01', 10);
    $service->activateRule($ruleId, 11);

    $sameActorRejected = false;
    try {
        $service->createAward($studentAccountId, $ruleId, Money::fromDecimalString('200.00'), 'طلب يدوي', 10, 10);
    } catch (RuntimeException) {
        $sameActorRejected = true;
    }
    $assert($sameActorRejected, 'manual award cannot be created and approved by the same actor');

    $awardId = $service->createAward($studentAccountId, $ruleId, Money::fromDecimalString('200.00'), 'طلب يدوي', 10, null);
    $sameApproverRejected = false;
    try {
        $service->approveAward($awardId, 10);
    } catch (RuntimeException) {
        $sameApproverRejected = true;
    }
    $assert($sameApproverRejected, 'manual award approval enforces maker-checker');
    $service->approveAward($awardId, 11);

    $firstApplicationId = $service->applyDiscount($awardId, $chargeId, $installmentId, Money::fromDecimalString('150.00'));
    $overApplicationRejected = false;
    try {
        $service->applyDiscount($awardId, $chargeId, $installmentId, Money::fromDecimalString('60.00'));
    } catch (RuntimeException) {
        $overApplicationRejected = true;
    }
    $assert($overApplicationRejected, 'concurrent-safe award/charge totals reject over-application');
    $secondApplicationId = $service->applyDiscount($awardId, $chargeId, $installmentId, Money::fromDecimalString('50.00'));

    $assert($firstApplicationId > 0 && $secondApplicationId > 0, 'approved discount links to the exact charge installment');
    $sum = $db->query('SELECT SUM(applied_amount) FROM finance_discount_applications WHERE discount_award_id = ' . $awardId)->fetchColumn();
    $assert((string) $sum === '200.00', 'application total equals the charge discount amount exactly');
    $assert($audit->events === [
        'finance_discount_rule_create',
        'finance_discount_rule_activate',
        'finance_discount_award_create',
        'finance_discount_award_approve',
        'finance_discount_apply',
        'finance_discount_apply',
    ], 'every successful discount write is audited once inside the transaction');

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

echo "Discount service integration test PASSED on {$testDb}.\n";
