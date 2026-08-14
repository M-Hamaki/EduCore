<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/user.php';

$db = educoreTestDatabase();
$user = new User($db);
$suffix = bin2hex(random_bytes(4));
$results = [];
$fourPartName = User::splitDisplayName('آدم أحمد شريف عثمان');
$results['four_part_name_round_trip'] = User::joinNameParts(array_values($fourPartName)) === 'آدم أحمد شريف عثمان'
    && ($fourPartName['family_name_ar'] ?? null) === null
    && ($fourPartName['fourth_name_ar'] ?? null) === 'عثمان';

$db->beginTransaction();
try {
    $user->name = 'اسم مؤقت';
    $user->username = 'student_consistency_' . $suffix;
    $user->password = 'Consistency#123';
    $user->role = 'student';
    $user->class_id = null;
    $user->create();
    $studentId = (int)$user->id;
    $user->saveStudentProfile($studentId, [
        'student_code' => 'TEST' . strtoupper($suffix),
        'first_name_ar' => 'أحمد',
        'second_name_ar' => 'محمد',
        'third_name_ar' => 'علي',
        'fourth_name_ar' => 'حسن',
        'family_name_ar' => 'سالم',
        'birth_date' => '2015-01-15',
        'city_area' => 'القاهرة',
    ]);
    $student = $db->query("SELECT u.name, sp.search_key_ar, sp.age_reference_date, sp.city_area FROM users u JOIN student_profiles sp ON sp.user_id=u.id WHERE u.id={$studentId}")->fetch(PDO::FETCH_ASSOC);
    $results['student_name_sync'] = $student['name'] === 'أحمد محمد علي حسن سالم';
    $results['student_search_sync'] = $student['search_key_ar'] !== '';
    $results['student_age_sync'] = !empty($student['age_reference_date']);
    $results['student_city_saved'] = $student['city_area'] === 'القاهرة';

    $staffUser = new User($db);
    $staffUser->name = 'موظف مؤقت';
    $staffUser->username = 'staff_consistency_' . $suffix;
    $staffUser->password = 'Consistency#456';
    $staffUser->role = 'teacher';
    $staffUser->class_id = null;
    $staffUser->create();
    $staffId = (int)$staffUser->id;
    $staffUser->saveStaffProfile($staffId, ['full_name_ar' => 'منى أحمد محمد سالم']);
    $results['staff_name_sync'] = $db->query("SELECT name FROM users WHERE id={$staffId}")->fetchColumn() === 'منى أحمد محمد سالم';

    foreach ($results as $name => $passed) echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (in_array(false, $results, true)) throw new RuntimeException('Profile consistency assertion failed');
    $db->rollBack();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
