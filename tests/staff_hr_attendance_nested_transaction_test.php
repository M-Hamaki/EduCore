<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceTransactionManager;

$failures = [];

function nestedTransactionCheck(string $name, bool $passed, array &$failures): void
{
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo 'nested_transaction.sqlite_driver:SKIP' . PHP_EOL;
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE transaction_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)');
$transactions = new PdoAttendanceTransactionManager($db);

$db->beginTransaction();
$db->exec("INSERT INTO transaction_probe (marker) VALUES ('outer_before')");
$caught = false;
try {
    $transactions->transactional(static function () use ($db): void {
        $db->exec("INSERT INTO transaction_probe (marker) VALUES ('business_write')");
        throw new RuntimeException('simulated mandatory audit failure');
    });
} catch (RuntimeException $exception) {
    $caught = $exception->getMessage() === 'simulated mandatory audit failure';
}
$db->exec("INSERT INTO transaction_probe (marker) VALUES ('outer_after')");
$db->commit();

$markers = $db->query('SELECT marker FROM transaction_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
nestedTransactionCheck('nested_transaction.propagates_audit_failure', $caught, $failures);
nestedTransactionCheck(
    'nested_transaction.rolls_back_only_inner_business_write',
    $markers === ['outer_before', 'outer_after'],
    $failures
);
nestedTransactionCheck('nested_transaction.outer_transaction_can_commit', !$db->inTransaction(), $failures);

$db->exec('DELETE FROM transaction_probe');
$db->beginTransaction();
$db->exec("INSERT INTO transaction_probe (marker) VALUES ('outer_must_rollback')");
$rollbackFailureCaught = false;
$savepointName = 'attendance_' . spl_object_id($transactions) . '_2';
try {
    $transactions->transactional(static function () use ($db, $savepointName): void {
        $db->exec("INSERT INTO transaction_probe (marker) VALUES ('inner_must_rollback')");
        $db->exec('RELEASE SAVEPOINT ' . $savepointName);
        throw new RuntimeException('simulated audit and savepoint failure');
    });
} catch (RuntimeException $exception) {
    $rollbackFailureCaught = str_contains($exception->getMessage(), 'outer transaction was aborted');
}
nestedTransactionCheck('nested_transaction.rollback_failure_is_fail_closed', $rollbackFailureCaught, $failures);
nestedTransactionCheck('nested_transaction.rollback_failure_aborts_outer', !$db->inTransaction(), $failures);
nestedTransactionCheck(
    'nested_transaction.rollback_failure_leaks_no_rows',
    (int) $db->query('SELECT COUNT(*) FROM transaction_probe')->fetchColumn() === 0,
    $failures
);

$db->beginTransaction();
$lostBoundaryCaught = false;
try {
    $transactions->transactional(static function () use ($db): void {
        $db->commit();
    });
} catch (RuntimeException $exception) {
    $lostBoundaryCaught = str_contains($exception->getMessage(), 'boundary was lost');
}
nestedTransactionCheck('nested_transaction.lost_boundary_is_not_silent_success', $lostBoundaryCaught, $failures);

exit($failures === [] ? 0 : 1);
