<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/excel_handler.php';
require_once '../classes/pdf_handler.php';
require_once '../classes/utilities.php';
require_once '../classes/EvaluationBackupService.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../classes/StaffAcademicScopeService.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');
requireCsrfPost();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$staffScopeService = new StaffAcademicScopeService($db);
$allowedClassIds = $portalContext->allowedClassIds();
$normalizedAllowedClassIds = $allowedClassIds === null ? [] : array_values(array_unique(array_filter(
    array_map('intval', $allowedClassIds),
    static fn(int $id): bool => $id > 0
)));
$reportClassScopeSql = $allowedClassIds === null
    ? '1 = 1'
    : ($normalizedAllowedClassIds === [] ? '1 = 0' : 'e.class_id IN (' . implode(',', $normalizedAllowedClassIds) . ')');
$reportYearScopeSql = $currentAcademicYearId > 0
    ? "(e.academic_year_id = {$currentAcademicYearId} OR e.academic_year_id IS NULL)"
    : '1 = 1';
$reportScopeSql = $reportClassScopeSql . ' AND ' . $reportYearScopeSql;
$classListScopeSql = $allowedClassIds === null
    ? '1 = 1'
    : ($normalizedAllowedClassIds === [] ? '1 = 0' : 'c.id IN (' . implode(',', $normalizedAllowedClassIds) . ')');

if ($portalContext->isScoped() && (($_GET['action'] ?? '') === 'reset')) {
    http_response_code(403);
    exit('تصفير جميع النقاط متاح لمدير النظام فقط.');
}

// Initialize objects
$user = new User($db);
$classroom = new ClassRoom($db);
$evaluation = new Evaluation($db);
$evaluation_type = new EvaluationType($db);
$excel_handler = new ExcelHandler();

// Handle bulk delete if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    try {
        if (isset($_POST['selected_evaluations']) && !empty($_POST['selected_evaluations'])) {
            $selected_ids = $_POST['selected_evaluations'];
            
            // Validate that all IDs are numeric
            $valid_ids = array_filter($selected_ids, 'is_numeric');
            
            if (count($valid_ids) > 0) {
                $valid_ids = array_values(array_unique(array_map('intval', $valid_ids)));
                // Create placeholders for prepared statement
                $placeholders = str_repeat('?,', count($valid_ids) - 1) . '?';
                $db->beginTransaction();
                $snapshotStmt = $db->prepare("SELECT * FROM evaluations WHERE id IN ($placeholders) FOR UPDATE");
                $snapshotStmt->execute($valid_ids);
                $snapshots = $snapshotStmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($snapshots) !== count($valid_ids)) {
                    throw new RuntimeException('One or more evaluations no longer exist.');
                }
                if ($portalContext->isScoped()) {
                    foreach ($snapshots as $snapshot) {
                        $portalContext->assertClassAllowed((int)$snapshot['class_id']);
                        $portalContext->assertStudentAllowed((int)$snapshot['student_id']);
                        $snapshotYearId = (int)($snapshot['academic_year_id'] ?? 0);
                        if ($snapshotYearId > 0 && $snapshotYearId !== $currentAcademicYearId) {
                            throw new RuntimeException('يتضمن الطلب تقييمًا من عام دراسي آخر.');
                        }
                    }
                }

                $delete_stmt = $db->prepare("DELETE FROM evaluations WHERE id IN ($placeholders)");
                if ($delete_stmt->execute($valid_ids)) {
                    $affected_rows = $delete_stmt->rowCount();
                    $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
                    $batchId = \EduCore\Modules\Operations\Audit\AuditContext::requestId();
                    foreach ($snapshots as $snapshot) {
                        $evaluationId = (int)$snapshot['id'];
                        $audit->recordDelete(
                            'evaluation', 'evaluations', $evaluationId,
                            'تقييم #' . $evaluationId, $snapshot,
                            'حذف تقييم ضمن دفعة جماعية', $batchId
                        );
                    }
                    $db->commit();
                    $success_message = "تم حذف " . $affected_rows . " تقييم بنجاح.";
                } else {
                    throw new RuntimeException('Evaluation bulk delete failed.');
                }
            } else {
                $error_message = "لم يتم تحديد تقييمات صالحة للحذف.";
            }
        } else {
            $error_message = "يرجى تحديد التقييمات المراد حذفها.";
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('evaluation bulk delete error: ' . $e->getMessage());
        $error_message = "تعذر حذف التقييمات المحددة.";
    }
    
    // Redirect to prevent resubmission
    $redirect_url = $_SERVER['PHP_SELF'];
    $params = [];
    if (isset($_GET['grade_id'])) $params['grade_id'] = $_GET['grade_id'];
    if (isset($_GET['class_id'])) $params['class_id'] = $_GET['class_id'];
    if (isset($_GET['student_id'])) $params['student_id'] = $_GET['student_id'];
    if (isset($_GET['teacher_id'])) $params['teacher_id'] = $_GET['teacher_id'];
    if (isset($_GET['evaluation_type_id'])) $params['evaluation_type_id'] = $_GET['evaluation_type_id'];
    if (isset($_GET['date_from'])) $params['date_from'] = $_GET['date_from'];
    if (isset($_GET['date_to'])) $params['date_to'] = $_GET['date_to'];
    if (isset($_GET['time_from'])) $params['time_from'] = $_GET['time_from'];
    if (isset($_GET['time_to'])) $params['time_to'] = $_GET['time_to'];
    
    if (!empty($params)) {
        $redirect_url .= '?' . http_build_query($params);
    }
    
    error_log("Redirecting to: " . $redirect_url);
    
    if (isset($success_message)) {
        $_SESSION['bulk_delete_message'] = $success_message;
        $_SESSION['bulk_delete_type'] = 'success';
    } elseif (isset($error_message)) {
        $_SESSION['bulk_delete_message'] = $error_message;
        $_SESSION['bulk_delete_type'] = 'error';
    }
    
    header("Location: " . $redirect_url);
    exit;
}

// Process filter form if submitted
$filter_grade = isset($_GET['grade_id']) && $_GET['grade_id'] !== '' ? $_GET['grade_id'] : null;
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? $_GET['class_id'] : null;
$filter_student = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? $_GET['student_id'] : null;
$filter_teacher = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? $_GET['teacher_id'] : null;
$filter_date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
$filter_date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;
$filter_time_from = isset($_GET['time_from']) && $_GET['time_from'] !== '' ? $_GET['time_from'] : null;
$filter_time_to = isset($_GET['time_to']) && $_GET['time_to'] !== '' ? $_GET['time_to'] : null;
$filter_evaluation_type = isset($_GET['evaluation_type_id']) && $_GET['evaluation_type_id'] !== '' ? $_GET['evaluation_type_id'] : null;

if ($filter_class) {
    $portalContext->assertClassAllowed((int)$filter_class);
}
if ($filter_student) {
    $portalContext->assertStudentAllowed((int)$filter_student);
}
if ($filter_teacher && $portalContext->isScoped()) {
    $allowedTeacherIds = $staffScopeService->allowedTeacherIds(
        $portalContext->userId(),
        $currentAcademicYearId,
        $portalContext->assignedRole()
    );
    if (!in_array((int)$filter_teacher, $allowedTeacherIds, true)) {
        throw new RuntimeException('المعلم المطلوب خارج نطاق الأخصائي.');
    }
}

