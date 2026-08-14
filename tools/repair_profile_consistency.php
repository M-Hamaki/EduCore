<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/user.php';

$apply = in_array('--apply', $argv ?? [], true);
$db = (new Database())->getConnection();
$user = new User($db);

$report = [
    'students_checked' => 0,
    'student_names_fixed' => 0,
    'student_profiles_created' => 0,
    'student_name_parts_backfilled' => 0,
    'student_derived_refreshed' => 0,
    'staff_checked' => 0,
    'staff_names_fixed' => 0,
];

$db->beginTransaction();
try {
    $students = $db->query("SELECT u.id, u.name, sp.id AS profile_id,
                                   sp.first_name_ar, sp.second_name_ar, sp.third_name_ar,
                                   sp.fourth_name_ar, sp.family_name_ar
                            FROM users u
                            LEFT JOIN student_profiles sp ON sp.user_id = u.id
                            WHERE u.role = 'student'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($students as $student) {
        $report['students_checked']++;
        if (empty($student['profile_id'])) {
            if ($user->ensureStudentProfile((int)$student['id'], (string)$student['name'])) {
                $report['student_profiles_created']++;
            }
            continue;
        }

        if (trim((string)$student['first_name_ar']) === '' && trim((string)$student['name']) !== '') {
            $parts = User::splitDisplayName((string)$student['name']);
            if ($user->saveStudentProfile((int)$student['id'], $parts)) {
                $report['student_name_parts_backfilled']++;
                $student = array_merge($student, $parts);
            }
        }

        $fullName = User::joinNameParts([
            $student['first_name_ar'], $student['second_name_ar'], $student['third_name_ar'],
            $student['fourth_name_ar'], $student['family_name_ar'],
        ]);
        if ($fullName !== '' && trim((string)$student['name']) !== $fullName) {
            $report['student_names_fixed']++;
        }
        if ($user->saveStudentProfile((int)$student['id'], [])) {
            $report['student_derived_refreshed']++;
        }
    }

    $staff = $db->query("SELECT u.id, u.name, sp.full_name_ar
                         FROM users u
                         JOIN staff_profiles sp ON sp.user_id = u.id
                         WHERE u.role IN ('teacher','specialist','supervisor')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($staff as $member) {
        $report['staff_checked']++;
        $fullName = trim((string)$member['full_name_ar']);
        if ($fullName !== '' && trim((string)$member['name']) !== $fullName) $report['staff_names_fixed']++;
        $user->saveStaffProfile((int)$member['id'], [
            'full_name_ar' => $member['full_name_ar'],
        ]);
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }

    echo ($apply ? 'APPLIED' : 'DRY_RUN') . PHP_EOL;
    foreach ($report as $key => $value) echo $key . '=' . $value . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
