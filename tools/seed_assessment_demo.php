<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/AssessmentEngine.php';

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$environment = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '')));
$connectedDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($environment !== 'testing'
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $connectedDatabase)
    || $connectedDatabase === 'educore'
) {
    fwrite(STDERR, "Refusing assessment demo seed outside an isolated testing database.\n");
    exit(2);
}

$withMarks = in_array('--with-marks', $argv ?? [], true);
$publishReport = in_array('--publish-report', $argv ?? [], true);

$tableExists = static function (string $table) use ($db): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
};

$requiredTables = [
    'academic_years',
    'academic_terms',
    'academic_months',
    'academic_weeks',
    'subjects',
    'grades',
    'classes',
    'users',
    'subject_grade_assignments',
    'teacher_subject_assignments',
    'assessment_schemes',
    'assessment_components',
    'assessment_windows',
    'report_windows',
    'report_window_items',
];
foreach ($requiredTables as $table) {
    if (!$tableExists($table)) {
        fwrite(STDERR, "Missing required table: {$table}\n");
        exit(1);
    }
}
if ($withMarks && !$tableExists('student_marks')) {
    fwrite(STDERR, "Missing required table for --with-marks: student_marks\n");
    exit(1);
}

$fetchOne = static function (string $sql, array $params = []) use ($db): ?array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$currentYear = $fetchOne("SELECT id, name, start_date, end_date FROM academic_years WHERE is_active = 1 AND status = 'active' ORDER BY id DESC LIMIT 1");
if (!$currentYear) {
    fwrite(STDERR, "No active academic year found.\n");
    exit(1);
}

