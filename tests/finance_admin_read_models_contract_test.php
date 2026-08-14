<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\FinanceAdminReadService;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceAdminQuery;

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') {
    fwrite(STDERR, "FAILED: --database must be an isolated *_test database.\n");
    exit(1);
}

$db = new PDO(
    'mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4',
    (string) env('DB_USER', 'root'),
    (string) env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS academic_years (
        id INT PRIMARY KEY,
        name VARCHAR(30) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 0,
        locked TINYINT NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'active'
    ) ENGINE=InnoDB"
);
$service = new FinanceAdminReadService(new PdoFinanceAdminQuery($db));
$views = [
    'fee_plans', 'discounts', 'receipts', 'debts', 'staff_contracts', 'payroll_runs', 'payroll_items',
    'staff_advances', 'student_ledger', 'staff_ledger', 'cashboxes', 'budgets',
    'archive', 'imports', 'student_accounts', 'buses', 'journal', 'accounts',
    'audit_log', 'vouchers', 'approvals', 'discount_awards', 'periods', 'refunds', 'payroll_payments',
];

foreach ($views as $view) {
    $rows = $service->rows($view, ['student_id' => 0, 'staff_id' => 0, 'academic_year_id' => 0], 5);
    if (!is_array($rows)) {
        fwrite(STDERR, "FAIL: {$view} did not return rows.\n");
        exit(1);
    }
}

echo 'Finance admin read models passed for ' . count($views) . " views.\n";
