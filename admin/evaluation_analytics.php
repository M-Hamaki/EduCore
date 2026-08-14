<?php
// Set page title
$page_title = "الإحصائيات والتحليلات";
$custom_page_title = true; // This page has its own custom title

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Validate admin session
Utilities::validateSession('admin');

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();

function evaluation_analytics_scope(?array $allowedClassIds, int $academicYearId, string $alias): string
{
    $conditions = [];
    if ($academicYearId > 0) {
        $conditions[] = "({$alias}.academic_year_id = {$academicYearId} OR {$alias}.academic_year_id IS NULL)";
    }
    if ($allowedClassIds !== null) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds), static fn(int $id): bool => $id > 0)));
        $conditions[] = $ids === [] ? '1 = 0' : "{$alias}.class_id IN (" . implode(',', $ids) . ')';
    }
    return $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
}

$analyticsEvaluationScope = evaluation_analytics_scope($allowedClassIds, $currentAcademicYearId, 'e');
$analyticsTotalEvaluationScope = evaluation_analytics_scope($allowedClassIds, $currentAcademicYearId, 'e_total');
$analyticsEnrollmentClassScope = $allowedClassIds === null
    ? '1 = 1'
    : (($allowedClassIds ?? []) === []
        ? '1 = 0'
        : 'se.class_id IN (' . implode(',', array_map('intval', $allowedClassIds)) . ')');
$analyticsClassScope = $allowedClassIds === null
    ? '1 = 1'
    : (($allowedClassIds ?? []) === []
        ? '1 = 0'
        : 'c.id IN (' . implode(',', array_map('intval', $allowedClassIds)) . ')');
$analyticsTotalEnrollmentScope = $allowedClassIds === null
    ? '1 = 1'
    : (($allowedClassIds ?? []) === []
        ? '1 = 0'
        : 'se_total.class_id IN (' . implode(',', array_map('intval', $allowedClassIds)) . ')');

// Initialize objects
$user = new User($db);
$classroom = new ClassRoom($db);
$evaluation = new Evaluation($db);
$evaluation_type = new EvaluationType($db);

// Include header
include_once '../includes/admin_header.php';

// Get comprehensive statistics for all data (no class restrictions for admin)

if (!function_exists('analyticsLimit')) {
    function analyticsLimit(string $param, int $default = 10, int $min = 5, int $max = 50): int
    {
        if (!isset($_GET[$param])) {
            return $default;
        }

        $value = (int)$_GET[$param];
        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return $value;
    }
}

$analyticsLimits = [
    'top_students' => analyticsLimit('top_students', 10),
    'class_performance' => analyticsLimit('class_performance', 10),
    'attention_students' => analyticsLimit('attention_students', 15),
    'teacher_efficiency' => analyticsLimit('teacher_efficiency', 10),
    'recent_achievements' => analyticsLimit('recent_achievements', 10)
];

// 1. Total positive points
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
                  WHERE {$analyticsEvaluationScope}";
$stmt_positive = $db->prepare($query_positive);
$stmt_positive->execute();
$total_positive = $stmt_positive->fetch(PDO::FETCH_ASSOC)['total'];

// 2. Total negative points
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
                  WHERE {$analyticsEvaluationScope}";
$stmt_negative = $db->prepare($query_negative);
$stmt_negative->execute();
$total_negative = $stmt_negative->fetch(PDO::FETCH_ASSOC)['total'];

// 3. Total evaluations
$query_total = "SELECT COUNT(*) as total FROM evaluations e WHERE {$analyticsEvaluationScope}";
$stmt_total = $db->prepare($query_total);
$stmt_total->execute();
$total_evaluations = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

// 4. Total students
$query_total_students = "SELECT COUNT(DISTINCT se.student_id) as total FROM student_enrollments se
    JOIN users s ON s.id = se.student_id AND s.role = 'student' AND s.deleted_at IS NULL
    WHERE se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
      AND {$analyticsEnrollmentClassScope}";
$stmt_total_students = $db->prepare($query_total_students);
$stmt_total_students->execute();
$total_students = $stmt_total_students->fetch(PDO::FETCH_ASSOC)['total'];

// 5. Average points per student
$net_points = $total_positive - $total_negative;
$average_points_per_student = $total_students > 0 ? round($net_points / $total_students, 2) : 0;

// 6. Most active class
$query_active_class = "SELECT c.name as class_name, COUNT(*) as evaluation_count
                      FROM evaluations e
                      JOIN classes c ON e.class_id = c.id
                      WHERE {$analyticsEvaluationScope}
                      GROUP BY c.id, c.name
                      ORDER BY evaluation_count DESC
                      LIMIT 1";
$stmt_active_class = $db->prepare($query_active_class);
$stmt_active_class->execute();
$active_class = $stmt_active_class->fetch(PDO::FETCH_ASSOC);
$most_active_class = $active_class ? $active_class['class_name'] : 'لا يوجد';
$most_active_class_count = $active_class ? $active_class['evaluation_count'] : 0;

// 7. This week's evaluations
$query_week = "SELECT COUNT(*) as total FROM evaluations e
               WHERE {$analyticsEvaluationScope} AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
$stmt_week = $db->prepare($query_week);
$stmt_week->execute();
$week_evaluations = $stmt_week->fetch(PDO::FETCH_ASSOC)['total'];

// 8. Today's evaluations
$query_today = "SELECT COUNT(*) as total FROM evaluations e
                WHERE {$analyticsEvaluationScope} AND DATE(e.date_created) = CURRENT_DATE()";
$stmt_today = $db->prepare($query_today);
$stmt_today->execute();
$today_evaluations = $stmt_today->fetch(PDO::FETCH_ASSOC)['total'];

// 9. Most active teachers
$query_active_teachers = "SELECT t.name as teacher_name, COUNT(*) as evaluation_count
                         FROM evaluations e
                         JOIN users t ON e.teacher_id = t.id
WHERE EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = t.id AND ura.role_key = 'teacher' AND ura.status = 'active')
  AND {$analyticsEvaluationScope}
                         GROUP BY t.id
                         ORDER BY evaluation_count DESC
                         LIMIT 5";
$stmt_active_teachers = $db->prepare($query_active_teachers);
$stmt_active_teachers->execute();

// 10. Monthly evaluation trend (sargable date filter)
$query_monthly = "SELECT 
                                 MONTH(date_created) as month_num,
                                 MONTHNAME(date_created) as month_name,
                                 COUNT(*) as count
                                 FROM evaluations e
                                 WHERE {$analyticsEvaluationScope}
                                   AND e.date_created >= DATE_FORMAT(CURRENT_DATE(), '%Y-01-01')
                                   AND e.date_created < DATE_ADD(DATE_FORMAT(CURRENT_DATE(), '%Y-01-01'), INTERVAL 1 YEAR)
                                 GROUP BY MONTH(e.date_created)
                                 ORDER BY MONTH(e.date_created)";
$stmt_monthly = $db->prepare($query_monthly);
$stmt_monthly->execute();
$monthly_data = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);

// 11. Top performing students across all classes
$query_top_students = "SELECT s.id, s.name, c.name as class_name,
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
                      (COALESCE(SUM(
                        CASE 
                            WHEN e.custom_points IS NOT NULL THEN 
                                CASE WHEN e.custom_points > 0 THEN e.custom_points ELSE 0 END
                            ELSE 
                                CASE WHEN et.type = 'positive' THEN et.points ELSE 0 END
                        END
                      ), 0) - COALESCE(SUM(
                        CASE 
                            WHEN e.custom_points IS NOT NULL THEN 
                                CASE WHEN e.custom_points < 0 THEN ABS(e.custom_points) ELSE 0 END
                            ELSE 
                                CASE WHEN et.type = 'negative' THEN et.points ELSE 0 END
                        END
                      ), 0)) as net_points
                      FROM student_enrollments se
                      JOIN users s ON s.id = se.student_id AND s.role = 'student'
                      JOIN classes c ON c.id = se.class_id
                      LEFT JOIN evaluations e ON s.id = e.student_id AND e.class_id = se.class_id AND {$analyticsEvaluationScope}
                      LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                      WHERE se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
                        AND {$analyticsEnrollmentClassScope}
                      GROUP BY s.id
                      ORDER BY net_points DESC
                      LIMIT " . $analyticsLimits['top_students'];
$stmt_top_students = $db->prepare($query_top_students);
$stmt_top_students->execute();