$subject = $fetchOne("SELECT id, name FROM subjects WHERE COALESCE(is_active, 1) = 1 ORDER BY id LIMIT 1");
$yearIdForSelection = (int) $currentYear['id'];
$class = $fetchOne("SELECT c.id, c.name, c.grade_id, COUNT(se.student_id) AS enrolled_count
    FROM classes c
    JOIN grades g ON g.id = c.grade_id
    LEFT JOIN student_enrollments se
      ON se.class_id = c.id
     AND se.academic_year_id = ?
     AND se.enrollment_status = 'enrolled'
    WHERE c.status = 'active'
      AND g.status = 'active'
    GROUP BY c.id, c.name, c.grade_id
    ORDER BY COUNT(se.student_id) DESC, c.display_order, c.name
    LIMIT 1", [$yearIdForSelection]);
$grade = $class
    ? $fetchOne("SELECT id, grade_name, stage_id FROM grades WHERE id = ? LIMIT 1", [(int) $class['grade_id']])
    : null;
if (!$grade) {
    $grade = $fetchOne("SELECT g.id, g.grade_name, g.stage_id
        FROM grades g
        WHERE g.status = 'active'
          AND EXISTS (
              SELECT 1
              FROM classes c
              WHERE c.grade_id = g.id
                AND c.status = 'active'
          )
        ORDER BY g.grade_order, g.id
        LIMIT 1");
}
if (!$grade) {
    $grade = $fetchOne("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY grade_order, id LIMIT 1");
}
if (!$class && $grade) {
    $class = $fetchOne("SELECT id, name, grade_id FROM classes WHERE status = 'active' AND grade_id = ? ORDER BY display_order, name LIMIT 1", [(int) $grade['id']]);
}
$teacher = $fetchOne("SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY id LIMIT 1");
$admin = $fetchOne("SELECT id, name FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");

if (!$subject || !$grade) {
    fwrite(STDERR, "Need at least one active subject and one active grade before seeding assessment demo data.\n");
    exit(1);
}
if (!$teacher && !$admin) {
    fwrite(STDERR, "Need an active teacher or admin so audit records have an actor.\n");
    exit(1);
}

$auditActor = $teacher ?: $admin;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = (int) $auditActor['id'];
$_SESSION['name'] = (string) $auditActor['name'];
$_SESSION['role'] = $teacher ? 'teacher' : 'admin';

$db->beginTransaction();
try {
    $yearId = (int) $currentYear['id'];
    $actorId = (int) $auditActor['id'];
    $yearStart = new DateTime((string) ($currentYear['start_date'] ?: date('Y') . '-09-01'));
    $yearEnd = new DateTime((string) ($currentYear['end_date'] ?: $yearStart->format('Y-m-d')));

    $dateAfterMonths = static function (DateTime $base, int $months, ?int $day = null) use ($yearEnd): string {
        $date = clone $base;
        $date->modify('first day of this month');
        if ($months !== 0) {
            $date->modify(($months > 0 ? '+' : '') . $months . ' months');
        }
        if ($day !== null) {
            $date->setDate((int) $date->format('Y'), (int) $date->format('m'), min($day, (int) $date->format('t')));
        }
        if ($date > $yearEnd) {
            return $yearEnd->format('Y-m-d');
        }
        return $date->format('Y-m-d');
    };

    $monthEnd = static function (string $start, ?string $limit = null) use ($yearEnd): string {
        $date = new DateTime($start);
        $date->modify('last day of this month');
        $max = $limit ? new DateTime($limit) : $yearEnd;
        if ($date > $max) {
            return $max->format('Y-m-d');
        }
        return $date->format('Y-m-d');
    };

    $weekEnd = static function (string $start, ?string $limit = null) use ($yearEnd): string {
        $date = new DateTime($start);
        $date->modify('+4 days');
        $max = $limit ? new DateTime($limit) : $yearEnd;
        if ($date > $max) {
            return $max->format('Y-m-d');
        }
        return $date->format('Y-m-d');
    };

    $ensureTerm = static function (int $order, string $name, string $start, string $end) use ($db, $yearId): array {
        $stmt = $db->prepare('SELECT id, name, start_date, end_date FROM academic_terms WHERE academic_year_id = ? AND term_order = ? LIMIT 1');
        $stmt->execute([$yearId, $order]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $existing ? (int) $existing['id'] : 0;
        if ($id > 0) {
            if (mb_strpos((string) ($existing['name'] ?? ''), 'تجريبي') !== false) {
                $update = $db->prepare('UPDATE academic_terms SET start_date = ?, end_date = ? WHERE id = ?');
                $update->execute([$start, $end, $id]);
                $existing['start_date'] = $start;
                $existing['end_date'] = $end;
            }
            return ['id' => $id, 'start_date' => (string) ($existing['start_date'] ?: $start), 'end_date' => (string) ($existing['end_date'] ?: $end)];
        }
        $insert = $db->prepare("INSERT INTO academic_terms (academic_year_id, name, term_order, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $insert->execute([$yearId, $name, $order, $start, $end]);
        return ['id' => (int) $db->lastInsertId(), 'start_date' => $start, 'end_date' => $end];
    };

    $ensureMonth = static function (int $termId, string $name, int $order, string $start, string $end, string $type = 'study') use ($db, $yearId, $actorId): int {
        $stmt = $db->prepare('SELECT id, name, notes FROM academic_months WHERE term_id = ? AND (name = ? OR month_order = ?) LIMIT 1');
        $stmt->execute([$termId, $name, $order]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $existing ? (int) $existing['id'] : 0;
        if ($id > 0) {
            if (mb_strpos((string) ($existing['name'] ?? ''), 'تجريبي') !== false || mb_strpos((string) ($existing['notes'] ?? ''), 'تجريبية') !== false) {
                $update = $db->prepare('UPDATE academic_months SET name = ?, start_date = ?, end_date = ?, month_type = ?, status = ? WHERE id = ?');
                $update->execute([$name, $start, $end, $type, 'active', $id]);
            }
            return $id;
        }
        $insert = $db->prepare("INSERT INTO academic_months
            (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
        $insert->execute([$yearId, $termId, $name, $order, $start, $end, $type, 'بيانات تجريبية لمحرك الرصد', $actorId]);
        return (int) $db->lastInsertId();
    };

    $ensureWeek = static function (int $termId, int $monthId, string $monthName, string $name, int $order, string $start, string $end, string $type = 'study', int $counts = 1) use ($db, $yearId): int {
        $stmt = $db->prepare('SELECT id, name, notes FROM academic_weeks WHERE term_id = ? AND week_order = ? LIMIT 1');
        $stmt->execute([$termId, $order]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $existing ? (int) $existing['id'] : 0;
        if ($id > 0) {
            if (mb_strpos((string) ($existing['name'] ?? ''), 'تجريبي') !== false || mb_strpos((string) ($existing['notes'] ?? ''), 'تجريبية') !== false) {
                $update = $db->prepare('UPDATE academic_weeks SET month_id = ?, month_label = ?, name = ?, start_date = ?, end_date = ?, week_type = ?, counts_for_average = ? WHERE id = ?');
                $update->execute([$monthId, $monthName, $name, $start, $end, $type, $counts, $id]);
            }
            return $id;
        }
        $insert = $db->prepare("INSERT INTO academic_weeks
            (academic_year_id, term_id, month_id, month_label, name, week_order, start_date, end_date, week_type, counts_for_average, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$yearId, $termId, $monthId, $monthName, $name, $order, $start, $end, $type, $counts, 'بيانات تجريبية لمحرك الرصد']);
        return (int) $db->lastInsertId();
    };

    $defaultTerm1Start = $yearStart->format('Y-m-d');
    $defaultTerm1End = $dateAfterMonths($yearStart, 4, 7);
    $defaultTerm2Start = $dateAfterMonths($yearStart, 5, 7);
    $defaultTerm2End = $dateAfterMonths($yearStart, 8, 28);
    $term1 = $ensureTerm(1, 'الترم الأول - تجريبي', $defaultTerm1Start, $defaultTerm1End);
    $term2 = $ensureTerm(2, 'الترم الثاني - تجريبي', $defaultTerm2Start, $defaultTerm2End);
    $term1Id = (int) $term1['id'];
    $term2Id = (int) $term2['id'];
    $term1End = (string) $term1['end_date'];

    $octoberStart = $dateAfterMonths(new DateTime((string) $term1['start_date']), 1, 1);
    $novemberStart = $dateAfterMonths(new DateTime((string) $term1['start_date']), 2, 1);
    $decemberStart = $dateAfterMonths(new DateTime((string) $term1['start_date']), 3, 1);
    $januaryStart = $dateAfterMonths(new DateTime((string) $term1['start_date']), 4, 1);

    $octoberId = $ensureMonth($term1Id, 'أكتوبر - تجريبي', 1, $octoberStart, $monthEnd($octoberStart, $term1End));
    $novemberId = $ensureMonth($term1Id, 'نوفمبر - تجريبي', 2, $novemberStart, $monthEnd($novemberStart, $term1End));
    $decemberId = $ensureMonth($term1Id, 'ديسمبر - تجريبي', 3, $decemberStart, $monthEnd($decemberStart, $term1End));
    $januaryId = $ensureMonth($term1Id, 'يناير امتحانات - تجريبي', 4, $januaryStart, $monthEnd($januaryStart, $term1End), 'exam');

    $weekIds = [];
    $weekIds[] = $ensureWeek($term1Id, $octoberId, 'أكتوبر - تجريبي', 'الأسبوع الأول - أكتوبر', 1, $dateAfterMonths(new DateTime($octoberStart), 0, 4), $weekEnd($dateAfterMonths(new DateTime($octoberStart), 0, 4), $term1End));
    $weekIds[] = $ensureWeek($term1Id, $octoberId, 'أكتوبر - تجريبي', 'الأسبوع الثاني - أكتوبر', 2, $dateAfterMonths(new DateTime($octoberStart), 0, 11), $weekEnd($dateAfterMonths(new DateTime($octoberStart), 0, 11), $term1End));
    $weekIds[] = $ensureWeek($term1Id, $octoberId, 'أكتوبر - تجريبي', 'الأسبوع الثالث - أكتوبر', 3, $dateAfterMonths(new DateTime($octoberStart), 0, 18), $weekEnd($dateAfterMonths(new DateTime($octoberStart), 0, 18), $term1End));
    $weekIds[] = $ensureWeek($term1Id, $novemberId, 'نوفمبر - تجريبي', 'الأسبوع الأول - نوفمبر', 4, $dateAfterMonths(new DateTime($novemberStart), 0, 1), $weekEnd($dateAfterMonths(new DateTime($novemberStart), 0, 1), $term1End));
    $weekIds[] = $ensureWeek($term1Id, $novemberId, 'نوفمبر - تجريبي', 'الأسبوع الثاني - نوفمبر', 5, $dateAfterMonths(new DateTime($novemberStart), 0, 8), $weekEnd($dateAfterMonths(new DateTime($novemberStart), 0, 8), $term1End));
    $weekIds[] = $ensureWeek($term1Id, $decemberId, 'ديسمبر - تجريبي', 'أسبوع مراجعة ديسمبر', 6, $dateAfterMonths(new DateTime($decemberStart), 0, 13), $weekEnd($dateAfterMonths(new DateTime($decemberStart), 0, 13), $term1End), 'revision', 0);
    $weekIds[] = $ensureWeek($term1Id, $januaryId, 'يناير امتحانات - تجريبي', 'أسبوع امتحان الفصل الدراسي', 7, $dateAfterMonths(new DateTime($januaryStart), 0, 1), $weekEnd($dateAfterMonths(new DateTime($januaryStart), 0, 1), $term1End), 'exam', 0);

    $subjectId = (int) $subject['id'];
    $gradeId = (int) $grade['id'];
    $stageId = !empty($grade['stage_id']) ? (int) $grade['stage_id'] : null;
    $classId = $class ? (int) $class['id'] : null;

    $assignmentStmt = $db->prepare('SELECT id FROM subject_grade_assignments WHERE academic_year_id = ? AND term_id IS NULL AND subject_id = ? AND grade_id = ? AND class_id IS NULL LIMIT 1');
    $assignmentStmt->execute([$yearId, $subjectId, $gradeId]);
    $assignmentId = (int) $assignmentStmt->fetchColumn();
    if ($assignmentId <= 0) {
        $insertAssignment = $db->prepare("INSERT INTO subject_grade_assignments
            (academic_year_id, term_id, subject_id, stage_id, grade_id, class_id, is_active, notes, created_by)
            VALUES (?, NULL, ?, ?, ?, NULL, 1, ?, ?)");
        $insertAssignment->execute([$yearId, $subjectId, $stageId, $gradeId, 'بيانات تجريبية لمحرك الرصد', $actorId]);
        $assignmentId = (int) $db->lastInsertId();
    }

    if ($teacher) {
        $teacherAssignmentStmt = $db->prepare('SELECT id FROM teacher_subject_assignments WHERE academic_year_id = ? AND teacher_id = ? AND subject_id = ? AND grade_id = ? AND class_id <=> ? LIMIT 1');
        $teacherAssignmentStmt->execute([$yearId, (int) $teacher['id'], $subjectId, $gradeId, $classId]);
        if (!$teacherAssignmentStmt->fetchColumn()) {
            $insertTeacherAssignment = $db->prepare("INSERT INTO teacher_subject_assignments
                (academic_year_id, term_id, teacher_id, subject_id, grade_id, class_id, starts_at, ends_at, can_record, can_review, is_active)
                VALUES (?, NULL, ?, ?, ?, ?, NULL, NULL, 1, 0, 1)");
            $insertTeacherAssignment->execute([$yearId, (int) $teacher['id'], $subjectId, $gradeId, $classId]);
        }
    }

    $schemeStmt = $db->prepare('SELECT id FROM assessment_schemes WHERE subject_assignment_id = ? AND term_id = ? AND name = ? LIMIT 1');
    $schemeStmt->execute([$assignmentId, $term1Id, 'خطة تجريبية 100 درجة']);
    $schemeId = (int) $schemeStmt->fetchColumn();
    if ($schemeId <= 0) {
        $insertScheme = $db->prepare("INSERT INTO assessment_schemes
            (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name, total_grade, pass_grade, counts_in_total,
             enable_excused_absence, normal_absence_policy, excused_absence_policy, rounding_enabled, rounding_mode, rounding_scope, annual_result_enabled,
             first_term_weight, second_term_weight, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 100, 50, 1, 1, 'zero', 'exclude', 1, 'two_decimals', 'total', 1, 50, 50, 'active', ?)");
        $insertScheme->execute([$yearId, $term1Id, $assignmentId, $subjectId, $stageId, $gradeId, 'خطة تجريبية 100 درجة', $actorId]);
        $schemeId = (int) $db->lastInsertId();
        (new AssessmentEngine($db))->applyComponentTemplate($schemeId, 'primary_100', false);
    }

    $weeklyComponents = $db->prepare("SELECT id, max_grade FROM assessment_components WHERE scheme_id = ? AND is_weekly = 1");
    $weeklyComponents->execute([$schemeId]);
    $insertRule = $db->prepare("INSERT INTO assessment_component_week_rules (component_id, week_id, is_included, max_grade_override)
        SELECT ?, ?, 1, NULL
        WHERE NOT EXISTS (
            SELECT 1 FROM assessment_component_week_rules WHERE component_id = ? AND week_id = ?
        )");
    foreach ($weeklyComponents->fetchAll(PDO::FETCH_ASSOC) ?: [] as $component) {
        foreach ($weekIds as $weekId) {
            $insertRule->execute([(int) $component['id'], (int) $weekId, (int) $component['id'], (int) $weekId]);
        }
    }

    $firstComponent = $fetchOne('SELECT id, name, max_grade, accepts_absence, accepts_excused_absence FROM assessment_components WHERE scheme_id = ? ORDER BY sort_order, id LIMIT 1', [$schemeId]);
    $windowId = 0;
    if ($firstComponent) {
        $windowStmt = $db->prepare('SELECT id FROM assessment_windows WHERE scheme_id = ? AND component_id = ? AND class_id <=> ? AND status = ? LIMIT 1');
        $windowStmt->execute([$schemeId, (int) $firstComponent['id'], $classId, 'open']);
        $windowId = (int) $windowStmt->fetchColumn();
        if ($windowId <= 0) {
            $insertWindow = $db->prepare("INSERT INTO assessment_windows
                (scheme_id, component_id, week_id, class_id, grade_id, teacher_id, window_name, opens_at, closes_at, status, allow_edit_after_save, requires_review, opened_by)
                VALUES (?, ?, NULL, ?, ?, NULL, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'open', 1, 0, ?)");
            $insertWindow->execute([$schemeId, (int) $firstComponent['id'], $classId, $gradeId, 'نافذة تجريبية - ' . $firstComponent['name'], $actorId]);
            $windowId = (int) $db->lastInsertId();
        }
    }

    $seededMarks = 0;
    if ($withMarks && $firstComponent && $classId) {
        $studentsStmt = $db->prepare("SELECT u.id
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            WHERE se.class_id = ?
              AND se.academic_year_id = ?
              AND se.enrollment_status = 'enrolled'
              AND u.role = 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
            ORDER BY u.name
            LIMIT 5");
        $studentsStmt->execute([$classId, $yearId]);
        $studentIds = array_map('intval', $studentsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $maxGrade = (float) $firstComponent['max_grade'];
        $demoRows = [
            ['value' => $maxGrade, 'status' => AssessmentEngine::STATUS_PRESENT, 'note' => 'درجة كاملة تجريبية'],
            ['value' => 0, 'status' => AssessmentEngine::STATUS_PRESENT, 'note' => 'صفر تجريبي'],
            ['value' => round(max(0.01, $maxGrade * 0.4), 2), 'status' => AssessmentEngine::STATUS_PRESENT, 'note' => 'أقل من النصف تجريبي'],
        ];
        if (!empty($firstComponent['accepts_absence'])) {
            $demoRows[] = ['value' => null, 'status' => AssessmentEngine::STATUS_ABSENT, 'note' => 'غياب تجريبي'];
        }
        if (!empty($firstComponent['accepts_excused_absence'])) {
            $demoRows[] = ['value' => null, 'status' => AssessmentEngine::STATUS_EXCUSED_ABSENT, 'note' => 'غياب بعذر تجريبي'];
        }

        $insertDemoMark = $db->prepare("INSERT INTO student_marks
            (student_id, scheme_id, component_id, week_id, week_slot, academic_year_id, term_id, subject_id,
             grade_id, class_id_at_entry, value, mark_status, note, recorded_by, review_status)
            SELECT ?, ?, ?, NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'not_required'
            WHERE NOT EXISTS (
                SELECT 1 FROM student_marks
                WHERE student_id = ?
                  AND component_id = ?
                  AND week_slot = 0
                  AND academic_year_id = ?
                  AND term_id = ?
            )");
        $auditDemoMark = $tableExists('student_mark_audit')
            ? $db->prepare("INSERT INTO student_mark_audit
                (mark_id, student_id, action, old_value, new_value, old_status, new_status, reason, changed_by)
                VALUES (?, ?, 'create', NULL, ?, NULL, ?, ?, ?)")
            : null;

        foreach ($studentIds as $index => $studentId) {
            if (!isset($demoRows[$index])) {
                break;
            }
            $row = $demoRows[$index];
            $insertDemoMark->execute([
                $studentId,
                $schemeId,
                (int) $firstComponent['id'],
                $yearId,
                $term1Id,
                $subjectId,
                $gradeId,
                $classId,
                $row['value'],
                $row['status'],
                'بيانات تجريبية اختيارية - ' . $row['note'],
                $teacher ? (int) $teacher['id'] : null,
                $studentId,
                (int) $firstComponent['id'],
                $yearId,
                $term1Id,
            ]);
            if ($insertDemoMark->rowCount() > 0) {
                $seededMarks++;
                if ($auditDemoMark) {
                    $auditDemoMark->execute([
                        (int) $db->lastInsertId(),
                        $studentId,
                        $row['value'] !== null ? (string) $row['value'] : null,
                        $row['status'],
                        'زراعة درجات تجريبية اختيارية',
                        $teacher ? (int) $teacher['id'] : null,
                    ]);
                }
            }
        }
    }

    $reportStmt = $db->prepare('SELECT id FROM report_windows WHERE academic_year_id = ? AND term_id = ? AND name = ? LIMIT 1');
    $reportStmt->execute([$yearId, $term1Id, 'تقرير أكتوبر التجريبي']);
    $reportId = (int) $reportStmt->fetchColumn();
    if ($reportId <= 0) {
        $insertReport = $db->prepare("INSERT INTO report_windows
            (academic_year_id, term_id, name, report_type, date_from, date_to, include_details, include_absence, include_teacher_notes, is_published, freeze_on_publish, created_by)
            VALUES (?, ?, ?, 'monthly', ?, ?, 1, 1, 0, 0, 0, ?)");
        $insertReport->execute([$yearId, $term1Id, 'تقرير أكتوبر التجريبي', $octoberStart, $monthEnd($octoberStart, $term1End), $actorId]);
        $reportId = (int) $db->lastInsertId();
    } else {
        $updateReport = $db->prepare('UPDATE report_windows SET date_from = ?, date_to = ?, freeze_on_publish = 0 WHERE id = ?');
        $updateReport->execute([$octoberStart, $monthEnd($octoberStart, $term1End), $reportId]);
    }
    $reportItemStmt = $db->prepare('SELECT id FROM report_window_items WHERE report_window_id = ? AND sort_order = 10 LIMIT 1');
    $reportItemStmt->execute([$reportId]);
    $reportItemId = (int) $reportItemStmt->fetchColumn();
    if ($reportItemId > 0) {
        $updateItem = $db->prepare('UPDATE report_window_items
            SET scheme_id = ?, component_id = NULL, week_id = NULL, subject_id = ?, include_item = 1
            WHERE id = ?');
        $updateItem->execute([$schemeId, $subjectId, $reportItemId]);
    } else {
        $insertItem = $db->prepare("INSERT INTO report_window_items
            (report_window_id, scheme_id, component_id, week_id, subject_id, include_item, sort_order)
            VALUES (?, ?, NULL, NULL, ?, 1, 10)");
        $insertItem->execute([$reportId, $schemeId, $subjectId]);
        $reportItemId = (int) $db->lastInsertId();
    }

    $publishResult = ['published' => 0, 'skipped' => 0];
    if ($publishReport) {
        $publishResult = (new AssessmentEngine($db))->publishReportWindow(
            $reportId,
            $classId ?: null,
            $teacher ? (int) $teacher['id'] : 0
        );
    }

    $db->commit();
    echo "Assessment demo seed completed.\n";
    echo "Academic year: {$currentYear['name']}\n";
    echo "Subject: {$subject['name']}\n";
    echo "Grade: {$grade['grade_name']}\n";
    echo "Class: " . ($class['name'] ?? 'none') . "\n";
    echo "Teacher: " . ($teacher['name'] ?? 'none') . "\n";
    echo "Scheme ID: {$schemeId}\n";
    echo "Window ID: {$windowId}\n";
    echo "Report ID: {$reportId}\n";
    echo "Report item ID: {$reportItemId}\n";
    echo "Demo marks seeded: {$seededMarks}\n";
    echo "Report published: " . ($publishReport ? 'yes' : 'no') . "\n";
    echo "Published reports: " . (int) $publishResult['published'] . "\n";
    echo "Skipped frozen reports: " . (int) $publishResult['skipped'] . "\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
