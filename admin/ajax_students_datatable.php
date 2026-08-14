<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../classes/user.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/StudentListPageQuery.php';

use EduCore\Modules\Students\Presentation\StudentListDataTablePresenter;
use EduCore\Modules\Students\StudentListDataTableQuery;
use EduCore\Modules\Students\StudentListReadRepository;

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

$scope = $_POST['student_scope'] ?? 'current';
$scope = in_array($scope, ['current', 'graduates', 'transferred', 'discontinued'], true) ? $scope : 'current';
$pages = [
    'current' => 'students.php',
    'graduates' => 'graduate_students.php',
    'transferred' => 'transferred_students.php',
    'discontinued' => 'discontinued_students.php',
];

try {
    $db = (new Database())->getConnection();
    $portal = new ScopedStaffPortalContext($db, AcademicYear::currentId($db));
    $isSpecialist = $portal->role() === 'specialist';
    if ($isSpecialist) {
        $scope = 'current';
    }

    $result = (new StudentListDataTableQuery(
        new StudentListReadRepository($db),
        new StudentListDataTablePresenter()
    ))->load(
        $_POST,
        $scope,
        $pages[$scope],
        $portal->allowedClassIds(),
        !$isSpecialist
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Student DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'draw' => (int) ($_POST['draw'] ?? 0),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
}
