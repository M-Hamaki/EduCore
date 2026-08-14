<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/LibraryListDataTableQuery.php';

$db = (new Database())->getConnection();
$databaseName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_test$/', $databaseName)) {
    fwrite(STDERR, "Refusing to query a non-test database.\n");
    exit(2);
}

$yearId = (int)($db->query("SELECT id FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC LIMIT 1")->fetchColumn() ?: 0);
$query = new LibraryListDataTableQuery($db);
$request = ['draw' => 1, 'start' => 0, 'length' => 10, 'search' => ['value' => ''], 'order' => [['column' => 0, 'dir' => 'asc']]];
$classIds = array_map('intval', $db->query("SELECT id FROM classes WHERE status = 'active' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN) ?: []);

$checks = [];
foreach (['loans', 'returns', 'fines'] as $type) {
    $admin = $query->load($type, $request, $yearId, null);
    $empty = $query->load($type, $request, $yearId, []);
    $scoped = $query->load($type, $request, $yearId, $classIds);
    $checks[$type . '_empty_scope_returns_no_rows'] = $empty['total'] === 0 && $empty['rows'] === [];
    $checks[$type . '_scope_never_exceeds_admin'] = $scoped['total'] <= $admin['total'];
}
$booksAdmin = $query->load('books', $request, $yearId, null);
$booksScoped = $query->load('books', $request, $yearId, []);
$checks['catalog_is_shared_across_scope'] = $booksAdmin['total'] === $booksScoped['total'];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
