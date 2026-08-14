<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$mapper = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileRequestMapper.php');
$command = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$checks = [
    'save_action_retained' => strpos($page, "isset(\$_POST['save_student_profile'])") !== false,
    'mapper_used_once_per_write_path' => substr_count($command, '$this->mapper->normalizeAndValidate($post)') === 2
        && strpos($command, 'public function save(') !== false
        && strpos($command, 'public function prepareSpecialistProfileProposal(') !== false,
    'both_profile_paths_use_mapper' => substr_count($command, '$this->mapper->profileData($post') === 2,
    'optimistic_lock_retained' => strpos($command, "\$post['record_version']") !== false
        && strpos($command, 'تم تعديل ملف الطالب بواسطة مستخدم آخر') !== false,
    'transaction_retained' => strpos($command, '$this->db->beginTransaction();') !== false
        && strpos($command, '$this->db->commit();') !== false
        && strpos($command, '$this->db->rollBack();') !== false,
    'validator_coverage' => strpos($mapper, 'ProfileInputValidator::nationalId(') !== false
        && strpos($mapper, 'ProfileInputValidator::birthDate(') !== false
        && strpos($mapper, 'ProfileInputValidator::mobile(') !== false
        && strpos($mapper, 'ProfileInputValidator::landline(') !== false,
    'profile_derivations_retained' => strpos($mapper, 'search_key_ar') !== false
        && strpos($mapper, 'search_key_en') !== false
        && strpos($mapper, 'calculateAgeFromOctober(') !== false
        && strpos($mapper, 'mergeEducationalGuardianship(') !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
