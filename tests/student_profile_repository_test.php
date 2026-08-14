<?php

require_once __DIR__ . '/../classes/StudentProfileRepository.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, class_id INTEGER)');
$db->exec('CREATE TABLE classes (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO classes (id, name) VALUES (7, '1/أ')");
$db->exec("INSERT INTO users (id, name, role, class_id) VALUES (10, 'طالب اختبار', 'student', 7)");

$repository = new StudentProfileRepository($db);
$results = [
    'class_name' => $repository->className(7) === '1/أ',
    'missing_class_fallback' => $repository->className(99) === 'فصل #99',
    'student_name' => $repository->studentName(10) === 'طالب اختبار',
    'missing_student_fallback' => $repository->studentName(99) === 'طالب #99',
];

try {
    $repository->assertManageableStudent(10);
    $results['student_target_accepted'] = true;
} catch (Throwable $e) {
    $results['student_target_accepted'] = false;
}
try {
    $repository->assertManageableStudent(99);
    $results['missing_target_rejected'] = false;
} catch (RuntimeException $e) {
    $results['missing_target_rejected'] = true;
}

$failed = array_keys(array_filter($results, static fn($passed) => !$passed));
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit($failed ? 1 : 0);