// 12. Class performance comparison
$query_class_performance = "SELECT c.name as class_name,
                           COUNT(DISTINCT s.id) as student_count,
                           COUNT(e.id) as total_evaluations,
                           COALESCE(SUM(
                             CASE 
                                 WHEN e.custom_points IS NOT NULL THEN 
                                     CASE WHEN e.custom_points > 0 THEN e.custom_points ELSE 0 END
                                 ELSE 
                                     CASE WHEN et.type = 'positive' THEN et.points ELSE 0 END
                             END
                           ), 0) as total_positive,
                           COALESCE(AVG(
                             CASE 
                                 WHEN e.custom_points IS NOT NULL THEN 
                                     e.custom_points
                                 ELSE 
                                     CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                             END
                           ), 0) as avg_points_per_evaluation
                           FROM classes c
                           LEFT JOIN student_enrollments se ON se.class_id = c.id
                               AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
                           LEFT JOIN users s ON s.id = se.student_id AND s.role = 'student'
                           LEFT JOIN evaluations e ON s.id = e.student_id AND e.class_id = c.id AND {$analyticsEvaluationScope}
                           LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                           WHERE {$analyticsClassScope}
                           GROUP BY c.id
                           ORDER BY total_positive DESC
                           LIMIT " . $analyticsLimits['class_performance'];
$stmt_class_performance = $db->prepare($query_class_performance);
$stmt_class_performance->execute();

// 13. Evaluation types usage statistics
$query_eval_types_stats = "SELECT et.name as evaluation_type,
                          et.type as eval_type,
                          et.points as default_points,
                          COUNT(e.id) as usage_count,
                          ROUND(COUNT(e.id) * 100.0 / NULLIF((SELECT COUNT(*) FROM evaluations e_total WHERE {$analyticsTotalEvaluationScope}), 0), 2) as usage_percentage
                          FROM evaluation_types et
                          LEFT JOIN evaluations e ON et.id = e.evaluation_type_id AND {$analyticsEvaluationScope}
                          GROUP BY et.id
                          ORDER BY usage_count DESC";
$stmt_eval_types_stats = $db->prepare($query_eval_types_stats);
$stmt_eval_types_stats->execute();

// 14. Recent activity summary (last 30 days)
$query_recent_activity = "SELECT 
                         COUNT(*) as total_evaluations_30d,
                         COUNT(DISTINCT e.student_id) as active_students_30d,
                         COUNT(DISTINCT e.teacher_id) as active_teachers_30d,
                         COUNT(DISTINCT e.class_id) as active_classes_30d
                         FROM evaluations e
                         WHERE {$analyticsEvaluationScope} AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)";
$stmt_recent_activity = $db->prepare($query_recent_activity);
$stmt_recent_activity->execute();
$recent_activity = $stmt_recent_activity->fetch(PDO::FETCH_ASSOC);

// 15. Daily activity for last 7 days
$query_daily_activity = "SELECT 
                        DATE(date_created) as activity_date,
                        COUNT(*) as daily_evaluations,
                        COUNT(DISTINCT student_id) as daily_students,
                        COUNT(DISTINCT teacher_id) as daily_teachers
                        FROM evaluations e
                        WHERE {$analyticsEvaluationScope} AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
                        GROUP BY DATE(e.date_created)
                        ORDER BY activity_date DESC";
$stmt_daily_activity = $db->prepare($query_daily_activity);
$stmt_daily_activity->execute();

// 16. Teacher efficiency analysis
$query_teacher_efficiency = "SELECT 
                            t.name as teacher_name,
                            COUNT(e.id) as total_evaluations,
                            COUNT(DISTINCT e.student_id) as students_evaluated,
                            COUNT(DISTINCT DATE(e.date_created)) as active_days,
                            ROUND(COUNT(e.id) / COUNT(DISTINCT DATE(e.date_created)), 2) as avg_evaluations_per_day,
                            SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) as positive_evaluations,
                            SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END) as negative_evaluations,
                            ROUND((SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) * 100.0 / COUNT(e.id)), 1) as positive_percentage
                            FROM users t
                            LEFT JOIN evaluations e ON t.id = e.teacher_id AND {$analyticsEvaluationScope}
                            LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
WHERE EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = t.id AND ura.role_key = 'teacher' AND ura.status = 'active')
  AND e.id IS NOT NULL
                            GROUP BY t.id
                            HAVING COUNT(e.id) > 0
                            ORDER BY total_evaluations DESC
                            LIMIT " . $analyticsLimits['teacher_efficiency'];
$stmt_teacher_efficiency = $db->prepare($query_teacher_efficiency);
$stmt_teacher_efficiency->execute();

// 17. Students needing attention (low/negative points)
$query_attention_students = "SELECT 
                            s.name as student_name,
                            c.name as class_name,
                            COALESCE(SUM(
                              CASE 
                                  WHEN e.custom_points IS NOT NULL THEN e.custom_points
                                  ELSE CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                              END
                            ), 0) as total_points,
                            COUNT(e.id) as evaluation_count,
                            SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END) as negative_count
                            FROM student_enrollments se
                            JOIN users s ON s.id = se.student_id AND s.role = 'student'
                            JOIN classes c ON c.id = se.class_id
                            LEFT JOIN evaluations e ON s.id = e.student_id AND e.class_id = se.class_id AND {$analyticsEvaluationScope}
                            LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                            WHERE se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
                              AND {$analyticsEnrollmentClassScope}
                            GROUP BY s.id
                            HAVING total_points < 10 OR negative_count >= 3
                            ORDER BY total_points ASC, negative_count DESC
                            LIMIT " . $analyticsLimits['attention_students'];
$stmt_attention_students = $db->prepare($query_attention_students);
$stmt_attention_students->execute();

// 18. Enhanced Evaluation trends by school hours (8 AM - 3 PM) organized by complete hours
$query_hourly_trends = "WITH time_slots AS (
    SELECT '8:00 - 9:00 صباحاً' as time_period, 8 as hour_num, 1 as sort_order
    UNION ALL SELECT '9:00 - 10:00 صباحاً', 9, 2
    UNION ALL SELECT '10:00 - 11:00 صباحاً', 10, 3  
    UNION ALL SELECT '11:00 - 12:00 صباحاً', 11, 4
    UNION ALL SELECT '12:00 - 1:00 مساءً', 12, 5
    UNION ALL SELECT '1:00 - 2:00 مساءً', 13, 6
    UNION ALL SELECT '2:00 - 3:00 مساءً', 14, 7
    UNION ALL SELECT 'خارج أوقات الدوام (3:00 مساءً - 8:00 صباحاً)', NULL, 99
)
SELECT 
    ts.time_period,
    ts.sort_order,
    COALESCE(COUNT(e.id), 0) as evaluation_count,
    COALESCE(ROUND(AVG(CASE 
        WHEN e.custom_points IS NOT NULL THEN e.custom_points
        ELSE CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
    END), 1), 0) as avg_points,
    COALESCE(COUNT(DISTINCT e.student_id), 0) as unique_students,
    COALESCE(COUNT(DISTINCT e.teacher_id), 0) as unique_teachers,
    COALESCE(SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END), 0) as positive_count,
    COALESCE(SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END), 0) as negative_count
FROM time_slots ts
LEFT JOIN evaluations e ON (
    (ts.hour_num IS NOT NULL AND HOUR(e.date_created) = ts.hour_num) OR
    (ts.hour_num IS NULL AND HOUR(e.date_created) NOT IN (8,9,10,11,12,13,14))
) AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) AND {$analyticsEvaluationScope}
LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
GROUP BY ts.time_period, ts.sort_order, ts.hour_num
ORDER BY ts.sort_order";
$stmt_hourly_trends = $db->prepare($query_hourly_trends);
$stmt_hourly_trends->execute();

// 19. Class behavior patterns
$query_class_behavior = "SELECT 
                        c.name as class_name,
                        COUNT(e.id) as total_evaluations,
                        SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) as positive_count,
                        SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END) as negative_count,
                        ROUND((SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) * 100.0 / COUNT(e.id)), 1) as positive_rate,
                        ROUND(COUNT(e.id) / COUNT(DISTINCT s.id), 2) as avg_evaluations_per_student
                        FROM classes c
                        LEFT JOIN student_enrollments se ON se.class_id = c.id
                            AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
                        LEFT JOIN users s ON s.id = se.student_id AND s.role = 'student'
                        LEFT JOIN evaluations e ON s.id = e.student_id AND e.class_id = c.id AND {$analyticsEvaluationScope}
                        LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                        WHERE e.id IS NOT NULL AND {$analyticsClassScope}
                        GROUP BY c.id
                        ORDER BY positive_rate DESC, total_evaluations DESC";