// Handle PDF export if requested - DO THIS BEFORE ANY HTML OUTPUT
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    try {
        $pdf_handler = new PdfHandler($db);
        
        $query = "SELECT e.id, e.date_created, 
                  s.name as student_name, 
                  t.name as teacher_name,
                  c.name as class_name,
                  g.grade_name,
                  et.name as evaluation_name, 
                  et.type, 
                  et.points,
                  e.custom_points,
                  e.reason,
                  CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END as display_points,
                  CASE WHEN e.custom_points IS NOT NULL THEN CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END ELSE et.type END as display_type
                  FROM evaluations e
                  JOIN users s ON e.student_id = s.id
                  JOIN users t ON e.teacher_id = t.id
                  JOIN classes c ON e.class_id = c.id
                  LEFT JOIN grades g ON c.grade_id = g.id
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE {$reportScopeSql}";
        $params = [];
        if ($filter_grade) { $query .= " AND c.grade_id = :grade_id"; $params[':grade_id'] = $filter_grade; }
        if ($filter_class) { $query .= " AND e.class_id = :class_id"; $params[':class_id'] = $filter_class; }
        if ($filter_student) { $query .= " AND e.student_id = :student_id"; $params[':student_id'] = $filter_student; }
        if ($filter_teacher) { $query .= " AND e.teacher_id = :teacher_id"; $params[':teacher_id'] = $filter_teacher; }
        if ($filter_evaluation_type) { $query .= " AND e.evaluation_type_id = :evaluation_type_id"; $params[':evaluation_type_id'] = $filter_evaluation_type; }
        if ($filter_date_from) { $query .= " AND e.date_created >= :date_from"; $params[':date_from'] = $filter_date_from . ' 00:00:00'; }
        if ($filter_date_to) { $query .= " AND e.date_created <= :date_to"; $params[':date_to'] = $filter_date_to . ' 23:59:59'; }
        $query .= " ORDER BY e.date_created DESC LIMIT 500";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
        $stmt->execute();
        
        $pdf_data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdf_data[] = [
                'student_name' => $row['student_name'],
                'class_name' => ($row['grade_name'] ?: '') . ' - ' . $row['class_name'],
                'type_name' => $row['evaluation_name'],
                'points' => $row['display_points'],
                'reward_type' => $row['display_type'] == 'positive' ? 'إيجابي' : 'سلبي',
                'teacher_name' => $row['teacher_name'],
                'notes' => $row['reason'] ?: '',
                'created_at' => $row['date_created']
            ];
        }
        
        $filters_info = [];
        if ($filter_date_from) $filters_info['date_from'] = $filter_date_from;
        if ($filter_date_to) $filters_info['date_to'] = $filter_date_to;
        
        $pdf_handler->exportEvaluationsReport($pdf_data, $filters_info);
    } catch (Exception $e) {
        die('خطأ في تصدير PDF: ' . $e->getMessage());
    }
}

// Handle Excel export if requested - DO THIS BEFORE ANY HTML OUTPUT
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Log filters for debugging
    error_log("Admin Export filters received: " . print_r($_GET, true));
    
    try {
        // Check database connection first
        if (!$db) {
            throw new Exception('Database connection not available');
        }
        
        // Build query based on filters with custom points handling
        $query = "SELECT e.id, e.date_created, 
                  s.name as student_name, 
                  t.name as teacher_name,
                  c.name as class_name,
                  g.grade_name,
                  et.name as evaluation_name, 
                  et.type, 
                  et.points,
                  e.custom_points,                  e.reason,
                  CASE 
                      WHEN e.custom_points IS NOT NULL THEN 
                          ABS(e.custom_points)
                      ELSE 
                          et.points
                  END as display_points,
                  CASE 
                      WHEN e.custom_points IS NOT NULL THEN 
                          CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END
                      ELSE 
                          et.type
                  END as display_type
                  FROM evaluations e
                  JOIN users s ON e.student_id = s.id
                  JOIN users t ON e.teacher_id = t.id
                  JOIN classes c ON e.class_id = c.id
                  LEFT JOIN grades g ON c.grade_id = g.id
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE {$reportScopeSql}";
    
    $params = [];
    
    if ($filter_grade) {
        $query .= " AND c.grade_id = :grade_id";
        $params[':grade_id'] = $filter_grade;
    }
    
    if ($filter_class) {
        $query .= " AND e.class_id = :class_id";
        $params[':class_id'] = $filter_class;
    }
    
    if ($filter_student) {
        $query .= " AND e.student_id = :student_id";
        $params[':student_id'] = $filter_student;
    }
    
    if ($filter_teacher) {
        $query .= " AND e.teacher_id = :teacher_id";
        $params[':teacher_id'] = $filter_teacher;
    }
    
    if ($filter_evaluation_type) {
        $query .= " AND e.evaluation_type_id = :evaluation_type_id";
        $params[':evaluation_type_id'] = $filter_evaluation_type;
    }
    
    if ($filter_date_from) {
        $query .= " AND e.date_created >= :date_from";
        $params[':date_from'] = $filter_date_from . ' 00:00:00';
    }
    
    if ($filter_date_to) {
        $query .= " AND e.date_created <= :date_to";
        $params[':date_to'] = $filter_date_to . ' 23:59:59';
    }
    
    // فلترة الوقت
    if (!empty($filter_time_from)) {
        $query .= " AND TIME(e.date_created) >= :time_from";
        $params[':time_from'] = $filter_time_from;
    }
    
    if (!empty($filter_time_to)) {
        $query .= " AND TIME(e.date_created) <= :time_to";
        $params[':time_to'] = $filter_time_to;
    }
    
    $query .= " ORDER BY e.date_created DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();      // Prepare data for export with custom points
    $export_data = [];
    $header_row = ['الرقم', 'الطالب', 'المعلم', 'الصف', 'الفصل', 'التقييم', 'النوع', 'النقاط', 'السبب', 'التاريخ'];
    $export_data[] = $header_row;
    
    $row_count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data_row = [
            $row['id'],
            $row['student_name'],
            $row['teacher_name'],
            $row['grade_name'] ?: 'غير محدد',
            $row['class_name'],
            $row['evaluation_name'],
            $row['display_type'] == 'positive' ? 'إيجابي' : 'سلبي',
            ($row['display_type'] == 'positive' ? '+' : '-') . $row['display_points'],
            $row['reason'] ?: 'لا يوجد',
            $row['date_created']
        ];
        $export_data[] = $data_row;
        $row_count++;
    }
    
    // If no data found, add an empty row message
    if ($row_count == 0) {
        $export_data[] = ['لا توجد بيانات', 'للتصدير', 'حسب', 'المرشحات', 'المحددة', '', '', '', ''];
    }      // Generate and download Excel file
    $result = $excel_handler->exportToExcel($export_data, 'تقرير_التقييمات_' . date('Y-m-d'));
    
    // Log export attempt for debugging
    error_log("Admin Reports export attempt - Filters: class_id=$filter_class, student_id=$filter_student, teacher_id=$filter_teacher, eval_type=$filter_evaluation_type, date_from=$filter_date_from, date_to=$filter_date_to");
    error_log("Admin Reports export attempt - Data rows: $row_count, Result: " . ($result ? $result : 'NULL'));
    
    // If the export returned a filename (fallback CSV), download it
    if ($result && file_exists($result)) {
        // Clear any output that might interfere with file download
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Set headers for file download with enhanced Arabic support
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($result) . '"');
        header('Cache-Control: max-age=0');
        header('Content-Length: ' . filesize($result));
        
        // Output file content
        readfile($result);
        
        // Clean up temporary file
        unlink($result);
        exit;
    } else {
        // Export failed - show error message
        error_log("Reports export failed - Result: " . var_export($result, true));
        die("فشل في إنشاء ملف التصدير. تحقق من أن المجلد قابل للكتابة والبيانات متوفرة.");
    }
      } catch (Exception $e) {
        // Log error and show user-friendly message
        error_log("Excel Export Error in evaluation_reports.php: " . $e->getMessage());
        
        // If database is not available, create a sample export
        if (strpos($e->getMessage(), 'Database') !== false || strpos($e->getMessage(), 'connection') !== false) {
            try {
                $sample_export_data = [
                    ['الرقم', 'الطالب', 'المعلم', 'الفصل', 'التقييم', 'النوع', 'النقاط', 'السبب', 'التاريخ'],
                    ['عذراً', 'قاعدة البيانات', 'غير متاحة', 'حالياً', 'يرجى المحاولة', 'لاحقاً', '', '', date('Y-m-d H:i:s')]
                ];
                
                $result = $excel_handler->exportToExcel($sample_export_data, 'تقرير_عذر_' . date('Y-m-d'));
                
                if ($result && file_exists($result)) {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . basename($result) . '"');
                    header('Cache-Control: max-age=0');
                    header('Content-Length: ' . filesize($result));
                    readfile($result);
                    unlink($result);
                    exit;
                }
            } catch (Exception $e2) {
                die("خطأ في النظام: " . $e2->getMessage());
            }
        }
          die("خطأ في تصدير Excel: " . $e->getMessage());
    }
}

// If we reach here, it means we're not exporting, so include header and prepare data
// Set page title
$page_title = "التقارير والإحصائيات";
$custom_page_title = true; // This page has its own custom title

// Include header
include_once '../includes/admin_header.php';

