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

$connect = static function () use ($testDb): PDO {
    return new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
};

$makeService = static function (PDO $db, AuditEventWriter $audit): DiscountService {
    $repository = new PdoDiscountRepository($db);
    return new DiscountService(
        $repository,
        $repository,
        $repository,
        new PdoChargeRepository($db),
        new PdoStudentFinanceAccountRepository($db),
        new DiscountCombinationPolicy(),
        new PdoFinanceTransactionManager($db),
        $audit
    );
};

try {
    $db = $connect();
    $yearId = 990040;
    $db->prepare('DELETE FROM finance_discount_rules WHERE academic_year_id = ?')->execute([$yearId]);

    $audit = new class implements AuditEventWriter {
        public int $events = 0;
        public function recordEvent(string $action, ?string $entityType, mixed $recordId, ?string $name, array $details = [], array $context = []): void
        {
            ++$this->events;
        }
    };
    $service = $makeService($db, $audit);
    $repository = new PdoDiscountRepository($db);

    $generalV1 = $service->createRuleVersion('sibling', $yearId, 'ALL', 'إخوة عام', 10, false, null, '2026-09-01', 1);
    $generalV2 = $service->createRuleVersion('sibling', $yearId, 'ALL', 'إخوة عام نسخة 2', 20, false, null, '2027-01-01', 1);
    $tuitionV1 = $service->createRuleVersion('sibling', $yearId, 'TUITION', 'إخوة مصروفات', 30, true, Money::fromDecimalString('500.00'), '2026-09-01', 1);

    $assert((int) $repository->findRuleById($generalV1)['version_number'] === 1, 'general scope starts at version 1');
    $assert((int) $repository->findRuleById($generalV2)['version_number'] === 2, 'general scope increments independently');
    $assert((int) $repository->findRuleById($tuitionV1)['version_number'] === 1, 'charge-type scope has its own version sequence');

    $service->activateRule($generalV1, 2);
    $secondActivationRejected = false;
    try {
        $service->activateRule($generalV2, 3);
    } catch (RuntimeException) {
        $secondActivationRejected = true;
    }
    $assert($secondActivationRejected, 'a second active version in the same scope is rejected');

    $service->activateRule($tuitionV1, 2);
    $assert((int) $repository->findApplicableRule('sibling', $yearId, 'TUITION', '2026-10-01')['id'] === $tuitionV1, 'charge-type-specific active rule overrides ALL');
    $assert((int) $repository->findApplicableRule('sibling', $yearId, 'BUS', '2026-10-01')['id'] === $generalV1, 'ALL rule is the fallback for another charge type');
    $assert($repository->findApplicableRule('sibling', $yearId, 'BUS', '2026-08-01') === null, 'effective date is enforced');

    $originalSnapshot = $repository->findRuleById($generalV1);
    $service->createRuleVersion('sibling', $yearId, 'ALL', 'إخوة عام نسخة 3', 40, false, null, '2027-09-01', 1);
    $assert($repository->findRuleById($generalV1) === $originalSnapshot, 'creating a new version never mutates the used/active version');
    $assert(!method_exists($repository, 'updateRule'), 'repository exposes no retroactive rule-update operation');

    $concurrentA = $service->createRuleVersion('employee_child', $yearId, 'ACTIVITY', 'عاملين أ', 10, false, null, '2026-09-01', 1);
    $concurrentB = $service->createRuleVersion('employee_child', $yearId, 'ACTIVITY', 'عاملين ب', 20, false, null, '2026-09-01', 1);
    $dbB = $connect();
    $dbB->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $serviceB = $makeService($dbB, $audit);

    $db->beginTransaction();
    $lock = $db->prepare('SELECT id FROM finance_discount_rules WHERE code = ? AND academic_year_id = ? AND scope_charge_type_key = ? ORDER BY id FOR UPDATE');
    $lock->execute(['employee_child', $yearId, 'ACTIVITY']);
    $lock->fetchAll(PDO::FETCH_COLUMN);
    $concurrentSecondFailed = false;
    try {
        $serviceB->activateRule($concurrentB, 3);
    } catch (PDOException) {
        $concurrentSecondFailed = true;
    }
    $service->activateRule($concurrentA, 2);
    $db->commit();
    $assert($concurrentSecondFailed, 'overlapping activation cannot acquire the scope lock');
    $activeCount = $db->prepare("SELECT COUNT(*) FROM finance_discount_rules WHERE code = 'employee_child' AND academic_year_id = ? AND scope_charge_type_key = 'ACTIVITY' AND status = 'active'");
    $activeCount->execute([$yearId]);
    $assert((int) $activeCount->fetchColumn() === 1, 'exactly one rule remains active after concurrent activation attempt');
    $assert($audit->events === 9, 'new rule versions and successful activations are audited exactly once');
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

echo "Discount version/scope/concurrency contract test PASSED on {$testDb}.\n";
