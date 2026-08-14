<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/user.php';
require_once '../classes/StaffListPageQuery.php';

use EduCore\Modules\Staff\Presentation\StaffListDataTablePresenter;
use EduCore\Modules\Staff\StaffListDataTableQuery;

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $query = new StaffListDataTableQuery(new User($db), new StaffListDataTablePresenter());
    echo json_encode($query->load($_POST), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Staff DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int) ($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
