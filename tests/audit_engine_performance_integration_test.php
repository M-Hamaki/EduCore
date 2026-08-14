<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;

$db = educoreTestDatabase();
$_SESSION['user_id'] = 987654322;
$_SESSION['name'] = 'Audit Performance Test';
$_SESSION['role'] = 'admin';

$iterations = 100;
$durations = [];
$db->beginTransaction();
try {
    $audit = new AuditService($db);
    $insert = $db->prepare('INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
    for ($index = 0; $index < $iterations; $index++) {
        $key = 'audit_perf_' . bin2hex(random_bytes(6));
        $insert->execute([$key, 'value-' . $index, 'isolated performance test']);
        $id = (int) $db->lastInsertId();
        $snapshot = [
            'id' => $id,
            'setting_key' => $key,
            'setting_value' => 'value-' . $index,
            'description' => 'isolated performance test',
            'future_field' => 'automatically handled',
            'password_hash' => 'must never persist',
        ];
        $started = hrtime(true);
        $audit->recordInsert('audit_performance_setting', 'settings', $id, $key, $snapshot, 'اختبار أداء التسجيل');
        $durations[] = (hrtime(true) - $started) / 1_000_000;
    }
} finally {
    $db->rollBack();
}

sort($durations, SORT_NUMERIC);
$averageMs = array_sum($durations) / max(1, count($durations));
$p95Index = max(0, (int) ceil(count($durations) * 0.95) - 1);
$p95Ms = $durations[$p95Index] ?? INF;
$checks = [
    'all_events_recorded_in_measurement' => count($durations) === $iterations,
    'average_audit_latency_within_budget' => $averageMs <= 30.0,
    'p95_audit_latency_within_budget' => $p95Ms <= 75.0,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
echo 'average_ms=' . number_format($averageMs, 3, '.', '') . PHP_EOL;
echo 'p95_ms=' . number_format($p95Ms, 3, '.', '') . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
