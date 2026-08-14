<?php
$page_title = "العيادة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/StudentOperationalGuard.php';
require_once '../classes/ClinicListDataTableQuery.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$success_message = $_SESSION['clinic_success'] ?? '';
$error_message = $_SESSION['clinic_error'] ?? '';
unset($_SESSION['clinic_success'], $_SESSION['clinic_error']);

$database = new Database();
$db = $database->getConnection();
$studentOperationalGuard = new StudentOperationalGuard($db);
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();
$isSpecialistClinicView = $portalContext->role() === 'specialist';
$canManageClinic = !$isSpecialistClinicView;
$assertVisitAllowed = static function (int $visitId) use ($db, $portalContext): int {
    $stmt = $db->prepare('SELECT student_id FROM student_clinic_visits WHERE id = ? LIMIT 1');
    $stmt->execute([$visitId]);
    $studentId = (int)($stmt->fetchColumn() ?: 0);
    if ($studentId <= 0) {
        throw new InvalidArgumentException('زيارة العيادة المطلوبة غير موجودة.');
    }
    $portalContext->assertStudentAllowed($studentId);
    return $studentId;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['clinic_error'] = 'خطأ في التحقق من الأمان.';
        header('Location: student_clinic.php');
        exit();
    }

    $action = $_POST['action'] ?? 'add';

    try {
        if (!$canManageClinic) {
            throw new RuntimeException('هذا الحساب مخول بعرض سجل الزيارات فقط.');
        }
        if ($action === 'delete') {
            $visitId = (int)($_POST['visit_id'] ?? 0);
            if ($visitId <= 0) {
                throw new InvalidArgumentException('معرف الزيارة غير صحيح.');
            }
            $assertVisitAllowed($visitId);
            $stmt = $db->prepare("DELETE FROM student_clinic_visits WHERE id = ?");
            $stmt->execute([$visitId]);
            ActivityLog::logDelete('clinic_visit', $visitId, 'حذف زيارة عيادة', []);
            $_SESSION['clinic_success'] = 'تم حذف زيارة العيادة بنجاح.';
        } elseif ($action === 'edit_health') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new InvalidArgumentException('معرف الطالب غير صحيح.');
            }
            $portalContext->assertStudentAllowed($studentId);
            $studentOperationalGuard->assertWritable($studentId);
            
            $bloodType = trim((string)($_POST['blood_type'] ?? ''));
            $insuranceNumber = trim((string)($_POST['insurance_number'] ?? ''));
            $insuranceStartDate = trim((string)($_POST['insurance_start_date'] ?? ''));
            $insuranceEndDate = trim((string)($_POST['insurance_end_date'] ?? ''));
            $healthStatus = trim((string)($_POST['health_status'] ?? ''));
            $chronicDiseases = trim((string)($_POST['chronic_diseases'] ?? ''));
            $allergies = trim((string)($_POST['allergies'] ?? ''));
            $disabilities = trim((string)($_POST['disabilities'] ?? ''));
            $medications = trim((string)($_POST['medications'] ?? ''));
            $treatmentPlan = trim((string)($_POST['treatment_plan'] ?? ''));
            $prevReports = trim((string)($_POST['previous_medical_reports'] ?? ''));
            $emergencyNotes = trim((string)($_POST['emergency_medical_notes'] ?? ''));

            // التحقق من وجود الملف الطبي
            $stmtCheck = $db->prepare("SELECT user_id FROM student_profiles WHERE user_id = ?");
            $stmtCheck->execute([$studentId]);
            $exists = $stmtCheck->fetchColumn();

            if ($exists) {
                $stmtUpdate = $db->prepare("UPDATE student_profiles SET
                    blood_type = ?, insurance_number = ?, insurance_start_date = ?, insurance_end_date = ?,
                    health_status = ?, chronic_diseases = ?, allergies = ?, disabilities = ?,
                    medications = ?, treatment_plan = ?, previous_medical_reports = ?, emergency_medical_notes = ?
                    WHERE user_id = ?");
                $stmtUpdate->execute([
                    $bloodType ?: null, $insuranceNumber ?: null, $insuranceStartDate ?: null, $insuranceEndDate ?: null,
                    $healthStatus ?: null, $chronicDiseases ?: null, $allergies ?: null, $disabilities ?: null,
                    $medications ?: null, $treatmentPlan ?: null, $prevReports ?: null, $emergencyNotes ?: null,
                    $studentId
                ]);
            } else {
                $stmtInsert = $db->prepare("INSERT INTO student_profiles
                    (user_id, blood_type, insurance_number, insurance_start_date, insurance_end_date,
                    health_status, chronic_diseases, allergies, disabilities,
                    medications, treatment_plan, previous_medical_reports, emergency_medical_notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([
                    $studentId, $bloodType ?: null, $insuranceNumber ?: null, $insuranceStartDate ?: null, $insuranceEndDate ?: null,
                    $healthStatus ?: null, $chronicDiseases ?: null, $allergies ?: null, $disabilities ?: null,
                    $medications ?: null, $treatmentPlan ?: null, $prevReports ?: null, $emergencyNotes ?: null
                ]);
            }

            ActivityLog::logUpdate('student_profile', $studentId, 'تعديل البيانات الصحية للطالب من صفحة العيادة', [
                'student_id' => $studentId
            ]);
            $_SESSION['clinic_success'] = 'تم تحديث البيانات الصحية للطالب بنجاح.';
            header('Location: student_clinic.php?tab=health');
            exit();
        } elseif ($action === 'edit') {
            $visitId = (int)($_POST['visit_id'] ?? 0);
            $studentId = (int)($_POST['student_id'] ?? 0);
            $visitDate = trim((string)($_POST['visit_date'] ?? ''));
            $visitTime = trim((string)($_POST['visit_time'] ?? ''));
            
            if ($visitId <= 0 || $studentId <= 0 || $visitDate === '' || $visitTime === '') {
                throw new InvalidArgumentException('جميع البيانات الأساسية مطلوبة للتعديل.');
            }
            $assertVisitAllowed($visitId);
            $portalContext->assertStudentAllowed($studentId);
            $studentOperationalGuard->assertWritable($studentId);

            $visitAt = $visitDate . ' ' . $visitTime . ':00';
            $complaint = trim((string)($_POST['complaint'] ?? ''));
            $diagnosis = trim((string)($_POST['diagnosis'] ?? ''));
            $actionTaken = trim((string)($_POST['action_taken'] ?? ''));
            $treatmentTaken = trim((string)($_POST['treatment_taken'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $stmt = $db->prepare("UPDATE student_clinic_visits SET
                student_id = ?, visit_at = ?, complaint = ?, diagnosis = ?, health_condition = ?, treatment_taken = ?, action_taken = ?, notes = ?
                WHERE id = ?");
            $stmt->execute([
                $studentId,
                $visitAt,
                $complaint,
                $diagnosis,
                $complaint !== '' ? $complaint : $diagnosis,
                $treatmentTaken,
                $actionTaken,
                $notes,
                $visitId
            ]);

            ActivityLog::logUpdate('clinic_visit', $visitId, 'تعديل زيارة عيادة', [
                'student_id' => $studentId,
                'visit_at' => $visitAt,
            ]);
            $_SESSION['clinic_success'] = 'تم تعديل بيانات زيارة العيادة بنجاح.';
        } else {
            // إضافة زيارة جديدة
            $studentId = (int)($_POST['student_id'] ?? 0);
            $visitDate = trim((string)($_POST['visit_date'] ?? ''));
            $visitTime = trim((string)($_POST['visit_time'] ?? ''));
            
            if ($studentId <= 0 || $visitDate === '' || $visitTime === '') {
                throw new InvalidArgumentException('اختر الطالب وحدد تاريخ ووقت الزيارة.');
            }
            $portalContext->assertStudentAllowed($studentId);
            $studentOperationalGuard->assertWritable($studentId);

            $visitAt = $visitDate . ' ' . $visitTime . ':00';
            $complaint = trim((string)($_POST['complaint'] ?? ''));
            $diagnosis = trim((string)($_POST['diagnosis'] ?? ''));
            $actionTaken = trim((string)($_POST['action_taken'] ?? ''));
            $treatmentTaken = trim((string)($_POST['treatment_taken'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $stmt = $db->prepare("INSERT INTO student_clinic_visits
                (student_id, visit_at, complaint, diagnosis, health_condition, treatment_taken, action_taken, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $studentId,
                $visitAt,
                $complaint,
                $diagnosis,
                $complaint !== '' ? $complaint : $diagnosis,
                $treatmentTaken,
                $actionTaken,
                $notes,
                $_SESSION['user_id'] ?? null,
            ]);

            $visitId = (int)$db->lastInsertId();
            ActivityLog::logCreate('clinic_visit', $visitId, 'زيارة عيادة', [
                'student_id' => $studentId,
                'visit_at' => $visitAt,
            ]);
            $_SESSION['clinic_success'] = 'تم تسجيل زيارة العيادة بنجاح.';
        }
    } catch (Exception $e) {
        $_SESSION['clinic_error'] = 'حدث خطأ: ' . $e->getMessage();
    }

    header('Location: student_clinic.php?tab=visits');
    exit();
}

// جلب الهيكل الأكاديمي للفلترة
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name, grade_id FROM classes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($allowedClassIds !== null) {
    $allowedClassMap = array_fill_keys($allowedClassIds, true);
    $classes = array_values(array_filter($classes, static fn(array $class): bool => isset($allowedClassMap[(int)$class['id']])));
    $allowedGradeMap = array_fill_keys(array_map('intval', array_column($classes, 'grade_id')), true);
    $grades = array_values(array_filter($grades, static fn(array $grade): bool => isset($allowedGradeMap[(int)$grade['id']])));
    $allowedStageMap = array_fill_keys(array_map('intval', array_column($grades, 'stage_id')), true);
    $stages = array_values(array_filter($stages, static fn(array $stage): bool => isset($allowedStageMap[(int)$stage['id']])));
}

$activeTab = $_GET['tab'] ?? 'health';
if (!in_array($activeTab, ['health', 'visits'])) {
    $activeTab = 'health';
}
if ($isSpecialistClinicView) {
    $activeTab = 'visits';
}

// قيم الفلاتر المستقبلة عبر GET
$filter_stage = (int)($_GET['stage_id'] ?? 0);
$filter_grade = (int)($_GET['grade_id'] ?? 0);
$filter_class = (int)($_GET['class_id'] ?? 0);
$filter_student = (int)($_GET['student_id'] ?? 0);
$filter_date_from = trim((string)($_GET['date_from'] ?? ''));
$filter_date_to = trim((string)($_GET['date_to'] ?? ''));

// قيم فلاتر الحالة الصحية للطلاب عبر GET
$health_filter_stage = (int)($_GET['health_stage_id'] ?? 0);
$health_filter_grade = (int)($_GET['health_grade_id'] ?? 0);
$health_filter_class = (int)($_GET['health_class_id'] ?? 0);
if ($allowedClassIds !== null) {
    if ($filter_class > 0 && !in_array($filter_class, $allowedClassIds, true)) {
        $filter_class = 0;
    }
    if ($health_filter_class > 0 && !in_array($health_filter_class, $allowedClassIds, true)) {
        $health_filter_class = 0;
    }
}

// جلب قائمة الطلاب للمودالات وشريط الفلترة
$studentsSql = "SELECT u.id, u.name, sp.student_code, se.class_id, c.grade_id, g.stage_id
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
    LEFT JOIN classes c ON c.id = se.class_id
    LEFT JOIN grades g ON g.id = c.grade_id
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
$studentsParams = [$currentAcademicYearId];
if ($currentAcademicYearId <= 0) {
    $studentsSql = "SELECT u.id, u.name, sp.student_code, u.class_id, c.grade_id, g.stage_id
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN classes c ON c.id = u.class_id
        LEFT JOIN grades g ON g.id = c.grade_id
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
    $studentsParams = [];
}
$studentClassColumn = $currentAcademicYearId > 0 ? 'se.class_id' : 'u.class_id';
if ($allowedClassIds !== null) {
    if ($allowedClassIds === []) {
        $studentsSql .= ' AND 1 = 0';
    } else {
        $studentsSql .= ' AND ' . $studentClassColumn . ' IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
        array_push($studentsParams, ...$allowedClassIds);
    }
}
$studentsSql .= " ORDER BY u.name";
$stmt = $db->prepare($studentsSql);
$stmt->execute($studentsParams);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Legacy full-table reads are kept disabled for rollback; DataTables reads pages from the endpoint below.
$legacyClinicListForRollback = false;
if ($legacyClinicListForRollback) {
// بناء استعلام سجل الزيارات مع الفلاتر الحية
$visitsSql = "SELECT v.*, u.name AS student_name, sp.student_code, c.name AS class_name, creator.name AS created_by_name
    FROM student_clinic_visits v
    JOIN users u ON u.id = v.student_id
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = " . (int)$currentAcademicYearId . "
    LEFT JOIN classes c ON c.id = se.class_id
    LEFT JOIN grades g ON g.id = c.grade_id
    LEFT JOIN users creator ON creator.id = v.created_by
    WHERE 1=1";

$visitsParams = [];

if ($filter_stage > 0) {
    $visitsSql .= " AND g.stage_id = ?";
    $visitsParams[] = $filter_stage;
}
if ($filter_grade > 0) {
    $visitsSql .= " AND c.grade_id = ?";
    $visitsParams[] = $filter_grade;
}
if ($filter_class > 0) {
    $visitsSql .= " AND se.class_id = ?";
    $visitsParams[] = $filter_class;
}
if ($filter_student > 0) {
    $visitsSql .= " AND v.student_id = ?";
    $visitsParams[] = $filter_student;
}
if ($filter_date_from !== '') {
    $visitsSql .= " AND v.visit_at >= ?";
    $visitsParams[] = $filter_date_from . ' 00:00:00';
}
if ($filter_date_to !== '') {
    $visitsSql .= " AND v.visit_at <= ?";
    $visitsParams[] = $filter_date_to . ' 23:59:59';
}

$visitsSql .= " ORDER BY v.visit_at DESC, v.id DESC LIMIT 300";
$stmtVisits = $db->prepare($visitsSql);
$stmtVisits->execute($visitsParams);
$visits = $stmtVisits->fetchAll(PDO::FETCH_ASSOC);

// بناء استعلام الحالة الصحية للطلاب مع الفلاتر
$healthSql = "SELECT u.id, u.name AS student_name, c.name AS class_name,
    sp.blood_type, sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
    sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date,
    sp.medications, sp.treatment_plan, sp.previous_medical_reports, sp.emergency_medical_notes,
    c.grade_id, g.stage_id
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
    LEFT JOIN classes c ON c.id = se.class_id
    LEFT JOIN grades g ON g.id = c.grade_id
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";

$healthParams = [$currentAcademicYearId];
if ($currentAcademicYearId <= 0) {
    $healthSql = "SELECT u.id, u.name AS student_name, c.name AS class_name,
        sp.blood_type, sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
        sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date,
        sp.medications, sp.treatment_plan, sp.previous_medical_reports, sp.emergency_medical_notes,
        c.grade_id, g.stage_id
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN classes c ON c.id = u.class_id
        LEFT JOIN grades g ON g.id = c.grade_id
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
    $healthParams = [];
}

if ($health_filter_stage > 0) {
    $healthSql .= " AND g.stage_id = ?";
    $healthParams[] = $health_filter_stage;
}
if ($health_filter_grade > 0) {
    $healthSql .= " AND c.grade_id = ?";
    $healthParams[] = $health_filter_grade;
}
if ($health_filter_class > 0) {
    $healthSql .= " AND se.class_id = ?";
    $healthParams[] = $health_filter_class;
}

$healthSql .= " ORDER BY u.name";
$stmtHealth = $db->prepare($healthSql);
$stmtHealth->execute($healthParams);
$healthStudents = $stmtHealth->fetchAll(PDO::FETCH_ASSOC);
}
$clinicListQuery = new ClinicListDataTableQuery($db);
$clinicCounts = $clinicListQuery->counts($currentAcademicYearId, ['health_stage_id' => $health_filter_stage, 'health_grade_id' => $health_filter_grade, 'health_class_id' => $health_filter_class], ['stage_id' => $filter_stage, 'grade_id' => $filter_grade, 'class_id' => $filter_class, 'student_id' => $filter_student, 'date_from' => $filter_date_from, 'date_to' => $filter_date_to], $allowedClassIds);
$healthStudents = [];
$visits = [];

require_once '../includes/admin_header.php';
?>

<!-- ترويسة الصفحة -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-clinic-medical me-2 text-danger"></i>العيادة</h1>
    <?php if ($activeTab === 'visits' && $canManageClinic): ?>
        <div class="admin-top-actions">
            <button class="btn btn-success shadow px-4 py-2" data-bs-toggle="modal" data-bs-target="#addVisitModal">
                <i class="fas fa-plus-circle me-2"></i>تسجيل زيارة جديدة
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- تبويبات الصفحة -->
<ul class="nav nav-tabs admin-tabs mb-4" id="clinicTab" role="tablist">
    <?php if (!$isSpecialistClinicView): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link fw-bold <?php echo $activeTab === 'health' ? 'active' : ''; ?>" href="student_clinic.php?tab=health">
            <i class="fas fa-heartbeat me-1"></i>الحالة الصحية للطلاب
            <span class="badge rounded-pill bg-primary ms-1"><?php echo (int)$clinicCounts['health']; ?></span>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link fw-bold <?php echo $activeTab === 'visits' ? 'active' : ''; ?>" href="student_clinic.php?tab=visits">
            <i class="fas fa-history me-1"></i>سجل زيارات العيادة
            <span class="badge rounded-pill bg-primary ms-1"><?php echo (int)$clinicCounts['visits']; ?></span>
        </a>
    </li>
</ul>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($activeTab === 'health'): ?>
<!-- جدول الحالة الصحية للطلاب مع الفلاتر -->
<form method="GET" action="student_clinic.php" id="healthFilterForm" class="admin-filter-bar">
    <input type="hidden" name="tab" value="health">
    <div class="admin-filter-controls">
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">المرحلة</label>
                <select class="form-select form-select-sm" name="health_stage_id" id="healthFilterStage" onchange="this.form.submit()">
                    <option value="">-- كل المراحل --</option>
                    <?php foreach ($stages as $stg): ?>
                        <option value="<?php echo $stg['id']; ?>" <?php echo $health_filter_stage === (int)$stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">الصف</label>
                <select class="form-select form-select-sm" name="health_grade_id" id="healthFilterGrade" onchange="this.form.submit()">
                    <option value="">-- كل الصفوف --</option>
                    <?php foreach ($grades as $grd): ?>
                        <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $health_filter_grade === (int)$grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">الفصل</label>
                <select class="form-select form-select-sm" name="health_class_id" id="healthFilterClass" onchange="this.form.submit()">
                    <option value="">-- كل الفصول --</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $health_filter_class === (int)$cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
    </div>
    <div class="admin-filter-actions">
        <a href="student_clinic.php?tab=health" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#healthTableSettingsModal" title="تخصيص أعمدة الجدول">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table" id="studentHealthTable">
                <thead>
                    <tr>
                        <th style="width: 45px;">#</th>
                        <th>اسم الطالب</th>
                        <th>الفصل</th>
                        <th>فصيلة الدم</th>
                        <th>الحالة الصحية العامة</th>
                        <th>الأمراض المزمنة</th>
                        <th>الحساسية</th>
                        <th>الإعاقات (إن وجدت)</th>
                        <th>رقم التأمين الطبي</th>
                        <th>تاريخ بداية التأمين</th>
                        <th>تاريخ نهاية التأمين</th>
                        <th>العلاج / الأدوية</th>
                        <th>خطط علاجية متبعة</th>
                        <th>تقارير طبية سابقة</th>
                        <th>ملاحظات طبية طارئة</th>
                        <th style="width: 80px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($healthStudents as $hIdx => $hStudent): ?>
                        <tr>
                            <td class="fw-bold text-center"><?php echo $hIdx + 1; ?></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($hStudent['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($hStudent['class_name'] ?? '-'); ?></td>
                            <td><span class="badge bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($hStudent['blood_type'] ?? '-'); ?></span></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['health_status'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['chronic_diseases'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['allergies'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['disabilities'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars($hStudent['insurance_number'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($hStudent['insurance_start_date'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($hStudent['insurance_end_date'] ?? '-'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['medications'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['treatment_plan'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['previous_medical_reports'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($hStudent['emergency_medical_notes'] ?? '-')); ?></td>
                            <td class="actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="تعديل الحالة الصحية" onclick="openEditHealthModal(<?php echo htmlspecialchars(json_encode($hStudent)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
        </table>
    </div>
</div>

<!-- مودال تخصيص أعمدة جدول الحالة الصحية للطلاب -->
<div class="modal fade" id="healthTableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">اختر الأعمدة التي تريد عرضها في جدول الحالة الصحية للطلاب:</p>
                <div class="row g-2">
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_seq" checked>
                            <label class="form-check-label" for="col_h_seq">مسلسل / #</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_name" checked>
                            <label class="form-check-label" for="col_h_name">اسم الطالب</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_class" checked>
                            <label class="form-check-label" for="col_h_class">الفصل</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_blood" checked>
                            <label class="form-check-label" for="col_h_blood">فصيلة الدم</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_health_status" checked>
                            <label class="form-check-label" for="col_h_health_status">الحالة الصحية العامة</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_chronic">
                            <label class="form-check-label" for="col_h_chronic">الأمراض المزمنة</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_allergies">
                            <label class="form-check-label" for="col_h_allergies">الحساسية</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_disabilities">
                            <label class="form-check-label" for="col_h_disabilities">الإعاقات (إن وجدت)</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_ins_num">
                            <label class="form-check-label" for="col_h_ins_num">رقم التأمين الطبي</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_ins_start">
                            <label class="form-check-label" for="col_h_ins_start">تاريخ بداية التأمين</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_ins_end">
                            <label class="form-check-label" for="col_h_ins_end">تاريخ نهاية التأمين</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_meds">
                            <label class="form-check-label" for="col_h_meds">العلاج / الأدوية</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_plan">
                            <label class="form-check-label" for="col_h_plan">خطط علاجية متبعة</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_reports">
                            <label class="form-check-label" for="col_h_reports">تقارير طبية سابقة</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_emergency">
                            <label class="form-check-label" for="col_h_emergency">ملاحظات طبية طارئة</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_h_actions" checked>
                            <label class="form-check-label" for="col_h_actions">الإجراءات</label>
                        </div>
                    </div>
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
<?php endif; ?>

<?php if ($activeTab === 'visits'): ?>
<!-- جدول سجل الزيارات مع الفلاتر التفاعلية الديناميكية -->
<form method="GET" action="student_clinic.php" id="clinicFilterForm" class="admin-filter-bar">
    <input type="hidden" name="tab" value="visits">
    <div class="admin-filter-controls">
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">المرحلة</label>
                <select class="form-select form-select-sm" name="stage_id" id="pageFilterStage" onchange="this.form.submit()">
                    <option value="">-- كل المراحل --</option>
                    <?php foreach ($stages as $stg): ?>
                        <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage === (int)$stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">الصف</label>
                <select class="form-select form-select-sm" name="grade_id" id="pageFilterGrade" onchange="this.form.submit()">
                    <option value="">-- كل الصفوف --</option>
                    <?php foreach ($grades as $grd): ?>
                        <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade === (int)$grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">الفصل</label>
                <select class="form-select form-select-sm" name="class_id" id="pageFilterClass" onchange="this.form.submit()">
                    <option value="">-- كل الفصول --</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class === (int)$cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">اسم الطالب</label>
                <select class="form-select form-select-sm" name="student_id" id="pageFilterStudent" onchange="this.form.submit()">
                    <option value="">-- كل الطلاب --</option>
                    <?php foreach ($students as $st): ?>
                        <option value="<?php echo (int)$st['id']; ?>"
                                data-stage="<?php echo (int)($st['stage_id'] ?? 0); ?>"
                                data-grade="<?php echo (int)($st['grade_id'] ?? 0); ?>"
                                data-class="<?php echo (int)($st['class_id'] ?? 0); ?>"
                                <?php echo $filter_student === (int)$st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">من الفترة</label>
                <input type="text" class="form-control form-control-sm flatpickr-date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>" onchange="this.form.submit()">
            </div>
            <div>
                <label class="form-label small text-muted mb-1 d-block fw-bold">إلى الفترة</label>
                <input type="text" class="form-control form-control-sm flatpickr-date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>" onchange="this.form.submit()">
            </div>
    </div>
    <div class="admin-filter-actions">
        <a href="student_clinic.php?tab=visits" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table" id="clinicVisitsTable">
                <thead>
                    <tr>
                        <th style="width: 45px;">#</th>
                        <th>اسم الطالب</th>
                        <th>التاريخ</th>
                        <th>الوقت</th>
                        <th>الفصل</th>
                        <th>شكوى الطالب</th>
                        <th>التشخيص الطبي</th>
                        <th>الإجراء المتخذ</th>
                        <th>العلاج الموصوف</th>
                        <th>ملاحظات</th>
                        <th style="width: 100px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits as $idx => $visit): 
                        $timestamp = strtotime($visit['visit_at']);
                        $formattedDate = date('Y/m/d', $timestamp);
                        $time12 = date('h:i', $timestamp) . ' ' . (date('a', $timestamp) === 'am' ? 'ص' : 'م');
                    ?>
                        <tr>
                            <td class="fw-bold text-center"><?php echo $idx + 1; ?></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($visit['student_name']); ?></td>
                            <td><span class="badge bg-light text-dark border px-2 py-1"><i class="far fa-calendar-alt me-2 text-muted"></i><?php echo $formattedDate; ?></span></td>
                            <td><span class="badge bg-light text-dark border px-2 py-1 d-inline-flex align-items-center gap-2"><i class="far fa-clock text-muted"></i><span dir="ltr"><?php echo $time12; ?></span></span></td>
                            <td><?php echo htmlspecialchars($visit['class_name'] ?? '-'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($visit['complaint'] ?? $visit['health_condition'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($visit['diagnosis'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars($visit['action_taken'] ?? '-'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($visit['treatment_taken'] ?? '-')); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($visit['notes'] ?? '-')); ?></td>
                            <td class="actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل" onclick="openEditVisitModal(<?php echo htmlspecialchars(json_encode($visit)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف" onclick="openDeleteVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars(addslashes($visit['student_name'])); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- مودال تخصيص أعمدة الجدول -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">اختر الأعمدة التي تريد عرضها في جدول زيارات العيادة:</p>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_seq" checked>
                            <label class="form-check-label" for="col_seq">مسلسل / #</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_student_name" checked>
                            <label class="form-check-label" for="col_student_name">اسم الطالب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_date" checked>
                            <label class="form-check-label" for="col_date">التاريخ</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_time" checked>
                            <label class="form-check-label" for="col_time">الوقت</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_class_name" checked>
                            <label class="form-check-label" for="col_class_name">الفصل</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_complaint" checked>
                            <label class="form-check-label" for="col_complaint">شكوى الطالب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_diagnosis" checked>
                            <label class="form-check-label" for="col_diagnosis">التشخيص الطبي</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_action_taken" checked>
                            <label class="form-check-label" for="col_action_taken">الإجراء المتخذ</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_treatment_taken" checked>
                            <label class="form-check-label" for="col_treatment_taken">العلاج الموصوف</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_notes" checked>
                            <label class="form-check-label" for="col_notes">ملاحظات</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_actions" checked>
                            <label class="form-check-label" for="col_actions">الإجراءات</label>
                        </div>
                    </div>
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

<!-- مودال تسجيل زيارة جديدة -->
<div class="modal fade" id="addVisitModal" tabindex="-1" aria-labelledby="addVisitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST" action="student_clinic.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="addVisitModalLabel"><i class="fas fa-notes-medical me-2"></i>تسجيل زيارة عيادة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                
                <div class="modal-body">
                    <!-- تصفية الطالب بالهيكل الأكاديمي -->
                    <div class="p-3 mb-3 bg-light rounded border">
                        <h6 class="fw-bold text-primary mb-2"><i class="fas fa-filter me-1"></i>فلترة وتسهيل الوصول للطالب</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">المرحلة</label>
                                <select id="modalFilterStage" class="form-select form-select-sm">
                                    <option value="">-- كل المراحل --</option>
                                    <?php foreach ($stages as $stg): ?>
                                        <option value="<?php echo $stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">الصف</label>
                                <select id="modalFilterGrade" class="form-select form-select-sm">
                                    <option value="">-- كل الصفوف --</option>
                                    <?php foreach ($grades as $grd): ?>
                                        <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>"><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">الفصل</label>
                                <select id="modalFilterClass" class="form-select form-select-sm">
                                    <option value="">-- كل الفصول --</option>
                                    <?php foreach ($classes as $cls): ?>
                                        <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>"><?php echo htmlspecialchars($cls['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">الطالب <span class="text-danger">*</span></label>
                            <select name="student_id" id="modalStudentId" class="form-select" required>
                                <option value="">اختر الطالب</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo (int)$student['id']; ?>"
                                            data-stage="<?php echo (int)($student['stage_id'] ?? 0); ?>"
                                            data-grade="<?php echo (int)($student['grade_id'] ?? 0); ?>"
                                            data-class="<?php echo (int)($student['class_id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">التاريخ <span class="text-danger">*</span></label>
                            <input type="text" name="visit_date" class="form-control flatpickr-date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوقت <span class="text-danger">*</span></label>
                            <input type="time" name="visit_time" class="form-control" value="<?php echo date('H:i'); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">شكوى الطالب</label>
                            <textarea name="complaint" class="form-control" rows="2" placeholder="وصف شكوى الطالب الأعراض..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">التشخيص الطبي</label>
                            <textarea name="diagnosis" class="form-control" rows="2" placeholder="التشخيص الطبي للحالة..."></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">الإجراء المتخذ</label>
                            <textarea name="action_taken" class="form-control" rows="2" placeholder="راحة، اتصال بولي الأمر، تحويل لطبيب..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">العلاج الموصوف</label>
                            <textarea name="treatment_taken" class="form-control" rows="2" placeholder="العلاج الذي تم صرفه أو إعطاؤه..."></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="أي ملاحظات إضافية..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ الزيارة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تعديل زيارة -->
<div class="modal fade" id="editVisitModal" tabindex="-1" aria-labelledby="editVisitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST" action="student_clinic.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="visit_id" id="editVisitId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="editVisitModalLabel"><i class="fas fa-edit me-2"></i>تعديل بيانات زيارة العيادة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">الطالب <span class="text-danger">*</span></label>
                            <select name="student_id" id="editStudentId" class="form-select" required>
                                <option value="">اختر الطالب</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo (int)$student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">التاريخ <span class="text-danger">*</span></label>
                            <input type="text" name="visit_date" id="editVisitDate" class="form-control flatpickr-date" required placeholder="اختر التاريخ...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوقت <span class="text-danger">*</span></label>
                            <input type="time" name="visit_time" id="editVisitTime" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">شكوى الطالب</label>
                            <textarea name="complaint" id="editComplaint" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">التشخيص الطبي</label>
                            <textarea name="diagnosis" id="editDiagnosis" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">الإجراء المتخذ</label>
                            <textarea name="action_taken" id="editActionTaken" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">العلاج الموصوف</label>
                            <textarea name="treatment_taken" id="editTreatmentTaken" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" id="editNotes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div class="modal fade" id="deleteVisitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST" action="student_clinic.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="visit_id" id="deleteVisitId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف زيارة العيادة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت تأكد من حذف سجل زيارة العيادة للطالب <span class="fw-bold text-primary" id="deleteStudentName"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        لا يمكن التراجع عن هذا الإجراء بعد الحذف.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>تأكيد الحذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تعديل الحالة الصحية للطالب -->
<div class="modal fade" id="editHealthModal" tabindex="-1" aria-labelledby="editHealthModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST" action="student_clinic.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="edit_health">
                <input type="hidden" name="student_id" id="editHealthStudentId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="editHealthModalLabel"><i class="fas fa-edit me-2"></i>تعديل الحالة الصحية للطالب: <span class="fw-bold" id="editHealthStudentName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <!-- الصف الأول -->
                        <div class="col-md-2">
                            <label class="form-label">فصيلة الدم</label>
                            <select class="form-select" name="blood_type" id="editHealthBloodType">
                                <option value="">-- اختر --</option>
                                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                                    <option value="<?php echo $bt; ?>"><?php echo $bt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">رقم التأمين الطبي</label>
                            <input type="text" class="form-control" name="insurance_number" id="editHealthInsuranceNumber" dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">تاريخ بداية التأمين</label>
                            <input type="text" class="form-control flatpickr-date" name="insurance_start_date" id="editHealthInsuranceStartDate" dir="ltr" placeholder="اختر التاريخ...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">تاريخ نهاية التأمين</label>
                            <input type="text" class="form-control flatpickr-date" name="insurance_end_date" id="editHealthInsuranceEndDate" dir="ltr" placeholder="اختر التاريخ...">
                        </div>

                        <!-- الصف الثاني -->
                        <div class="col-md-6">
                            <label class="form-label">الحالة الصحية العامة</label>
                            <textarea class="form-control" name="health_status" id="editHealthStatus" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الأمراض المزمنة</label>
                            <textarea class="form-control" name="chronic_diseases" id="editHealthChronic" rows="2"></textarea>
                        </div>

                        <!-- الصف الثالث -->
                        <div class="col-md-6">
                            <label class="form-label">الحساسية</label>
                            <textarea class="form-control" name="allergies" id="editHealthAllergies" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الإعاقات (إن وجدت)</label>
                            <textarea class="form-control" name="disabilities" id="editHealthDisabilities" rows="2"></textarea>
                        </div>

                        <!-- الصف الرابع -->
                        <div class="col-md-6">
                            <label class="form-label">العلاج / الأدوية</label>
                            <textarea class="form-control" name="medications" id="editHealthMeds" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">خطط علاجية متبعة</label>
                            <textarea class="form-control" name="treatment_plan" id="editHealthPlan" rows="2"></textarea>
                        </div>

                        <!-- الصف الخامس -->
                        <div class="col-md-6">
                            <label class="form-label">تقارير طبية سابقة</label>
                            <textarea class="form-control" name="previous_medical_reports" id="editHealthReports" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات طبية طارئة</label>
                            <textarea class="form-control" name="emergency_medical_notes" id="editHealthEmergency" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
<script src="../assets/js/admin-server-side-table.js"></script>
<script src="../assets/js/admin_table_actions.js"></script>

<script>
function openEditVisitModal(visit) {
    document.getElementById('editVisitId').value = visit.id;
    document.getElementById('editStudentId').value = visit.student_id;
    
    if (visit.visit_at) {
        const parts = visit.visit_at.split(' ');
        document.getElementById('editVisitDate').value = parts[0] || '';
        if (parts[1]) {
            document.getElementById('editVisitTime').value = parts[1].substring(0, 5);
        }
    }
    document.getElementById('editComplaint').value = visit.complaint || visit.health_condition || '';
    document.getElementById('editDiagnosis').value = visit.diagnosis || '';
    document.getElementById('editActionTaken').value = visit.action_taken || '';
    document.getElementById('editTreatmentTaken').value = visit.treatment_taken || '';
    document.getElementById('editNotes').value = visit.notes || '';

    new bootstrap.Modal(document.getElementById('editVisitModal')).show();
}

function openDeleteVisitModal(id, studentName) {
    document.getElementById('deleteVisitId').value = id;
    document.getElementById('deleteStudentName').textContent = studentName;
    new bootstrap.Modal(document.getElementById('deleteVisitModal')).show();
}

function openEditHealthModal(student) {
    document.getElementById('editHealthStudentId').value = student.id;
    document.getElementById('editHealthStudentName').textContent = student.student_name || '';
    document.getElementById('editHealthBloodType').value = student.blood_type || '';
    document.getElementById('editHealthInsuranceNumber').value = student.insurance_number || '';
    document.getElementById('editHealthInsuranceStartDate').value = student.insurance_start_date || '';
    document.getElementById('editHealthInsuranceEndDate').value = student.insurance_end_date || '';
    document.getElementById('editHealthStatus').value = student.health_status || '';
    document.getElementById('editHealthChronic').value = student.chronic_diseases || '';
    document.getElementById('editHealthAllergies').value = student.allergies || '';
    document.getElementById('editHealthDisabilities').value = student.disabilities || '';
    document.getElementById('editHealthMeds').value = student.medications || '';
    document.getElementById('editHealthPlan').value = student.treatment_plan || '';
    document.getElementById('editHealthReports').value = student.previous_medical_reports || '';
    document.getElementById('editHealthEmergency').value = student.emergency_medical_notes || '';

    new bootstrap.Modal(document.getElementById('editHealthModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.AdminServerSideTable && document.getElementById('clinicVisitsTable')) {
        window.AdminServerSideTable.init({ selector: '#clinicVisitsTable', url: 'ajax_clinic_datatable.php', order: [[2, 'desc']], language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الزيارات…', emptyTable: 'لا توجد زيارات مطابقة.' }, requestData: function () { return { type: 'visits', stage_id: document.getElementById('pageFilterStage').value, grade_id: document.getElementById('pageFilterGrade').value, class_id: document.getElementById('pageFilterClass').value, student_id: document.getElementById('pageFilterStudent').value, date_from: document.querySelector('[name="date_from"]').value, date_to: document.querySelector('[name="date_to"]').value }; } });
    }
    if (window.AdminServerSideTable && document.getElementById('studentHealthTable')) {
        window.AdminServerSideTable.init({ selector: '#studentHealthTable', url: 'ajax_clinic_datatable.php', order: [[1, 'asc']], language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل البيانات الصحية…', emptyTable: 'لا توجد بيانات صحية مطابقة.' }, requestData: function () { return { type: 'health', health_stage_id: document.getElementById('healthFilterStage').value, health_grade_id: document.getElementById('healthFilterGrade').value, health_class_id: document.getElementById('healthFilterClass').value }; } });
    }
    // 1. إعدادات أعمدة الجداول والتطبيق الفوري
    if (typeof initializeTableColumnSettings === 'function' && document.getElementById('clinicVisitsTable')) {
        initializeTableColumnSettings('clinicVisitsTable', {
            col_seq: 0,
            col_student_name: 1,
            col_date: 2,
            col_time: 3,
            col_class_name: 4,
            col_complaint: 5,
            col_diagnosis: 6,
            col_action_taken: 7,
            col_treatment_taken: 8,
            col_notes: 9,
            col_actions: 10
        }, 'clinic_visits_table_columns');
    }

    if (typeof initializeTableColumnSettings === 'function' && document.getElementById('studentHealthTable')) {
        initializeTableColumnSettings('studentHealthTable', {
            col_h_seq: 0,
            col_h_name: 1,
            col_h_class: 2,
            col_h_blood: 3,
            col_h_health_status: 4,
            col_h_chronic: 5,
            col_h_allergies: 6,
            col_h_disabilities: 7,
            col_h_ins_num: 8,
            col_h_ins_start: 9,
            col_h_ins_end: 10,
            col_h_meds: 11,
            col_h_plan: 12,
            col_h_reports: 13,
            col_h_emergency: 14
        }, 'student_health_table_columns');
    }

    // 2. تصفية خيارات خيارات الطالب في الفلتر العلوي حسب الهيكل
    const pageStageFilter = document.getElementById('pageFilterStage');
    const pageGradeFilter = document.getElementById('pageFilterGrade');
    const pageClassFilter = document.getElementById('pageFilterClass');
    const pageStudentFilter = document.getElementById('pageFilterStudent');

    if (pageStageFilter && pageGradeFilter && pageClassFilter) {
        pageStageFilter.addEventListener('change', function() {
            const stageId = this.value;
            pageGradeFilter.value = '';
            pageGradeFilter.querySelectorAll('option[data-stage]').forEach(opt => {
                opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
            });
            pageClassFilter.value = '';
            pageClassFilter.querySelectorAll('option[data-grade]').forEach(opt => { opt.style.display = 'none'; });
            this.form.submit();
        });

        pageGradeFilter.addEventListener('change', function() {
            const gradeId = this.value;
            pageClassFilter.value = '';
            pageClassFilter.querySelectorAll('option[data-grade]').forEach(opt => {
                opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
            });
            this.form.submit();
        });

        const initStageId = pageStageFilter.value;
        if (initStageId) {
            pageGradeFilter.querySelectorAll('option[data-stage]').forEach(opt => {
                opt.style.display = (opt.getAttribute('data-stage') === initStageId) ? '' : 'none';
            });
        }
        const initGradeId = pageGradeFilter.value;
        if (initGradeId) {
            pageClassFilter.querySelectorAll('option[data-grade]').forEach(opt => {
                opt.style.display = (opt.getAttribute('data-grade') === initGradeId) ? '' : 'none';
            });
        }
    }

    if (pageStageFilter && pageGradeFilter && pageClassFilter && pageStudentFilter) {
        function filterPageStudentOptions() {
            const stgId = pageStageFilter.value;
            const grdId = pageGradeFilter.value;
            const clsId = pageClassFilter.value;

            Array.from(pageStudentFilter.options).forEach(opt => {
                if (!opt.value) return;
                let show = true;
                if (stgId && opt.getAttribute('data-stage') !== stgId) show = false;
                if (grdId && opt.getAttribute('data-grade') !== grdId) show = false;
                if (clsId && opt.getAttribute('data-class') !== clsId) show = false;
                opt.style.display = show ? '' : 'none';
            });
        }
        filterPageStudentOptions();
    }

    // 3. تصفية وفلترة تبويب الحالة الصحية للطلاب
    const healthStageFilter = document.getElementById('healthFilterStage');
    const healthGradeFilter = document.getElementById('healthFilterGrade');
    const healthClassFilter = document.getElementById('healthFilterClass');

    if (healthStageFilter && healthGradeFilter && healthClassFilter) {
        healthStageFilter.addEventListener('change', function() {
            const stageId = this.value;
            healthGradeFilter.value = '';
            healthGradeFilter.querySelectorAll('option[data-stage]').forEach(opt => {
                opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
            });
            healthClassFilter.value = '';
            healthClassFilter.querySelectorAll('option[data-grade]').forEach(opt => { opt.style.display = 'none'; });
            this.form.submit();
        });

        healthGradeFilter.addEventListener('change', function() {
            const gradeId = this.value;
            healthClassFilter.value = '';
            healthClassFilter.querySelectorAll('option[data-grade]').forEach(opt => {
                opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
            });
            this.form.submit();
        });

        const initHStageId = healthStageFilter.value;
        if (initHStageId) {
            healthGradeFilter.querySelectorAll('option[data-stage]').forEach(opt => {
                opt.style.display = (opt.getAttribute('data-stage') === initHStageId) ? '' : 'none';
            });
        }
        const initHGradeId = healthGradeFilter.value;
        if (initHGradeId) {
            healthClassFilter.querySelectorAll('option[data-grade]').forEach(opt => {
                opt.style.display = (opt.getAttribute('data-grade') === initHGradeId) ? '' : 'none';
            });
        }
    }

    // 4. فلترة مودال تسجيل الزيارة
    const stageFilter = document.getElementById('modalFilterStage');
    const gradeFilter = document.getElementById('modalFilterGrade');
    const classFilter = document.getElementById('modalFilterClass');
    const studentSelect = document.getElementById('modalStudentId');

    if (!stageFilter || !gradeFilter || !classFilter || !studentSelect) return;

    function applyStudentFilters() {
        const stageId = stageFilter.value;
        const gradeId = gradeFilter.value;
        const classId = classFilter.value;

        Array.from(studentSelect.options).forEach(opt => {
            if (!opt.value) return;
            const optStage = opt.getAttribute('data-stage');
            const optGrade = opt.getAttribute('data-grade');
            const optClass = opt.getAttribute('data-class');

            let match = true;
            if (stageId && optStage !== stageId) match = false;
            if (gradeId && optGrade !== gradeId) match = false;
            if (classId && optClass !== classId) match = false;

            opt.style.display = match ? '' : 'none';
        });

        const selectedOpt = studentSelect.options[studentSelect.selectedIndex];
        if (selectedOpt && selectedOpt.style.display === 'none') {
            studentSelect.value = '';
        }
    }

    stageFilter.addEventListener('change', function() {
        const stageId = this.value;
        gradeFilter.value = '';
        gradeFilter.querySelectorAll('option[data-stage]').forEach(opt => {
            opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
        });
        classFilter.value = '';
        classFilter.querySelectorAll('option[data-grade]').forEach(opt => { opt.style.display = 'none'; });
        applyStudentFilters();
    });

    gradeFilter.addEventListener('change', function() {
        const gradeId = this.value;
        classFilter.value = '';
        classFilter.querySelectorAll('option[data-grade]').forEach(opt => {
            opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
        });
        applyStudentFilters();
    });

    classFilter.addEventListener('change', function() {
        applyStudentFilters();
    });
});
</script>