$stmt_class_behavior = $db->prepare($query_class_behavior);
$stmt_class_behavior->execute();

// 20. Most active students (based on evaluation count)
$query_active_students = "SELECT 
                         s.name as student_name,
                         c.name as class_name,
                         COUNT(e.id) as total_evaluations,
                         SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) as positive_evaluations,
                         SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END) as negative_evaluations,
                         COUNT(DISTINCT DATE(e.date_created)) as active_days,
                         COUNT(DISTINCT e.teacher_id) as different_teachers,
                         ROUND(COUNT(e.id) / COUNT(DISTINCT DATE(e.date_created)), 2) as avg_evaluations_per_day
                         FROM student_enrollments se
                         JOIN users s ON s.id = se.student_id AND s.role = 'student'
                         JOIN evaluations e ON s.id = e.student_id AND e.class_id = se.class_id
                         JOIN evaluation_types et ON e.evaluation_type_id = et.id
                         JOIN classes c ON c.id = se.class_id
                         WHERE se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
                           AND {$analyticsEnrollmentClassScope} AND {$analyticsEvaluationScope}
                         GROUP BY s.id
                         ORDER BY total_evaluations DESC, positive_evaluations DESC
                         LIMIT " . $analyticsLimits['top_students'];
$stmt_active_students = $db->prepare($query_active_students);
$stmt_active_students->execute();

// 21. Weekly performance trends
$query_weekly_trends = "SELECT 
                       YEARWEEK(date_created, 1) as week_number,
                       WEEK(date_created, 1) as week_of_year,
                       YEAR(date_created) as year,
                       COUNT(*) as total_evaluations,
                       SUM(CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END) as positive_count,
                       SUM(CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END) as negative_count,
                       COUNT(DISTINCT e.student_id) as active_students,
                       COUNT(DISTINCT e.teacher_id) as active_teachers
                       FROM evaluations e
                       JOIN evaluation_types et ON e.evaluation_type_id = et.id
                       WHERE {$analyticsEvaluationScope} AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 8 WEEK)
                       GROUP BY YEARWEEK(date_created, 1)
                       ORDER BY week_number DESC";
$stmt_weekly_trends = $db->prepare($query_weekly_trends);
$stmt_weekly_trends->execute();

// 22. Daily evaluation trends - Last 10 days
$query_day_distribution = "SELECT 
                          DATE(date_created) as day_date,
                          DAYNAME(date_created) as day_name,
                          DAY(date_created) as day_of_month,
                          MONTH(date_created) as month,
                          YEAR(date_created) as year,
                          COUNT(*) as evaluation_count,
                          COUNT(DISTINCT student_id) as unique_students,
                          ROUND(AVG(CASE 
                              WHEN e.custom_points IS NOT NULL THEN e.custom_points
                              ELSE CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                          END), 2) as avg_points
                          FROM evaluations e
                          JOIN evaluation_types et ON e.evaluation_type_id = et.id
                          WHERE {$analyticsEvaluationScope} AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 10 DAY)
                          GROUP BY DATE(date_created)
                          ORDER BY day_date DESC";
$stmt_day_distribution = $db->prepare($query_day_distribution);
$stmt_day_distribution->execute();

// استعلام teacher_interaction تم إزالته لأنه لم يعد مستخدماً

// 24. Enhanced Points distribution analysis with wider ranges - Show all ranges
$query_points_distribution = "SELECT 
                             ranges.point_range,
                             COALESCE(student_stats.student_count, 0) as student_count,
                             COALESCE(student_stats.percentage, 0) as percentage,
                             COALESCE(student_stats.min_points, 0) as min_points,
                             COALESCE(student_stats.max_points, 0) as max_points,
                             COALESCE(student_stats.avg_points, 0) as avg_points
                             FROM (
                                 SELECT '500+ نقطة' as point_range, 1 as sort_order
                                 UNION SELECT '400-499 نقطة', 2
                                 UNION SELECT '300-399 نقطة', 3
                                 UNION SELECT '200-299 نقطة', 4
                                 UNION SELECT '150-199 نقطة', 5
                                 UNION SELECT '100-149 نقطة', 6
                                 UNION SELECT '75-99 نقطة', 7
                                 UNION SELECT '50-74 نقطة', 8
                                 UNION SELECT '25-49 نقطة', 9
                                 UNION SELECT '0-24 نقطة', 10
                                 UNION SELECT 'نقاط سالبة', 11
                             ) ranges
                             LEFT JOIN (
                                 SELECT 
                                 CASE 
                                     WHEN total_points >= 500 THEN '500+ نقطة'
                                     WHEN total_points >= 400 THEN '400-499 نقطة'
                                     WHEN total_points >= 300 THEN '300-399 نقطة'
                                     WHEN total_points >= 200 THEN '200-299 نقطة'
                                     WHEN total_points >= 150 THEN '150-199 نقطة'
                                     WHEN total_points >= 100 THEN '100-149 نقطة'
                                     WHEN total_points >= 75 THEN '75-99 نقطة'
                                     WHEN total_points >= 50 THEN '50-74 نقطة'
                                     WHEN total_points >= 25 THEN '25-49 نقطة'
                                     WHEN total_points >= 0 THEN '0-24 نقطة'
                                     ELSE 'نقاط سالبة'
                                 END as point_range,
                                 COUNT(*) as student_count,
                                 ROUND(COUNT(*) * 100.0 / NULLIF((SELECT COUNT(DISTINCT se_total.student_id)
                                     FROM student_enrollments se_total
                                     WHERE se_total.academic_year_id = {$currentAcademicYearId}
                                       AND se_total.enrollment_status = 'enrolled'
                                       AND {$analyticsTotalEnrollmentScope}), 0), 1) as percentage,
                                 MIN(total_points) as min_points,
                                 MAX(total_points) as max_points,
                                 ROUND(AVG(total_points), 1) as avg_points
                                 FROM (
                                     SELECT s.id,
                                     COALESCE(SUM(
                                         CASE 
                                             WHEN e.custom_points IS NOT NULL THEN e.custom_points
                                             ELSE CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                                         END
                                     ), 0) as total_points
                                     FROM student_enrollments se
                                     JOIN users s ON s.id = se.student_id AND s.role = 'student'
                                     LEFT JOIN evaluations e ON s.id = e.student_id AND e.class_id = se.class_id AND {$analyticsEvaluationScope}
                                     LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                                     WHERE se.academic_year_id = {$currentAcademicYearId}
                                       AND se.enrollment_status = 'enrolled'
                                       AND {$analyticsEnrollmentClassScope}
                                     GROUP BY s.id
                                 ) as student_points
                                 GROUP BY 
                                 CASE 
                                     WHEN total_points >= 500 THEN '500+ نقطة'
                                     WHEN total_points >= 400 THEN '400-499 نقطة'
                                     WHEN total_points >= 300 THEN '300-399 نقطة'
                                     WHEN total_points >= 200 THEN '200-299 نقطة'
                                     WHEN total_points >= 150 THEN '150-199 نقطة'
                                     WHEN total_points >= 100 THEN '100-149 نقطة'
                                     WHEN total_points >= 75 THEN '75-99 نقطة'
                                     WHEN total_points >= 50 THEN '50-74 نقطة'
                                     WHEN total_points >= 25 THEN '25-49 نقطة'
                                     WHEN total_points >= 0 THEN '0-24 نقطة'
                                     ELSE 'نقاط سالبة'
                                 END
                             ) student_stats ON ranges.point_range = student_stats.point_range
                             ORDER BY ranges.sort_order";
$stmt_points_distribution = $db->prepare($query_points_distribution);
$stmt_points_distribution->execute();

// 25. Recent achievements (students with significant point gains)
$query_recent_achievements = "SELECT 
                             s.name as student_name,
                             c.name as class_name,
                             COUNT(e.id) as recent_evaluations,
                             SUM(CASE 
                                 WHEN e.custom_points IS NOT NULL THEN 
                                     CASE WHEN e.custom_points > 0 THEN e.custom_points ELSE 0 END
                                 ELSE 
                                     CASE WHEN et.type = 'positive' THEN et.points ELSE 0 END
                             END) as recent_positive_points,
                             COUNT(DISTINCT DATE(e.date_created)) as active_days_recent
                             FROM student_enrollments se
                             JOIN users s ON s.id = se.student_id AND s.role = 'student'
                             JOIN evaluations e ON s.id = e.student_id AND e.class_id = se.class_id
                             JOIN evaluation_types et ON e.evaluation_type_id = et.id
                             JOIN classes c ON c.id = se.class_id
                             WHERE se.academic_year_id = {$currentAcademicYearId}
                             AND se.enrollment_status = 'enrolled'
                             AND {$analyticsEnrollmentClassScope}
                             AND {$analyticsEvaluationScope}
                             AND e.date_created >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
                             GROUP BY s.id
                             HAVING recent_positive_points >= 10
                             ORDER BY recent_positive_points DESC, recent_evaluations DESC
                             LIMIT " . $analyticsLimits['recent_achievements'];
