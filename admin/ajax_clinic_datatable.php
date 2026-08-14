<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ClinicListDataTableQuery.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $yearId = AcademicYear::currentId($db);
    $portalContext = new ScopedStaffPortalContext($db, $yearId);
    $query = new ClinicListDataTableQuery($db);
    $type = (string)($_POST['type'] ?? '');
    $canManage = $portalContext->role() !== 'specialist';

    if ($type === 'health' && $portalContext->role() === 'specialist') {
        http_response_code(403);
        echo json_encode(['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = match ($type) {
        'health' => $query->health($_POST, $yearId, $portalContext->allowedClassIds(), $canManage),
        'visits' => $query->visits($_POST, $yearId, $portalContext->allowedClassIds(), $canManage),
        default => null,
    };
    if ($result === null) {
        http_response_code(422);
        $result = ['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Clinic DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