// Retrieve flash messages from session if any
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['alert_message']) && isset($_SESSION['alert_type'])) {
    if ($_SESSION['alert_type'] == 'success') {
        $success_message = $_SESSION['alert_message'];
    } else {
        $error_message = $_SESSION['alert_message'];
    }
    unset($_SESSION['alert_message']);
    unset($_SESSION['alert_type']);
}

// Handle bulk delete messages
if (isset($_SESSION['bulk_delete_message']) && isset($_SESSION['bulk_delete_type'])) {
    if ($_SESSION['bulk_delete_type'] == 'success') {
        $success_message = $_SESSION['bulk_delete_message'];
    } else {
        $error_message = $_SESSION['bulk_delete_message'];
    }
    unset($_SESSION['bulk_delete_message']);
    unset($_SESSION['bulk_delete_type']);
}

// Prepare grades options
$grades_query = "SELECT DISTINCT g.id, g.grade_name, g.grade_code
    FROM grades g JOIN classes c ON c.grade_id = g.id
    WHERE c.academic_year_id = ? AND {$classListScopeSql} ORDER BY g.grade_order";
$grades_stmt = $db->prepare($grades_query);
$grades_stmt->execute([$currentAcademicYearId]);
$grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare class options
$classesStmt = $db->prepare("SELECT c.* FROM classes c
    WHERE c.academic_year_id = ? AND {$classListScopeSql} ORDER BY c.name");
$classesStmt->execute([$currentAcademicYearId]);
$classes = $classesStmt;

// Prepare students options
$studentWhere = ["se.academic_year_id = ?", "se.enrollment_status = 'enrolled'", "u.role = 'student'", "u.deleted_at IS NULL"];
$studentParams = [$currentAcademicYearId];
if ($allowedClassIds !== null) {
    $studentWhere[] = $normalizedAllowedClassIds === []
        ? '1 = 0'
        : 'se.class_id IN (' . implode(',', $normalizedAllowedClassIds) . ')';
}
if ($filter_class) {
    $studentWhere[] = 'se.class_id = ?';
    $studentParams[] = (int)$filter_class;
}
$studentsStmt = $db->prepare("SELECT u.*, se.class_id FROM student_enrollments se
    JOIN users u ON u.id = se.student_id WHERE " . implode(' AND ', $studentWhere) . ' ORDER BY u.name');
$studentsStmt->execute($studentParams);
$students = $studentsStmt;

// Prepare teachers and specialists options (only active)
if ($portalContext->isScoped()) {
    $allowedTeacherIds = $staffScopeService->allowedTeacherIds(
        $portalContext->userId(),
        $currentAcademicYearId,
        $portalContext->assignedRole()
    );
    if ($allowedTeacherIds === []) {
        $teachers = [];
    } else {
        $teacherMarks = implode(',', array_fill(0, count($allowedTeacherIds), '?'));
        $teachersStmt = $db->prepare("SELECT u.* FROM users u
            WHERE u.status = 'active' AND u.id IN ({$teacherMarks})
              AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')
            ORDER BY u.name");
        $teachersStmt->execute($allowedTeacherIds);
        $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $teachers_list = $user->readActiveByRole('teacher');
    $specialists_list = $user->readActiveByRole('specialist');
    $teachers = array_merge($teachers_list, $specialists_list);
}

// Prepare evaluation types options
$evaluation_types = $evaluation_type->readAll();

// Get summary data for dashboard with custom points handling
// 1. Total positive points (including custom points)
$query_positive = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN e.custom_points IS NOT NULL THEN 
                            CASE WHEN e.custom_points > 0 THEN e.custom_points ELSE 0 END
                        ELSE 
                            CASE WHEN et.type = 'positive' THEN et.points ELSE 0 END
                    END
                  ), 0) as total
                  FROM evaluations e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE {$reportScopeSql}";
$stmt_positive = $db->prepare($query_positive);
$stmt_positive->execute();
$total_positive = $stmt_positive->fetch(PDO::FETCH_ASSOC)['total'];

// 2. Total negative points (including custom points)
$query_negative = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN e.custom_points IS NOT NULL THEN 
                            CASE WHEN e.custom_points < 0 THEN ABS(e.custom_points) ELSE 0 END
                        ELSE 
                            CASE WHEN et.type = 'negative' THEN et.points ELSE 0 END
                    END
                  ), 0) as total
                  FROM evaluations e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE {$reportScopeSql}";
$stmt_negative = $db->prepare($query_negative);
$stmt_negative->execute();
$total_negative = $stmt_negative->fetch(PDO::FETCH_ASSOC)['total'];

// 3. Total evaluations
$query_total = "SELECT COUNT(*) as total FROM evaluations e WHERE {$reportScopeSql}";
$stmt_total = $db->prepare($query_total);
$stmt_total->execute();
$total_evaluations = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

// 4. Recent trend (last 7 days)
$query_trend = "SELECT DATE(date_created) as date, 
               SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) as positive_count,
               SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END) as negative_count
               FROM evaluations e
               JOIN evaluation_types et ON e.evaluation_type_id = et.id
               WHERE {$reportScopeSql} AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
               GROUP BY DATE(date_created)
               ORDER BY date ASC";
$stmt_trend = $db->prepare($query_trend);
$stmt_trend->execute();
$trend_data = $stmt_trend->fetchAll(PDO::FETCH_ASSOC);

// 5. Top performing students (most positive points) with custom points handling
$query_top_students = "SELECT s.id, s.name, 
                      COALESCE(SUM(
                        CASE 
                            WHEN e.custom_points IS NOT NULL THEN 
                                CASE WHEN e.custom_points > 0 THEN e.custom_points ELSE 0 END
                            ELSE 
                                CASE WHEN et.type = 'positive' THEN et.points ELSE 0 END
                        END
                      ), 0) as positive_points,
                      COALESCE(SUM(
                        CASE 
                            WHEN e.custom_points IS NOT NULL THEN 
                                CASE WHEN e.custom_points < 0 THEN ABS(e.custom_points) ELSE 0 END
                            ELSE 
                                CASE WHEN et.type = 'negative' THEN et.points ELSE 0 END
                        END
                      ), 0) as negative_points,
                      COALESCE(SUM(
                        CASE 
                            WHEN e.custom_points IS NOT NULL THEN 
                                e.custom_points
                            ELSE 
                                CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                        END
                      ), 0) as total_points
                      FROM evaluations e
                      JOIN users s ON e.student_id = s.id
                      JOIN evaluation_types et ON e.evaluation_type_id = et.id
                      WHERE {$reportScopeSql}
                      GROUP BY s.id
                      ORDER BY total_points DESC
                      LIMIT 5";
$stmt_top_students = $db->prepare($query_top_students);
$stmt_top_students->execute();
$top_students = $stmt_top_students->fetchAll(PDO::FETCH_ASSOC);

// 6. Most used evaluation types
$query_top_evaluations = "SELECT et.id, et.name, et.type, COUNT(*) as count
                         FROM evaluations e
                         JOIN evaluation_types et ON e.evaluation_type_id = et.id
                         WHERE {$reportScopeSql}
                         GROUP BY et.id
                         ORDER BY count DESC
                         LIMIT 5";
$stmt_top_evaluations = $db->prepare($query_top_evaluations);
$stmt_top_evaluations->execute();
$top_evaluations = $stmt_top_evaluations->fetchAll(PDO::FETCH_ASSOC);

// Build query for filtered evaluations with custom points handling
$query = "SELECT e.id, e.date_created, 
          s.name as student_name, 
          t.name as teacher_name,
          c.name as class_name,
          g.grade_name,
          et.name as evaluation_name, 
          et.type, 
          et.points,
          e.custom_points,
          e.reason,
          CASE 
              WHEN e.custom_points IS NOT NULL THEN 
                  ABS(e.custom_points)
              ELSE 
                  et.points
          END as display_points,
          CASE 
              WHEN e.custom_points IS NOT NULL THEN 
                  CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END
              ELSE 
                  et.type
          END as display_type
          FROM evaluations e
          JOIN users s ON e.student_id = s.id
          JOIN users t ON e.teacher_id = t.id
          JOIN classes c ON e.class_id = c.id
          LEFT JOIN grades g ON c.grade_id = g.id
          JOIN evaluation_types et ON e.evaluation_type_id = et.id
          WHERE {$reportScopeSql}";

$params = [];

if ($filter_grade) {
    $query .= " AND c.grade_id = :grade_id";
    $params[':grade_id'] = $filter_grade;
}

if ($filter_class) {
    $query .= " AND e.class_id = :class_id";
    $params[':class_id'] = $filter_class;
}

