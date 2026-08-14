<?php

require_once __DIR__ . '/../classes/user.php';

$root = dirname(__DIR__);
$facade = (string) file_get_contents($root . '/classes/user.php');
$store = (string) file_get_contents($root . '/classes/UserProfileStore.php');
$delegatedMethods = [
    'getStaffProfile',
    'saveStaffProfile',
    'readAllStaffWithProfiles',
    'deleteStaffProfileImage',
    'normalizeArabicName',
    'buildSearchKey',
    'calculateAgeFromOctober',
    'calculateCurrentAge',
    'generateStudentCode',
    'generateEmployeeCode',
    'generateTeacherCode',
    'getStudentProfile',
    'saveStudentProfile',
    'getStudentGuardians',
    'saveStudentGuardian',
    'deleteStudentGuardian',
    'findPotentialSiblings',
    'findPotentialKinship',
    'searchStudentsForSibling',
    'linkSiblings',
    'unlinkSiblings',
    'getStudentSiblings',
    'logStudentTransfer',
    'getStudentTransfers',
    'getStudentAcademicHistory',
    'getStudentsWithProfiles',
];

$allMethodsPresent = true;
foreach ($delegatedMethods as $method) {
    $allMethodsPresent = $allMethodsPresent
        && method_exists(User::class, $method)
        && method_exists(UserProfileStore::class, $method);
}

$checks = [
    'all_public_methods_preserved' => $allMethodsPresent,
    'static_age_contract_preserved' => (new ReflectionMethod(
        User::class,
        'calculateCurrentAge'
    ))->isStatic() && (new ReflectionMethod(User::class, 'normalizeArabicName'))->isStatic(),
    'facade_constructs_store' => strpos($facade, 'new UserProfileStore($db)') !== false,
    'facade_no_longer_owns_profile_sql' => strpos(
        $facade,
        'SELECT * FROM staff_profiles WHERE user_id'
    ) === false && strpos($facade, 'SELECT * FROM student_profiles WHERE user_id') === false,
    'store_owns_profile_sql' => strpos(
        $store,
        'SELECT * FROM staff_profiles WHERE user_id'
    ) !== false && strpos($store, 'SELECT * FROM student_profiles WHERE user_id') !== false,
    'store_does_not_read_http_state' => strpos($store, '$_POST') === false
        && strpos($store, '$_GET') === false
        && strpos($store, '$_FILES') === false
        && strpos($store, '$_SESSION') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