$stmt_recent_achievements = $db->prepare($query_recent_achievements);
$stmt_recent_achievements->execute();
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12 animate-up">
            <h2 class="mb-4">
                <i class="fas fa-chart-line me-2 text-primary"></i>
                الإحصائيات والتحليلات المتقدمة
            </h2>
            <p class="text-muted mb-4">تحليل عميق لأداء الطلاب والمعلمين وأنماط السلوك المدرسي</p>
        </div>
    </div>

    <!-- Primary Statistics Cards - صف أول -->

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-3">
        <div class="col animate-up delay-1">
            <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                <div class="stat-card-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $total_positive; ?>">0</div>
                    <div class="stat-card-label">النقاط الإيجابية</div>
                    <div class="stat-card-sub"><i class="fas fa-arrow-up"></i> إجمالي النقاط المكتسبة</div>
                </div>
            </div>
        </div>
        <div class="col animate-up delay-2">
            <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
                <div class="stat-card-icon"><i class="fas fa-minus-circle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $total_negative; ?>">0</div>
                    <div class="stat-card-label">النقاط السالبة</div>
                    <div class="stat-card-sub"><i class="fas fa-arrow-down"></i> إجمالي النقاط المخصومة</div>
                </div>
            </div>
        </div>
        <div class="col animate-up delay-3">
            <div class="stat-card" style="--card-gradient: var(--info-gradient);">
                <div class="stat-card-icon"><i class="fas fa-balance-scale"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $net_points; ?>">0</div>
                    <div class="stat-card-label">صافي النقاط</div>
                    <div class="stat-card-sub"><i class="fas fa-chart-line"></i> الفرق بين الإيجابي والسالب</div>
                </div>
            </div>
        </div>
        <div class="col animate-up delay-4">
            <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                <div class="stat-card-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $average_points_per_student; ?>">0</div>
                    <div class="stat-card-label">متوسط النقاط</div>
                    <div class="stat-card-sub"><i class="fas fa-user"></i> لكل طالب</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Statistics - صف ثاني -->
    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #e83e8c, #be185d);">
                <div class="stat-card-icon"><i class="fas fa-list-alt"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo number_format($total_evaluations); ?></div>
                    <div class="stat-card-label">إجمالي التقييمات</div>
                    <div class="stat-card-sub"><i class="fas fa-clipboard-list"></i> جميع التقييمات المسجلة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-calendar-week"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $week_evaluations; ?></div>
                    <div class="stat-card-label">تقييمات الأسبوع</div>
                    <div class="stat-card-sub"><i class="fas fa-calendar"></i> آخر 7 أيام</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
                <div class="stat-card-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $today_evaluations; ?></div>
                    <div class="stat-card-label">تقييمات اليوم</div>
                    <div class="stat-card-sub"><i class="fas fa-clock"></i> اليوم الحالي</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #6b7280, #4b5563);">
                <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-card-label">إجمالي الطلاب</div>
                    <div class="stat-card-sub"><i class="fas fa-users"></i> جميع الطلاب المسجلين</div>
                </div>
            </div>
        </div>
    </div>

    <?php $limitOptions = [5, 10, 15, 20, 30, 40, 50]; ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="get" class="no-active-filter">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label small text-muted">عدد الطلاب في قائمة الأكثر نشاطاً</label>
                        <select name="top_students" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($limitOptions as $option): ?>
                                <option value="<?php echo $option; ?>" <?php echo $analyticsLimits['top_students'] == $option ? 'selected' : ''; ?>>
                                    <?php echo $option; ?> طالب
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label small text-muted">عدد الطلاب المحتاجين للاهتمام</label>
                        <select name="attention_students" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($limitOptions as $option): ?>
                                <option value="<?php echo $option; ?>" <?php echo $analyticsLimits['attention_students'] == $option ? 'selected' : ''; ?>>
                                    <?php echo $option; ?> طالب
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label small text-muted">عدد المعلمين في التحليل الشامل</label>
                        <select name="teacher_efficiency" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($limitOptions as $option): ?>
                                <option value="<?php echo $option; ?>" <?php echo $analyticsLimits['teacher_efficiency'] == $option ? 'selected' : ''; ?>>
                                    <?php echo $option; ?> معلم
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php if (!empty($_GET)): ?>
                    <?php foreach ($_GET as $paramKey => $paramValue): ?>
                        <?php if (in_array($paramKey, ['top_students', 'attention_students', 'teacher_efficiency'])) continue; ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($paramKey); ?>" value="<?php echo htmlspecialchars($paramValue); ?>">
                    <?php endforeach; ?>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Accordion للتحليلات التفصيلية -->
    <div class="accordion" id="analyticsAccordion">

        <!-- تحليلات الطلاب -->
        <div class="accordion-item">
            <h2 class="accordion-header" id="studentAnalyticsHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#studentAnalyticsCollapse" aria-expanded="false" aria-controls="studentAnalyticsCollapse">
                    <i class="fas fa-user-graduate me-2 text-primary"></i>
                    <strong>تحليلات الطلاب</strong>
                </button>
            </h2>
            <div id="studentAnalyticsCollapse" class="accordion-collapse collapse" aria-labelledby="studentAnalyticsHeading" data-bs-parent="#analyticsAccordion">
                <div class="accordion-body">
                    <!-- أكثر الطلاب نشاطاً -->
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-fire me-2"></i>أكثر الطلاب نشاطاً</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_active_students->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead class="table-light">
                                            <tr>
                                                <th>الترتيب</th>
                                                <th>الطالب</th>
                                                <th>الفصل</th>
                                                <th>إجمالي التقييمات</th>
                                                <th>إيجابي</th>
                                                <th>سلبي</th>
                                                <th>أيام نشطة</th>
                                                <th>معلمين مختلفين</th>
                                                <th>متوسط/يوم</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $rank = 1; while ($student = $stmt_active_students->fetch(PDO::FETCH_ASSOC)): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge <?php echo $rank <= 3 ? 'bg-warning' : 'bg-secondary'; ?> rounded-circle" 
                                                              style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                                                            <?php echo $rank++; ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo htmlspecialchars($student['student_name']); ?></strong></td>
                                                    <td><small class="text-muted"><?php echo htmlspecialchars($student['class_name']); ?></small></td>
                                                    <td><span class="badge bg-primary"><?php echo $student['total_evaluations']; ?></span></td>
                                                    <td><span class="badge bg-success"><?php echo $student['positive_evaluations']; ?></span></td>
                                                    <td><span class="badge bg-danger"><?php echo $student['negative_evaluations']; ?></span></td>
                                                    <td><span class="badge bg-info"><?php echo $student['active_days']; ?></span></td>
                                                    <td><span class="badge bg-secondary"><?php echo $student['different_teachers']; ?></span></td>
                                                    <td><span class="badge bg-warning"><?php echo $student['avg_evaluations_per_day']; ?></span></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات طلاب نشطين
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- الطلاب المحتاجون للاهتمام -->
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>طلاب يحتاجون اهتمام</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_attention_students->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>الترتيب</th>
                                                <th>الطالب</th>
                                                <th>الفصل</th>
                                                <th>إجمالي النقاط</th>
                                                <th>عدد التقييمات</th>
                                                <th>السلبيات</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $rank = 1;
                                            while ($student = $stmt_attention_students->fetch(PDO::FETCH_ASSOC)): 
                                            ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-danger rounded-circle" 
                                                              style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                                                            <?php echo $rank++; ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo htmlspecialchars($student['student_name']); ?></strong></td>
                                                    <td><small class="text-muted"><?php echo htmlspecialchars($student['class_name']); ?></small></td>
                                                    <td>
                                                        <span class="badge <?php echo $student['total_points'] < 0 ? 'bg-danger' : 'bg-warning'; ?>">
                                                            <?php echo $student['total_points']; ?> نقطة
                                                        </span>
                                                    </td>
                                                    <td><span class="badge bg-secondary"><?php echo $student['evaluation_count']; ?></span></td>
                                                    <td>
                                                        <?php if ($student['negative_count'] > 0): ?>
                                                            <span class="badge bg-danger"><?php echo $student['negative_count']; ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success mb-0">
                                    <i class="fas fa-check-circle me-2"></i>
                                    جميع الطلاب يحققون أداءً جيداً
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- تحليلات المعلمين -->
        <div class="accordion-item">
            <h2 class="accordion-header" id="teacherAnalyticsHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#teacherAnalyticsCollapse" aria-expanded="false" aria-controls="teacherAnalyticsCollapse">
                    <i class="fas fa-chalkboard-teacher me-2 text-info"></i>
                    <strong>تحليلات المعلمين</strong>
                </button>
            </h2>
            <div id="teacherAnalyticsCollapse" class="accordion-collapse collapse" aria-labelledby="teacherAnalyticsHeading" data-bs-parent="#analyticsAccordion">
                <div class="accordion-body">
                    <!-- تحليل شامل للمعلمين (كفاءة + تفاعل) -->
                    <div class="card shadow border-0">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>تحليل شامل للمعلمين (الكفاءة والتفاعل)</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_teacher_efficiency->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>الترتيب</th>
                                                <th>المعلم</th>
                                                <th>إجمالي التقييمات</th>
                                                <th>الطلاب المُقيمون</th>
                                                <th>الأيام النشطة</th>
                                                <th>متوسط/يوم</th>
                                                <th>النسبة الإيجابية</th>
                                                <th>الحالة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // إعادة تشغيل الاستعلام
                                            $stmt_teacher_efficiency->execute();
                                            
                                            $rank = 1;
                                            while ($teacher = $stmt_teacher_efficiency->fetch(PDO::FETCH_ASSOC)): 
                                            ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge <?php echo $rank <= 3 ? 'bg-warning' : 'bg-secondary'; ?> rounded-circle" 
                                                              style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                                                            <?php echo $rank++; ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo htmlspecialchars($teacher['teacher_name']); ?></strong></td>
                                                    <td><span class="badge bg-primary"><?php echo $teacher['total_evaluations']; ?></span></td>
                                                    <td><span class="badge bg-success"><?php echo $teacher['students_evaluated']; ?></span></td>
                                                    <td><span class="badge bg-warning"><?php echo $teacher['active_days']; ?></span></td>
                                                    <td><span class="badge bg-secondary"><?php echo $teacher['avg_evaluations_per_day']; ?></span></td>
                                                    <td>
                                                        <span class="badge <?php echo $teacher['positive_percentage'] >= 70 ? 'bg-success' : ($teacher['positive_percentage'] >= 50 ? 'bg-warning' : 'bg-danger'); ?>">
                                                            <?php echo $teacher['positive_percentage']; ?>%
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($teacher['positive_percentage'] >= 70): ?>
                                                            <span class="badge bg-success"><i class="fas fa-thumbs-up me-1"></i>ممتاز</span>
                                                        <?php elseif ($teacher['positive_percentage'] >= 50): ?>
                                                            <span class="badge bg-warning"><i class="fas fa-balance-scale me-1"></i>جيد</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>يحتاج تطوير</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات تحليل معلمين كافية
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- تحليلات الفصول -->
        <div class="accordion-item">
            <h2 class="accordion-header" id="classAnalyticsHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#classAnalyticsCollapse" aria-expanded="false" aria-controls="classAnalyticsCollapse">
                    <i class="fas fa-school me-2 text-warning"></i>
                    <strong>تحليلات الفصول</strong>
                </button>
            </h2>
            <div id="classAnalyticsCollapse" class="accordion-collapse collapse" aria-labelledby="classAnalyticsHeading" data-bs-parent="#analyticsAccordion">
                <div class="accordion-body">
                    <!-- أنماط سلوك الفصول -->
                    <div class="card shadow border-0">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>أنماط سلوك الفصول</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_class_behavior->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead class="table-light">
                                            <tr>
                                                <th>الفصل</th>
                                                <th>إجمالي التقييمات</th>
                                                <th>تقييمات إيجابية</th>
                                                <th>تقييمات سلبية</th>
                                                <th>معدل الإيجابية</th>
                                                <th>متوسط التقييمات/طالب</th>
                                                <th>الحالة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($class = $stmt_class_behavior->fetch(PDO::FETCH_ASSOC)): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($class['class_name']); ?></strong></td>
                                                    <td><span class="badge bg-primary"><?php echo $class['total_evaluations']; ?></span></td>
                                                    <td><span class="badge bg-success"><?php echo $class['positive_count']; ?></span></td>
                                                    <td><span class="badge bg-danger"><?php echo $class['negative_count']; ?></span></td>
                                                    <td>
                                                        <span class="badge <?php echo $class['positive_rate'] >= 75 ? 'bg-success' : ($class['positive_rate'] >= 50 ? 'bg-warning' : 'bg-danger'); ?>">
                                                            <?php echo $class['positive_rate']; ?>%
                                                        </span>
                                                    </td>
                                                    <td><span class="badge bg-info"><?php echo $class['avg_evaluations_per_student']; ?></span></td>
                                                    <td>
                                                        <?php if ($class['positive_rate'] >= 75): ?>
                                                            <span class="badge bg-success"><i class="fas fa-thumbs-up me-1"></i>ممتاز</span>
                                                        <?php elseif ($class['positive_rate'] >= 50): ?>
                                                            <span class="badge bg-warning"><i class="fas fa-balance-scale me-1"></i>جيد</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>يحتاج تحسين</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات أنماط سلوك متاحة
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- تحليلات التقييمات والنقاط -->
        <div class="accordion-item">
            <h2 class="accordion-header" id="evaluationAnalyticsHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#evaluationAnalyticsCollapse" aria-expanded="false" aria-controls="evaluationAnalyticsCollapse">
                    <i class="fas fa-star me-2 text-secondary"></i>
                    <strong>تحليلات التقييمات والنقاط</strong>
                </button>
            </h2>
            <div id="evaluationAnalyticsCollapse" class="accordion-collapse collapse" aria-labelledby="evaluationAnalyticsHeading" data-bs-parent="#analyticsAccordion">
                <div class="accordion-body">
                    <!-- أكثر أنواع التقييم استخدامًا -->
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>أكثر أنواع التقييم استخدامًا</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_eval_types_stats->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead>
                                            <tr>
                                                <th>نوع التقييم</th>
                                                <th>النوع</th>
                                                <th>النقاط الافتراضية</th>
                                                <th>عدد مرات الاستخدام</th>
                                                <th>نسبة الاستخدام</th>
                                                <th>شريط التقدم</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($eval_type = $stmt_eval_types_stats->fetch(PDO::FETCH_ASSOC)): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($eval_type['evaluation_type']); ?></strong></td>
                                                    <td>
                                                        <span class="badge <?php echo $eval_type['eval_type'] == 'positive' ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo $eval_type['eval_type'] == 'positive' ? 'إيجابي' : 'سلبي'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $eval_type['eval_type'] == 'positive' ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo $eval_type['eval_type'] == 'positive' ? '+' : '-'; ?><?php echo $eval_type['default_points']; ?>
                                                        </span>
                                                    </td>
                                                    <td><span class="badge bg-primary"><?php echo $eval_type['usage_count']; ?></span></td>
                                                    <td><span class="badge bg-info"><?php echo $eval_type['usage_percentage']; ?>%</span></td>
                                                    <td>
                                                        <div class="progress" style="height: 8px;">
                                                            <div class="progress-bar <?php echo $eval_type['eval_type'] == 'positive' ? 'bg-success' : 'bg-danger'; ?>" 
                                                                 style="width: <?php echo min($eval_type['usage_percentage'], 100); ?>%"></div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات أنواع تقييم متاحة
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- توزيع الطلاب حسب النقاط -->
                    <div class="card shadow border-0">
                        <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #e83e8c); color: white;">
                            <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>توزيع الطلاب حسب النقاط</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_points_distribution->rowCount() > 0): ?>
                                <!-- جدول تفصيلي للتوزيع -->
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover table-striped table-bordered">
                                        <thead class="table-primary">
                                            <tr>
                                                <th class="text-center">فئة النقاط</th>
                                                <th class="text-center">عدد الطلاب</th>
                                                <th class="text-center">النسبة المئوية</th>
                                                <th class="text-center">أقل نقاط</th>
                                                <th class="text-center">أعلى نقاط</th>
                                                <th class="text-center">متوسط النقاط</th>
                                                <th class="text-center">التقييم</th>
                                                <th class="text-center">التمثيل البصري</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $distribution_data = $stmt_points_distribution->fetchAll(PDO::FETCH_ASSOC);
                                            $total_students = array_sum(array_column($distribution_data, 'student_count'));
                                            
                                            foreach ($distribution_data as $range): 
                                                $color_class = '';
                                                $badge_class = '';
                                                $performance_label = '';
                                                $icon = '';
                                                
                                                switch($range['point_range']) {
                                                    case '500+ نقطة': 
                                                        $color_class = 'bg-gradient bg-success'; 
                                                        $badge_class = 'bg-success'; 
                                                        $performance_label = 'متفوق جداً'; 
                                                        $icon = 'fas fa-crown';
                                                        break;
                                                    case '400-499 نقطة': 
                                                        $color_class = 'bg-gradient bg-info'; 
                                                        $badge_class = 'bg-info'; 
                                                        $performance_label = 'متفوق'; 
                                                        $icon = 'fas fa-star';
                                                        break;
                                                    case '300-399 نقطة': 
                                                        $color_class = 'bg-gradient bg-primary'; 
                                                        $badge_class = 'bg-primary'; 
                                                        $performance_label = 'ممتاز'; 
                                                        $icon = 'fas fa-medal';
                                                        break;
                                                    case '200-299 نقطة': 
                                                        $color_class = 'bg-gradient bg-success'; 
                                                        $badge_class = 'bg-success'; 
                                                        $performance_label = 'جيد جداً'; 
                                                        $icon = 'fas fa-thumbs-up';
                                                        break;
                                                    case '150-199 نقطة': 
                                                        $color_class = 'bg-gradient bg-info'; 
                                                        $badge_class = 'bg-info'; 
                                                        $performance_label = 'جيد'; 
                                                        $icon = 'fas fa-check-circle';
                                                        break;
                                                    case '100-149 نقطة': 
                                                        $color_class = 'bg-gradient bg-primary'; 
                                                        $badge_class = 'bg-primary'; 
                                                        $performance_label = 'مقبول'; 
                                                        $icon = 'fas fa-check';
                                                        break;
                                                    case '75-99 نقطة': 
                                                        $color_class = 'bg-gradient bg-warning'; 
                                                        $badge_class = 'bg-warning'; 
                                                        $performance_label = 'متوسط'; 
                                                        $icon = 'fas fa-balance-scale';
                                                        break;
                                                    case '50-74 نقطة': 
                                                        $color_class = 'bg-gradient bg-secondary'; 
                                                        $badge_class = 'bg-secondary'; 
                                                        $performance_label = 'ضعيف'; 
                                                        $icon = 'fas fa-minus-circle';
                                                        break;
                                                    case '25-49 نقطة': 
                                                        $color_class = 'bg-gradient bg-dark'; 
                                                        $badge_class = 'bg-dark'; 
                                                        $performance_label = 'ضعيف جداً'; 
                                                        $icon = 'fas fa-times-circle';
                                                        break;
                                                    case '0-24 نقطة': 
                                                        $color_class = 'bg-light text-dark'; 
                                                        $badge_class = 'bg-light text-dark'; 
                                                        $performance_label = 'يحتاج دعم'; 
                                                        $icon = 'fas fa-exclamation-triangle';
                                                        break;
                                                    default: 
                                                        $color_class = 'bg-gradient bg-danger'; 
                                                        $badge_class = 'bg-danger'; 
                                                        $performance_label = 'يحتاج دعم عاجل'; 
                                                        $icon = 'fas fa-exclamation-triangle';
                                                        break;
                                                }
                                            ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <strong class="text-primary"><?php echo $range['point_range']; ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge <?php echo $badge_class; ?> fs-6"><?php echo $range['student_count']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-dark"><?php echo $range['percentage']; ?>%</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <small class="text-muted"><?php echo $range['min_points']; ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <small class="text-muted"><?php echo $range['max_points']; ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?php echo $range['avg_points']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge <?php echo $badge_class; ?>">
                                                            <i class="<?php echo $icon; ?> me-1"></i><?php echo $performance_label; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 15px;">
                                                            <div class="progress-bar <?php echo $color_class; ?>" 
                                                                 style="width: <?php echo $range['percentage']; ?>%"
                                                                 data-bs-toggle="tooltip" 
                                                                 title="<?php echo $range['percentage']; ?>% من إجمالي الطلاب">
                                                                <small><?php echo $range['percentage']; ?>%</small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- ملخص بصري للتوزيع -->
                                <div class="mt-4">
                                    <h6 class="text-center mb-3">
                                        <i class="fas fa-chart-bar me-2"></i>ملخص التوزيع التفصيلي
                                    </h6>
                                    <div class="row text-center">
                                        <div class="col-lg-2 col-md-3 col-6 mb-3">
                                            <div class="card border-success">
                                                <div class="card-body p-2">
                                                    <div class="badge bg-success p-2 w-100">
                                                        <i class="fas fa-crown d-block mb-1"></i>
                                                        <div><strong>نجوم المدرسة</strong></div>
                                                        <div class="fs-6">(300+ نقطة)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-2 col-md-3 col-6 mb-3">
                                            <div class="card border-info">
                                                <div class="card-body p-2">
                                                    <div class="badge bg-info p-2 w-100">
                                                        <i class="fas fa-star d-block mb-1"></i>
                                                        <div><strong>متفوقون</strong></div>
                                                        <div class="fs-6">(200-299 نقطة)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-2 col-md-3 col-6 mb-3">
                                            <div class="card border-primary">
                                                <div class="card-body p-2">
                                                    <div class="badge bg-primary p-2 w-100">
                                                        <i class="fas fa-medal d-block mb-1"></i>
                                                        <div><strong>ممتازون</strong></div>
                                                        <div class="fs-6">(100-199 نقطة)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-2 col-md-3 col-6 mb-3">
                                            <div class="card border-warning">
                                                <div class="card-body p-2">
                                                    <div class="badge bg-warning p-2 w-100">
                                                        <i class="fas fa-thumbs-up d-block mb-1"></i>
                                                        <div><strong>جيدون</strong></div>
                                                        <div class="fs-6">(50-99 نقطة)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-2 col-md-3 col-6 mb-3">
                                            <div class="card border-secondary">
                                                <div class="card-body p-2">
                                                    <div class="badge bg-secondary p-2 w-100">
                                                        <i class="fas fa-balance-scale d-block mb-1"></i>
                                                        <div><strong>متوسطون</strong></div>
                                                        <div class="fs-6">(25-49 نقطة)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-2 col-md-3 col-6 mb-3">
                                            <div class="card border-danger">
                                                <div class="card-body p-2">
                                                    <div class="badge bg-danger p-2 w-100">
                                                        <i class="fas fa-exclamation-triangle d-block mb-1"></i>
                                                        <div><strong>يحتاجون دعم</strong></div>
                                                        <div class="fs-6">(أقل من 25)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- إحصائيات إضافية -->
                                <div class="mt-4">
                                    <div class="alert alert-info">
                                        <div class="row text-center">
                                            <div class="col-md-4">
                                                <i class="fas fa-users me-2"></i>
                                                <strong>إجمالي الطلاب:</strong> <?php echo $total_students; ?> طالب
                                            </div>
                                            <div class="col-md-4">
                                                <i class="fas fa-chart-line me-2"></i>
                                                <strong>أعلى متوسط نقاط:</strong> 
                                                <?php echo max(array_column($distribution_data, 'avg_points')); ?> نقطة
                                            </div>
                                            <div class="col-md-4">
                                                <i class="fas fa-trophy me-2"></i>
                                                <strong>الطلاب المتفوقون:</strong> 
                                                <?php 
                                                $excellent_count = 0;
                                                foreach($distribution_data as $range) {
                                                    if(strpos($range['point_range'], '200') !== false || 
                                                       strpos($range['point_range'], '300') !== false || 
                                                       strpos($range['point_range'], '400') !== false || 
                                                       strpos($range['point_range'], '500') !== false) {
                                                        $excellent_count += $range['student_count'];
                                                    }
                                                }
                                                echo $excellent_count;
                                                ?> طالب
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات توزيع نقاط متاحة
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- تحليلات الاتجاهات الزمنية -->
        <div class="accordion-item">
            <h2 class="accordion-header" id="timeAnalyticsHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#timeAnalyticsCollapse" aria-expanded="false" aria-controls="timeAnalyticsCollapse">
                    <i class="fas fa-clock me-2 text-primary"></i>
                    <strong>تحليلات الاتجاهات الزمنية</strong>
                </button>
            </h2>
            <div id="timeAnalyticsCollapse" class="accordion-collapse collapse" aria-labelledby="timeAnalyticsHeading" data-bs-parent="#analyticsAccordion">
                <div class="accordion-body">
                    <!-- توزيع النشاط حسب الساعة -->
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>توزيع النشاط حسب فترات الدوام المدرسي</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_hourly_trends->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover table-striped">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-center">الفترة الزمنية</th>
                                                <th class="text-center">عدد التقييمات</th>
                                                <th class="text-center">متوسط النقاط</th>
                                                <th class="text-center">الطلاب النشطين</th>
                                                <th class="text-center">المعلمين النشطين</th>
                                                <th class="text-center">إيجابية</th>
                                                <th class="text-center">سلبية</th>
                                                <th class="text-center">النسبة من الذروة</th>
                                                <th class="text-center">مستوى النشاط</th>
                                                <th class="text-center">التمثيل البصري</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $hourly_data = $stmt_hourly_trends->fetchAll(PDO::FETCH_ASSOC);
                                            if (!empty($hourly_data)) {
                                                $evaluation_counts = array_column($hourly_data, 'evaluation_count');
                                                $max_hourly = !empty($evaluation_counts) ? max($evaluation_counts) : 1;
                                                
                                                foreach ($hourly_data as $hour): 
                                                    $evaluation_count = isset($hour['evaluation_count']) ? $hour['evaluation_count'] : 0;
                                                    $hour_percentage = $max_hourly > 0 ? ($evaluation_count / $max_hourly) * 100 : 0;
                                                    $is_school_hours = isset($hour['sort_order']) && $hour['sort_order'] != 99;
                                                    $time_period = isset($hour['time_period']) ? $hour['time_period'] : 'غير محدد';
                                                    $unique_students = isset($hour['unique_students']) ? $hour['unique_students'] : 0;
                                                    $unique_teachers = isset($hour['unique_teachers']) ? $hour['unique_teachers'] : 0;
                                                    $avg_points = isset($hour['avg_points']) ? $hour['avg_points'] : 0;
                                                    $positive_count = isset($hour['positive_count']) ? $hour['positive_count'] : 0;
                                                    $negative_count = isset($hour['negative_count']) ? $hour['negative_count'] : 0;
                                            ?>
                                                <tr class="<?php echo $is_school_hours ? '' : 'table-secondary'; ?>">
                                                    <td class="text-center">
                                                        <strong><?php echo htmlspecialchars($time_period); ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $evaluation_count; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo $avg_points >= 0 ? 'success' : 'danger'; ?>"><?php echo $avg_points; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success"><?php echo $unique_students; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?php echo $unique_teachers; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success"><?php echo $positive_count; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-danger"><?php echo $negative_count; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-secondary"><?php echo round($hour_percentage); ?>%</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($hour_percentage >= 75): ?>
                                                            <span class="badge bg-success">ذروة النشاط</span>
                                                        <?php elseif ($hour_percentage >= 50): ?>
                                                            <span class="badge bg-warning">نشط</span>
                                                        <?php elseif ($hour_percentage >= 25): ?>
                                                            <span class="badge bg-secondary">متوسط</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-dark">هادئ</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 12px;">
                                                            <div class="progress-bar <?php echo $hour_percentage >= 75 ? 'bg-success' : ($hour_percentage >= 50 ? 'bg-warning' : ($hour_percentage >= 25 ? 'bg-secondary' : 'bg-light')); ?>" 
                                                                 style="width: <?php echo $hour_percentage; ?>%"
                                                                 data-bs-toggle="tooltip" 
                                                                 title="<?php echo $evaluation_count; ?> تقييم، <?php echo $unique_students; ?> طالب، <?php echo $unique_teachers; ?> معلم">
                                                                <small class="text-white"><?php echo round($hour_percentage); ?>%</small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php 
                                                endforeach;
                                            } else {
                                                echo '<tr><td colspan="10" class="text-center text-muted"><i class="fas fa-info-circle"></i> لا توجد تقييمات في آخر 30 يوم</td></tr>';
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- ملخص إحصائي للدوام -->
                                <div class="mt-4">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="alert alert-success">
                                                <h6><i class="fas fa-school me-2"></i>إحصائيات وقت الدوام (8:00 صباحاً - 3:00 مساءً)</h6>
                                                <?php 
                                                if (!empty($hourly_data)) {
                                                    $school_hours_data = array_filter($hourly_data, function($h) { return isset($h['sort_order']) && $h['sort_order'] != 99; });
                                                    $total_school_evaluations = 0;
                                                    $total_school_points = 0;
                                                    $school_periods_count = 0;
                                                    
                                                    foreach($school_hours_data as $period) {
                                                        if(isset($period['evaluation_count'])) {
                                                            $total_school_evaluations += $period['evaluation_count'];
                                                        }
                                                        if(isset($period['avg_points'])) {
                                                            $total_school_points += $period['avg_points'];
                                                            $school_periods_count++;
                                                        }
                                                    }
                                                    
                                                    $avg_school_points = $school_periods_count > 0 ? round($total_school_points / $school_periods_count, 1) : 0;
                                                ?>
                                                    <p class="mb-1"><strong>إجمالي التقييمات:</strong> <?php echo $total_school_evaluations; ?></p>
                                                    <p class="mb-1"><strong>متوسط النقاط:</strong> <?php echo $avg_school_points; ?></p>
                                                    <p class="mb-0"><strong>عدد الفترات النشطة:</strong> <?php echo count($school_hours_data); ?> من 7 فترات</p>
                                                <?php } else { ?>
                                                    <p class="mb-0">لا توجد بيانات متاحة</p>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="alert alert-warning">
                                                <h6><i class="fas fa-home me-2"></i>إحصائيات خارج الدوام</h6>
                                                <?php 
                                                if (!empty($hourly_data)) {
                                                    $out_hours_data = array_filter($hourly_data, function($h) { return isset($h['sort_order']) && $h['sort_order'] == 99; });
                                                    $total_out_evaluations = 0;
                                                    $total_out_points = 0;
                                                    
                                                    foreach($out_hours_data as $period) {
                                                        if(isset($period['evaluation_count'])) {
                                                            $total_out_evaluations += $period['evaluation_count'];
                                                        }
                                                        if(isset($period['avg_points'])) {
                                                            $total_out_points += $period['avg_points'];
                                                        }
                                                    }
                                                ?>
                                                    <p class="mb-1"><strong>إجمالي التقييمات:</strong> <?php echo $total_out_evaluations; ?></p>
                                                    <p class="mb-1"><strong>متوسط النقاط:</strong> <?php echo count($out_hours_data) > 0 ? round($total_out_points, 1) : 0; ?></p>
                                                    <p class="mb-0"><strong>نسبة خارج الدوام:</strong> <?php echo $total_school_evaluations + $total_out_evaluations > 0 ? round(($total_out_evaluations / ($total_school_evaluations + $total_out_evaluations)) * 100, 1) : 0; ?>%</p>
                                                <?php } else { ?>
                                                    <p class="mb-0">لا توجد بيانات متاحة</p>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات توزيع أوقات النشاط
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- الاتجاهات اليومية -->
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-calendar-week me-2"></i>الاتجاهات اليومية - آخر 10 أيام</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_day_distribution->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover table-striped">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-center">التاريخ</th>
                                                <th class="text-center">اليوم</th>
                                                <th class="text-center">التقييمات</th>
                                                <th class="text-center">الطلاب المُقيمون</th>
                                                <th class="text-center">متوسط النقاط</th>
                                                <th class="text-center">النسبة من الذروة</th>
                                                <th class="text-center">مستوى النشاط</th>
                                                <th class="text-center">نوع اليوم</th>
                                                <th class="text-center">التمثيل البصري</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $day_data = $stmt_day_distribution->fetchAll(PDO::FETCH_ASSOC);
                                            $max_evaluations = max(array_column($day_data, 'evaluation_count'));
                                            $arabic_days = [
                                                'Sunday' => 'الأحد',
                                                'Monday' => 'الاثنين', 
                                                'Tuesday' => 'الثلاثاء',
                                                'Wednesday' => 'الأربعاء',
                                                'Thursday' => 'الخميس',
                                                'Friday' => 'الجمعة',
                                                'Saturday' => 'السبت'
                                            ];
                                            foreach ($day_data as $day): 
                                                $activity_percentage = $max_evaluations > 0 ? ($day['evaluation_count'] / $max_evaluations) * 100 : 0;
                                                
                                                // Format date
                                                $formatted_date = $day['day_of_month'] . '/' . $day['month'] . '/' . $day['year'];
                                                $arabic_day = $arabic_days[$day['day_name']] ?? $day['day_name'];
                                            ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <strong><?php echo $formatted_date; ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?php echo $arabic_day; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $day['evaluation_count']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success"><?php echo $day['unique_students']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo $day['avg_points'] >= 0 ? 'success' : 'danger'; ?>"><?php echo $day['avg_points']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?php echo round($activity_percentage); ?>%</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($activity_percentage >= 75): ?>
                                                            <span class="badge bg-success">نشط جداً</span>
                                                        <?php elseif ($activity_percentage >= 50): ?>
                                                            <span class="badge bg-warning">نشط</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">هادئ</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php 
                                                        $day_eng = $day['day_name'];
                                                        if (in_array($day_eng, ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'])): ?>
                                                            <span class="badge bg-success">يوم دراسي</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning">عطلة</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 10px;">
                                                            <div class="progress-bar <?php echo $activity_percentage >= 75 ? 'bg-success' : ($activity_percentage >= 50 ? 'bg-warning' : 'bg-secondary'); ?>" 
                                                                 style="width: <?php echo $activity_percentage; ?>%"
                                                                 data-bs-toggle="tooltip" 
                                                                 title="<?php echo $day['evaluation_count']; ?> تقييم، <?php echo $day['unique_students']; ?> طالب">
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات توزيع أيام
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- الاتجاهات الأسبوعية -->
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>الاتجاهات الأسبوعية - آخر 8 أسابيع</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stmt_weekly_trends->rowCount() > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover table-striped">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-center">الأسبوع</th>
                                                <th class="text-center">إجمالي التقييمات</th>
                                                <th class="text-center">الطلاب النشطين</th>
                                                <th class="text-center">المعلمين النشطين</th>
                                                <th class="text-center">متوسط النقاط</th>
                                                <th class="text-center">النسبة الإيجابية</th>
                                                <th class="text-center">مستوى النشاط</th>
                                                <th class="text-center">الاتجاه</th>
                                                <th class="text-center">التمثيل البصري</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $weekly_data = $stmt_weekly_trends->fetchAll(PDO::FETCH_ASSOC);
                                            $max_weekly = max(array_column($weekly_data, 'total_evaluations'));
                                            $previous_total = null;
                                            foreach ($weekly_data as $week): 
                                                $positive_rate = $week['total_evaluations'] > 0 ? round(($week['positive_count'] / $week['total_evaluations']) * 100, 1) : 0;
                                                $week_percentage = ($week['total_evaluations'] / $max_weekly) * 100;
                                                $avg_points = $week['total_evaluations'] > 0 ? round((($week['positive_count'] * 5) - ($week['negative_count'] * 5)) / $week['total_evaluations'], 1) : 0;
                                                
                                                $trend = '';
                                                $trend_class = '';
                                                if ($previous_total !== null) {
                                                    if ($week['total_evaluations'] > $previous_total) {
                                                        $trend = '<i class="fas fa-arrow-up"></i> +' . ($week['total_evaluations'] - $previous_total);
                                                        $trend_class = 'text-success';
                                                    } elseif ($week['total_evaluations'] < $previous_total) {
                                                        $trend = '<i class="fas fa-arrow-down"></i> -' . ($previous_total - $week['total_evaluations']);
                                                        $trend_class = 'text-danger';
                                                    } else {
                                                        $trend = '<i class="fas fa-minus"></i> ثابت';
                                                        $trend_class = 'text-warning';
                                                    }
                                                } else {
                                                    $trend = '<i class="fas fa-chart-line"></i> بداية';
                                                    $trend_class = 'text-info';
                                                }
                                                $previous_total = $week['total_evaluations'];
                                            ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <strong>أسبوع <?php echo $week['week_of_year']; ?>/<?php echo $week['year']; ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $week['total_evaluations']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success"><?php echo $week['active_students']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-secondary"><?php echo $week['active_teachers']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo $avg_points >= 0 ? 'success' : 'danger'; ?>"><?php echo $avg_points; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo $positive_rate >= 70 ? 'success' : ($positive_rate >= 50 ? 'warning' : 'danger'); ?>"><?php echo $positive_rate; ?>%</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($week_percentage >= 75): ?>
                                                            <span class="badge bg-success">مرتفع</span>
                                                        <?php elseif ($week_percentage >= 50): ?>
                                                            <span class="badge bg-warning">متوسط</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">منخفض</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="<?php echo $trend_class; ?>"><?php echo $trend; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 10px;">
                                                            <div class="progress-bar <?php echo $week_percentage >= 75 ? 'bg-success' : ($week_percentage >= 50 ? 'bg-warning' : 'bg-secondary'); ?>" 
                                                                 style="width: <?php echo $week_percentage; ?>%"
                                                                 data-bs-toggle="tooltip" 
                                                                 title="<?php echo $week['total_evaluations']; ?> تقييم">
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات اتجاهات أسبوعية
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- الاتجاهات الشهرية -->
                    <div class="card shadow border-0">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>الاتجاهات الشهرية</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($monthly_data)): ?>
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm table-hover table-striped">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-center">الشهر</th>
                                                <th class="text-center">عدد التقييمات</th>
                                                <th class="text-center">النسبة من الذروة</th>
                                                <th class="text-center">مستوى النشاط</th>
                                                <th class="text-center">الموسم</th>
                                                <th class="text-center">التمثيل البصري</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $max_monthly = max(array_column($monthly_data, 'count'));
                                            $arabic_months = [
                                                1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                                                5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                                                9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
                                            ];
                                            foreach ($monthly_data as $month): 
                                                $month_percentage = ($month['count'] / $max_monthly) * 100;
                                                $season = '';
                                                if (in_array($month['month_num'], [9, 10, 11, 12, 1, 2])) {
                                                    $season = 'فصل دراسي';
                                                    $season_class = 'bg-success';
                                                } elseif (in_array($month['month_num'], [6, 7, 8])) {
                                                    $season = 'إجازة صيفية';
                                                    $season_class = 'bg-warning';
                                                } else {
                                                    $season = 'فصل دراسي';
                                                    $season_class = 'bg-info';
                                                }
                                            ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <strong><?php echo $arabic_months[$month['month_num']] ?? 'شهر ' . $month['month_num']; ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $month['count']; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?php echo round($month_percentage); ?>%</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($month_percentage >= 75): ?>
                                                            <span class="badge bg-success">مرتفع جداً</span>
                                                        <?php elseif ($month_percentage >= 50): ?>
                                                            <span class="badge bg-warning">مرتفع</span>
                                                        <?php elseif ($month_percentage >= 25): ?>
                                                            <span class="badge bg-secondary">متوسط</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-dark">منخفض</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge <?php echo $season_class; ?>"><?php echo $season; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 10px;">
                                                            <div class="progress-bar <?php echo $month_percentage >= 75 ? 'bg-success' : ($month_percentage >= 50 ? 'bg-warning' : ($month_percentage >= 25 ? 'bg-secondary' : 'bg-light')); ?>" 
                                                                 style="width: <?php echo $month_percentage; ?>%"
                                                                 data-bs-toggle="tooltip" 
                                                                 title="<?php echo $month['count']; ?> تقييم">
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3">
                                    <div class="alert alert-info">
                                        <div class="row text-center">
                                            <div class="col-md-4">
                                                <i class="fas fa-calendar-check me-2"></i>
                                                <strong>إجمالي التقييمات هذا العام:</strong> <?php echo array_sum(array_column($monthly_data, 'count')); ?> تقييم
                                            </div>
                                            <div class="col-md-4">
                                                <i class="fas fa-chart-line me-2"></i>
                                                <strong>متوسط التقييمات الشهرية:</strong> <?php echo round(array_sum(array_column($monthly_data, 'count')) / count($monthly_data)); ?> تقييم
                                            </div>
                                            <div class="col-md-4">
                                                <i class="fas fa-trophy me-2"></i>
                                                <strong>أعلى شهر:</strong> 
                                                <?php 
                                                $max_month_data = array_filter($monthly_data, function($m) use ($max_monthly) { return $m['count'] == $max_monthly; });
                                                $max_month = reset($max_month_data);
                                                echo $arabic_months[$max_month['month_num']] . ' (' . $max_month['count'] . ' تقييم)';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    لا توجد بيانات شهرية متاحة
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

<?php
// Include footer
include_once '../includes/admin_footer.php';
?>
