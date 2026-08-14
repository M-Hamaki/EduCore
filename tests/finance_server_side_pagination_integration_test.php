<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Finance\Application\FinanceAdminReadService;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceAdminQuery;

$entrypoint = file_get_contents(__DIR__ . '/../admin/finance_datatable.php');
$authPosition = strpos((string) $entrypoint, "Utilities::validateSession('admin')");
$databasePosition = strpos((string) $entrypoint, '$database = new Database();');
$factoryPosition = strpos(
    (string) $entrypoint,
    'new FinanceServiceFactory($db, new AuditService($db))'
);
if (
    $authPosition === false
    || $databasePosition === false
    || $factoryPosition === false
    || !($authPosition < $databasePosition && $databasePosition < $factoryPosition)
) {
    fwrite(STDERR, "FAIL: Finance DataTable entrypoint must authenticate, initialize PDO, then build the service factory.\n");
    exit(1);
}

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
$service = new FinanceAdminReadService(new PdoFinanceAdminQuery($db));
$views = [
    'receipts' => 'receipt_number',
    'student_ledger' => 'transaction_id',
    'staff_ledger' => 'transaction_id',
    'payroll_runs' => 'id',
    'payroll_items' => 'id',
    'journal' => 'entry_number',
    'audit_log' => 'created_at',
    'vouchers' => 'entry_date',
];

foreach ($views as $view => $orderBy) {
    $page = $service->page(
        $view,
        ['student_id' => 0, 'staff_id' => 0, 'academic_year_id' => 0],
        '__finance_search_with_no_matches__',
        $orderBy,
        'desc',
        0,
        25
    );
    if (!isset($page['total'], $page['filtered'], $page['rows']) || !is_array($page['rows'])) {
        fwrite(STDERR, "FAIL: {$view} returned an invalid page contract.\n");
        exit(1);
    }
    if ($page['filtered'] !== 0 || $page['rows'] !== []) {
        fwrite(STDERR, "FAIL: {$view} did not apply server-side search.\n");
        exit(1);
    }
}

echo 'Finance server-side paging passed for ' . count($views) . " high-volume views.\n";
