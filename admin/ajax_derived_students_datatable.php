<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Students/bootstrap.php';

use EduCore\Modules\Students\DerivedStudentListDataTableQuery;

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $query = new DerivedStudentListDataTableQuery($db);
    $list = (string) ($_POST['list'] ?? '');
    if ($list === 'new') {
        $year = AcademicYear::getCurrent($db);
        echo json_encode($query->loadNewStudents($_POST, AcademicYear::currentId($db), $year['start_date'] ?? null, $year['end_date'] ?? null), JSON_UNESCAPED_UNICODE);
    } elseif ($list === 'graduate') {
        echo json_encode($query->loadGraduates($_POST), JSON_UNESCAPED_UNICODE);
    } elseif ($list === 'transferred') {
        echo json_encode($query->loadTransferredStudents($_POST, AcademicYear::currentId($db)), JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(422);
        echo json_encode(['draw' => (int) ($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('Derived student DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int) ($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
