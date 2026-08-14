<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Domain\Money;
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
    foreach ([
        "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY, name VARCHAR(200), role VARCHAR(40), status VARCHAR(30),
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS stages (
            id INT PRIMARY KEY, stage_name VARCHAR(200)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS grades (
            id INT PRIMARY KEY, grade_name VARCHAR(200), stage_id INT
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS classes (
            id INT PRIMARY KEY, name VARCHAR(200), grade_id INT
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS student_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT UNIQUE, student_code VARCHAR(80)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS student_enrollments (
            id INT AUTO_INCREMENT PRIMARY KEY, student_id INT, academic_year_id INT,
            class_id INT, grade_id INT, stage_id INT, enrollment_date DATE,
            enrollment_status VARCHAR(30),
            UNIQUE KEY uq_test_student_year (student_id, academic_year_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS student_siblings (
            id INT AUTO_INCREMENT PRIMARY KEY, student_id INT, sibling_id INT, confirmed TINYINT DEFAULT 1
        ) ENGINE=InnoDB",
    ] as $ddl) {
        $db->exec($ddl);
    }
    $db->beginTransaction();

    $yearId = 998026;
    $studentId = 998026;
    $stageId = 998026;
    $gradeId = 998026;
    $classId = 998026;
    $maker = 501;
    $checker = 502;
    $db->prepare(
        "INSERT INTO academic_years (id, name, is_active, locked, status)
         VALUES (?, ?, 1, 0, 'active')
         ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, locked = 0, status = 'active'"
    )->execute([$yearId, '2098-2099']);
    $db->prepare(
        'INSERT INTO stages (id, stage_name) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE stage_name = VALUES(stage_name)'
    )->execute([$stageId, 'مرحلة اختبار']);
    $db->prepare(
        'INSERT INTO grades (id, grade_name, stage_id) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE grade_name = VALUES(grade_name), stage_id = VALUES(stage_id)'
    )->execute([$gradeId, 'صف اختبار', $stageId]);
    $db->prepare(
        'INSERT INTO classes (id, name, grade_id) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), grade_id = VALUES(grade_id)'
    )->execute([$classId, 'فصل اختبار', $gradeId]);
    $db->prepare(
        "INSERT INTO users (id, name, role, status, deleted_at)
         VALUES (?, ?, 'student', 'active', NULL)
         ON DUPLICATE KEY UPDATE name = VALUES(name), role = 'student', status = 'active', deleted_at = NULL"
    )->execute([$studentId, 'طالب توافق اختباري']);
    $db->prepare(
        'INSERT INTO student_profiles (user_id, student_code) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE student_code = VALUES(student_code)'
    )->execute([$studentId, 'COMP-998026']);
    $db->prepare(
        "INSERT INTO student_enrollments
            (student_id, academic_year_id, class_id, grade_id, stage_id, enrollment_date, enrollment_status)
         VALUES (?, ?, ?, ?, ?, ?, 'enrolled')
         ON DUPLICATE KEY UPDATE
            class_id = VALUES(class_id), grade_id = VALUES(grade_id), stage_id = VALUES(stage_id),
            enrollment_date = VALUES(enrollment_date), enrollment_status = 'enrolled'"
    )->execute([$studentId, $yearId, $classId, $gradeId, $stageId, '2098-09-01']);

    $chargeTypeStmt = $db->prepare(
        "SELECT id FROM finance_charge_types WHERE code = 'tuition' LIMIT 1"
    );
    $chargeTypeStmt->execute();
    $chargeTypeId = (int) $chargeTypeStmt->fetchColumn();
    if ($chargeTypeId <= 0) {
        $db->prepare(
            "INSERT INTO finance_charge_types (code, name_ar, category, is_active)
             VALUES ('tuition', 'مصروفات دراسية', 'tuition', 1)"
        )->execute();
        $chargeTypeId = (int) $db->lastInsertId();
    }

    $accountInsert = $db->prepare(
        'INSERT INTO accounting_accounts (code, name_ar, type)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), type = VALUES(type)'
    );
    $accounts = [];
    foreach ([
        ['COMP-AR', 'ذمم توافق اختبارية', 'asset'],
        ['COMP-REV', 'إيراد توافق اختباري', 'revenue'],
        ['COMP-CASH', 'نقدية توافق اختبارية', 'asset'],
        ['COMP-DISC', 'خصومات توافق اختبارية', 'expense'],
    ] as [$code, $name, $type]) {
        $accountInsert->execute([$code, $name, $type]);
        $accounts[$code] = (int) $db->lastInsertId();
    }
    $db->exec('UPDATE finance_cashboxes SET is_active = 0');
    $db->prepare(
        "INSERT INTO finance_cashboxes
            (code, name, type, is_active, accountability_role, receipt_prefix)
         VALUES ('COMP-CASHBOX', 'خزينة توافق اختبارية', 'cash', 1, 'admin', 'CMP')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_active = 1"
    )->execute();
    $cashboxId = (int) $db->lastInsertId();

    $mappingVersion = 998026;
    $db->prepare(
        'DELETE FROM accounting_account_mapping_headers WHERE version_number = ?'
    )->execute([$mappingVersion]);
    $db->prepare(
        'INSERT INTO accounting_account_mapping_headers
            (version_number, effective_from, status, created_by)
         VALUES (?, ?, ?, ?)'
    )->execute([$mappingVersion, '2098-01-01', 'active', $maker]);
    $mappingHeaderId = (int) $db->lastInsertId();
    $mappingInsert = $db->prepare(
        'INSERT INTO accounting_account_mapping_lines
            (mapping_header_id, operation_type, selector_charge_type_id,
             selector_payment_method, selector_cashbox_id, debit_account_id,
             credit_account_id, specificity_score, priority)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mappingInsert->execute([
        $mappingHeaderId,
        'student_charge',
        $chargeTypeId,
        null,
        null,
        $accounts['COMP-AR'],
        $accounts['COMP-REV'],
        1,
        100,
    ]);
    $mappingInsert->execute([
        $mappingHeaderId,
        'receipt',
        null,
        'cash',
        $cashboxId,
        $accounts['COMP-CASH'],
        $accounts['COMP-AR'],
        2,
        100,
    ]);
    $mappingInsert->execute([
        $mappingHeaderId,
        'student_discount',
        null,
        null,
        null,
        $accounts['COMP-DISC'],
        $accounts['COMP-AR'],
        0,
        100,
    ]);

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
    $factory = new FinanceServiceFactory($db, $audit);
    $planId = $factory->feePlanService()->createPlan(
        $chargeTypeId,
        $yearId,
        $gradeId,
        'خطة توافق اختبارية',
        $maker
    );
    $versionId = $factory->feePlanService()->createVersion(
        $planId,
        '2098-09-01',
        [[
            'name' => 'قسط واحد',
            'gross_amount' => Money::fromDecimalString('1000.00'),
            'due_date' => '2098-10-01',
            'display_order' => 1,
        ]],
        $maker
    );
    $factory->feePlanService()->activateVersion($versionId, $checker);

    $compatibility = $factory->legacyCollectionCompatibilityService();
    $generation = $compatibility->generateFees([
        'year' => '2098-2099',
        'student_id' => $studentId,
    ], $maker);
    $assert($generation['generated'] === 1, 'legacy generation delegates to active-plan charge creation');
    $beforePayment = $compatibility->studentFee($studentId, '2098-2099');
    $assert((string) $beforePayment['fee']['balance'] === '1000.00', 'legacy student JSON reads the unified ledger opening balance');

    $payment = $compatibility->recordPayment([
        'student_id' => $studentId,
        'year' => '2098-2099',
        'amount' => '200.00',
        'payment_date' => '2098-10-02',
        'payment_method' => 'cash',
        'receipt_number' => 'LEG-COMP-1',
        'notes' => 'دفعة توافق',
    ], $maker);
    $assert((string) $payment['total_paid'] === '200.00' && (string) $payment['balance'] === '800.00', 'legacy receipt POST returns unchanged total/balance fields from Finance');

    $ruleId = $factory->legacyFeeDefinitionService()->saveOtherDiscount([
        'od_id' => 998026,
        'academic_year' => '2098-2099',
        'od_name' => 'خصم توافق',
        'discount_type' => 'amount',
        'discount_value' => '100.00',
    ], $maker);
    $assert($ruleId > 0, 'legacy discount definition becomes a versioned Finance rule');
    $discountApprovalId = $compatibility->requestDiscount(
        $studentId,
        998026,
        '2098-2099',
        $maker
    );
    $factory->approvalWorkflowService()->approve($discountApprovalId, $checker);
    $afterDiscount = $compatibility->studentFee($studentId, '2098-2099');
    $assert((string) $afterDiscount['fee']['final_amount'] === '900.00', 'approved post-charge discount reduces the legacy final amount');
    $assert((string) $afterDiscount['fee']['balance'] === '700.00', 'approved post-charge discount reduces the unified balance');
    $assert((string) $afterDiscount['installments'][0]['remaining_due'] === '700.00', 'approved discount reduces the legacy installment balance');

    $receiptId = (int) $afterDiscount['payments'][0]['id'];
    $reversalRequest = $compatibility->requestReceiptReversal($receiptId, $maker);
    $assert((int) $reversalRequest['approval_request_id'] > 0, 'legacy delete becomes a maker-checker reversal request');
    $stillPosted = $db->query(
        'SELECT status FROM finance_receipts WHERE id = ' . $receiptId
    )->fetchColumn();
    $assert((string) $stillPosted === 'posted', 'legacy delete never hard-deletes or reverses before checker approval');

    $table = $compatibility->dataTable([
        'draw' => 7,
        'start' => 0,
        'length' => 25,
        'search' => ['value' => 'طالب توافق'],
        'order' => [['column' => 2, 'dir' => 'asc']],
    ], '2098-2099');
    $assert(
        $table['draw'] === 7
            && $table['recordsFiltered'] === 1
            && count($table['data'][0]) === 9,
        'legacy DataTable response preserves draw/count/data and nine visible columns'
    );

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

echo "Finance legacy compatibility integration test PASSED on {$testDb}.\n";
