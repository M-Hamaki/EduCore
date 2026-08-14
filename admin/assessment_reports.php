<?php
$page_title = "تقارير الدرجات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function reports_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function reports_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function reports_redirect(string $tab = 'windows'): void
{
    $validTabs = ['windows', 'items'];
    if (!in_array($tab, $validTabs, true)) {
        $tab = 'windows';
    }
    header('Location: assessment_reports.php?tab=' . urlencode($tab));
    exit();
}

function reports_assert_current_year(?int $currentAcademicYearId, array $row, string $message = 'هذا السجل لا يتبع العام الدراسي الحالي.'): void
{
    if ((int) $currentAcademicYearId > 0 && (int) ($row['academic_year_id'] ?? 0) !== (int) $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

function reports_fetch_window(PDO $db, int $reportWindowId): array
{
    $stmt = $db->prepare('SELECT * FROM report_windows WHERE id = ? LIMIT 1');
    $stmt->execute([$reportWindowId]);
    $reportWindow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reportWindow) {
        throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
    }
    return $reportWindow;
}

function reports_fetch_item_with_window(PDO $db, int $itemId): array
{
    $stmt = $db->prepare('SELECT rwi.*, rw.academic_year_id FROM report_window_items rwi JOIN report_windows rw ON rw.id = rwi.report_window_id WHERE rwi.id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new InvalidArgumentException('عنصر التقرير غير موجود.');
    }
    return $item;
}

function reports_assert_date(?string $value, string $label): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . ' غير صحيح.');
    }
    return $value;
}

function reports_checked($value): string
{
    return !empty($value) ? 'checked' : '';
}

function reports_validate_item_scope(PDO $db, int $reportWindowId, ?int $schemeId, ?int $componentId, ?int $weekId, ?int $subjectId): array
{
    if ($reportWindowId <= 0) {
        throw new InvalidArgumentException('اختر نافذة التقرير.');
    }
    if ($schemeId === null && $componentId === null && $weekId === null && $subjectId === null) {
        throw new InvalidArgumentException('اختر مادة أو خطة أو بندا أو أسبوعا واحدا على الأقل.');
    }
    $reportStmt = $db->prepare('SELECT academic_year_id, term_id FROM report_windows WHERE id = ? LIMIT 1');
    $reportStmt->execute([$reportWindowId]);
    $reportWindow = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reportWindow) {
        throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
    }
    if ($schemeId !== null) {
        $schemeStmt = $db->prepare('SELECT academic_year_id, term_id, subject_id FROM assessment_schemes WHERE id = ? LIMIT 1');
        $schemeStmt->execute([$schemeId]);
        $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$scheme || (int) $scheme['academic_year_id'] !== (int) $reportWindow['academic_year_id'] || (!empty($reportWindow['term_id']) && (int) $scheme['term_id'] !== (int) $reportWindow['term_id'])) {
            throw new InvalidArgumentException('الخطة المختارة لا تتوافق مع التقرير.');
        }
        if ($subjectId !== null && (int) $scheme['subject_id'] !== $subjectId) {
            throw new InvalidArgumentException('المادة المختارة لا تتوافق مع الخطة المختارة.');
        }
    }
    if ($componentId !== null) {
        $componentStmt = $db->prepare("SELECT ac.scheme_id, sch.academic_year_id, sch.term_id, sch.subject_id FROM assessment_components ac JOIN assessment_schemes sch ON sch.id = ac.scheme_id WHERE ac.id = ? LIMIT 1");
        $componentStmt->execute([$componentId]);
        $component = $componentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$component || (int) $component['academic_year_id'] !== (int) $reportWindow['academic_year_id'] || (!empty($reportWindow['term_id']) && (int) $component['term_id'] !== (int) $reportWindow['term_id'])) {
            throw new InvalidArgumentException('البند المختار لا يتوافق مع التقرير.');
        }
        if ($schemeId !== null && (int) $component['scheme_id'] !== $schemeId) {
            throw new InvalidArgumentException('البند المختار لا يتبع الخطة المختارة.');
        }
        if ($subjectId !== null && (int) $component['subject_id'] !== $subjectId) {
            throw new InvalidArgumentException('المادة المختارة لا تتوافق مع البند المختار.');
        }
        if ($schemeId === null) {
            $schemeId = (int) $component['scheme_id'];
        }
    }
    if ($weekId !== null) {
        $weekStmt = $db->prepare('SELECT academic_year_id, term_id FROM academic_weeks WHERE id = ? LIMIT 1');
        $weekStmt->execute([$weekId]);
        $week = $weekStmt->fetch(PDO::FETCH_ASSOC);
        if (!$week || (int) $week['academic_year_id'] !== (int) $reportWindow['academic_year_id'] || (!empty($reportWindow['term_id']) && (int) $week['term_id'] !== (int) $reportWindow['term_id'])) {
            throw new InvalidArgumentException('الأسبوع المختار لا يتوافق مع التقرير.');
        }
    }

    return [
        'report_window_id' => $reportWindowId,
        'scheme_id' => $schemeId,
        'component_id' => $componentId,
        'week_id' => $weekId,
        'subject_id' => $subjectId,
    ];
}

$reportsReady = reports_table_exists($db, 'report_windows') && reports_table_exists($db, 'report_window_items');
$schemesReady = reports_table_exists($db, 'assessment_schemes');
$componentsReady = reports_table_exists($db, 'assessment_components');
$calendarReady = reports_table_exists($db, 'academic_years') && reports_table_exists($db, 'academic_terms') && reports_table_exists($db, 'academic_weeks');
$publishedReportsReady = reports_table_exists($db, 'published_reports');

