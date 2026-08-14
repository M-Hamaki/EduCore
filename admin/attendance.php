<?php
/**
 * تقارير الحضور والغياب - Admin Attendance Reports & Recording
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once '../config/database.php';
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
require_once '../includes/csrf.php';

// Validate session before database connection or request handling
Utilities::validateSession('admin');

$page_title = "الحضور والغياب";
$custom_page_title = true;

require_once '../classes/ActivityLog.php';
require_once '../classes/pdf_handler.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/StudentEnrollment.php';
require_once '../classes/user.php';
require_once '../classes/StudentOperationalGuard.php';
require_once '../classes/StudentAttendanceService.php';
require_once '../classes/ScopedStaffPortalContext.php';

$database = new Database();
$db = $database->getConnection();
$studentOperationalGuard = new StudentOperationalGuard($db);
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();

/**
 * جلب طلاب فصل معيّن في العام الحالي (مركزي عبر التسجيلات السنوية).
 */
function attendance_fetch_class_students(PDO $db, $classId, ?array $allowedClassIds = null): array {
    if (empty($classId)) return [];
    $classId = (int)$classId;
    if ($allowedClassIds !== null && !in_array($classId, $allowedClassIds, true)) {
        return [];
    }
    $yearId = AcademicYear::currentId($db);
    if ($yearId > 0) {
        $stmt = $db->prepare("SELECT u.id, u.name FROM users u
            JOIN student_enrollments se ON se.student_id = u.id
                AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            WHERE se.class_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY u.name");
        $stmt->execute([$yearId, $classId]);
    } else {
        $stmt = $db->prepare("SELECT u.id, u.name FROM users u
            WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY u.name");
        $stmt->execute([$classId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Adds a fail-closed class constraint to a query used by a scoped staff portal.
 * A null list represents unrestricted admin access; an empty list represents no access.
 */
function attendance_add_class_scope(array &$where, array &$params, string $column, ?array $allowedClassIds, string $prefix): void {
    if ($allowedClassIds === null) {
        return;
    }
    if ($allowedClassIds === []) {
        $where[] = '1 = 0';
        return;
    }

    $placeholders = [];
    foreach (array_values($allowedClassIds) as $index => $classId) {
        $placeholder = ':' . $prefix . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = (int)$classId;
    }
    $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
}

// ==================== AJAX Endpoints ====================
if (isset($_GET['ajax'])) {
    // Clear any buffered output to prevent JSON corruption
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        Utilities::validateSession('admin');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'جلسة غير صالحة']);
        exit;
    }
    
    // Get students for a class with existing attendance
    if ($_GET['ajax'] === 'get_students') {
        $class_id = intval($_GET['class_id'] ?? 0);
        $date = is_scalar($_GET['date'] ?? null) ? (string) $_GET['date'] : '';
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$parsedDate
            || (is_array($dateErrors) && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0))
            || $parsedDate->format('Y-m-d') !== $date
            || $parsedDate > new DateTimeImmutable('today')) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'تاريخ الحضور غير صالح أو يقع في المستقبل.']);
            exit;
        }

        try {
            $portalContext->assertClassAllowed($class_id);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        $student_list = attendance_fetch_class_students($db, $class_id, $allowedClassIds);
        
        // Get existing attendance
        $attendanceSql = "SELECT student_id, status, notes FROM attendance WHERE class_id = ? AND attendance_date = ?"
            . ($currentAcademicYearId > 0 ? " AND academic_year_id = ?" : "");
        $att = $db->prepare($attendanceSql);
        $att->execute($currentAcademicYearId > 0
            ? [$class_id, $date, $currentAcademicYearId]
            : [$class_id, $date]);
        $existing = [];
        while ($row = $att->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['student_id']] = $row;
        }
        
        echo json_encode(['success' => true, 'students' => $student_list, 'attendance' => $existing]);
        exit;
    }
    
    // Save attendance
    if ($_GET['ajax'] === 'save_attendance') {
        // Validate CSRF token for AJAX POST request
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($_SESSION['csrf_token']) || !$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            http_response_code(419);
            echo json_encode(['success' => false, 'message' => 'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة وحاول مرة أخرى.']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            if (!is_array($data)) {
                throw new InvalidArgumentException('طلب حفظ الحضور غير صالح.');
            }
            $class_id = (int) ($data['class_id'] ?? 0);
            $date = (string) ($data['date'] ?? '');
            $portalContext->assertClassAllowed($class_id);
            $statuses = $data['statuses'] ?? [];
            $notes = $data['notes'] ?? [];
            if (!is_array($statuses) || !is_array($notes)) {
                throw new Exception('بيانات الحضور غير صالحة.');
            }
            $result = (new StudentAttendanceService($db))->saveClassDay(
                $class_id,
                $currentAcademicYearId,
                (string) $date,
                $statuses,
                $notes,
                (int) ($_SESSION['user_id'] ?? 0),
                $portalContext->role()
            );
            
            echo json_encode(['success' => true, 'message' => "تم حفظ حضور {$result['count']} طالب بنجاح"]);
        } catch (Throwable $e) {
            if ($e instanceof PDOException) {
                error_log('Admin student attendance save failed: ' . $e->getMessage());
                $message = 'تعذر حفظ الحضور بسبب خطأ في قاعدة البيانات. لم يتم اعتماد أي تغيير جزئي.';
            } else {
                $message = $e->getMessage();
            }
            echo json_encode(['success' => false, 'message' => $message]);
        }
        exit;
    }
    
    exit;
}

require_once '../includes/admin_header.php';

// Get filters
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? intval($_GET['class_id']) : '';
$filter_grade = isset($_GET['grade_id']) && $_GET['grade_id'] !== '' ? intval($_GET['grade_id']) : '';
$filter_stage = isset($_GET['stage_id']) && $_GET['stage_id'] !== '' ? intval($_GET['stage_id']) : '';
$filter_student = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? intval($_GET['student_id']) : '';

function validate_date($date, $default) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $default;
}

$filter_date_from = validate_date($_GET['date_from'] ?? '', date('Y-m-01'));
$filter_date_to = validate_date($_GET['date_to'] ?? '', date('Y-m-d'));
$filter_single_date = validate_date($_GET['date'] ?? '', date('Y-m-d'));
$filter_status = htmlspecialchars($_GET['status'] ?? '');
$view = htmlspecialchars($_GET['view'] ?? 'record');

// Get stages, grades, classes for filters
$classListWhere = ["c.status = 'active'"];
$classListParams = [];
attendance_add_class_scope($classListWhere, $classListParams, 'c.id', $allowedClassIds, 'list_class_');
$classListStmt = $db->prepare(
    "SELECT c.id, c.name, c.grade_id FROM classes c WHERE "
    . implode(' AND ', $classListWhere)
    . " ORDER BY c.name"
);
$classListStmt->execute($classListParams);
$classes = $classListStmt->fetchAll(PDO::FETCH_ASSOC);
$visibleGradeIds = array_values(array_unique(array_map('intval', array_column($classes, 'grade_id'))));