if ($filter_student) {
    $query .= " AND e.student_id = :student_id";
    $params[':student_id'] = $filter_student;
}

if ($filter_teacher) {
    $query .= " AND e.teacher_id = :teacher_id";
    $params[':teacher_id'] = $filter_teacher;
}

if ($filter_evaluation_type) {
    $query .= " AND e.evaluation_type_id = :evaluation_type_id";
    $params[':evaluation_type_id'] = $filter_evaluation_type;
}

if ($filter_date_from) {
    $query .= " AND e.date_created >= :date_from";
    $params[':date_from'] = $filter_date_from . ' 00:00:00';
}

if ($filter_date_to) {
    $query .= " AND e.date_created <= :date_to";
    $params[':date_to'] = $filter_date_to . ' 23:59:59';
}

$query .= " ORDER BY e.date_created DESC";

// Note: The actual data is loaded via AJAX DataTables - no need to execute here
?>

<div class="admin-page-heading animate-up">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-chart-bar me-2 text-primary"></i>التقارير والإحصائيات</h1>
        <p class="text-muted m-0">تحليل وتقارير شاملة عن التقييمات والسلوك الطلابي</p>
    </div>
    <div class="admin-top-actions no-print">
        <button id="exportBtn" class="btn btn-header-premium btn-export-soft shadow-sm" onclick="exportToExcel()">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </button>
        <button class="btn btn-header-premium btn-pdf-soft shadow-sm" onclick="exportToPdf()">
            <i class="fas fa-file-pdf me-1"></i>تصدير PDF
        </button>
        <button type="button" class="btn btn-header-premium btn-print-soft shadow-sm" onclick="window.print();">
            <i class="fas fa-print me-1"></i>طباعة
        </button>
    </div>
</div>

<!-- Alerts Container -->
<div id="alertContainer">
<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        <?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
</div>


            
            <!-- Unified Filter Form & Action Bar -->
            <form method="GET" action="evaluation_reports.php" class="admin-filter-bar no-print mb-4" id="filterForm" novalidate>
                <!-- All Filter Inputs in One Unified Flex Container for Seamless Natural Flow -->
                <div class="admin-filter-controls w-100 mb-2">
                    <!-- الصف الدراسي -->
                    <select class="form-select form-select-sm admin-inline-select-sm" id="grade_id" name="grade_id" aria-label="الصف الدراسي">
                        <option value="">-- جميع الصفوف --</option>
                        <?php
                        foreach ($grades as $grade) {
                            $selected = ($filter_grade == $grade['id']) ? 'selected' : '';
                            echo '<option value="' . $grade['id'] . '" ' . $selected . '>' . htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>
                    
                    <!-- الفصل -->
                    <select class="form-select form-select-sm admin-inline-select-sm" id="class_id" name="class_id" aria-label="الفصل">
                        <option value="">-- جميع الفصول --</option>
                        <?php
                        while ($class = $classes->fetch(PDO::FETCH_ASSOC)) {
                            $selected = ($filter_class == $class['id']) ? 'selected' : '';
                            echo '<option value="' . $class['id'] . '" ' . $selected . '>' . htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>
                    
                    <!-- المعلم/الأخصائي -->
                    <select class="form-select form-select-sm admin-inline-select-sm" id="teacher_id" name="teacher_id" aria-label="المعلم/الأخصائي">
                        <option value="">-- جميع المعلمين --</option>
                        <?php
                        foreach ($teachers as $teacher) {
                            $selected = ($filter_teacher == $teacher['id']) ? 'selected' : '';
                            $status_badge = ($teacher['status'] == 'active') ? '(نشط)' : '(معطل)';
                            $role_badge = '';
                            if (isset($teacher['role'])) {
                                $role_badge = ($teacher['role'] == 'specialist') ? ' [أخصائي]' : ' [معلم]';
                            }
                            echo '<option value="' . $teacher['id'] . '" ' . $selected . '>' . htmlspecialchars($teacher['name'], ENT_QUOTES, 'UTF-8') . $role_badge . ' ' . $status_badge . '</option>';
                        }
                        ?>
                    </select>
                    
                    <!-- نوع التقييم -->
                    <select class="form-select form-select-sm admin-inline-select-sm" id="evaluation_type_id" name="evaluation_type_id" aria-label="نوع التقييم">
                        <option value="">-- جميع الأنواع --</option>
                        <?php
                        while ($et = $evaluation_types->fetch(PDO::FETCH_ASSOC)) {
                            $selected = ($filter_evaluation_type == $et['id']) ? 'selected' : '';
                            $type_label = ($et['type'] == 'positive') ? '(إيجابي)' : '(سلبي)';
                            echo '<option value="' . $et['id'] . '" ' . $selected . '>' . htmlspecialchars($et['name'], ENT_QUOTES, 'UTF-8') . ' ' . $type_label . '</option>';
                        }
                        ?>
                    </select>
                    
                    <!-- الطالب -->
                    <select class="form-select form-select-sm admin-inline-select-sm" id="student_id" name="student_id" aria-label="الطالب">
                        <option value="">-- جميع الطلاب --</option>
                        <?php
                        foreach ($students as $student) {
                            $selected = ($filter_student == $student['id']) ? 'selected' : '';
                            echo '<option value="' . $student['id'] . '" ' . $selected . '>' . htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>

                    <!-- التاريخ والوقت بعد الطالب مباشرة بنفس الحاوية المتدفقة -->
                    <input type="text" class="form-control form-control-sm flatpickr-date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filter_date_from ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="من تاريخ" title="من تاريخ" aria-label="من تاريخ">
                    <input type="text" class="form-control form-control-sm flatpickr-date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filter_date_to ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="إلى تاريخ" title="إلى تاريخ" aria-label="إلى تاريخ">
                    <input type="time" class="form-control form-control-sm" id="time_from" name="time_from" value="<?php echo htmlspecialchars($filter_time_from ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="من وقت" title="من وقت" aria-label="من وقت">
                    <input type="time" class="form-control form-control-sm" id="time_to" name="time_to" value="<?php echo htmlspecialchars($filter_time_to ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="إلى وقت" title="إلى وقت" aria-label="إلى وقت">
                </div>

                <!-- Row 3: Action Buttons (Right: Selection & Delete, Left: Reset & Search) -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 w-100">
                    <div class="admin-filter-actions">
                        <!-- أزرار التحديد والحذف -->
                        <button type="button" id="selectAll" class="btn btn-light btn-sm">
                            <i class="fas fa-check-square me-1 text-primary" id="selectAllIcon"></i>
                            <span id="selectAllText">تحديد الصفحة الحالية</span>
                            <span class="badge bg-primary text-white ms-2 px-2 py-1 rounded-pill fw-bold" id="selectedCount">0</span>
                        </button>
                        <button type="button" name="bulk_delete_btn" class="btn btn-light btn-sm" id="bulkDeleteBtn" disabled>
                            <i class="fas fa-trash me-1 text-danger"></i>حذف المحدد
                        </button>
                    </div>
                    
                    <div class="admin-filter-actions ms-auto">
                        <a href="evaluation_reports.php" class="btn btn-light btn-sm" id="resetFilter">
                            <i class="fas fa-rotate-left me-1 text-secondary"></i>إعادة تعيين
                        </a>
                        <button type="submit" class="btn btn-light btn-sm">
                            <i class="fas fa-search me-1 text-primary"></i>عرض النتائج
                        </button>
                    </div>
                </div>
            </form>
            
            
            <!-- Filtered Evaluations Results -->
            <div class="admin-list-surface mb-4 animate-up delay-2">
                
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped align-middle admin-data-table" id="evaluationsTable">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="masterCheckbox" class="form-check-input" title="تحديد سجلات الصفحة الحالية" aria-label="تحديد سجلات الصفحة الحالية">
                                </th>
                                <th>الرقم</th>
                                <th>الطالب</th>
                                <th>المعلم</th>
                                <th>الصف</th>
                                <th>الفصل</th>
                                <th>التقييم</th>
                                <th>النوع</th>
                                <th>النقاط</th>
                                <th>التاريخ</th>
                                <th class="text-center actions-column admin-table-actions">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

<!-- Custom CSS to fix DataTables pagination and info outside table -->
<style>
    /* Ensure DataTables pagination and info appear outside table */
    .table-responsive {
        overflow-x: auto;
        margin-bottom: 0 !important;
    }
    
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        padding: 15px 0 !important;
        margin-top: 10px !important;
    }
    
    .dataTables_wrapper .dataTables_length {
        padding: 10px 0 !important;
    }
    
    /* Ensure pagination is always visible */
    .dataTables_wrapper .dataTables_paginate {
        text-align: left !important;
        clear: both !important;
    }
    
    /* تثبيت التظليل عند الضغط على الصف - لون أحمر فاتح */
    #evaluationsTable tbody tr.row-selected,
    #evaluationsTable tbody tr.row-selected:hover {
        background-color: #ffe6e6 !important;
    }
    
    #evaluationsTable tbody tr.row-selected td,
    #evaluationsTable tbody tr.row-selected:hover td {
        background-color: #ffe6e6 !important;
        border-color: #ffcccc !important;
    }
    
    /* تظليل الصفوف عند المرور عليها - لون أزرق فاتح */
    #evaluationsTable tbody tr:not(.row-selected):hover {
        background-color: #cce5ff !important;
    }
    
    #evaluationsTable tbody tr:not(.row-selected):hover td {
        background-color: #cce5ff !important;
    }
    
    #evaluationsTable tbody tr {
        cursor: pointer;
        transition: background-color 0.15s ease-in-out;
    }
