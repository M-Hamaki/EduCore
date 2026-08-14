<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
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
    $db->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY, name VARCHAR(200), role VARCHAR(40), status VARCHAR(30),
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS staff_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNIQUE,
            current_work_status VARCHAR(30),
            full_name_ar VARCHAR(200),
            employee_code VARCHAR(80),
            job_title VARCHAR(160)
        ) ENGINE=InnoDB"
    );
    $db->beginTransaction();

    $staffId = 998027;
    $actorId = 601;
    $db->prepare(
        "INSERT INTO users (id, name, role, status, deleted_at)
         VALUES (?, ?, 'teacher', 'active', NULL)
         ON DUPLICATE KEY UPDATE name = VALUES(name), role = 'teacher', status = 'active', deleted_at = NULL"
    )->execute([$staffId, 'عامل توافق اختباري']);
    $db->prepare(
        'INSERT INTO staff_profiles
            (user_id, current_work_status, full_name_ar, employee_code, job_title)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            current_work_status = VALUES(current_work_status),
            full_name_ar = VALUES(full_name_ar),
            employee_code = VALUES(employee_code),
            job_title = VALUES(job_title)'
    )->execute([$staffId, 'on_duty', 'عامل توافق اختباري', 'STAFF-COMP-1', 'معلم']);

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
            $this->events[] = $action;
        }
    };
    $service = (new FinanceServiceFactory($db, $audit))->legacyStaffFinanceCompatibilityService();
    $baseInput = [
        'staff_id' => $staffId,
        'basic_salary' => '1000.00',
        'allowance_transport' => '100.00',
        'allowance_housing' => '50.00',
        'other_allowances_data' => json_encode([['name' => 'بدل اختبار', 'amount' => '25.00']], JSON_UNESCAPED_UNICODE),
        'deduction_insurance' => '50.00',
        'deduction_tax' => '25.00',
        'other_deductions_data' => json_encode([['name' => 'خصم اختبار', 'amount' => '10.00']], JSON_UNESCAPED_UNICODE),
        'net_salary' => '1090.00',
        'advances_data' => '[]',
        'financial_notes' => 'ملاحظة محفوظة في سجل التوافق',
    ];
    $firstContractId = $service->save($baseInput, $actorId);
    $result = $service->staffFinancial($staffId);
    $data = $result['data'];
    $assert($firstContractId > 0 && $result['success'] === true, 'legacy staff save creates a compensation contract');
    $assert((string) $data['basic_salary'] === '1000.00', 'legacy basic salary reads from contract components');
    $assert((string) $data['allowance_transport'] === '100.00' && (string) $data['allowance_housing'] === '50.00', 'legacy named allowances retain their fields');
    $assert((string) $data['net_salary'] === '1090.00', 'legacy net salary is derived from immutable component rows');
    $assert((string) $data['financial_notes'] === 'ملاحظة محفوظة في سجل التوافق', 'non-accounting legacy notes remain available through compatibility metadata');

    $secondInput = $baseInput;
    $secondInput['basic_salary'] = '1100.00';
    $secondInput['net_salary'] = '1190.00';
    $secondContractId = $service->save($secondInput, $actorId);
    $versions = $db->query(
        'SELECT version_number, status
         FROM staff_compensation_contracts
         WHERE staff_id = ' . $staffId . '
         ORDER BY version_number'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert($secondContractId !== $firstContractId && count($versions) === 2, 'same-day salary changes create a new contract version');
    $assert((int) $versions[0]['version_number'] === 1 && (int) $versions[1]['version_number'] === 2, 'contract versions are deterministic and preserve the first snapshot');
    $assert((string) $service->staffFinancial($staffId)['data']['basic_salary'] === '1100.00', 'compatibility read selects the latest mapped contract version');

    $advanceRejected = false;
    try {
        $withAdvance = $baseInput;
        $withAdvance['advances_data'] = json_encode([[
            'name' => 'سلفة غير مهيأة',
            'amount' => '500.00',
            'paid' => '100.00',
        ]], JSON_UNESCAPED_UNICODE);
        $service->save($withAdvance, $actorId);
    } catch (RuntimeException) {
        $advanceRejected = true;
    }
    $assert($advanceRejected, 'legacy salary form cannot hide an advance outside the staff advance ledger');

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

echo "Finance legacy staff compatibility integration test PASSED on {$testDb}.\n";
