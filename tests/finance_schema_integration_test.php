<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? ''));
if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName) || $databaseName === 'educore') {
    fwrite(STDERR, "FAIL: --database must identify an isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
$_ENV['DB_NAME'] = $databaseName;
$_SERVER['DB_NAME'] = $databaseName;

require_once dirname(__DIR__) . '/config/database.php';
$db = (new Database())->getConnection();
if (!$db instanceof PDO || (string) $db->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
    fwrite(STDERR, "FAIL: isolated database connection could not be proven.\n");
    exit(2);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$column = static function (string $table, string $name) use ($db): ?array {
    $stmt = $db->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
};
$tableExists = static function (string $table) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() === 1;
};

foreach ([
    'finance_subledger_accounts',
    'finance_subledger_transactions',
    'finance_subledger_lines',
    'finance_payment_allocations',
    'finance_unapplied_credits',
    'finance_adjustments',
    'finance_refunds',
    'staff_advance_movements',
    'accounting_journal_entries',
    'accounting_journal_lines',
    'finance_vouchers',
] as $table) {
    $assert($tableExists($table), "table {$table} exists");
}

foreach ([
    ['finance_payment_allocations', 'signed_amount'],
    ['finance_unapplied_credits', 'signed_amount'],
    ['finance_adjustments', 'signed_amount'],
    ['finance_refunds', 'signed_amount'],
    ['accounting_journal_entries', 'subledger_transaction_id'],
    ['finance_vouchers', 'source_cashbox_id'],
    ['finance_vouchers', 'destination_cashbox_id'],
] as [$table, $name]) {
    $assert($column($table, $name) !== null, "{$table}.{$name} exists");
}

$journalStatus = $column('accounting_journal_entries', 'status');
$assert(
    $journalStatus !== null && strtolower((string) $journalStatus['COLUMN_TYPE']) === "enum('draft','posted')",
    'journal status is draft|posted only'
);

$stmt = $db->prepare(
    'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",")
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
);
$stmt->execute(['finance_subledger_accounts', 'uk_subledger_party_scope_currency']);
$assert(
    (string) $stmt->fetchColumn() === 'party_type,party_id,scope_key,currency',
    'sub-ledger account uniqueness includes currency'
);
$stmt->execute(['accounting_journal_entries', 'uk_journal_subledger_tx']);
$assert(
    (string) $stmt->fetchColumn() === 'subledger_transaction_id',
    'journal/sub-ledger linkage has a unique index'
);

foreach (['v_student_subledger_balances', 'v_staff_subledger_balances', 'v_budget_actuals'] as $view) {
    $assert($tableExists($view), "view {$view} exists");
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} finance schema integration failure(s).\n");
    exit(1);
}

echo "Finance schema integration passed on {$databaseName}.\n";
