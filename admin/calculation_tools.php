<?php
/**
 * Calculation Tools Page
 * Provides utilities for administrative calculations (e.g., Admission Age and Date Conversions).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/user.php';
require_once __DIR__ . '/../classes/utilities.php';
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/AcademicYear.php';
require_once __DIR__ . '/../classes/ScopedStaffPortalContext.php';

Utilities::validateSession('admin');

requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();

function calculation_tools_class_scope(?array $allowedClassIds, string $column): string
{
    if ($allowedClassIds === null) {
        return '1 = 1';
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds), static fn(int $id): bool => $id > 0)));
    return $ids === [] ? '1 = 0' : $column . ' IN (' . implode(',', $ids) . ')';
}

$classScopeSql = calculation_tools_class_scope($allowedClassIds, 'c.id');

// Calculate reference year based on current academic year
$academicYear = AcademicYear::getCurrent($db);
$academicYearName = $academicYear ? $academicYear['name'] : '';
if (preg_match_all('/(\d{4})/', $academicYearName, $matches)) {
    if (count($matches[1]) > 1) {
        $refYear = (int)$matches[1][1];
    } else {
        $refYear = (int)$matches[1][0] + 1;
    }
} else {
    $refYear = (int)date('Y');
}

// AJAX Handler for student selection filters
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];
    try {

    if ($action === 'compare_stats' && isset($_GET['type'], $_GET['id1'], $_GET['id2'])) {
        $type = $_GET['type'];
        $id1 = (int)$_GET['id1'];
        $id2 = (int)$_GET['id2'];
        if (!in_array($type, ['classes', 'grades', 'stages'], true)) {
            throw new RuntimeException('نوع المقارنة غير صالح.');
        }
        if ($type === 'classes') {
            $portalContext->assertClassAllowed($id1);
            $portalContext->assertClassAllowed($id2);
        }
        $refDate = $refYear . '-10-01';

        $getBirthDates = function($entityType, $entityId) use ($db, $currentAcademicYearId, $classScopeSql) {
            if ($entityType === 'classes') {
                $sql = "SELECT sp.birth_date
                        FROM student_profiles sp
                        JOIN users u ON sp.user_id = u.id
                        JOIN student_enrollments se ON se.student_id = u.id
                            AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                        JOIN classes c ON c.id = se.class_id
                        WHERE se.class_id = ? AND {$classScopeSql}
                          AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
                $stmt = $db->prepare($sql);
                $stmt->execute([$currentAcademicYearId, $entityId]);
            } elseif ($entityType === 'grades') {
                $sql = "SELECT sp.birth_date
                        FROM student_profiles sp
                        JOIN users u ON sp.user_id = u.id
                        JOIN student_enrollments se ON se.student_id = u.id
                            AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                        JOIN classes c ON se.class_id = c.id
                        WHERE c.grade_id = ? AND {$classScopeSql}
                          AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
                $stmt = $db->prepare($sql);
                $stmt->execute([$currentAcademicYearId, $entityId]);
            } elseif ($entityType === 'stages') {
                $sql = "SELECT sp.birth_date
                        FROM student_profiles sp
                        JOIN users u ON sp.user_id = u.id
                        JOIN student_enrollments se ON se.student_id = u.id
                            AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                        JOIN classes c ON se.class_id = c.id
                        JOIN grades g ON c.grade_id = g.id
                        WHERE g.stage_id = ? AND {$classScopeSql}
                          AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
                $stmt = $db->prepare($sql);
                $stmt->execute([$currentAcademicYearId, $entityId]);
            } else {
                return [];
            }
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };

        $dates1 = $getBirthDates($type, $id1);
        $dates2 = $getBirthDates($type, $id2);

        $calcStats = function($dates, $refDate) {
            $count = count($dates);
            if ($count === 0) {
                return ['count' => 0, 'avg_age_days' => 0, 'std_dev_days' => 0, 'years' => 0, 'months' => 0, 'days' => 0];
            }

            $agesInDays = [];
            $refDateTime = new DateTime($refDate);
            foreach ($dates as $d) {
                if (empty($d)) continue;
                try {
                    $birthDateTime = new DateTime($d);
                    $diff = $birthDateTime->diff($refDateTime);
                    if ($diff->invert) {
                        $agesInDays[] = 0;
                    } else {
                        $agesInDays[] = (int)$diff->days;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }

            $validCount = count($agesInDays);
            if ($validCount === 0) {
                return ['count' => 0, 'avg_age_days' => 0, 'std_dev_days' => 0, 'years' => 0, 'months' => 0, 'days' => 0];
            }

            $sumDays = array_sum($agesInDays);
            $avgDays = $sumDays / $validCount;

            $varianceSum = 0;
            foreach ($agesInDays as $age) {
                $varianceSum += pow($age - $avgDays, 2);
            }
            $stdDevDays = sqrt($varianceSum / $validCount);

            $avgYears = floor($avgDays / 365.25);
            $remainingDays = $avgDays - ($avgYears * 365.25);
            $avgMonths = floor($remainingDays / 30.44);
            $avgDaysOnly = round($remainingDays - ($avgMonths * 30.44));
            if ($avgDaysOnly >= 30) {
                $avgMonths++;
                $avgDaysOnly = 0;
            }
            if ($avgMonths >= 12) {
                $avgYears++;
                $avgMonths = 0;
            }

            return [
                'count' => $validCount,
                'avg_age_days' => $avgDays,
                'std_dev_days' => $stdDevDays,
                'years' => (int)$avgYears,
                'months' => (int)$avgMonths,
                'days' => (int)$avgDaysOnly
            ];
        };

        $stats1 = $calcStats($dates1, $refDate);
        $stats2 = $calcStats($dates2, $refDate);

        $getName = function($entityType, $entityId) use ($db, $currentAcademicYearId, $classScopeSql) {
            if ($entityType === 'classes') {
                $stmt = $db->prepare("SELECT c.name FROM classes c WHERE c.id = ? AND {$classScopeSql}");
                $stmt->execute([$entityId]);
                return $stmt->fetchColumn() ?: "الفصل #{$entityId}";
            } elseif ($entityType === 'grades') {
                $stmt = $db->prepare("SELECT g.grade_name FROM grades g
                    WHERE g.id = ? AND EXISTS (
                        SELECT 1 FROM classes c WHERE c.grade_id = g.id
                          AND c.academic_year_id = ? AND {$classScopeSql}
                    )");
                $stmt->execute([$entityId, $currentAcademicYearId]);
                return $stmt->fetchColumn() ?: "الصف #{$entityId}";
            } elseif ($entityType === 'stages') {
                $stmt = $db->prepare("SELECT s.stage_name FROM stages s
                    WHERE s.id = ? AND EXISTS (
                        SELECT 1 FROM grades g JOIN classes c ON c.grade_id = g.id
                        WHERE g.stage_id = s.id AND c.academic_year_id = ? AND {$classScopeSql}
                    )");
                $stmt->execute([$entityId, $currentAcademicYearId]);
                return $stmt->fetchColumn() ?: "المرحلة #{$entityId}";
            }
            return "";
        };

        $name1 = $getName($type, $id1);
        $name2 = $getName($type, $id2);

        $diffDays = abs($stats1['avg_age_days'] - $stats2['avg_age_days']);
        $diffYears = floor($diffDays / 365.25);
        $remainingDays = $diffDays - ($diffYears * 365.25);
        $diffMonths = floor($remainingDays / 30.44);
        $diffDaysOnly = round($remainingDays - ($diffMonths * 30.44));
        if ($diffDaysOnly >= 30) {
            $diffMonths++;
            $diffDaysOnly = 0;
        }
        if ($diffMonths >= 12) {
            $diffYears++;
            $diffMonths = 0;
        }

        $diffText = "";
        if ($diffYears > 0) $diffText .= "{$diffYears} سنة ";
        if ($diffMonths > 0) $diffText .= "و {$diffMonths} شهر ";
        if ($diffDaysOnly > 0 || empty($diffText)) $diffText .= "و {$diffDaysOnly} يوم";
        $diffText = trim($diffText, "و ");

        $getRating = function($stdDev, $count) {
            if ($count === 0) return ['label' => 'غير متوفر (لا يوجد طلاب)', 'class' => 'badge bg-secondary'];
            if ($stdDev < 120) return ['label' => 'تجانس ممتاز (فروق سنية طفيفة جداً)', 'class' => 'badge bg-success'];
            if ($stdDev < 240) return ['label' => 'تجانس جيد جداً (تطابق عمري طبيعي)', 'class' => 'badge bg-primary'];
            if ($stdDev < 365) return ['label' => 'تجانس مقبول (تفاوت عمري معتاد)', 'class' => 'badge bg-warning text-dark'];
            return ['label' => 'تفاوت كبير (تشتت عمري ملحوظ)', 'class' => 'badge bg-danger'];
        };

        $rating1 = $getRating($stats1['std_dev_days'], $stats1['count']);
        $rating2 = $getRating($stats2['std_dev_days'], $stats2['count']);

        if ($stats1['count'] == 0 || $stats2['count'] == 0) {
            $advisor = "لا توجد بيانات طلاب كافية في أحد الطرفين لإجراء المقارنة.";
        } else {
            $advisor = "الفرق في متوسط العمر بين <strong>{$name1}</strong> و <strong>{$name2}</strong> هو <strong>{$diffText}</strong>.<br>";
            if ($diffDays < 180) {
                $advisor .= "<i class='fas fa-check-circle text-success me-1'></i> كلا الطرفين متقاربان عمرياً بشكل ممتاز وتوزيع الفئات السنية متطابق ومتكافئ.";
            } elseif ($diffDays < 365) {
                $advisor .= "<i class='fas fa-info-circle text-primary me-1'></i> هناك فرق عمر طفيف بين الطرفين، ويظل هذا الفارق مقبولاً في بيئة العمل المدرسي المعتادة.";
            } else {
                $advisor .= "<i class='fas fa-exclamation-triangle text-warning me-1'></i> تنبيه: يوجد فارق عمري كبير نسبياً (أكثر من سنة). يُفضل مراجعة التوزيع لضمان التكافؤ وتجنب تفاوت كبير في متوسطات الأعمار.";
            }
        }

        echo json_encode([
            'success' => true,
            'name1' => $name1,
            'name2' => $name2,
            'stats1' => $stats1,
            'stats2' => $stats2,
            'rating1' => $rating1,
            'rating2' => $rating2,
            'diff_text' => $diffText,
            'advisor' => $advisor
        ]);
        exit;
    }

    if ($action === 'get_grades' && isset($_GET['stage_id'])) {
        $stageId = (int)$_GET['stage_id'];
        $stmt = $db->prepare("SELECT DISTINCT g.id, g.grade_name AS name
            FROM grades g JOIN classes c ON c.grade_id = g.id
            WHERE g.stage_id = ? AND g.status = 'active' AND c.academic_year_id = ? AND {$classScopeSql}
            ORDER BY g.grade_name ASC");
        $stmt->execute([$stageId, $currentAcademicYearId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'get_classes' && isset($_GET['grade_id'])) {
        $gradeId = (int)$_GET['grade_id'];
        $stmt = $db->prepare("SELECT c.id, c.name FROM classes c
            WHERE c.grade_id = ? AND c.status = 'active' AND c.academic_year_id = ? AND {$classScopeSql}
            ORDER BY c.name ASC");
        $stmt->execute([$gradeId, $currentAcademicYearId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'get_students' && isset($_GET['class_id'])) {
        $classId = (int)$_GET['class_id'];
        $portalContext->assertClassAllowed($classId);
        $stmt = $db->prepare("SELECT u.id, u.name FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            WHERE se.academic_year_id = ? AND se.class_id = ? AND se.enrollment_status = 'enrolled'
              AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY u.name ASC");
        $stmt->execute([$currentAcademicYearId, $classId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'get_national_id' && isset($_GET['student_id'])) {
        $studentId = (int)$_GET['student_id'];
        $portalContext->assertStudentAllowed($studentId);
        $stmt = $db->prepare("SELECT national_id FROM student_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $nid = $stmt->fetchColumn();
        echo json_encode(['national_id' => $nid ?: '']);
        exit;
    }
    } catch (RuntimeException $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Preload active stages, grades, and classes for student/statistical filters
$stmtClasses = $db->prepare("SELECT c.id, c.name, c.grade_id FROM classes c
    WHERE c.status = 'active' AND c.academic_year_id = ? AND {$classScopeSql} ORDER BY c.name ASC");
$stmtClasses->execute([$currentAcademicYearId]);
$all_classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
$gradeStmt = $db->prepare("SELECT DISTINCT g.id, g.grade_name AS name, g.stage_id
    FROM grades g JOIN classes c ON c.grade_id = g.id
    WHERE g.status = 'active' AND c.academic_year_id = ? AND {$classScopeSql}
    ORDER BY g.grade_name ASC");
$gradeStmt->execute([$currentAcademicYearId]);
$all_grades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
$stageStmt = $db->prepare("SELECT DISTINCT s.id, s.stage_name AS name
    FROM stages s JOIN grades g ON g.stage_id = s.id JOIN classes c ON c.grade_id = g.id
    WHERE s.status = 'active' AND c.academic_year_id = ? AND {$classScopeSql}
    ORDER BY s.stage_name ASC");
$stageStmt->execute([$currentAcademicYearId]);
$stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
$studentsStmt = $db->prepare("SELECT u.id, u.name, se.class_id, c.grade_id, g.stage_id
    FROM student_enrollments se
    JOIN users u ON u.id = se.student_id
    JOIN classes c ON se.class_id = c.id
    LEFT JOIN grades g ON c.grade_id = g.id
    WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled' AND {$classScopeSql}
      AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
    ORDER BY u.name ASC");
$studentsStmt->execute([$currentAcademicYearId]);
$all_students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Function to suggest educational stage based on age as of ref date
function getSuggestedStage($years, $months) {
    $totalMonths = ($years * 12) + $months;

    if ($totalMonths < 36) {
        return ['stage' => 'غير مؤهل للقبول', 'class' => 'text-danger', 'notes' => 'السن أقل من 3 سنوات (غير مؤهل للتقديم حالياً).'];
    } elseif ($totalMonths >= 36 && $totalMonths < 48) {
        return ['stage' => 'الحضانة (Nursery)', 'class' => 'text-secondary', 'notes' => 'العمر مناسب للقبول في مرحلة الحضانة (أقل من 4 سنوات).'];
    } elseif ($totalMonths >= 48 && $totalMonths < 60) {
        return ['stage' => 'المستوى الأول رياض الأطفال (KG1)', 'class' => 'text-warning', 'notes' => 'العمر مناسب للمستوى الأول لرياض الأطفال (بين 4 و 5 سنوات).'];
    } elseif ($totalMonths >= 60 && $totalMonths < 72) {
        return ['stage' => 'المستوى الثاني رياض الأطفال (KG2)', 'class' => 'text-info', 'notes' => 'العمر مناسب للمستوى الثاني لرياض الأطفال (بين 5 و 6 سنوات).'];
    } elseif ($totalMonths >= 72 && $totalMonths < 108) {
        return ['stage' => 'الصف الأول الابتدائي', 'class' => 'text-success', 'notes' => 'العمر مؤهل قانوناً للقبول بالصف الأول الابتدائي (أكبر من أو يساوي 6 سنوات).'];
    } elseif ($totalMonths >= 108 && $totalMonths < 180) {
        return ['stage' => 'مرحلة التعليم الأساسي (الابتدائي/الإعدادي)', 'class' => 'text-success', 'notes' => 'العمر مؤهل لصفوف مرحلة التعليم الأساسي (ابتدائي أو إعدادي).'];
    } elseif ($totalMonths >= 180 && $totalMonths <= 216) {
        return ['stage' => 'المرحلة الثانوية', 'class' => 'text-primary', 'notes' => 'العمر مؤهل للمرحلة الثانوية.'];
    } else {
        return ['stage' => 'خارج سن القبول المدرسي المعتاد', 'class' => 'text-danger', 'notes' => 'العمر يتجاوز سن القبول المدرسي المعتاد (أكبر من 18 سنة).'];
    }
}

// PRG Pattern: retrieve and clear session data
$birthDateAdmission = $_SESSION['calc_birth_date_admission'] ?? '';
$ageResultAdmission = $_SESSION['calc_age_result_admission'] ?? null;

$birthDateCustom = $_SESSION['calc_birth_date_custom'] ?? '';
$refDateCustom = $_SESSION['calc_ref_date_custom'] ?? date('Y-m-d');
$ageResultCustom = $_SESSION['calc_age_result_custom'] ?? null;

$activeTab = $_SESSION['calc_active_tab'] ?? 'admission';

unset(
    $_SESSION['calc_birth_date_admission'],
    $_SESSION['calc_age_result_admission'],
    $_SESSION['calc_birth_date_custom'],
    $_SESSION['calc_ref_date_custom'],
    $_SESSION['calc_age_result_custom'],
    $_SESSION['calc_active_tab']
);



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'admission' && !empty($_POST['birth_date'])) {
        $birth = $_POST['birth_date'];
        $ref = $refYear . '-10-01';

        $birthDateTime = new DateTime($birth);
        $refDateTime = new DateTime($ref);
        $diff = $birthDateTime->diff($refDateTime);

        $_SESSION['calc_birth_date_admission'] = $birth;
        $_SESSION['calc_active_tab'] = 'admission';

        if ($diff->invert) {
            $_SESSION['calc_age_result_admission'] = ['years' => 0, 'months' => 0, 'days' => 0, 'ref' => $ref, 'invalid' => true];
        } else {
            $_SESSION['calc_age_result_admission'] = ['years' => $diff->y, 'months' => $diff->m, 'days' => $diff->d, 'ref' => $ref, 'invalid' => false];
        }
    }
    elseif ($action === 'custom' && !empty($_POST['birth_date']) && !empty($_POST['ref_date'])) {
        $birth = $_POST['birth_date'];
        $ref = $_POST['ref_date'];

        $birthDateTime = new DateTime($birth);
        $refDateTime = new DateTime($ref);
        $diff = $birthDateTime->diff($refDateTime);

        $_SESSION['calc_birth_date_custom'] = $birth;
        $_SESSION['calc_ref_date_custom'] = $ref;
        $_SESSION['calc_active_tab'] = 'custom';

        if ($diff->invert) {
            $_SESSION['calc_age_result_custom'] = ['years' => 0, 'months' => 0, 'days' => 0, 'ref' => $ref, 'invalid' => true];
        } else {
            $_SESSION['calc_age_result_custom'] = ['years' => $diff->y, 'months' => $diff->m, 'days' => $diff->d, 'ref' => $ref, 'invalid' => false];
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$page_title = "أدوات الحساب";
$custom_page_title = true;
include_once __DIR__ . '/../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/calculation-tools.css">



<!-- Air Datepicker CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.css">

<?php
$currentYear = $refYear;
?>

<!-- عنوان الصفحة -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-calculator me-2"></i>أدوات الحساب والتواريخ</h1>
    <div class="btn-toolbar mb-2 mb-md-0"></div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4 border-bottom admin-tabs" id="calcTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'admission' ? 'active' : ''; ?>" id="admission-tab" data-bs-toggle="tab" data-bs-target="#pane-admission" type="button" role="tab" aria-controls="pane-admission" aria-selected="<?php echo $activeTab === 'admission' ? 'true' : 'false'; ?>">
            <i class="fas fa-graduation-cap me-2"></i>حاسبة السن (في 1 أكتوبر)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'custom' ? 'active' : ''; ?>" id="custom-tab" data-bs-toggle="tab" data-bs-target="#pane-custom" type="button" role="tab" aria-controls="pane-custom" aria-selected="<?php echo $activeTab === 'custom' ? 'true' : 'false'; ?>">
            <i class="fas fa-tools me-2"></i>أدوات حرة (تواريخ وتحويلات)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" id="nid-tab" data-bs-toggle="tab" data-bs-target="#pane-nid" type="button" role="tab" aria-controls="pane-nid" aria-selected="false">
            <i class="fas fa-id-card me-2"></i>تحليل الرقم القومي
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" id="homogeneity-tab" data-bs-toggle="tab" data-bs-target="#pane-homogeneity" type="button" role="tab" aria-controls="pane-homogeneity" aria-selected="false">
            <i class="fas fa-people-arrows me-2"></i>التجانس العمري للفصل
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" id="balancing-tab" data-bs-toggle="tab" data-bs-target="#pane-balancing" type="button" role="tab" aria-controls="pane-balancing" aria-selected="false">
            <i class="fas fa-balance-scale me-2"></i>توزيع وتكافؤ الفصول
        </button>
    </li>
</ul>

<div class="tab-content" id="calcTabsContent">
    <!-- ====== تبويب أدوات القبول ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'admission' ? 'show active' : ''; ?>" id="pane-admission" role="tabpanel" aria-labelledby="admission-tab">
        <div class="row">
            <div class="col-xl-7 col-lg-7 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-user-clock me-2"></i>حاسبة السن ومرحلة القبول (في 1 أكتوبر)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">
                            تقوم هذه الأداة بحساب <strong>عمر الطالب</strong> بدقة في <strong>1 أكتوبر <?php echo $currentYear; ?></strong> وتحديد <strong>أهلية القبول</strong> في الصفوف والمراحل الدراسية المختلفة.
                        </p>

                        <form method="POST" action="">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="admission">
                            <div class="mb-4">
                                <label for="birth_date_admission" class="form-label fw-bold"><i class="fas fa-baby me-1 text-primary"></i>تاريخ ميلاد الطالب</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                    <input type="text" name="birth_date" id="birth_date_admission" class="form-control border-start-0 flatpickr-date"
                                           placeholder="اختر التاريخ..." value="<?php echo htmlspecialchars($birthDateAdmission); ?>" required>
                                    <button type="button" class="btn btn-outline-secondary border-start-0" onclick="clearDateInput('birth_date_admission')" title="مسح">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="fas fa-magic me-1"></i> احسب سن القبول
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?php if ($ageResultAdmission): ?>
                            <hr>
                            <div class="result-area mt-4 p-4 rounded bg-light border animate__animated animate__fadeIn">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="text-secondary mb-0"><i class="fas fa-poll me-2"></i>السن في 1 أكتوبر <?php echo $currentYear; ?></h5>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyResultText('<?php echo "{$ageResultAdmission['years']} سنة و {$ageResultAdmission['months']} شهر و {$ageResultAdmission['days']} يوم"; ?>', 'btnCopyAdmission')" id="btnCopyAdmission">
                                        <i class="fas fa-copy me-1"></i> نسخ النتيجة
                                    </button>
                                </div>

                                <?php if (isset($ageResultAdmission['invalid']) && $ageResultAdmission['invalid']): ?>
                                    <div class="alert alert-danger mb-0">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        تاريخ الميلاد المدخل يقع بعد تاريخ المرجع (1 أكتوبر).
                                    </div>
                                <?php else: ?>
                                    <div class="row g-3 text-center">
                                        <div class="col-4">
                                            <div class="p-3 rounded-3" style="background-color: rgba(37, 99, 235, 0.08);">
                                                <div class="h2 fw-bold text-primary mb-0"><?php echo $ageResultAdmission['years']; ?></div>
                                                <div class="small fw-semibold text-muted">سنة</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded-3" style="background-color: rgba(16, 185, 129, 0.08);">
                                                <div class="h2 fw-bold text-success mb-0"><?php echo $ageResultAdmission['months']; ?></div>
                                                <div class="small fw-semibold text-muted">شهر</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded-3" style="background-color: rgba(14, 165, 233, 0.08);">
                                                <div class="h2 fw-bold text-info mb-0"><?php echo $ageResultAdmission['days']; ?></div>
                                                <div class="small fw-semibold text-muted">يوم</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Suggested Stage Box -->
                                    <?php
                                    $suggested = getSuggestedStage($ageResultAdmission['years'], $ageResultAdmission['months']);
                                    ?>
                                    <div class="alert mt-4 mb-0 border-0 d-flex align-items-center gap-3" style="background-color: rgba(37, 99, 235, 0.06);">
                                        <div class="h1 mb-0 text-primary"><i class="fas fa-graduation-cap text-primary"></i></div>
                                        <div>
                                            <div class="fw-bold text-dark">مرحلة القبول المقترحة: <span class="<?php echo $suggested['class']; ?> fw-bold"><?php echo htmlspecialchars($suggested['stage']); ?></span></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($suggested['notes']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-5 col-lg-5 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2"></i>ملاحظات قواعد سن القبول</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div>السن القانوني للالتحاق بالصف الأول الابتدائي هو <strong class="text-dark">6 سنوات</strong> في أول أكتوبر.</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div>سن قبول رياض الأطفال (KG1) يبدأ من <strong class="text-dark">4 سنوات</strong> وحتى دون الـ <strong class="text-dark">5 سنوات</strong>.</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div>يتم ترتيب المتقدمين للمدارس تنازلياً من الأكبر سناً للأصغر سناً حسب الأماكن المتاحة.</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== تبويب أدوات حرة ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'custom' ? 'show active' : ''; ?>" id="pane-custom" role="tabpanel" aria-labelledby="custom-tab">
        <div class="row">
            <div class="col-xl-7 col-lg-7">
                <!-- Custom Age Card -->
                <div class="card shadow admin-card-surface mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-user-clock me-2"></i>حساب السن في تاريخ مخصص</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">
                            احسب <strong>سن الطالب</strong> بدقة <strong>باليوم والشهر والسنة</strong> في أي <strong>تاريخ مرجع</strong> تحدده (لتحديد العمر عند <strong>النقل</strong>، <strong>التخرج</strong>، إلخ).
                        </p>

                        <form method="POST" action="" class="row g-3">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="custom">
                            <div class="col-md-6">
                                <label for="birth_date_custom" class="form-label fw-bold"><i class="fas fa-baby me-1 text-primary"></i>تاريخ ميلاد الطالب</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                    <input type="text" name="birth_date" id="birth_date_custom" class="form-control border-start-0 flatpickr-date"
                                           placeholder="اختر التاريخ..." value="<?php echo htmlspecialchars($birthDateCustom); ?>" required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearDateInput('birth_date_custom')" title="مسح">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="ref_date_custom" class="form-label fw-bold"><i class="fas fa-history me-1 text-success"></i>تاريخ المرجع للحساب</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-check text-muted"></i></span>
                                    <input type="text" name="ref_date" id="ref_date_custom" class="form-control border-start-0 flatpickr-date"
                                           placeholder="اختر التاريخ..." value="<?php echo htmlspecialchars($refDateCustom); ?>" required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearDateInput('ref_date_custom')" title="مسح">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12 mt-4 text-end">
                                <button type="submit" class="btn btn-primary shadow px-4 py-2">
                                    <i class="fas fa-calculator me-1"></i> احسب الآن
                                </button>
                            </div>
                        </form>

                        <?php if ($ageResultCustom): ?>
                            <hr>
                            <div class="result-area mt-4 p-4 rounded bg-light border animate__animated animate__fadeIn">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="text-secondary mb-0"><i class="fas fa-poll me-2"></i>السن في <?php echo htmlspecialchars($ageResultCustom['ref']); ?></h5>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyResultText('<?php echo "{$ageResultCustom['years']} سنة و {$ageResultCustom['months']} شهر و {$ageResultCustom['days']} يوم"; ?>', 'btnCopyCustom')" id="btnCopyCustom">
                                        <i class="fas fa-copy me-1"></i> نسخ النتيجة
                                    </button>
                                </div>

                                <?php if (isset($ageResultCustom['invalid']) && $ageResultCustom['invalid']): ?>
                                    <div class="alert alert-danger mb-0">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        تاريخ الميلاد المدخل يقع بعد تاريخ المرجع المحدد (<?php echo htmlspecialchars($ageResultCustom['ref']); ?>).
                                    </div>
                                <?php else: ?>
                                    <div class="row g-3 text-center">
                                        <div class="col-4">
                                            <div class="p-3 rounded-3" style="background-color: rgba(37, 99, 235, 0.08);">
                                                <div class="h2 fw-bold text-primary mb-0"><?php echo $ageResultCustom['years']; ?></div>
                                                <div class="small fw-semibold text-muted">سنة</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded-3" style="background-color: rgba(16, 185, 129, 0.08);">
                                                <div class="h2 fw-bold text-success mb-0"><?php echo $ageResultCustom['months']; ?></div>
                                                <div class="small fw-semibold text-muted">شهر</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded-3" style="background-color: rgba(14, 165, 233, 0.08);">
                                                <div class="h2 fw-bold text-info mb-0"><?php echo $ageResultCustom['days']; ?></div>
                                                <div class="small fw-semibold text-muted">يوم</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Hijri/Gregorian Converter Card -->
                <div class="card shadow admin-card-surface mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-calendar-alt me-2"></i>محوّل التواريخ (ميلادي ↔ هجري)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">
                            قم <strong>بتحويل التاريخ</strong> من <strong>هجري لميلادي</strong> أو <strong>العكس</strong> فوراً دون مغادرة الصفحة.
                        </p>

                        <div class="row g-3">
                            <!-- Gregorian to Hijri -->
                            <div class="col-md-6 border-start-0 border-end border-md-end-1">
                                <h6 class="fw-bold text-primary mb-3"><i class="fas fa-sign-in-alt me-1 text-primary"></i>تحويل من ميلادي لهجري</h6>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">اختر التاريخ الميلادي</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                        <input type="text" id="greg_to_conv" class="form-control border-start-0" placeholder="اختر التاريخ...">
                                        <button type="button" class="btn btn-outline-secondary" onclick="clearDateInput('greg_to_conv')" title="مسح">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="p-3 rounded-3 bg-light border border-dashed text-center">
                                    <span class="small text-muted d-block mb-1">التاريخ الهجري المقابل:</span>
                                    <strong class="h5 text-dark mb-0 d-block" id="hijri_result_txt">-</strong>
                                </div>
                            </div>

                            <!-- Hijri to Gregorian -->
                            <div class="col-md-6">
                                <h6 class="fw-bold text-success mb-3"><i class="fas fa-sign-out-alt me-1 text-success"></i>تحويل من هجري لميلادي</h6>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold mb-0">اختر التاريخ الهجري</label>
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" onclick="clearHijriSelects()" style="font-size: 0.75rem;">
                                            <i class="fas fa-times me-1"></i>مسح
                                        </button>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-4">
                                            <select id="hij_day" class="form-select form-select-sm shadow-sm" onchange="convertHijriToGreg()">
                                                <option value="">اليوم</option>
                                            </select>
                                        </div>
                                        <div class="col-4">
                                            <select id="hij_month" class="form-select form-select-sm shadow-sm" onchange="updateHijriDays(); convertHijriToGreg()">
                                                <option value="">الشهر</option>
                                                <option value="1">محرم</option>
                                                <option value="2">صفر</option>
                                                <option value="3">ربيع الأول</option>
                                                <option value="4">ربيع الآخر</option>
                                                <option value="5">جمادى الأولى</option>
                                                <option value="6">جمادى الآخرة</option>
                                                <option value="7">رجب</option>
                                                <option value="8">شعبان</option>
                                                <option value="9">رمضان</option>
                                                <option value="10">شوال</option>
                                                <option value="11">ذو القعدة</option>
                                                <option value="12">ذو الحجة</option>
                                            </select>
                                        </div>
                                        <div class="col-4">
                                            <select id="hij_year" class="form-select form-select-sm shadow-sm" onchange="updateHijriDays(); convertHijriToGreg()">
                                                <option value="">السنة</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-3 rounded-3 bg-light border border-dashed text-center">
                                    <span class="small text-muted d-block mb-1">التاريخ الميلادي المقابل:</span>
                                    <strong class="h5 text-dark mb-0 d-block" id="greg_result_txt">-</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- General Tools Info -->
            <div class="col-xl-5 col-lg-5 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2"></i>كيفية استخدام أدوات التواريخ</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">تاريخ المرجع للحساب</strong>: يحدد اليوم المستهدف لمعرفة السن الفعلي فيه (مثل نهاية العام الدراسي لحساب سنوات الدراسة).</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">محول التواريخ</strong>: يعتمد على خوارزمية الحساب التقويمي الإسلامي والتقويم الميلادي المطابق لإصدارات شؤون الطلاب الرسمية.</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div>في التواريخ الهجرية، قد تختلف النتائج بفارق يوم واحد طبقاً لرؤية الهلال الفعلية والتقويم المعتمد محلياً.</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== تبويب محلل الرقم القومي ====== -->
    <div class="tab-pane fade" id="pane-nid" role="tabpanel" aria-labelledby="nid-tab">
        <div class="row">
            <div class="col-xl-7 col-lg-7 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-id-card me-2"></i>مستخرج ومحلل بيانات الرقم القومي المصري</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">
                            تقوم هذه الأداة <strong>بفك شفرة الرقم القومي المصري</strong> المكون من <strong>14 رقماً</strong> للتحقق من صحته واستخراج <strong>تاريخ الميلاد</strong>، <strong>النوع</strong>، و<strong>محافظة الميلاد</strong> تلقائياً.
                        </p>

                        <!-- فلاتر اختيار الطالب -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-filter me-2"></i>اختيار طالب من الفصول (اختياري)</h6>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" onclick="resetStudentFilters()" style="font-size: 0.75rem;">
                                    <i class="fas fa-undo me-1"></i> إعادة تعيين الفلاتر
                                </button>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label for="filter_stage" class="form-label small fw-bold">المرحلة الدراسية</label>
                                    <select id="filter_stage" class="form-select form-select-sm" onchange="onStageChange(this.value)">
                                        <option value="">-- اختر المرحلة --</option>
                                        <?php foreach ($stages as $stage): ?>
                                            <option value="<?php echo $stage['id']; ?>"><?php echo htmlspecialchars($stage['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="filter_grade" class="form-label small fw-bold">الصف الدراسي</label>
                                    <select id="filter_grade" class="form-select form-select-sm" onchange="onGradeChange(this.value)">
                                        <option value="">-- اختر الصف --</option>
                                        <?php foreach ($all_grades as $grade): ?>
                                            <option value="<?php echo $grade['id']; ?>"><?php echo htmlspecialchars($grade['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="filter_class" class="form-label small fw-bold">الفصل</label>
                                    <select id="filter_class" class="form-select form-select-sm" onchange="onClassChange(this.value)">
                                        <option value="">-- اختر الفصل --</option>
                                        <?php foreach ($all_classes as $cls): ?>
                                            <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="filter_student" class="form-label small fw-bold">اسم الطالب</label>
                                    <select id="filter_student" class="form-select form-select-sm" onchange="onStudentChange(this.value)">
                                        <option value="">-- اختر الطالب --</option>
                                        <?php foreach ($all_students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <!-- إدخال الرقم القومي -->
                        <div class="mb-4">
                            <label for="nid_input" class="form-label fw-bold"><i class="fas fa-fingerprint me-1 text-primary"></i>الرقم القومي (14 رقماً)</label>
                            <div class="input-group shadow-sm mb-3">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-barcode text-muted"></i></span>
                                <input type="text" id="nid_input" class="form-control border-start-0 border-end-0" maxlength="14" placeholder="مثال: 29910151234567" required>
                                <button type="button" class="btn btn-outline-secondary border-start-0" onclick="clearNationalID()" title="مسح">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-primary shadow px-4 py-2 text-white" style="color: #ffffff !important;" onclick="parseNationalID()">
                                    <i class="fas fa-search me-1 text-white" style="color: #ffffff !important;"></i> تحليل واستخراج
                                </button>
                            </div>
                        </div>

                        <div id="nid_result_area" class="result-area p-4 rounded bg-light border animate__animated animate__fadeIn d-none">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="text-secondary mb-0"><i class="fas fa-clipboard-check me-2"></i>البيانات المستخرجة</h5>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnUseBirthDate" onclick="useBirthDateFromNID()">
                                    <i class="fas fa-graduation-cap me-1"></i> استخدام لحساب سن القبول
                                </button>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="p-3 rounded bg-white border text-center shadow-sm">
                                        <div class="small fw-semibold text-muted mb-1"><i class="fas fa-calendar-day text-primary me-1"></i>تاريخ الميلاد</div>
                                        <div class="h5 fw-bold text-dark mb-0" id="nid_birth_date">-</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 rounded bg-white border text-center shadow-sm">
                                        <div class="small fw-semibold text-muted mb-1"><i class="fas fa-venus-mars text-success me-1"></i>النوع (الجنس)</div>
                                        <div class="h5 fw-bold text-dark mb-0" id="nid_gender">-</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 rounded bg-white border text-center shadow-sm">
                                        <div class="small fw-semibold text-muted mb-1"><i class="fas fa-map-marker-alt text-danger me-1"></i>محافظة الميلاد</div>
                                        <div class="h5 fw-bold text-dark mb-0" id="nid_gov">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="nid_error_area" class="alert alert-danger mt-3 d-none">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            الرقم القومي المدخل غير صحيح. يجب أن يتكون من 14 رقماً تماماً ويبدأ بـ 2 أو 3.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5 col-lg-5 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2"></i>تفاصيل ترميز الرقم القومي</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">الرقم الأول (من اليسار)</strong>: يمثل قرن الميلاد (2 للقرن العشرين 1900-1999، و 3 للقرن الحادي والعشرين 2000-2099).</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">الأرقام من الثاني إلى السابع</strong>: تمثل تاريخ الميلاد بالتنسيق (سنة - شهر - يوم).</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div>
                                    <strong class="text-dark">الرقمان الثامن والتاسع</strong>: يمثلان كود محافظة الميلاد طبقاً للترقيم الإحصائي الرسمي لمصر.
                                    <div class="row g-2 text-end mt-2 pt-2 text-dark" style="font-size: 0.85rem; font-family: 'Cairo', sans-serif;">
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">01</strong> <span>القاهرة</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">02</strong> <span>الإسكندرية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">03</strong> <span>بورسعيد</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">04</strong> <span>السويس</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">11</strong> <span>دمياط</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">12</strong> <span>الدقهلية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">13</strong> <span>الشرقية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">14</strong> <span>القليوبية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">15</strong> <span>كفر الشيخ</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">16</strong> <span>الغربية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">17</strong> <span>المنوفية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">18</strong> <span>البحيرة</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">19</strong> <span>الإسماعيلية</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">21</strong> <span>الجيزة</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">22</strong> <span>بني سويف</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">23</strong> <span>الفيوم</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">24</strong> <span>المنيا</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">25</strong> <span>أسيوط</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">26</strong> <span>سوهاج</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">27</strong> <span>قنا</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">28</strong> <span>أسوان</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">29</strong> <span>الأقصر</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">31</strong> <span>البحر الأحمر</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">32</strong> <span>الوادي الجديد</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">33</strong> <span>مطروح</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">34</strong> <span>شمال سيناء</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">35</strong> <span>جنوب سيناء</span>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3 d-flex align-items-center justify-content-start gap-1">
                                            <strong class="text-primary">88</strong> <span>خارج مصر</span>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">الرقم الثالث عشر (قبل الأخير)</strong>: يحدد النوع (فردي للذكور، وزوجي للإناث).</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== تبويب التجانس العمري ====== -->
    <div class="tab-pane fade" id="pane-homogeneity" role="tabpanel" aria-labelledby="homogeneity-tab">
        <div class="row">
            <div class="col-xl-7 col-lg-7 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-people-arrows me-2"></i>حاسبة التجانس العمري وفارق السن</h5>
                    </div>
                    <div class="card-body">
                        <!-- Toggle switcher between Individual and Statistical comparison -->
                        <div class="d-flex justify-content-center mb-4">
                            <div class="btn-group shadow-sm" role="group" aria-label="Homogeneity Mode">
                                <input type="radio" class="btn-check" name="homo_mode" id="homo_mode_individual" autocomplete="off" checked onclick="toggleHomogeneityMode('individual')">
                                <label class="btn btn-outline-primary px-4 fw-bold" for="homo_mode_individual"><i class="fas fa-user-friends me-1"></i>مقارنة فردية (طالبين)</label>

                                <input type="radio" class="btn-check" name="homo_mode" id="homo_mode_statistical" autocomplete="off" onclick="toggleHomogeneityMode('statistical')">
                                <label class="btn btn-outline-primary px-4 fw-bold" for="homo_mode_statistical"><i class="fas fa-chart-bar me-1"></i>مقارنة إحصائية (فصول/صفوف)</label>
                            </div>
                        </div>

                        <!-- Individual comparison section -->
                        <div id="homo_individual_section">
                            <p class="text-muted small mb-4">
                                قارن بين تاريخي ميلاد لطالبين (مثل الأشقاء أو التوائم) لحساب فارق السن الدقيق بينهما باليوم والشهر والسنة وتقييم التقارب العمري للدراسة.
                            </p>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="homo_birth1" class="form-label fw-bold"><i class="fas fa-user text-primary me-1"></i>تاريخ ميلاد الطالب الأول</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                        <input type="text" id="homo_birth1" class="form-control border-start-0 flatpickr-date" placeholder="اختر التاريخ..." required>
                                        <button type="button" class="btn btn-outline-secondary" onclick="clearDateInput('homo_birth1')" title="مسح">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="homo_birth2" class="form-label fw-bold"><i class="fas fa-user-friends text-success me-1"></i>تاريخ ميلاد الطالب الثاني</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                        <input type="text" id="homo_birth2" class="form-control border-start-0 flatpickr-date" placeholder="اختر التاريخ..." required>
                                        <button type="button" class="btn btn-outline-secondary" onclick="clearDateInput('homo_birth2')" title="مسح">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mb-4">
                                <button type="button" class="btn btn-primary px-4" onclick="calculateAgeHomogeneity()">
                                    <i class="fas fa-calculator me-1"></i> احسب فارق السن والتجانس
                                </button>
                            </div>

                            <div id="homo_result_area" class="result-area p-4 rounded bg-light border animate__animated animate__fadeIn d-none">
                                <h5 class="text-secondary mb-3"><i class="fas fa-poll me-2"></i>نتيجة التحليل</h5>

                                <div class="row g-3 text-center mb-3">
                                    <div class="col-4">
                                        <div class="p-3 rounded bg-white border shadow-sm">
                                            <div class="h2 fw-bold text-primary mb-0" id="homo_res_years">0</div>
                                            <div class="small fw-semibold text-muted">سنة</div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-3 rounded bg-white border shadow-sm">
                                            <div class="h2 fw-bold text-success mb-0" id="homo_res_months">0</div>
                                            <div class="small fw-semibold text-muted">شهر</div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-3 rounded bg-white border shadow-sm">
                                            <div class="h2 fw-bold text-info mb-0" id="homo_res_days">0</div>
                                            <div class="small fw-semibold text-muted">يوم</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-info mb-0" id="homo_advisor_box">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <span id="homo_advisor_txt">الرجاء إدخال تواريخ صحيحة.</span>
                                </div>
                            </div>
                        </div>

                        <!-- Statistical comparison section -->
                        <div id="homo_statistical_section" class="d-none">
                            <p class="text-muted small mb-4">
                                قارن <strong>إحصائياً</strong> بين <strong>توزيع الأعمار</strong>، و<strong>متوسط السن</strong>، و<strong>نسبة التجانس</strong> للطلاب المقيدين في <strong>فصلين</strong>، أو <strong>صفين</strong>، أو <strong>مرحلتين</strong> دراسيتين مختلفتين.
                            </p>

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label for="homo_compare_type" class="form-label fw-bold"><i class="fas fa-filter text-primary me-1"></i>نوع المقارنة</label>
                                    <select id="homo_compare_type" class="form-select shadow-sm" onchange="onHomogeneityCompareTypeChange()">
                                        <option value="classes">بين فصلين دراسيين</option>
                                        <option value="grades">بين صفين دراسيين</option>
                                        <option value="stages">بين مرحلتين دراسيتين</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label id="homo_label_side1" for="homo_side1" class="form-label fw-bold"><i class="fas fa-arrow-right text-success me-1"></i>الطرف الأول (أ)</label>
                                    <select id="homo_side1" class="form-select shadow-sm">
                                        <!-- Populated via JS -->
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label id="homo_label_side2" for="homo_side2" class="form-label fw-bold"><i class="fas fa-arrow-left text-danger me-1"></i>الطرف الثاني (ب)</label>
                                    <select id="homo_side2" class="form-select shadow-sm">
                                        <!-- Populated via JS -->
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mb-4">
                                <button type="button" class="btn btn-primary px-4" onclick="calculateStatisticalHomogeneity()">
                                    <i class="fas fa-chart-bar me-1"></i> قارن إحصائياً
                                </button>
                            </div>

                            <div id="homo_stat_result_area" class="result-area p-4 rounded bg-light border animate__animated animate__fadeIn d-none">
                                <h5 class="text-secondary mb-3"><i class="fas fa-poll me-2"></i>نتائج المقارنة الإحصائية</h5>

                                <div class="row g-3 mb-4">
                                    <!-- Side 1 Stats -->
                                    <div class="col-md-6">
                                        <div class="p-3 rounded bg-white border shadow-sm h-100">
                                            <h6 class="fw-bold text-success border-bottom pb-2 mb-3" id="homo_stat_name1">الطرف الأول (أ)</h6>
                                            <ul class="list-unstyled mb-0 small">
                                                <li class="mb-2 d-flex justify-content-between">
                                                    <span>عدد الطلاب:</span>
                                                    <strong class="text-dark" id="homo_stat_count1">0</strong>
                                                </li>
                                                <li class="mb-2 d-flex justify-content-between">
                                                    <span>متوسط السن:</span>
                                                    <strong class="text-primary" id="homo_stat_avg1">0 سنة و 0 شهر</strong>
                                                </li>
                                                <li class="d-flex justify-content-between align-items-center">
                                                    <span>مستوى التجانس:</span>
                                                    <span id="homo_stat_rating1" class="badge">غير متوفر</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <!-- Side 2 Stats -->
                                    <div class="col-md-6">
                                        <div class="p-3 rounded bg-white border shadow-sm h-100">
                                            <h6 class="fw-bold text-danger border-bottom pb-2 mb-3" id="homo_stat_name2">الطرف الثاني (ب)</h6>
                                            <ul class="list-unstyled mb-0 small">
                                                <li class="mb-2 d-flex justify-content-between">
                                                    <span>عدد الطلاب:</span>
                                                    <strong class="text-dark" id="homo_stat_count2">0</strong>
                                                </li>
                                                <li class="mb-2 d-flex justify-content-between">
                                                    <span>متوسط السن:</span>
                                                    <strong class="text-primary" id="homo_stat_avg2">0 سنة و 0 شهر</strong>
                                                </li>
                                                <li class="d-flex justify-content-between align-items-center">
                                                    <span>مستوى التجانس:</span>
                                                    <span id="homo_stat_rating2" class="badge">غير متوفر</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-soft-primary border shadow-sm mb-0 py-3" id="homo_stat_advisor_box">
                                    <h6 class="alert-heading fw-bold mb-2"><i class="fas fa-info-circle me-1"></i>التحليل والتوجيه التربوي:</h6>
                                    <div class="small" id="homo_stat_advisor_txt">جاري احتساب النتائج...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5 col-lg-5 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-chart-line me-2"></i>أهمية التقييم العمري</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">توجيه الإخوة والتوائم</strong>: يفيد الأخصائي الاجتماعي في تحديد ما إذا كان من الأفضل وضع الطلاب متقاربي العمر في نفس الفصل أو فصلين مختلفين لمنع التأثير النفسي السلبي.</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">تقييم التجانس العمري للفصل</strong>: يساعد في توزيع الفئات السنية وتحديد ملائمتها لبعضها في الأنشطة المدرسية والرياضية المشتركة.</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== تبويب توزيع وتكافؤ الفصول ====== -->
    <div class="tab-pane fade" id="pane-balancing" role="tabpanel" aria-labelledby="balancing-tab">
        <div class="row">
            <div class="col-xl-7 col-lg-7 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-balance-scale me-2"></i>حاسبة التوزيع العادل وتكافؤ الفصول</h5>
                    </div>
                    <div class="card-body" style="padding: 1.25rem !important;">
                        <p class="text-muted small mb-4">
                            قم بإدخال <strong>الأعداد الكلية للبنين والبنات</strong> بالإضافة لـ <strong>عدد الفصول المتاحة</strong>، للحصول على محاكاة لـ <strong>التوزيع العادل والمتساوي</strong> للطلاب عبر الفصول دون <strong>تكدس</strong> أو <strong>عدم توازن</strong>.
                        </p>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="bal_boys" class="form-label fw-bold"><i class="fas fa-mars text-primary me-1"></i>إجمالي البنين</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-user-friends text-muted"></i></span>
                                    <input type="number" id="bal_boys" class="form-control border-start-0" min="0" placeholder="مثال: 45" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="bal_girls" class="form-label fw-bold"><i class="fas fa-venus text-danger me-1"></i>إجمالي البنات</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-user-friends text-muted"></i></span>
                                    <input type="number" id="bal_girls" class="form-control border-start-0" min="0" placeholder="مثال: 38" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="bal_classes" class="form-label fw-bold"><i class="fas fa-school text-warning me-1"></i>عدد الفصول المتاحة</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-door-open text-muted"></i></span>
                                    <input type="number" id="bal_classes" class="form-control border-start-0" min="1" placeholder="مثال: 3" required>
                                </div>
                            </div>
                        </div>

                        <div class="text-end mb-4">
                            <button type="button" class="btn btn-primary shadow px-4 py-2 text-white" style="color: #ffffff !important;" onclick="calculateClassBalancing()">
                                <i class="fas fa-random me-1 text-white" style="color: #ffffff !important;"></i> توزيع وموازنة الفصول
                            </button>
                        </div>

                        <div id="bal_result_area" class="result-area p-4 rounded bg-light border animate__animated animate__fadeIn d-none">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="text-secondary mb-0"><i class="fas fa-list-ol me-2"></i>خطة التوزيع المقترحة للفصول</h5>
                                <span class="badge bg-soft-primary text-primary px-3 py-2 fs-6" id="bal_total_badge">الإجمالي: 0 طالب</span>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped bg-white align-middle text-center small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>اسم الفصل المقترح</th>
                                            <th><i class="fas fa-mars text-primary me-1"></i>البنين</th>
                                            <th><i class="fas fa-venus text-danger me-1"></i>البنات</th>
                                            <th>إجمالي طلاب الفصل</th>
                                            <th>نسبة التكافؤ (بنين / بنات)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bal_table_body">
                                        <!-- سيتم ملؤه ديناميكياً بالجافا سكريبت -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5 col-lg-5 d-flex flex-column">
                <div class="card shadow admin-card-surface mb-4 h-100">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2"></i>أهداف تكافؤ وتوزيع الفصول</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">توازن النوع</strong>: يضمن توزيعاً متساوياً للبنين والبنات في كل فصل لتجنب الفصول ذات الأغلبية المطلقة من نوع واحد، مما يساهم في بيئة تعليمية أفضل.</div>
                            </li>
                            <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <div><strong class="text-dark">تكامل القدرة الاستيعابية</strong>: يوزع الكسور والزيادات الطلابية على الفصول بحيث لا يتعدى الفارق بين أكبر فصل وأصغر فصل طالباً واحداً فقط.</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../classes/Presentation/CalculationTools/script_bootstrap.php'; ?>

function copyResultText(textToCopy, btnId) {
    navigator.clipboard.writeText(textToCopy).then(function() {
        const btn = document.getElementById(btnId);
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> تم النسخ!';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        btn.classList.add('text-white');
        setTimeout(function() {
            btn.innerHTML = originalHtml;
            btn.classList.replace('btn-success', 'btn-outline-secondary');
            btn.classList.remove('text-white');
        }, 2000);
    });
}

// Tabular Islamic Calendar Algorithm
const hijriMonths = [
    "محرم", "صفر", "ربيع الأول", "ربيع الآخر", "جمادى الأولى", "جمادى الآخرة",
    "رجب", "شعبان", "رمضان", "شوال", "ذو القعدة", "ذو الحجة"
];

function convertGregToHijri() {
    const inputVal = document.getElementById('greg_to_conv').value;
    if (!inputVal) return;

    const d = new Date(inputVal);
    let day = d.getDate();
    let month = d.getMonth() + 1;
    let year = d.getFullYear();

    if (month < 3) {
        year -= 1;
        month += 12;
    }

    let jd = Math.floor(365.25 * year) + Math.floor(30.6001 * (month + 1)) + day + 1720995;

    // Tabular Islamic calendar
    let l = jd - 1948084;
    let n = Math.floor((30 * l + 10646) / 10631);
    l = l - Math.floor((10631 * n - 10646) / 30);
    let j = Math.floor((l + 43) / 29.5);
    l = l - Math.floor(29.5 * j - 43);

    let id = l;
    let im = j;
    let iy = n;

    // Safety boundaries
    if (im < 1) im = 1;
    if (im > 12) im = 12;

    document.getElementById('hijri_result_txt').innerText = `${id} ${hijriMonths[im - 1]} ${iy} هـ`;
}

function convertHijriToGreg() {
    var hDay = parseInt(document.getElementById('hij_day').value);
    var hMonth = parseInt(document.getElementById('hij_month').value);
    var hYear = parseInt(document.getElementById('hij_year').value);

    if (!hDay || !hMonth || !hYear) {
        document.getElementById('greg_result_txt').innerText = '-';
        return;
    }

    // Kuwayt / Tabular algorithm
    var jd = Math.floor((11 * hYear + 3) / 30) + 354 * hYear + 30 * hMonth - Math.floor((hMonth - 1) / 2) + hDay + 1948440 - 385;

    if (jd > 2299160) {
        var l = jd + 68569;
        var n = Math.floor((4 * l) / 146097);
        l = l - Math.floor((146097 * n + 3) / 4);
        var i = Math.floor((4000 * (l + 1)) / 1461001);
        l = l - Math.floor((1461 * i) / 4) + 31;
        var j = Math.floor((80 * l) / 2447);
        var d = l - Math.floor((2447 * j) / 80);
        l = Math.floor(j / 11);
        var m = j + 2 - 12 * l;
        var y = 100 * (n - 49) + i + l;

        var gregMonths = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                          'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        document.getElementById('greg_result_txt').innerHTML =
            '<span class="d-block">' + d + ' ' + gregMonths[m - 1] + ' ' + y + ' م</span>' +
            '<span class="small text-muted">' + y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0') + '</span>';
    }
}

function updateHijriDays() {
    var month = parseInt(document.getElementById('hij_month').value);
    var daySelect = document.getElementById('hij_day');
    var currentDay = parseInt(daySelect.value);
    // Odd months have 30 days, even months have 29 (simplified tabular)
    var maxDays = (!month) ? 30 : (month % 2 === 1 ? 30 : 29);
    daySelect.innerHTML = '<option value="">اليوم</option>';
    for (var i = 1; i <= maxDays; i++) {
        var selected = (i === currentDay) ? ' selected' : '';
        daySelect.innerHTML += '<option value="' + i + '"' + selected + '>' + i + '</option>';
    }
}

function clearHijriSelects() {
    document.getElementById('hij_day').selectedIndex = 0;
    document.getElementById('hij_month').selectedIndex = 0;
    document.getElementById('hij_year').selectedIndex = 0;
    document.getElementById('greg_result_txt').innerText = '-';
}

// Egyptian National ID Parser Logic
const govCodes = {
    "01": "القاهرة", "02": "الإسكندرية", "03": "بورسعيد", "04": "السويس",
    "11": "دمياط", "12": "الدقهلية", "13": "الشرقية", "14": "القليوبية",
    "15": "كفر الشيخ", "16": "الغربية", "17": "المنوفية", "18": "البحيرة",
    "19": "الإسماعيلية", "21": "الجيزة", "22": "بني سويف", "23": "الفيوم",
    "24": "المنيا", "25": "أسيوط", "26": "سوهاج", "27": "قنا",
    "28": "أسوان", "29": "الأقصر", "31": "البحر الأحمر", "32": "الوادي الجديد",
    "33": "مطروح", "34": "شمال سيناء", "35": "جنوب سيناء", "88": "خارج جمهورية مصر العربية"
};

let extractedBirthDate = "";

function filterDropdowns() {
    const stageSelect = document.getElementById('filter_stage');
    const gradeSelect = document.getElementById('filter_grade');
    const classSelect = document.getElementById('filter_class');
    const studentSelect = document.getElementById('filter_student');

    const stageId = stageSelect.value;
    const gradeId = gradeSelect.value;
    const classId = classSelect.value;
    const studentId = studentSelect.value;

    // 1. Filter Grades
    let filteredGrades = dbGrades;
    if (stageId) {
        filteredGrades = dbGrades.filter(g => g.stage_id == stageId);
    }

    const currentGrade = gradeSelect.value;
    gradeSelect.innerHTML = '<option value="">-- اختر الصف --</option>';
    filteredGrades.forEach(g => {
        const opt = document.createElement('option');
        opt.value = g.id;
        opt.textContent = g.name;
        if (g.id == currentGrade) opt.selected = true;
        gradeSelect.appendChild(opt);
    });

    // 2. Filter Classes
    let filteredClasses = dbClasses;
    if (gradeId) {
        filteredClasses = dbClasses.filter(c => c.grade_id == gradeId);
    } else if (stageId) {
        const gradeIds = filteredGrades.map(g => g.id);
        filteredClasses = dbClasses.filter(c => gradeIds.includes(c.grade_id));
    }

    const currentClass = classSelect.value;
    classSelect.innerHTML = '<option value="">-- اختر الفصل --</option>';
    filteredClasses.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        if (c.id == currentClass) opt.selected = true;
        classSelect.appendChild(opt);
    });

    // 3. Filter Students
    let filteredStudents = dbStudents;
    if (classId) {
        filteredStudents = dbStudents.filter(s => s.class_id == classId);
    } else if (gradeId) {
        filteredStudents = dbStudents.filter(s => s.grade_id == gradeId);
    } else if (stageId) {
        filteredStudents = dbStudents.filter(s => s.stage_id == stageId);
    }

    const currentStudent = studentSelect.value;
    studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
    filteredStudents.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name;
        if (s.id == currentStudent) opt.selected = true;
        studentSelect.appendChild(opt);
    });
}

function onStageChange(stageId) {
    const gradeSelect = document.getElementById('filter_grade');
    const classSelect = document.getElementById('filter_class');
    const studentSelect = document.getElementById('filter_student');

    if (stageId) {
        const gradeVal = gradeSelect.value;
        const matchingGrade = dbGrades.find(g => g.id == gradeVal && g.stage_id == stageId);
        if (!matchingGrade) {
            gradeSelect.value = "";
            classSelect.value = "";
            studentSelect.value = "";
        }
    }
    filterDropdowns();
}

function onGradeChange(gradeId) {
    const stageSelect = document.getElementById('filter_stage');
    const classSelect = document.getElementById('filter_class');
    const studentSelect = document.getElementById('filter_student');

    if (gradeId) {
        const selectedGrade = dbGrades.find(g => g.id == gradeId);
        if (selectedGrade && selectedGrade.stage_id) {
            stageSelect.value = selectedGrade.stage_id;
        }
        const classVal = classSelect.value;
        const matchingClass = dbClasses.find(c => c.id == classVal && c.grade_id == gradeId);
        if (!matchingClass) {
            classSelect.value = "";
            studentSelect.value = "";
        }
    }
    filterDropdowns();
}

function onClassChange(classId) {
    const stageSelect = document.getElementById('filter_stage');
    const gradeSelect = document.getElementById('filter_grade');
    const studentSelect = document.getElementById('filter_student');

    if (classId) {
        const selectedClass = dbClasses.find(c => c.id == classId);
        if (selectedClass && selectedClass.grade_id) {
            gradeSelect.value = selectedClass.grade_id;
            const selectedGrade = dbGrades.find(g => g.id == selectedClass.grade_id);
            if (selectedGrade && selectedGrade.stage_id) {
                stageSelect.value = selectedGrade.stage_id;
            }
        }
        const studentVal = studentSelect.value;
        const matchingStudent = dbStudents.find(s => s.id == studentVal && s.class_id == classId);
        if (!matchingStudent) {
            studentSelect.value = "";
        }
    }
    filterDropdowns();
}

function onStudentChange(studentId) {
    const stageSelect = document.getElementById('filter_stage');
    const gradeSelect = document.getElementById('filter_grade');
    const classSelect = document.getElementById('filter_class');

    if (studentId) {
        const selectedStudent = dbStudents.find(s => s.id == studentId);
        if (selectedStudent) {
            if (selectedStudent.class_id) {
                classSelect.value = selectedStudent.class_id;
            }
            if (selectedStudent.grade_id) {
                gradeSelect.value = selectedStudent.grade_id;
            }
            if (selectedStudent.stage_id) {
                stageSelect.value = selectedStudent.stage_id;
            }
        }
        loadStudentNID(studentId);
    } else {
        clearNationalID();
    }
    filterDropdowns();
}

function loadStudentNID(studentId) {
    const nidInput = document.getElementById('nid_input');
    nidInput.value = "";

    if (!studentId) return;

    fetch(`calculation_tools.php?ajax_action=get_national_id&student_id=${studentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.national_id) {
                nidInput.value = data.national_id;
                parseNationalID();
            } else {
                alert("عذراً، هذا الطالب ليس لديه رقم قومي مسجل في النظام حالياً.");
            }
        });
}

function resetStudentFilters() {
    document.getElementById('filter_stage').value = "";
    document.getElementById('filter_grade').value = "";
    document.getElementById('filter_class').value = "";
    document.getElementById('filter_student').value = "";

    document.getElementById('nid_input').value = "";
    document.getElementById('nid_result_area').classList.add('d-none');
    document.getElementById('nid_error_area').classList.add('d-none');
    extractedBirthDate = "";

    filterDropdowns();
}

function clearNationalID() {
    document.getElementById('nid_input').value = "";
    document.getElementById('nid_result_area').classList.add('d-none');
    document.getElementById('nid_error_area').classList.add('d-none');
    extractedBirthDate = "";
}

function parseNationalID() {
    const input = document.getElementById('nid_input').value.trim();
    const resultArea = document.getElementById('nid_result_area');
    const errorArea = document.getElementById('nid_error_area');

    resultArea.classList.add('d-none');
    errorArea.classList.add('d-none');

    if (input.length !== 14 || !/^\d+$/.test(input)) {
        errorArea.classList.remove('d-none');
        return;
    }

    const centuryDigit = parseInt(input.charAt(0));
    if (centuryDigit !== 2 && centuryDigit !== 3) {
        errorArea.classList.remove('d-none');
        return;
    }

    const yearPart = input.substring(1, 3);
    const monthPart = input.substring(3, 5);
    const dayPart = input.substring(5, 7);

    const century = centuryDigit === 2 ? "19" : "20";
    const fullYear = century + yearPart;

    const birthDateStr = `${fullYear}-${monthPart}-${dayPart}`;
    const birthDate = new Date(birthDateStr);

    if (isNaN(birthDate.getTime()) || birthDate.getDate() !== parseInt(dayPart) || (birthDate.getMonth() + 1) !== parseInt(monthPart)) {
        errorArea.classList.remove('d-none');
        return;
    }

    extractedBirthDate = birthDateStr;

    const govCode = input.substring(7, 9);
    const governorate = govCodes[govCode] || "غير معروف";

    const genderDigit = parseInt(input.charAt(12));
    const gender = (genderDigit % 2 === 0) ? "أنثى" : "ذكر";

    document.getElementById('nid_birth_date').innerText = birthDateStr;
    document.getElementById('nid_gender').innerText = gender;
    document.getElementById('nid_gov').innerText = governorate;

    resultArea.classList.remove('d-none');
}

function useBirthDateFromNID() {
    if (!extractedBirthDate) return;
    document.getElementById('birth_date_admission').value = extractedBirthDate;
    const tabEl = document.querySelector('#admission-tab');
    const tab = new bootstrap.Tab(tabEl);
    tab.show();
}

// Age Homogeneity Logic
function calculateAgeHomogeneity() {
    const date1Input = document.getElementById('homo_birth1').value;
    const date2Input = document.getElementById('homo_birth2').value;
    const resultArea = document.getElementById('homo_result_area');

    if (!date1Input || !date2Input) {
        alert("الرجاء إدخال تاريخي الميلاد للطالبين.");
        return;
    }

    const d1 = new Date(date1Input);
    const d2 = new Date(date2Input);

    let early = d1 < d2 ? d1 : d2;
    let late = d1 < d2 ? d2 : d1;

    let years = late.getFullYear() - early.getFullYear();
    let months = late.getMonth() - early.getMonth();
    let days = late.getDate() - early.getDate();

    if (days < 0) {
        months -= 1;
        const prevMonth = new Date(late.getFullYear(), late.getMonth(), 0);
        days += prevMonth.getDate();
    }

    if (months < 0) {
        years -= 1;
        months += 12;
    }

    document.getElementById('homo_res_years').innerText = years;
    document.getElementById('homo_res_months').innerText = months;
    document.getElementById('homo_res_days').innerText = days;

    const totalMonths = (years * 12) + months;
    let advisorTxt = "";
    let alertClass = "alert-info";

    if (totalMonths === 0 && days === 0) {
        advisorTxt = "تاريخ الميلاد متطابق تماماً (توائم متطابقين في نفس اليوم).";
        alertClass = "alert-success";
    } else if (totalMonths < 12) {
        advisorTxt = "تقارب عمري ممتاز ومثالي (الفارق أقل من سنة). يمكن دمجهما في نفس الصف أو فصلين متجانسين.";
        alertClass = "alert-success";
    } else if (totalMonths >= 12 && totalMonths < 36) {
        advisorTxt = "فارق السن طبيعي بين الإخوة (بين سنة إلى 3 سنوات).";
        alertClass = "alert-info";
    } else {
        advisorTxt = "فارق سن كبير (أكثر من 3 سنوات). الطالبان يتبعان مرحلتين دراسيتين مختلفتين تماماً.";
        alertClass = "alert-warning";
    }

    const advisorBox = document.getElementById('homo_advisor_box');
    advisorBox.className = `alert ${alertClass} mb-0`;
    document.getElementById('homo_advisor_txt').innerText = advisorTxt;

    resultArea.classList.remove('d-none');
}

// clearDateInput تُوفَّر الآن مركزياً عبر assets/js/air-datepicker-init.js

// Statistical Homogeneity Logic
function toggleHomogeneityMode(mode) {
    const indSection = document.getElementById('homo_individual_section');
    const statSection = document.getElementById('homo_statistical_section');
    if (mode === 'individual') {
        indSection.classList.remove('d-none');
        statSection.classList.add('d-none');
    } else {
        indSection.classList.add('d-none');
        statSection.classList.remove('d-none');
        const side1 = document.getElementById('homo_side1');
        if (side1.options.length === 0) {
            onHomogeneityCompareTypeChange();
        }
    }
}

function onHomogeneityCompareTypeChange() {
    const type = document.getElementById('homo_compare_type').value;
    const side1 = document.getElementById('homo_side1');
    const side2 = document.getElementById('homo_side2');
    const label1 = document.getElementById('homo_label_side1');
    const label2 = document.getElementById('homo_label_side2');

    let optionsData = [];
    let labelPrefix = "";

    if (type === 'classes') {
        optionsData = dbClasses;
        labelPrefix = "الفصل";
    } else if (type === 'grades') {
        optionsData = dbGrades;
        labelPrefix = "الصف";
    } else if (type === 'stages') {
        optionsData = dbStages;
        labelPrefix = "المرحلة";
    }

    label1.innerHTML = `<i class="fas fa-arrow-right text-success me-1"></i>${labelPrefix} الأول (أ)`;
    label2.innerHTML = `<i class="fas fa-arrow-left text-danger me-1"></i>${labelPrefix} الثاني (ب)`;

    side1.innerHTML = "";
    side2.innerHTML = "";

    if (optionsData.length === 0) {
        const opt = document.createElement('option');
        opt.value = "";
        opt.innerText = "لا توجد بيانات متاحة";
        side1.appendChild(opt.cloneNode(true));
        side2.appendChild(opt.cloneNode(true));
        return;
    }

    optionsData.forEach((item, index) => {
        const opt1 = document.createElement('option');
        opt1.value = item.id;
        opt1.innerText = item.name;
        side1.appendChild(opt1);

        const opt2 = document.createElement('option');
        opt2.value = item.id;
        opt2.innerText = item.name;
        if (index === 1 || (optionsData.length === 1 && index === 0)) {
            opt2.selected = true;
        }
        side2.appendChild(opt2);
    });
}

function calculateStatisticalHomogeneity() {
    const type = document.getElementById('homo_compare_type').value;
    const id1 = document.getElementById('homo_side1').value;
    const id2 = document.getElementById('homo_side2').value;
    const resultArea = document.getElementById('homo_stat_result_area');

    if (!id1 || !id2) {
        let selectLabel = "الطرفين";
        if (type === 'classes') selectLabel = "الفصلين";
        else if (type === 'grades') selectLabel = "الصفين";
        else if (type === 'stages') selectLabel = "المرحلتين";
        alert(`الرجاء تحديد كلا ${selectLabel} لإجراء المقارنة.`);
        return;
    }
    if (id1 === id2) {
        let itemLabel = "الفصل";
        if (type === 'grades') itemLabel = "الصف";
        else if (type === 'stages') itemLabel = "المرحلة";
        alert(`الرجاء اختيار ${itemLabel}ين مختلفين للمقارنة (لا يمكن مقارنة ${itemLabel} مع نفسه).`);
        return;
    }

    fetch(`calculation_tools.php?ajax_action=compare_stats&type=${type}&id1=${id1}&id2=${id2}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('homo_stat_name1').innerText = data.name1;
                document.getElementById('homo_stat_count1').innerText = `${data.stats1.count} طالب/طالبة`;
                document.getElementById('homo_stat_avg1').innerText = data.stats1.count > 0 ? `${data.stats1.years} سنة، و ${data.stats1.months} شهر، و ${data.stats1.days} يوم` : '0 سنة';

                const r1 = document.getElementById('homo_stat_rating1');
                r1.innerText = data.rating1.label;
                r1.className = data.rating1.class;

                document.getElementById('homo_stat_name2').innerText = data.name2;
                document.getElementById('homo_stat_count2').innerText = `${data.stats2.count} طالب/طالبة`;
                document.getElementById('homo_stat_avg2').innerText = data.stats2.count > 0 ? `${data.stats2.years} سنة، و ${data.stats2.months} شهر، و ${data.stats2.days} يوم` : '0 سنة';

                const r2 = document.getElementById('homo_stat_rating2');
                r2.innerText = data.rating2.label;
                r2.className = data.rating2.class;

                document.getElementById('homo_stat_advisor_txt').innerHTML = data.advisor;
                resultArea.classList.remove('d-none');
            } else {
                alert("حدث خطأ أثناء جلب البيانات.");
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("فشل الاتصال بالخادم لإجراء المقارنة.");
        });
}

// Class Balancing Logic
function calculateClassBalancing() {
    const boys = parseInt(document.getElementById('bal_boys').value);
    const girls = parseInt(document.getElementById('bal_girls').value);
    const classesCount = parseInt(document.getElementById('bal_classes').value);
    const resultArea = document.getElementById('bal_result_area');
    const tableBody = document.getElementById('bal_table_body');

    if (isNaN(boys) || isNaN(girls) || isNaN(classesCount) || boys < 0 || girls < 0 || classesCount < 1) {
        alert("الرجاء إدخال قيم صحيحة للبنين والبنات وعدد الفصول.");
        return;
    }

    const totalStudents = boys + girls;
    document.getElementById('bal_total_badge').innerText = `الإجمالي: ${totalStudents} طالب وطالبة`;

    tableBody.innerHTML = "";

    const baseBoys = Math.floor(boys / classesCount);
    const extraBoys = boys % classesCount;

    const baseGirls = Math.floor(girls / classesCount);
    const extraGirls = girls % classesCount;

    const classNames = ["أ", "ب", "ج", "د", "هـ", "و", "ز", "ح", "ط", "ي"];

    for (let i = 0; i < classesCount; i++) {
        const classBoysCount = baseBoys + (i < extraBoys ? 1 : 0);
        const classGirlsCount = baseGirls + (i < extraGirls ? 1 : 0);
        const classTotal = classBoysCount + classGirlsCount;

        let boysPct = classTotal > 0 ? ((classBoysCount / classTotal) * 100).toFixed(1) : 0;
        let girlsPct = classTotal > 0 ? ((classGirlsCount / classTotal) * 100).toFixed(1) : 0;

        const className = i < classNames.length ? `الفصل (${classNames[i]})` : `الفصل (${i + 1})`;

        const row = `
            <tr>
                <td class="fw-bold text-dark">${className}</td>
                <td><span class="badge bg-soft-primary text-primary px-3 py-2">${classBoysCount}</span></td>
                <td><span class="badge bg-soft-danger text-danger px-3 py-2" style="background-color: rgba(239, 68, 68, 0.08);">${classGirlsCount}</span></td>
                <td class="fw-bold">${classTotal}</td>
                <td>
                    <div class="progress" style="height: 18px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: ${boysPct}%" title="بنين: ${boysPct}%">${boysPct}%</div>
                        <div class="progress-bar bg-danger" role="progressbar" style="width: ${girlsPct}%" title="بنات: ${girlsPct}%">${girlsPct}%</div>
                    </div>
                </td>
            </tr>
        `;
        tableBody.insertAdjacentHTML('beforeend', row);
    }

    resultArea.classList.remove('d-none');
}

// Run initial conversion on page load if date is set
window.addEventListener('DOMContentLoaded', function() {
    // التهيئة العامة لحقول .flatpickr-date تتم الآن مركزياً عبر assets/js/air-datepicker-init.js
    // (المحمّل في includes/admin_footer.php). هنا نبقى فقط الحالة الخاصة بمحول التواريخ.

    // Initialise Air Datepicker with custom onSelect handler for converter
    new AirDatepicker("#greg_to_conv", {
        locale: window.AirDatepickerArabicLocale,
        dateFormat: 'yyyy-MM-dd',
        autoClose: true,
        onSelect: function({date, formattedDate, datepicker}) {
            convertGregToHijri();
        }
    });

    // Populate Hijri year and day selects
    (function() {
        var yearSelect = document.getElementById('hij_year');
        var daySelect = document.getElementById('hij_day');
        // Populate years: 1400 to 1500
        for (var y = 1446; y >= 1400; y--) {
            yearSelect.innerHTML += '<option value="' + y + '">' + y + ' هـ</option>';
        }
        // Also add future years
        var futureHtml = '';
        for (var y = 1447; y <= 1500; y++) {
            futureHtml += '<option value="' + y + '">' + y + ' هـ</option>';
        }
        yearSelect.innerHTML = '<option value="">السنة</option>' + futureHtml + yearSelect.innerHTML.replace('<option value="">السنة</option>', '');
        // Populate days 1-30
        for (var d = 1; d <= 30; d++) {
            daySelect.innerHTML += '<option value="' + d + '">' + d + '</option>';
        }
    })();

    // Trigger conversion on manual typing/changes for Gregorian converter
    $('#greg_to_conv').on('input change', function() {
        convertGregToHijri();
    });
});
</script>

<?php include_once __DIR__ . '/../includes/admin_footer.php'; ?>
