<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Set page title
$page_title = "لوحة التحكم";
$custom_page_title = true;

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$scopedLandingPages = [
    'specialist' => 'specialist_dashboard.php',
    'doctor' => 'student_clinic.php',
    'librarian' => 'library.php',
];
$sessionRole = (string)($_SESSION['role'] ?? '');
if (isset($scopedLandingPages[$sessionRole])) {
    $allowedPages = Utilities::getAllowedAdminPagesForRole($sessionRole);
    $landingPage = $scopedLandingPages[$sessionRole];
    if (is_array($allowedPages) && in_array($landingPage, $allowedPages, true)) {
        header('Location: ' . $landingPage);
        exit;
    }

    include_once '../includes/admin_header.php';
    ?>
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-shield-alt me-2 text-primary"></i>بوابة العاملين</h1>
    </div>
    <div class="alert alert-info" role="alert">
        <i class="fas fa-info-circle me-2"></i>
        تم إنشاء الحساب، ولم تُفعّل صفحات هذا الدور بعد. راجع مسؤول النظام.
    </div>
    <?php
    require_once '../includes/admin_footer.php';
    exit;
}
require_once __DIR__ . '/../classes/AcademicYear.php';
$currentAcademicYearId = AcademicYear::currentId($db);

function dashboard_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function dashboard_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

// Include header
include_once '../includes/admin_header.php';

// Get counts for dashboard
// 1. Count classes
$class = new ClassRoom($db);
if ($currentAcademicYearId > 0) {
    $classCountStmt = $db->prepare('SELECT COUNT(*) FROM classes WHERE academic_year_id = ? OR academic_year_id IS NULL');
    $classCountStmt->execute([$currentAcademicYearId]);
    $class_count = (int) $classCountStmt->fetchColumn();
} else {
    $class_count = method_exists($class, 'countAll') ? $class->countAll() : $class->readAll()->rowCount();
}

// 2. Count teachers (active and inactive)
$user = new User($db);
$active_teacher_count = $user->countActiveTeachers();
$inactive_teacher_count = $user->countInactiveTeachers();
$total_teacher_count = $active_teacher_count + $inactive_teacher_count;

