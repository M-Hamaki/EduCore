<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$columnExists = static function (PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
};

$legacyStudentIds = [];
if ($columnExists($db, 'student_profiles', 'is_test_account')) {
    $legacyStudentIds = array_map('intval', $db->query(
        'SELECT user_id FROM student_profiles WHERE is_test_account = 1 ORDER BY user_id'
    )->fetchAll(PDO::FETCH_COLUMN));
}

$migration = require dirname(__DIR__) . '/database/migrations/20260719_student_test_account_ownership.php';
$migration($db);
$migration($db); // idempotency proof

$migratedStudentIds = array_map('intval', $db->query(
    "SELECT id FROM users WHERE role = 'student' AND is_test_account = 1 ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN));

$checks = [
    'users_column_exists' => $columnExists($db, 'users', 'is_test_account'),
    'legacy_profile_column_removed' => !$columnExists($db, 'student_profiles', 'is_test_account'),
    'all_legacy_test_flags_preserved' => count(array_diff($legacyStudentIds, $migratedStudentIds)) === 0,
    'idempotent_second_run_preserves_flags' => count($migratedStudentIds) >= count($legacyStudentIds),
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
echo 'legacy_test_accounts=' . count($legacyStudentIds) . PHP_EOL;
echo 'migrated_test_accounts=' . count($migratedStudentIds) . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
