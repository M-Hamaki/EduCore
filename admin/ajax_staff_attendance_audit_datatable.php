<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/StaffAttendanceService.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $result = (new StaffAttendanceService($db))->getAttendanceAuditDataTable($_POST);
    $escape = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    $actionLabels = ['insert' => 'إضافة', 'update' => 'تعديل', 'delete' => 'حذف', 'biometric_import' => 'مزامنة بصمة'];
    $actionBadges = ['insert' => 'success', 'update' => 'primary', 'delete' => 'danger', 'biometric_import' => 'warning'];
    $sourceLabels = ['manual' => 'يدوي', 'biometric' => 'بصمة'];
    $offset = max(0, (int)($_POST['start'] ?? 0));

    $data = [];
    foreach ($result['rows'] as $index => $row) {
        $action = (string)($row['action_type'] ?? '');
        $badgeClass = $actionBadges[$action] ?? 'secondary';
        $badgeText = $action === 'biometric_import' ? ' text-dark' : '';
        $data[] = [
            $offset + $index + 1,
            $escape($row['staff_name'] ?? '-'),
            $escape($row['attendance_date'] ?? '-'),
            '<span class="badge bg-' . $escape($badgeClass) . $badgeText . '">' . $escape($actionLabels[$action] ?? $action) . '</span>',
            $escape($sourceLabels[$row['source'] ?? ''] ?? ($row['source'] ?? '-')),
            $escape($row['changed_by_name'] ?? '-') ?: '-',
            '<pre class="small mb-0">' . $escape($row['before_data'] ?? '') . '</pre>',
            '<pre class="small mb-0">' . $escape($row['after_data'] ?? '') . '</pre>',
            $escape($row['created_at'] ?? '-')
        ];
    }

    echo json_encode([
        'draw' => $result['draw'],
        'recordsTotal' => $result['recordsTotal'],
        'recordsFiltered' => $result['recordsFiltered'],
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Staff attendance audit DataTables endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
}
