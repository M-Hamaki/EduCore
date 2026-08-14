<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/StaffAcademicScopeService.php';
require_once '../classes/StaffRoleCapabilityResolver.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $academicYearId = AcademicYear::currentId($db);
    $staffId = max(0, (int)($_POST['staff_id'] ?? 0));
    $roleKey = trim((string)($_POST['role_key'] ?? ''));
    if (($_POST['action'] ?? 'get') !== 'get' || $academicYearId <= 0 || $staffId <= 0 || $roleKey === '') {
        throw new InvalidArgumentException('طلب نطاق العامل غير صالح.');
    }

    $staffStmt = $db->prepare("SELECT u.id, u.name, u.role
        FROM users u
        INNER JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE u.id = ?
          AND (u.role IS NULL OR u.role NOT IN ('student','external_teacher'))
        LIMIT 1");
    $staffStmt->execute([$staffId]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        throw new InvalidArgumentException('العامل المحدد غير موجود أو غير قابل للإدارة من هذه الصفحة.');
    }
    if (!(new StaffRoleCapabilityResolver($db))->requiresAcademicScope($roleKey)) {
        throw new InvalidArgumentException('الدور المحدد لا يستخدم نطاقاً أكاديمياً.');
    }

    $service = new StaffAcademicScopeService($db);
    $scope = $service->scope($staffId, $academicYearId, $roleKey);
    $grades = $db->query("SELECT g.id, g.grade_name, g.stage_id, COALESCE(s.stage_name, 'مرحلة غير محددة') AS stage_name FROM grades g LEFT JOIN stages s ON s.id = g.stage_id WHERE g.status = 'active' ORDER BY COALESCE(s.id, 999), g.grade_order, g.grade_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $classes = $db->query("SELECT id, grade_id, name FROM classes WHERE status = 'active' ORDER BY grade_id, display_order, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode([
        'success' => true,
        'staff' => ['id' => (int)$staff['id'], 'name' => (string)$staff['name'], 'role' => $roleKey],
        'academic_year_id' => $academicYearId,
        'academic_year_name' => AcademicYear::currentName($db),
        'grades' => $grades,
        'classes' => $classes,
        'scope' => $scope,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException|RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Staff scope endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر تحميل النطاق الأكاديمي للعامل.'], JSON_UNESCAPED_UNICODE);
}
