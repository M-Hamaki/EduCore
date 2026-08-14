<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/admin/staff_financial_data.php');
$failures = [];

$positions = [
    'auth' => strpos($source, "Utilities::validateSession('admin');"),
    'csrf' => strpos($source, 'requireCsrfPost();'),
    'database' => strpos($source, '$database = new Database();'),
    'set_db' => strpos($source, 'ActivityLog::setDb($db);'),
    'begin' => strpos($source, '$db->beginTransaction();'),
    'update' => strpos($source, '$update->execute(['),
    'log' => strpos($source, '$logged = ActivityLog::logUpdate('),
    'commit' => strpos($source, '$db->commit();'),
    'rollback' => strpos($source, '$db->rollBack();'),
];

foreach ($positions as $name => $position) {
    if ($position === false) {
        $failures[] = 'missing_' . $name;
    }
}

if (!$failures) {
    if (!($positions['auth'] < $positions['csrf'] && $positions['csrf'] < $positions['database'])) {
        $failures[] = 'guard_order';
    }
    if (!($positions['set_db'] < $positions['begin'] && $positions['begin'] < $positions['update']
        && $positions['update'] < $positions['log'] && $positions['log'] < $positions['commit'])) {
        $failures[] = 'transaction_order';
    }
    if (strpos($source, 'if (!$logged)') === false || strpos($source, '$db->inTransaction()') === false) {
        $failures[] = 'failure_rollback_contract';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: staff finance update and audit share one atomic transaction.\n";