</style>

<!-- JavaScript for DataTables and Filters -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin reports JavaScript loaded successfully');
    
    // Helper to display alerts smoothly inside #alertContainer
    function showAlert(html) {
        const container = document.getElementById('alertContainer');
        if (container) {
            container.innerHTML = html;
            container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else if ($('.admin-page-heading').length) {
            $('.admin-page-heading').after(html);
        }
    }

    // Initialize DataTable (server-side)
    const dataTable = $('#evaluationsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: '../includes/ajax_handlers.php',
            type: 'GET',
            data: function(d) {
                d.action = 'admin_reports_datatable';
                d.grade_id = $('#grade_id').val();
                d.class_id = $('#class_id').val();
                d.student_id = $('#student_id').val();
                d.teacher_id = $('#teacher_id').val();
                d.evaluation_type_id = $('#evaluation_type_id').val();
                d.date_from = $('#date_from').val();
                d.date_to = $('#date_to').val();
                d.time_from = $('#time_from').val();
                d.time_to = $('#time_to').val();
            }
        },
        columns: [
            { data: 0, orderable: false }, // checkbox
            { data: 1 }, // id
            { data: 2 }, // student
            { data: 3 }, // teacher
            { data: 4 }, // grade
            { data: 5 }, // class
            { data: 6 }, // evaluation + reason
            { data: 7 }, // type badge
            { data: 8 }, // points badge
            { data: 9 }, // date
            { data: 10, orderable: false } // actions
        ],
        "language": {
            "search": "البحث:",
            "lengthMenu": "عرض _MENU_ مدخلات",
            "info": "عرض _START_ إلى _END_ من أصل _TOTAL_ مدخل",
            "infoEmpty": "عرض 0 إلى 0 من أصل 0 مدخل",
            "infoFiltered": "(منقح من _MAX_ مدخل إجمالي)",
            "loadingRecords": "جاري التحميل...",
            "zeroRecords": "لم يتم العثور على أي سجلات مطابقة",
            "emptyTable": "لا توجد بيانات متاحة في الجدول",
            "paginate": {
                "first": "الأول",
                "last": "الأخير",
                "next": "التالي",
                "previous": "السابق"
            },
            "aria": {
                "sortAscending": ": تنشيط لترتيب العمود تصاعديًا",
                "sortDescending": ": تنشيط لترتيب العمود تنازليًا"
            },
            "decimal": ".",
            "thousands": ","
        },
        "order": [[9, "desc"]], // Sort by date column (updated index)
        "pageLength": 50,
        "lengthMenu": [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, "الكل"]],
        "columnDefs": [
            { targets: [0, 10], orderable: false }, // Disable sorting for checkbox and actions columns
            { targets: 10, className: 'text-center actions-column admin-table-actions' },
            { targets: 0, className: 'text-center' },
            { targets: [1, 2, 3, 4, 5, 6, 7, 8, 9], className: 'text-right' }
        ]
    });
    
    // Dynamic Cascading Dropdowns & Instant Live Filter Reload
    $('#grade_id').on('change', function() {
        const gradeId = $(this).val();

        // Update Classrooms Dropdown
        $.ajax({
            url: '../includes/ajax_handlers.php',
            type: 'GET',
            data: { action: 'get_classrooms', grade_id: gradeId },
            dataType: 'json',
            success: function(response) {
                const classSelect = $('#class_id');
                const currentVal = classSelect.val();
                classSelect.empty().append('<option value="">-- جميع الفصول --</option>');
                const addedIds = new Set();
                if (Array.isArray(response)) {
                    response.forEach(function(cls) {
                        const idStr = String(cls.id);
                        if (!addedIds.has(idStr)) {
                            addedIds.add(idStr);
                            const selected = (idStr === String(currentVal)) ? 'selected' : '';
                            classSelect.append('<option value="' + cls.id + '" ' + selected + '>' + cls.name + '</option>');
                        }
                    });
                }
            }
        });

        // Update Teachers Dropdown
        $.ajax({
            url: '../includes/ajax_handlers.php',
            type: 'GET',
            data: { action: 'get_teachers_by_class', grade_id: gradeId },
            dataType: 'json',
            success: function(response) {
                const teacherSelect = $('#teacher_id');
                const currentVal = teacherSelect.val();
                teacherSelect.empty().append('<option value="">-- جميع المعلمين --</option>');
                const addedIds = new Set();
                if (Array.isArray(response)) {
                    response.forEach(function(tch) {
                        const idStr = String(tch.id);
                        if (!addedIds.has(idStr)) {
                            addedIds.add(idStr);
                            const selected = (idStr === String(currentVal)) ? 'selected' : '';
                            teacherSelect.append('<option value="' + tch.id + '" ' + selected + '>' + tch.name + '</option>');
                        }
                    });
                }
            }
        });

        // Update Students Dropdown
        $.ajax({
            url: '../includes/ajax_handlers.php',
            type: 'GET',
            data: { action: 'get_students_by_class', grade_id: gradeId },
            dataType: 'json',
            success: function(response) {
                const studentSelect = $('#student_id');
                const currentVal = studentSelect.val();
                studentSelect.empty().append('<option value="">-- جميع الطلاب --</option>');
                const addedIds = new Set();
                if (Array.isArray(response)) {
                    response.forEach(function(st) {
                        const idStr = String(st.id);
                        if (!addedIds.has(idStr)) {
                            addedIds.add(idStr);
                            const selected = (idStr === String(currentVal)) ? 'selected' : '';
                            studentSelect.append('<option value="' + st.id + '" ' + selected + '>' + st.name + '</option>');
                        }
                    });
                }
            }
        });

        dataTable.ajax.reload();
    });

    $('#class_id').on('change', function() {
        const classId = $(this).val();
        const gradeId = $('#grade_id').val();

        // Update Teachers Dropdown by Class
        $.ajax({
            url: '../includes/ajax_handlers.php',
            type: 'GET',
            data: { action: 'get_teachers_by_class', class_id: classId, grade_id: gradeId },
            dataType: 'json',
            success: function(response) {
                const teacherSelect = $('#teacher_id');
                const currentVal = teacherSelect.val();
                teacherSelect.empty().append('<option value="">-- جميع المعلمين --</option>');
                const addedIds = new Set();
                if (Array.isArray(response)) {
                    response.forEach(function(tch) {
                        const idStr = String(tch.id);
                        if (!addedIds.has(idStr)) {
                            addedIds.add(idStr);
                            const selected = (idStr === String(currentVal)) ? 'selected' : '';
                            teacherSelect.append('<option value="' + tch.id + '" ' + selected + '>' + tch.name + '</option>');
                        }
                    });
                }
            }
        });

        // Update Students Dropdown by Class
        $.ajax({
            url: '../includes/ajax_handlers.php',
            type: 'GET',
            data: { action: 'get_students_by_class', class_id: classId, grade_id: gradeId },
            dataType: 'json',
            success: function(response) {
                const studentSelect = $('#student_id');
                const currentVal = studentSelect.val();
                studentSelect.empty().append('<option value="">-- جميع الطلاب --</option>');
                const addedIds = new Set();
                if (Array.isArray(response)) {
                    response.forEach(function(st) {
                        const idStr = String(st.id);
                        if (!addedIds.has(idStr)) {
                            addedIds.add(idStr);
                            const selected = (idStr === String(currentVal)) ? 'selected' : '';
                            studentSelect.append('<option value="' + st.id + '" ' + selected + '>' + st.name + '</option>');
                        }
                    });
                }
            }
        });

        dataTable.ajax.reload();
    });

    // Auto-reload table on any other filter change
    $('#teacher_id, #evaluation_type_id, #student_id, #date_from, #date_to, #time_from, #time_to').on('change', function() {
        dataTable.ajax.reload();
    });

    // Bulk selection functionality with persistence across pagination
    const selectedIds = new Set();
    const masterCheckbox = document.getElementById('masterCheckbox');
    const selectAllBtn = document.getElementById('selectAll');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    const selectAllText = document.getElementById('selectAllText');
    const selectAllIcon = document.getElementById('selectAllIcon');
    
    // Update selected count and button state
    function updateSelectionState() {
        const pageCheckboxes = $('#evaluationsTable').find('.evaluation-checkbox');
        let pageChecked = 0;
        pageCheckboxes.each(function() {
            const id = this.value;
            if (selectedIds.has(id)) {
                this.checked = true;
                pageChecked++;
            } else {
                this.checked = false;
            }
        });

        const totalSelected = selectedIds.size;
        if (selectedCountSpan) selectedCountSpan.textContent = totalSelected;
        if (bulkDeleteBtn) bulkDeleteBtn.disabled = totalSelected === 0;

        const allPageSelected = pageCheckboxes.length > 0 && pageChecked === pageCheckboxes.length;
        if (totalSelected > 0) {
            if (selectedCountSpan) {
                selectedCountSpan.className = 'badge bg-primary text-white ms-2 px-2 py-1 rounded-pill fw-bold';
            }
        } else {
            if (selectedCountSpan) {
                selectedCountSpan.className = 'badge bg-secondary text-white ms-2 px-2 py-1 rounded-pill fw-bold';
            }
        }
        if (selectAllIcon) {
            selectAllIcon.className = allPageSelected
                ? 'fas fa-square me-1 text-primary'
                : 'fas fa-check-square me-1 text-primary';
        }
        if (selectAllText) {
            selectAllText.textContent = allPageSelected ? 'إلغاء تحديد الصفحة الحالية' : 'تحديد الصفحة الحالية';
        }

        if (masterCheckbox) {
            if (pageCheckboxes.length === 0) {
                masterCheckbox.indeterminate = false;
                masterCheckbox.checked = false;
            } else if (pageChecked === 0) {
                masterCheckbox.indeterminate = false;
                masterCheckbox.checked = false;
            } else if (pageChecked === pageCheckboxes.length) {
                masterCheckbox.indeterminate = false;
                masterCheckbox.checked = true;
            } else {
                masterCheckbox.indeterminate = true;
            }
        }
    }
    
    // Master checkbox functionality
    if (masterCheckbox) {
        masterCheckbox.addEventListener('change', function() {
            const pageCheckboxes = $('#evaluationsTable').find('.evaluation-checkbox');
            pageCheckboxes.each(function() {
                this.checked = masterCheckbox.checked;
                const id = this.value;
                if (this.checked) {
                    selectedIds.add(id);
                } else {
                    selectedIds.delete(id);
                }
            });
            updateSelectionState();
        });
    }
    
    // Handle individual checkbox changes (using event delegation for DataTable compatibility)
    $(document).on('change', '.evaluation-checkbox', function() {
        const id = this.value;
        if (this.checked) {
            selectedIds.add(id);
        } else {
            selectedIds.delete(id);
        }
        updateSelectionState();
    });
    
    // Select / Deselect toggle button
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            const pageCheckboxes = $('#evaluationsTable').find('.evaluation-checkbox');
            const allPageSelected = pageCheckboxes.length > 0
                && pageCheckboxes.filter(':checked').length === pageCheckboxes.length;
            if (allPageSelected) {
                pageCheckboxes.each(function() {
                    this.checked = false;
                    selectedIds.delete(this.value);
                });
            } else {
                pageCheckboxes.each(function() {
                    this.checked = true;
                    selectedIds.add(this.value);
                });
            }
            updateSelectionState();
        });
    }

    function clearEvaluationSelection() {
        if (selectedIds.size === 0) return;
        selectedIds.clear();
        updateSelectionState();
    }

    $('#grade_id, #class_id, #teacher_id, #evaluation_type_id, #student_id, #date_from, #date_to, #time_from, #time_to')
        .on('change', clearEvaluationSelection);
    $('form[action="evaluation_reports.php"]').on('submit', clearEvaluationSelection);
    dataTable.on('search.dt', clearEvaluationSelection);
    
    // Bulk delete button click handler
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function(e) {
        if (selectedIds.size === 0) {
                // Show no selection modal instead of alert
                const noSelectionModal = new bootstrap.Modal(document.getElementById('noSelectionModal'));
                noSelectionModal.show();
                return;
            }
            
            // Update count in modal and show modal
            document.getElementById('bulkDeleteCount').textContent = selectedIds.size;
            const bulkDeleteModal = new bootstrap.Modal(document.getElementById('bulkDeleteModal'));
            bulkDeleteModal.show();
        });
    }
    
    // Remove the old form submit handler since we're using button click now
    // if (bulkDeleteForm) {
    //     bulkDeleteForm.addEventListener('submit', function(e) {
    //         ...
    //     });
    // }
    
    // Handle bulk delete confirmation from modal
    document.getElementById('confirmBulkDelete').addEventListener('click', function() {
        console.log('Admin: Bulk delete button clicked');
        const confirmBtn = this;
        const originalText = confirmBtn.innerHTML;
        
        // Show loading state
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري الحذف...';
        confirmBtn.disabled = true;
        
        // Convert Set to Array for sending
        const idsArray = Array.from(selectedIds);
        console.log('Admin: Deleting evaluations:', idsArray);
        console.log('Admin: JSON stringified:', JSON.stringify(idsArray));
        
        // Send AJAX request - send as JSON string
        $.ajax({
            url: '../includes/ajax_handlers.php',
            type: 'POST',
            data: {
                action: 'bulk_delete_evaluations_admin',
                selected_evaluations: JSON.stringify(idsArray)
            },
            dataType: 'json',
            success: function(response) {
                console.log('Admin: Delete response:', response);
                
                // Hide modal
                const bulkDeleteModal = bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal'));
                bulkDeleteModal.hide();
                
                // Reset button
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
                
                if (response.success) {
                    // Show success message
                    const alertHtml = `
                        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            ${response.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    showAlert(alertHtml);
                    
                    // Clear selected IDs
                    selectedIds.clear();
                    
                    console.log('Admin: About to reload table...');
                    // Reload the DataTable
                    dataTable.ajax.reload(null, false);
                } else {
                    // Show error message
                    const alertHtml = `
                        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${response.message || 'حدث خطأ أثناء حذف التقييمات'}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    showAlert(alertHtml);
                }
            },
            error: function(xhr, status, error) {
                console.error('Admin: AJAX Error:', {xhr: xhr, status: status, error: error});
                console.error('Admin: Response Text:', xhr.responseText);
                console.error('Admin: Status Code:', xhr.status);
                console.error('Admin: Ready State:', xhr.readyState);
                
                // Try to parse response as JSON
                try {
                    const jsonResponse = JSON.parse(xhr.responseText);
                    console.error('Admin: Parsed JSON:', jsonResponse);
                } catch (e) {
                    console.error('Admin: Response is not JSON');
                }
                
                // Hide modal
                const bulkDeleteModal = bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal'));
                bulkDeleteModal.hide();
                
                // Reset button
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
                
                // Show error message
                const alertHtml = `
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        حدث خطأ أثناء الاتصال بالخادم<br>
                        <small>Status: ${status}, Error: ${error}, Code: ${xhr.status}</small><br>
                        <small>Response: ${xhr.responseText ? xhr.responseText.substring(0, 200) : 'empty'}</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                showAlert(alertHtml);
            }
        });
    });
    
    // Initialize selection state and sync on draw
    dataTable.on('draw', function() { updateSelectionState(); });
    updateSelectionState();
    
    // تثبيت التظليل عند الضغط على الصف
    // Click to toggle row highlighting (not on checkbox or action buttons)
    $('#evaluationsTable tbody').on('click', 'tr', function(e) {
        // Don't toggle if clicking on checkbox, buttons, or links
        if ($(e.target).is('input[type="checkbox"], button, a, .btn') || 
            $(e.target).closest('button, a, .btn').length > 0) {
            return;
        }
        
        $(this).toggleClass('row-selected');
    });
    
    // Update students and teachers dropdown when class changes
    $('#class_id').change(function() {
        console.log('Admin: Class changed!');
        const classId = $(this).val();
        console.log('Admin: Selected class ID:', classId);
        
        var studentSelect = $('#student_id');
        var teacherSelect = $('#teacher_id');
        
        // Reset student and teacher selections
        studentSelect.html('<option value="">-- جميع الطلاب --</option>');
        teacherSelect.html('<option value="">-- الجميع --</option>');
        
        if (classId) {
            // Update students list
            console.log('Admin: Loading students for class', classId);
            studentSelect.html('<option value="">جاري تحميل الطلاب...</option>');
            $.ajax({
                url: '../includes/ajax_handlers.php',
                type: 'POST',
                data: {
                    action: 'get_students_by_class',
                    class_id: classId
                },
                dataType: 'json',
                beforeSend: function() {
                    console.log('Admin: AJAX request started for students');
                },
                success: function(data) {
                    console.log('Admin: Students AJAX success:', data);
                    let options = '<option value="">-- جميع الطلاب --</option>';
                    if (data && Array.isArray(data)) {
                        console.log('Admin: Data is array with length:', data.length);
                        data.forEach(function(student) {
                            options += `<option value="${student.id}">${student.name}</option>`;
                        });
                    } else if (data && data.students && Array.isArray(data.students)) {
                        console.log('Admin: Data.students is array with length:', data.students.length);
                        data.students.forEach(function(student) {
                            options += `<option value="${student.id}">${student.name}</option>`;
                        });
                    } else {
                        console.log('Admin: Unexpected students data format:', data);
                    }
                    studentSelect.html(options);
                },
                error: function(xhr, status, error) {
                    console.error('Admin: Students AJAX error:', error);
                    studentSelect.html('<option value="">-- جميع الطلاب --</option>');
                    studentSelect.append('<option value="">خطأ في التحميل</option>');
                }
            });
            
            // Update teachers and specialists list
            console.log('Admin: Loading teachers and specialists for class', classId);
            teacherSelect.html('<option value="">جاري التحميل...</option>');
            $.ajax({
                url: '../includes/ajax_handlers.php',
                type: 'POST',
                data: {
                    action: 'get_teachers_by_class',
                    class_id: classId
                },
                dataType: 'json',
                beforeSend: function() {
                    console.log('Admin: AJAX request started for teachers');
                },
                success: function(teachers) {
                    console.log('Admin: Teachers AJAX success:', teachers);
                    teacherSelect.html('<option value="">-- الجميع --</option>');
                    if (teachers && teachers.length > 0) {
                        console.log('Admin: Adding', teachers.length, 'teachers/specialists to dropdown');
                        teachers.forEach(function(teacher) {
                            var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
                            var roleBadge = teacher.role === 'specialist' ? ' [أخصائي]' : ' [معلم]';
                            teacherSelect.append(
                                $('<option></option>')
                                    .attr('value', teacher.id)
                                    .text(teacher.name + roleBadge + ' ' + statusBadge)
                            );
                        });
                    } else {
                        console.log('Admin: No teachers/specialists found for this class');
                        teacherSelect.append('<option value="">لا يوجد معلمين/أخصائيين في هذا الفصل</option>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Admin: Teachers AJAX error:', error);
                    teacherSelect.html('<option value="">-- جميع المعلمين --</option>');
                    teacherSelect.append('<option value="">خطأ في التحميل</option>');
                }
            });
        } else {
            console.log('Admin: No class selected, loading all students and teachers');
            
            // If no class selected, get all students
            $.ajax({
                url: '../includes/ajax_handlers.php',
                type: 'POST',
                data: {
                    action: 'get_all_students'
                },
                dataType: 'json',
                success: function(data) {
                    console.log('Admin: Get all students success:', data);
                    let options = '<option value="">-- جميع الطلاب --</option>';
                    if (data && Array.isArray(data)) {
                        console.log('Admin: All students data is array with length:', data.length);
                        data.forEach(function(student) {
                            options += `<option value="${student.id}">${student.name}</option>`;
                        });
                    } else if (data && data.students && Array.isArray(data.students)) {
                        console.log('Admin: All students data.students is array with length:', data.students.length);
                        data.students.forEach(function(student) {
                            options += `<option value="${student.id}">${student.name}</option>`;
                        });
                    } else {
                        console.log('Admin: Unexpected data format for all students:', data);
                    }
                    studentSelect.html(options);
                },
                error: function(xhr, status, error) {
                    console.error('Admin: Get all students AJAX error:', error);
                }
            });
            
            // Get all teachers and specialists
            $.ajax({
                url: '../includes/ajax_handlers.php',
                type: 'POST',
                data: {
                    action: 'get_all_teachers'
                },
                dataType: 'json',
                success: function(teachers) {
                    console.log('Admin: Get all teachers/specialists success:', teachers);
                    if (teachers && teachers.length > 0) {
                        teachers.forEach(function(teacher) {
                            var statusBadge = (teacher.status === 'active') ? '(نشط)' : '(معطل)';
                            var roleBadge = teacher.role === 'specialist' ? ' [أخصائي]' : ' [معلم]';
                            teacherSelect.append(
                                $('<option></option>')
                                    .attr('value', teacher.id)
                                    .text(teacher.name + roleBadge + ' ' + statusBadge)
                            );
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Admin: Get all teachers/specialists AJAX error:', error);
                }
            });
        }
    });

    // Reload on filter submit
    $('form[action="evaluation_reports.php"]').on('submit', function(e) {
        e.preventDefault();
        dataTable.ajax.reload();
    });

    // Handle delete evaluation button click
    document.addEventListener('click', function(e) {
        if (e.target && e.target.closest('.delete-evaluation-btn')) {
            const btn = e.target.closest('.delete-evaluation-btn');
            const evaluationId = btn.getAttribute('data-id');
            const studentName = btn.getAttribute('data-student-name') || 'غير محدد';
            const evaluationType = btn.getAttribute('data-evaluation-type') || 'غير محدد';
            
            // Set modal data
            document.getElementById('delete_evaluation_id').value = evaluationId;
            document.getElementById('delete_evaluation_details').innerHTML =
                `<strong>الطالب:</strong> ${studentName}<br>` +
                `<strong>نوع التقييم:</strong> ${evaluationType}`;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('deleteEvaluationModal'));
            modal.show();
        }
    });
    
    // Handle delete confirmation
    document.getElementById('confirmDeleteEvaluation').addEventListener('click', function() {
        const evaluationId = document.getElementById('delete_evaluation_id').value;
        
        fetch('../includes/ajax_handlers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: `action=delete_evaluation_from_report&evaluation_id=${encodeURIComponent(evaluationId)}`
        })
        .then(response => response.json())
        .then(data => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteEvaluationModal'));
            modal.hide();
            
            if (data.success) {
                // Show success message with Bootstrap alert
                const alertHtml = `
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                showAlert(alertHtml);
                
                // Reload the DataTable without page reload
                dataTable.ajax.reload(null, false);
            } else {
                // Show error message
                const alertHtml = `
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                showAlert(alertHtml);
            }
        })
        .catch(error => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteEvaluationModal'));
            modal.hide();
            
            const alertHtml = `
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    حدث خطأ أثناء حذف التقييم.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            showAlert(alertHtml);
        });
    });
});

