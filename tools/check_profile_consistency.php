<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/user.php';

$db = (new Database())->getConnection();
$issues = [
    'students_missing_profile' => 0,
    'student_name_mismatches' => 0,
    'student_name_parts_missing' => 0,
    'student_search_mismatches' => 0,
    'orphan_student_profiles' => 0,
    'staff_missing_profile' => 0,
    'staff_name_mismatches' => 0,
    'orphan_staff_profiles' => 0,
];

$students = $db->query("SELECT u.id, u.name, sp.* FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id WHERE u.role='student'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($students as $student) {
    if (empty($student['user_id'])) {
        $issues['students_missing_profile']++;
        continue;
    }
    if (trim((string)$student['first_name_ar']) === '' && trim((string)$student['name']) !== '') {
        $issues['student_name_parts_missing']++;
    }
    $fullName = User::joinNameParts([
        $student['first_name_ar'], $student['second_name_ar'], $student['third_name_ar'],
        $student['fourth_name_ar'], $student['family_name_ar'],
    ]);
    if ($fullName !== '' && trim((string)$student['name']) !== $fullName) $issues['student_name_mismatches']++;
    $searchAr = User::buildSearchKey(
        $student['first_name_ar'], $student['second_name_ar'], $student['third_name_ar'],
        $student['fourth_name_ar'], $student['family_name_ar']
    );
    $searchEn = User::buildSearchKey(
        $student['first_name_en'], $student['second_name_en'], $student['third_name_en'],
        $student['fourth_name_en'], $student['family_name_en']
    );
    if ((string)$student['search_key_ar'] !== $searchAr || (string)$student['search_key_en'] !== $searchEn) {
        $issues['student_search_mismatches']++;
    }
}

$issues['orphan_student_profiles'] = (int)$db->query("SELECT COUNT(*) FROM student_profiles sp LEFT JOIN users u ON u.id=sp.user_id WHERE u.id IS NULL OR u.role<>'student'")->fetchColumn();
$issues['staff_missing_profile'] = (int)$db->query("SELECT COUNT(*) FROM users u LEFT JOIN staff_profiles sp ON sp.user_id=u.id WHERE u.role IN ('teacher','specialist','supervisor') AND sp.id IS NULL")->fetchColumn();
$issues['orphan_staff_profiles'] = (int)$db->query("SELECT COUNT(*) FROM staff_profiles sp LEFT JOIN users u ON u.id=sp.user_id WHERE u.id IS NULL OR u.role='student'")->fetchColumn();

$staff = $db->query("SELECT u.name, sp.full_name_ar FROM users u JOIN staff_profiles sp ON sp.user_id=u.id WHERE u.role IN ('teacher','specialist','supervisor')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($staff as $member) {
    if (trim((string)$member['full_name_ar']) !== '' && trim((string)$member['name']) !== trim((string)$member['full_name_ar'])) {
        $issues['staff_name_mismatches']++;
    }
}

$total = array_sum($issues);
foreach ($issues as $name => $count) echo $name . '=' . $count . PHP_EOL;
echo 'total_issues=' . $total . PHP_EOL;
exit($total === 0 ? 0 : 2);
