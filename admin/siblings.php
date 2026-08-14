<?php
/**
 * إدارة الإخوة والأشقاء وصلات القرابة — صفحة مستقلة لعرض وإدارة علاقات الأخوة وصلات القرابة بين الطلاب
 */
$page_title = "إدارة الإخوة والأشقاء وصلات القرابة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/StudentProfileRepository.php';
require_once '../classes/StudentRelationshipService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$studentRelationshipService = new StudentRelationshipService($db, new StudentProfileRepository($db));

function siblings_public_error_message(Throwable $error): string
{
    for ($cursor = $error; $cursor !== null; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof PDOException) {
            error_log('Student relationship database operation failed: ' . $error->getMessage());
            return 'تعذر حفظ العلاقة في قاعدة البيانات. لم تُحفظ تغييرات جزئية.';
        }
    }
    return $error->getMessage();
}

// ===== AJAX Endpoints =====
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['ajax'] === 'search_students') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            echo json_encode(['results' => []]);
            exit();
        }
        $ayId = AcademicYear::currentId($db);
        if ($ayId > 0) {
            $stmt = $db->prepare("SELECT u.id, u.name, sp.student_code, c.name as class_name
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                LEFT JOIN classes c ON c.id = se.class_id
                WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
                AND (u.name LIKE ? OR sp.student_code LIKE ?)
                LIMIT 20");
            $stmt->execute([$ayId, "%$q%", "%$q%"]);
        } else {
            $stmt = $db->prepare("SELECT u.id, u.name, sp.student_code, c.name as class_name
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM student_profiles esp WHERE esp.user_id=u.id AND esp.enrollment_status <> 'enrolled')
                AND (u.name LIKE ? OR sp.student_code LIKE ?)
                LIMIT 20");
            $stmt->execute(["%$q%", "%$q%"]);
        }
        echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    echo json_encode(['success' => false]);
    exit();
}

// صلة القرابة
$siblingRelLabels = [
    'brother' => 'أخ شقيق',
    'sister' => 'أخت شقيقة',
    'half_brother' => 'أخ غير شقيق (من الأب)',
    'half_sister' => 'أخت غير شقيقة (من الأب)',
    'step_brother' => 'أخ من الأم',
    'step_sister' => 'أخت من الأم'
];

// علاقات من الأب
$fatherRels = ['brother', 'sister', 'half_brother', 'half_sister'];
// علاقات من الأم فقط. العلاقة العامة brother/sister لا تثبت جهة الأم،
// وإدراجها هنا كان يكرر كل مجموعات الأشقاء العادية في تبويب الأم.
$motherRels = ['step_brother', 'step_sister'];

// PRG pattern
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Tab persistence
$activeTab = $_GET['tab'] ?? 'father';
$validTabs = ['father', 'mother', 'links'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'father';

// ===== معالجة POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    // إلغاء ربط شقيق
    if (isset($_POST['unlink_sibling'])) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $siblingId = (int)($_POST['sibling_id'] ?? 0);
        $postTab = $_POST['active_tab'] ?? $activeTab;
        try {
            $studentRelationshipService->unlinkSibling($studentId, $siblingId);
            $_SESSION['success_message'] = "تم إلغاء ربط الشقيق بنجاح.";
        } catch (Throwable $e) {
            $_SESSION['error_message'] = siblings_public_error_message($e);
        }
        header("Location: siblings.php?tab=" . urlencode($postTab));
        exit;
    }
    // ربط شقيق جديد
    if (isset($_POST['link_sibling'])) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $siblingId = (int)($_POST['sibling_id'] ?? 0);
        $rel = $_POST['sibling_relationship'] ?? 'brother';
        $postTab = $_POST['active_tab'] ?? $activeTab;
        try {
            $_SESSION['success_message'] = $studentRelationshipService->link($studentId, $siblingId, (string) $rel);
        } catch (Throwable $e) {
            $_SESSION['error_message'] = siblings_public_error_message($e);
        }
        header("Location: siblings.php?tab=" . urlencode($postTab));
        exit;
    }

    // ربط طالب بقريب
    if (isset($_POST['action']) && $_POST['action'] === 'link_kinship') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $relative_id = (int)($_POST['relative_id'] ?? 0);
        $kinship_type_id = (int)($_POST['kinship_type_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($student_id <= 0 || $relative_id <= 0 || $kinship_type_id <= 0) {
            $_SESSION['error_message'] = "يجب اختيار الطالب والقريب وصلة القرابة";
        } elseif ($student_id === $relative_id) {
            $_SESSION['error_message'] = "لا يمكن ربط الطالب بنفسه";
        } else {
            try {
                $studentRelationshipService->linkKinshipByType(
                    $student_id,
                    $relative_id,
                    $kinship_type_id,
                    $notes
                );
                $_SESSION['success_message'] = "تم ربط صلة القرابة بنجاح";
            } catch (Throwable $e) {
                $_SESSION['error_message'] = siblings_public_error_message($e);
            }
        }
        header("Location: siblings.php?tab=links");
        exit();
    }

    // إلغاء ربط قرابة
    if (isset($_POST['action']) && $_POST['action'] === 'unlink_kinship') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $relative_id = (int)($_POST['relative_id'] ?? 0);
        try {
            $studentRelationshipService->unlinkKinship($student_id, $relative_id);
            $_SESSION['success_message'] = "تم إلغاء صلة القرابة بنجاح";
        } catch (Throwable $e) {
            $_SESSION['error_message'] = siblings_public_error_message($e);
        }
        header("Location: siblings.php?tab=links");
        exit();
    }
}

// ===== جلب جميع مجموعات الأشقاء =====