// Function to export to PDF with current filters
function exportToPdf() {
    const gradeId = $('#grade_id').val();
    const classId = $('#class_id').val();
    const studentId = $('#student_id').val();
    const teacherId = $('#teacher_id').val();
    const evaluationTypeId = $('#evaluation_type_id').val();
    const dateFrom = $('#date_from').val();
    const dateTo = $('#date_to').val();
    
    let exportUrl = 'evaluation_reports.php?export=pdf';
    if (gradeId) exportUrl += '&grade_id=' + encodeURIComponent(gradeId);
    if (classId) exportUrl += '&class_id=' + encodeURIComponent(classId);
    if (studentId) exportUrl += '&student_id=' + encodeURIComponent(studentId);
    if (teacherId) exportUrl += '&teacher_id=' + encodeURIComponent(teacherId);
    if (evaluationTypeId) exportUrl += '&evaluation_type_id=' + encodeURIComponent(evaluationTypeId);
    if (dateFrom) exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) exportUrl += '&date_to=' + encodeURIComponent(dateTo);
    
    window.location.href = exportUrl;
}

// Function to export to Excel with current filters
function exportToExcel() {
    console.log('Export function called');
    
    // Get current filter values from form
    const gradeId = $('#grade_id').val();
    const classId = $('#class_id').val();
    const studentId = $('#student_id').val();
    const teacherId = $('#teacher_id').val();
    const evaluationTypeId = $('#evaluation_type_id').val();
    const dateFrom = $('#date_from').val();
    const dateTo = $('#date_to').val();
    const timeFrom = $('#time_from').val();
    const timeTo = $('#time_to').val();
    
    // Build export URL with current filters
    let exportUrl = 'evaluation_reports.php?export=excel';
    
    if (gradeId) {
        exportUrl += '&grade_id=' + encodeURIComponent(gradeId);
    }
    if (classId) {
        exportUrl += '&class_id=' + encodeURIComponent(classId);
    }
    if (studentId) {
        exportUrl += '&student_id=' + encodeURIComponent(studentId);
    }
    if (teacherId) {
        exportUrl += '&teacher_id=' + encodeURIComponent(teacherId);
    }
    if (evaluationTypeId) {
        exportUrl += '&evaluation_type_id=' + encodeURIComponent(evaluationTypeId);
    }
    if (dateFrom) {
        exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
    }
    if (dateTo) {
        exportUrl += '&date_to=' + encodeURIComponent(dateTo);
    }
    if (timeFrom) {
        exportUrl += '&time_from=' + encodeURIComponent(timeFrom);
    }
    if (timeTo) {
        exportUrl += '&time_to=' + encodeURIComponent(timeTo);
    }
    
    console.log('Export URL:', exportUrl);
    
    // Navigate to export URL
    window.location.href = exportUrl;
}

