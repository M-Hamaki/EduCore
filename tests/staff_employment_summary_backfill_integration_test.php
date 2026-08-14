<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($databaseName !== 'educore_staff_consistency_test') {
    throw new RuntimeException(
        'This destructive fixture is restricted to educore_staff_consistency_test.'
    );
}

$db->exec('DROP TABLE IF EXISTS staff_job_movements');
$db->exec('DROP TABLE IF EXISTS staff_status_history');
$db->exec('DROP TABLE IF EXISTS staff_profiles');
$db->exec('CREATE TABLE staff_profiles (
    user_id INT PRIMARY KEY,
    job_title VARCHAR(255) NULL,
    job_grade VARCHAR(100) NULL,
    department VARCHAR(255) NULL,
    contract_type VARCHAR(100) NULL,
    contract_start DATE NULL,
    contract_end DATE NULL,
    current_work_status VARCHAR(20) NULL,
    current_status_reason VARCHAR(255) NULL,
    current_status_effective_date DATE NULL,
    hire_date DATE NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE staff_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    movement_type VARCHAR(100) NOT NULL,
    status_after VARCHAR(20) NOT NULL,
    status_label VARCHAR(100) NULL,
    status_reason VARCHAR(255) NULL,
    effective_date DATE NULL,
    job_title VARCHAR(255) NULL,
    job_grade VARCHAR(100) NULL,
    department VARCHAR(255) NULL,
    contract_type VARCHAR(100) NULL,
    contract_start DATE NULL,
    contract_end DATE NULL,
    source VARCHAR(50) NOT NULL DEFAULT \'staff_form\'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE staff_job_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    previous_job_title VARCHAR(255) NULL,
    new_job_title VARCHAR(255) NULL,
    previous_contract_type VARCHAR(100) NULL,
    new_contract_type VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$db->exec("INSERT INTO staff_profiles VALUES
    (1, 'مدرس أول', 'الأولى', 'ابتدائي', 'دائم', '2020-09-01', NULL, 'on_duty', NULL, '2020-09-01', '2020-09-01'),
    (2, 'أخصائي نفسي', 'الثانية', 'إعدادي', 'مؤقت', '2021-09-01', NULL, 'on_duty', NULL, '2021-09-01', '2021-09-01'),
    (3, 'مسؤول المكتبة', NULL, 'إعدادي', 'دائم', '2022-09-01', NULL, 'on_duty', NULL, '2022-09-01', '2022-09-01'),
    (4, 'قسم الإعلام', NULL, 'إداري', 'دائم', '2023-09-01', NULL, 'on_duty', NULL, '2023-09-01', '2023-09-01')");
$db->exec("INSERT INTO staff_status_history
    (user_id, movement_type, status_after, effective_date, job_title, job_grade, department, contract_type, contract_start)
    VALUES
    (1, 'تعيين', 'on_duty', '2020-09-01', NULL, NULL, NULL, NULL, NULL),
    (2, 'تعيين', 'on_duty', '2021-09-01', 'مسمى صريح', NULL, NULL, 'دائم', NULL)");
$db->exec("INSERT INTO staff_job_movements
    (previous_job_title, new_job_title, previous_contract_type, new_contract_type)
    VALUES ('مشرف حسابات', 'مدير حسابات', 'مؤقت', 'جزئي')");

$migration = require dirname(__DIR__)
    . '/database/migrations/20260729_staff_employment_summary_consistency.php';
$migration($db);
$migration($db);

$first = $db->query('SELECT * FROM staff_status_history WHERE user_id = 1')->fetch(PDO::FETCH_ASSOC);
$second = $db->query('SELECT * FROM staff_status_history WHERE user_id = 2')->fetch(PDO::FETCH_ASSOC);
$third = $db->query('SELECT * FROM staff_status_history WHERE user_id = 3')->fetch(PDO::FETCH_ASSOC);
$fourth = $db->query('SELECT * FROM staff_status_history WHERE user_id = 4')->fetch(PDO::FETCH_ASSOC);
$movement = $db->query('SELECT * FROM staff_job_movements LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$profileTitles = $db->query('SELECT job_title FROM staff_profiles ORDER BY user_id')
    ->fetchAll(PDO::FETCH_COLUMN);
$profileContracts = $db->query('SELECT contract_type FROM staff_profiles ORDER BY user_id')
    ->fetchAll(PDO::FETCH_COLUMN);

$checks = [
    'missing_summary_fields_backfilled' => ($first['job_title'] ?? null) === 'معلم'
        && ($first['job_grade'] ?? null) === 'الأولى'
        && ($first['department'] ?? null) === 'ابتدائي',
    'explicit_history_value_preserved' => ($second['job_title'] ?? null) === 'مسمى صريح',
    'missing_history_row_created_with_canonical_title' => ($third['job_title'] ?? null) === 'أمين مكتبة'
        && ($third['source'] ?? null) === 'summary_backfill',
    'profile_job_titles_canonicalized' => $profileTitles === ['معلم', 'أخصائي', 'أمين مكتبة', null],
    'retired_media_title_removed_from_history' => ($fourth['job_title'] ?? null) === null,
    'profile_contracts_canonicalized' => $profileContracts === ['permanent', 'temporary', 'permanent', 'permanent'],
    'history_contracts_canonicalized' => ($first['contract_type'] ?? null) === 'permanent'
        && ($second['contract_type'] ?? null) === 'permanent',
    'movement_contracts_canonicalized' => ($movement['previous_contract_type'] ?? null) === 'temporary'
        && ($movement['new_contract_type'] ?? null) === 'parttime',
    'movement_job_titles_canonicalized' => ($movement['previous_job_title'] ?? null) === 'محاسب'
        && ($movement['new_job_title'] ?? null) === 'محاسب',
    'idempotent_second_run' => (int) $db->query('SELECT COUNT(*) FROM staff_status_history')->fetchColumn() === 4,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