// جلب كل العلاقات
$stmt = $db->query("
    SELECT ss.student_id, ss.sibling_id, ss.relationship,
           u1.name AS student_name, u1.status AS student_status,
           sp1.student_code AS student_code,
           CONCAT_WS(' ', NULLIF(TRIM(sp1.second_name_ar), ''), NULLIF(TRIM(sp1.third_name_ar), ''), NULLIF(TRIM(sp1.fourth_name_ar), ''), NULLIF(TRIM(sp1.family_name_ar), '')) AS student_father,
           c1.name AS student_class,
           u2.name AS sibling_name, u2.status AS sibling_status,
           sp2.student_code AS sibling_code,
           CONCAT_WS(' ', NULLIF(TRIM(sp2.second_name_ar), ''), NULLIF(TRIM(sp2.third_name_ar), ''), NULLIF(TRIM(sp2.fourth_name_ar), ''), NULLIF(TRIM(sp2.family_name_ar), '')) AS sibling_father,
           c2.name AS sibling_class
    FROM student_siblings ss
    JOIN users u1 ON ss.student_id = u1.id AND u1.role = 'student'
    JOIN users u2 ON ss.sibling_id = u2.id AND u2.role = 'student'
    LEFT JOIN student_profiles sp1 ON u1.id = sp1.user_id
    LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id
    LEFT JOIN classes c1 ON u1.class_id = c1.id
    LEFT JOIN classes c2 ON u2.class_id = c2.id
    WHERE u1.deleted_at IS NULL AND u2.deleted_at IS NULL
    ORDER BY u1.name, u2.name
");
$allLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تجميع في عائلات (مجموعات أشقاء)
$visited = [];
$families = [];
$adjacency = []; // student_id => [sibling_ids]

foreach ($allLinks as $link) {
    $adjacency[$link['student_id']][] = $link['sibling_id'];
}

// BFS لإيجاد المجموعات المتصلة
$studentInfoMap = [];
foreach ($allLinks as $link) {
    $studentInfoMap[$link['student_id']] = [
        'name' => $link['student_name'],
        'code' => $link['student_code'],
        'class' => $link['student_class'],
        'father' => $link['student_father'],
        'status' => $link['student_status'],
        'id' => $link['student_id']
    ];
    $studentInfoMap[$link['sibling_id']] = [
        'name' => $link['sibling_name'],
        'code' => $link['sibling_code'],
        'class' => $link['sibling_class'],
        'father' => $link['sibling_father'],
        'status' => $link['sibling_status'],
        'id' => $link['sibling_id']
    ];
}

// بناء خريطة العلاقات بين الطلاب
$relMap = [];
foreach ($allLinks as $link) {
    $relMap[$link['student_id']][$link['sibling_id']] = $link['relationship'];
}

// تجميع أشقاء من الأب (brother, sister, half_brother, half_sister)
$fatherAdjacency = [];
foreach ($allLinks as $link) {
    if (in_array($link['relationship'], $fatherRels)) {
        $fatherAdjacency[$link['student_id']][] = $link['sibling_id'];
    }
}

// تجميع العلاقات المسجلة صراحة كأشقاء من الأم
$motherAdjacency = [];
foreach ($allLinks as $link) {
    if (in_array($link['relationship'], $motherRels)) {
        $motherAdjacency[$link['student_id']][] = $link['sibling_id'];
    }
}

// BFS لتجميع مجموعات
function buildFamilies($adjacencyList, $studentInfoMap) {
    $visited = [];
    $families = [];
    foreach (array_keys($studentInfoMap) as $sid) {
        if (isset($visited[$sid])) continue;
        if (!isset($adjacencyList[$sid])) continue;
        $queue = [$sid];
        $family = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            $family[] = $current;
            if (isset($adjacencyList[$current])) {
                foreach ($adjacencyList[$current] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $queue[] = $neighbor;
                    }
                }
            }
        }
        if (count($family) > 1) {
            $families[] = $family;
        }
    }
    return $families;
}

$fatherFamilies = buildFamilies($fatherAdjacency, $studentInfoMap);
$motherFamilies = buildFamilies($motherAdjacency, $studentInfoMap);

// إحصائيات
$totalFatherFamilies = count($fatherFamilies);
$totalMotherFamilies = count($motherFamilies);

$fatherLinked = [];
foreach ($fatherFamilies as $f) { foreach ($f as $s) $fatherLinked[$s] = true; }
$motherLinked = [];
foreach ($motherFamilies as $f) { foreach ($f as $s) $motherLinked[$s] = true; }

$totalFatherLinked = count($fatherLinked);
$totalMotherLinked = count($motherLinked);

// عدد الروابط من الأب
$fatherLinks = 0;
foreach ($allLinks as $link) {
    if (in_array($link['relationship'], $fatherRels)) $fatherLinks++;
}
$fatherLinks = $fatherLinks / 2;

// عدد الروابط من الأم
$motherLinks = 0;
foreach ($allLinks as $link) {
    if (in_array($link['relationship'], $motherRels)) $motherLinks++;
}
$motherLinks = $motherLinks / 2;

// فلاتر
$filter_search = trim($_GET['search'] ?? '');

// تصفية
function filterFamilies($families, $studentInfoMap, $filter_search) {
    if (empty($filter_search)) return $families;
    return array_filter($families, function($family) use ($studentInfoMap, $filter_search) {
        foreach ($family as $sid) {
            $info = $studentInfoMap[$sid] ?? [];
            if (stripos($info['name'] ?? '', $filter_search) !== false ||
                stripos($info['code'] ?? '', $filter_search) !== false ||
                stripos($info['father'] ?? '', $filter_search) !== false) {
                return true;
            }
        }
        return false;
    });
}

$fatherFamilies = filterFamilies($fatherFamilies, $studentInfoMap, $filter_search);
$motherFamilies = filterFamilies($motherFamilies, $studentInfoMap, $filter_search);

// دالة مساعدة لإنشاء روابط الترقيم مع الحفاظ على الفلاتر والتبويب النشط
function getSiblingPaginationUrl($pageParamName, $pageNumber) {
    $params = $_GET;
    $params[$pageParamName] = $pageNumber;
    // للتأكد من تحديد التبويب الحالي إذا لم يكن بالـ URL
    if (!isset($params['tab'])) {
        global $activeTab;
        $params['tab'] = $activeTab;
    }
    return 'siblings.php?' . http_build_query($params);
}

// إعدادات الترقيم
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 15; // عدد المجموعات في الصفحة الواحدة

$fatherFamiliesFilteredCount = count($fatherFamilies);
$fatherPage = isset($_GET['f_page']) ? max(1, (int)$_GET['f_page']) : 1;
$totalFatherPages = max(1, ceil($fatherFamiliesFilteredCount / $limit));
if ($fatherPage > $totalFatherPages) $fatherPage = $totalFatherPages;
$fatherOffset = ($fatherPage - 1) * $limit;
$paginatedFatherFamilies = array_slice($fatherFamilies, $fatherOffset, $limit);

