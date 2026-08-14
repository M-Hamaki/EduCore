<?php
declare(strict_types=1);
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/FeePaymentListQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');
try {
    $db = (new Database())->getConnection();
    echo json_encode((new FeePaymentListQuery($db))->load($_POST, AcademicYear::currentId($db)), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Fee payment DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int) ($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