// 3. Count students (active, inactive, graduated)
if ($currentAcademicYearId > 0) {
    $stmt_active_students = $db->prepare("SELECT COUNT(*) FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        WHERE se.academic_year_id = ?
          AND se.enrollment_status = 'enrolled'
          AND se.academic_status <> 'graduated'
          AND u.role = 'student'
          AND u.status = 'active'
          AND u.deleted_at IS NULL");
    $stmt_active_students->execute([$currentAcademicYearId]);
    $active_student_count = (int) $stmt_active_students->fetchColumn();

    $stmt_graduated_students = $db->prepare("SELECT COUNT(*) FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        WHERE se.academic_year_id = ?
          AND (se.academic_status = 'graduated' OR se.enrollment_status = 'graduated')
          AND u.role = 'student'
          AND u.deleted_at IS NULL");
    $stmt_graduated_students->execute([$currentAcademicYearId]);
    $graduated_student_count = (int) $stmt_graduated_students->fetchColumn();

    $stmt_inactive_students = $db->prepare("SELECT COUNT(*) FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        WHERE se.academic_year_id = ?
          AND se.enrollment_status = 'enrolled'
          AND u.role = 'student'
          AND u.status = 'inactive'
          AND u.deleted_at IS NULL");
    $stmt_inactive_students->execute([$currentAcademicYearId]);
    $inactive_student_count = (int) $stmt_inactive_students->fetchColumn();
} else {
    $active_student_count = (int) $db->query("SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id WHERE u.role='student' AND u.status='active' AND u.deleted_at IS NULL AND COALESCE(sp.enrollment_status,'enrolled')='enrolled'")->fetchColumn();
    $graduated_student_count = (int) $db->query("SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id WHERE u.role='student' AND u.deleted_at IS NULL AND COALESCE(sp.enrollment_status,IF(u.status='graduated','graduated','enrolled'))='graduated'")->fetchColumn();
    $inactive_student_count = $user->countByRoleAndStatus('student', 'inactive');
}
// 4. Count specialists (active and inactive)
$active_specialist_count = $user->countByRoleAndStatus('specialist', 'active');
$inactive_specialist_count = $user->countByRoleAndStatus('specialist', 'inactive');
$total_specialist_count = $active_specialist_count + $inactive_specialist_count;

// 5. Count evaluation types
$evaluation_type = new EvaluationType($db);
$evaluation_type_count = method_exists($evaluation_type, 'countAll') ? $evaluation_type->countAll() : $evaluation_type->readAll()->rowCount();

$pos_eval_types_query = "SELECT COUNT(*) as total FROM evaluation_types WHERE type = 'positive'";
$stmt_pos_eval_types = $db->prepare($pos_eval_types_query);
$stmt_pos_eval_types->execute();
$pos_eval_types_count = $stmt_pos_eval_types->fetch(PDO::FETCH_ASSOC)['total'];

// 6. Count total evaluations
$evaluation = new Evaluation($db);
if ($currentAcademicYearId > 0) {
    $total_evaluations_query = "SELECT COUNT(*) as total FROM evaluations WHERE academic_year_id = ?";
    $stmt_total_evaluations = $db->prepare($total_evaluations_query);
    $stmt_total_evaluations->execute([$currentAcademicYearId]);
} else {
    $total_evaluations_query = "SELECT COUNT(*) as total FROM evaluations";
    $stmt_total_evaluations = $db->prepare($total_evaluations_query);
    $stmt_total_evaluations->execute();
}
$total_evaluations = $stmt_total_evaluations->fetch(PDO::FETCH_ASSOC)['total'];

// 7. Count positive evaluations this month
if ($currentAcademicYearId > 0) {
    $positive_month_query = "SELECT COUNT(*) as total FROM evaluations e 
                            JOIN evaluation_types et ON e.evaluation_type_id = et.id 
                            WHERE (et.type = 'positive' OR (e.custom_points IS NOT NULL AND e.custom_points > 0))
                            AND e.academic_year_id = ?
                            AND e.date_created >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                            AND e.date_created < DATE_ADD(DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01'), INTERVAL 1 MONTH)";
    $stmt_positive = $db->prepare($positive_month_query);
    $stmt_positive->execute([$currentAcademicYearId]);
} else {
    $positive_month_query = "SELECT COUNT(*) as total FROM evaluations e 
                            JOIN evaluation_types et ON e.evaluation_type_id = et.id 
                            WHERE (et.type = 'positive' OR (e.custom_points IS NOT NULL AND e.custom_points > 0))
                            AND e.date_created >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                            AND e.date_created < DATE_ADD(DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01'), INTERVAL 1 MONTH)";
    $stmt_positive = $db->prepare($positive_month_query);
    $stmt_positive->execute();
}
$positive_month = $stmt_positive->fetch(PDO::FETCH_ASSOC)['total'];

// 8. Count active classes (with active students) — مرتبطة بالعام الحالي
if ($currentAcademicYearId > 0) {
    $active_classes_query = "SELECT COUNT(DISTINCT se.class_id) as total
        FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        JOIN classes c ON c.id = se.class_id
        WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
        AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND c.status = 'active'";
    $stmt_active_classes = $db->prepare($active_classes_query);
    $stmt_active_classes->execute([$currentAcademicYearId]);
} else {
    $active_classes_query = "SELECT COUNT(DISTINCT u.class_id) as total FROM users u JOIN classes c ON u.class_id = c.id WHERE u.role = 'student' AND u.status = 'active' AND c.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')";
    $stmt_active_classes = $db->prepare($active_classes_query);
    $stmt_active_classes->execute();
}
$active_classes = $stmt_active_classes->fetch(PDO::FETCH_ASSOC)['total'];

$year_class_count = 0;
if ($currentAcademicYearId > 0 && dashboard_column_exists($db, 'classes', 'academic_year_id')) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE academic_year_id = ? AND status = 'active'");
    $stmt->execute([$currentAcademicYearId]);
    $year_class_count = (int) $stmt->fetchColumn();
}
if ($currentAcademicYearId > 0) {
    $class_count = $year_class_count > 0 ? $year_class_count : (int) $active_classes;
}

// 9. Count stages (Display all stages completely)
$stages_count = (int) $db->query("SELECT COUNT(*) FROM stages")->fetchColumn();
$active_stages_count = (int) $db->query("SELECT COUNT(*) FROM stages WHERE status = 'active'")->fetchColumn();

// 10. Count grades for the current academic year when enrollment data exists
$grades_count = 0;
$active_grades_count = 0;
if ($currentAcademicYearId > 0 && dashboard_table_exists($db, 'student_enrollments')) {
    $stmt_grades = $db->prepare("SELECT COUNT(DISTINCT se.grade_id)
        FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        WHERE se.academic_year_id = ?
          AND se.enrollment_status = 'enrolled'
          AND se.grade_id IS NOT NULL
          AND u.role = 'student'
          AND u.status = 'active'
          AND u.deleted_at IS NULL");
    $stmt_grades->execute([$currentAcademicYearId]);
    $grades_count = (int) $stmt_grades->fetchColumn();
    $active_grades_count = $grades_count;
}
if ($currentAcademicYearId <= 0 || !dashboard_table_exists($db, 'student_enrollments')) {
    $grades_count = (int) $db->query("SELECT COUNT(*) FROM grades")->fetchColumn();
    $active_grades_count = (int) $db->query("SELECT COUNT(*) FROM grades WHERE status = 'active'")->fetchColumn();
}

// 11. Count subjects linked to the current academic year when the new assessment link table has data
try {
    $subjects_count = 0;
    $active_subjects_count = 0;
    $hasSubjectYearLinks = $currentAcademicYearId > 0 && dashboard_table_exists($db, 'subject_grade_assignments');
    if ($hasSubjectYearLinks) {
        $stmt_subject_links = $db->prepare("SELECT COUNT(*) FROM subject_grade_assignments WHERE academic_year_id = ?");
        $stmt_subject_links->execute([$currentAcademicYearId]);
        $hasSubjectYearLinks = (int) $stmt_subject_links->fetchColumn() > 0;
    }
    if ($hasSubjectYearLinks) {
        $stmt_subjects = $db->prepare("SELECT COUNT(DISTINCT subject_id)
            FROM subject_grade_assignments
            WHERE academic_year_id = ?");
        $stmt_subjects->execute([$currentAcademicYearId]);
        $subjects_count = (int) $stmt_subjects->fetchColumn();

        $stmt_active_subjects = $db->prepare("SELECT COUNT(DISTINCT subject_id)
            FROM subject_grade_assignments
            WHERE academic_year_id = ? AND is_active = 1");
        $stmt_active_subjects->execute([$currentAcademicYearId]);
        $active_subjects_count = (int) $stmt_active_subjects->fetchColumn();
    }

    if (!$hasSubjectYearLinks) {
        $subjects_count = (int) $db->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
        if (dashboard_column_exists($db, 'subjects', 'status')) {
            $active_subjects_count = (int) $db->query("SELECT COUNT(*) FROM subjects WHERE status = 'active'")->fetchColumn();
        } elseif (dashboard_column_exists($db, 'subjects', 'is_active')) {
            $active_subjects_count = (int) $db->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
        } else {
            $active_subjects_count = $subjects_count;
        }
    }
} catch (Exception $e) {
    $subjects_count = 0;
    $active_subjects_count = 0;
}

// 12. Count notifications
try {
    $notifications_query = "SELECT COUNT(*) as total FROM notifications";
    $stmt_notifications = $db->prepare($notifications_query);
    $stmt_notifications->execute();
    $notifications_count = $stmt_notifications->fetch(PDO::FETCH_ASSOC)['total'];

    $active_notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE is_active = 1";
    $stmt_active_notifications = $db->prepare($active_notifications_query);
    $stmt_active_notifications->execute();
    $active_notifications_count = $stmt_active_notifications->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $notifications_count = 0;
    $active_notifications_count = 0;
}

// 13. إحصائيات إضافية للوحة التحكم
$today_attendance_count = 0;
$today_present_count = 0;
$today_attendance_pct = 0;
$bus_students_count = 0;
$buses_count = 0;
$external_teachers_count = 0;
$training_programs_count = 0;
$training_courses_count = 0;
$training_enrollments_count = 0;
$library_books_count = 0;
$library_active_loans = 0;
$clinic_month_count = 0;

try {
    // الحضور اليوم
    if (dashboard_table_exists($db, 'attendance')) {
        if ($currentAcademicYearId > 0) {
            $stmt_att_tot = $db->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND deleted_at IS NULL AND academic_year_id = ?");
            $stmt_att_tot->execute([$currentAcademicYearId]);
            $today_attendance_count = (int) $stmt_att_tot->fetchColumn();

            $stmt_att_pres = $db->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND status = 'present' AND deleted_at IS NULL AND academic_year_id = ?");
            $stmt_att_pres->execute([$currentAcademicYearId]);
            $today_present_count = (int) $stmt_att_pres->fetchColumn();
        } else {
            $today_attendance_count = (int) $db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND deleted_at IS NULL")->fetchColumn();
            $today_present_count = (int) $db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND status = 'present' AND deleted_at IS NULL")->fetchColumn();
        }
        $today_attendance_pct = $today_attendance_count > 0 ? round(($today_present_count / $today_attendance_count) * 100) : 0;
    }

    // النقل المدرسي (الباصات)
    if ($currentAcademicYearId > 0) {
        $stmt_bus = $db->prepare("SELECT COUNT(*) FROM student_bus_assignments sba
            JOIN users u ON u.id = sba.student_id
            WHERE sba.academic_year_id = ? AND u.status = 'active' AND u.deleted_at IS NULL");
        $stmt_bus->execute([$currentAcademicYearId]);
        $bus_students_count = (int) $stmt_bus->fetchColumn();
    } else {
        $bus_students_count = (int) $db->query("SELECT COUNT(*) FROM student_bus_assignments sba
            JOIN users u ON u.id = sba.student_id
            WHERE u.status = 'active' AND u.deleted_at IS NULL")->fetchColumn();
    }
    $buses_count = (int) $db->query("SELECT COUNT(*) FROM buses")->fetchColumn();

    // المدربين الخارجيين
    if (dashboard_table_exists($db, 'external_teachers')) {
        $external_teachers_count = (int) $db->query("SELECT COUNT(*) FROM external_teachers")->fetchColumn();
    }

    // التدريب والتطوير
    if (dashboard_table_exists($db, 'training_programs')) {
        $training_programs_count = (int) $db->query("SELECT COUNT(*) FROM training_programs")->fetchColumn();
    }
    if (dashboard_table_exists($db, 'training_courses')) {
        $training_courses_count = (int) $db->query("SELECT COUNT(*) FROM training_courses")->fetchColumn();
    }
    if (dashboard_table_exists($db, 'training_enrollments')) {
        $training_enrollments_count = (int) $db->query("SELECT COUNT(*) FROM training_enrollments")->fetchColumn();
    }

    // المكتبة
    if (dashboard_table_exists($db, 'library_books')) {
        $library_books_count = (int) $db->query("SELECT COUNT(*) FROM library_books")->fetchColumn();
    }
    if (dashboard_table_exists($db, 'library_borrowings')) {
        $library_active_loans = (int) $db->query("SELECT COUNT(*) FROM library_borrowings WHERE return_date IS NULL")->fetchColumn();
    }

    // العيادة المدرسية — زيارات الشهر الحالي
    if (dashboard_table_exists($db, 'clinic_visits')) {
        $clinic_month_count = (int) $db->query("SELECT COUNT(*) FROM clinic_visits WHERE visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
    }

    // الخريجون في العام الحالي
    // (graduated_student_count تم حسابه مسبقاً في قسم 3)
} catch (Exception $e) {
    // تبقى المؤشرات بصفر إذا كانت الجداول غير مكتملة
}

// 14. بيانات الرسوم البيانية
$dashboardLoadChart = static function (string $context, callable $loader): array {
    try {
        $rows = $loader();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('Dashboard chart load failed [' . $context . ']: ' . $e->getMessage());
        return [];
    }
};

$chart_students_by_stage = $dashboardLoadChart('students_by_stage', static function () use ($db, $currentAcademicYearId): array {
    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT s.stage_name, COUNT(DISTINCT u.id) AS cnt
            FROM stages s
            LEFT JOIN grades g ON g.stage_id = s.id
            LEFT JOIN student_enrollments se ON se.grade_id = g.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            LEFT JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            WHERE s.status = 'active'
            GROUP BY s.id, s.stage_name
            ORDER BY s.stage_order");
        $stmt->execute([$currentAcademicYearId]);
    } else {
        $stmt = $db->query("SELECT s.stage_name, COUNT(DISTINCT u.id) AS cnt
            FROM stages s
            LEFT JOIN grades g ON g.stage_id = s.id
            LEFT JOIN classes c ON c.grade_id = g.id
            LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            WHERE s.status = 'active'
            GROUP BY s.id, s.stage_name
            ORDER BY s.stage_order");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_students_by_grade = $dashboardLoadChart('students_by_grade', static function () use ($db, $currentAcademicYearId): array {
    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT g.grade_name, COUNT(se.id) AS cnt
            FROM student_enrollments se
            JOIN grades g ON g.id = se.grade_id
            JOIN users u ON u.id = se.student_id
            WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
              AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY g.id, g.grade_name ORDER BY cnt DESC LIMIT 10");
        $stmt->execute([$currentAcademicYearId]);
    } else {
        $stmt = $db->query("SELECT g.grade_name, COUNT(u.id) AS cnt
            FROM grades g
            LEFT JOIN classes c ON c.grade_id = g.id
            LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY g.id, g.grade_name ORDER BY cnt DESC LIMIT 10");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_monthly_evaluations = $dashboardLoadChart('monthly_evaluations', static function () use ($db, $currentAcademicYearId): array {
    $yearSql = $currentAcademicYearId > 0 ? 'e.academic_year_id = ? AND ' : '';
    $stmt = $db->prepare("SELECT
        DATE_FORMAT(e.date_created, '%Y-%m') AS month_key,
        DATE_FORMAT(e.date_created, '%m/%Y') AS month_label,
        SUM(CASE WHEN et.type = 'positive' OR (e.custom_points IS NOT NULL AND e.custom_points > 0) THEN 1 ELSE 0 END) AS positive_cnt,
        SUM(CASE WHEN et.type = 'negative' OR (e.custom_points IS NOT NULL AND e.custom_points < 0) THEN 1 ELSE 0 END) AS negative_cnt
        FROM evaluations e
        JOIN evaluation_types et ON et.id = e.evaluation_type_id
        WHERE {$yearSql}e.date_created >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
        GROUP BY month_key, month_label ORDER BY month_key");
    $stmt->execute($currentAcademicYearId > 0 ? [$currentAcademicYearId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_staff_by_role = $dashboardLoadChart('staff_by_role', static function () use ($db): array {
    if (dashboard_table_exists($db, 'user_role_assignments')) {
        $stmt = $db->query("SELECT ura.role_key AS role,
            SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN u.status != 'active' THEN 1 ELSE 0 END) AS inactive_cnt
            FROM user_role_assignments ura
            JOIN users u ON u.id = ura.user_id
            WHERE u.deleted_at IS NULL AND ura.status = 'active'
              AND ura.role_key IN ('admin','teacher','specialist')
            GROUP BY ura.role_key ORDER BY FIELD(ura.role_key, 'admin', 'teacher', 'specialist')");
    } else {
        $stmt = $db->query("SELECT role,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN status != 'active' THEN 1 ELSE 0 END) AS inactive_cnt
            FROM users WHERE deleted_at IS NULL AND role IN ('admin','teacher','specialist')
            GROUP BY role ORDER BY FIELD(role, 'admin', 'teacher', 'specialist')");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_students_per_class = $dashboardLoadChart('students_per_class', static function () use ($db, $currentAcademicYearId): array {
    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT c.name AS class_name, COUNT(se.id) AS cnt
            FROM student_enrollments se
            JOIN classes c ON c.id = se.class_id
            JOIN users u ON u.id = se.student_id
            WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
              AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY c.id, c.name ORDER BY cnt DESC LIMIT 15");
        $stmt->execute([$currentAcademicYearId]);
    } else {
        $stmt = $db->query("SELECT c.name AS class_name, COUNT(u.id) AS cnt
            FROM classes c
            LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY c.id, c.name ORDER BY cnt DESC LIMIT 15");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_top_teachers = $dashboardLoadChart('top_teachers', static function () use ($db, $currentAcademicYearId): array {
    $stmt = $db->prepare("SELECT u.name AS teacher_name, COUNT(*) AS cnt
        FROM evaluations e JOIN users u ON u.id = e.teacher_id
        " . ($currentAcademicYearId > 0 ? 'WHERE e.academic_year_id = ?' : '') . "
        GROUP BY e.teacher_id, u.name ORDER BY cnt DESC LIMIT 10");
    $stmt->execute($currentAcademicYearId > 0 ? [$currentAcademicYearId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_top_classes_evals = $dashboardLoadChart('top_classes_evaluations', static function () use ($db, $currentAcademicYearId): array {
    $stmt = $db->prepare("SELECT c.name AS class_name, COUNT(*) AS cnt
        FROM evaluations e JOIN classes c ON c.id = e.class_id
        " . ($currentAcademicYearId > 0 ? 'WHERE e.academic_year_id = ?' : '') . "
        GROUP BY e.class_id, c.name ORDER BY cnt DESC LIMIT 10");
    $stmt->execute($currentAcademicYearId > 0 ? [$currentAcademicYearId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_top_users_actions = $dashboardLoadChart('top_users_actions', static function () use ($db): array {
    if (!dashboard_table_exists($db, 'action_logs')) {
        return [];
    }
    $stmt = $db->query("SELECT u.name AS user_name, COUNT(*) AS cnt
        FROM action_logs al JOIN users u ON u.id = al.user_id
        GROUP BY al.user_id, u.name ORDER BY cnt DESC LIMIT 10");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$chart_ai_api_usage = $dashboardLoadChart('ai_api_usage', static function () use ($db): array {
    if (!dashboard_table_exists($db, 'ai_api_logs')) {
        return [];
    }
    $stmt = $db->query("SELECT DATE(created_at) as date_label, COUNT(*) as cnt, SUM(tokens_used) as tokens
        FROM ai_api_logs GROUP BY date_label ORDER BY date_label DESC LIMIT 10");
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
});

$chart_materials_per_grade = $dashboardLoadChart('materials_per_grade', static function () use ($db, $currentAcademicYearId): array {
    if (!dashboard_table_exists($db, 'materials')) {
        return [];
    }
    $stmt = $db->prepare("SELECT g.grade_name, COUNT(*) AS cnt
        FROM materials m JOIN grades g ON g.id = m.grade_id
        " . ($currentAcademicYearId > 0 ? 'WHERE m.academic_year_id = ?' : '') . "
        GROUP BY m.grade_id, g.grade_name ORDER BY cnt DESC LIMIT 10");
    $stmt->execute($currentAcademicYearId > 0 ? [$currentAcademicYearId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

// 15. إحصائيات إضافية (كروت جديدة)
$admins_count = 0;
$ai_lessons_count = 0;
$internal_transfers_count = 0;
$published_reports_count = 0;
$activities_count = 0;

$action_logs_count = 0;
$materials_count = 0;
$guardians_count = 0;
$siblings_count = 0;
$ai_api_logs_count = 0;

try {
    $admins_count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND deleted_at IS NULL")->fetchColumn();

    if (dashboard_table_exists($db, 'ai_lessons')) {
        $ai_lessons_count = (int) $db->query("SELECT COUNT(*) FROM ai_lessons")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'student_transfers')) {
        $internal_transfers_count = (int) $db->query("SELECT COUNT(*) FROM student_transfers")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'published_reports')) {
        $published_reports_count = (int) $db->query("SELECT COUNT(*) FROM published_reports")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'activities')) {
        $activities_count = (int) $db->query("SELECT COUNT(*) FROM activities")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'action_logs')) {
        $action_logs_count = (int) $db->query("SELECT COUNT(*) FROM action_logs")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'materials')) {
        if ($currentAcademicYearId > 0) {
            $stmt_mat_cnt = $db->prepare("SELECT COUNT(*) FROM materials WHERE academic_year_id = ?");
            $stmt_mat_cnt->execute([$currentAcademicYearId]);
            $materials_count = (int) $stmt_mat_cnt->fetchColumn();
        } else {
            $materials_count = (int) $db->query("SELECT COUNT(*) FROM materials")->fetchColumn();
        }
    }

    if (dashboard_table_exists($db, 'student_guardians')) {
        $guardians_count = (int) $db->query("SELECT COUNT(*) FROM student_guardians")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'student_siblings')) {
        $siblings_count = (int) $db->query("SELECT COUNT(*) FROM student_siblings")->fetchColumn();
    }

    if (dashboard_table_exists($db, 'ai_api_logs')) {
        $ai_api_logs_count = (int) $db->query("SELECT COUNT(*) FROM ai_api_logs")->fetchColumn();
    }
} catch (Exception $e) {
    // تبقى بصفر
}

// 16. تحليلات أداء الذكاء الاصطناعي
$ai_success_rate = 100;
$ai_avg_response_time = 0;
$ai_total_tokens = 0;
$ai_top_teacher = 'لا يوجد';

try {
    if (dashboard_table_exists($db, 'ai_api_logs')) {
        $total_calls = (int) $db->query("SELECT COUNT(*) FROM ai_api_logs")->fetchColumn();
        if ($total_calls > 0) {
            $success_calls = (int) $db->query("SELECT COUNT(*) FROM ai_api_logs WHERE status='success' OR status='completed' OR status IS NULL OR status=''")->fetchColumn();
            // If success count is 0 but we have logs, let's treat non-failed status as success
            $failed_calls = (int) $db->query("SELECT COUNT(*) FROM ai_api_logs WHERE status='failed' OR status='error'")->fetchColumn();
            $success_calls = $total_calls - $failed_calls;
            $ai_success_rate = round(($success_calls / $total_calls) * 100, 1);
            $ai_avg_response_time = round((float) $db->query("SELECT AVG(response_time_ms) FROM ai_api_logs")->fetchColumn() / 1000, 2);
            $ai_total_tokens = (int) $db->query("SELECT SUM(tokens_used) FROM ai_api_logs")->fetchColumn();

            $top_teach = $db->query("SELECT u.name FROM ai_api_logs l JOIN users u ON u.id = l.teacher_id GROUP BY l.teacher_id, u.name ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
            if ($top_teach) {
                $ai_top_teacher = $top_teach;
            }
        }
    }
} catch (Exception $e) {
    // defaults
}

// Get recent evaluations for dashboard (last 5) with custom points handling
$recent_where = "";
$recent_params = [];
if ($currentAcademicYearId > 0) {
    $recent_where = " WHERE e.academic_year_id = ? ";
    $recent_params[] = $currentAcademicYearId;
}

$query = "SELECT e.id, e.date_created, 
          s.name as student_name, 
          t.name as teacher_name,
          c.name as class_name,
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
          JOIN evaluation_types et ON e.evaluation_type_id = et.id
          $recent_where
          ORDER BY e.date_created DESC
          LIMIT 5";

$stmt_recent = $db->prepare($query);
$stmt_recent->execute($recent_params);
?>


<div class="row">
    <div class="col-12 d-flex justify-content-between align-items-center mb-4 animate-up">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fas fa-tachometer-alt me-2 text-primary"></i>
                لوحة التحكم
            </h1>
            <p class="text-muted m-0">نظرة عامة على أداء المؤسسة التعليمية والمؤشرات الحيوية</p>
        </div>
        <div class="admin-top-actions no-print">
            <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="offcanvas"
                data-bs-target="#dashboardSettings" aria-controls="dashboardSettings">
                <i class="fas fa-sliders-h me-1"></i>تخصيص لوحة التحكم
            </button>
            <button onclick="window.print()" class="btn btn-header-premium btn-print-soft">
                <i class="fas fa-print me-1"></i>طباعة
            </button>
        </div>
    </div>
</div>

<div class="dashboard-canvas sortable-dashboard">

    <!-- Dashboard Stats Grid -->
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xxl-5 g-3 mb-4 sortable-dashboard" id="widget-kpi">

        <!-- المراحل -->
        <div class="col animate-up delay-1 dashboard-card" data-card-id="stages">
            <a href="stages.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #0ea5e9; --card-color-bg: #e0f2fe;">
                    <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
                    <div class="stat-card-badge"><?php echo $active_stages_count; ?> نشط</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $stages_count; ?>">0</div>
                        <div class="stat-card-label">المراحل الدراسية</div>
                        <div class="stat-card-sub"><i class="fas fa-layer-group"></i> إجمالي المراحل الدراسية المسجلة
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الصفوف -->
        <div class="col animate-up delay-2 dashboard-card" data-card-id="grades">
            <a href="grades.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #10b981; --card-color-bg: #e6f4ea;">
                    <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
                    <div class="stat-card-badge"><?php echo $active_grades_count; ?> في العام</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $grades_count; ?>">0</div>
                        <div class="stat-card-label">الصفوف الدراسية</div>
                        <div class="stat-card-sub"><i class="fas fa-list"></i> صفوف بها طلاب في العام الحالي</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الفصول -->
        <div class="col animate-up delay-3 dashboard-card" data-card-id="classes">
            <a href="classes.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #0ea5e9; --card-color-bg: #e0f2fe;">
                    <div class="stat-card-icon"><i class="fas fa-school"></i></div>
                    <div class="stat-card-badge"><?php echo $active_classes; ?> بها طلاب</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $class_count; ?>">0</div>
                        <div class="stat-card-label">الفصول</div>
                        <div class="stat-card-sub"><i class="fas fa-door-open"></i> فصول العام الدراسي الحالي</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- المواد -->
        <div class="col animate-up delay-4 dashboard-card" data-card-id="subjects">
            <a href="subjects.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #10b981; --card-color-bg: #e6f4ea;">
                    <div class="stat-card-icon"><i class="fas fa-book"></i></div>
                    <div class="stat-card-badge"><?php echo $active_subjects_count; ?> نشطة</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $subjects_count; ?>">0</div>
                        <div class="stat-card-label">المواد الدراسية</div>
                        <div class="stat-card-sub"><i class="fas fa-book-open"></i> مواد مرتبطة بالعام الحالي</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- العاملين -->
        <div class="col animate-up delay-5 dashboard-card" data-card-id="staff">
            <a href="staff.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #1d4ed8; --card-color-bg: #eff6ff;">
                    <div class="stat-card-icon"><i class="fas fa-users-cog"></i></div>
                    <div class="stat-card-badge"><?php echo $active_teacher_count + $active_specialist_count; ?> نشط
                    </div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter"
                            data-target="<?php echo $total_teacher_count + $total_specialist_count; ?>">0</div>
                        <div class="stat-card-label">العاملين</div>
                        <div class="stat-card-sub"><i class="fas fa-chalkboard-teacher"></i>
                            <?php echo $total_teacher_count; ?> معلم · <i class="fas fa-user-nurse"></i>
                            <?php echo $total_specialist_count; ?> أخصائي</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الطلاب -->
        <div class="col animate-up delay-1 dashboard-card" data-card-id="students">
            <a href="students.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #d97706; --card-color-bg: #fef3c7;">
                    <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-card-badge"><?php echo $active_student_count; ?> نشط</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $active_student_count; ?>">0</div>
                        <div class="stat-card-label">الطلاب المقيدون</div>
                        <div class="stat-card-sub"><i class="fas fa-users"></i>
                            <?php echo number_format($inactive_student_count); ?> معطل ·
                            <?php echo number_format($graduated_student_count); ?> خريج</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- أنواع التقييم -->
        <div class="col animate-up delay-2 dashboard-card" data-card-id="evaluation_types">
            <a href="evaluation_types.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #1d4ed8; --card-color-bg: #eff6ff;">
                    <div class="stat-card-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-card-badge"><?php echo $pos_eval_types_count; ?> إيجابي</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $evaluation_type_count; ?>">0
                        </div>
                        <div class="stat-card-label">أنواع التقييم</div>
                        <div class="stat-card-sub"><i class="fas fa-award"></i>
                            <?php echo $evaluation_type_count - $pos_eval_types_count; ?> سلبي</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- إجمالي التقييمات -->
        <div class="col animate-up delay-3 dashboard-card" data-card-id="total_evaluations">
            <a href="evaluation_analytics.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #d97706; --card-color-bg: #fef3c7;">
                    <div class="stat-card-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="stat-card-badge"><?php echo number_format($positive_month); ?> إيجابي</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $total_evaluations; ?>">0</div>
                        <div class="stat-card-label">إجمالي التقييمات</div>
                        <div class="stat-card-sub"><i class="fas fa-calendar-alt"></i> مضافة هذا الشهر</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- التنبيهات -->
        <div class="col animate-up delay-4 dashboard-card" data-card-id="notifications">
            <a href="notifications.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #ef4444; --card-color-bg: #fef2f2;">
                    <div class="stat-card-icon"><i class="fas fa-bell"></i></div>
                    <div class="stat-card-badge"><?php echo $active_notifications_count; ?> نشط</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $notifications_count; ?>">0</div>
                        <div class="stat-card-label">التنبيهات</div>
                        <div class="stat-card-sub"><i class="fas fa-paper-plane"></i> إشعارات مُرسلة</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الحضور اليوم -->
        <div class="col animate-up delay-5 dashboard-card" data-card-id="attendance_today">
            <a href="attendance.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #8b5cf6; --card-color-bg: #f5f3ff;">
                    <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-card-badge"><?php echo $today_attendance_pct; ?>% حاضر</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $today_attendance_count; ?>">0
                        </div>
                        <div class="stat-card-label">سجلات حضور اليوم</div>
                        <div class="stat-card-sub"><i class="fas fa-check-circle"></i>
                            <?php echo $today_present_count; ?> حاضر</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- النقل المدرسي -->
        <div class="col animate-up delay-1 dashboard-card" data-card-id="transport">
            <a href="student_buses.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #f97316; --card-color-bg: #fff7ed;">
                    <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
                    <div class="stat-card-badge"><?php echo $buses_count; ?> باص</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $bus_students_count; ?>">0</div>
                        <div class="stat-card-label">طلاب النقل</div>
                        <div class="stat-card-sub"><i class="fas fa-route"></i> طلاب مسجلين في الباصات</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- المدربين الخارجيين -->
        <div class="col animate-up delay-2 dashboard-card" data-card-id="external_teachers">
            <a href="external_teachers.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #6366f1; --card-color-bg: #eef2ff;">
                    <div class="stat-card-icon"><i class="fas fa-user-tie"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $external_teachers_count; ?>">0
                        </div>
                        <div class="stat-card-label">المدربين الخارجيين</div>
                        <div class="stat-card-sub"><i class="fas fa-id-badge"></i> مدربين مسجلين</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- التدريب والتطوير -->
        <div class="col animate-up delay-3 dashboard-card" data-card-id="training">
            <a href="training_programs.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #14b8a6; --card-color-bg: #f0fdfa;">
                    <div class="stat-card-icon"><i class="fas fa-chalkboard"></i></div>
                    <div class="stat-card-badge"><?php echo $training_enrollments_count; ?> مسجل</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $training_courses_count; ?>">0
                        </div>
                        <div class="stat-card-label">الدورات التدريبية</div>
                        <div class="stat-card-sub"><i class="fas fa-sitemap"></i>
                            <?php echo $training_programs_count; ?> برنامج</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- المكتبة -->
        <div class="col animate-up delay-4 dashboard-card" data-card-id="library">
            <a href="library.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #ec4899; --card-color-bg: #fdf2f8;">
                    <div class="stat-card-icon"><i class="fas fa-book-reader"></i></div>
                    <div class="stat-card-badge"><?php echo $library_active_loans; ?> إعارة نشطة</div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $library_books_count; ?>">0</div>
                        <div class="stat-card-label">المكتبة</div>
                        <div class="stat-card-sub"><i class="fas fa-book-open"></i> كتاب في المكتبة</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- العيادة المدرسية -->
        <div class="col animate-up delay-5 dashboard-card" data-card-id="clinic">
            <a href="student_clinic.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #06b6d4; --card-color-bg: #ecfeff;">
                    <div class="stat-card-icon"><i class="fas fa-heartbeat"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $clinic_month_count; ?>">0</div>
                        <div class="stat-card-label">زيارات العيادة</div>
                        <div class="stat-card-sub"><i class="fas fa-calendar-alt"></i> زيارات هذا الشهر</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الخريجون -->
        <div class="col animate-up delay-1 dashboard-card" data-card-id="graduates">
            <a href="graduates.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #78716c; --card-color-bg: #f5f5f4;">
                    <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $graduated_student_count; ?>">0
                        </div>
                        <div class="stat-card-label">الخريجون</div>
                        <div class="stat-card-sub"><i class="fas fa-graduation-cap"></i> خريجو العام الحالي</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الإداريون -->
        <div class="col animate-up delay-2 dashboard-card" data-card-id="admins">
            <a href="staff.php?role=admin" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #3b82f6; --card-color-bg: #eff6ff;">
                    <div class="stat-card-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $admins_count; ?>">0</div>
                        <div class="stat-card-label">الإداريون</div>
                        <div class="stat-card-sub"><i class="fas fa-shield-alt"></i> إداريين نشطين</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الدروس الذكية AI -->
        <div class="col animate-up delay-3 dashboard-card" data-card-id="ai_lessons">
            <a href="ai_lessons_monitor.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #8b5cf6; --card-color-bg: #f5f3ff;">
                    <div class="stat-card-icon"><i class="fas fa-robot"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $ai_lessons_count; ?>">0</div>
                        <div class="stat-card-label">الدروس الذكية (AI)</div>
                        <div class="stat-card-sub"><i class="fas fa-brain"></i> درس تم إنشاؤه بالذكاء</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- التحويلات الداخلية -->
        <div class="col animate-up delay-4 dashboard-card" data-card-id="internal_transfers">
            <a href="student_promotion.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #eab308; --card-color-bg: #fef9c3;">
                    <div class="stat-card-icon"><i class="fas fa-exchange-alt"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $internal_transfers_count; ?>">0
                        </div>
                        <div class="stat-card-label">التحويلات الداخلية</div>
                        <div class="stat-card-sub"><i class="fas fa-random"></i> سجلات التحويل بين الصفوف</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- التقارير المنشورة -->
        <div class="col animate-up delay-5 dashboard-card" data-card-id="published_reports">
            <a href="evaluation_reports.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #10b981; --card-color-bg: #f0fdf4;">
                    <div class="stat-card-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $published_reports_count; ?>">0
                        </div>
                        <div class="stat-card-label">التقارير المنشورة</div>
                        <div class="stat-card-sub"><i class="fas fa-check-double"></i> تقارير معتمدة</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- الأنشطة التفاعلية -->
        <div class="col animate-up delay-1 dashboard-card" data-card-id="activities">
            <a href="activities_monitor.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #ec4899; --card-color-bg: #fdf2f8;">
                    <div class="stat-card-icon"><i class="fas fa-puzzle-piece"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $activities_count; ?>">0</div>
                        <div class="stat-card-label">الأنشطة التفاعلية</div>
                        <div class="stat-card-sub"><i class="fas fa-gamepad"></i> أنشطة نشطة في النظام</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- سجلات العمليات -->
        <div class="col animate-up delay-2 dashboard-card" data-card-id="action_logs">
            <a href="activity_logs.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #4b5563; --card-color-bg: #f3f4f6;">
                    <div class="stat-card-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $action_logs_count; ?>">0</div>
                        <div class="stat-card-label">سجلات العمليات</div>
                        <div class="stat-card-sub"><i class="fas fa-fingerprint"></i> حركات مسجلة بالكامل</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- المواد المرفوعة -->
        <div class="col animate-up delay-3 dashboard-card" data-card-id="materials">
            <a href="materials_center.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #2563eb; --card-color-bg: #eff6ff;">
                    <div class="stat-card-icon"><i class="fas fa-folder-open"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $materials_count; ?>">0</div>
                        <div class="stat-card-label">المواد المرفوعة</div>
                        <div class="stat-card-sub"><i class="fas fa-file-upload"></i> مستندات وملفات تعليمية</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- أولياء الأمور -->
        <div class="col animate-up delay-4 dashboard-card" data-card-id="student_guardians">
            <a href="students.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #059669; --card-color-bg: #ecfdf5;">
                    <div class="stat-card-icon"><i class="fas fa-user-friends"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $guardians_count; ?>">0</div>
                        <div class="stat-card-label">أولياء الأمور</div>
                        <div class="stat-card-sub"><i class="fas fa-id-card"></i> حسابات أولياء الأمور</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- أشقاء الطلاب -->
        <div class="col animate-up delay-5 dashboard-card" data-card-id="student_siblings">
            <a href="siblings.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #d97706; --card-color-bg: #fffbeb;">
                    <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $siblings_count; ?>">0</div>
                        <div class="stat-card-label">أشقاء الطلاب</div>
                        <div class="stat-card-sub"><i class="fas fa-link"></i> علاقات قرابة مسجلة</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- استهلاك الذكاء الاصطناعي -->
        <div class="col animate-up delay-1 dashboard-card" data-card-id="ai_api_logs">
            <a href="ai_settings.php" class="text-decoration-none">
                <div class="stat-card" style="--card-color: #7c3aed; --card-color-bg: #f5f3ff;">
                    <div class="stat-card-icon"><i class="fas fa-server"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $ai_api_logs_count; ?>">0</div>
                        <div class="stat-card-label">طلبات الـ AI API</div>
                        <div class="stat-card-sub"><i class="fas fa-bolt"></i> استعلام ذكي مسجل</div>
                    </div>
                </div>
            </a>
        </div>

    </div>

    <!-- لوحة الأقسام والرسوم البيانية القابلة للترتيب وإعادة الحجم -->
    <div id="dashboard-sections-sortable" class="row g-4 mb-4">

        <!-- لوحة الإجراءات السريعة التفاعلية -->
        <div class="col-12 dashboard-section" data-id="quick_actions" data-section-id="quick_actions"
            data-default-size="col-12">
            <div class="card premium-card animate-up delay-2 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-primary-subtle me-3 text-primary d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-bolt fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">لوحة الإجراءات السريعة والاختصارات</h6>
                    </div>

                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-8 g-3">
                        <div class="col">
                            <a href="students.php?action=add" class="quick-action-btn">
                                <div class="quick-action-icon bg-primary-subtle text-primary"><i
                                        class="fas fa-user-plus"></i></div>
                                <span class="quick-action-label">إضافة طالب</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="attendance.php" class="quick-action-btn">
                                <div class="quick-action-icon bg-success-subtle text-success"><i
                                        class="fas fa-calendar-check"></i></div>
                                <span class="quick-action-label">رصد الغياب</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="ai_lessons_monitor.php" class="quick-action-btn">
                                <div class="quick-action-icon"
                                    style="background-color: #f3e8ff; color: #a855f7; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 0.75rem;">
                                    <i class="fas fa-robot"></i></div>
                                <span class="quick-action-label">تحضير ذكي (AI)</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="notifications.php" class="quick-action-btn">
                                <div class="quick-action-icon bg-danger-subtle text-danger"><i
                                        class="fas fa-bullhorn"></i></div>
                                <span class="quick-action-label">تنبيه جماعي</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="student_buses.php" class="quick-action-btn">
                                <div class="quick-action-icon bg-warning-subtle text-warning"><i class="fas fa-bus"></i>
                                </div>
                                <span class="quick-action-label">خطوط الحافلات</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="library.php" class="quick-action-btn">
                                <div class="quick-action-icon bg-info-subtle text-info"><i class="fas fa-book"></i>
                                </div>
                                <span class="quick-action-label">المكتبة الرقمية</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="student_clinic.php" class="quick-action-btn">
                                <div class="quick-action-icon bg-teal-subtle text-teal"
                                    style="background-color: #e0f2fe; color: #0ea5e9;"><i class="fas fa-heartbeat"></i>
                                </div>
                                <span class="quick-action-label">زيارة العيادة</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="materials_center.php" class="quick-action-btn">
                                <div class="quick-action-icon bg-secondary-subtle text-secondary"><i
                                        class="fas fa-file-upload"></i></div>
                                <span class="quick-action-label">رفع المقررات</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- لوحة تحليلات أداء الذكاء الاصطناعي -->
        <div class="col-12 dashboard-section" data-id="ai_insights" data-section-id="ai_insights"
            data-default-size="col-12">
            <div class="card premium-card animate-up delay-3 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-purple-subtle text-purple d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px; background-color: #f3e8ff; color: #a855f7;">
                            <i class="fas fa-brain fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">مؤشرات كفاءة وتشغيل الذكاء الاصطناعي</h6>
                    </div>

                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center bg-light-subtle h-100 d-flex flex-column justify-content-between">
                                <div class="text-muted small mb-1">معدل نجاح طلبات الـ API</div>
                                <div class="fs-3 fw-bold text-success my-auto"><span class="counter"
                                        data-target="<?php echo $ai_success_rate; ?>">0</span>%</div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div class="progress-bar bg-success"
                                        style="width: <?php echo $ai_success_rate; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center bg-light-subtle h-100 d-flex flex-column justify-content-between">
                                <div class="text-muted small mb-1">متوسط سرعة استجابة النموذج</div>
                                <div class="fs-3 fw-bold text-primary my-auto"><?php echo $ai_avg_response_time; ?> ثانية</div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div class="progress-bar bg-primary"
                                        style="width: <?php echo min(100, round(($ai_avg_response_time / 10) * 100)); ?>%">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center bg-light-subtle h-100 d-flex flex-column justify-content-between">
                                <div class="text-muted small mb-1">إجمالي استهلاك الرموز (Tokens)</div>
                                <div class="fs-3 fw-bold text-purple my-auto" style="color: #8b5cf6;"><span class="counter"
                                        data-target="<?php echo $ai_total_tokens; ?>">0</span></div>
                                <div class="text-muted small mt-2"><i class="fas fa-coins me-1"></i>حجم المعالجة
                                    الإجمالية</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center bg-light-subtle h-100 d-flex flex-column justify-content-between">
                                <div class="text-muted small mb-1">المعلم الأكثر نشاطاً بالذكاء</div>
                                <div class="fs-5 fw-bold text-dark text-truncate my-auto"
                                    title="<?php echo htmlspecialchars($ai_top_teacher); ?>"><i
                                        class="fas fa-chalkboard-teacher me-2 text-warning"></i><?php echo htmlspecialchars($ai_top_teacher); ?>
                                </div>
                                <div class="text-muted small mt-2">صاحب أكبر عدد تحضيرات</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- توزيع الطلاب حسب المرحلة -->
        <div class="col-lg-4 dashboard-section" data-id="chart_students_stage" data-section-id="chart_students_stage"
            data-default-size="col-lg-4">
            <div class="card premium-card animate-up delay-2 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-primary-subtle me-3 text-primary d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-chart-pie fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">توزيع الطلاب حسب المرحلة</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 300px;"><canvas id="chartStudentsStage"></canvas></div>
                </div>
            </div>
        </div>

        <!-- توزيع الطلاب حسب الصف -->
        <div class="col-lg-8 dashboard-section" data-id="chart_students_grade" data-section-id="chart_students_grade"
            data-default-size="col-lg-8">
            <div class="card premium-card animate-up delay-3 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-success-subtle me-3 text-success d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-chart-bar fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">توزيع الطلاب حسب الصف (أعلى 10)</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 350px;"><canvas id="chartStudentsGrade"></canvas></div>
                </div>
            </div>
        </div>

        <!-- التقييمات الشهرية -->
        <div class="col-lg-8 dashboard-section" data-id="chart_evaluations" data-section-id="chart_evaluations"
            data-default-size="col-lg-8">
            <div class="card premium-card animate-up delay-4 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-warning-subtle me-3 text-warning d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-chart-line fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">التقييمات الشهرية (آخر 6 أشهر)</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 300px;"><canvas id="chartMonthlyEvals"></canvas></div>
                </div>
            </div>
        </div>

        <!-- توزيع العاملين -->
        <div class="col-lg-4 dashboard-section" data-id="chart_staff" data-section-id="chart_staff"
            data-default-size="col-lg-4">
            <div class="card premium-card animate-up delay-5 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-info-subtle me-3 text-info d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-users fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">توزيع العاملين حسب الدور</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 300px;"><canvas id="chartStaffRoles"></canvas></div>
                </div>
            </div>
        </div>

        <!-- كثافة الفصول أعلى 15 -->
        <div class="col-12 dashboard-section" data-id="chart_students_per_class"
            data-section-id="chart_students_per_class" data-default-size="col-12">
            <div class="card premium-card animate-up delay-2 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-primary-subtle me-3 text-primary d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-users-rectangle fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">كثافة الفصول وحجم استيعاب الطلاب (أعلى 15)</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 380px;"><canvas id="chartStudentsPerClass"></canvas></div>
                </div>
            </div>
        </div>

        <!-- أعلى المعلمين بالتقييمات -->
        <div class="col-lg-6 dashboard-section" data-id="chart_top_teachers" data-section-id="chart_top_teachers"
            data-default-size="col-lg-6">
            <div class="card premium-card animate-up delay-3 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-success-subtle me-3 text-success d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-award fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">أعلى المعلمين نشاطاً في التقييمات (أعلى 10)</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 320px;"><canvas id="chartTopTeachers"></canvas></div>
                </div>
            </div>
        </div>

        <!-- أعلى الفصول بالتقييمات -->
        <div class="col-lg-6 dashboard-section" data-id="chart_top_classes_evals"
            data-section-id="chart_top_classes_evals" data-default-size="col-lg-6">
            <div class="card premium-card animate-up delay-4 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-warning-subtle me-3 text-warning d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-medal fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">أكثر الفصول حصداً للتقييمات (أعلى 10)</h6>
                    </div>

                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 320px;"><canvas id="chartTopClassesEvals"></canvas></div>
                </div>
            </div>
        </div>

        <!-- أعلى المستخدمين نشاطاً بالعمليات -->
        <div class="col-lg-6 dashboard-section" data-id="chart_top_users_actions"
            data-section-id="chart_top_users_actions" data-default-size="col-lg-6">
            <div class="card premium-card animate-up delay-2 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-primary-subtle me-3 text-primary d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-user-clock fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">أعلى المستخدمين نشاطاً بالعمليات (أعلى 10)</h6>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 320px;"><canvas id="chartTopUsersActions"></canvas></div>
                </div>
            </div>
        </div>
        <!-- الدروس والمواد المرفوعة حسب الصف -->
        <div class="col-lg-6 dashboard-section" data-id="chart_materials_per_grade"
            data-section-id="chart_materials_per_grade" data-default-size="col-lg-6">
            <div class="card premium-card animate-up delay-3 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-success-subtle me-3 text-success d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-file-alt fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">الدروس والمواد المرفوعة حسب الصف</h6>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 320px;"><canvas id="chartMaterialsPerGrade"></canvas></div>
                </div>
            </div>
        </div>

        <!-- استهلاك الذكاء الاصطناعي اليومي -->
        <div class="col-12 dashboard-section" data-id="chart_ai_api_usage" data-section-id="chart_ai_api_usage"
            data-default-size="col-12">
            <div class="card premium-card animate-up delay-4 shadow-sm border-0 h-100">
                <div
                    class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                    <div class="d-flex align-items-center header-drag-handle" style="cursor: move;">
                        <div class="rounded-circle p-2 bg-warning-subtle me-3 text-warning d-flex align-items-center justify-content-center"
                            style="width: 38px; height: 38px;">
                            <i class="fas fa-brain fs-6"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark ms-2">استهلاك الـ AI API اليومي (آخر 10 أيام نشطة)</h6>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div style="position: relative; height: 350px;"><canvas id="chartAiApiUsage"></canvas></div>
                </div>
            </div>
        </div>

    </div> <!-- End of dashboard-sections-sortable -->
</div>
<!-- End of dashboard-canvas --><!-- Customization Floating Button (Removed as per standard, but kept for legacy styles if needed, actually let's hide it) -->



<!-- Dashboard Customizer Offcanvas -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashboardSettings" aria-labelledby="dashboardSettingsLabel">
    <div class="offcanvas-header bg-dark text-white p-4">
        <h5 class="offcanvas-title fw-bold" id="dashboardSettingsLabel"><i class="fas fa-cog me-2"></i>تخصيص لوحة التحكم
        </h5>
        <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas"
            aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-4">
        <p class="text-muted small mb-4">قم بتفعيل أو إخفاء العناصر التي تريدها في لوحة التحكم الخاصة بك. سيتم حفظ
            خياراتك تلقائياً.</p>

        <!-- Quick Presets -->
        <div class="mb-4">
            <h6 class="text-uppercase text-muted fw-bold small mb-3"><i class="fas fa-magic me-1 text-primary"></i>
                القوالب الجاهزة</h6>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="all">
                    <i class="fas fa-th-large me-1"></i> عرض الكل
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn"
                    data-preset="academic">
                    <i class="fas fa-graduation-cap me-1"></i> أكاديمي
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="admin">
                    <i class="fas fa-users-cog me-1"></i> إداري
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="minimal">
                    <i class="fas fa-eye-slash me-1"></i> مبسط
                </button>
            </div>
        </div>

        <div class="mb-4 border-top pt-3">
            <h6 class="text-uppercase text-muted fw-bold small mb-3">كروت الإحصائيات</h6>
            <div class="custom-checks-group">
                <?php
                $cards = [
                    ['id' => 'stages', 'label' => 'المراحل الدراسية'],
                    ['id' => 'grades', 'label' => 'الصفوف الدراسية'],
                    ['id' => 'classes', 'label' => 'الفصول'],
                    ['id' => 'subjects', 'label' => 'المواد الدراسية'],
                    ['id' => 'staff', 'label' => 'العاملين'],
                    ['id' => 'students', 'label' => 'الطلاب'],
                    ['id' => 'evaluation_types', 'label' => 'أنواع التقييم'],
                    ['id' => 'total_evaluations', 'label' => 'إجمالي التقييمات'],
                    ['id' => 'notifications', 'label' => 'التنبيهات'],
                    ['id' => 'attendance_today', 'label' => 'الحضور اليوم'],
                    ['id' => 'transport', 'label' => 'النقل المدرسي'],
                    ['id' => 'external_teachers', 'label' => 'المدربين الخارجيين'],
                    ['id' => 'training', 'label' => 'التدريب والتطوير'],
                    ['id' => 'library', 'label' => 'المكتبة'],
                    ['id' => 'clinic', 'label' => 'العيادة المدرسية'],
                    ['id' => 'graduates', 'label' => 'الخريجون'],
                    ['id' => 'admins', 'label' => 'الإداريون'],
                    ['id' => 'ai_lessons', 'label' => 'الدروس الذكية (AI)'],
                    ['id' => 'internal_transfers', 'label' => 'التحويلات الداخلية'],
                    ['id' => 'published_reports', 'label' => 'التقارير المنشورة'],
                    ['id' => 'activities', 'label' => 'الأنشطة التفاعلية'],
                    ['id' => 'action_logs', 'label' => 'سجلات العمليات'],
                    ['id' => 'materials', 'label' => 'المواد المرفوعة'],
                    ['id' => 'student_guardians', 'label' => 'أولياء الأمور'],
                    ['id' => 'student_siblings', 'label' => 'أشقاء الطلاب'],
                    ['id' => 'ai_api_logs', 'label' => 'طلبات الـ AI API']
                ];
                foreach ($cards as $c): ?>
                    <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                        <label class="form-check-label fw-bold"
                            for="check_<?php echo $c['id']; ?>"><?php echo $c['label']; ?></label>
                        <input class="form-check-input widget-toggle ms-0" type="checkbox"
                            id="check_<?php echo $c['id']; ?>" data-target="<?php echo $c['id']; ?>" checked>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-4 border-top pt-3">
            <h6 class="text-uppercase text-muted fw-bold small mb-3">الرسوم البيانية</h6>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_students_stage">توزيع الطلاب حسب
                    المرحلة</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_students_stage"
                    data-section="chart_students_stage" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_students_grade">توزيع الطلاب حسب الصف</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_students_grade"
                    data-section="chart_students_grade" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_evaluations">التقييمات الشهرية</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_evaluations"
                    data-section="chart_evaluations" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_staff">توزيع العاملين</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_staff"
                    data-section="chart_staff" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_students_per_class">كثافة الفصول</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_students_per_class"
                    data-section="chart_students_per_class" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_top_teachers">أعلى المعلمين بالتقييمات</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_top_teachers"
                    data-section="chart_top_teachers" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_top_classes_evals">أكثر الفصول
                    بالتقييمات</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_top_classes_evals"
                    data-section="chart_top_classes_evals" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_top_users_actions">أعلى المستخدمين
                    بالعمليات</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_top_users_actions"
                    data-section="chart_top_users_actions" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_materials_per_grade">المواد المرفوعة حسب
                    الصف</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_materials_per_grade"
                    data-section="chart_materials_per_grade" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_chart_ai_api_usage">استهلاك الـ AI API اليومي</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_chart_ai_api_usage"
                    data-section="chart_ai_api_usage" checked>
            </div>
        </div>

        <div class="mb-4 border-top pt-3">
            <h6 class="text-uppercase text-muted fw-bold small mb-3">الأقسام واللوحات الإضافية</h6>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_quick_actions">لوحة الإجراءات السريعة</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_quick_actions"
                    data-section="quick_actions" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="check_ai_insights">لوحة تحليلات الذكاء الاصطناعي</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="check_ai_insights"
                    data-section="ai_insights" checked>
            </div>
        </div>

        <!-- Reset Button -->
        <div class="mt-4 pt-3 border-top">
            <button type="button" class="btn btn-danger w-100 py-2 btn-sm" id="reset-dashboard-prefs">
                <i class="fas fa-undo me-2"></i> استعادة الإعدادات الافتراضية
            </button>
        </div>

        <div class="alert alert-primary py-2 small mt-4">
            <i class="fas fa-info-circle me-1"></i> يتم الحفظ تلقائياً في المتصفح.
        </div>
    </div>
</div>

<!-- SortableJS Library -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<?php require __DIR__ . '/../classes/Presentation/Dashboard/interactions.php'; ?>

<style>
    .dashboard-card {
        transition: all 0.3s ease;
    }

    .dashboard-section {
        transition: all 0.3s ease;
    }

    /* SortableJS Styles */
    .sortable-ghost {
        opacity: 0.4;
        border: 2px dashed var(--primary-color, #3b82f6) !important;
        background-color: #eff6ff !important;
        border-radius: 12px;
    }

    .sortable-drag {
        opacity: 0.9;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
    }

    .header-drag-handle {
        cursor: grab;
    }

    .header-drag-handle:active {
        cursor: grabbing;
    }

    .customizer-sidebar .form-switch .form-check-input {
        width: 2.8em;
        height: 1.4em;
    }

    /* Quick Action Styles */
    .quick-action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        text-decoration: none !important;
        padding: 1rem 0.5rem;
        background: #fff;
        border: 1px solid #f1f5f9;
        border-radius: 12px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        height: 100%;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }

    .quick-action-btn:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
        border-color: #e2e8f0;
    }

    .quick-action-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        margin-bottom: 0.75rem;
        transition: all 0.3s ease;
    }

    .quick-action-btn:hover .quick-action-icon {
        transform: scale(1.1);
    }

    .quick-action-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #334155;
        transition: color 0.3s ease;
    }

    .quick-action-btn:hover .quick-action-label {
        color: #0f172a;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php require __DIR__ . '/../classes/Presentation/Dashboard/charts.php'; ?>

<?php
// Include footer
include_once '../includes/admin_footer.php';
?>