$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'windows');
$validTabs = ['windows', 'items'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'windows';
}

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';
$reportTypeLabels = ['monthly' => 'شهري', 'period' => 'فترة', 'annual' => 'نهاية عام', 'custom' => 'مخصص'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable((int) $currentAcademicYearId);
        if (!$reportsReady) {
            throw new RuntimeException('جداول التقارير المنشورة غير مطبقة بعد.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_report_window') {
            $academicYearId = $currentAcademicYearId > 0 ? $currentAcademicYearId : (int) ($_POST['academic_year_id'] ?? 0);
            $termId = !empty($_POST['term_id']) ? (int) $_POST['term_id'] : null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $reportType = in_array(($_POST['report_type'] ?? 'monthly'), array_keys($reportTypeLabels), true) ? (string) $_POST['report_type'] : 'monthly';
            $dateFrom = reports_assert_date($_POST['date_from'] ?? '', 'تاريخ بداية التقرير');
            $dateTo = reports_assert_date($_POST['date_to'] ?? '', 'تاريخ نهاية التقرير');
            if ($academicYearId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر العام الدراسي واكتب اسم التقرير.');
            }
            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                throw new InvalidArgumentException('تاريخ بداية التقرير يجب أن يكون قبل تاريخ النهاية.');
            }
            $yearStmt = $db->prepare('SELECT start_date, end_date FROM academic_years WHERE id = ? LIMIT 1');
            $yearStmt->execute([$academicYearId]);
            $yearRange = $yearStmt->fetch(PDO::FETCH_ASSOC);
            if (!$yearRange) {
                throw new InvalidArgumentException('العام الدراسي المحدد غير موجود.');
            }
            if ($termId !== null) {
                $termStmt = $db->prepare('SELECT academic_year_id, start_date, end_date FROM academic_terms WHERE id = ? LIMIT 1');
                $termStmt->execute([$termId]);
                $termRange = $termStmt->fetch(PDO::FETCH_ASSOC);
                if (!$termRange || (int) $termRange['academic_year_id'] !== $academicYearId) {
                    throw new InvalidArgumentException('الترم المختار لا يتبع العام الدراسي المحدد.');
                }
                if ($dateFrom && !empty($termRange['start_date']) && $dateFrom < $termRange['start_date']) {
                    throw new InvalidArgumentException('تاريخ بداية التقرير خارج نطاق الترم المختار.');
                }
                if ($dateTo && !empty($termRange['end_date']) && $dateTo > $termRange['end_date']) {
                    throw new InvalidArgumentException('تاريخ نهاية التقرير خارج نطاق الترم المختار.');
                }
            }

            $stmt = $db->prepare("INSERT INTO report_windows
                (academic_year_id, term_id, name, report_type, date_from, date_to,
                 include_details, include_absence, include_teacher_notes, freeze_on_publish, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$academicYearId, $termId, $name, $reportType, $dateFrom, $dateTo, isset($_POST['include_details']) ? 1 : 0, isset($_POST['include_absence']) ? 1 : 0, isset($_POST['include_teacher_notes']) ? 1 : 0, isset($_POST['freeze_on_publish']) ? 1 : 0, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
            $reportWindowId = (int) $db->lastInsertId();
            ActivityLog::logCreate('report_window', $reportWindowId, $name, ['academic_year' => $academicYearId, 'term' => $termId, 'report_type' => $reportType]);
            $_SESSION['success_message'] = 'تم إنشاء نافذة التقرير بنجاح.';
            reports_redirect('windows');
        }

        if ($action === 'update_report_window') {
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $reportType = in_array(($_POST['report_type'] ?? 'monthly'), array_keys($reportTypeLabels), true) ? (string) $_POST['report_type'] : 'monthly';
            $dateFrom = reports_assert_date($_POST['date_from'] ?? '', 'تاريخ بداية التقرير');
            $dateTo = reports_assert_date($_POST['date_to'] ?? '', 'تاريخ نهاية التقرير');
            if ($reportWindowId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر نافذة التقرير واكتب اسم التقرير.');
            }
            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                throw new InvalidArgumentException('تاريخ بداية التقرير يجب أن يكون قبل تاريخ النهاية.');
            }
            $reportStmt = $db->prepare("SELECT rw.*, ay.start_date AS year_start, ay.end_date AS year_end, t.start_date AS term_start, t.end_date AS term_end FROM report_windows rw JOIN academic_years ay ON ay.id = rw.academic_year_id LEFT JOIN academic_terms t ON t.id = rw.term_id WHERE rw.id = ? LIMIT 1");
            $reportStmt->execute([$reportWindowId]);
            $existingReport = $reportStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingReport) {
                throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
            }
            reports_assert_current_year($currentAcademicYearId, $existingReport);
            if (!empty($existingReport['term_id'])) {
                if ($dateFrom && !empty($existingReport['term_start']) && $dateFrom < $existingReport['term_start']) {
                    throw new InvalidArgumentException('تاريخ بداية التقرير خارج نطاق الترم المختار.');
                }
                if ($dateTo && !empty($existingReport['term_end']) && $dateTo > $existingReport['term_end']) {
                    throw new InvalidArgumentException('تاريخ نهاية التقرير خارج نطاق الترم المختار.');
                }
            }
            $stmt = $db->prepare("UPDATE report_windows
                SET name = ?, report_type = ?, date_from = ?, date_to = ?,
                    include_details = ?, include_absence = ?, include_teacher_notes = ?, freeze_on_publish = ?
                WHERE id = ?");
            $stmt->execute([$name, $reportType, $dateFrom, $dateTo, isset($_POST['include_details']) ? 1 : 0, isset($_POST['include_absence']) ? 1 : 0, isset($_POST['include_teacher_notes']) ? 1 : 0, isset($_POST['freeze_on_publish']) ? 1 : 0, $reportWindowId]);
            ActivityLog::logUpdate('report_window', $reportWindowId, $name, ['old_name' => $existingReport['name'], 'new_name' => $name, 'report_type' => $reportType]);
            $_SESSION['success_message'] = 'تم تعديل نافذة التقرير بنجاح.';
            reports_redirect('windows');
        }

        if ($action === 'publish_report_window') {
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            $classId = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
            if ($reportWindowId <= 0) {
                throw new InvalidArgumentException('اختر نافذة التقرير المطلوب نشرها.');
            }
            $reportWindow = reports_fetch_window($db, $reportWindowId);
            reports_assert_current_year($currentAcademicYearId, $reportWindow);
            $db->beginTransaction();
            $publishResult = (new AssessmentEngine($db))->publishReportWindow($reportWindowId, $classId, (int) ($_SESSION['user_id'] ?? 0));
            $db->commit();
            $publishedCount = (int) ($publishResult['published'] ?? 0);
            $skippedCount = (int) ($publishResult['skipped'] ?? 0);
            $pendingReviewCount = (int) ($publishResult['pending_review'] ?? 0);
            ActivityLog::logUpdate('report_window', $reportWindowId, 'نشر تقرير', ['class_id' => $classId, 'count' => $publishedCount, 'skipped' => $skippedCount, 'pending_review' => $pendingReviewCount]);
            $_SESSION['success_message'] = "تم نشر {$publishedCount} تقريرا للطلاب.";
            if ($skippedCount > 0) {
                $_SESSION['success_message'] .= " وتم ترك {$skippedCount} تقريرا مجمدا بدون تغيير.";
            }
            if ($pendingReviewCount > 0) {
                $_SESSION['success_message'] .= " توجد {$pendingReviewCount} درجة بانتظار المراجعة لم تدخل في التقارير.";
            }
            reports_redirect('windows');
        }

        if ($action === 'add_report_item') {
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            $schemeId = !empty($_POST['scheme_id']) ? (int) $_POST['scheme_id'] : null;
            $componentId = !empty($_POST['component_id']) ? (int) $_POST['component_id'] : null;
            $weekId = !empty($_POST['week_id']) ? (int) $_POST['week_id'] : null;
            $subjectId = !empty($_POST['subject_id']) ? (int) $_POST['subject_id'] : null;
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            if ($reportWindowId <= 0) {
                throw new InvalidArgumentException('اختر نافذة التقرير.');
            }
            if ($schemeId === null && $componentId === null && $weekId === null && $subjectId === null) {
                throw new InvalidArgumentException('اختر مادة أو خطة أو بندا أو أسبوعا واحدا على الأقل.');
            }
            $reportStmt = $db->prepare('SELECT academic_year_id, term_id FROM report_windows WHERE id = ? LIMIT 1');
            $reportStmt->execute([$reportWindowId]);
            $reportWindow = $reportStmt->fetch(PDO::FETCH_ASSOC);
            if (!$reportWindow) {
                throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
            }
            reports_assert_current_year($currentAcademicYearId, $reportWindow);
            if ($schemeId !== null) {
                $schemeStmt = $db->prepare('SELECT academic_year_id, term_id, subject_id FROM assessment_schemes WHERE id = ? LIMIT 1');
                $schemeStmt->execute([$schemeId]);
                $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$scheme || (int) $scheme['academic_year_id'] !== (int) $reportWindow['academic_year_id'] || (!empty($reportWindow['term_id']) && (int) $scheme['term_id'] !== (int) $reportWindow['term_id'])) {
                    throw new InvalidArgumentException('الخطة المختارة لا تتوافق مع التقرير.');
                }
                if ($subjectId !== null && (int) $scheme['subject_id'] !== $subjectId) {
                    throw new InvalidArgumentException('المادة المختارة لا تتوافق مع الخطة المختارة.');
                }
            }
            if ($componentId !== null) {
                $componentStmt = $db->prepare("SELECT ac.scheme_id, sch.academic_year_id, sch.term_id, sch.subject_id FROM assessment_components ac JOIN assessment_schemes sch ON sch.id = ac.scheme_id WHERE ac.id = ? LIMIT 1");
                $componentStmt->execute([$componentId]);
                $component = $componentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$component || (int) $component['academic_year_id'] !== (int) $reportWindow['academic_year_id'] || (!empty($reportWindow['term_id']) && (int) $component['term_id'] !== (int) $reportWindow['term_id'])) {
                    throw new InvalidArgumentException('البند المختار لا يتوافق مع التقرير.');
                }
                if ($schemeId !== null && (int) $component['scheme_id'] !== $schemeId) {
                    throw new InvalidArgumentException('البند المختار لا يتبع الخطة المختارة.');
                }
                if ($subjectId !== null && (int) $component['subject_id'] !== $subjectId) {
                    throw new InvalidArgumentException('المادة المختارة لا تتوافق مع البند المختار.');
                }
                if ($schemeId === null) {
                    $schemeId = (int) $component['scheme_id'];
                }
            }
            if ($weekId !== null) {
                $weekStmt = $db->prepare('SELECT academic_year_id, term_id FROM academic_weeks WHERE id = ? LIMIT 1');
                $weekStmt->execute([$weekId]);
                $week = $weekStmt->fetch(PDO::FETCH_ASSOC);
                if (!$week || (int) $week['academic_year_id'] !== (int) $reportWindow['academic_year_id'] || (!empty($reportWindow['term_id']) && (int) $week['term_id'] !== (int) $reportWindow['term_id'])) {
                    throw new InvalidArgumentException('الأسبوع المختار لا يتوافق مع التقرير.');
                }
            }
            $duplicateStmt = $db->prepare("SELECT id FROM report_window_items WHERE report_window_id = ? AND ((scheme_id IS NULL AND ? IS NULL) OR scheme_id = ?) AND ((component_id IS NULL AND ? IS NULL) OR component_id = ?) AND ((week_id IS NULL AND ? IS NULL) OR week_id = ?) AND ((subject_id IS NULL AND ? IS NULL) OR subject_id = ?) LIMIT 1");
            $duplicateStmt->execute([$reportWindowId, $schemeId, $schemeId, $componentId, $componentId, $weekId, $weekId, $subjectId, $subjectId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('تمت إضافة نفس نطاق محتوى التقرير من قبل.');
            }
            $stmt = $db->prepare("INSERT INTO report_window_items (report_window_id, scheme_id, component_id, week_id, subject_id, include_item, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([$reportWindowId, $schemeId, $componentId, $weekId, $subjectId, $sortOrder]);
            ActivityLog::logUpdate('report_window', $reportWindowId, 'تخصيص محتوى التقرير', ['scheme' => $schemeId, 'component' => $componentId, 'week' => $weekId, 'subject' => $subjectId]);
            $_SESSION['success_message'] = 'تم إضافة بند/نطاق إلى محتوى التقرير.';
            reports_redirect('items');
        }

        if ($action === 'update_report_item') {
            $itemId = (int) ($_POST['report_item_id'] ?? 0);
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            $schemeId = !empty($_POST['scheme_id']) ? (int) $_POST['scheme_id'] : null;
            $componentId = !empty($_POST['component_id']) ? (int) $_POST['component_id'] : null;
            $weekId = !empty($_POST['week_id']) ? (int) $_POST['week_id'] : null;
            $subjectId = !empty($_POST['subject_id']) ? (int) $_POST['subject_id'] : null;
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $includeItem = isset($_POST['include_item']) ? 1 : 0;
            if ($itemId <= 0) {
                throw new InvalidArgumentException('عنصر التقرير غير موجود.');
            }
            $oldItem = reports_fetch_item_with_window($db, $itemId);
            reports_assert_current_year($currentAcademicYearId, $oldItem);
            $normalizedItem = reports_validate_item_scope($db, $reportWindowId, $schemeId, $componentId, $weekId, $subjectId);
            $targetReportWindow = reports_fetch_window($db, $reportWindowId);
            reports_assert_current_year($currentAcademicYearId, $targetReportWindow);
            $schemeId = $normalizedItem['scheme_id'];
            $componentId = $normalizedItem['component_id'];
            $weekId = $normalizedItem['week_id'];
            $subjectId = $normalizedItem['subject_id'];
            $duplicateStmt = $db->prepare("SELECT id FROM report_window_items WHERE report_window_id = ? AND id <> ? AND ((scheme_id IS NULL AND ? IS NULL) OR scheme_id = ?) AND ((component_id IS NULL AND ? IS NULL) OR component_id = ?) AND ((week_id IS NULL AND ? IS NULL) OR week_id = ?) AND ((subject_id IS NULL AND ? IS NULL) OR subject_id = ?) LIMIT 1");
            $duplicateStmt->execute([$reportWindowId, $itemId, $schemeId, $schemeId, $componentId, $componentId, $weekId, $weekId, $subjectId, $subjectId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('تمت إضافة نفس نطاق محتوى التقرير من قبل.');
            }
            $stmt = $db->prepare('UPDATE report_window_items SET report_window_id = ?, scheme_id = ?, component_id = ?, week_id = ?, subject_id = ?, include_item = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$reportWindowId, $schemeId, $componentId, $weekId, $subjectId, $includeItem, $sortOrder, $itemId]);
            ActivityLog::logUpdate('report_window', $reportWindowId, 'تعديل عنصر محتوى التقرير', ['item' => $itemId, 'old_report_window_id' => $oldItem['report_window_id'], 'scheme' => $schemeId, 'component' => $componentId, 'week' => $weekId, 'subject' => $subjectId, 'include_item' => $includeItem]);
            $_SESSION['success_message'] = 'تم تعديل عنصر محتوى التقرير بنجاح.';
            reports_redirect('items');
        }

        if ($action === 'toggle_report_item') {
            $itemId = (int) ($_POST['report_item_id'] ?? 0);
            $item = reports_fetch_item_with_window($db, $itemId);
            reports_assert_current_year($currentAcademicYearId, $item);
            $newStatus = !empty($item['include_item']) ? 0 : 1;
            $db->prepare('UPDATE report_window_items SET include_item = ? WHERE id = ?')->execute([$newStatus, $itemId]);
            ActivityLog::logUpdate('report_window', (int) $item['report_window_id'], $newStatus ? 'تفعيل عنصر محتوى التقرير' : 'تعطيل عنصر محتوى التقرير', ['item' => $itemId, 'include_item' => $newStatus]);
            $_SESSION['success_message'] = $newStatus ? 'تم تفعيل عنصر محتوى التقرير.' : 'تم تعطيل عنصر محتوى التقرير.';
            reports_redirect('items');
        }

        if ($action === 'remove_report_item') {
            $itemId = (int) ($_POST['report_item_id'] ?? 0);
            $item = reports_fetch_item_with_window($db, $itemId);
            reports_assert_current_year($currentAcademicYearId, $item);
            $reportWindowId = (int) $item['report_window_id'];
            $db->prepare('DELETE FROM report_window_items WHERE id = ?')->execute([$itemId]);
            ActivityLog::logUpdate('report_window', $reportWindowId, 'إزالة عنصر من التقرير', ['item' => $itemId]);
            $_SESSION['success_message'] = 'تم إزالة العنصر من محتوى التقرير.';
            reports_redirect('items');
        }

        if ($action === 'hide_report_window') {
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            if ($reportWindowId <= 0) {
                throw new InvalidArgumentException('اختر نافذة التقرير.');
            }
            $reportWindow = reports_fetch_window($db, $reportWindowId);
            reports_assert_current_year($currentAcademicYearId, $reportWindow);
            $db->prepare('UPDATE report_windows SET is_published = 0, hidden_at = NOW() WHERE id = ?')->execute([$reportWindowId]);
            ActivityLog::logUpdate('report_window', $reportWindowId, 'إخفاء تقرير', ['window' => $reportWindowId]);
            $_SESSION['success_message'] = 'تم إخفاء التقرير عن الطلاب.';
            reports_redirect('windows');
        }

        if ($action === 'unpublish_report_window') {
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            if ($reportWindowId <= 0) {
                throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
            }
            $reportWindow = reports_fetch_window($db, $reportWindowId);
            reports_assert_current_year($currentAcademicYearId, $reportWindow);
            if (!$publishedReportsReady || !reports_table_exists($db, 'published_report_details')) {
                throw new RuntimeException('جداول النسخ المنشورة غير مطبقة بعد.');
            }
            $result = (new AssessmentEngine($db))->unpublishReportWindow($reportWindowId);
            $_SESSION['success_message'] = sprintf(
                'تم إلغاء النشر وحذف %d نسخة طالب و%d بند تفصيلي. يمكنك الآن حذف نافذة التقرير.',
                (int) ($result['deleted_reports'] ?? 0),
                (int) ($result['deleted_details'] ?? 0)
            );
            reports_redirect('windows');
        }

        if ($action === 'delete_report_window') {
            $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
            if ($reportWindowId <= 0) {
                throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
            }
            $reportWindow = reports_fetch_window($db, $reportWindowId);
            reports_assert_current_year($currentAcademicYearId, $reportWindow);
            $reportName = (string) ($reportWindow['name'] ?? '');
            if ($publishedReportsReady) {
                $countStmt = $db->prepare('SELECT COUNT(*) FROM published_reports WHERE report_window_id = ?');
                $countStmt->execute([$reportWindowId]);
                if ((int) $countStmt->fetchColumn() > 0) {
                    throw new RuntimeException('لا يمكن حذف نافذة تقرير لها نسخ منشورة. استخدم الإخفاء بدلا من الحذف.');
                }
            }
            $db->prepare('DELETE FROM report_window_items WHERE report_window_id = ?')->execute([$reportWindowId]);
            $db->prepare('DELETE FROM report_windows WHERE id = ?')->execute([$reportWindowId]);
            ActivityLog::logDelete('report_window', $reportWindowId, $reportName, []);
            $_SESSION['success_message'] = 'تم حذف نافذة التقرير بنجاح.';
            reports_redirect('windows');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        reports_redirect($activeTab);
    }
}

$academicYears = [];
$terms = [];
$months = [];
$subjects = [];
$schemes = [];
$components = [];
$weeks = [];
$classes = [];
$reportWindows = [];
$reportItems = [];
$reportsCount = 0;
$publishedWindowsCount = 0;
$publishedReportsCount = 0;
$pendingReviewTotal = 0;

if ($calendarReady) {
    $academicYears = $db->query("SELECT id, name, is_active FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($currentAcademicYearId > 0) {
        $termsStmt = $db->prepare('SELECT t.*, ay.name AS academic_year_name
            FROM academic_terms t
            JOIN academic_years ay ON ay.id = t.academic_year_id
            WHERE t.academic_year_id = ?
            ORDER BY t.term_order ASC');
        $termsStmt->execute([$currentAcademicYearId]);
        $terms = $termsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $terms = $db->query("SELECT t.*, ay.name AS academic_year_name FROM academic_terms t JOIN academic_years ay ON ay.id = t.academic_year_id ORDER BY ay.is_active DESC, ay.id DESC, t.term_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (reports_table_exists($db, 'academic_months')) {
        if ($currentAcademicYearId > 0) {
            $monthsStmt = $db->prepare("SELECT m.*, ay.name AS academic_year_name, t.name AS term_name
                FROM academic_months m
                JOIN academic_years ay ON ay.id = m.academic_year_id
                JOIN academic_terms t ON t.id = m.term_id
                WHERE m.status = 'active' AND m.academic_year_id = ?
                ORDER BY t.term_order ASC, m.month_order ASC");
            $monthsStmt->execute([$currentAcademicYearId]);
            $months = $monthsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $months = $db->query("SELECT m.*, ay.name AS academic_year_name, t.name AS term_name FROM academic_months m JOIN academic_years ay ON ay.id = m.academic_year_id JOIN academic_terms t ON t.id = m.term_id WHERE m.status = 'active' ORDER BY ay.is_active DESC, ay.id DESC, t.term_order ASC, m.month_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
    if ($currentAcademicYearId > 0) {
        $weeksStmt = $db->prepare('SELECT w.*, t.name AS term_name
            FROM academic_weeks w
            JOIN academic_terms t ON t.id = w.term_id
            WHERE w.academic_year_id = ?
            ORDER BY t.term_order ASC, w.week_order ASC');
        $weeksStmt->execute([$currentAcademicYearId]);
        $weeks = $weeksStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $weeks = $db->query("SELECT w.*, t.name AS term_name FROM academic_weeks w JOIN academic_terms t ON t.id = w.term_id ORDER BY t.term_order ASC, w.week_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
$subjectActiveWhere = reports_column_exists($db, 'subjects', 'status')
    ? "WHERE status = 'active'"
    : (reports_column_exists($db, 'subjects', 'is_active') ? 'WHERE COALESCE(is_active, 1) = 1' : '');
$subjects = $db->query("SELECT id, name FROM subjects {$subjectActiveWhere} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$classActiveCondition = reports_column_exists($db, 'classes', 'status') ? " AND c.status = 'active'" : '';
if ($currentAcademicYearId > 0 && reports_table_exists($db, 'student_enrollments')) {
    $classStmt = $db->prepare("SELECT DISTINCT c.id, c.name
        FROM classes c
        JOIN student_enrollments se ON se.class_id = c.id
        WHERE se.academic_year_id = ?
          AND se.enrollment_status = 'enrolled'
          {$classActiveCondition}
        ORDER BY c.grade_id, c.display_order, c.name");
    $classStmt->execute([$currentAcademicYearId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $classActiveWhere = reports_column_exists($db, 'classes', 'status') ? "WHERE status = 'active'" : '';
    $classes = $db->query("SELECT id, name FROM classes {$classActiveWhere} ORDER BY grade_id, display_order, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($schemesReady) {
    $schemeSql = "SELECT sch.*, s.name AS subject_name, g.grade_name, t.name AS term_name
        FROM assessment_schemes sch
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        JOIN academic_terms t ON t.id = sch.term_id";
    $schemeParams = [];
    if ($currentAcademicYearId > 0) {
        $schemeSql .= ' WHERE sch.academic_year_id = ?';
        $schemeParams[] = $currentAcademicYearId;
    }
    $schemeSql .= ' ORDER BY sch.id DESC';
    $schemeStmt = $db->prepare($schemeSql);
    $schemeStmt->execute($schemeParams);
    $schemes = $schemeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($componentsReady) {
    $componentSql = 'SELECT ac.*, sch.academic_year_id, sch.term_id, sch.subject_id, sch.name AS scheme_name
        FROM assessment_components ac
        JOIN assessment_schemes sch ON sch.id = ac.scheme_id';
    $componentParams = [];
    if ($currentAcademicYearId > 0) {
        $componentSql .= ' WHERE sch.academic_year_id = ?';
        $componentParams[] = $currentAcademicYearId;
    }
    $componentSql .= ' ORDER BY sch.id DESC, ac.sort_order ASC';
    $componentStmt = $db->prepare($componentSql);
    $componentStmt->execute($componentParams);
    $components = $componentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($reportsReady) {
    $reportWindowSql = 'SELECT rw.*, ay.name AS academic_year_name, t.name AS term_name, COUNT(pr.id) AS published_count
        FROM report_windows rw
        JOIN academic_years ay ON ay.id = rw.academic_year_id
        LEFT JOIN academic_terms t ON t.id = rw.term_id
        LEFT JOIN published_reports pr ON pr.report_window_id = rw.id';
    $reportWindowParams = [];
    if ($currentAcademicYearId > 0) {
        $reportWindowSql .= ' WHERE rw.academic_year_id = ?';
        $reportWindowParams[] = $currentAcademicYearId;
    }
    $reportWindowSql .= ' GROUP BY rw.id ORDER BY rw.id DESC';
    $reportWindowStmt = $db->prepare($reportWindowSql);
    $reportWindowStmt->execute($reportWindowParams);
    $reportWindows = $reportWindowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $engine = new AssessmentEngine($db);
    foreach ($reportWindows as &$reportWindow) {
        $reportWindow['pending_review_count'] = $engine->countPendingReviewMarksForReportWindow((int) $reportWindow['id']);
        $reportsCount++;
        if (!empty($reportWindow['is_published'])) {
            $publishedWindowsCount++;
        }
        $publishedReportsCount += (int) $reportWindow['published_count'];
        $pendingReviewTotal += (int) $reportWindow['pending_review_count'];
    }
    unset($reportWindow);
    $reportItemSql = 'SELECT rwi.*, rw.name AS report_name, s.name AS subject_name, sch.name AS scheme_name, ac.name AS component_name, w.name AS week_name
        FROM report_window_items rwi
        JOIN report_windows rw ON rw.id = rwi.report_window_id
        LEFT JOIN subjects s ON s.id = rwi.subject_id
        LEFT JOIN assessment_schemes sch ON sch.id = rwi.scheme_id
        LEFT JOIN assessment_components ac ON ac.id = rwi.component_id
        LEFT JOIN academic_weeks w ON w.id = rwi.week_id';
    $reportItemParams = [];
    if ($currentAcademicYearId > 0) {
        $reportItemSql .= ' WHERE rw.academic_year_id = ?';
        $reportItemParams[] = $currentAcademicYearId;
    }
    $reportItemSql .= ' ORDER BY rw.id DESC, rwi.sort_order ASC, rwi.id ASC';
    $reportItemStmt = $db->prepare($reportItemSql);
    $reportItemStmt->execute($reportItemParams);
    $reportItems = $reportItemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-file-lines me-2 text-primary"></i>تقارير الدرجات</h1>
    <div class="admin-top-actions no-print">
        <?php if ($reportsReady): ?>
            <?php if ($activeTab === 'items'): ?>
                <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addReportItemModal"><i class="fas fa-list-check me-1"></i>تخصيص محتوى</button>
            <?php else: ?>
                <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addReportWindowModal"><i class="fas fa-plus-circle me-1"></i>إنشاء تقرير</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (!empty($error_message)): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>



<?php if (!$reportsReady || !$calendarReady): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول التقارير والتقويم أولا.</div>
<?php else: ?>
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-file-lines"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$reportsCount; ?>">0</div><div class="stat-card-label">نوافذ التقارير</div><div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'كل الأعوام', ENT_QUOTES, 'UTF-8'); ?></div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-eye"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$publishedWindowsCount; ?>">0</div><div class="stat-card-label">متاحة للطلاب</div><div class="stat-card-sub">نوافذ منشورة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);"><div class="stat-card-icon"><i class="fas fa-paper-plane"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$publishedReportsCount; ?>">0</div><div class="stat-card-label">نسخ منشورة</div><div class="stat-card-sub">تقارير طلاب</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$pendingReviewTotal; ?>">0</div><div class="stat-card-label">بانتظار المراجعة</div><div class="stat-card-sub">درجات لم تُنشر</div></div></div></div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'windows' ? 'active' : ''; ?>" href="assessment_reports.php?tab=windows">
            <i class="fas fa-file-lines me-1"></i>نوافذ التقارير <span class="badge rounded-pill bg-primary ms-1"><?php echo (int) $reportsCount; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'items' ? 'active' : ''; ?>" href="assessment_reports.php?tab=items">
            <i class="fas fa-list-check me-1"></i>محتوى التقارير <span class="badge rounded-pill bg-primary ms-1"><?php echo count($reportItems); ?></span>
        </a>
    </li>
</ul>

<div class="admin-list-surface">
        <?php if ($activeTab === 'items'): ?>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped align-middle datatable admin-data-table">
                    <thead><tr><th>التقرير</th><th>المادة</th><th>الخطة</th><th>البند</th><th>الأسبوع</th><th>الترتيب</th><th>الحالة</th><th class="admin-col-130px">إجراءات</th></tr></thead>
                    <tbody>
                    <?php if (empty($reportItems)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">لم يتم تخصيص محتوى لأي تقرير بعد.</td></tr>
                    <?php else: ?>
                    <?php foreach ($reportItems as $item): ?>
                        <?php $itemName = $item['report_name'] . ' - ' . ($item['component_name'] ?? $item['scheme_name'] ?? $item['subject_name'] ?? 'نطاق عام'); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['report_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['subject_name'] ?? 'كل المواد', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['scheme_name'] ?? 'كل الخطط', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['component_name'] ?? 'كل البنود', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['week_name'] ?? 'كل الأسابيع', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) $item['sort_order']; ?></td>
                            <td><?php echo !empty($item['include_item']) ? '<span class="badge bg-success">مفعل</span>' : '<span class="badge bg-secondary">معطل</span>'; ?></td>
                            <td class="actions-column admin-table-actions">
                                <button type="button" class="btn btn-sm btn-action-pills btn-edit edit-item-btn me-1" data-bs-toggle="tooltip" title="تعديل" data-item-id="<?php echo (int) $item['id']; ?>" data-item-name="<?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>" data-report-window-id="<?php echo (int) $item['report_window_id']; ?>" data-subject-id="<?php echo (int) ($item['subject_id'] ?? 0); ?>" data-scheme-id="<?php echo (int) ($item['scheme_id'] ?? 0); ?>" data-component-id="<?php echo (int) ($item['component_id'] ?? 0); ?>" data-week-id="<?php echo (int) ($item['week_id'] ?? 0); ?>" data-sort-order="<?php echo (int) $item['sort_order']; ?>" data-include-item="<?php echo !empty($item['include_item']) ? '1' : '0'; ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm btn-action-pills <?php echo !empty($item['include_item']) ? 'btn-deactivate' : 'btn-activate'; ?> me-1 toggle-item-btn" data-bs-toggle="tooltip" title="<?php echo !empty($item['include_item']) ? 'تعطيل' : 'تفعيل'; ?>" data-item-id="<?php echo (int) $item['id']; ?>" data-item-name="<?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>" data-current-status="<?php echo !empty($item['include_item']) ? '1' : '0'; ?>"><i class="fas <?php echo !empty($item['include_item']) ? 'fa-ban' : 'fa-check'; ?>"></i></button>
                                <button type="button" class="btn btn-sm btn-action-pills btn-delete remove-item-btn" data-bs-toggle="tooltip" title="حذف" data-item-id="<?php echo (int) $item['id']; ?>" data-item-name="<?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped align-middle datatable admin-data-table">
                    <thead><tr><th>التقرير</th><th>العام/الترم</th><th>الفترة</th><th>النوع</th><th>المنشور</th><th>بانتظار المراجعة</th><th>الحالة</th><th class="admin-col-220px">إجراءات</th></tr></thead>
                    <tbody>
                    <?php if (empty($reportWindows)): ?><tr><td colspan="8" class="text-center text-muted py-4">لم يتم إنشاء نوافذ تقارير بعد.</td></tr><?php else: ?>
                        <?php foreach ($reportWindows as $reportWindow): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td><?php echo htmlspecialchars($reportWindow['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted"><?php echo htmlspecialchars($reportWindow['term_name'] ?? 'كل الترمات', ENT_QUOTES, 'UTF-8'); ?></div></td>
                                <td><span dir="ltr"><?php echo htmlspecialchars(($reportWindow['date_from'] ?? '-') . ' / ' . ($reportWindow['date_to'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($reportTypeLabels[$reportWindow['report_type']] ?? $reportWindow['report_type'], ENT_QUOTES, 'UTF-8'); ?> <?php echo !empty($reportWindow['freeze_on_publish']) ? '<span class="badge bg-light text-dark">تجميد</span>' : '<span class="badge bg-warning text-dark">تحديث</span>'; ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo number_format((int) $reportWindow['published_count']); ?></span></td>
                                <td><?php echo (int) ($reportWindow['pending_review_count'] ?? 0) > 0 ? '<span class="badge bg-warning text-dark">' . number_format((int) $reportWindow['pending_review_count']) . '</span>' : '<span class="badge bg-success">0</span>'; ?></td>
                                <td>
                                    <?php if (!empty($reportWindow['is_published'])): ?>
                                        <span class="badge bg-success">متاح للطلاب</span>
                                    <?php elseif ((int) $reportWindow['published_count'] > 0): ?>
                                        <span class="badge bg-warning text-dark">مخفي - النسخ محفوظة</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير منشور</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-column admin-table-actions">
                                    <button type="button" class="btn btn-sm btn-action-pills btn-edit edit-report-btn me-1" data-bs-toggle="tooltip" title="تعديل" data-report-id="<?php echo (int) $reportWindow['id']; ?>" data-report-name="<?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?>" data-report-type="<?php echo htmlspecialchars($reportWindow['report_type'], ENT_QUOTES, 'UTF-8'); ?>" data-report-date-from="<?php echo htmlspecialchars((string) ($reportWindow['date_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-report-date-to="<?php echo htmlspecialchars((string) ($reportWindow['date_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-report-include-details="<?php echo !empty($reportWindow['include_details']) ? '1' : '0'; ?>" data-report-include-absence="<?php echo !empty($reportWindow['include_absence']) ? '1' : '0'; ?>" data-report-include-notes="<?php echo !empty($reportWindow['include_teacher_notes']) ? '1' : '0'; ?>" data-report-freeze="<?php echo !empty($reportWindow['freeze_on_publish']) ? '1' : '0'; ?>" data-report-period="<?php echo htmlspecialchars($reportWindow['academic_year_name'] . ' / ' . ($reportWindow['term_name'] ?? 'كل الترمات'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-activate me-1 publish-report-btn" data-bs-toggle="tooltip" title="نشر" data-report-id="<?php echo (int) $reportWindow['id']; ?>" data-report-name="<?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-paper-plane"></i></button>
                                    <?php if (!empty($reportWindow['is_published'])): ?><button type="button" class="btn btn-sm btn-action-pills btn-deactivate me-1 hide-report-btn" data-bs-toggle="tooltip" title="إخفاء" data-report-id="<?php echo (int) $reportWindow['id']; ?>" data-report-name="<?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-eye-slash"></i></button><?php endif; ?>
                                    <?php if ((int) $reportWindow['published_count'] > 0): ?>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-delete unpublish-report-btn me-1" data-bs-toggle="tooltip" title="إلغاء النشر وحذف نسخ الطلاب" data-report-id="<?php echo (int) $reportWindow['id']; ?>" data-report-name="<?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?>" data-published-count="<?php echo (int) $reportWindow['published_count']; ?>"><i class="fas fa-file-circle-xmark"></i></button>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-delete" data-bs-toggle="tooltip" title="احذف النسخ المنشورة أولا" disabled aria-disabled="true"><i class="fas fa-trash"></i></button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-delete delete-report-btn" data-bs-toggle="tooltip" title="حذف نافذة التقرير" data-report-id="<?php echo (int) $reportWindow['id']; ?>" data-report-name="<?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
</div>

<?php
$reportWindowFields = static function (string $prefix, bool $isAdd = false) use ($academicYears, $terms, $months, $currentAcademicYearId, $currentAcademicYearName, $reportTypeLabels): void {
    $id = static fn(string $name): string => $prefix . ucfirst($name);
?>
    <div class="row g-3">
        <?php if ($isAdd): ?>
            <div class="col-md-6"><label class="form-label">العام الدراسي</label><?php if ($currentAcademicYearId > 0): ?><input type="hidden" name="academic_year_id" value="<?php echo (int) $currentAcademicYearId; ?>"><div class="form-control bg-light"><?php echo htmlspecialchars($currentAcademicYearName, ENT_QUOTES, 'UTF-8'); ?></div><?php else: ?><select name="academic_year_id" id="<?php echo $id('year'); ?>" class="form-select report-year-select" required><option value="">اختر العام</option><?php foreach ($academicYears as $year): ?><option value="<?php echo (int) $year['id']; ?>"><?php echo htmlspecialchars($year['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label">الترم</label><select name="term_id" id="<?php echo $id('term'); ?>" class="form-select report-term-select"><option value="">كل الترمات</option><?php foreach ($terms as $term): ?><option value="<?php echo (int) $term['id']; ?>" data-year="<?php echo (int) $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['name'] . ' - ' . $term['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
            <div class="col-12"><div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>العام/الترم ثابتان لهذه النافذة: <span class="fw-bold" id="<?php echo $id('period'); ?>"></span></div></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">الشهر الدراسي</label><select id="<?php echo $id('month'); ?>" class="form-select report-month-select" data-prefix="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>"><option value="">تحديد يدوي للفترة</option><?php foreach ($months as $month): ?><option value="<?php echo (int) $month['id']; ?>" data-year="<?php echo (int) $month['academic_year_id']; ?>" data-term="<?php echo (int) $month['term_id']; ?>" data-start="<?php echo htmlspecialchars((string) ($month['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-end="<?php echo htmlspecialchars((string) ($month['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($month['name'] . ' - ' . $month['term_name'] . ' - ' . $month['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><div class="form-text">اختيار الشهر يملأ فترة التقرير تلقائيا ويمكن تعديل التاريخ بعدها.</div></div>
        <div class="col-md-6"><label class="form-label">اسم التقرير</label><input type="text" name="name" id="<?php echo $id('name'); ?>" class="form-control" maxlength="190" required></div>
        <div class="col-md-6"><label class="form-label">نوع التقرير</label><select name="report_type" id="<?php echo $id('type'); ?>" class="form-select"><?php foreach ($reportTypeLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">من تاريخ</label><input type="text" name="date_from" id="<?php echo $id('dateFrom'); ?>" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
        <div class="col-md-6"><label class="form-label">إلى تاريخ</label><input type="text" name="date_to" id="<?php echo $id('dateTo'); ?>" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
        <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="include_details" id="<?php echo $id('details'); ?>" value="1" checked><span class="form-check-label">إظهار تفاصيل البنود للطالب</span></label></div>
        <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="include_absence" id="<?php echo $id('absence'); ?>" value="1" checked><span class="form-check-label">إظهار الغياب داخل التقرير</span></label></div>
        <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="include_teacher_notes" id="<?php echo $id('notes'); ?>" value="1"><span class="form-check-label">إظهار ملاحظات المعلم</span></label></div>
        <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="freeze_on_publish" id="<?php echo $id('freeze'); ?>" value="1" checked><span class="form-check-label">تجميد النسخ المنشورة عند إعادة النشر</span></label></div>
    </div>
<?php
};
?>

<div class="modal fade" id="addReportWindowModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_report_window"><input type="hidden" name="active_tab" value="windows"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إنشاء نافذة تقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php $reportWindowFields('add', true); ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إنشاء</button></div></form></div></div></div>
<div class="modal fade" id="editReportWindowModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="update_report_window"><input type="hidden" name="active_tab" value="windows"><input type="hidden" name="report_window_id" id="editReportId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل نافذة التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php $reportWindowFields('edit', false); ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div></form></div></div></div>

<div class="modal fade" id="addReportItemModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_report_item"><input type="hidden" name="active_tab" value="items"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-list-check me-2"></i>تخصيص محتوى التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-4"><label class="form-label">نافذة التقرير</label><select name="report_window_id" id="itemReportWindow" class="form-select" required><option value="">اختر التقرير</option><?php foreach ($reportWindows as $reportWindow): ?><option value="<?php echo (int) $reportWindow['id']; ?>" data-year="<?php echo (int) $reportWindow['academic_year_id']; ?>" data-term="<?php echo !empty($reportWindow['term_id']) ? (int) $reportWindow['term_id'] : ''; ?>"><?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">المادة</label><select name="subject_id" id="itemSubject" class="form-select"><option value="">كل المواد</option><?php foreach ($subjects as $subject): ?><option value="<?php echo (int) $subject['id']; ?>"><?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">الخطة</label><select name="scheme_id" id="itemScheme" class="form-select"><option value="">كل الخطط</option><?php foreach ($schemes as $scheme): ?><option value="<?php echo (int) $scheme['id']; ?>" data-year="<?php echo (int) $scheme['academic_year_id']; ?>" data-term="<?php echo (int) $scheme['term_id']; ?>" data-subject="<?php echo (int) $scheme['subject_id']; ?>"><?php echo htmlspecialchars($scheme['subject_name'] . ' - ' . $scheme['grade_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">البند</label><select name="component_id" id="itemComponent" class="form-select"><option value="">كل البنود</option><?php foreach ($components as $component): ?><option value="<?php echo (int) $component['id']; ?>" data-year="<?php echo (int) $component['academic_year_id']; ?>" data-term="<?php echo (int) $component['term_id']; ?>" data-subject="<?php echo (int) $component['subject_id']; ?>" data-scheme="<?php echo (int) $component['scheme_id']; ?>"><?php echo htmlspecialchars($component['name'] . ' - ' . $component['scheme_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">الأسبوع</label><select name="week_id" id="itemWeek" class="form-select"><option value="">كل الأسابيع</option><?php foreach ($weeks as $week): ?><option value="<?php echo (int) $week['id']; ?>" data-year="<?php echo (int) $week['academic_year_id']; ?>" data-term="<?php echo (int) $week['term_id']; ?>"><?php echo htmlspecialchars($week['name'] . ' - ' . ($week['month_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">الترتيب</label><input type="number" name="sort_order" class="form-control" min="0" max="999" value="0"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i>إضافة</button></div></form></div></div></div>

<div class="modal fade" id="editReportItemModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="update_report_item"><input type="hidden" name="active_tab" value="items"><input type="hidden" name="report_item_id" id="editItemId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل محتوى التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-4"><label class="form-label">نافذة التقرير</label><select name="report_window_id" id="editItemReportWindow" class="form-select" required><option value="">اختر التقرير</option><?php foreach ($reportWindows as $reportWindow): ?><option value="<?php echo (int) $reportWindow['id']; ?>" data-year="<?php echo (int) $reportWindow['academic_year_id']; ?>" data-term="<?php echo !empty($reportWindow['term_id']) ? (int) $reportWindow['term_id'] : ''; ?>"><?php echo htmlspecialchars($reportWindow['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">المادة</label><select name="subject_id" id="editItemSubject" class="form-select"><option value="">كل المواد</option><?php foreach ($subjects as $subject): ?><option value="<?php echo (int) $subject['id']; ?>"><?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">الخطة</label><select name="scheme_id" id="editItemScheme" class="form-select"><option value="">كل الخطط</option><?php foreach ($schemes as $scheme): ?><option value="<?php echo (int) $scheme['id']; ?>" data-year="<?php echo (int) $scheme['academic_year_id']; ?>" data-term="<?php echo (int) $scheme['term_id']; ?>" data-subject="<?php echo (int) $scheme['subject_id']; ?>"><?php echo htmlspecialchars($scheme['subject_name'] . ' - ' . $scheme['grade_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">البند</label><select name="component_id" id="editItemComponent" class="form-select"><option value="">كل البنود</option><?php foreach ($components as $component): ?><option value="<?php echo (int) $component['id']; ?>" data-year="<?php echo (int) $component['academic_year_id']; ?>" data-term="<?php echo (int) $component['term_id']; ?>" data-subject="<?php echo (int) $component['subject_id']; ?>" data-scheme="<?php echo (int) $component['scheme_id']; ?>"><?php echo htmlspecialchars($component['name'] . ' - ' . $component['scheme_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">الأسبوع</label><select name="week_id" id="editItemWeek" class="form-select"><option value="">كل الأسابيع</option><?php foreach ($weeks as $week): ?><option value="<?php echo (int) $week['id']; ?>" data-year="<?php echo (int) $week['academic_year_id']; ?>" data-term="<?php echo (int) $week['term_id']; ?>"><?php echo htmlspecialchars($week['name'] . ' - ' . ($week['month_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">الترتيب</label><input type="number" name="sort_order" id="editItemSortOrder" class="form-control" min="0" max="999" value="0"></div><div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="include_item" id="editItemInclude" value="1" checked><span class="form-check-label">إظهار هذا العنصر داخل التقرير</span></label></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div></form></div></div></div>

<div class="modal fade" id="publishReportModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="publish_report_window"><input type="hidden" name="active_tab" value="windows"><input type="hidden" name="report_window_id" id="publishReportId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>نشر التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="text-center">نشر تقرير <span id="publishReportName" class="fw-bold text-primary"></span> للطلاب؟</p><label class="form-label">نطاق النشر</label><select name="class_id" class="form-select"><option value="">كل الطلاب</option><?php foreach ($classes as $class): ?><option value="<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-paper-plane me-1"></i>نشر</button></div></form></div></div></div>
<div class="modal fade" id="hideReportModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="hide_report_window"><input type="hidden" name="active_tab" value="windows"><input type="hidden" name="report_window_id" id="hideReportId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye-slash me-2"></i>إخفاء تقرير الطلاب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><i class="fas fa-eye-slash text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد إخفاء تقرير <span id="hideReportName" class="fw-bold text-primary"></span> عن الطلاب؟</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-eye-slash me-1"></i>إخفاء</button></div></form></div></div></div>
<div class="modal fade" id="unpublishReportModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="unpublish_report_window"><input type="hidden" name="active_tab" value="windows"><input type="hidden" name="report_window_id" id="unpublishReportId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-circle-xmark me-2"></i>إلغاء النشر وحذف النسخ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-circle-xmark text-danger admin-modal-icon-lg"></i></div><p class="text-center">سيتم حذف <span id="unpublishReportCount" class="fw-bold text-danger">0</span> نسخة طالب منشورة من تقرير <span id="unpublishReportName" class="fw-bold text-primary"></span>.</p><div class="alert alert-danger"><i class="fas fa-triangle-exclamation me-2"></i>سيُحذف محتوى النسخ وتفاصيلها، وتصبح النافذة غير منشورة. لا يؤثر ذلك في درجات الطلاب الأصلية.</div><div class="alert alert-info mb-0"><i class="fas fa-rotate-left me-2"></i>يمكن التراجع فورًا من إشعار النظام ما لم تُعد نشر التقرير أو يحدث تعارض لاحق.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-file-circle-xmark me-1"></i>إلغاء النشر وحذف النسخ</button></div></form></div></div></div>
<div class="modal fade" id="deleteReportModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_report_window"><input type="hidden" name="active_tab" value="windows"><input type="hidden" name="report_window_id" id="deleteReportId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف نافذة التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف نافذة <span id="deleteReportName" class="fw-bold text-primary"></span>؟</p><div class="alert alert-warning text-start">سيمنع النظام الحذف إذا كانت لها نسخ منشورة للطلاب.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div></form></div></div></div>
<div class="modal fade" id="toggleReportItemModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_report_item"><input type="hidden" name="active_tab" value="items"><input type="hidden" name="report_item_id" id="toggleItemId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-ban me-2"></i>تغيير حالة عنصر التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><i class="fas fa-list-check text-warning mb-3 admin-modal-icon-lg"></i><p>هل تريد <span id="toggleItemAction" class="fw-bold"></span> <span id="toggleItemName" class="fw-bold text-primary"></span>؟</p><div class="alert alert-info text-start"><i class="fas fa-circle-info me-1"></i>التعطيل يخفي هذا العنصر من محتوى التقرير بدون حذف بياناته.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>تأكيد</button></div></form></div></div></div>
<div class="modal fade" id="removeReportItemModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_reports.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="remove_report_item"><input type="hidden" name="active_tab" value="items"><input type="hidden" name="report_item_id" id="removeItemId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>إزالة عنصر من التقرير</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><i class="fas fa-list-check text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد إزالة <span id="removeItemName" class="fw-bold text-primary"></span> من محتوى التقرير؟</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>إزالة</button></div></form></div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) { const el = document.getElementById(id); if (el && window.bootstrap) new bootstrap.Modal(el).show(); }
    function setValue(id, value) { const el = document.getElementById(id); if (el) el.value = value || ''; }
    function setChecked(id, value) { const el = document.getElementById(id); if (el) el.checked = value === '1'; }
    const addItemFilterConfig = { report: 'itemReportWindow', subject: 'itemSubject', scheme: 'itemScheme', component: 'itemComponent', week: 'itemWeek' };
    const editItemFilterConfig = { report: 'editItemReportWindow', subject: 'editItemSubject', scheme: 'editItemScheme', component: 'editItemComponent', week: 'editItemWeek' };
    function selectedOption(select) {
        return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    }
    function setOptionVisible(option, visible) {
        option.hidden = !visible;
        option.style.display = visible ? '' : 'none';
    }
    function resetHiddenSelection(select) {
        if (!select || !select.value) return;
        const selected = selectedOption(select);
        if (selected && selected.hidden) {
            select.value = '';
        }
    }
    function matchesReportContext(option, yearId, termId) {
        if (!option.value) return true;
        if (yearId && option.dataset.year !== yearId) return false;
        if (termId && option.dataset.term !== termId) return false;
        return true;
    }
    function applyReportItemFilters(config) {
        const reportSelect = document.getElementById(config.report);
        const subjectSelect = document.getElementById(config.subject);
        const schemeSelect = document.getElementById(config.scheme);
        const componentSelect = document.getElementById(config.component);
        const weekSelect = document.getElementById(config.week);
        const reportOption = selectedOption(reportSelect);
        const yearId = reportOption ? (reportOption.dataset.year || '') : '';
        const termId = reportOption ? (reportOption.dataset.term || '') : '';
        const subjectId = subjectSelect ? subjectSelect.value : '';

        if (schemeSelect) {
            schemeSelect.querySelectorAll('option').forEach(function (option) {
                const visible = matchesReportContext(option, yearId, termId) && (!subjectId || !option.value || option.dataset.subject === subjectId);
                setOptionVisible(option, visible);
            });
            resetHiddenSelection(schemeSelect);
        }

        const schemeId = schemeSelect ? schemeSelect.value : '';
        if (componentSelect) {
            componentSelect.querySelectorAll('option').forEach(function (option) {
                const visible = matchesReportContext(option, yearId, termId)
                    && (!subjectId || !option.value || option.dataset.subject === subjectId)
                    && (!schemeId || !option.value || option.dataset.scheme === schemeId);
                setOptionVisible(option, visible);
            });
            resetHiddenSelection(componentSelect);
        }

        if (weekSelect) {
            weekSelect.querySelectorAll('option').forEach(function (option) {
                setOptionVisible(option, matchesReportContext(option, yearId, termId));
            });
            resetHiddenSelection(weekSelect);
        }
    }
    [addItemFilterConfig, editItemFilterConfig].forEach(function (config) {
        [config.report, config.subject, config.scheme].forEach(function (id) {
            const select = document.getElementById(id);
            if (select) {
                select.addEventListener('change', function () {
                    applyReportItemFilters(config);
                });
            }
        });
        applyReportItemFilters(config);
    });
    function applyReportMonth(monthSelect) {
        const selected = monthSelect.options[monthSelect.selectedIndex];
        if (!selected) return;
        const prefix = monthSelect.dataset.prefix || '';
        const termSelect = document.getElementById(prefix + 'Term');
        const dateFrom = document.getElementById(prefix + 'DateFrom');
        const dateTo = document.getElementById(prefix + 'DateTo');
        if (termSelect && selected.dataset.term) {
            termSelect.value = selected.dataset.term;
        }
        if (dateFrom && selected.dataset.start) {
            dateFrom.value = selected.dataset.start;
        }
        if (dateTo && selected.dataset.end) {
            dateTo.value = selected.dataset.end;
        }
    }
    document.querySelectorAll('.report-month-select').forEach(function (select) {
        select.addEventListener('change', function () {
            applyReportMonth(this);
        });
    });
    document.querySelectorAll('.edit-report-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('editReportId', this.dataset.reportId); setValue('editName', this.dataset.reportName); setValue('editType', this.dataset.reportType); setValue('editDateFrom', this.dataset.reportDateFrom); setValue('editDateTo', this.dataset.reportDateTo); setValue('editMonth', ''); setChecked('editDetails', this.dataset.reportIncludeDetails); setChecked('editAbsence', this.dataset.reportIncludeAbsence); setChecked('editNotes', this.dataset.reportIncludeNotes); setChecked('editFreeze', this.dataset.reportFreeze); document.getElementById('editPeriod').textContent = this.dataset.reportPeriod || ''; showModal('editReportWindowModal'); }); });
    document.querySelectorAll('.publish-report-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('publishReportId', this.dataset.reportId); document.getElementById('publishReportName').textContent = this.dataset.reportName || ''; showModal('publishReportModal'); }); });
    document.querySelectorAll('.hide-report-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('hideReportId', this.dataset.reportId); document.getElementById('hideReportName').textContent = this.dataset.reportName || ''; showModal('hideReportModal'); }); });
    document.querySelectorAll('.unpublish-report-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('unpublishReportId', this.dataset.reportId); document.getElementById('unpublishReportName').textContent = this.dataset.reportName || ''; document.getElementById('unpublishReportCount').textContent = this.dataset.publishedCount || '0'; showModal('unpublishReportModal'); }); });
    document.querySelectorAll('.delete-report-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('deleteReportId', this.dataset.reportId); document.getElementById('deleteReportName').textContent = this.dataset.reportName || ''; showModal('deleteReportModal'); }); });
    document.querySelectorAll('.edit-item-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('editItemId', this.dataset.itemId); setValue('editItemReportWindow', this.dataset.reportWindowId); setValue('editItemSubject', this.dataset.subjectId); setValue('editItemScheme', this.dataset.schemeId); setValue('editItemComponent', this.dataset.componentId); setValue('editItemWeek', this.dataset.weekId); setValue('editItemSortOrder', this.dataset.sortOrder); setChecked('editItemInclude', this.dataset.includeItem); applyReportItemFilters(editItemFilterConfig); showModal('editReportItemModal'); }); });
    document.querySelectorAll('.toggle-item-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('toggleItemId', this.dataset.itemId); document.getElementById('toggleItemName').textContent = this.dataset.itemName || ''; document.getElementById('toggleItemAction').textContent = this.dataset.currentStatus === '1' ? 'تعطيل' : 'تفعيل'; showModal('toggleReportItemModal'); }); });
    document.querySelectorAll('.remove-item-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('removeItemId', this.dataset.itemId); document.getElementById('removeItemName').textContent = this.dataset.itemName || ''; showModal('removeReportItemModal'); }); });
});
</script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