// Show reset confirmation modal if action=reset is requested
<?php if (isset($_GET['action']) && $_GET['action'] == 'reset'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const resetModal = new bootstrap.Modal(document.getElementById('resetPointsModal'));
    resetModal.show();
});
<?php endif; ?>
</script>

<!-- Bulk Delete Confirmation Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-labelledby="bulkDeleteModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header modal-header-delete">
                <h5 class="modal-title" id="bulkDeleteModalLabel">
                    <i class="fas fa-trash me-2"></i>تأكيد الحذف المجمع
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>تحذير:</strong> هذا الإجراء لا يمكن التراجع عنه!
                </div>
                <p>هل أنت متأكد من حذف <strong><span id="bulkDeleteCount">0</span></strong> تقييم؟</p>
                <p class="text-muted small">سيتم حذف التقييمات المحددة نهائياً من النظام.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-danger" id="confirmBulkDelete">
                    <i class="fas fa-trash me-1"></i>تأكيد الحذف
                </button>
            </div>
        </div>
    </div>
</div>

<!-- No Selection Warning Modal -->
<div class="modal fade" id="noSelectionModal" tabindex="-1" aria-labelledby="noSelectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header modal-header-warning">
                <h5 class="modal-title" id="noSelectionModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>تنبيه
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <i class="fas fa-info-circle fa-3x text-warning mb-3"></i>
                <p class="mb-0">يرجى تحديد التقييمات المراد حذفها أولاً</p>
                <small class="text-muted">استخدم صناديق الاختيار بجانب التقييمات لتحديدها</small>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>فهمت
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Points Confirmation Modal -->
<div class="modal fade" id="resetPointsModal" tabindex="-1" aria-labelledby="resetPointsModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <div class="modal-header modal-header-delete">
                <h5 class="modal-title" id="resetPointsModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>تأكيد تصفير جميع النقاط
                </h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <h6><i class="fas fa-warning me-2"></i>تحذير مهم!</h6>
                    <p>هذا الإجراء سيؤدي إلى:</p>
                    <ul>
                        <li><strong>حذف جميع التقييمات والنقاط</strong> لجميع الطلاب</li>
                        <li><strong>إعادة تعيين نقاط جميع الطلاب إلى الصفر</strong></li>
                        <li><strong>فقدان سجل التقييمات بالكامل</strong></li>
                    </ul>
                </div>
                
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle me-2"></i>ملاحظة:</h6>
                    <p>سيتم إنشاء نسخة احتياطية تلقائياً قبل الحذف.</p>
                </div>
                
                <form method="POST" action="evaluation_reports.php?action=reset" id="resetForm" data-confirm-message="هل أنت متأكد 100% من تصفير جميع النقاط؟ هذا الإجراء لا يمكن التراجع عنه!" data-confirm-operation="delete">
                    <div class="mb-3">
                        <label for="confirm_reset" class="form-label">
                            <strong>لتأكيد العملية، اكتب النص التالي بالضبط:</strong>
                        </label>
                        <div class="bg-light p-2 rounded mb-2">
                            <code>CONFIRM_RESET_ALL_POINTS</code>
                        </div>
                        <input type="text" class="form-control" id="confirm_reset" name="confirm_reset" 
                               placeholder="اكتب النص هنا..." required autocomplete="off">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-grid">
                                <a href="evaluation_reports.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-1"></i>إلغاء
                                </a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger" id="confirmResetBtn" disabled>
                                    <i class="fas fa-eraser me-1"></i>تأكيد تصفير النقاط
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Enable/disable confirm button based on text input
document.getElementById('confirm_reset').addEventListener('input', function() {
    const confirmText = this.value;
    const confirmBtn = document.getElementById('confirmResetBtn');
    
    if (confirmText === 'CONFIRM_RESET_ALL_POINTS') {
        confirmBtn.disabled = false;
        confirmBtn.classList.remove('btn-secondary');
        confirmBtn.classList.add('btn-danger');
    } else {
        confirmBtn.disabled = true;
        confirmBtn.classList.remove('btn-danger');
        confirmBtn.classList.add('btn-secondary');
    }
});

