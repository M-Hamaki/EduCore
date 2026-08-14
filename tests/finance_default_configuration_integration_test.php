<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;

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
    $connectedDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $assert($connectedDatabase === $testDb, 'test connection remains isolated');

    $accountCodes = [
        '1100', '1200', '1210', '2100', '2200', '2210', '4100',
        '4200', '5100', '5200', '5300', '5400', '5500',
    ];
    $quotedCodes = implode(',', array_fill(0, count($accountCodes), '?'));
    $accountCount = $db->prepare(
        "SELECT COUNT(*) FROM accounting_accounts WHERE code IN ({$quotedCodes})"
    );
    $accountCount->execute($accountCodes);
    $assert((int) $accountCount->fetchColumn() === count($accountCodes), 'all default chart-of-account rows exist');

    $cashbox = $db->query(
        "SELECT id, type, receipt_prefix
           FROM finance_cashboxes
          WHERE code = 'MAIN'
          LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $cashboxId = (int) ($cashbox['id'] ?? 0);
    $assert(
        $cashboxId > 0
        && ($cashbox['type'] ?? null) === 'cash'
        && ($cashbox['receipt_prefix'] ?? null) === 'RCP',
        'default MAIN cashbox exists'
    );

    $activeHeaderCount = (int) $db->query(
        "SELECT COUNT(*) FROM accounting_account_mapping_headers WHERE status = 'active'"
    )->fetchColumn();
    $assert($activeHeaderCount >= 1, 'an active mapping header exists');

    $mappingCount = (int) $db->query(
        'SELECT COUNT(*)
           FROM accounting_account_mapping_lines ml
           JOIN accounting_account_mapping_headers mh ON mh.id = ml.mapping_header_id
          WHERE mh.status = "active"'
    )->fetchColumn();
    $assert($mappingCount >= 19, 'all required default mapping lines exist');

    $controlCount = (int) $db->query(
        "SELECT COUNT(*)
           FROM accounting_control_accounts ca
           JOIN accounting_accounts a ON a.id = ca.account_id
          WHERE a.code IN ('1200', '1210', '2100', '2200')"
    )->fetchColumn();
    $assert($controlCount === 4, 'all student and staff control accounts exist');

    $db->exec(
        "CREATE TABLE IF NOT EXISTS academic_years (
            id INT NOT NULL PRIMARY KEY,
            name VARCHAR(30) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "INSERT INTO academic_years (id, name, is_active, locked, status)
         VALUES (992028, '2092-2093', 1, 0, 'active')
         ON DUPLICATE KEY UPDATE
            is_active = VALUES(is_active),
            locked = VALUES(locked),
            status = VALUES(status)"
    );

    $migration = require __DIR__ . '/../database/migrations/20260728_finance_default_configuration.php';
    if (!is_callable($migration)) {
        throw new RuntimeException('Default Finance configuration migration must return a callable.');
    }
    $migration($db);

    $periodCount = (int) $db->query(
        "SELECT COUNT(*)
           FROM finance_periods
          WHERE academic_year_id = 992028
            AND name = 'العام الدراسي الكامل'
            AND status = 'open'"
    )->fetchColumn();
    $assert($periodCount === 1, 'default open period is created when an active academic year exists');

    $defaultHeaderId = (int) $db->query(
        "SELECT ml.mapping_header_id
           FROM accounting_account_mapping_lines ml
           JOIN accounting_accounts debit_account ON debit_account.id = ml.debit_account_id
           JOIN accounting_accounts credit_account ON credit_account.id = ml.credit_account_id
          WHERE ml.operation_type = 'student_charge'
            AND ml.selector_charge_type_id IS NULL
            AND ml.selector_payroll_component_id IS NULL
            AND ml.selector_payment_method IS NULL
            AND ml.selector_cashbox_id IS NULL
            AND ml.selector_voucher_type IS NULL
            AND debit_account.code = '1200'
            AND credit_account.code = '4100'
          GROUP BY ml.mapping_header_id
          ORDER BY COUNT(*) DESC, ml.mapping_header_id
          LIMIT 1"
    )->fetchColumn();
    $assert($defaultHeaderId > 0, 'default mapping header is identifiable');

    // Other integration tests may create newer active mappings. Temporarily
    // isolate the seeded header so repository resolution verifies this migration.
    $db->beginTransaction();
    $deactivateOtherHeaders = $db->prepare(
        "UPDATE accounting_account_mapping_headers
            SET status = 'superseded', superseded_at = NOW()
          WHERE status = 'active' AND id <> ?"
    );
    $deactivateOtherHeaders->execute([$defaultHeaderId]);

    $repository = new PdoAccountMappingLineRepository($db);
    $policy = new AccountMappingPolicy();
    $chargeTypeId = (int) $db->query(
        "SELECT id FROM finance_charge_types WHERE code = 'tuition' LIMIT 1"
    )->fetchColumn();
    $payrollComponentId = (int) $db->query(
        'SELECT id FROM payroll_components ORDER BY id LIMIT 1'
    )->fetchColumn();

    $cases = [
        ['student_charge', ['charge_type_id' => $chargeTypeId], '1200', '4100'],
        ['student_discount', [], '5300', '1200'],
        ['receipt', ['payment_method' => 'cash', 'cashbox_id' => $cashboxId], '1100', '1200'],
        ['unapplied_credit', ['payment_method' => 'cash', 'cashbox_id' => $cashboxId], '1100', '2100'],
        ['unapplied_credit_application', [], '2100', '1200'],
        ['refund_allocation', ['payment_method' => 'cash'], '1200', '1100'],
        ['refund_unapplied_credit', ['payment_method' => 'cash'], '2100', '1100'],
        ['student_debt_write_off', [], '5400', '1200'],
        ['advance_issue', [], '1210', '1100'],
        ['advance_cash_repayment', ['cashbox_id' => $cashboxId], '1100', '1210'],
        ['advance_payroll_deduction', [], '2200', '1210'],
        ['advance_write_off', [], '5500', '1210'],
        ['payroll_component', ['payroll_component_id' => $payrollComponentId], '5100', '2210'],
        ['payroll_run_item_posting', [], '5100', '2200'],
        ['payroll_payment', ['payment_method' => 'cash', 'cashbox_id' => $cashboxId], '2200', '1100'],
        ['voucher', ['cashbox_id' => $cashboxId, 'voucher_type' => 'expense'], '5200', '1100'],
        ['voucher', ['cashbox_id' => $cashboxId, 'voucher_type' => 'other_income'], '1100', '4200'],
        ['voucher_transfer_out', ['cashbox_id' => $cashboxId], '1100', '1100'],
        ['voucher_transfer_in', ['cashbox_id' => $cashboxId], '1100', '1100'],
    ];
    $accountCode = $db->prepare('SELECT code FROM accounting_accounts WHERE id = ?');
    foreach ($cases as [$operation, $selectors, $expectedDebit, $expectedCredit]) {
        $resolved = $policy->resolve($repository->findActiveLines($operation, $selectors));
        $accountCode->execute([(int) $resolved['debit_account_id']]);
        $actualDebit = (string) $accountCode->fetchColumn();
        $accountCode->execute([(int) $resolved['credit_account_id']]);
        $actualCredit = (string) $accountCode->fetchColumn();
        $assert(
            $actualDebit === $expectedDebit && $actualCredit === $expectedCredit,
            "mapping {$operation} resolves to {$expectedDebit}/{$expectedCredit}"
        );
    }
    $db->rollBack();

    $countsBeforeRetry = [
        'accounts' => (int) $db->query('SELECT COUNT(*) FROM accounting_accounts')->fetchColumn(),
        'cashboxes' => (int) $db->query('SELECT COUNT(*) FROM finance_cashboxes')->fetchColumn(),
        'periods' => (int) $db->query('SELECT COUNT(*) FROM finance_periods')->fetchColumn(),
        'headers' => (int) $db->query('SELECT COUNT(*) FROM accounting_account_mapping_headers')->fetchColumn(),
        'mappings' => (int) $db->query('SELECT COUNT(*) FROM accounting_account_mapping_lines')->fetchColumn(),
        'controls' => (int) $db->query('SELECT COUNT(*) FROM accounting_control_accounts')->fetchColumn(),
    ];
    $migration($db);
    $countsAfterRetry = [
        'accounts' => (int) $db->query('SELECT COUNT(*) FROM accounting_accounts')->fetchColumn(),
        'cashboxes' => (int) $db->query('SELECT COUNT(*) FROM finance_cashboxes')->fetchColumn(),
        'periods' => (int) $db->query('SELECT COUNT(*) FROM finance_periods')->fetchColumn(),
        'headers' => (int) $db->query('SELECT COUNT(*) FROM accounting_account_mapping_headers')->fetchColumn(),
        'mappings' => (int) $db->query('SELECT COUNT(*) FROM accounting_account_mapping_lines')->fetchColumn(),
        'controls' => (int) $db->query('SELECT COUNT(*) FROM accounting_control_accounts')->fetchColumn(),
    ];
    $assert($countsAfterRetry === $countsBeforeRetry, 'default configuration migration is idempotent');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Finance default configuration integration test PASSED on {$testDb}.\n";