$motherFamiliesFilteredCount = count($motherFamilies);
$motherPage = isset($_GET['m_page']) ? max(1, (int)$_GET['m_page']) : 1;
$totalMotherPages = max(1, ceil($motherFamiliesFilteredCount / $limit));
if ($motherPage > $totalMotherPages) $motherPage = $totalMotherPages;
$motherOffset = ($motherPage - 1) * $limit;
$paginatedMotherFamilies = array_slice($motherFamilies, $motherOffset, $limit);

// دالة مساعدة لرسم أزرار التنقل (Pagination UI)
function renderSiblingPagination($currentPage, $totalPages, $paramName) {
    if ($totalPages <= 1) return '';

    $html = '<nav aria-label="Page navigation" class="mt-4 no-print"><ul class="pagination justify-content-center pagination-sm m-0">';

    // السابق
    $disabledClass = ($currentPage <= 1) ? 'disabled' : '';
    $prevUrl = getSiblingPaginationUrl($paramName, $currentPage - 1);
    $html .= '<li class="page-item ' . $disabledClass . '"><a class="page-link" href="' . $prevUrl . '"><i class="fas fa-chevron-right me-1"></i>السابق</a></li>';

    // الصفحة الأولى
    if ($currentPage > 3) {
        $firstUrl = getSiblingPaginationUrl($paramName, 1);
        $html .= '<li class="page-item"><a class="page-link" href="' . $firstUrl . '">1</a></li>';
        if ($currentPage > 4) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    // الصفحات المحيطة بالصفحة الحالية
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    for ($i = $start; $i <= $end; $i++) {
        $activeClass = ($i == $currentPage) ? 'active' : '';
        $pageUrl = getSiblingPaginationUrl($paramName, $i);
        $html .= '<li class="page-item ' . $activeClass . '"><a class="page-link" href="' . $pageUrl . '">' . $i . '</a></li>';
    }

    // الصفحة الأخيرة
    if ($currentPage < $totalPages - 2) {
        if ($currentPage < $totalPages - 3) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $lastUrl = getSiblingPaginationUrl($paramName, $totalPages);
        $html .= '<li class="page-item"><a class="page-link" href="' . $lastUrl . '">' . $totalPages . '</a></li>';
    }

    // التالي
    $disabledClass = ($currentPage >= $totalPages) ? 'disabled' : '';
    $nextUrl = getSiblingPaginationUrl($paramName, $currentPage + 1);
    $html .= '<li class="page-item ' . $disabledClass . '"><a class="page-link" href="' . $nextUrl . '">التالي<i class="fas fa-chevron-left ms-1"></i></a></li>';

    $html .= '</ul></nav>';
    return $html;
}

// جلب أنواع صلات القرابة والروابط
$kinshipTypes = $db->query("SELECT kt.* FROM kinship_types kt WHERE kt.status = 'active' ORDER BY kt.name")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($filter_search)) {
    $searchWildcard = "%$filter_search%";
    $stmtKinship = $db->prepare("
        SELECT sk.id, sk.student_id, sk.relative_id, sk.notes,
               kt.name as kinship_name,
               u1.name as student_name, sp1.student_code as student_code, c1.name as student_class,
               u2.name as relative_name, sp2.student_code as relative_code, c2.name as relative_class
        FROM student_kinships sk
        JOIN kinship_types kt ON sk.kinship_type_id = kt.id
        JOIN users u1 ON sk.student_id = u1.id
        JOIN users u2 ON sk.relative_id = u2.id
        LEFT JOIN student_profiles sp1 ON u1.id = sp1.user_id
        LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id
        LEFT JOIN classes c1 ON u1.class_id = c1.id
        LEFT JOIN classes c2 ON u2.class_id = c2.id
        WHERE sk.student_id < sk.relative_id
          AND u1.role = 'student' AND u2.role = 'student'
          AND u1.deleted_at IS NULL AND u2.deleted_at IS NULL
          AND (u1.name LIKE ? OR sp1.student_code LIKE ? OR u2.name LIKE ? OR sp2.student_code LIKE ? OR kt.name LIKE ?)
        ORDER BY u1.name, u2.name
    ");
    $stmtKinship->execute([$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
    $kinshipLinks = $stmtKinship->fetchAll(PDO::FETCH_ASSOC);
} else {
    $kinshipLinks = $db->query("
        SELECT sk.id, sk.student_id, sk.relative_id, sk.notes,
               kt.name as kinship_name,
               u1.name as student_name, sp1.student_code as student_code, c1.name as student_class,
               u2.name as relative_name, sp2.student_code as relative_code, c2.name as relative_class
        FROM student_kinships sk
        JOIN kinship_types kt ON sk.kinship_type_id = kt.id
        JOIN users u1 ON sk.student_id = u1.id
        JOIN users u2 ON sk.relative_id = u2.id
        LEFT JOIN student_profiles sp1 ON u1.id = sp1.user_id
        LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id
        LEFT JOIN classes c1 ON u1.class_id = c1.id
        LEFT JOIN classes c2 ON u2.class_id = c2.id
        WHERE sk.student_id < sk.relative_id
          AND u1.role = 'student' AND u2.role = 'student'
          AND u1.deleted_at IS NULL AND u2.deleted_at IS NULL
        ORDER BY u1.name, u2.name
    ")->fetchAll(PDO::FETCH_ASSOC);
}
$totalKinshipLinks = count($kinshipLinks);

// =============== EXCEL EXPORT ===============
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    require_once '../classes/excel_handler.php';
    $excel_handler = new ExcelHandler();
    
    $exportTab = $_GET['tab'] ?? 'father';
    $excelData = [];
    $filename = '';
    
    if ($exportTab === 'father' || $exportTab === 'mother') {
        $exportFamilies = ($exportTab === 'father') ? $fatherFamilies : $motherFamilies;
        $exportRelsList = ($exportTab === 'father') ? $fatherRels : $motherRels;
        
        $filename = ($exportTab === 'father') ? 'تقرير_الاشقاء_من_الأب' : 'تقرير_الاشقاء_من_الأم';
        $excelData[] = [($exportTab === 'father' ? 'تقرير الأشقاء من الأب' : 'تقرير الأشقاء من الأم')];
        if ($filter_search !== '') {
            $excelData[] = ['تصفية البحث:', $filter_search];
        }
        $excelData[] = [];
        
        $fIndex = 1;
        foreach ($exportFamilies as $family) {
            $firstInfo = $studentInfoMap[$family[0]] ?? [];
            $familyFather = $firstInfo['father'] ?? '';
            $excelData[] = ['مجموعة أشقاء #' . $fIndex++ . ($familyFather ? ' — أبناء: ' . $familyFather : '')];
            
            if ($exportTab === 'father') {
                $excelData[] = ['#', 'الاسم', 'الكود', 'الفصل', 'اسم الأب', 'الحالة', 'العلاقة'];
            } else {
                $excelData[] = ['#', 'الاسم', 'الكود', 'الفصل', 'الحالة', 'العلاقة'];
            }
            
            foreach ($family as $idx => $sid) {
                $info = $studentInfoMap[$sid] ?? [];
                $statusText = ($info['status'] ?? '') === 'active' ? 'نشط' : ($info['status'] ?? '-');
                
                $rels = [];
                foreach ($family as $otherSid) {
                    if ($otherSid === $sid) continue;
                    $rel = $relMap[$sid][$otherSid] ?? '';
                    if ($rel && in_array($rel, $exportRelsList)) {
                        $otherInfo = $studentInfoMap[$otherSid] ?? [];
                        $otherFirstName = explode(' ', $otherInfo['name'] ?? '')[0];
                        $rels[] = ($siblingRelLabels[$rel] ?? $rel) . ' ' . $otherFirstName;
                    }
                }
                $relsText = implode(', ', $rels);
                
                if ($exportTab === 'father') {
                    $excelData[] = [
                        $idx + 1,
                        $info['name'] ?? '-',
                        $info['code'] ?? '-',
                        $info['class'] ?? '-',
                        $info['father'] ?? '-',
                        $statusText,
                        $relsText
                    ];
                } else {
                    $excelData[] = [
                        $idx + 1,
                        $info['name'] ?? '-',
                        $info['code'] ?? '-',
                        $info['class'] ?? '-',
                        $statusText,
                        $relsText
                    ];
                }
            }
            $excelData[] = [];
        }
    } elseif ($exportTab === 'links') {
        $filename = 'تقرير_روابط_القرابة_الأخرى';
        $excelData[] = ['تقرير روابط القرابة الأخرى بين الطلاب'];
        if ($filter_search !== '') {
            $excelData[] = ['تصفية البحث:', $filter_search];
        }
        $excelData[] = [];
        $excelData[] = ['#', 'الطالب الأول', 'كود الطالب الأول', 'فصل الطالب الأول', 'صلة القرابة', 'الطالب الثاني (القريب)', 'كود الطالب الثاني', 'فصل الطالب الثاني', 'ملاحظات'];
        
        foreach ($kinshipLinks as $idx => $kl) {
            $excelData[] = [
                $idx + 1,
                $kl['student_name'],
                $kl['student_code'] ?? '-',
                $kl['student_class'] ?? '-',
                $kl['kinship_name'],
                $kl['relative_name'],
                $kl['relative_code'] ?? '-',
                $kl['relative_class'] ?? '-',
                $kl['notes'] ?? '-'
            ];
        }
    }
    
    if (!empty($excelData)) {
        $filepath = $excel_handler->exportToExcel($excelData, $filename);
        if ($filepath && file_exists($filepath)) {
            if (ob_get_level() > 0) ob_clean();
            $ext = pathinfo($filepath, PATHINFO_EXTENSION);
            if ($ext === 'xlsx') {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xlsx"');
            } else {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
            }
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate');
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }
}

require_once '../includes/admin_header.php';
?>

<style>
/* تصغير ارتفاع كروت الإحصائيات لتناسب تقسيم 5 أعمدة وتماثل كروت الإحصائيات المدرسية */
.family-card{border:1px solid #dee2e6;border-radius:12px;margin-bottom:1rem;overflow:hidden;transition:box-shadow .2s}
.family-card:hover{box-shadow:0 4px 15px rgba(0,0,0,.08)}
.family-card-header{padding:.75rem 1rem;font-weight:600;display:flex;align-items:center;justify-content:space-between}
.family-card-body{padding:0}
.family-card-body table{margin-bottom:0}

/* تنسيق موحد لعرض الأعمدة في جداول الإخوة لمنع التفاوت البصري */
.table-father-family, .table-mother-family {
    table-layout: fixed;
    width: 100%;
}
.table-father-family th, .table-father-family td,
.table-mother-family th, .table-mother-family td {
    vertical-align: middle;
}
/* أعمدة جدول الأب */
.table-father-family th:nth-child(1) { width: 5%; }
.table-father-family th:nth-child(2) { width: 25%; }
.table-father-family th:nth-child(3) { width: 12%; }
.table-father-family th:nth-child(4) { width: 10%; }
.table-father-family th:nth-child(5) { width: 15%; }
.table-father-family th:nth-child(6) { width: 8%; }
.table-father-family th:nth-child(7) { width: 15%; }
.table-father-family th:nth-child(8) { width: 10%; min-width: 100px; }

/* أعمدة جدول الأم */
.table-mother-family th:nth-child(1) { width: 5%; }
.table-mother-family th:nth-child(2) { width: 30%; }
.table-mother-family th:nth-child(3) { width: 12%; }
.table-mother-family th:nth-child(4) { width: 10%; }
.table-mother-family th:nth-child(5) { width: 8%; }
.table-mother-family th:nth-child(6) { width: 25%; }
.table-mother-family th:nth-child(7) { width: 10%; min-width: 100px; }

/* إضافة حشوة جانبية للعمود الأول والأخير ليتناسقا مع حواف الهيدر ولا يلتصقا بحافة البطاقة */
.table-father-family th:first-child, .table-father-family td:first-child,
.table-mother-family th:first-child, .table-mother-family td:first-child {
    padding-right: 1.25rem !important;
}
.table-father-family th:last-child, .table-father-family td:last-child,
.table-mother-family th:last-child, .table-mother-family td:last-child {
    padding-left: 1.25rem !important;
}
</style>

<!-- عنوان الصفحة -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-friends me-2 text-primary"></i>إدارة الإخوة والأشقاء وصلات القرابة</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#linkSiblingModal" onclick="resetSiblingLinkForm()">
            <i class="fas fa-plus-circle"></i>ربط شقيق جديد
        </button>
        <button type="button" class="btn btn-header-premium btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#linkModal" onclick="resetLinkForm()">
            <i class="fas fa-plus-circle"></i>ربط قرابة جديدة
        </button>
        <a href="relationship_discovery.php" class="btn btn-header-premium btn-warning-soft">
            <i class="fas fa-search-plus"></i>اكتشاف مجموعات القرابة
        </a>
        <a id="exportExcelBtn" href="siblings.php?export=excel&tab=<?php echo urlencode($activeTab); ?>&search=<?php echo urlencode($filter_search); ?>&limit=<?php echo $limit; ?>" class="btn btn-header-premium btn-export-soft">
            <i class="fas fa-file-excel"></i>تصدير Excel
        </a>
        <button type="button" onclick="window.print()" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print"></i>طباعة
        </button>
    </div>
</div>

<!-- رسائل -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- بطاقات الإحصائيات -->
<div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4 no-print">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-male"></i></div>
            <div class="stat-card-badge"><?php echo $totalFatherLinked; ?> طالب</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalFatherFamilies; ?>">0</div>
                <div class="stat-card-label">أشقاء من الأب</div>
                <div class="stat-card-sub"><i class="fas fa-users"></i> مجموعات الأب</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #db2777);">
            <div class="stat-card-icon"><i class="fas fa-female"></i></div>
            <div class="stat-card-badge"><?php echo $totalMotherLinked; ?> طالب</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalMotherFamilies; ?>">0</div>
                <div class="stat-card-label">أشقاء من الأم</div>
                <div class="stat-card-sub"><i class="fas fa-users"></i> مجموعات الأم</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-user-friends"></i></div>
            <div class="stat-card-badge">صلات نشطة</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalFatherLinked + $totalMotherLinked; ?>">0</div>
                <div class="stat-card-label">إجمالي الطلاب المرتبطين</div>
                <div class="stat-card-sub"><i class="fas fa-user-check"></i> طلاب لديهم إخوة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-link"></i></div>
            <div class="stat-card-badge"><?php echo (int)$fatherLinks; ?> أب | <?php echo (int)$motherLinks; ?> أم</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($fatherLinks + $motherLinks); ?>">0</div>
                <div class="stat-card-label">روابط الإخوة</div>
                <div class="stat-card-sub"><i class="fas fa-project-diagram"></i> روابط ثنائية</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #a855f7, #8b5cf6);">
            <div class="stat-card-icon"><i class="fas fa-users-cog"></i></div>
            <div class="stat-card-badge">قرابات أخرى</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalKinshipLinks; ?>">0</div>
                <div class="stat-card-label">صلات القرابة الأخرى</div>
                <div class="stat-card-sub"><i class="fas fa-network-wired"></i> قرابات عائلية</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3 border-bottom admin-tabs no-print" id="siblingTabs">
    <li class="nav-item">
        <a class="nav-link fw-semibold <?php echo $activeTab === 'father' ? 'active' : ''; ?>" href="#pane-father" data-bs-toggle="tab">
            <i class="fas fa-male me-2"></i>أشقاء من الأب
            <span class="badge ms-1" style="background-color: rgba(37, 99, 235, 0.1) !important; color: #2563eb !important;"><?php echo $totalFatherFamilies; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-semibold <?php echo $activeTab === 'mother' ? 'active' : ''; ?>" href="#pane-mother" data-bs-toggle="tab">
            <i class="fas fa-female me-2"></i>أشقاء من الأم
            <span class="badge ms-1" style="background-color: rgba(239, 68, 68, 0.1) !important; color: #dc2626 !important;"><?php echo $totalMotherFamilies; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-semibold <?php echo $activeTab === 'links' ? 'active' : ''; ?>" href="#pane-links" data-bs-toggle="tab">
            <i class="fas fa-link me-2"></i>روابط القرابة الأخرى
            <span class="badge ms-1" style="background-color: rgba(139, 92, 246, 0.15) !important; color: #8b5cf6 !important;"><?php echo $totalKinshipLinks; ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- ====== تبويب أشقاء من الأب ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'father' ? 'show active' : ''; ?>" id="pane-father">
        <div class="admin-list-surface">
            <form method="GET" class="mb-0 no-print">
                <input type="hidden" name="tab" value="father">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 px-3 pt-3">
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($filter_search)): ?>
                        <a href="siblings.php?tab=father&limit=<?php echo $limit; ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                        <?php endif; ?>
                        <div class="dataTables_filter">
                            <label>
                                بحث:
                                <input type="search" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="الاسم، الكود، اسم الأب..." style="width: 250px; display: inline-block;">
                            </label>
                        </div>
                    </div>
                    <div class="dataTables_length">
                        <label>
                            عرض 
                            <select name="limit" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; display: inline-block;">
                                <option value="10" <?php echo $limit === 10 ? 'selected' : ''; ?>>10</option>
                                <option value="15" <?php echo $limit === 15 ? 'selected' : ''; ?>>15</option>
                                <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                            سجلات
                        </label>
                    </div>
                </div>
            </form>
            <?php if (empty($paginatedFatherFamilies)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-user-friends text-muted" style="font-size:4rem;"></i>
                    <p class="text-muted mt-3 mb-0">لا توجد مجموعات أشقاء من الأب.</p>
                    <p class="text-muted small">يمكنك ربط الأشقاء من صفحة بيانات الطالب ← تبويب الإخوه والأشقاء</p>
                </div>
            <?php else: ?>
                <?php foreach ($paginatedFatherFamilies as $fi => $family): ?>
                    <?php
                        $firstInfo = $studentInfoMap[$family[0]] ?? [];
                        $familyFather = $firstInfo['father'] ?? '';
                        $familySize = count($family);
                    ?>
                    <div class="family-card">
                        <div class="family-card-header bg-light">
                            <div>
                                <i class="fas fa-users text-primary me-2"></i>
                                مجموعة #<?php echo $fatherOffset + $fi + 1; ?>
                                <?php if ($familyFather): ?>
                                    — <span class="text-primary">أبناء: <?php echo htmlspecialchars($familyFather); ?></span>
                                <?php endif; ?>
                                <span class="badge bg-primary ms-2"><?php echo $familySize; ?> طالب</span>
                            </div>
                        </div>
                        <div class="family-card-body">
                            <table class="table table-hover mb-0 table-father-family admin-data-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>الاسم</th>
                                        <th>الكود</th>
                                        <th>الفصل</th>
                                        <th>اسم الأب</th>
                                        <th>الحالة</th>
                                        <th>العلاقة</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($family as $idx => $sid):
                                    $info = $studentInfoMap[$sid] ?? [];
                                    $statusBadge = ($info['status'] ?? '') === 'active'
                                        ? '<span class="badge bg-success">نشط</span>'
                                        : '<span class="badge bg-secondary">' . htmlspecialchars($info['status'] ?? '-') . '</span>';

                                    $rels = [];
                                    foreach ($family as $otherSid) {
                                        if ($otherSid === $sid) continue;
                                        $rel = $relMap[$sid][$otherSid] ?? '';
                                        if ($rel && in_array($rel, $fatherRels)) {
                                            $otherInfo = $studentInfoMap[$otherSid] ?? [];
                                            $otherFirstName = explode(' ', $otherInfo['name'] ?? '')[0];
                                            $rels[] = ($siblingRelLabels[$rel] ?? $rel) . ' ' . htmlspecialchars($otherFirstName);
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td>
                                            <a href="students.php?action=edit&id=<?php echo $sid; ?>&tab=siblings" class="text-decoration-none fw-bold">
                                                <?php echo htmlspecialchars($info['name'] ?? '-'); ?>
                                            </a>
                                        </td>
                                        <td dir="ltr"><?php echo htmlspecialchars($info['code'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($info['class'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($info['father'] ?? '-'); ?></td>
                                        <td><?php echo $statusBadge; ?></td>
                                        <td>
                                            <?php foreach ($rels as $r): ?>
                                                <span class="badge bg-light text-dark border mb-1"><?php echo $r; ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="actions-column">
                                            <a href="students.php?action=edit&id=<?php echo $sid; ?>&tab=siblings" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="فتح بيانات الطالب">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php
                                            foreach ($family as $otherSid):
                                                if ($otherSid === $sid) continue;
                                                $otherInfo = $studentInfoMap[$otherSid] ?? [];
                                                $otherFirstName = explode(' ', $otherInfo['name'] ?? '')[0];
                                            ?>
                                            <form method="POST" class="d-inline">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                <input type="hidden" name="sibling_id" value="<?php echo $otherSid; ?>">
                                                <input type="hidden" name="active_tab" value="father">
                                                <button type="button" class="btn btn-action-pills btn-delete btn-unlink-sibling me-1"
                                                        data-student-name="<?php echo htmlspecialchars($info['name'] ?? ''); ?>"
                                                        data-sibling-name="<?php echo htmlspecialchars($otherInfo['name'] ?? ''); ?>"
                                                        data-bs-toggle="tooltip" title="إلغاء ربط مع <?php echo htmlspecialchars($otherFirstName); ?>">
                                                    <i class="fas fa-unlink"></i>
                                                </button>
                                            </form>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php echo renderSiblingPagination($fatherPage, $totalFatherPages, 'f_page'); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== تبويب أشقاء من الأم ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'mother' ? 'show active' : ''; ?>" id="pane-mother">
        <div class="admin-list-surface">
            <form method="GET" class="mb-0 no-print">
                <input type="hidden" name="tab" value="mother">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 px-3 pt-3">
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($filter_search)): ?>
                        <a href="siblings.php?tab=mother&limit=<?php echo $limit; ?>" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                        <?php endif; ?>
                        <div class="dataTables_filter">
                            <label>
                                بحث:
                                <input type="search" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="الاسم، الكود، اسم الأب..." style="width: 250px; display: inline-block;">
                            </label>
                        </div>
                    </div>
                    <div class="dataTables_length">
                        <label>
                            عرض 
                            <select name="limit" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; display: inline-block;">
                                <option value="10" <?php echo $limit === 10 ? 'selected' : ''; ?>>10</option>
                                <option value="15" <?php echo $limit === 15 ? 'selected' : ''; ?>>15</option>
                                <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                            سجلات
                        </label>
                    </div>
                </div>
            </form>
            <?php if (empty($paginatedMotherFamilies)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-user-friends text-muted" style="font-size:4rem;"></i>
                    <p class="text-muted mt-3 mb-0">لا توجد مجموعات أشقاء من الأم.</p>
                    <p class="text-muted small">يمكنك ربط الأشقاء من صفحة بيانات الطالب ← تبويب الإخوه والأشقاء</p>
                </div>
            <?php else: ?>
                <?php foreach ($paginatedMotherFamilies as $fi => $family): ?>
                    <?php
                        $firstInfo = $studentInfoMap[$family[0]] ?? [];
                        $familySize = count($family);
                    ?>
                    <div class="family-card">
                        <div class="family-card-header bg-light">
                            <div>
                                <i class="fas fa-users text-danger me-2"></i>
                                مجموعة #<?php echo $motherOffset + $fi + 1; ?>
                                <span class="badge bg-danger ms-2"><?php echo $familySize; ?> طالب</span>
                            </div>
                        </div>
                        <div class="family-card-body">
                            <table class="table table-hover mb-0 table-mother-family admin-data-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>الاسم</th>
                                        <th>الكود</th>
                                        <th>الفصل</th>
                                        <th>الحالة</th>
                                        <th>العلاقة</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($family as $idx => $sid):
                                    $info = $studentInfoMap[$sid] ?? [];
                                    $statusBadge = ($info['status'] ?? '') === 'active'
                                        ? '<span class="badge bg-success">نشط</span>'
                                        : '<span class="badge bg-secondary">' . htmlspecialchars($info['status'] ?? '-') . '</span>';

                                    $rels = [];
                                    foreach ($family as $otherSid) {
                                        if ($otherSid === $sid) continue;
                                        $rel = $relMap[$sid][$otherSid] ?? '';
                                        if ($rel && in_array($rel, $motherRels)) {
                                            $otherInfo = $studentInfoMap[$otherSid] ?? [];
                                            $otherFirstName = explode(' ', $otherInfo['name'] ?? '')[0];
                                            $rels[] = ($siblingRelLabels[$rel] ?? $rel) . ' ' . htmlspecialchars($otherFirstName);
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td>
                                            <a href="students.php?action=edit&id=<?php echo $sid; ?>&tab=siblings" class="text-decoration-none fw-bold">
                                                <?php echo htmlspecialchars($info['name'] ?? '-'); ?>
                                            </a>
                                        </td>
                                        <td dir="ltr"><?php echo htmlspecialchars($info['code'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($info['class'] ?? '-'); ?></td>
                                        <td><?php echo $statusBadge; ?></td>
                                        <td>
                                            <?php foreach ($rels as $r): ?>
                                                <span class="badge bg-light text-dark border mb-1"><?php echo $r; ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="actions-column">
                                            <a href="students.php?action=edit&id=<?php echo $sid; ?>&tab=siblings" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="فتح بيانات الطالب">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php
                                            foreach ($family as $otherSid):
                                                if ($otherSid === $sid) continue;
                                                $otherInfo = $studentInfoMap[$otherSid] ?? [];
                                                $otherFirstName = explode(' ', $otherInfo['name'] ?? '')[0];
                                            ?>
                                            <form method="POST" class="d-inline">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                <input type="hidden" name="sibling_id" value="<?php echo $otherSid; ?>">
                                                <input type="hidden" name="active_tab" value="mother">
                                                <button type="button" class="btn btn-action-pills btn-delete btn-unlink-sibling me-1"
                                                        data-student-name="<?php echo htmlspecialchars($info['name'] ?? ''); ?>"
                                                        data-sibling-name="<?php echo htmlspecialchars($otherInfo['name'] ?? ''); ?>"
                                                        data-bs-toggle="tooltip" title="إلغاء ربط مع <?php echo htmlspecialchars($otherFirstName); ?>">
                                                    <i class="fas fa-unlink"></i>
                                                </button>
                                            </form>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php echo renderSiblingPagination($motherPage, $totalMotherPages, 'm_page'); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== تبويب روابط القرابة الأخرى ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'links' ? 'show active' : ''; ?>" id="pane-links">
        <div class="admin-list-surface">
            <?php if (empty($kinshipLinks)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-link fa-3x mb-3 opacity-50"></i>
                    <p>لا توجد روابط قرابة بين الطلاب</p>
                </div>
            <?php else: ?>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped admin-data-table" id="linksTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الطالب الأول</th>
                            <th>الفصل</th>
                            <th>صلة القرابة</th>
                            <th>الطالب الثاني</th>
                            <th>الفصل</th>
                            <th>ملاحظات</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kinshipLinks as $idx => $kl): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($kl['student_name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($kl['student_code'] ?? ''); ?></small></td>
                            <td><?php echo htmlspecialchars($kl['student_class'] ?? '-'); ?></td>
                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($kl['kinship_name']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($kl['relative_name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($kl['relative_code'] ?? ''); ?></small></td>
                            <td><?php echo htmlspecialchars($kl['relative_class'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($kl['notes'] ?? '-'); ?></td>
                            <td class="actions-column">
                                <button class="btn btn-action-pills btn-delete me-1" data-bs-toggle="tooltip" title="إلغاء الربط" onclick="confirmUnlinkKinship(<?php echo $kl['student_id']; ?>, <?php echo $kl['relative_id']; ?>, '<?php echo htmlspecialchars($kl['student_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($kl['relative_name'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-unlink"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Link Kinship Modal -->
<div class="modal fade" id="linkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="link_kinship">
                <input type="hidden" name="student_id" id="linkStudentId">
                <input type="hidden" name="relative_id" id="linkRelativeId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-link me-2"></i>ربط قرابة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الطالب الأول <span class="text-danger">*</span></label>
                            <input type="text" id="searchStudent1" class="form-control" placeholder="ابحث بالاسم أو الكود..." autocomplete="off">
                            <div id="results1" class="list-group mt-1" style="max-height:200px;overflow-y:auto;"></div>
                            <div id="selected1" class="mt-2"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الطالب الثاني (القريب) <span class="text-danger">*</span></label>
                            <input type="text" id="searchStudent2" class="form-control" placeholder="ابحث بالاسم أو الكود..." autocomplete="off">
                            <div id="results2" class="list-group mt-1" style="max-height:200px;overflow-y:auto;"></div>
                            <div id="selected2" class="mt-2"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">صلة القرابة <span class="text-danger">*</span></label>
                            <select name="kinship_type_id" class="form-select" required>
                                <option value="">-- اختر --</option>
                                <?php foreach ($kinshipTypes as $kt): ?>
                                <option value="<?php echo $kt['id']; ?>"><?php echo htmlspecialchars($kt['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات</label>
                            <input type="text" name="notes" class="form-control" placeholder="ملاحظات اختيارية...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-link me-1"></i>ربط</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Link Sibling Modal -->
<div class="modal fade" id="linkSiblingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <input type="hidden" name="active_tab" id="siblingActiveTab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="student_id" id="linkSiblingStudentId">
                <input type="hidden" name="sibling_id" id="linkSiblingId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-friends me-2"></i>ربط شقيق جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الطالب الأول <span class="text-danger">*</span></label>
                            <input type="text" id="searchSibling1" class="form-control" placeholder="ابحث بالاسم أو الكود..." autocomplete="off">
                            <div id="siblingResults1" class="list-group mt-1" style="max-height:200px;overflow-y:auto;"></div>
                            <div id="siblingSelected1" class="mt-2"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الطالب الثاني (الشقيق) <span class="text-danger">*</span></label>
                            <input type="text" id="searchSibling2" class="form-control" placeholder="ابحث بالاسم أو الكود..." autocomplete="off">
                            <div id="siblingResults2" class="list-group mt-1" style="max-height:200px;overflow-y:auto;"></div>
                            <div id="siblingSelected2" class="mt-2"></div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">علاقة الأخوة <span class="text-danger">*</span></label>
                            <select name="sibling_relationship" class="form-select" required>
                                <option value="">-- اختر --</option>
                                <?php foreach ($siblingRelLabels as $rk => $rv): ?>
                                <option value="<?php echo $rk; ?>"><?php echo htmlspecialchars($rv); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="link_sibling" class="btn btn-success"><i class="fas fa-link me-1"></i>ربط</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Unlink Kinship Modal -->
<div class="modal fade" id="unlinkKinshipModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="unlink_kinship">
                <input type="hidden" name="student_id" id="unlinkStudentId2">
                <input type="hidden" name="relative_id" id="unlinkRelativeId2">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-unlink me-2"></i>إلغاء صلة القرابة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-unlink text-danger" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل أنت متأكد من إلغاء صلة القرابة بين:<br>
                        <span class="fw-bold text-primary" id="unlinkStudent"></span> و <span class="fw-bold text-primary" id="unlinkRelative"></span>؟
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-unlink me-1"></i>تأكيد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تأكيد إلغاء الربط -->
<div class="modal fade" id="unlinkSiblingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-unlink me-2"></i>إلغاء ربط شقيق</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-unlink text-danger" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من إلغاء ربط الأخوة بين:<br>
                    <span class="fw-bold text-primary" id="unlinkStudentName"></span> و <span class="fw-bold text-primary" id="unlinkSiblingName"></span>؟
                </p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>سيتم إلغاء الربط في كلا الاتجاهين.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmUnlinkBtn"><i class="fas fa-check me-1"></i>تأكيد إلغاء الربط</button>
            </div>
        </div>
    </div>
</div>

<script>
// مودال تأكيد إلغاء الربط
let currentUnlinkForm = null;
document.querySelectorAll('.btn-unlink-sibling').forEach(btn => {
    btn.addEventListener('click', function() {
        currentUnlinkForm = this.closest('form');
        document.getElementById('unlinkStudentName').textContent = this.dataset.studentName;
        document.getElementById('unlinkSiblingName').textContent = this.dataset.siblingName;
        new bootstrap.Modal(document.getElementById('unlinkSiblingModal')).show();
    });
});
document.getElementById('confirmUnlinkBtn')?.addEventListener('click', function() {
    if (currentUnlinkForm) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'unlink_sibling';
        input.value = '1';
        currentUnlinkForm.appendChild(input);
        currentUnlinkForm.submit();
    }
});

// Tab persistence
document.querySelectorAll('#siblingTabs a[data-bs-toggle="tab"]').forEach(function(tab) {
    tab.addEventListener('shown.bs.tab', function(e) {
        var tabName = e.target.getAttribute('href').replace('#pane-', '');
        var url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
        var input = document.getElementById('activeTabInput');
        if (input) input.value = tabName;
        var sInput = document.getElementById('siblingActiveTab');
        if (sInput) sInput.value = tabName;
        
        // تحديث رابط التصدير للتبويب الجديد ديناميكياً
        var exportBtn = document.getElementById('exportExcelBtn');
        if (exportBtn) {
            var exportUrl = new URL(exportBtn.href, window.location.origin + window.location.pathname);
            exportUrl.searchParams.set('tab', tabName);
            exportBtn.href = exportUrl.pathname + exportUrl.search;
        }
    });
});

// Link form functions
function resetLinkForm() {
    document.getElementById('linkStudentId').value = '';
    document.getElementById('linkRelativeId').value = '';
    document.getElementById('searchStudent1').value = '';
    document.getElementById('searchStudent2').value = '';
    document.getElementById('results1').innerHTML = '';
    document.getElementById('results2').innerHTML = '';
    document.getElementById('selected1').innerHTML = '';
    document.getElementById('selected2').innerHTML = '';
}

function resetSiblingLinkForm() {
    document.getElementById('linkSiblingStudentId').value = '';
    document.getElementById('linkSiblingId').value = '';
    document.getElementById('searchSibling1').value = '';
    document.getElementById('searchSibling2').value = '';
    document.getElementById('siblingResults1').innerHTML = '';
    document.getElementById('siblingResults2').innerHTML = '';
    document.getElementById('siblingSelected1').innerHTML = '';
    document.getElementById('siblingSelected2').innerHTML = '';
}

// AJAX student search for link
function setupStudentSearch(inputId, resultsId, selectedId, hiddenId) {
    var input = document.getElementById(inputId);
    var results = document.getElementById(resultsId);
    var selected = document.getElementById(selectedId);
    var hidden = document.getElementById(hiddenId);
    var timer;

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { results.innerHTML = ''; return; }
        timer = setTimeout(function() {
            $.ajax({
                url: 'siblings.php?ajax=search_students&q=' + encodeURIComponent(q),
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    results.innerHTML = '';
                    (data.results || []).forEach(function(s) {
                        var item = document.createElement('a');
                        item.href = '#';
                        item.className = 'list-group-item list-group-item-action py-1 px-2';
                        item.innerHTML = '<strong>' + escHtml(s.name) + '</strong> <small class="text-muted">' + escHtml(s.student_code || '') + ' — ' + escHtml(s.class_name || '') + '</small>';
                        item.addEventListener('click', function(e) {
                            e.preventDefault();
                            hidden.value = s.id;
                            selected.innerHTML = '<span class="badge bg-primary p-2"><i class="fas fa-user me-1"></i>' + escHtml(s.name) + ' (' + escHtml(s.student_code || '') + ')</span>';
                            results.innerHTML = '';
                            input.value = s.name;
                        });
                        results.appendChild(item);
                    });
                }
            });
        }, 300);
    });
}

setupStudentSearch('searchStudent1', 'results1', 'selected1', 'linkStudentId');
setupStudentSearch('searchStudent2', 'results2', 'selected2', 'linkRelativeId');
setupStudentSearch('searchSibling1', 'siblingResults1', 'siblingSelected1', 'linkSiblingStudentId');
setupStudentSearch('searchSibling2', 'siblingResults2', 'siblingSelected2', 'linkSiblingId');

function confirmUnlinkKinship(sid, rid, sName, rName) {
    document.getElementById('unlinkStudentId2').value = sid;
    document.getElementById('unlinkRelativeId2').value = rid;
    document.getElementById('unlinkStudent').textContent = sName;
    document.getElementById('unlinkRelative').textContent = rName;
    new bootstrap.Modal(document.getElementById('unlinkKinshipModal')).show();
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

// DataTables and Tooltips initialization
$(document).ready(function() {
    if ($('#linksTable').length && typeof $.fn.DataTable !== 'undefined') {
        $('#linksTable').DataTable({
            language: {
                search: "بحث:",
                lengthMenu: "عرض _MENU_ سجلات",
                info: "عرض _START_ إلى _END_ من أصل _TOTAL_ سجل",
                infoEmpty: "عرض 0 إلى 0 من أصل 0 سجل",
                infoFiltered: "(تصفية من إجمالي _MAX_ سجل)",
                zeroRecords: "لم يتم العثور على أي نتائج",
                paginate: {
                    first: "الأول",
                    previous: "السابق",
                    next: "التالي",
                    last: "الأخير"
                }
            },
            pageLength: 50,
            responsive: true
        });
    }
});

// تفعيل التلميحات بعد تحميل Bootstrap من التذييل المشترك.
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap === 'undefined') {
        return;
    }

    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
});

// البحث الحي اللحظي للفلاتر
document.querySelectorAll('.dataTables_filter input[name="search"]').forEach(function(input) {
    let timer;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        const form = this.closest('form');
        timer = setTimeout(function() {
            form.submit();
        }, 600); // 600ms debounce
    });
    // ضع المؤشر في نهاية النص عند تحميل الصفحة إذا كان هناك نص بحث نشط
    if (input.value.trim() !== '') {
        input.focus();
        const val = input.value;
        input.value = '';
        input.value = val;
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