if ($allowedClassIds === null) {
    $grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY grade_order")->fetchAll(PDO::FETCH_ASSOC);
    $stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($visibleGradeIds === []) {
    $grades = [];
    $stages = [];
} else {
    $gradePlaceholders = implode(',', array_fill(0, count($visibleGradeIds), '?'));
    $gradeStmt = $db->prepare("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' AND id IN ({$gradePlaceholders}) ORDER BY grade_order");
    $gradeStmt->execute($visibleGradeIds);
    $grades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    $visibleStageIds = array_values(array_unique(array_map('intval', array_column($grades, 'stage_id'))));
    if ($visibleStageIds === []) {
        $stages = [];
    } else {
        $stagePlaceholders = implode(',', array_fill(0, count($visibleStageIds), '?'));
        $stageStmt = $db->prepare("SELECT id, stage_name FROM stages WHERE status = 'active' AND id IN ({$stagePlaceholders}) ORDER BY stage_order");
        $stageStmt->execute($visibleStageIds);
        $stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

foreach ([$filter_class, (int)($_GET['edit_class'] ?? 0)] as $requestedClassId) {
    if ($requestedClassId > 0) {
        $portalContext->assertClassAllowed($requestedClassId);
    }
}
if ($filter_student > 0) {
    $portalContext->assertStudentAllowed($filter_student);
}

// Get students for current class filter if set (used in student absence report dropdown)
$students_in_class = [];
if ($filter_class) {
    $students_in_class = attendance_fetch_class_students($db, $filter_class, $allowedClassIds);
}

// Calculate edit stage and grade if edit_class is requested
$edit_class = $_GET['edit_class'] ?? '';
$edit_date = $_GET['edit_date'] ?? '';
$edit_grade = '';
$edit_stage = '';
if ($edit_class) {
    foreach ($classes as $cls) {
        if ($cls['id'] == $edit_class) {
            $edit_grade = $cls['grade_id'];
            break;
        }
    }
    if ($edit_grade) {
        foreach ($grades as $grd) {
            if ($grd['id'] == $edit_grade) {
                $edit_stage = $grd['stage_id'];
                break;
            }
        }
    }
}

// Overall statistics (dynamically filtered by stage, grade, class, and date)
$stats_where = ["a.attendance_date BETWEEN :stats_date_from AND :stats_date_to"];
$stats_params = [':stats_date_from' => $filter_date_from, ':stats_date_to' => $filter_date_to];
$stats_joins = "";

attendance_add_class_scope($stats_where, $stats_params, 'a.class_id', $allowedClassIds, 'stats_scope_class_');
if ($currentAcademicYearId > 0) {
    $stats_where[] = 'a.academic_year_id = :stats_year_id';
    $stats_params[':stats_year_id'] = $currentAcademicYearId;
}

if ($filter_class) {
    $stats_where[] = "a.class_id = :class_id";
    $stats_params[':class_id'] = $filter_class;
}
if ($filter_grade || $filter_stage) {
    $stats_joins .= " JOIN classes c ON a.class_id = c.id";
}
if ($filter_grade) {
    $stats_where[] = "c.grade_id = :grade_id";
    $stats_params[':grade_id'] = $filter_grade;
}
if ($filter_stage) {
    $stats_joins .= " LEFT JOIN grades g ON c.grade_id = g.id";
    $stats_where[] = "g.stage_id = :stage_id";
    $stats_params[':stage_id'] = $filter_stage;
}

$stats_where_sql = implode(' AND ', $stats_where);
$overall_stats_stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT a.student_id) as total_students,
        COUNT(DISTINCT a.class_id) as total_classes,
        COUNT(DISTINCT a.attendance_date) as total_days,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
        COUNT(*) as total_records
    FROM attendance a
    {$stats_joins}
    WHERE {$stats_where_sql}
");
$overall_stats_stmt->execute($stats_params);
$overall_stats = $overall_stats_stmt->fetch(PDO::FETCH_ASSOC);

$attendance_rate = $overall_stats['total_records'] > 0 
    ? round(($overall_stats['present_count'] / $overall_stats['total_records']) * 100, 1) 
    : 0;

// Build query for detailed view
$where_clauses = ["a.attendance_date BETWEEN :date_from AND :date_to"];
$params = [':date_from' => $filter_date_from, ':date_to' => $filter_date_to];

attendance_add_class_scope($where_clauses, $params, 'a.class_id', $allowedClassIds, 'detail_scope_class_');
if ($currentAcademicYearId > 0) {
    $where_clauses[] = 'a.academic_year_id = :detail_year_id';
    $params[':detail_year_id'] = $currentAcademicYearId;
}

if ($filter_class) {
    $where_clauses[] = "a.class_id = :class_id";
    $params[':class_id'] = $filter_class;
}
if ($filter_grade) {
    $where_clauses[] = "c.grade_id = :grade_id";
    $params[':grade_id'] = $filter_grade;
}
if ($filter_stage) {
    $where_clauses[] = "g.stage_id = :stage_id";
    $params[':stage_id'] = $filter_stage;
}
if ($filter_status) {
    $where_clauses[] = "a.status = :status";
    $params[':status'] = $filter_status;
}

$where_sql = implode(' AND ', $where_clauses);

// Class-level summary
$class_summary = [];
if ($view === 'summary') {
    $stmt = $db->prepare("
        SELECT c.id as class_id, c.name as class_name,
               g.grade_name, s.stage_name,
               COUNT(DISTINCT a.student_id) as students,
               COUNT(DISTINCT a.attendance_date) as days,
               SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
               SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
               SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
               SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
               COUNT(*) as total
        FROM attendance a
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN grades g ON c.grade_id = g.id
        LEFT JOIN stages s ON g.stage_id = s.id
        WHERE {$where_sql}
        GROUP BY c.id, c.name, g.grade_name, s.stage_name
        ORDER BY s.stage_order, g.grade_order, c.name
    ");
    $stmt->execute($params);
    $class_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Detailed records
$detailed_records = [];
if ($view === 'detailed') {
    $stmt = $db->prepare("
        SELECT a.*, u.name as student_name, c.name as class_name,
               recorder.name as recorder_name
        FROM attendance a
        JOIN users u ON a.student_id = u.id
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN grades g ON c.grade_id = g.id
        LEFT JOIN users recorder ON a.recorded_by = recorder.id
        WHERE {$where_sql}
        ORDER BY a.attendance_date DESC, c.name, u.name
        LIMIT 500
    ");
    $stmt->execute($params);
    $detailed_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Absent today
$absent_today = [];
if ($view === 'absent_today') {
    $absent_where = ["a.attendance_date = :attendance_date", "a.status = 'absent'"];
    $absent_params = [':attendance_date' => $filter_single_date];
    attendance_add_class_scope($absent_where, $absent_params, 'a.class_id', $allowedClassIds, 'absent_scope_class_');
    if ($currentAcademicYearId > 0) {
        $absent_where[] = 'a.academic_year_id = :absent_year_id';
        $absent_params[':absent_year_id'] = $currentAcademicYearId;
    }
    if ($filter_class) {
        $absent_where[] = 'a.class_id = :class_id';
        $absent_params[':class_id'] = $filter_class;
    }
    if ($filter_grade) {
        $absent_where[] = 'c.grade_id = :grade_id';
        $absent_params[':grade_id'] = $filter_grade;
    }
    if ($filter_stage) {
        $absent_where[] = 'g.stage_id = :stage_id';
        $absent_params[':stage_id'] = $filter_stage;
    }
    $absent_where_sql = implode(' AND ', $absent_where);
    $stmt = $db->prepare("
        SELECT a.*, u.name as student_name, c.name as class_name
        FROM attendance a
        JOIN users u ON a.student_id = u.id
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN grades g ON c.grade_id = g.id
        WHERE {$absent_where_sql}
        ORDER BY c.name, u.name
    ");
    $stmt->execute($absent_params);
    $absent_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Class Absence Report
$class_absence_records = [];
if ($view === 'class_absence' && $filter_class) {
    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("
            SELECT u.id as student_id, u.name as student_name,
                   COUNT(a.id) as total_days,
                   SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                   SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                   SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                   SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count
            FROM users u
            JOIN student_enrollments se ON se.student_id = u.id
                AND se.academic_year_id = :year_id AND se.enrollment_status = 'enrolled'
            LEFT JOIN attendance a ON u.id = a.student_id
                AND a.attendance_date BETWEEN :date_from AND :date_to
                AND a.academic_year_id = :attendance_year_id
            WHERE se.class_id = :class_id AND u.role = 'student' AND u.status = 'active'
            GROUP BY u.id, u.name
            ORDER BY u.name
        ");
        $stmt->execute([
            ':year_id' => $currentAcademicYearId,
            ':attendance_year_id' => $currentAcademicYearId,
            ':class_id' => $filter_class,
            ':date_from' => $filter_date_from,
            ':date_to' => $filter_date_to
        ]);
    } else {
        $stmt = $db->prepare("
            SELECT u.id as student_id, u.name as student_name,
                   COUNT(a.id) as total_days,
                   SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                   SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                   SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                   SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count
            FROM users u
            LEFT JOIN attendance a ON u.id = a.student_id AND a.attendance_date BETWEEN :date_from AND :date_to
            WHERE u.class_id = :class_id AND u.role = 'student' AND u.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id = u.id AND sp.enrollment_status <> 'enrolled')
            GROUP BY u.id, u.name
            ORDER BY u.name
        ");
        $stmt->execute([
            ':class_id' => $filter_class,
            ':date_from' => $filter_date_from,
            ':date_to' => $filter_date_to
        ]);
    }
    $class_absence_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Student Absence Report
$student_stats = null;
$student_absence_records = [];
if ($view === 'student_absence' && $filter_student) {
    $studentAttendanceWhere = [
        'student_id = :student_id',
        'attendance_date BETWEEN :date_from AND :date_to',
    ];
    $studentAttendanceParams = [
        ':student_id' => $filter_student,
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to,
    ];
    attendance_add_class_scope(
        $studentAttendanceWhere,
        $studentAttendanceParams,
        'class_id',
        $allowedClassIds,
        'student_stats_scope_class_'
    );
    if ($currentAcademicYearId > 0) {
        $studentAttendanceWhere[] = 'academic_year_id = :student_stats_year_id';
        $studentAttendanceParams[':student_stats_year_id'] = $currentAcademicYearId;
    }
    $studentAttendanceWhereSql = implode(' AND ', $studentAttendanceWhere);

    // Stats query
    $stmt = $db->prepare("
        SELECT COUNT(id) as total_days,
               SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
               SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
               SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
               SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count
        FROM attendance
        WHERE {$studentAttendanceWhereSql}
    ");
    $stmt->execute($studentAttendanceParams);
    $student_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Detailed logs query
    $studentDetailWhere = [
        'a.student_id = :student_id',
        'a.attendance_date BETWEEN :date_from AND :date_to',
    ];
    $studentDetailParams = [
        ':student_id' => $filter_student,
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to,
    ];
    attendance_add_class_scope(
        $studentDetailWhere,
        $studentDetailParams,
        'a.class_id',
        $allowedClassIds,
        'student_detail_scope_class_'
    );
    if ($currentAcademicYearId > 0) {
        $studentDetailWhere[] = 'a.academic_year_id = :student_detail_year_id';
        $studentDetailParams[':student_detail_year_id'] = $currentAcademicYearId;
    }
    $studentDetailWhereSql = implode(' AND ', $studentDetailWhere);
    $stmt = $db->prepare("
        SELECT a.*, c.name as class_name, u.name as recorder_name
        FROM attendance a
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN users u ON a.recorded_by = u.id
        WHERE {$studentDetailWhereSql}
        ORDER BY a.attendance_date DESC
    ");
    $stmt->execute($studentDetailParams);
    $student_absence_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new PdfHandler($db);
    $view_title = 'تقرير الحضور والغياب';
    
    if ($view === 'summary') {
        $view_title .= ' - ملخص الفصول';
        $headers = ['المرحلة', 'الصف', 'الفصل', 'الطلاب', 'الأيام', 'حاضر', 'غائب', 'متأخر', 'بإذن', 'نسبة الحضور'];
        $rows = [];
        foreach ($class_summary as $row) {
            $rate = $row['total'] > 0 ? round(($row['present_count'] / $row['total']) * 100, 1) : 0;
            $rows[] = [
                $row['stage_name'] ?? '-',
                $row['grade_name'] ?? '-',
                $row['class_name'],
                $row['students'],
                $row['days'],
                $row['present_count'],
                $row['absent_count'],
                $row['late_count'],
                $row['excused_count'],
                $rate . '%'
            ];
        }
        $pdf->exportTable($headers, $rows, $view_title, 'attendance_summary', ['orientation' => 'landscape']);
    } elseif ($view === 'detailed') {
        $view_title .= ' - سجلات تفصيلية';
        $status_map = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'بإذن'];
        $headers = ['التاريخ', 'الطالب', 'الفصل', 'الحالة', 'ملاحظات', 'المسجل'];
        $rows = [];
        foreach ($detailed_records as $rec) {
            $rows[] = [
                $rec['attendance_date'],
                $rec['student_name'],
                $rec['class_name'],
                $status_map[$rec['status']] ?? $rec['status'],
                $rec['notes'] ?? '-',
                $rec['recorder_name'] ?? '-'
            ];
        }
        $pdf->exportTable($headers, $rows, $view_title, 'attendance_detailed', ['orientation' => 'landscape']);
    } elseif ($view === 'absent_today') {
        $formatted_date = date('Y/m/d', strtotime($filter_single_date));
        $view_title .= ' - الغائبون في تاريخ ' . $formatted_date;
        $headers = ['#', 'اسم الطالب', 'الفصل', 'ملاحظات'];
        $rows = [];
        foreach ($absent_today as $i => $rec) {
            $rows[] = [$i + 1, $rec['student_name'], $rec['class_name'], $rec['notes'] ?? '-'];
        }
        $pdf->exportTable($headers, $rows, $view_title, 'absent_today');
    } elseif ($view === 'class_absence') {
        $view_title .= ' - غياب الفصل';
        $cls_name = '';
        if ($filter_class) {
            foreach ($classes as $cls) {
                if ($cls['id'] == $filter_class) {
                    $cls_name = $cls['name'];
                    break;
                }
            }
        }
        if ($cls_name) {
            $view_title .= " ($cls_name)";
        }
        $headers = ['الطالب', 'إجمالي الأيام', 'حاضر', 'غائب', 'متأخر', 'بإذن', 'نسبة الحضور'];
        $rows = [];
        foreach ($class_absence_records as $row) {
            $row_total = $row['present_count'] + $row['absent_count'] + $row['late_count'] + $row['excused_count'];
            $rate = $row_total > 0 ? round(($row['present_count'] / $row_total) * 100, 1) : 0;
            $rows[] = [
                $row['student_name'],
                $row_total,
                $row['present_count'],
                $row['absent_count'],
                $row['late_count'],
                $row['excused_count'],
                $rate . '%'
            ];
        }
        $pdf->exportTable($headers, $rows, $view_title, 'class_absence_report', ['orientation' => 'landscape']);
    } elseif ($view === 'student_absence') {
        $view_title .= ' - غياب الطالب';
        $std_name = '';
        if ($filter_student && !empty($students_in_class)) {
            foreach ($students_in_class as $std) {
                if ($std['id'] == $filter_student) {
                    $std_name = $std['name'];
                    break;
                }
            }
        }
        if (!$std_name && $filter_student) {
            $std_query = $db->prepare("SELECT name FROM users WHERE id = ?");
            $std_query->execute([$filter_student]);
            $std_name = $std_query->fetchColumn();
        }
        if ($std_name) {
            $view_title .= " ($std_name)";
        }
        $status_map = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'بإذن'];
        $headers = ['التاريخ', 'الفصل', 'الحالة', 'ملاحظات', 'المسجل'];
        $rows = [];
        foreach ($student_absence_records as $rec) {
            $rows[] = [
                $rec['attendance_date'],
                $rec['class_name'],
                $status_map[$rec['status']] ?? $rec['status'],
                $rec['notes'] ?? '-',
                $rec['recorder_name'] ?? '-'
            ];
        }
        $pdf->exportTable($headers, $rows, $view_title, 'student_absence_report', ['orientation' => 'landscape']);
    }
    exit;
}

$status_labels = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'late' => 'متأخر',
    'excused' => 'بإذن'
];
$status_colors = [
    'present' => 'success',
    'absent' => 'danger',
    'late' => 'warning',
    'excused' => 'info'
];
?>

<div class="attendance-page">
<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>الحضور والغياب</h1>
    <div class="admin-top-actions">
        <?php if ($view !== 'record'): ?>
        <a href="#" class="btn btn-header-premium btn-pdf-soft" onclick="exportAttendancePdf()">
            <i class="fas fa-file-pdf me-1"></i>PDF
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-header-premium btn-print-soft" onclick="window.print();">
            <i class="fas fa-print me-1"></i>طباعة
        </button>
    </div>
</div>

    <!-- Overall Stats -->
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-3 mb-4">
        <!-- نسبة الحضور -->
        <div class="col animate-up delay-1">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><span class="counter" data-target="<?php echo $attendance_rate; ?>">0</span>%</div>
                    <div class="stat-card-label">نسبة الحضور</div>
                    <div class="stat-card-sub"><i class="fas fa-percent"></i> معدل الحضور العام</div>
                </div>
            </div>
        </div>
        <!-- حاضر -->
        <div class="col animate-up delay-2">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$overall_stats['present_count']; ?>">0</div>
                    <div class="stat-card-label">حاضر</div>
                    <div class="stat-card-sub"><i class="fas fa-check"></i> إجمالي الحاضرين</div>
                </div>
            </div>
        </div>
        <!-- غائب -->
        <div class="col animate-up delay-3">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-user-times"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$overall_stats['absent_count']; ?>">0</div>
                    <div class="stat-card-label">غائب</div>
                    <div class="stat-card-sub"><i class="fas fa-times"></i> إجمالي الغائبين</div>
                </div>
            </div>
        </div>
        <!-- متأخر -->
        <div class="col animate-up delay-4">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$overall_stats['late_count']; ?>">0</div>
                    <div class="stat-card-label">متأخر</div>
                    <div class="stat-card-sub"><i class="fas fa-hourglass-half"></i> إجمالي حالات التأخر</div>
                </div>
            </div>
        </div>
        <!-- بإذن -->
        <div class="col animate-up delay-5">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <div class="stat-card-icon"><i class="fas fa-user-shield"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$overall_stats['excused_count']; ?>">0</div>
                    <div class="stat-card-label">بإذن</div>
                    <div class="stat-card-sub"><i class="fas fa-envelope-open-text"></i> غياب بعذر مقبول</div>
                </div>
            </div>
        </div>
        <!-- إجمالي السجلات -->
        <div class="col animate-up delay-6">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$overall_stats['total_records']; ?>">0</div>
                    <div class="stat-card-label">إجمالي السجلات</div>
                    <div class="stat-card-sub"><i class="fas fa-database"></i> السجلات المؤرشفة</div>
                </div>
            </div>
        </div>
    </div>


    <!-- View Tabs -->
    <ul class="nav nav-tabs mb-3" id="attendanceTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $view === 'record' ? 'active' : ''; ?>" 
               href="?view=record">
                <i class="fas fa-edit me-1"></i>تسجيل الحضور
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $view === 'absent_today' ? 'active' : ''; ?>" 
               href="?view=absent_today">
                <i class="fas fa-user-times me-1"></i>غياب اليوم
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $view === 'class_absence' ? 'active' : ''; ?>" 
               href="?view=class_absence&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>">
                <i class="fas fa-school me-1"></i>غياب فصل محدد
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $view === 'student_absence' ? 'active' : ''; ?>" 
               href="?view=student_absence&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>">
                <i class="fas fa-user me-1"></i>غياب طالب محدد
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $view === 'detailed' ? 'active' : ''; ?>" 
               href="?view=detailed&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>">
                <i class="fas fa-list me-1"></i>سجلات تفصيلية
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $view === 'summary' ? 'active' : ''; ?>" 
               href="?view=summary&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>">
                <i class="fas fa-chart-bar me-1"></i>ملخص الفصول
            </a>
        </li>
    </ul>



    <?php if ($view === 'record'): ?>
    <!-- Attendance Recording -->
    <div class="admin-work-panel mb-4">
        <div class="admin-work-panel-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 w-100">
                <!-- الفلاتر من جهة اليمين -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select id="recordStageId" class="form-select form-select-sm" style="width: auto; min-width: 120px;" onchange="filterRecordGrades(this.value)">
                        <option value="">جميع المراحل</option>
                        <?php foreach ($stages as $stg): ?>
                            <option value="<?php echo $stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select id="recordGradeId" class="form-select form-select-sm" style="width: auto; min-width: 120px;" onchange="filterRecordClasses(this.value)">
                        <option value="">جميع الصفوف</option>
                        <?php foreach ($grades as $grd): ?>
                            <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>"><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select id="recordClassId" class="form-select form-select-sm" style="width: auto; min-width: 120px;" onchange="hideRecordArea()">
                        <option value="">جميع الفصول</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>"><?php echo htmlspecialchars($cls['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="d-flex align-items-center gap-1">
                        <span class="small text-secondary fw-bold">التاريخ:</span>
                        <input type="text" id="recordDate" class="form-control form-control-sm flatpickr-date" style="width: auto;" placeholder="اختر التاريخ..." value="<?php echo date('Y-m-d'); ?>" onchange="hideRecordArea()" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <!-- الأزرار من جهة اليسار -->
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-light btn-sm" onclick="loadStudentsForRecord()">
                        <i class="fas fa-search me-1"></i>عرض الطلاب
                    </button>
                    <button type="button" class="btn btn-light btn-sm" onclick="resetRecordFilters()" title="إعادة تعيين الفلاتر">
                        <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">

            <div id="recordArea" style="display:none;">
                <!-- Mark All & Save Top -->
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <span class="text-muted small">تحديد الكل:</span>
                        <button type="button" class="btn btn-success btn-sm" onclick="adminMarkAll('present')"><i class="fas fa-check me-1"></i>حاضر</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="adminMarkAll('absent')"><i class="fas fa-times me-1"></i>غائب</button>
                        <button type="button" class="btn btn-warning btn-sm" onclick="adminMarkAll('late')"><i class="fas fa-clock me-1"></i>متأخر</button>
                        <button type="button" class="btn btn-info btn-sm" onclick="adminMarkAll('excused')"><i class="fas fa-file-alt me-1"></i>بإذن</button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span id="recordSaveStatusTop" class="me-2"></span>
                        <button type="button" class="btn btn-primary btn-sm" id="recordSaveBtnTop" onclick="saveAdminAttendance()">
                            <i class="fas fa-save me-1"></i>حفظ الحضور
                        </button>
                    </div>
                </div>

                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover admin-data-table" id="recordTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px">#</th>
                                <th>اسم الطالب</th>
                                <th>الحالة</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody id="recordTableBody">
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <span id="recordInfo" class="text-muted small"></span>
                    <div>
                        <span id="recordSaveStatus" class="me-2"></span>
                        <button type="button" class="btn btn-primary btn-lg" id="recordSaveBtn" onclick="saveAdminAttendance()">
                            <i class="fas fa-save me-2"></i>حفظ الحضور
                        </button>
                    </div>
                </div>
            </div>

            <div id="recordPlaceholder" class="text-center py-4 text-muted">
                <i class="fas fa-hand-pointer fa-3x mb-3"></i>
                <p class="h5">اختر فصلاً وتاريخاً لتسجيل الحضور</p>
            </div>
        </div>
    </div>
    
    <!-- Auto-load if coming from edit link -->
    <?php require __DIR__ . '/../classes/Presentation/Attendance/edit_autoload.php'; ?>

    <?php elseif ($view === 'class_absence'): ?>
    <!-- Class Absence Report Table -->
    <div class="admin-work-panel">
        <div class="admin-work-panel-header">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="mb-0"><i class="fas fa-school me-2"></i>غياب فصل محدد</h5>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-0">
                        <input type="hidden" name="view" value="class_absence">
                        <select name="stage_id" class="form-select form-select-sm" onchange="filterGrades(this.value)" style="width:auto; min-width:110px;">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stages as $stg): ?>
                                <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage == $stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="grade_id" class="form-select form-select-sm" id="gradeFilter" onchange="filterClasses(this.value)" style="width:auto; min-width:110px;">
                            <option value="">كل الصفوف</option>
                            <?php foreach ($grades as $grd): ?>
                                <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade == $grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" class="form-select form-select-sm" id="classFilter" style="width:auto; min-width:110px;">
                            <option value="">كل الفصول</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class == $cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                                <span class="small text-secondary fw-bold">من:</span>
                        <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date" style="width: 125px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_from; ?>">
                                <span class="small text-secondary fw-bold">إلى:</span>
                        <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date" style="width: 125px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_to; ?>">
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>فلترة</button>
                        <a href="?view=class_absence" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!$filter_class): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-school fa-3x mb-3"></i>
                    <p class="h5">يرجى اختيار المرحلة والصف والفصل أولاً لعرض التقرير</p>
                </div>
            <?php elseif (empty($class_absence_records)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>لا توجد سجلات حضور لهذا الفصل في الفترة المحددة</p>
                </div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped mb-0 admin-data-table">
                        <thead class="table-light">
                            <tr>
                                <th>اسم الطالب</th>
                                <th>إجمالي الأيام</th>
                                <th class="text-success">حاضر</th>
                                <th class="text-danger">غائب</th>
                                <th class="text-warning">متأخر</th>
                                <th class="text-info">بإذن</th>
                                <th>نسبة الحضور</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_absence_records as $row): 
                                $row_total = $row['present_count'] + $row['absent_count'] + $row['late_count'] + $row['excused_count'];
                                $rate = $row_total > 0 ? round(($row['present_count'] / $row_total) * 100, 1) : 0;
                                $rate_class = $rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['student_name']); ?></strong></td>
                                <td><?php echo $row_total; ?></td>
                                <td><span class="badge bg-success"><?php echo $row['present_count']; ?></span></td>
                                <td><span class="badge bg-danger"><?php echo $row['absent_count']; ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?php echo $row['late_count']; ?></span></td>
                                <td><span class="badge bg-info"><?php echo $row['excused_count']; ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $rate_class; ?>" style="width: <?php echo $rate; ?>%"></div>
                                        </div>
                                        <span class="text-<?php echo $rate_class; ?> fw-bold small"><?php echo $rate; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($view === 'student_absence'): ?>
    <!-- Student Absence Report -->
    <div class="admin-work-panel">
        <div class="admin-work-panel-header">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="mb-0"><i class="fas fa-user me-2"></i>غياب طالب محدد</h5>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-0">
                        <input type="hidden" name="view" value="student_absence">
                        <select name="stage_id" class="form-select form-select-sm" onchange="filterGrades(this.value)" style="width:auto; min-width:100px;">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stages as $stg): ?>
                                <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage == $stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="grade_id" class="form-select form-select-sm" id="gradeFilter" onchange="filterClasses(this.value)" style="width:auto; min-width:100px;">
                            <option value="">كل الصفوف</option>
                            <?php foreach ($grades as $grd): ?>
                                <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade == $grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" class="form-select form-select-sm" id="classFilter" onchange="loadClassStudents(this.value)" style="width:auto; min-width:100px;">
                            <option value="">اختر الفصل</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class == $cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="student_id" class="form-select form-select-sm" id="studentFilter" style="width:auto; min-width:130px;">
                            <option value="">اختر الطالب</option>
                            <?php foreach ($students_in_class as $std): ?>
                                <option value="<?php echo $std['id']; ?>" <?php echo $filter_student == $std['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($std['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                                <span class="small text-secondary fw-bold">من:</span>
                        <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date" style="width: 115px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_from; ?>">
                                <span class="small text-secondary fw-bold">إلى:</span>
                        <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date" style="width: 115px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_to; ?>">
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>فلترة</button>
                        <a href="?view=student_absence" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (!$filter_student): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-user-tag fa-3x mb-3"></i>
                    <p class="h5">يرجى اختيار الطالب والفترة الزمنية أولاً لعرض التقرير</p>
                </div>
            <?php else: 
                $total_records = $student_stats['present_count'] + $student_stats['absent_count'] + $student_stats['late_count'] + $student_stats['excused_count'];
                $student_rate = $total_records > 0 ? round(($student_stats['present_count'] / $total_records) * 100, 1) : 0;
            ?>
                <!-- Stats Row -->
                <div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4">
                    <div class="col animate-up delay-1">
                        <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
                            <div class="stat-card-icon"><i class="fas fa-chart-pie"></i></div>
                            <div class="stat-card-info">
                                <div class="stat-card-number"><span class="counter" data-target="<?php echo $student_rate; ?>">0</span>%</div>
                                <div class="stat-card-label">نسبة الحضور</div>
                                <div class="stat-card-sub"><i class="fas fa-percent"></i> معدل حضور الطالب</div>
                            </div>
                        </div>
                    </div>
                    <div class="col animate-up delay-2">
                        <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                            <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
                            <div class="stat-card-info">
                                <div class="stat-card-number counter" data-target="<?php echo (int)$student_stats['present_count']; ?>">0</div>
                                <div class="stat-card-label">أيام الحضور</div>
                                <div class="stat-card-sub"><i class="fas fa-check"></i> الحضور الفعلي</div>
                            </div>
                        </div>
                    </div>
                    <div class="col animate-up delay-3">
                        <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
                            <div class="stat-card-icon"><i class="fas fa-user-times"></i></div>
                            <div class="stat-card-info">
                                <div class="stat-card-number counter" data-target="<?php echo (int)$student_stats['absent_count']; ?>">0</div>
                                <div class="stat-card-label">أيام الغياب</div>
                                <div class="stat-card-sub"><i class="fas fa-times"></i> الغياب بدون عذر</div>
                            </div>
                        </div>
                    </div>
                    <div class="col animate-up delay-4">
                        <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                            <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                            <div class="stat-card-info">
                                <div class="stat-card-number counter" data-target="<?php echo (int)$student_stats['late_count']; ?>">0</div>
                                <div class="stat-card-label">أيام التأخر</div>
                                <div class="stat-card-sub"><i class="fas fa-history"></i> التأخر الصباحي</div>
                            </div>
                        </div>
                    </div>
                    <div class="col animate-up delay-5">
                        <div class="stat-card" style="--card-gradient: var(--info-gradient);">
                            <div class="stat-card-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="stat-card-info">
                                <div class="stat-card-number counter" data-target="<?php echo (int)$student_stats['excused_count']; ?>">0</div>
                                <div class="stat-card-label">غياب بإذن</div>
                                <div class="stat-card-sub"><i class="fas fa-envelope-open-text"></i> غياب بعذر مقبول</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Table -->
                <h5 class="mb-3 mt-4"><i class="fas fa-list me-1"></i>سجل الحضور التفصيلي للطالب</h5>
                <?php if (empty($student_absence_records)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <p>لا توجد سجلات حضور لهذا الطالب في الفترة المحددة</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive admin-table-wrap">
                        <table class="table table-hover table-striped mb-0 admin-data-table">
                            <thead class="table-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>الفصل</th>
                                    <th>الحالة</th>
                                    <th>ملاحظات</th>
                                    <th>المسجل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student_absence_records as $rec): ?>
                                <tr>
                                    <td><?php echo $rec['attendance_date']; ?></td>
                                    <td><?php echo htmlspecialchars($rec['class_name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $status_colors[$rec['status']]; ?>">
                                            <?php echo $status_labels[$rec['status']]; ?>
                                        </span>
                                    </td>
                                    <td class="small"><?php echo htmlspecialchars($rec['notes'] ?? '-'); ?></td>
                                    <td class="small text-muted"><?php echo htmlspecialchars($rec['recorder_name'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($view === 'summary'): ?>
    <!-- Class Summary Table -->
    <div class="admin-work-panel">
        <div class="admin-work-panel-header">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>ملخص الفصول</h5>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-0">
                        <input type="hidden" name="view" value="summary">
                        <select name="stage_id" class="form-select form-select-sm" onchange="filterGrades(this.value)" style="width:auto; min-width:110px;">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stages as $stg): ?>
                                <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage == $stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="grade_id" class="form-select form-select-sm" id="gradeFilter" onchange="filterClasses(this.value)" style="width:auto; min-width:110px;">
                            <option value="">كل الصفوف</option>
                            <?php foreach ($grades as $grd): ?>
                                <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade == $grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" class="form-select form-select-sm" id="classFilter" style="width:auto; min-width:110px;">
                            <option value="">كل الفصول</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class == $cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                                <span class="small text-secondary fw-bold">من:</span>
                        <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date" style="width: 125px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_from; ?>">
                                <span class="small text-secondary fw-bold">إلى:</span>
                        <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date" style="width: 125px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_to; ?>">
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>فلترة</button>
                        <a href="?view=summary" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($class_summary)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>لا توجد بيانات حضور في الفترة المحددة</p>
                </div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped mb-0 admin-data-table">
                        <thead class="table-light">
                            <tr>
                                <th>المرحلة</th>
                                <th>الصف</th>
                                <th>الفصل</th>
                                <th>الطلاب</th>
                                <th>الأيام</th>
                                <th class="text-success">حاضر</th>
                                <th class="text-danger">غائب</th>
                                <th class="text-warning">متأخر</th>
                                <th class="text-info">بإذن</th>
                                <th>نسبة الحضور</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_summary as $row): 
                                $rate = $row['total'] > 0 ? round(($row['present_count'] / $row['total']) * 100, 1) : 0;
                                $rate_class = $rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['stage_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['grade_name'] ?? '-'); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['class_name']); ?></strong></td>
                                <td><?php echo $row['students']; ?></td>
                                <td><?php echo $row['days']; ?></td>
                                <td><span class="badge bg-success"><?php echo $row['present_count']; ?></span></td>
                                <td><span class="badge bg-danger"><?php echo $row['absent_count']; ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?php echo $row['late_count']; ?></span></td>
                                <td><span class="badge bg-info"><?php echo $row['excused_count']; ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $rate_class; ?>" style="width: <?php echo $rate; ?>%"></div>
                                        </div>
                                        <span class="text-<?php echo $rate_class; ?> fw-bold small"><?php echo $rate; ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <a href="?view=record&edit_class=<?php echo $row['class_id']; ?>" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="تسجيل / تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php elseif ($view === 'detailed'): ?>
    <!-- Detailed Records -->
    <div class="admin-work-panel">
        <div class="admin-work-panel-header">
            <div class="row align-items-center">
                <div class="col-md-3 d-flex align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>السجلات التفصيلية</h5>
                    <button type="button" class="btn btn-light btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                        <i class="fas fa-cog"></i>
                    </button>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-0">
                        <input type="hidden" name="view" value="detailed">
                        <select name="stage_id" class="form-select form-select-sm" onchange="filterGrades(this.value)" style="width:auto; min-width:100px;">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stages as $stg): ?>
                                <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage == $stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="grade_id" class="form-select form-select-sm" id="gradeFilter" onchange="filterClasses(this.value)" style="width:auto; min-width:100px;">
                            <option value="">كل الصفوف</option>
                            <?php foreach ($grades as $grd): ?>
                                <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade == $grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" class="form-select form-select-sm" id="classFilter" style="width:auto; min-width:100px;">
                            <option value="">كل الفصول</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class == $cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status" class="form-select form-select-sm" style="width:auto; min-width:100px;">
                            <option value="">كل الحالات</option>
                            <?php foreach ($status_labels as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo $filter_status === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                                <span class="small text-secondary fw-bold">من:</span>
                        <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date" style="width: 120px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_from; ?>">
                                <span class="small text-secondary fw-bold">إلى:</span>
                        <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date" style="width: 120px;" placeholder="اختر التاريخ..." value="<?php echo $filter_date_to; ?>">
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>فلترة</button>
                        <a href="?view=detailed" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($detailed_records)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>لا توجد سجلات</p>
                </div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped mb-0 admin-data-table" id="detailedTable">
                        <thead class="table-light">
                            <tr>
                                <th>التاريخ</th>
                                <th>الطالب</th>
                                <th>الفصل</th>
                                <th>الحالة</th>
                                <th>ملاحظات</th>
                                <th>المسجل</th>
                                <th>تعديل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detailed_records as $rec): ?>
            <tr>
                <td class="text-nowrap"><?php echo $rec['attendance_date']; ?></td>
                                <td><strong><?php echo htmlspecialchars($rec['student_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($rec['class_name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_colors[$rec['status']]; ?>">
                                        <?php echo $status_labels[$rec['status']]; ?>
                                    </span>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($rec['notes'] ?? '-'); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($rec['recorder_name'] ?? '-'); ?></td>
                                <td>
                                    <a href="?view=record&edit_class=<?php echo $rec['class_id']; ?>&edit_date=<?php echo $rec['attendance_date']; ?>" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($view === 'absent_today'): ?>
    <!-- Absent Today -->
    <div class="admin-work-panel">
        <div class="admin-work-panel-header">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="mb-0"><i class="fas fa-user-times me-2"></i>الغائبون اليوم <span class="badge bg-light text-primary ms-2"><?php echo count($absent_today); ?></span></h5>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-0">
                        <input type="hidden" name="view" value="absent_today">
                        <select name="stage_id" class="form-select form-select-sm" onchange="filterGrades(this.value)" style="width:auto; min-width:110px;">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stages as $stg): ?>
                                <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage == $stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="grade_id" class="form-select form-select-sm" id="gradeFilter" onchange="filterClasses(this.value)" style="width:auto; min-width:110px;">
                            <option value="">كل الصفوف</option>
                            <?php foreach ($grades as $grd): ?>
                                <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade == $grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" class="form-select form-select-sm" id="classFilter" style="width:auto; min-width:110px;">
                            <option value="">كل الفصول</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class == $cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                                <span class="small text-secondary fw-bold">التاريخ:</span>
                        <input type="text" name="date" class="form-control form-control-sm flatpickr-date" style="width: 135px;" placeholder="اختر التاريخ..." value="<?php echo htmlspecialchars($filter_single_date); ?>">
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>فلترة</button>
                        <a href="?view=absent_today" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($absent_today)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <p class="text-success h5"><?php echo $filter_single_date === date('Y-m-d') ? 'لا يوجد غائبون اليوم!' : 'لا يوجد غائبون في هذا التاريخ!'; ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover mb-0 admin-data-table">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>اسم الطالب</th>
                                <th>الفصل</th>
                                <th>ملاحظات</th>
                                <th>تعديل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($absent_today as $i => $rec): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($rec['student_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($rec['class_name']); ?></td>
                                <td class="small"><?php echo htmlspecialchars($rec['notes'] ?? '-'); ?></td>
                                <td>
                                    <a href="?view=record&edit_class=<?php echo $rec['class_id']; ?>&edit_date=<?php echo $rec['attendance_date']; ?>" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="تعديل الحضور">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<!-- Unsaved Changes Warning Modal -->
<div class="modal fade" id="attendanceUnsavedChangesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view text-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-circle me-2"></i>تنبيه قبل الخروج</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-triangle-exclamation text-warning fa-3x"></i>
                </div>
                <p class="text-center mb-0">لديك بيانات غير محفوظة. إذا غادرت الآن ستفقد التغييرات.</p>
            </div>
            <div class="modal-footer">
                <!-- Cancel button is solid red for alert warnings -->
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>البقاء في الصفحة
                </button>
                <button type="button" class="btn btn-warning" id="attendanceUnsavedLeaveBtn">
                    مغادرة بدون حفظ
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Table Settings Modal (for detailedTable) -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view text-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>اختر الأعمدة التي تريد عرضها في جدول السجلات التفصيلية:</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_date" checked>
                    <label class="form-check-label" for="col_date">التاريخ</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_student" checked>
                    <label class="form-check-label" for="col_student">اسم الطالب</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_class" checked>
                    <label class="form-check-label" for="col_class">الفصل</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_status" checked>
                    <label class="form-check-label" for="col_status">الحالة</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_notes" checked>
                    <label class="form-check-label" for="col_notes">الملاحظات</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="col_recorder" checked>
                    <label class="form-check-label" for="col_recorder">المسجل</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
let attendanceFormDirty = false;
let attendanceBypassUnsavedGuard = false;
let attendanceUnsavedLeaveCallback = null;

function hasAttendanceUnsavedChanges() {
    return attendanceFormDirty;
}

// Add dirty tracking listeners
window.addEventListener('beforeunload', function (e) {
    if (attendanceBypassUnsavedGuard || !hasAttendanceUnsavedChanges()) {
        return;
    }
    e.preventDefault();
    e.returnValue = '';
});

document.addEventListener('click', function (e) {
    const link = e.target.closest('a[href]');
    if (!link) {
        return;
    }
    const href = (link.getAttribute('href') || '').trim();
    if (!href || href.startsWith('#') || href.toLowerCase().startsWith('javascript:') || link.target === '_blank' || link.hasAttribute('download')) {
        return;
    }
    if (attendanceBypassUnsavedGuard || !hasAttendanceUnsavedChanges()) {
        return;
    }
    if (link.closest('#attendanceUnsavedChangesModal')) {
        return;
    }

    e.preventDefault();
    confirmAttendanceUnsavedExit(function () {
        attendanceBypassUnsavedGuard = true;
        window.location.href = href;
    });
});

function confirmAttendanceUnsavedExit(onConfirm) {
    const modalEl = document.getElementById('attendanceUnsavedChangesModal');
    if (!modalEl || !window.bootstrap) {
        return;
    }
    attendanceUnsavedLeaveCallback = onConfirm;
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('attendanceUnsavedLeaveBtn')?.addEventListener('click', function () {
        if (typeof attendanceUnsavedLeaveCallback === 'function') {
            attendanceUnsavedLeaveCallback();
        }
        const modalEl = document.getElementById('attendanceUnsavedChangesModal');
        const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        if (modal) {
            modal.hide();
        }
        attendanceUnsavedLeaveCallback = null;
    });
    
    // Delegate input change for notes input
    document.getElementById('recordTableBody')?.addEventListener('input', function(e) {
        if (e.target && e.target.classList.contains('att-notes')) {
            attendanceFormDirty = true;
        }
    });
});

function exportAttendancePdf() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'pdf');
    window.location.href = 'attendance.php?' + params.toString();
}

function filterGrades(stageId) {
    const gradeSelect = document.getElementById('gradeFilter');
    if (!gradeSelect) return;
    const options = gradeSelect.querySelectorAll('option[data-stage]');
    
    options.forEach(opt => {
        if (!stageId || opt.dataset.stage === stageId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    gradeSelect.value = '';
    filterClasses(''); // Reset classes when stage changes
}

function filterClasses(gradeId) {
    const classSelect = document.getElementById('classFilter');
    if (!classSelect) return;
    const options = classSelect.querySelectorAll('option[data-grade]');
    
    options.forEach(opt => {
        if (!gradeId || opt.dataset.grade === gradeId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    classSelect.value = '';
    
    // Also reset student select if it exists
    const studentSelect = document.getElementById('studentFilter');
    if (studentSelect) {
        studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
    }
}

function loadClassStudents(classId, selectedStudentId = '') {
    const studentSelect = document.getElementById('studentFilter');
    if (!studentSelect) return;
    
    studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
    if (!classId) return;
    
    fetch(`attendance.php?ajax=get_students&class_id=${classId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.students) {
                data.students.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name;
                    if (selectedStudentId && s.id == selectedStudentId) {
                        opt.selected = true;
                    }
                    studentSelect.appendChild(opt);
                });
            }
        })
        .catch(err => console.error('Error loading students:', err));
}

function filterRecordGrades(stageId) {
    const gradeSelect = document.getElementById('recordGradeId');
    const classSelect = document.getElementById('recordClassId');
    if (!gradeSelect) return;
    
    // Reset grade & class selects
    gradeSelect.value = '';
    if (classSelect) classSelect.value = '';
    
    // Hide/show grade options
    const gradeOptions = gradeSelect.querySelectorAll('option[data-stage]');
    gradeOptions.forEach(opt => {
        if (!stageId || opt.dataset.stage === stageId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    
    // Filter classes to hide all (since no grade is selected yet)
    filterRecordClasses('');
    
    // Hide record area if shown
    hideRecordArea();
}

function filterRecordClasses(gradeId) {
    const classSelect = document.getElementById('recordClassId');
    if (!classSelect) return;
    
    // Reset class select
    classSelect.value = '';
    
    // Hide/show class options
    const classOptions = classSelect.querySelectorAll('option[data-grade]');
    classOptions.forEach(opt => {
        if (!gradeId || opt.dataset.grade === gradeId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    
    // Hide record area if shown
    hideRecordArea();
}

function hideRecordArea() {
    const recordArea = document.getElementById('recordArea');
    const recordPlaceholder = document.getElementById('recordPlaceholder');
    if (recordArea) recordArea.style.display = 'none';
    if (recordPlaceholder) recordPlaceholder.style.display = 'block';
}

function resetRecordFilters() {
    const stageSelect = document.getElementById('recordStageId');
    const gradeSelect = document.getElementById('recordGradeId');
    const classSelect = document.getElementById('recordClassId');
    const dateInput = document.getElementById('recordDate');
    
    if (stageSelect) stageSelect.value = '';
    if (gradeSelect) {
        gradeSelect.value = '';
        const gradeOptions = gradeSelect.querySelectorAll('option[data-stage]');
        gradeOptions.forEach(opt => opt.style.display = '');
    }
    if (classSelect) {
        classSelect.value = '';
        const classOptions = classSelect.querySelectorAll('option[data-grade]');
        classOptions.forEach(opt => opt.style.display = '');
    }
    if (dateInput) {
        dateInput.value = '<?php echo date('Y-m-d'); ?>';
    }
    
    attendanceFormDirty = false;
    attendanceBypassUnsavedGuard = false;
    hideRecordArea();
}

// ===== Admin Attendance Recording =====
function loadStudentsForRecord() {
    const classId = document.getElementById('recordClassId')?.value;
    const date = document.getElementById('recordDate')?.value;
    if (!classId || !date) return;
    
    const tbody = document.getElementById('recordTableBody');
    const area = document.getElementById('recordArea');
    const placeholder = document.getElementById('recordPlaceholder');
    
    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3"><i class="fas fa-spinner fa-spin me-2"></i>جاري التحميل...</td></tr>';
    area.style.display = 'block';
    placeholder.style.display = 'none';
    
    fetch(`attendance.php?ajax=get_students&class_id=${classId}&date=${date}`)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(text => {
            try { return JSON.parse(text); } 
            catch(e) { throw new Error('استجابة غير صالحة من السيرفر'); }
        })
        .then(data => {
            if (!data.success || !data.students || data.students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">لا يوجد طلاب في هذا الفصل</td></tr>';
                return;
            }
            
            let html = '';
            data.students.forEach((s, i) => {
                const existing = data.attendance[s.id] || null;
                const status = existing ? existing.status : 'present';
                const notes = existing ? (existing.notes || '') : '';
                
                html += `<tr>
                    <td>${i + 1}</td>
                    <td class="fw-bold">${escapeHtml(s.name)}</td>
                    <td>
                        <input type="hidden" class="att-status" data-student="${s.id}" value="${status}">
                        <div class="d-flex flex-wrap gap-1 admin-status-btns">
                            <button type="button" class="btn btn-sm ${status === 'present' ? 'btn-success' : 'btn-outline-success'}" onclick="setAdminStatus(${s.id}, 'present', this)"><i class="fas fa-check"></i> <span class="status-label">حاضر</span></button>
                            <button type="button" class="btn btn-sm ${status === 'absent' ? 'btn-danger' : 'btn-outline-danger'}" onclick="setAdminStatus(${s.id}, 'absent', this)"><i class="fas fa-times"></i> <span class="status-label">غائب</span></button>
                            <button type="button" class="btn btn-sm ${status === 'late' ? 'btn-warning' : 'btn-outline-warning'}" onclick="setAdminStatus(${s.id}, 'late', this)"><i class="fas fa-clock"></i> <span class="status-label">متأخر</span></button>
                            <button type="button" class="btn btn-sm ${status === 'excused' ? 'btn-info' : 'btn-outline-info'}" onclick="setAdminStatus(${s.id}, 'excused', this)"><i class="fas fa-file-alt"></i> <span class="status-label">بإذن</span></button>
                        </div>
                    </td>
                    <td><input type="text" class="form-control form-control-sm att-notes" data-student="${s.id}" placeholder="ملاحظات..." value="${escapeHtml(notes)}"></td>
                </tr>`;
            });
            
            tbody.innerHTML = html;
            attendanceFormDirty = false;
            attendanceBypassUnsavedGuard = false;
            
            const classSelect = document.getElementById('recordClassId');
            const className = classSelect.options[classSelect.selectedIndex].text;
            document.getElementById('recordInfo').innerHTML = `<i class="fas fa-users me-1"></i>${data.students.length} طالب | <i class="fas fa-school me-1"></i>${escapeHtml(className)} | <i class="fas fa-calendar me-1"></i>${date}`;
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3"><i class="fas fa-exclamation-triangle me-2"></i>' + (err.message || 'حدث خطأ في التحميل') + '</td></tr>';
            console.error('Load students error:', err);
        });
}

function setAdminStatus(studentId, status, btn) {
    const input = document.querySelector(`.att-status[data-student="${studentId}"]`);
    input.value = status;
    
    const container = btn.parentElement;
    container.querySelectorAll('.btn').forEach(b => {
        b.className = b.className.replace(/btn-(success|danger|warning|info)\b/g, (m, type) => 'btn-outline-' + type);
    });
    
    const colorMap = { present: 'success', absent: 'danger', late: 'warning', excused: 'info' };
    btn.className = btn.className.replace('btn-outline-' + colorMap[status], 'btn-' + colorMap[status]);
    
    attendanceFormDirty = true;
}

function adminMarkAll(status) {
    document.querySelectorAll('.att-status').forEach(input => {
        const studentId = input.dataset.student;
        input.value = status;
    });
    
    document.querySelectorAll('.admin-status-btns').forEach(container => {
        container.querySelectorAll('.btn').forEach(b => {
            b.className = b.className.replace(/btn-(success|danger|warning|info)\b/g, (m, type) => 'btn-outline-' + type);
        });
        const colorMap = { present: 'success', absent: 'danger', late: 'warning', excused: 'info' };
        const target = container.querySelector(`[onclick*="'${status}'"]`);
        if (target) {
            target.className = target.className.replace('btn-outline-' + colorMap[status], 'btn-' + colorMap[status]);
        }
    });
    
    attendanceFormDirty = true;
}

function saveAdminAttendance() {
    const classId = document.getElementById('recordClassId').value;
    const date = document.getElementById('recordDate').value;
    const btn = document.getElementById('recordSaveBtn');
    const btnTop = document.getElementById('recordSaveBtnTop');
    const statusEl = document.getElementById('recordSaveStatus');
    const statusElTop = document.getElementById('recordSaveStatusTop');
    
    if (!classId || !date) return;
    
    const statuses = {};
    const notes = {};
    
    document.querySelectorAll('.att-status').forEach(input => {
        statuses[input.dataset.student] = input.value;
    });
    document.querySelectorAll('.att-notes').forEach(input => {
        if (input.value.trim()) notes[input.dataset.student] = input.value.trim();
    });
    
    const disableSaveButtons = (disabled, text, bgClassOld, bgClassNew) => {
        [btn, btnTop].forEach(b => {
            if (b) {
                b.disabled = disabled;
                b.innerHTML = text;
                if (bgClassOld && bgClassNew) {
                    b.classList.replace(bgClassOld, bgClassNew);
                }
            }
        });
    };
    
    disableSaveButtons(true, '<i class="fas fa-spinner fa-spin me-2"></i>جاري الحفظ...');
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    fetch('attendance.php?ajax=save_attendance', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ class_id: classId, date: date, statuses: statuses, notes: notes })
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(text => {
        try { return JSON.parse(text); }
        catch(e) { throw new Error('استجابة غير صالحة من السيرفر'); }
    })
    .then(data => {
        if (data.success) {
            attendanceFormDirty = false;
            const msg = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' + data.message + '</span>';
            if (statusEl) statusEl.innerHTML = msg;
            if (statusElTop) statusElTop.innerHTML = msg;
            
            disableSaveButtons(false, '<i class="fas fa-check me-2"></i>تم الحفظ', 'btn-primary', 'btn-success');
            
            setTimeout(() => {
                [btn, btnTop].forEach(b => {
                    if (b) {
                        b.innerHTML = '<i class="fas fa-save me-2"></i>حفظ الحضور';
                        b.classList.replace('btn-success', 'btn-primary');
                    }
                });
                if (statusEl) statusEl.innerHTML = '';
                if (statusElTop) statusElTop.innerHTML = '';
            }, 3000);
        } else {
            const errMsg = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>' + data.message + '</span>';
            if (statusEl) statusEl.innerHTML = errMsg;
            if (statusElTop) statusElTop.innerHTML = errMsg;
            disableSaveButtons(false, '<i class="fas fa-save me-2"></i>حفظ الحضور');
        }
    })
    .catch(err => {
        const connErr = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>حدث خطأ في الاتصال</span>';
        if (statusEl) statusEl.innerHTML = connErr;
        if (statusElTop) statusElTop.innerHTML = connErr;
        disableSaveButtons(false, '<i class="fas fa-save me-2"></i>حفظ الحضور');
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Navigate to record tab with pre-selected class and date
function editAttendanceRecord(classId, date) {
    window.location.href = `attendance.php?view=record&edit_class=${classId}&edit_date=${date}`;
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof initializeTableColumnSettings === 'function' && document.getElementById('detailedTable')) {
        initializeTableColumnSettings('detailedTable', {
            col_date: 0,
            col_student: 1,
            col_class: 2,
            col_status: 3,
            col_notes: 4,
            col_recorder: 5
        }, 'attendance_detailed_columns');
    }
    
    if (typeof $ !== 'undefined' && $.fn.DataTable && document.getElementById('detailedTable')) {
        $('#detailedTable').DataTable({
            pageLength: 50,
            order: [[0, 'desc']],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
                search: "بحث سريع:", 
                lengthMenu: "عرض _MENU_ سجل",
                info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                paginate: { first: "الأول", last: "الأخير", next: "التالي", previous: "السابق" }
            }
        });
    }
});
</script>

</div>

<?php require_once '../includes/admin_footer.php'; ?>