</script>

<!-- Delete Evaluation Modal -->
<div class="modal fade" id="deleteEvaluationModal" tabindex="-1" aria-labelledby="deleteEvaluationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header modal-header-delete">
                <h5 class="modal-title" id="deleteEvaluationModalLabel">
                    <i class="fas fa-trash-alt me-2"></i>حذف تقييم
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف هذا التقييم؟</p>
                <div class="alert alert-info" id="delete_evaluation_details">
                    <!-- سيتم ملء التفاصيل هنا بواسطة JavaScript -->
                </div>
                <p class="text-danger text-center mb-0">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    هذا الإجراء لا يمكن التراجع عنه.
                </p>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="delete_evaluation_id" name="delete_evaluation_id">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteEvaluation">حذف</button>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include_once '../includes/admin_footer.php';

// Handle reset points action with security and confirmation
if (isset($_GET['action']) && $_GET['action'] == 'reset') {
    // Check if this is a POST request with confirmation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
        // Double check confirmation
        if ($_POST['confirm_reset'] === 'CONFIRM_RESET_ALL_POINTS') {
            try {
                $backupService = new EvaluationBackupService($db);
                $resetResult = $backupService->resetAll(
                    (int)$_SESSION['user_id'],
                    (string)($_SESSION['name'] ?? '')
                );
                $total_evaluations = (int)$resetResult['total_evaluations'];
                $delete_result = true;
                
                if ($delete_result) {
                    // Log the action
                    error_log("ADMIN ACTION: All evaluations reset by admin. Total deleted: " . $total_evaluations . " records. Timestamp: " . date('Y-m-d H:i:s'));
                    
                    // Set success message
                    Utilities::setFlashMessage("تم تصفير جميع النقاط بنجاح! تم حذف $total_evaluations تقييم. تم إنشاء نسخة احتياطية.", "success");
                    
                    // Redirect to prevent resubmission
                    header("Location: evaluation_reports.php");
                    exit;
                } else {
                    throw new Exception("فشل في تصفير النقاط");
                }
                
            } catch (Exception $e) {
                // Set error message
                Utilities::setFlashMessage("خطأ في تصفير النقاط: " . $e->getMessage(), "danger");
                
                // Redirect back
                header("Location: evaluation_reports.php");
                exit;
            }
        } else {
            // Invalid confirmation
            Utilities::setFlashMessage("كلمة التأكيد غير صحيحة. لم يتم تصفير النقاط.", "warning");
            header("Location: evaluation_reports.php");
            exit;
        }
    }
}
?>
