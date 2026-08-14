<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/ClinicListDataTableQuery.php';

$db = (new Database())->getConnection();
$databaseName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_test$/', $databaseName)) {
    fwrite(STDERR, "Refusing to query a non-test database.\n");
    exit(2);
}

$yearId = (int)($db->query("SELECT id FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC LIMIT 1")->fetchColumn() ?: 0);
$query = new ClinicListDataTableQuery($db);
$request = ['draw' => 1, 'start' => 0, 'length' => 10, 'search' => ['value' => ''], 'order' => [['column' => 1, 'dir' => 'asc']]];
$unrestricted = $query->counts($yearId, [], [], null);
$empty = $query->counts($yearId, [], [], []);
$emptyHealth = $query->health($request, $yearId, [], true);
$emptyVisits = $query->visits($request, $yearId, [], true);

$classIds = array_map('intval', $db->query('SELECT id FROM classes WHERE status = \'active\' ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN) ?: []);
$scoped = $query->counts($yearId, [], [], $classIds);

$checks = [
    'empty_scope_returns_no_health_rows' => $empty['health'] === 0 && $emptyHealth['recordsTotal'] === 0 && $emptyHealth['data'] === [],
    'empty_scope_returns_no_visit_rows' => $empty['visits'] === 0 && $emptyVisits['recordsTotal'] === 0 && $emptyVisits['data'] === [],
    'scoped_totals_never_exceed_admin_totals' => $scoped['health'] <= $unrestricted['health'] && $scoped['visits'] <= $unrestricted['visits'],
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
