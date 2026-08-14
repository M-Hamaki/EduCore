<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$user = (string) file_get_contents($root . '/classes/user.php');
$repository = (string) file_get_contents($root . '/src/Modules/Students/StudentListReadRepository.php');
$adapterStart = strpos($user, '    public function getStudentsPaginated(');
$adapterEnd = $adapterStart === false ? false : strpos($user, "\n\n    /**", $adapterStart);
$adapter = ($adapterStart !== false && $adapterEnd !== false)
    ? substr($user, $adapterStart, $adapterEnd - $adapterStart)
    : '';

$checks = [
    'legacy_adapter_preserved' => strpos($user, 'public function getStudentsPaginated(') !== false
        && strpos($user, 'new \\EduCore\\Modules\\Students\\StudentListReadRepository(') !== false
        && strpos($user, 'return $repository->fetch(') !== false
        && strpos($user, 'public function getStudentsByClasses(') !== false
        && strpos($user, 'return $repository->getStudentsByClasses($class_ids);') !== false,
    'query_owned_by_student_module' => strpos($repository, 'namespace EduCore\\Modules\\Students;') !== false
        && strpos($repository, 'final class StudentListReadRepository') !== false
        && strpos($repository, 'public function fetch(') !== false,
    'legacy_query_not_duplicated' => strpos($adapter, 'SELECT ') === false
        && substr_count($repository, 'SELECT COUNT(*) FROM {$this->tableName} u') === 1,
    'filters_and_ordering_preserved' => strpos($repository, "se.academic_status = 'graduated'") !== false
        && strpos($repository, "se.enrollment_status = 'transferred'") !== false
        && strpos($repository, "se.enrollment_status IN ('discontinued', 'withdrawn')") !== false
        && strpos($repository, ':student_search') !== false
        && strpos($repository, '$orderMap = [') !== false
        && strpos($repository, 'ORDER BY {$orderSql} {$orderDirection}, u.id ASC') !== false,
    'projection_is_fail_closed' => strpos($repository, 'private const REQUIRED_PROJECTED_FIELDS') !== false
        && strpos($repository, 'private const FIELD_SELECTS') !== false
        && strpos($repository, '?array $selectedFields = null') !== false
        && strpos($repository, 'isset(self::FIELD_SELECTS[$field])') !== false
        && strpos($repository, '$this->projectedSelect($selectedFields, $scope, $useEnrollments)') !== false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "Student list read repository contract test passed.\n";
