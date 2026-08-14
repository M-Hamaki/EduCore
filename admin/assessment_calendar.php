<?php
$page_title = "التقويم";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function calendar_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function calendar_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function calendar_count(PDO $db, string $table): int
{
    return calendar_table_exists($db, $table) ? (int) $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() : 0;
}

function calendar_redirect(string $tab): void
{
    header('Location: assessment_calendar.php?tab=' . urlencode($tab));
    exit();
}

function calendar_assert_date(?string $value, string $label): string
{
    $value = trim((string) $value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . ' غير صحيح.');
    }
    return $value;
}

function calendar_assert_selected_year(?int $currentAcademicYearId, array $row, string $message): void
{
    if ($currentAcademicYearId && (int) ($row['academic_year_id'] ?? 0) !== $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

$activeTab = $_GET['tab'] ?? 'terms';
$validTabs = ['terms', 'months', 'weeks'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'terms';
}

$calendarReady = calendar_table_exists($db, 'academic_years')
    && calendar_table_exists($db, 'academic_terms')
    && calendar_table_exists($db, 'academic_weeks');
$monthsReady = calendar_table_exists($db, 'academic_months')
    && calendar_column_exists($db, 'academic_weeks', 'month_id');

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$calendarReady) {
            throw new RuntimeException('لم يتم تطبيق جداول تقويم محرك الدرجات بعد.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_term') {
            $academicYearId = $currentAcademicYearId > 0 ? $currentAcademicYearId : (int) ($_POST['academic_year_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $termOrder = (int) ($_POST['term_order'] ?? 1);
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $status = in_array(($_POST['status'] ?? 'active'), ['active', 'inactive', 'archived'], true) ? (string) $_POST['status'] : 'active';

            if ($academicYearId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر العام الدراسي واكتب اسم الترم.');
            }
            if ($termOrder < 1 || $termOrder > 4) {
                throw new InvalidArgumentException('ترتيب الترم يجب أن يكون بين 1 و 4.');
            }
            $startDate = $startDate !== '' ? calendar_assert_date($startDate, 'تاريخ بداية الترم') : null;
            $endDate = $endDate !== '' ? calendar_assert_date($endDate, 'تاريخ نهاية الترم') : null;
            if ($startDate && $endDate && $startDate > $endDate) {
                throw new InvalidArgumentException('تاريخ بداية الترم يجب أن يكون قبل تاريخ النهاية.');
            }

            $duplicateStmt = $db->prepare('SELECT id FROM academic_terms WHERE academic_year_id = ? AND term_order = ? LIMIT 1');
            $duplicateStmt->execute([$academicYearId, $termOrder]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('ترتيب الترم مستخدم بالفعل داخل نفس العام.');
            }

            $stmt = $db->prepare("INSERT INTO academic_terms (academic_year_id, name, term_order, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$academicYearId, $name, $termOrder, $startDate, $endDate, $status]);
            $termId = (int) $db->lastInsertId();
            ActivityLog::logCreate('academic_term', $termId, $name, [
                'academic_year' => $academicYearId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
            ]);
            $_SESSION['success_message'] = 'تم إضافة الترم الدراسي بنجاح.';
            calendar_redirect('terms');
        }

        if ($action === 'update_term') {
            $termId = (int) ($_POST['term_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $termOrder = (int) ($_POST['term_order'] ?? 1);
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $status = in_array(($_POST['status'] ?? 'active'), ['active', 'inactive', 'archived'], true) ? (string) $_POST['status'] : 'active';

            $termStmt = $db->prepare('SELECT * FROM academic_terms WHERE id = ? LIMIT 1');
            $termStmt->execute([$termId]);
            $oldTerm = $termStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldTerm || $name === '') {
                throw new InvalidArgumentException('الترم المحدد غير موجود أو الاسم فارغ.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $oldTerm, 'لا يمكن تعديل ترم خارج العام الدراسي المختار.');
            if ($termOrder < 1 || $termOrder > 4) {
                throw new InvalidArgumentException('ترتيب الترم يجب أن يكون بين 1 و 4.');
            }
            $startDate = $startDate !== '' ? calendar_assert_date($startDate, 'تاريخ بداية الترم') : null;
            $endDate = $endDate !== '' ? calendar_assert_date($endDate, 'تاريخ نهاية الترم') : null;
            if ($startDate && $endDate && $startDate > $endDate) {
                throw new InvalidArgumentException('تاريخ بداية الترم يجب أن يكون قبل تاريخ النهاية.');
            }

            $duplicateStmt = $db->prepare('SELECT id FROM academic_terms WHERE academic_year_id = ? AND term_order = ? AND id <> ? LIMIT 1');
            $duplicateStmt->execute([(int) $oldTerm['academic_year_id'], $termOrder, $termId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('ترتيب الترم مستخدم بالفعل داخل نفس العام.');
            }

            $stmt = $db->prepare('UPDATE academic_terms SET name = ?, term_order = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?');
            $stmt->execute([$name, $termOrder, $startDate, $endDate, $status, $termId]);
            ActivityLog::logUpdate('academic_term', $termId, $name, ['old_name' => $oldTerm['name'], 'new_name' => $name, 'status' => $status]);
            $_SESSION['success_message'] = 'تم تعديل الترم الدراسي بنجاح.';
            calendar_redirect('terms');
        }

        if ($action === 'toggle_term') {
            $termId = (int) ($_POST['term_id'] ?? 0);
            $termStmt = $db->prepare('SELECT name, status, academic_year_id FROM academic_terms WHERE id = ? LIMIT 1');
            $termStmt->execute([$termId]);
            $term = $termStmt->fetch(PDO::FETCH_ASSOC);
            if (!$term) {
                throw new InvalidArgumentException('الترم المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $term, 'لا يمكن تغيير حالة ترم خارج العام الدراسي المختار.');
            $newStatus = ($term['status'] ?? '') === 'active' ? 'inactive' : 'active';
            $db->prepare('UPDATE academic_terms SET status = ? WHERE id = ?')->execute([$newStatus, $termId]);
            ActivityLog::logUpdate('academic_term', $termId, (string) $term['name'], ['new_status' => $newStatus]);
            $_SESSION['success_message'] = $newStatus === 'active' ? 'تم تفعيل الترم الدراسي.' : 'تم تعطيل الترم الدراسي.';
            calendar_redirect('terms');
        }

        if ($action === 'delete_term') {
            $termId = (int) ($_POST['term_id'] ?? 0);
            $termStmt = $db->prepare('SELECT * FROM academic_terms WHERE id = ? LIMIT 1');
            $termStmt->execute([$termId]);
            $term = $termStmt->fetch(PDO::FETCH_ASSOC);
            if (!$term) {
                throw new InvalidArgumentException('الترم المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $term, 'لا يمكن حذف ترم خارج العام الدراسي المختار.');
            $dependencyChecks = [
                ['academic_months', 'term_id'],
                ['academic_weeks', 'term_id'],
                ['subject_grade_assignments', 'term_id'],
                ['teacher_subject_assignments', 'term_id'],
                ['assessment_schemes', 'term_id'],
                ['report_windows', 'term_id'],
            ];
            $dependencies = 0;
            foreach ($dependencyChecks as $check) {
                [$table, $column] = $check;
                if (calendar_table_exists($db, $table)) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
                    $stmt->execute([$termId]);
                    $dependencies += (int) $stmt->fetchColumn();
                }
            }
            if ($dependencies > 0) {
                throw new RuntimeException('لا يمكن حذف الترم لوجود بيانات مرتبطة به. يمكن تعطيله أو أرشفته بدلا من الحذف.');
            }
            $db->prepare('DELETE FROM academic_terms WHERE id = ?')->execute([$termId]);
            ActivityLog::logDelete('academic_term', $termId, (string) $term['name'], ['academic_year' => $term['academic_year_id']]);
            $_SESSION['success_message'] = 'تم حذف الترم الدراسي بنجاح.';
            calendar_redirect('terms');
        }

        if ($action === 'add_month') {
            if (!$monthsReady) {
                throw new RuntimeException('لم يتم تطبيق جدول الشهور الدراسية بعد.');
            }
            $termId = (int) ($_POST['term_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $monthOrder = (int) ($_POST['month_order'] ?? 1);
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $monthType = in_array(($_POST['month_type'] ?? 'study'), ['study', 'holiday', 'exam', 'custom'], true) ? (string) $_POST['month_type'] : 'study';
            $status = in_array(($_POST['status'] ?? 'active'), ['active', 'inactive', 'archived'], true) ? (string) $_POST['status'] : 'active';
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($termId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر الترم واكتب اسم الشهر.');
            }
            if ($monthOrder < 1 || $monthOrder > 24) {
                throw new InvalidArgumentException('ترتيب الشهر غير صحيح.');
            }
            $startDate = $startDate !== '' ? calendar_assert_date($startDate, 'تاريخ بداية الشهر') : null;
            $endDate = $endDate !== '' ? calendar_assert_date($endDate, 'تاريخ نهاية الشهر') : null;
            if ($startDate && $endDate && $startDate > $endDate) {
                throw new InvalidArgumentException('تاريخ بداية الشهر يجب أن يكون قبل تاريخ النهاية.');
            }

            $termStmt = $db->prepare('SELECT academic_year_id, name, start_date, end_date FROM academic_terms WHERE id = ? LIMIT 1');
            $termStmt->execute([$termId]);
            $term = $termStmt->fetch(PDO::FETCH_ASSOC);
            if (!$term) {
                throw new InvalidArgumentException('الترم المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $term, 'لا يمكن إضافة شهر داخل ترم خارج العام الدراسي المختار.');
            if (!empty($term['start_date']) && $startDate && $startDate < $term['start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الشهر خارج نطاق بداية الترم.');
            }
            if (!empty($term['end_date']) && $endDate && $endDate > $term['end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الشهر خارج نطاق نهاية الترم.');
            }

            $duplicateStmt = $db->prepare('SELECT id FROM academic_months WHERE term_id = ? AND (name = ? OR month_order = ?) LIMIT 1');
            $duplicateStmt->execute([$termId, $name, $monthOrder]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('يوجد شهر بنفس الاسم أو الترتيب داخل هذا الترم.');
            }

            $stmt = $db->prepare("INSERT INTO academic_months
                (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([(int) $term['academic_year_id'], $termId, $name, $monthOrder, $startDate, $endDate, $monthType, $status, $notes !== '' ? $notes : null, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
            $monthId = (int) $db->lastInsertId();
            ActivityLog::logCreate('academic_month', $monthId, $name, ['term' => $term['name'], 'start_date' => $startDate, 'end_date' => $endDate, 'status' => $status]);
            $_SESSION['success_message'] = 'تم إضافة الشهر الدراسي بنجاح.';
            calendar_redirect('months');
        }

        if ($action === 'update_month') {
            if (!$monthsReady) {
                throw new RuntimeException('لم يتم تطبيق جدول الشهور الدراسية بعد.');
            }
            $monthId = (int) ($_POST['month_id'] ?? 0);
            $termId = (int) ($_POST['term_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $monthOrder = (int) ($_POST['month_order'] ?? 1);
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $monthType = in_array(($_POST['month_type'] ?? 'study'), ['study', 'holiday', 'exam', 'custom'], true) ? (string) $_POST['month_type'] : 'study';
            $status = in_array(($_POST['status'] ?? 'active'), ['active', 'inactive', 'archived'], true) ? (string) $_POST['status'] : 'active';
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $oldStmt = $db->prepare('SELECT * FROM academic_months WHERE id = ? LIMIT 1');
            $oldStmt->execute([$monthId]);
            $oldMonth = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldMonth || $termId <= 0 || $name === '') {
                throw new InvalidArgumentException('الشهر المحدد غير موجود أو بياناته غير مكتملة.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $oldMonth, 'لا يمكن تعديل شهر خارج العام الدراسي المختار.');
            if ($monthOrder < 1 || $monthOrder > 24) {
                throw new InvalidArgumentException('ترتيب الشهر غير صحيح.');
            }
            $startDate = $startDate !== '' ? calendar_assert_date($startDate, 'تاريخ بداية الشهر') : null;
            $endDate = $endDate !== '' ? calendar_assert_date($endDate, 'تاريخ نهاية الشهر') : null;
            if ($startDate && $endDate && $startDate > $endDate) {
                throw new InvalidArgumentException('تاريخ بداية الشهر يجب أن يكون قبل تاريخ النهاية.');
            }

            $termStmt = $db->prepare('SELECT academic_year_id, start_date, end_date FROM academic_terms WHERE id = ? LIMIT 1');
            $termStmt->execute([$termId]);
            $term = $termStmt->fetch(PDO::FETCH_ASSOC);
            if (!$term) {
                throw new InvalidArgumentException('الترم المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $term, 'لا يمكن نقل شهر إلى ترم خارج العام الدراسي المختار.');
            if (!empty($term['start_date']) && $startDate && $startDate < $term['start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الشهر خارج نطاق بداية الترم.');
            }
            if (!empty($term['end_date']) && $endDate && $endDate > $term['end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الشهر خارج نطاق نهاية الترم.');
            }

            $duplicateStmt = $db->prepare('SELECT id FROM academic_months WHERE term_id = ? AND (name = ? OR month_order = ?) AND id <> ? LIMIT 1');
            $duplicateStmt->execute([$termId, $name, $monthOrder, $monthId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('يوجد شهر بنفس الاسم أو الترتيب داخل هذا الترم.');
            }

            $stmt = $db->prepare('UPDATE academic_months SET academic_year_id = ?, term_id = ?, name = ?, month_order = ?, start_date = ?, end_date = ?, month_type = ?, status = ?, notes = ? WHERE id = ?');
            $stmt->execute([(int) $term['academic_year_id'], $termId, $name, $monthOrder, $startDate, $endDate, $monthType, $status, $notes !== '' ? $notes : null, $monthId]);
            $db->prepare('UPDATE academic_weeks SET academic_year_id = ?, term_id = ?, month_label = ? WHERE month_id = ?')->execute([(int) $term['academic_year_id'], $termId, $name, $monthId]);
            ActivityLog::logUpdate('academic_month', $monthId, $name, ['old_name' => $oldMonth['name'], 'new_name' => $name, 'status' => $status]);
            $_SESSION['success_message'] = 'تم تعديل الشهر الدراسي بنجاح.';
            calendar_redirect('months');
        }

        if ($action === 'toggle_month') {
            if (!$monthsReady) {
                throw new RuntimeException('لم يتم تطبيق جدول الشهور الدراسية بعد.');
            }
            $monthId = (int) ($_POST['month_id'] ?? 0);
            $monthStmt = $db->prepare('SELECT name, status, academic_year_id FROM academic_months WHERE id = ? LIMIT 1');
            $monthStmt->execute([$monthId]);
            $month = $monthStmt->fetch(PDO::FETCH_ASSOC);
            if (!$month) {
                throw new InvalidArgumentException('الشهر المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $month, 'لا يمكن تغيير حالة شهر خارج العام الدراسي المختار.');
            $newStatus = ($month['status'] ?? '') === 'active' ? 'inactive' : 'active';
            $db->prepare('UPDATE academic_months SET status = ? WHERE id = ?')->execute([$newStatus, $monthId]);
            ActivityLog::logUpdate('academic_month', $monthId, (string) $month['name'], ['new_status' => $newStatus]);
            $_SESSION['success_message'] = $newStatus === 'active' ? 'تم تفعيل الشهر الدراسي.' : 'تم تعطيل الشهر الدراسي.';
            calendar_redirect('months');
        }

        if ($action === 'delete_month') {
            if (!$monthsReady) {
                throw new RuntimeException('لم يتم تطبيق جدول الشهور الدراسية بعد.');
            }
            $monthId = (int) ($_POST['month_id'] ?? 0);
            $monthStmt = $db->prepare('SELECT * FROM academic_months WHERE id = ? LIMIT 1');
            $monthStmt->execute([$monthId]);
            $month = $monthStmt->fetch(PDO::FETCH_ASSOC);
            if (!$month) {
                throw new InvalidArgumentException('الشهر المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $month, 'لا يمكن حذف شهر خارج العام الدراسي المختار.');
            $weekStmt = $db->prepare('SELECT COUNT(*) FROM academic_weeks WHERE month_id = ?');
            $weekStmt->execute([$monthId]);
            if ((int) $weekStmt->fetchColumn() > 0) {
                throw new RuntimeException('لا يمكن حذف الشهر لأن بداخله أسابيع. احذف أو انقل الأسابيع أولا، أو عطّله بدلا من الحذف.');
            }
            $db->prepare('DELETE FROM academic_months WHERE id = ?')->execute([$monthId]);
            ActivityLog::logDelete('academic_month', $monthId, (string) $month['name'], ['term_id' => $month['term_id']]);
            $_SESSION['success_message'] = 'تم حذف الشهر الدراسي بنجاح.';
            calendar_redirect('months');
        }

        if ($action === 'copy_month') {
            if (!$monthsReady) {
                throw new RuntimeException('لم يتم تطبيق جدول الشهور الدراسية بعد.');
            }
            $sourceMonthId = (int) ($_POST['source_month_id'] ?? 0);
            $targetTermId = (int) ($_POST['target_term_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $monthOrder = (int) ($_POST['month_order'] ?? 1);
            $targetStartDate = calendar_assert_date($_POST['start_date'] ?? '', 'تاريخ بداية الشهر الجديد');
            $targetEndInput = trim((string) ($_POST['end_date'] ?? ''));
            $targetEndDate = $targetEndInput !== '' ? calendar_assert_date($targetEndInput, 'تاريخ نهاية الشهر الجديد') : null;
            $firstWeekOrder = (int) ($_POST['first_week_order'] ?? 1);

            if ($sourceMonthId <= 0 || $targetTermId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر الشهر المصدر والترم الهدف واكتب اسم الشهر الجديد.');
            }
            if ($monthOrder < 1 || $monthOrder > 24 || $firstWeekOrder < 1 || $firstWeekOrder > 60) {
                throw new InvalidArgumentException('ترتيب الشهر أو أول أسبوع غير صحيح.');
            }
            if ($targetEndDate && $targetStartDate > $targetEndDate) {
                throw new InvalidArgumentException('تاريخ بداية الشهر الجديد يجب أن يكون قبل تاريخ النهاية.');
            }

            $sourceStmt = $db->prepare('SELECT * FROM academic_months WHERE id = ? LIMIT 1');
            $sourceStmt->execute([$sourceMonthId]);
            $sourceMonth = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            $targetTermStmt = $db->prepare('SELECT academic_year_id, name, start_date, end_date FROM academic_terms WHERE id = ? LIMIT 1');
            $targetTermStmt->execute([$targetTermId]);
            $targetTerm = $targetTermStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sourceMonth || !$targetTerm) {
                throw new InvalidArgumentException('الشهر المصدر أو الترم الهدف غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $sourceMonth, 'لا يمكن نسخ شهر من خارج العام الدراسي المختار.');
            calendar_assert_selected_year($currentAcademicYearId, $targetTerm, 'لا يمكن نسخ شهر إلى ترم خارج العام الدراسي المختار.');
            if (!empty($targetTerm['start_date']) && $targetStartDate < $targetTerm['start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الشهر الجديد خارج نطاق بداية الترم الهدف.');
            }

            $duplicateStmt = $db->prepare('SELECT id FROM academic_months WHERE term_id = ? AND (name = ? OR month_order = ?) LIMIT 1');
            $duplicateStmt->execute([$targetTermId, $name, $monthOrder]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('يوجد شهر بنفس الاسم أو الترتيب داخل الترم الهدف.');
            }

            $weeksStmt = $db->prepare('SELECT * FROM academic_weeks WHERE month_id = ? ORDER BY week_order, start_date, id');
            $weeksStmt->execute([$sourceMonthId]);
            $sourceWeeks = $weeksStmt->fetchAll(PDO::FETCH_ASSOC);
            $sourceAnchor = $sourceMonth['start_date'] ?: ($sourceWeeks[0]['start_date'] ?? $targetStartDate);
            $shiftDays = (int) (new DateTimeImmutable((string) $sourceAnchor))->diff(new DateTimeImmutable($targetStartDate))->format('%r%a');
            $newWeeks = [];
            $lastCopiedWeekEnd = null;

            foreach ($sourceWeeks as $index => $sourceWeek) {
                $sourceStart = new DateTimeImmutable((string) $sourceWeek['start_date']);
                $durationDays = (int) $sourceStart->diff(new DateTimeImmutable((string) $sourceWeek['end_date']))->format('%a');
                $newStart = $sourceStart->modify(($shiftDays >= 0 ? '+' : '') . $shiftDays . ' days');
                $newEnd = $newStart->modify('+' . $durationDays . ' days');
                $newStartValue = $newStart->format('Y-m-d');
                $newEndValue = $newEnd->format('Y-m-d');
                $newOrder = $firstWeekOrder + $index;

                if ($newOrder > 60) {
                    throw new InvalidArgumentException('ترتيب الأسابيع الناتج يتجاوز الحد المسموح.');
                }
                if (!empty($targetTerm['end_date']) && $newEndValue > $targetTerm['end_date']) {
                    throw new InvalidArgumentException('أحد الأسابيع المنسوخة ينتهي بعد نهاية الترم الهدف.');
                }
                if ($targetEndDate && $newEndValue > $targetEndDate) {
                    throw new InvalidArgumentException('أحد الأسابيع المنسوخة ينتهي بعد نهاية الشهر الجديد.');
                }

                $orderStmt = $db->prepare('SELECT name FROM academic_weeks WHERE term_id = ? AND week_order = ? LIMIT 1');
                $orderStmt->execute([$targetTermId, $newOrder]);
                if ($orderStmt->fetchColumn()) {
                    throw new InvalidArgumentException('ترتيب أسبوع منسوخ مستخدم بالفعل داخل الترم الهدف.');
                }
                $overlapStmt = $db->prepare('SELECT name FROM academic_weeks WHERE term_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1');
                $overlapStmt->execute([$targetTermId, $newEndValue, $newStartValue]);
                if ($overlapStmt->fetchColumn()) {
                    throw new InvalidArgumentException('أحد الأسابيع المنسوخة يتداخل مع أسبوع موجود داخل الترم الهدف.');
                }

                $newWeeks[] = [
                    'name' => $sourceWeek['name'],
                    'week_order' => $newOrder,
                    'start_date' => $newStartValue,
                    'end_date' => $newEndValue,
                    'week_type' => $sourceWeek['week_type'],
                    'counts_for_average' => (int) $sourceWeek['counts_for_average'],
                    'notes' => $sourceWeek['notes'],
                ];
                $lastCopiedWeekEnd = $newEndValue;
            }

            $monthEndForInsert = $targetEndDate ?: ($lastCopiedWeekEnd ?: $targetStartDate);
            if (!empty($targetTerm['end_date']) && $monthEndForInsert > $targetTerm['end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الشهر الجديد خارج نطاق نهاية الترم الهدف.');
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO academic_months
                    (academic_year_id, term_id, name, month_order, start_date, end_date, month_type, status, notes, copied_from_month_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([(int) $targetTerm['academic_year_id'], $targetTermId, $name, $monthOrder, $targetStartDate, $monthEndForInsert, $sourceMonth['month_type'], 'active', $sourceMonth['notes'], $sourceMonthId, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
                $newMonthId = (int) $db->lastInsertId();

                $weekInsertStmt = $db->prepare("INSERT INTO academic_weeks
                    (academic_year_id, term_id, month_id, month_label, name, week_order, start_date, end_date, week_type, counts_for_average, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($newWeeks as $newWeek) {
                    $weekInsertStmt->execute([(int) $targetTerm['academic_year_id'], $targetTermId, $newMonthId, $name, $newWeek['name'], $newWeek['week_order'], $newWeek['start_date'], $newWeek['end_date'], $newWeek['week_type'], $newWeek['counts_for_average'], $newWeek['notes']]);
                }
                ActivityLog::logCreate('academic_month', $newMonthId, $name, ['source_month' => $sourceMonth['name'], 'target_term' => $targetTerm['name'], 'copied_weeks' => count($newWeeks)]);
                $db->commit();
            } catch (Throwable $copyError) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $copyError;
            }
            $_SESSION['success_message'] = 'تم نسخ الشهر مع ' . count($newWeeks) . ' أسبوع بنجاح.';
            calendar_redirect('months');
        }

        if ($action === 'add_week') {
            $monthId = (int) ($_POST['month_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $weekOrder = (int) ($_POST['week_order'] ?? 1);
            $startDate = calendar_assert_date($_POST['start_date'] ?? '', 'تاريخ بداية الأسبوع');
            $endDate = calendar_assert_date($_POST['end_date'] ?? '', 'تاريخ نهاية الأسبوع');
            $weekType = in_array(($_POST['week_type'] ?? 'study'), ['study', 'holiday', 'exam', 'revision'], true) ? (string) $_POST['week_type'] : 'study';
            $countsForAverage = isset($_POST['counts_for_average']) ? 1 : 0;
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if (!$monthsReady || $monthId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر الشهر واكتب اسم الأسبوع.');
            }
            if ($weekOrder < 1 || $weekOrder > 60) {
                throw new InvalidArgumentException('ترتيب الأسبوع غير صحيح.');
            }
            if ($startDate > $endDate) {
                throw new InvalidArgumentException('تاريخ بداية الأسبوع يجب أن يكون قبل تاريخ النهاية.');
            }

            $monthStmt = $db->prepare('SELECT m.*, t.name AS term_name, t.start_date AS term_start_date, t.end_date AS term_end_date FROM academic_months m JOIN academic_terms t ON t.id = m.term_id WHERE m.id = ? LIMIT 1');
            $monthStmt->execute([$monthId]);
            $month = $monthStmt->fetch(PDO::FETCH_ASSOC);
            if (!$month) {
                throw new InvalidArgumentException('الشهر المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $month, 'لا يمكن إضافة أسبوع داخل شهر خارج العام الدراسي المختار.');
            if (!empty($month['term_start_date']) && $startDate < $month['term_start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الأسبوع خارج نطاق بداية الترم.');
            }
            if (!empty($month['term_end_date']) && $endDate > $month['term_end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الأسبوع خارج نطاق نهاية الترم.');
            }
            if (!empty($month['start_date']) && $startDate < $month['start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الأسبوع خارج نطاق بداية الشهر.');
            }
            if (!empty($month['end_date']) && $endDate > $month['end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الأسبوع خارج نطاق نهاية الشهر.');
            }

            $overlapStmt = $db->prepare('SELECT name FROM academic_weeks WHERE term_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1');
            $overlapStmt->execute([(int) $month['term_id'], $endDate, $startDate]);
            if ($overlapStmt->fetchColumn()) {
                throw new InvalidArgumentException('تاريخ الأسبوع يتداخل مع أسبوع آخر داخل نفس الترم.');
            }
            $orderStmt = $db->prepare('SELECT name FROM academic_weeks WHERE term_id = ? AND week_order = ? LIMIT 1');
            $orderStmt->execute([(int) $month['term_id'], $weekOrder]);
            if ($orderStmt->fetchColumn()) {
                throw new InvalidArgumentException('ترتيب الأسبوع مستخدم بالفعل داخل نفس الترم.');
            }

            $stmt = $db->prepare("INSERT INTO academic_weeks
                (academic_year_id, term_id, month_id, month_label, name, week_order, start_date, end_date, week_type, counts_for_average, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([(int) $month['academic_year_id'], (int) $month['term_id'], $monthId, $month['name'], $name, $weekOrder, $startDate, $endDate, $weekType, $countsForAverage, $notes !== '' ? $notes : null]);
            $weekId = (int) $db->lastInsertId();
            ActivityLog::logCreate('academic_week', $weekId, $name, ['term' => $month['term_name'], 'start_date' => $startDate, 'end_date' => $endDate]);
            $_SESSION['success_message'] = 'تم إضافة الأسبوع الدراسي بنجاح.';
            calendar_redirect('weeks');
        }

        if ($action === 'update_week') {
            $weekId = (int) ($_POST['week_id'] ?? 0);
            $monthId = (int) ($_POST['month_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $weekOrder = (int) ($_POST['week_order'] ?? 1);
            $startDate = calendar_assert_date($_POST['start_date'] ?? '', 'تاريخ بداية الأسبوع');
            $endDate = calendar_assert_date($_POST['end_date'] ?? '', 'تاريخ نهاية الأسبوع');
            $weekType = in_array(($_POST['week_type'] ?? 'study'), ['study', 'holiday', 'exam', 'revision'], true) ? (string) $_POST['week_type'] : 'study';
            $countsForAverage = isset($_POST['counts_for_average']) ? 1 : 0;
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $oldStmt = $db->prepare('SELECT * FROM academic_weeks WHERE id = ? LIMIT 1');
            $oldStmt->execute([$weekId]);
            $oldWeek = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldWeek || !$monthsReady || $monthId <= 0 || $name === '') {
                throw new InvalidArgumentException('الأسبوع المحدد غير موجود أو بياناته غير مكتملة.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $oldWeek, 'لا يمكن تعديل أسبوع خارج العام الدراسي المختار.');
            if ($weekOrder < 1 || $weekOrder > 60 || $startDate > $endDate) {
                throw new InvalidArgumentException('ترتيب أو تواريخ الأسبوع غير صحيحة.');
            }

            $monthStmt = $db->prepare('SELECT m.*, t.start_date AS term_start_date, t.end_date AS term_end_date FROM academic_months m JOIN academic_terms t ON t.id = m.term_id WHERE m.id = ? LIMIT 1');
            $monthStmt->execute([$monthId]);
            $month = $monthStmt->fetch(PDO::FETCH_ASSOC);
            if (!$month) {
                throw new InvalidArgumentException('الشهر المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $month, 'لا يمكن نقل أسبوع إلى شهر خارج العام الدراسي المختار.');
            if (!empty($month['term_start_date']) && $startDate < $month['term_start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الأسبوع خارج نطاق بداية الترم.');
            }
            if (!empty($month['term_end_date']) && $endDate > $month['term_end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الأسبوع خارج نطاق نهاية الترم.');
            }
            if (!empty($month['start_date']) && $startDate < $month['start_date']) {
                throw new InvalidArgumentException('تاريخ بداية الأسبوع خارج نطاق بداية الشهر.');
            }
            if (!empty($month['end_date']) && $endDate > $month['end_date']) {
                throw new InvalidArgumentException('تاريخ نهاية الأسبوع خارج نطاق نهاية الشهر.');
            }

            $overlapStmt = $db->prepare('SELECT name FROM academic_weeks WHERE term_id = ? AND start_date <= ? AND end_date >= ? AND id <> ? LIMIT 1');
            $overlapStmt->execute([(int) $month['term_id'], $endDate, $startDate, $weekId]);
            if ($overlapStmt->fetchColumn()) {
                throw new InvalidArgumentException('تاريخ الأسبوع يتداخل مع أسبوع آخر داخل نفس الترم.');
            }
            $orderStmt = $db->prepare('SELECT name FROM academic_weeks WHERE term_id = ? AND week_order = ? AND id <> ? LIMIT 1');
            $orderStmt->execute([(int) $month['term_id'], $weekOrder, $weekId]);
            if ($orderStmt->fetchColumn()) {
                throw new InvalidArgumentException('ترتيب الأسبوع مستخدم بالفعل داخل نفس الترم.');
            }

            $stmt = $db->prepare("UPDATE academic_weeks
                SET academic_year_id = ?, term_id = ?, month_id = ?, month_label = ?, name = ?, week_order = ?,
                    start_date = ?, end_date = ?, week_type = ?, counts_for_average = ?, notes = ?
                WHERE id = ?");
            $stmt->execute([(int) $month['academic_year_id'], (int) $month['term_id'], $monthId, $month['name'], $name, $weekOrder, $startDate, $endDate, $weekType, $countsForAverage, $notes !== '' ? $notes : null, $weekId]);
            ActivityLog::logUpdate('academic_week', $weekId, $name, ['old_name' => $oldWeek['name'], 'new_name' => $name, 'week_type' => $weekType]);
            $_SESSION['success_message'] = 'تم تعديل الأسبوع الدراسي بنجاح.';
            calendar_redirect('weeks');
        }

        if ($action === 'toggle_week') {
            $weekId = (int) ($_POST['week_id'] ?? 0);
            $weekStmt = $db->prepare('SELECT name, week_type, academic_year_id FROM academic_weeks WHERE id = ? LIMIT 1');
            $weekStmt->execute([$weekId]);
            $week = $weekStmt->fetch(PDO::FETCH_ASSOC);
            if (!$week) {
                throw new InvalidArgumentException('الأسبوع المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $week, 'لا يمكن تغيير حالة أسبوع خارج العام الدراسي المختار.');
            $disable = ($week['week_type'] ?? '') === 'study';
            $db->prepare('UPDATE academic_weeks SET week_type = ? WHERE id = ?')->execute([$disable ? 'holiday' : 'study', $weekId]);
            ActivityLog::logUpdate('academic_week', $weekId, (string) $week['name'], ['new_type' => $disable ? 'holiday' : 'study']);
            $_SESSION['success_message'] = $disable ? 'تم تعطيل الأسبوع وتحويله إلى عطلة.' : 'تم تفعيل الأسبوع كأسبوع دراسي.';
            calendar_redirect('weeks');
        }

        if ($action === 'delete_week') {
            $weekId = (int) ($_POST['week_id'] ?? 0);
            $weekStmt = $db->prepare('SELECT * FROM academic_weeks WHERE id = ? LIMIT 1');
            $weekStmt->execute([$weekId]);
            $week = $weekStmt->fetch(PDO::FETCH_ASSOC);
            if (!$week) {
                throw new InvalidArgumentException('الأسبوع المحدد غير موجود.');
            }
            calendar_assert_selected_year($currentAcademicYearId, $week, 'لا يمكن حذف أسبوع خارج العام الدراسي المختار.');
            $dependencyChecks = [
                ['assessment_component_week_rules', 'week_id'],
                ['assessment_windows', 'week_id'],
                ['student_marks', 'week_id'],
                ['report_window_items', 'week_id'],
                ['published_report_details', 'week_id'],
            ];
            $dependencies = 0;
            foreach ($dependencyChecks as $check) {
                [$table, $column] = $check;
                if (calendar_table_exists($db, $table)) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
                    $stmt->execute([$weekId]);
                    $dependencies += (int) $stmt->fetchColumn();
                }
            }
            if ($dependencies > 0) {
                throw new RuntimeException('لا يمكن حذف الأسبوع لوجود رصد أو نوافذ أو تقارير مرتبطة به.');
            }
            $db->prepare('DELETE FROM academic_weeks WHERE id = ?')->execute([$weekId]);
            ActivityLog::logDelete('academic_week', $weekId, (string) $week['name'], ['term_id' => $week['term_id']]);
            $_SESSION['success_message'] = 'تم حذف الأسبوع الدراسي بنجاح.';
            calendar_redirect('weeks');
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        calendar_redirect($activeTab);
    }
}

$academicYears = [];
$terms = [];
$months = [];
$weeks = [];
$monthOrderKeys = [];
$termCount = 0;
$monthCount = 0;
$weekCount = 0;
$activeTermCount = 0;
$studyWeekCount = 0;
$monthTypeLabels = ['study' => 'دراسي', 'holiday' => 'عطلة', 'exam' => 'امتحانات', 'custom' => 'مخصص'];
$weekTypeLabels = ['study' => 'دراسي', 'holiday' => 'عطلة', 'exam' => 'امتحانات', 'revision' => 'مراجعة'];

if ($calendarReady) {
    $academicYears = $db->query("SELECT id, name, is_active FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($currentAcademicYearId > 0) {
        $termStmt = $db->prepare('SELECT t.*, ay.name AS academic_year_name
            FROM academic_terms t
            JOIN academic_years ay ON ay.id = t.academic_year_id
            WHERE t.academic_year_id = ?
            ORDER BY t.term_order ASC');
        $termStmt->execute([$currentAcademicYearId]);
        $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $termCount = count($terms);
        $activeTermStmt = $db->prepare("SELECT COUNT(*) FROM academic_terms WHERE academic_year_id = ? AND status = 'active'");
        $activeTermStmt->execute([$currentAcademicYearId]);
        $activeTermCount = (int) $activeTermStmt->fetchColumn();
    } else {
        $terms = $db->query("SELECT t.*, ay.name AS academic_year_name
            FROM academic_terms t
            JOIN academic_years ay ON ay.id = t.academic_year_id
            ORDER BY ay.is_active DESC, ay.id DESC, t.term_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $termCount = calendar_count($db, 'academic_terms');
        $activeTermCount = (int) $db->query("SELECT COUNT(*) FROM academic_terms WHERE status = 'active'")->fetchColumn();
    }

    if ($monthsReady) {
        $monthSql = "SELECT m.*, t.name AS term_name, t.start_date AS term_start_date,
                t.end_date AS term_end_date, ay.name AS academic_year_name, COUNT(w.id) AS weeks_count
            FROM academic_months m
            JOIN academic_terms t ON t.id = m.term_id
            JOIN academic_years ay ON ay.id = m.academic_year_id
            LEFT JOIN academic_weeks w ON w.month_id = m.id";
        $monthParams = [];
        if ($currentAcademicYearId > 0) {
            $monthSql .= ' WHERE m.academic_year_id = ?';
            $monthParams[] = $currentAcademicYearId;
        }
        $monthSql .= ' GROUP BY m.id ORDER BY ay.is_active DESC, ay.id DESC, t.term_order ASC, m.month_order ASC';
        $monthStmt = $db->prepare($monthSql);
        $monthStmt->execute($monthParams);
        $months = $monthStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $monthCount = count($months);
        foreach ($months as $calendarMonth) {
            $monthOrderKeys[(int) $calendarMonth['term_id'] . ':' . (int) $calendarMonth['month_order']] = true;
        }
    } else {
        $monthCount = calendar_count($db, 'academic_months');
    }

    $weekSql = "SELECT w.*, m.name AS month_name, t.name AS term_name,
            t.start_date AS term_start_date, t.end_date AS term_end_date, ay.name AS academic_year_name
        FROM academic_weeks w
        LEFT JOIN academic_months m ON m.id = w.month_id
        JOIN academic_terms t ON t.id = w.term_id
        JOIN academic_years ay ON ay.id = w.academic_year_id";
    $weekParams = [];
    if ($currentAcademicYearId > 0) {
        $weekSql .= ' WHERE w.academic_year_id = ?';
        $weekParams[] = $currentAcademicYearId;
    }
    $weekSql .= ' ORDER BY ay.is_active DESC, ay.id DESC, t.term_order ASC, w.week_order ASC';
    $weekStmt = $db->prepare($weekSql);
    $weekStmt->execute($weekParams);
    $weeks = $weekStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $weekCount = count($weeks);
    $weekOrderKeys = [];
    foreach ($weeks as $calendarWeek) {
        $weekOrderKeys[(int) $calendarWeek['term_id'] . ':' . (int) $calendarWeek['week_order']] = true;
    }
    if ($currentAcademicYearId > 0) {
        $studyWeekStmt = $db->prepare("SELECT COUNT(*) FROM academic_weeks WHERE academic_year_id = ? AND week_type = 'study' AND counts_for_average = 1");
        $studyWeekStmt->execute([$currentAcademicYearId]);
        $studyWeekCount = (int) $studyWeekStmt->fetchColumn();
    } else {
        $studyWeekCount = (int) $db->query("SELECT COUNT(*) FROM academic_weeks WHERE week_type = 'study' AND counts_for_average = 1")->fetchColumn();
    }
}

$tabMeta = [
    'terms' => ['label' => 'فصل دراسي', 'plural' => 'الفصول الدراسية', 'icon' => 'fas fa-calendar-alt', 'count' => $termCount],
    'months' => ['label' => 'شهر دراسي', 'plural' => 'الشهور الدراسية', 'icon' => 'fas fa-calendar-days', 'count' => $monthCount],
    'weeks' => ['label' => 'أسبوع دراسي', 'plural' => 'الأسابيع الدراسية', 'icon' => 'fas fa-calendar-week', 'count' => $weekCount],
];
$currentTab = $tabMeta[$activeTab];

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-calendar-check me-2 text-primary"></i>التقويم</h1>
    <div class="admin-top-actions no-print">
        <?php if ($calendarReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#add<?php echo ucfirst($activeTab); ?>Modal">
                <i class="fas fa-plus-circle me-1"></i>إضافة <?php echo htmlspecialchars($currentTab['label'], ENT_QUOTES, 'UTF-8'); ?>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>



<?php if (!$calendarReady): ?>
    <div class="alert alert-warning">
        <i class="fas fa-clock me-2"></i>طبّق Migration محرك الدرجات أولا لتفعيل التقويم.
    </div>
<?php else: ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <a href="assessment_calendar.php?tab=terms" class="text-decoration-none h-100 d-block">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$termCount; ?>">0</div>
                    <div class="stat-card-label">الفصول الدراسية</div>
                    <div class="stat-card-sub">نشط: <?php echo number_format($activeTermCount); ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="assessment_calendar.php?tab=months" class="text-decoration-none h-100 d-block">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-calendar-days"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$monthCount; ?>">0</div>
                    <div class="stat-card-label">الشهور</div>
                    <div class="stat-card-sub">داخل الفصول الدراسية</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="assessment_calendar.php?tab=weeks" class="text-decoration-none h-100 d-block">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-calendar-week"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$weekCount; ?>">0</div>
                    <div class="stat-card-label">الأسابيع</div>
                    <div class="stat-card-sub">دراسية: <?php echo number_format($studyWeekCount); ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-school"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?php echo htmlspecialchars($currentAcademicYearName ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-card-label">العام الحالي</div>
                <div class="stat-card-sub">من الشريط العلوي</div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabMeta as $tabKey => $meta): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === $tabKey ? 'active' : ''; ?>" href="assessment_calendar.php?tab=<?php echo $tabKey; ?>">
                <i class="<?php echo $meta['icon']; ?> me-1"></i><?php echo htmlspecialchars($meta['plural'], ENT_QUOTES, 'UTF-8'); ?>
                <span class="badge rounded-pill bg-primary ms-1"><?php echo (int) $meta['count']; ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="admin-list-surface">
        <?php if ($activeTab === 'terms'): ?>
            <div class="admin-filter-bar mb-3">
                <div class="admin-filter-controls"></div>
                <div class="admin-filter-actions">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                        <i class="fas fa-cog me-1"></i>إعدادات الجدول
                    </button>
                </div>
            </div>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped align-middle datatable admin-data-table" id="termsTable">
                    <thead><tr><th class="admin-col-5 text-center">#</th><th>الفصل الدراسي</th><th class="text-center">الترتيب</th><th>التاريخ</th><th>الحالة</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php if (empty($terms)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">لم تتم إضافة فصول دراسية بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($terms as $index => $term): ?>
                            <tr>
                                <td class="text-center text-muted"><?php echo (int) $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo (int) $term['term_order']; ?></span></td>
                                <td><span dir="ltr"><?php echo htmlspecialchars(($term['start_date'] ?? '-') . ' / ' . ($term['end_date'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><span class="badge bg-<?php echo ($term['status'] ?? '') === 'active' ? 'success' : 'secondary'; ?>"><?php echo ($term['status'] ?? '') === 'active' ? 'نشط' : 'معطل'; ?></span></td>
                                <td class="actions-column admin-table-actions">
                                    <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-term-btn" data-bs-toggle="tooltip" title="تعديل"
                                            data-term-id="<?php echo (int) $term['id']; ?>"
                                            data-term-name="<?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-term-order="<?php echo (int) $term['term_order']; ?>"
                                            data-term-start="<?php echo htmlspecialchars((string) ($term['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-term-end="<?php echo htmlspecialchars((string) ($term['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-term-status="<?php echo htmlspecialchars((string) ($term['status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo ($term['status'] ?? '') === 'active' ? 'btn-deactivate' : 'btn-activate'; ?> toggle-calendar-btn"
                                            data-bs-toggle="tooltip"
                                            title="<?php echo ($term['status'] ?? '') === 'active' ? 'تعطيل' : 'تفعيل'; ?>"
                                            data-action="toggle_term"
                                            data-tab="terms"
                                            data-target-field="term_id"
                                            data-target-id="<?php echo (int) $term['id']; ?>"
                                            data-target-name="<?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-target-label="الفصل الدراسي"
                                            data-current-status="<?php echo ($term['status'] ?? '') === 'active' ? 'active' : 'inactive'; ?>"><i class="fas <?php echo ($term['status'] ?? '') === 'active' ? 'fa-ban' : 'fa-check'; ?>"></i></button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete delete-term-btn" data-bs-toggle="tooltip" title="حذف" data-term-id="<?php echo (int) $term['id']; ?>" data-term-name="<?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($activeTab === 'months'): ?>
            <div class="admin-filter-bar mb-3">
                <div class="admin-filter-controls"></div>
                <div class="admin-filter-actions">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                        <i class="fas fa-cog me-1"></i>إعدادات الجدول
                    </button>
                </div>
            </div>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped align-middle datatable admin-data-table" id="monthsTable">
                    <thead><tr><th class="admin-col-5 text-center">#</th><th>الفصل الدراسي</th><th>الشهر</th><th class="text-center">الترتيب</th><th>التاريخ</th><th>النوع</th><th>الأسابيع</th><th>الحالة</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php if (!$monthsReady): ?>
                        <tr><td colspan="9" class="text-center text-warning py-4">جدول الشهور غير مطبق.</td></tr>
                    <?php elseif (empty($months)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">لم تتم إضافة شهور دراسية بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($months as $index => $month): ?>
                            <?php
                            $nextMonthOrder = (int) $month['month_order'] + 1;
                            $nextMonthAlreadyExists = isset($monthOrderKeys[(int) $month['term_id'] . ':' . $nextMonthOrder]);
                            $monthHasCompleteDates = !empty($month['start_date']) && !empty($month['end_date']);
                            $monthTermHasEnded = !empty($month['term_end_date']) && (string) $month['end_date'] >= (string) $month['term_end_date'];
                            $canSuggestNextMonth = $monthHasCompleteDates && $nextMonthOrder <= 24 && !$nextMonthAlreadyExists && !$monthTermHasEnded;
                            ?>
                            <tr>
                                <td class="text-center text-muted"><?php echo (int) $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($month['term_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><strong><?php echo htmlspecialchars($month['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo (int) $month['month_order']; ?></span></td>
                                <td><span dir="ltr"><?php echo htmlspecialchars(($month['start_date'] ?? '-') . ' / ' . ($month['end_date'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($monthTypeLabels[$month['month_type']] ?? $month['month_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo (int) $month['weeks_count']; ?></span></td>
                                <td><span class="badge bg-<?php echo ($month['status'] ?? '') === 'active' ? 'success' : 'secondary'; ?>"><?php echo ($month['status'] ?? '') === 'active' ? 'نشط' : 'معطل'; ?></span></td>
                                <td class="actions-column admin-table-actions">
                                    <button type="button" class="btn btn-sm btn-action-pills btn-services me-1 copy-month-btn" data-bs-toggle="tooltip" title="نسخ"
                                            data-month-id="<?php echo (int) $month['id']; ?>" data-month-term="<?php echo (int) $month['term_id']; ?>"
                                            data-month-name="<?php echo htmlspecialchars($month['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-month-order="<?php echo (int) $month['month_order']; ?>"
                                            data-month-start="<?php echo htmlspecialchars((string) ($month['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-copy"></i></button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-month-btn" data-bs-toggle="tooltip" title="تعديل"
                                            data-month-id="<?php echo (int) $month['id']; ?>" data-month-term="<?php echo (int) $month['term_id']; ?>"
                                            data-month-name="<?php echo htmlspecialchars($month['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-month-order="<?php echo (int) $month['month_order']; ?>"
                                            data-month-start="<?php echo htmlspecialchars((string) ($month['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-month-end="<?php echo htmlspecialchars((string) ($month['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-month-type="<?php echo htmlspecialchars((string) ($month['month_type'] ?? 'study'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-month-status="<?php echo htmlspecialchars((string) ($month['status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-month-notes="<?php echo htmlspecialchars((string) ($month['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button>
                                    <?php if ($canSuggestNextMonth): ?>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-activate me-1 quick-add-next-month-btn" data-bs-toggle="tooltip" title="إضافة شهر تالٍ"
                                                data-month-term="<?php echo (int) $month['term_id']; ?>"
                                                data-month-name="<?php echo htmlspecialchars((string) $month['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-month-order="<?php echo (int) $month['month_order']; ?>"
                                                data-month-start="<?php echo htmlspecialchars((string) $month['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-month-end="<?php echo htmlspecialchars((string) $month['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-month-term-end="<?php echo htmlspecialchars((string) ($month['term_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-month-type="<?php echo htmlspecialchars((string) ($month['month_type'] ?? 'study'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-forward-step"></i></button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo ($month['status'] ?? '') === 'active' ? 'btn-deactivate' : 'btn-activate'; ?> toggle-calendar-btn"
                                            data-bs-toggle="tooltip"
                                            title="<?php echo ($month['status'] ?? '') === 'active' ? 'تعطيل' : 'تفعيل'; ?>"
                                            data-action="toggle_month"
                                            data-tab="months"
                                            data-target-field="month_id"
                                            data-target-id="<?php echo (int) $month['id']; ?>"
                                            data-target-name="<?php echo htmlspecialchars($month['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-target-label="الشهر"
                                            data-current-status="<?php echo ($month['status'] ?? '') === 'active' ? 'active' : 'inactive'; ?>"><i class="fas <?php echo ($month['status'] ?? '') === 'active' ? 'fa-ban' : 'fa-check'; ?>"></i></button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete delete-month-btn" data-bs-toggle="tooltip" title="حذف" data-month-id="<?php echo (int) $month['id']; ?>" data-month-name="<?php echo htmlspecialchars($month['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="admin-filter-bar mb-3">
                <div class="admin-filter-controls"></div>
                <div class="admin-filter-actions">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                        <i class="fas fa-cog me-1"></i>إعدادات الجدول
                    </button>
                </div>
            </div>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped align-middle datatable admin-data-table" id="weeksTable">
                    <thead><tr><th class="admin-col-5 text-center">#</th><th>الفصل الدراسي</th><th>الشهر</th><th>الأسبوع</th><th class="text-center">الترتيب</th><th>التاريخ</th><th>النوع</th><th>المتوسط</th><th>الحالة</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php if (empty($weeks)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">لم تتم إضافة أسابيع دراسية بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($weeks as $index => $week): ?>
                            <?php
                            $nextWeekOrder = (int) $week['week_order'] + 1;
                            $nextWeekAlreadyExists = isset($weekOrderKeys[(int) $week['term_id'] . ':' . $nextWeekOrder]);
                            $termHasEnded = !empty($week['term_end_date']) && (string) $week['end_date'] >= (string) $week['term_end_date'];
                            $canSuggestNextWeek = $nextWeekOrder <= 60 && !$nextWeekAlreadyExists && !$termHasEnded;
                            ?>
                            <tr data-week-term="<?php echo (int) $week['term_id']; ?>" data-week-order="<?php echo (int) $week['week_order']; ?>" data-week-start="<?php echo htmlspecialchars((string) $week['start_date'], ENT_QUOTES, 'UTF-8'); ?>" data-week-end="<?php echo htmlspecialchars((string) $week['end_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="text-center text-muted"><?php echo (int) $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($week['term_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($week['month_name'] ?? ($week['month_label'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><strong><?php echo htmlspecialchars($week['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo (int) $week['week_order']; ?></span></td>
                                <td><span dir="ltr"><?php echo htmlspecialchars($week['start_date'] . ' / ' . $week['end_date'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($weekTypeLabels[$week['week_type']] ?? $week['week_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($week['counts_for_average']) ? '<span class="badge bg-success">نعم</span>' : '<span class="badge bg-warning text-dark">لا</span>'; ?></td>
                                <td><span class="badge bg-<?php echo ($week['week_type'] ?? '') === 'study' ? 'success' : 'secondary'; ?>"><?php echo ($week['week_type'] ?? '') === 'study' ? 'نشط' : 'معطل'; ?></span></td>
                                <td class="actions-column admin-table-actions">
                                    <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-week-btn" data-bs-toggle="tooltip" title="تعديل"
                                            data-week-id="<?php echo (int) $week['id']; ?>" data-week-month="<?php echo (int) ($week['month_id'] ?? 0); ?>"
                                            data-week-name="<?php echo htmlspecialchars($week['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-week-order="<?php echo (int) $week['week_order']; ?>"
                                            data-week-start="<?php echo htmlspecialchars((string) $week['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-week-end="<?php echo htmlspecialchars((string) $week['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-week-type="<?php echo htmlspecialchars((string) $week['week_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-week-average="<?php echo !empty($week['counts_for_average']) ? '1' : '0'; ?>"
                                            data-week-notes="<?php echo htmlspecialchars((string) ($week['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button>
                                    <?php if ($canSuggestNextWeek): ?>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-activate me-1 quick-add-next-week-btn" data-bs-toggle="tooltip" title="إضافة أسبوع تالٍ"
                                                data-week-id="<?php echo (int) $week['id']; ?>"
                                                data-week-term="<?php echo (int) $week['term_id']; ?>"
                                                data-week-month="<?php echo (int) ($week['month_id'] ?? 0); ?>"
                                                data-week-name="<?php echo htmlspecialchars((string) $week['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-week-order="<?php echo (int) $week['week_order']; ?>"
                                                data-week-start="<?php echo htmlspecialchars((string) $week['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-week-end="<?php echo htmlspecialchars((string) $week['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-week-term-end="<?php echo htmlspecialchars((string) ($week['term_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-week-type="<?php echo htmlspecialchars((string) $week['week_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-week-average="<?php echo !empty($week['counts_for_average']) ? '1' : '0'; ?>"><i class="fas fa-forward-step"></i></button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo ($week['week_type'] ?? '') === 'study' ? 'btn-deactivate' : 'btn-activate'; ?> toggle-calendar-btn"
                                            data-bs-toggle="tooltip"
                                            title="<?php echo ($week['week_type'] ?? '') === 'study' ? 'تعطيل' : 'تفعيل'; ?>"
                                            data-action="toggle_week"
                                            data-tab="weeks"
                                            data-target-field="week_id"
                                            data-target-id="<?php echo (int) $week['id']; ?>"
                                            data-target-name="<?php echo htmlspecialchars($week['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-target-label="الأسبوع"
                                            data-current-status="<?php echo ($week['week_type'] ?? '') === 'study' ? 'active' : 'inactive'; ?>"><i class="fas <?php echo ($week['week_type'] ?? '') === 'study' ? 'fa-ban' : 'fa-check'; ?>"></i></button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete delete-week-btn" data-bs-toggle="tooltip" title="حذف" data-week-id="<?php echo (int) $week['id']; ?>" data-week-name="<?php echo htmlspecialchars($week['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
</div>

<div class="modal fade" id="addTermsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_calendar.php?tab=terms">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="add_term">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة فصل دراسي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-7">
                <label class="form-label">العام الدراسي</label>
                <?php if ($currentAcademicYearId > 0): ?>
                    <input type="hidden" name="academic_year_id" value="<?php echo (int) $currentAcademicYearId; ?>">
                    <div class="form-control bg-light"><?php echo htmlspecialchars($currentAcademicYearName, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <select name="academic_year_id" class="form-select" required><option value="">اختر العام</option><?php foreach ($academicYears as $year): ?><option value="<?php echo (int) $year['id']; ?>"><?php echo htmlspecialchars($year['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                <?php endif; ?>
            </div>
            <div class="col-md-5"><label class="form-label">ترتيب الفصل الدراسي</label><input type="number" name="term_order" class="form-control" value="1" min="1" max="4" required></div>
            <div class="col-12"><label class="form-label">اسم الفصل الدراسي</label><input type="text" name="name" class="form-control" required maxlength="100"></div>
            <div class="col-md-6"><label class="form-label">تاريخ البداية</label><input type="text" name="start_date" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-md-6"><label class="form-label">تاريخ النهاية</label><input type="text" name="end_date" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-12"><label class="form-label">الحالة</label><select name="status" class="form-select"><option value="active">نشط</option><option value="inactive">معطل</option><option value="archived">مؤرشف</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إضافة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editTermModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_calendar.php?tab=terms">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_term"><input type="hidden" name="term_id" id="editTermId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل الفصل الدراسي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-7"><label class="form-label">اسم الفصل الدراسي</label><input type="text" name="name" id="editTermName" class="form-control" required maxlength="100"></div>
            <div class="col-md-5"><label class="form-label">الترتيب</label><input type="number" name="term_order" id="editTermOrder" class="form-control" min="1" max="4" required></div>
            <div class="col-md-6"><label class="form-label">تاريخ البداية</label><input type="text" name="start_date" id="editTermStart" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-md-6"><label class="form-label">تاريخ النهاية</label><input type="text" name="end_date" id="editTermEnd" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-12"><label class="form-label">الحالة</label><select name="status" id="editTermStatus" class="form-select"><option value="active">نشط</option><option value="inactive">معطل</option><option value="archived">مؤرشف</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="deleteTermModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_calendar.php?tab=terms">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_term"><input type="hidden" name="term_id" id="deleteTermId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف الفصل الدراسي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف الفصل الدراسي <span class="fw-bold text-primary" id="deleteTermName"></span>؟</p><div class="alert alert-warning text-start">سيمنع النظام الحذف إذا وُجدت بيانات مرتبطة بالفصل الدراسي.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="addMonthsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_calendar.php?tab=months" id="addMonthForm">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="add_month">
        <div class="modal-header"><h5 class="modal-title" id="addMonthModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة شهر دراسي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-info d-none" id="addMonthQuickContext" role="status" aria-live="polite"></div>
            <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="addMonthTerm">الفصل الدراسي</label><select name="term_id" id="addMonthTerm" class="form-select" required><option value="">اختر الفصل الدراسي</option><?php foreach ($terms as $term): ?><option value="<?php echo (int) $term['id']; ?>"><?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="addMonthOrder">ترتيب الشهر</label><input type="number" name="month_order" id="addMonthOrder" class="form-control" min="1" max="24" value="1" required></div>
            <div class="col-md-3"><label class="form-label" for="addMonthStatus">الحالة</label><select name="status" id="addMonthStatus" class="form-select"><option value="active">نشط</option><option value="inactive">معطل</option><option value="archived">مؤرشف</option></select></div>
            <div class="col-md-6"><label class="form-label" for="addMonthName">اسم الشهر</label><input type="text" name="name" id="addMonthName" class="form-control" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label" for="addMonthStart">من</label><input type="text" name="start_date" id="addMonthStart" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-md-3"><label class="form-label" for="addMonthEnd">إلى</label><input type="text" name="end_date" id="addMonthEnd" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-md-4"><label class="form-label" for="addMonthType">النوع</label><select name="month_type" id="addMonthType" class="form-select"><option value="study">دراسي</option><option value="holiday">عطلة</option><option value="exam">امتحانات</option><option value="custom">مخصص</option></select></div>
            <div class="col-md-8"><label class="form-label" for="addMonthNotes">ملاحظات</label><input type="text" name="notes" id="addMonthNotes" class="form-control" maxlength="500"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إضافة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editMonthModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_calendar.php?tab=months">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_month"><input type="hidden" name="month_id" id="editMonthId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل الشهر</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">الفصل الدراسي</label><select name="term_id" id="editMonthTerm" class="form-select" required><?php foreach ($terms as $term): ?><option value="<?php echo (int) $term['id']; ?>"><?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">الترتيب</label><input type="number" name="month_order" id="editMonthOrder" class="form-control" min="1" max="24" required></div>
            <div class="col-md-3"><label class="form-label">الحالة</label><select name="status" id="editMonthStatus" class="form-select"><option value="active">نشط</option><option value="inactive">معطل</option><option value="archived">مؤرشف</option></select></div>
            <div class="col-md-6"><label class="form-label">اسم الشهر</label><input type="text" name="name" id="editMonthName" class="form-control" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label">من</label><input type="text" name="start_date" id="editMonthStart" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-md-3"><label class="form-label">إلى</label><input type="text" name="end_date" id="editMonthEnd" class="form-control flatpickr-date" placeholder="اختر التاريخ..."></div>
            <div class="col-md-4"><label class="form-label">النوع</label><select name="month_type" id="editMonthType" class="form-select"><option value="study">دراسي</option><option value="holiday">عطلة</option><option value="exam">امتحانات</option><option value="custom">مخصص</option></select></div>
            <div class="col-md-8"><label class="form-label">ملاحظات</label><input type="text" name="notes" id="editMonthNotes" class="form-control" maxlength="500"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="copyMonthModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_calendar.php?tab=months">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="copy_month"><input type="hidden" name="source_month_id" id="copyMonthSourceId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-copy me-2"></i>نسخ شهر مع أسابيعه</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">الشهر المصدر</label><input type="text" id="copyMonthSourceName" class="form-control" readonly></div>
            <div class="col-md-6"><label class="form-label">الفصل الدراسي الهدف</label><select name="target_term_id" id="copyMonthTargetTerm" class="form-select" required><option value="">اختر الفصل الدراسي</option><?php foreach ($terms as $term): ?><option value="<?php echo (int) $term['id']; ?>"><?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">اسم الشهر الجديد</label><input type="text" name="name" id="copyMonthName" class="form-control" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label">ترتيب الشهر</label><input type="number" name="month_order" id="copyMonthOrder" class="form-control" min="1" max="24" required></div>
            <div class="col-md-3"><label class="form-label">ترتيب أول أسبوع</label><input type="number" name="first_week_order" id="copyMonthFirstWeekOrder" class="form-control" min="1" max="60" required value="1"></div>
            <div class="col-md-6"><label class="form-label">بداية الشهر الجديد</label><input type="text" name="start_date" id="copyMonthStart" class="form-control flatpickr-date" required placeholder="اختر التاريخ..."></div>
            <div class="col-md-6"><label class="form-label">نهاية الشهر الجديد</label><input type="text" name="end_date" id="copyMonthEnd" class="form-control flatpickr-date" placeholder="اختر التاريخ..."><div class="form-text">اتركه فارغا ليحسبه النظام من آخر أسبوع منسوخ.</div></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-copy me-1"></i>نسخ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="deleteMonthModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_calendar.php?tab=months">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_month"><input type="hidden" name="month_id" id="deleteMonthId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف الشهر</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف الشهر <span class="fw-bold text-primary" id="deleteMonthName"></span>؟</p><div class="alert alert-warning text-start">لا يمكن حذف شهر يحتوي أسابيع.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="addWeeksModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_calendar.php?tab=weeks" id="addWeekForm">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="add_week">
        <div class="modal-header"><h5 class="modal-title" id="addWeekModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة أسبوع دراسي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-info d-none" id="addWeekQuickContext" role="status" aria-live="polite"></div>
            <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="addWeekMonth">الشهر</label><select name="month_id" id="addWeekMonth" class="form-select" required><option value="">اختر الشهر</option><?php foreach ($months as $month): ?><option value="<?php echo (int) $month['id']; ?>" data-month-term="<?php echo (int) $month['term_id']; ?>" data-month-name="<?php echo htmlspecialchars((string) $month['name'], ENT_QUOTES, 'UTF-8'); ?>" data-month-order="<?php echo (int) $month['month_order']; ?>" data-month-start="<?php echo htmlspecialchars((string) ($month['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-month-end="<?php echo htmlspecialchars((string) ($month['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-month-status="<?php echo htmlspecialchars((string) ($month['status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($month['term_name'] . ' - ' . $month['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="addWeekOrder">الترتيب</label><input type="number" name="week_order" id="addWeekOrder" class="form-control" min="1" max="60" value="1" required></div>
            <div class="col-md-3"><label class="form-label" for="addWeekType">النوع</label><select name="week_type" id="addWeekType" class="form-select"><option value="study">دراسي</option><option value="holiday">عطلة</option><option value="exam">امتحانات</option><option value="revision">مراجعة</option></select></div>
            <div class="col-md-6"><label class="form-label" for="addWeekName">اسم الأسبوع</label><input type="text" name="name" id="addWeekName" class="form-control" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label" for="addWeekStart">من</label><input type="text" name="start_date" id="addWeekStart" class="form-control flatpickr-date" required placeholder="اختر التاريخ..."></div>
            <div class="col-md-3"><label class="form-label" for="addWeekEnd">إلى</label><input type="text" name="end_date" id="addWeekEnd" class="form-control flatpickr-date" required placeholder="اختر التاريخ..."></div>
            <div class="col-md-5"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="counts_for_average" id="addWeekAverage" checked><label class="form-check-label" for="addWeekAverage">يدخل في المتوسط</label></div></div>
            <div class="col-md-7"><label class="form-label" for="addWeekNotes">ملاحظات</label><input type="text" name="notes" id="addWeekNotes" class="form-control" maxlength="500"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إضافة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editWeekModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_calendar.php?tab=weeks">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_week"><input type="hidden" name="week_id" id="editWeekId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل الأسبوع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">الشهر</label><select name="month_id" id="editWeekMonth" class="form-select" required><?php foreach ($months as $month): ?><option value="<?php echo (int) $month['id']; ?>"><?php echo htmlspecialchars($month['term_name'] . ' - ' . $month['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">الترتيب</label><input type="number" name="week_order" id="editWeekOrder" class="form-control" min="1" max="60" required></div>
            <div class="col-md-3"><label class="form-label">النوع</label><select name="week_type" id="editWeekType" class="form-select"><option value="study">دراسي</option><option value="holiday">عطلة</option><option value="exam">امتحانات</option><option value="revision">مراجعة</option></select></div>
            <div class="col-md-6"><label class="form-label">اسم الأسبوع</label><input type="text" name="name" id="editWeekName" class="form-control" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label">من</label><input type="text" name="start_date" id="editWeekStart" class="form-control flatpickr-date" required placeholder="اختر التاريخ..."></div>
            <div class="col-md-3"><label class="form-label">إلى</label><input type="text" name="end_date" id="editWeekEnd" class="form-control flatpickr-date" required placeholder="اختر التاريخ..."></div>
            <div class="col-md-5"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="counts_for_average" id="editWeekAverage"><label class="form-check-label" for="editWeekAverage">يدخل في المتوسط</label></div></div>
            <div class="col-md-7"><label class="form-label">ملاحظات</label><input type="text" name="notes" id="editWeekNotes" class="form-control" maxlength="500"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="deleteWeekModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_calendar.php?tab=weeks">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_week"><input type="hidden" name="week_id" id="deleteWeekId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف الأسبوع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف الأسبوع <span class="fw-bold text-primary" id="deleteWeekName"></span>؟</p><div class="alert alert-warning text-start">سيمنع النظام الحذف إذا وُجدت درجات أو نوافذ رصد أو تقارير مرتبطة بالأسبوع.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="toggleCalendarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleCalendarModalContent"><form method="post" action="assessment_calendar.php" id="toggleCalendarForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" id="toggleCalendarAction">
        <input type="hidden" name="term_id" id="toggleCalendarTermId">
        <input type="hidden" name="month_id" id="toggleCalendarMonthId">
        <input type="hidden" name="week_id" id="toggleCalendarWeekId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="toggleCalendarHeaderIcon"></i>تغيير حالة عنصر التقويم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="text-center mb-3"><i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="toggleCalendarBodyIcon"></i></div>
            <p class="text-center">هل تريد <span class="fw-bold" id="toggleCalendarActionText"></span> <span id="toggleCalendarTargetLabel">العنصر</span> <span class="fw-bold text-primary" id="toggleCalendarTargetName"></span>؟</p>
            <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>تغيير الحالة يؤثر على استخدام العنصر في إعدادات التقويم والرصد، ولا يحذف البيانات المرتبطة به.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning" id="toggleCalendarSubmit"><i class="fas fa-ban me-1"></i><span id="toggleCalendarSubmitText">تعطيل</span></button></div>
    </form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) {
        const modalEl = document.getElementById(id);
        if (modalEl && window.bootstrap) {
            new bootstrap.Modal(modalEl).show();
        }
    }

    function parseIsoDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!match) {
            return null;
        }
        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatIsoDate(date) {
        return date.getUTCFullYear() + '-'
            + String(date.getUTCMonth() + 1).padStart(2, '0') + '-'
            + String(date.getUTCDate()).padStart(2, '0');
    }

    function addCalendarDays(date, days) {
        const result = new Date(date.getTime());
        result.setUTCDate(result.getUTCDate() + days);
        return result;
    }

    function calendarDaysBetween(start, end) {
        return Math.round((end.getTime() - start.getTime()) / 86400000);
    }

    function setCalendarDateValue(input, value) {
        if (!input) {
            return;
        }
        const normalizedValue = value instanceof Date ? formatIsoDate(value) : String(value || '');
        if (typeof window.setAirDatepickerValue === 'function'
            && window.setAirDatepickerValue(input, normalizedValue, { dispatchChange: false })) {
            return;
        }
        input.value = normalizedValue;
    }

    const gregorianMonthNames = [
        'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
        'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'
    ];

    function lastDayOfUtcMonth(date) {
        return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0));
    }

    function isFullGregorianMonth(start, end) {
        const lastDay = lastDayOfUtcMonth(start);
        return start.getUTCDate() === 1
            && start.getUTCFullYear() === end.getUTCFullYear()
            && start.getUTCMonth() === end.getUTCMonth()
            && end.getUTCDate() === lastDay.getUTCDate();
    }

    function resetAddMonthForm() {
        const form = document.getElementById('addMonthForm');
        const title = document.getElementById('addMonthModalTitle');
        const context = document.getElementById('addMonthQuickContext');
        if (form) {
            form.reset();
        }
        setCalendarDateValue(document.getElementById('addMonthStart'), '');
        setCalendarDateValue(document.getElementById('addMonthEnd'), '');
        if (title) {
            title.innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة شهر دراسي';
        }
        if (context) {
            context.textContent = '';
            context.className = 'alert alert-info d-none';
        }
    }

    document.querySelectorAll('[data-bs-target="#addMonthsModal"]').forEach(function (button) {
        button.addEventListener('click', resetAddMonthForm);
    });

    function resetAddWeekForm() {
        const form = document.getElementById('addWeekForm');
        const title = document.getElementById('addWeekModalTitle');
        const context = document.getElementById('addWeekQuickContext');
        if (form) {
            form.reset();
        }
        setCalendarDateValue(document.getElementById('addWeekStart'), '');
        setCalendarDateValue(document.getElementById('addWeekEnd'), '');
        if (title) {
            title.innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة أسبوع دراسي';
        }
        if (context) {
            context.textContent = '';
            context.className = 'alert alert-info d-none';
        }
        const orderInput = document.getElementById('addWeekOrder');
        if (orderInput) {
            orderInput.value = '1';
        }
        const averageInput = document.getElementById('addWeekAverage');
        if (averageInput) {
            averageInput.checked = true;
        }
    }

    document.querySelectorAll('[data-bs-target="#addWeeksModal"]').forEach(function (button) {
        button.addEventListener('click', resetAddWeekForm);
    });

    document.querySelectorAll('.edit-term-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('editTermId').value = this.dataset.termId || '';
            document.getElementById('editTermName').value = this.dataset.termName || '';
            document.getElementById('editTermOrder').value = this.dataset.termOrder || '1';
            setCalendarDateValue(document.getElementById('editTermStart'), this.dataset.termStart || '');
            setCalendarDateValue(document.getElementById('editTermEnd'), this.dataset.termEnd || '');
            document.getElementById('editTermStatus').value = this.dataset.termStatus || 'active';
            showModal('editTermModal');
        });
    });
    document.querySelectorAll('.delete-term-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('deleteTermId').value = this.dataset.termId || '';
            document.getElementById('deleteTermName').textContent = this.dataset.termName || '';
            showModal('deleteTermModal');
        });
    });

    document.querySelectorAll('.toggle-calendar-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const isActive = this.dataset.currentStatus === 'active';
            const targetField = this.dataset.targetField || '';
            const submitButton = document.getElementById('toggleCalendarSubmit');
            const actionText = document.getElementById('toggleCalendarActionText');
            const submitText = document.getElementById('toggleCalendarSubmitText');
            const form = document.getElementById('toggleCalendarForm');
            const modalContent = document.getElementById('toggleCalendarModalContent');

            document.getElementById('toggleCalendarAction').value = this.dataset.action || '';
            document.getElementById('toggleCalendarTermId').value = '';
            document.getElementById('toggleCalendarMonthId').value = '';
            document.getElementById('toggleCalendarWeekId').value = '';
            if (targetField === 'term_id') {
                document.getElementById('toggleCalendarTermId').value = this.dataset.targetId || '';
            } else if (targetField === 'month_id') {
                document.getElementById('toggleCalendarMonthId').value = this.dataset.targetId || '';
            } else if (targetField === 'week_id') {
                document.getElementById('toggleCalendarWeekId').value = this.dataset.targetId || '';
            }

            form.action = 'assessment_calendar.php?tab=' + encodeURIComponent(this.dataset.tab || 'terms');
            document.getElementById('toggleCalendarTargetLabel').textContent = this.dataset.targetLabel || 'العنصر';
            document.getElementById('toggleCalendarTargetName').textContent = this.dataset.targetName || '';
            actionText.textContent = isActive ? 'تعطيل' : 'تفعيل';
            actionText.className = isActive ? 'fw-bold text-warning' : 'fw-bold text-success';
            submitText.textContent = isActive ? 'تعطيل' : 'تفعيل';
            submitButton.className = isActive ? 'btn btn-warning' : 'btn btn-success';
            submitButton.querySelector('i').className = isActive ? 'fas fa-ban me-1' : 'fas fa-check me-1';
            modalContent.classList.toggle('admin-modal-warning', isActive);
            modalContent.classList.toggle('admin-modal-create', !isActive);
            const bodyIcon = document.getElementById('toggleCalendarBodyIcon');
            const headerIcon = document.getElementById('toggleCalendarHeaderIcon');
            if (bodyIcon) {
                bodyIcon.className = isActive ? 'fas fa-ban text-warning admin-modal-icon-lg' : 'fas fa-check-circle text-success admin-modal-icon-lg';
            }
            if (headerIcon) {
                headerIcon.className = isActive ? 'fas fa-ban me-2' : 'fas fa-check-circle me-2';
            }
            showModal('toggleCalendarModal');
        });
    });

    document.querySelectorAll('.edit-month-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('editMonthId').value = this.dataset.monthId || '';
            document.getElementById('editMonthTerm').value = this.dataset.monthTerm || '';
            document.getElementById('editMonthName').value = this.dataset.monthName || '';
            document.getElementById('editMonthOrder').value = this.dataset.monthOrder || '1';
            setCalendarDateValue(document.getElementById('editMonthStart'), this.dataset.monthStart || '');
            setCalendarDateValue(document.getElementById('editMonthEnd'), this.dataset.monthEnd || '');
            document.getElementById('editMonthType').value = this.dataset.monthType || 'study';
            document.getElementById('editMonthStatus').value = this.dataset.monthStatus || 'active';
            document.getElementById('editMonthNotes').value = this.dataset.monthNotes || '';
            showModal('editMonthModal');
        });
    });
    document.querySelectorAll('.quick-add-next-month-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const sourceStart = parseIsoDate(this.dataset.monthStart || '');
            const sourceEnd = parseIsoDate(this.dataset.monthEnd || '');
            if (!sourceStart || !sourceEnd || sourceEnd < sourceStart) {
                return;
            }

            resetAddMonthForm();
            const nextOrder = parseInt(this.dataset.monthOrder || '0', 10) + 1;
            const nextStart = addCalendarDays(sourceEnd, 1);
            const durationDays = calendarDaysBetween(sourceStart, sourceEnd);
            let nextEnd = isFullGregorianMonth(sourceStart, sourceEnd)
                ? lastDayOfUtcMonth(nextStart)
                : addCalendarDays(nextStart, durationDays);
            const warnings = [];
            const termEnd = parseIsoDate(this.dataset.monthTermEnd || '');
            if (termEnd && nextEnd > termEnd) {
                nextEnd = termEnd;
                warnings.push('تم تقصير تاريخ النهاية ليتوافق مع نهاية الترم.');
            }

            document.getElementById('addMonthTerm').value = this.dataset.monthTerm || '';
            document.getElementById('addMonthOrder').value = String(nextOrder);
            document.getElementById('addMonthStatus').value = 'active';
            document.getElementById('addMonthType').value = this.dataset.monthType || 'study';
            document.getElementById('addMonthNotes').value = '';
            document.getElementById('addMonthName').value = gregorianMonthNames[nextStart.getUTCMonth()]
                || ('الشهر ' + nextOrder);
            setCalendarDateValue(document.getElementById('addMonthStart'), nextStart);
            setCalendarDateValue(document.getElementById('addMonthEnd'), nextEnd);

            const title = document.getElementById('addMonthModalTitle');
            const context = document.getElementById('addMonthQuickContext');
            title.innerHTML = '<i class="fas fa-forward-step me-2"></i>إضافة الشهر التالي';
            context.className = warnings.length > 0 ? 'alert alert-warning' : 'alert alert-info';
            context.textContent = warnings.length > 0
                ? warnings.join(' ')
                : 'تم اقتراح الشهر التالي بعد «' + (this.dataset.monthName || '') + '» دون نسخ أسابيعه. راجع البيانات ثم اضغط إضافة.';
            if (window.bootstrap && bootstrap.Tooltip) {
                const tooltip = bootstrap.Tooltip.getInstance(this);
                if (tooltip) {
                    tooltip.hide();
                }
            }
            showModal('addMonthsModal');
        });
    });
    document.querySelectorAll('.copy-month-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const sourceOrder = parseInt(this.dataset.monthOrder || '0', 10);
            const sourceName = this.dataset.monthName || '';
            document.getElementById('copyMonthSourceId').value = this.dataset.monthId || '';
            document.getElementById('copyMonthSourceName').value = sourceName;
            document.getElementById('copyMonthTargetTerm').value = this.dataset.monthTerm || '';
            document.getElementById('copyMonthName').value = sourceName ? ('نسخة من ' + sourceName) : '';
            document.getElementById('copyMonthOrder').value = sourceOrder > 0 ? String(sourceOrder + 1) : '1';
            document.getElementById('copyMonthFirstWeekOrder').value = '1';
            setCalendarDateValue(document.getElementById('copyMonthStart'), this.dataset.monthStart || '');
            setCalendarDateValue(document.getElementById('copyMonthEnd'), '');
            showModal('copyMonthModal');
        });
    });
    document.querySelectorAll('.delete-month-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('deleteMonthId').value = this.dataset.monthId || '';
            document.getElementById('deleteMonthName').textContent = this.dataset.monthName || '';
            showModal('deleteMonthModal');
        });
    });

    document.querySelectorAll('.edit-week-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('editWeekId').value = this.dataset.weekId || '';
            document.getElementById('editWeekMonth').value = this.dataset.weekMonth || '';
            document.getElementById('editWeekName').value = this.dataset.weekName || '';
            document.getElementById('editWeekOrder').value = this.dataset.weekOrder || '1';
            setCalendarDateValue(document.getElementById('editWeekStart'), this.dataset.weekStart || '');
            setCalendarDateValue(document.getElementById('editWeekEnd'), this.dataset.weekEnd || '');
            document.getElementById('editWeekType').value = this.dataset.weekType || 'study';
            document.getElementById('editWeekAverage').checked = this.dataset.weekAverage === '1';
            document.getElementById('editWeekNotes').value = this.dataset.weekNotes || '';
            showModal('editWeekModal');
        });
    });
    document.querySelectorAll('.quick-add-next-week-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const sourceStart = parseIsoDate(this.dataset.weekStart || '');
            const sourceEnd = parseIsoDate(this.dataset.weekEnd || '');
            if (!sourceStart || !sourceEnd || sourceEnd < sourceStart) {
                return;
            }

            resetAddWeekForm();
            const nextOrder = parseInt(this.dataset.weekOrder || '0', 10) + 1;
            const nextStart = addCalendarDays(sourceEnd, 1);
            const durationDays = calendarDaysBetween(sourceStart, sourceEnd);
            let nextEnd = addCalendarDays(nextStart, durationDays);
            const sourceTermId = this.dataset.weekTerm || '';
            const sourceMonthId = this.dataset.weekMonth || '';
            const monthSelect = document.getElementById('addWeekMonth');
            const monthOptions = monthSelect ? Array.from(monthSelect.options).filter(function (option) {
                return option.value !== '' && option.dataset.monthTerm === sourceTermId;
            }) : [];
            const containsDate = function (option, date) {
                const monthStart = parseIsoDate(option.dataset.monthStart || '');
                const monthEnd = parseIsoDate(option.dataset.monthEnd || '');
                return (!monthStart || date >= monthStart) && (!monthEnd || date <= monthEnd);
            };
            let targetMonth = monthOptions.find(function (option) {
                return option.value === sourceMonthId && containsDate(option, nextStart);
            });
            if (!targetMonth) {
                targetMonth = monthOptions
                    .filter(function (option) { return containsDate(option, nextStart); })
                    .sort(function (left, right) {
                        return parseInt(left.dataset.monthOrder || '0', 10) - parseInt(right.dataset.monthOrder || '0', 10);
                    })[0] || null;
            }

            const warnings = [];
            const termEnd = parseIsoDate(this.dataset.weekTermEnd || '');
            if (termEnd && nextEnd > termEnd) {
                nextEnd = termEnd;
                warnings.push('تم تقصير تاريخ النهاية ليتوافق مع نهاية الترم.');
            }

            if (targetMonth) {
                monthSelect.value = targetMonth.value;
                const monthEnd = parseIsoDate(targetMonth.dataset.monthEnd || '');
                if (monthEnd && nextEnd > monthEnd) {
                    nextEnd = monthEnd;
                    warnings.push('تم تقصير الأسبوع ليتوافق مع نهاية الشهر؛ راجع التاريخ قبل الإضافة.');
                }
                if ((targetMonth.dataset.monthStatus || 'active') !== 'active') {
                    warnings.push('الشهر المقترح غير نشط حاليًا.');
                }
            } else {
                monthSelect.value = '';
                warnings.push('لا يوجد شهر يغطي تاريخ بداية الأسبوع التالي؛ اختر الشهر أو أضفه أولًا.');
            }

            document.getElementById('addWeekOrder').value = String(nextOrder);
            document.getElementById('addWeekType').value = this.dataset.weekType || 'study';
            document.getElementById('addWeekAverage').checked = this.dataset.weekAverage === '1';
            document.getElementById('addWeekNotes').value = '';
            document.getElementById('addWeekName').value = 'الأسبوع ' + nextOrder
                + (targetMonth && targetMonth.dataset.monthName ? ' - ' + targetMonth.dataset.monthName : '');
            setCalendarDateValue(document.getElementById('addWeekStart'), nextStart);
            setCalendarDateValue(document.getElementById('addWeekEnd'), nextEnd);

            const title = document.getElementById('addWeekModalTitle');
            const context = document.getElementById('addWeekQuickContext');
            title.innerHTML = '<i class="fas fa-forward-step me-2"></i>إضافة الأسبوع التالي';
            context.className = warnings.length > 0 ? 'alert alert-warning' : 'alert alert-info';
            context.textContent = warnings.length > 0
                ? warnings.join(' ')
                : 'تم اقتراح الأسبوع التالي بعد «' + (this.dataset.weekName || '') + '». راجع البيانات ثم اضغط إضافة.';
            if (window.bootstrap && bootstrap.Tooltip) {
                const tooltip = bootstrap.Tooltip.getInstance(this);
                if (tooltip) {
                    tooltip.hide();
                }
            }
            showModal('addWeeksModal');
        });
    });
    document.querySelectorAll('.delete-week-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('deleteWeekId').value = this.dataset.weekId || '';
            document.getElementById('deleteWeekName').textContent = this.dataset.weekName || '';
            showModal('deleteWeekModal');
        });
    });
});
</script>

<!-- Table Settings Modal -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>اختر الأعمدة التي تريد عرضها في الجدول:</p>
                <?php if ($activeTab === 'terms'): ?>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_term_name" checked>
                        <label class="form-check-label" for="chk_term_name">الفصل الدراسي</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_term_order" checked>
                        <label class="form-check-label" for="chk_term_order">الترتيب</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_term_dates" checked>
                        <label class="form-check-label" for="chk_term_dates">التاريخ</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_term_status" checked>
                        <label class="form-check-label" for="chk_term_status">الحالة</label>
                    </div>
                <?php elseif ($activeTab === 'months'): ?>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_term" checked>
                        <label class="form-check-label" for="chk_month_term">الفصل الدراسي</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_name" checked>
                        <label class="form-check-label" for="chk_month_name">الشهر</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_order" checked>
                        <label class="form-check-label" for="chk_month_order">الترتيب</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_dates" checked>
                        <label class="form-check-label" for="chk_month_dates">التاريخ</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_type" checked>
                        <label class="form-check-label" for="chk_month_type">النوع</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_weeks" checked>
                        <label class="form-check-label" for="chk_month_weeks">الأسابيع</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_month_status" checked>
                        <label class="form-check-label" for="chk_month_status">الحالة</label>
                    </div>
                <?php else: ?>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_term" checked>
                        <label class="form-check-label" for="chk_week_term">الفصل الدراسي</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_month" checked>
                        <label class="form-check-label" for="chk_week_month">الشهر</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_name" checked>
                        <label class="form-check-label" for="chk_week_name">الأسبوع</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_order" checked>
                        <label class="form-check-label" for="chk_week_order">الترتيب</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_dates" checked>
                        <label class="form-check-label" for="chk_week_dates">التاريخ</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_type" checked>
                        <label class="form-check-label" for="chk_week_type">النوع</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_average" checked>
                        <label class="form-check-label" for="chk_week_average">المتوسط</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_week_status" checked>
                        <label class="form-check-label" for="chk_week_status">الحالة</label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($activeTab === 'terms'): ?>
        initializeTableColumnSettings('termsTable', {
            'chk_term_name': 1,
            'chk_term_order': 2,
            'chk_term_dates': 3,
            'chk_term_status': 4
        }, 'terms_table_columns');
    <?php elseif ($activeTab === 'months'): ?>
        initializeTableColumnSettings('monthsTable', {
            'chk_month_term': 1,
            'chk_month_name': 2,
            'chk_month_order': 3,
            'chk_month_dates': 4,
            'chk_month_type': 5,
            'chk_month_weeks': 6,
            'chk_month_status': 7
        }, 'months_table_columns');
    <?php else: ?>
        initializeTableColumnSettings('weeksTable', {
            'chk_week_term': 1,
            'chk_week_month': 2,
            'chk_week_name': 3,
            'chk_week_order': 4,
            'chk_week_dates': 5,
            'chk_week_type': 6,
            'chk_week_average': 7,
            'chk_week_status': 8
        }, 'weeks_table_columns');
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
