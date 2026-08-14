<?php
/**
 * تعيين الطلاب للحافلات - Student Bus Assignments
 */
$page_title = "تعيين الحافلات للطلاب";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../classes/StudentOperationalGuard.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$studentOperationalGuard = new StudentOperationalGuard($db);
$currentAcademicYearId = AcademicYear::currentId($db);

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// ===== حفظ التعيينات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_bus') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $busId = !empty($_POST['bus_id']) ? (int)$_POST['bus_id'] : null;
        $backupBusId = !empty($_POST['backup_bus_id']) ? (int)$_POST['backup_bus_id'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if ($studentId) {
            try {
                $db->beginTransaction();
                $studentOperationalGuard->assertWritable($studentId);
                $existingSql = 'SELECT * FROM student_bus_assignments WHERE student_id = ?'
                    . ($currentAcademicYearId > 0 ? ' AND academic_year_id = ?' : ' AND academic_year_id IS NULL')
                    . ' FOR UPDATE';
                $existingParams = $currentAcademicYearId > 0
                    ? [$studentId, $currentAcademicYearId]
                    : [$studentId];
                $beforeStmt = $db->prepare($existingSql);
                $beforeStmt->execute($existingParams);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($busId || $backupBusId) {
                // تعيين أو تحديث (مرتبط بالعام الحالي)
                if ($currentAcademicYearId > 0) {
                    $stmt = $db->prepare("INSERT INTO student_bus_assignments (student_id, bus_id, backup_bus_id, notes, academic_year_id) VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE bus_id = VALUES(bus_id), backup_bus_id = VALUES(backup_bus_id), notes = VALUES(notes)");
                    $stmt->execute([$studentId, $busId, $backupBusId, $notes ?: null, $currentAcademicYearId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO student_bus_assignments (student_id, bus_id, backup_bus_id, notes) VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE bus_id = VALUES(bus_id), backup_bus_id = VALUES(backup_bus_id), notes = VALUES(notes)");
                    $stmt->execute([$studentId, $busId, $backupBusId, $notes ?: null]);
                }
                $_SESSION['success_message'] = 'تم تعيين الحافلة بنجاح.';
            } else {
                // إزالة التعيين
                if ($currentAcademicYearId > 0) {
                    $db->prepare("DELETE FROM student_bus_assignments WHERE student_id = ? AND academic_year_id = ?")->execute([$studentId, $currentAcademicYearId]);
                } else {
                    $db->prepare("DELETE FROM student_bus_assignments WHERE student_id = ? AND academic_year_id IS NULL")->execute([$studentId]);
                }
                $_SESSION['success_message'] = 'تم إزالة تعيين الحافلة.';
            }
                $afterStmt = $db->prepare(str_replace(' FOR UPDATE', '', $existingSql));
                $afterStmt->execute($existingParams);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $nameStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
                $nameStmt->execute([$studentId]);
                $studentName = (string)($nameStmt->fetchColumn() ?: ('طالب #' . $studentId));
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    'update', 'student_bus_assignment', $studentId, $studentName,
                    [
                        'academic_year' => $currentAcademicYearId ?: null,
                        'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff(
                            $before ?: [],
                            $after ?: []
                        ),
                        'undo_policy' => 'annual_assignment_restore_not_enabled',
                    ]
                );
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('student bus assignment save error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر حفظ تعيين الحافلة.';
            }
            header("Location: student_buses.php" . Utilities::buildQueryString(['stage_id', 'grade_id', 'class_id']));
            exit();
        }
    }
    // حفظ مجمع
    elseif ($_POST['action'] === 'bulk_assign') {
        $studentIds = $_POST['student_ids'] ?? [];
        $busIds = $_POST['bus_ids'] ?? [];
        $backupBusIds = $_POST['backup_bus_ids'] ?? [];
        $notesArr = $_POST['notes_arr'] ?? [];

        try {
            $db->beginTransaction();
            $studentOperationalGuard->assertWritableMany($studentIds);
            if ($currentAcademicYearId > 0) {
                $stmtUpsert = $db->prepare("INSERT INTO student_bus_assignments (student_id, bus_id, backup_bus_id, notes, academic_year_id) VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE bus_id = VALUES(bus_id), backup_bus_id = VALUES(backup_bus_id), notes = VALUES(notes)");
                $stmtDel = $db->prepare("DELETE FROM student_bus_assignments WHERE student_id = ? AND academic_year_id = ?");
            } else {
                $stmtUpsert = $db->prepare("INSERT INTO student_bus_assignments (student_id, bus_id, backup_bus_id, notes) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE bus_id = VALUES(bus_id), backup_bus_id = VALUES(backup_bus_id), notes = VALUES(notes)");
                $stmtDel = $db->prepare("DELETE FROM student_bus_assignments WHERE student_id = ? AND academic_year_id IS NULL");
            }

            $count = 0;
            $auditChanges = [];
            if (!empty($studentIds)) {
                // Fetch existing assignments for comparison to count only actual changes
                $placeholders = str_repeat('?,', count($studentIds) - 1) . '?';
                
                $existingQuery = "SELECT student_id, bus_id, backup_bus_id, notes FROM student_bus_assignments WHERE student_id IN ($placeholders)";
                $queryParams = $studentIds;
                if ($currentAcademicYearId > 0) {
                    $existingQuery .= " AND academic_year_id = ?";
                    $queryParams[] = $currentAcademicYearId;
                } else {
                    $existingQuery .= " AND academic_year_id IS NULL";
                }
                
                $stmtEx = $db->prepare($existingQuery);
                $stmtEx->execute($queryParams);
                $existingRows = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
                
                $existingMap = [];
                foreach ($existingRows as $row) {
                    $existingMap[$row['student_id']] = [
                        'bus_id' => $row['bus_id'],
                        'backup_bus_id' => $row['backup_bus_id'],
                        'notes' => $row['notes']
                    ];
                }

                for ($i = 0; $i < count($studentIds); $i++) {
                    $sid = (int)$studentIds[$i];
                    $bid = !empty($busIds[$i]) ? (int)$busIds[$i] : null;
                    $bbid = !empty($backupBusIds[$i]) ? (int)$backupBusIds[$i] : null;
                    $nt = trim($notesArr[$i] ?? '');
                    
                    $hasChanged = false;
                    $isCurrentlyAssigned = isset($existingMap[$sid]);
                    
                    if ($bid || $bbid) {
                        if (!$isCurrentlyAssigned) {
                            $hasChanged = true;
                        } else {
                            $oldBid = !empty($existingMap[$sid]['bus_id']) ? (int)$existingMap[$sid]['bus_id'] : null;
                            $oldBbid = !empty($existingMap[$sid]['backup_bus_id']) ? (int)$existingMap[$sid]['backup_bus_id'] : null;
                            $oldNt = trim((string)$existingMap[$sid]['notes']);
                            
                            if ($oldBid !== $bid ||
                                $oldBbid !== $bbid ||
                                $oldNt !== $nt) {
                                $hasChanged = true;
                            }
                        }

                        if ($currentAcademicYearId > 0) {
                            $stmtUpsert->execute([$sid, $bid, $bbid, $nt ?: null, $currentAcademicYearId]);
                        } else {
                            $stmtUpsert->execute([$sid, $bid, $bbid, $nt ?: null]);
                        }
                        
                        if ($hasChanged) {
                            $auditChanges[(string)$sid] = [
                                'from' => $existingMap[$sid] ?? null,
                                'to' => ['bus_id' => $bid, 'backup_bus_id' => $bbid, 'notes' => $nt ?: null],
                            ];
                            $count++;
                        }
                    } else {
                        if ($isCurrentlyAssigned) {
                            $auditChanges[(string)$sid] = [
                                'from' => $existingMap[$sid],
                                'to' => null,
                            ];
                            if ($currentAcademicYearId > 0) {
                                $stmtDel->execute([$sid, $currentAcademicYearId]);
                            } else {
                                $stmtDel->execute([$sid]);
                            }
                            $count++;
                        }
                    }
                }
            }
            if ($count > 0) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    'update', 'student_bus_assignment_bulk', $currentAcademicYearId ?: null,
                    'تحديث تعيينات حافلات الطلاب',
                    [
                        'academic_year' => $currentAcademicYearId ?: null,
                        'count' => $count,
                        'changes' => $auditChanges,
                        'undo_policy' => 'annual_assignment_restore_not_enabled',
                    ]
                );
            }
            $db->commit();
            
            if ($count > 0) {
                $_SESSION['success_message'] = "تم تحديث تعيينات $count طالب بنجاح.";
            } else {
                $_SESSION['success_message'] = "لم يتم إجراء أي تعديلات جديدة.";
            }
            header("Location: student_buses.php" . Utilities::buildQueryString(['stage_id', 'grade_id', 'class_id']));
            exit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('student bus bulk assignment error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'حدث خطأ أثناء الحفظ.';
            header("Location: student_buses.php" . Utilities::buildQueryString(['stage_id', 'grade_id', 'class_id']));
            exit();
        }
    }
}

// جلب البيانات
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY stage_id, id")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name, grade_id FROM classes WHERE status='active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
$activeBuses = $db->query("SELECT id, bus_number, area FROM buses WHERE status='active' ORDER BY bus_number")->fetchAll(PDO::FETCH_ASSOC);

// خريطة معرّف الباص → رقمه (لعرض رقم باص العام السابق للمرجعية)
$busNumberMap = [];
$allBusesMap = $db->query("SELECT id, bus_number FROM buses")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allBusesMap as $b) $busNumberMap[(int)$b['id']] = $b['bus_number'];

// فلاتر
$filterStage = !empty($_GET['stage_id']) ? (int)$_GET['stage_id'] : '';
$filterGrade = !empty($_GET['grade_id']) ? (int)$_GET['grade_id'] : '';
$filterClass = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : '';

// Auto-resolve parents if a deep level is selected directly (Table filters reload)
if ($filterClass) {
    $stmtLoc = $db->prepare("SELECT c.grade_id, g.stage_id FROM classes c JOIN grades g ON c.grade_id = g.id WHERE c.id = ?");
    $stmtLoc->execute([$filterClass]);
    $locData = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    if ($locData) {
        if (!$filterGrade) $filterGrade = (int)$locData['grade_id'];
        if (!$filterStage) $filterStage = (int)$locData['stage_id'];
    }
} elseif ($filterGrade) {
    $stmtLoc = $db->prepare("SELECT stage_id FROM grades WHERE id = ?");
    $stmtLoc->execute([$filterGrade]);
    $locData = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    if ($locData) {
        if (!$filterStage) $filterStage = (int)$locData['stage_id'];
    }
}

// بناء استعلام الطلاب
$where = ["u.role = 'student'", "u.status = 'active'", "u.deleted_at IS NULL"];
$params = [];

if ($currentAcademicYearId > 0) {
    $where[] = "se.enrollment_status = 'enrolled'";
}

if (!empty($filterClass)) {
    $where[] = $currentAcademicYearId > 0 ? "se.class_id = ?" : "u.class_id = ?";
    $params[] = $filterClass;
} elseif (!empty($filterGrade)) {
    $where[] = $currentAcademicYearId > 0 ? "se.grade_id = ?" : "c.grade_id = ?";
    $params[] = $filterGrade;
} elseif (!empty($filterStage)) {
    $where[] = $currentAcademicYearId > 0 ? "se.stage_id = ?" : "g.stage_id = ?";
    $params[] = $filterStage;
}

// العام السابق (الأحدث قبل العام الحالي) — لعرض رقم باص العام الماضي للمرجعية
$prevYearId = 0;
if ($currentAcademicYearId > 0) {
    $prevStmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 0 ORDER BY id DESC LIMIT 1");
    $prevStmt->execute([$currentAcademicYearId]);
    $prevYearId = (int) ($prevStmt->fetchColumn() ?: 0);
}
$prevBusSubquery = $prevYearId > 0
    ? "(SELECT sba2.bus_id FROM student_bus_assignments sba2 WHERE sba2.student_id = u.id AND sba2.academic_year_id = {$prevYearId} LIMIT 1)"
    : "NULL";
$prevBusBackupSubquery = $prevYearId > 0
    ? "(SELECT sba3.backup_bus_id FROM student_bus_assignments sba3 WHERE sba3.student_id = u.id AND sba3.academic_year_id = {$prevYearId} LIMIT 1)"
    : "NULL";

$enrollJoin = $currentAcademicYearId > 0
    ? "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
       LEFT JOIN classes c ON c.id = se.class_id"
    : "LEFT JOIN classes c ON u.class_id = c.id";

$sql = "SELECT u.id, u.name, u.class_id, c.name as class_name,
    g.grade_name, s.stage_name, sp.city_area, sp.address_current,
    sba.bus_id as assigned_bus_id, sba.backup_bus_id as assigned_backup_bus_id, sba.notes as bus_notes,
    {$prevBusSubquery} as previous_bus_id,
    {$prevBusBackupSubquery} as previous_backup_bus_id
    FROM users u
    {$enrollJoin}
    LEFT JOIN grades g ON c.grade_id = g.id
    LEFT JOIN stages s ON g.stage_id = s.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    LEFT JOIN student_bus_assignments sba ON u.id = sba.student_id AND sba.academic_year_id = {$currentAcademicYearId}
    WHERE " . implode(' AND ', $where) . "
    ORDER BY u.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات تعيين الطلاب للحافلات للعام الدراسي الحالي
$totalStudents = (int)$db->query("SELECT COUNT(*) FROM users u 
    JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND se.enrollment_status = 'enrolled'")->fetchColumn();

$primaryAssignedCount = (int)$db->query("SELECT COUNT(*) FROM student_bus_assignments sba 
    JOIN users u ON sba.student_id = u.id 
    JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND se.enrollment_status = 'enrolled'
    AND sba.academic_year_id = {$currentAcademicYearId} AND sba.bus_id IS NOT NULL")->fetchColumn();

$backupAssignedCount = (int)$db->query("SELECT COUNT(*) FROM student_bus_assignments sba 
    JOIN users u ON sba.student_id = u.id 
    JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND se.enrollment_status = 'enrolled'
    AND sba.academic_year_id = {$currentAcademicYearId} AND sba.backup_bus_id IS NOT NULL")->fetchColumn();

$unassignedCount = (int)$db->query("SELECT COUNT(*) FROM users u 
    JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND se.enrollment_status = 'enrolled'
    AND NOT EXISTS (
        SELECT 1 FROM student_bus_assignments sba 
        WHERE sba.student_id = u.id AND sba.academic_year_id = {$currentAcademicYearId} 
        AND (sba.bus_id IS NOT NULL OR sba.backup_bus_id IS NOT NULL)
    )")->fetchColumn();

// Load cascading dropdown options matching the filter (similar to buses.php)
if ($filterStage) {
    $stmt = $db->prepare("SELECT id, grade_name, stage_id FROM grades WHERE stage_id = ? ORDER BY id");
    $stmt->execute([$filterStage]);
    $filterGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterGrades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY stage_id, id")->fetchAll(PDO::FETCH_ASSOC);
}

if ($filterGrade) {
    $stmt = $db->prepare("SELECT id, name, grade_id FROM classes WHERE grade_id = ? AND status='active' ORDER BY name");
    $stmt->execute([$filterGrade]);
    $filterClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterClasses = $db->query("SELECT id, name, grade_id FROM classes WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<!-- عنوان الصفحة -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-bus me-2"></i>تعيين الحافلات للطلاب</h1>
</div>

<!-- كروت الإحصائيات -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
      <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-card-info">
        <div class="stat-card-number counter" data-target="<?php echo $totalStudents; ?>">0</div>
        <div class="stat-card-label">إجمالي الطلاب</div>
        <div class="stat-card-sub"><i class="fas fa-info-circle"></i> طلاب مسجلون بالعام الحالي</div>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
      <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
      <div class="stat-card-info">
        <div class="stat-card-number counter" data-target="<?php echo $primaryAssignedCount; ?>">0</div>
        <div class="stat-card-label">تعيين أساسي</div>
        <div class="stat-card-sub"><i class="fas fa-check-circle"></i> مسندين لحافلة رئيسية</div>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
      <div class="stat-card-icon"><i class="fas fa-sync-alt"></i></div>
      <div class="stat-card-info">
        <div class="stat-card-number counter" data-target="<?php echo $backupAssignedCount; ?>">0</div>
        <div class="stat-card-label">تعيين احتياطي</div>
        <div class="stat-card-sub"><i class="fas fa-info"></i> مسندين لحافلة بديلة</div>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
      <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-card-info">
        <div class="stat-card-number counter" data-target="<?php echo $unassignedCount; ?>">0</div>
        <div class="stat-card-label">غير معينين</div>
        <div class="stat-card-sub"><i class="fas fa-exclamation-triangle"></i> بانتظار تعيين حافلة</div>
      </div>
    </div>
  </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- جدول الطلاب الموحد -->
<form method="GET" class="admin-filter-bar" id="busFilterForm">
    <!-- الفلاتر والبحث من جهة اليمين -->
    <div class="admin-filter-controls">
                <!-- المرحلة -->
                <select name="stage_id" class="form-select form-select-sm" style="width:auto; min-width:130px;" id="filterStage" onchange="this.form.submit()">
                    <option value="">كل المراحل</option>
                    <?php foreach ($stages as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $filterStage == $st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['stage_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- الصف -->
                <select name="grade_id" class="form-select form-select-sm" style="width:auto; min-width:120px;" id="filterGrade" onchange="this.form.submit()">
                    <option value="">كل الصفوف</option>
                    <?php foreach ($filterGrades as $gr): ?>
                        <option value="<?php echo $gr['id']; ?>" data-stage="<?php echo $gr['stage_id']; ?>"
                            <?php echo $filterGrade == $gr['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($gr['grade_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- الفصل -->
                <select name="class_id" class="form-select form-select-sm" style="width:auto; min-width:110px;" id="filterClass" onchange="this.form.submit()">
                    <option value="">كل الفصول</option>
                    <?php foreach ($filterClasses as $cl): ?>
                        <option value="<?php echo $cl['id']; ?>" data-grade="<?php echo $cl['grade_id']; ?>"
                            <?php echo $filterClass == $cl['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cl['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <!-- البحث الحي باسم الطالب -->
                <div class="position-relative" style="width: 180px;">
                    <input type="text" id="liveStudentSearch" class="form-control form-control-sm pe-4" placeholder="بحث باسم الطالب...">
                    <i class="fas fa-search position-absolute top-50 end-0 translate-middle-y text-muted me-2"></i>
                </div>
    </div>

    <!-- الأزرار من جهة اليسار -->
    <div class="admin-filter-actions">
                <a href="student_buses.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="إعدادات الجدول">
                    <i class="fas fa-cog me-1"></i>إعدادات الجدول
                </button>
    </div>
</form>

<div class="admin-list-surface">
<form method="POST" id="bulkForm" action="student_buses.php">
    <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="bulk_assign">
            
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div class="text-muted small"><i class="fas fa-info-circle me-1 text-primary"></i>يمكنك تعديل حافلات وملاحظات الطلاب بالجدول مباشرة، ثم حفظ جميع البيانات دفعة واحدة.</div>
                <button type="submit" class="btn btn-success px-4 py-2 shadow-sm"><i class="fas fa-save me-1"></i>حفظ جميع التعيينات</button>
            </div>

            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover align-middle admin-data-table" id="studentBusesTable">
                    <thead>
                        <tr>
                            <th data-col="col_num" width="50" class="text-center">#</th>
                            <th data-col="col_name">اسم الطالب</th>
                            <th data-col="col_grade">الصف</th>
                            <th data-col="col_class">الفصل</th>
                            <th data-col="col_area">المنطقة</th>
                            <th data-col="col_address">العنوان التفصيلي</th>
                            <th data-col="col_prev_bus" class="text-center">باص العام السابق</th>
                            <th data-col="col_primary_bus" width="140">الحافلة الأساسية</th>
                            <th data-col="col_backup_bus" width="140">الحافلة الاحتياطية</th>
                            <th data-col="col_notes" width="220">ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 0; foreach ($students as $student): $i++; ?>
                        <tr>
                            <td data-col="col_num" class="text-center text-secondary fw-bold"><?php echo $i; ?></td>
                            <td data-col="col_name">
                                <strong><?php echo htmlspecialchars($student['name']); ?></strong>
                                <input type="hidden" name="student_ids[]" value="<?php echo $student['id']; ?>">
                            </td>
                            <td data-col="col_grade">
                                <span class="badge bg-secondary-subtle text-secondary border" style="font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($student['grade_name'] ?? '—'); ?>
                                </span>
                            </td>
                            <td data-col="col_class">
                                <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($student['class_name'] ?? 'بدون فصل'); ?>
                                </span>
                            </td>
                            <td data-col="col_area"><small class="text-secondary"><?php echo htmlspecialchars($student['city_area'] ?? '—'); ?></small></td>
                            <td data-col="col_address"><small class="text-secondary"><?php echo htmlspecialchars($student['address_current'] ?? '—'); ?></small></td>
                            <td data-col="col_prev_bus" class="text-center">
                                <?php
                                    $prevBus = $busNumberMap[(int)($student['previous_bus_id'] ?? 0)] ?? null;
                                    $prevBackup = $busNumberMap[(int)($student['previous_backup_bus_id'] ?? 0)] ?? null;
                                ?>
                                <?php if ($prevBus !== null || $prevBackup !== null): ?>
                                    <?php if ($prevBus !== null): ?>
                                        <span class="badge bg-secondary px-2.5 py-1 rounded" title="الباص الأساسي السابق"><?php echo htmlspecialchars($prevBus); ?></span>
                                    <?php endif; ?>
                                    <?php if ($prevBackup !== null): ?>
                                        <span class="badge bg-light text-secondary border px-2.5 py-1 rounded ms-1" title="الباص الاحتياطي السابق"><i class="fas fa-sync-alt me-1"></i><?php echo htmlspecialchars($prevBackup); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                  <?php endif; ?>
                            </td>
                            <td data-col="col_primary_bus">
                                <?php $isPrimaryAssigned = !empty($student['assigned_bus_id']); ?>
                                <select name="bus_ids[]" class="form-select form-select-sm <?php echo $isPrimaryAssigned ? 'bg-success-subtle border-success' : ''; ?>" onchange="this.classList.toggle('bg-success-subtle', this.value !== ''); this.classList.toggle('border-success', this.value !== '');">
                                    <option value="">-- بدون --</option>
                                    <?php foreach ($activeBuses as $bus): ?>
                                        <option value="<?php echo $bus['id']; ?>"
                                            <?php echo ($student['assigned_bus_id'] ?? '') == $bus['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bus['bus_number']); ?>
                                            <?php if ($bus['area']): ?>(<?php echo htmlspecialchars($bus['area']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td data-col="col_backup_bus">
                                <?php $isBackupAssigned = !empty($student['assigned_backup_bus_id']); ?>
                                <select name="backup_bus_ids[]" class="form-select form-select-sm <?php echo $isBackupAssigned ? 'bg-success-subtle border-success' : ''; ?>" onchange="this.classList.toggle('bg-success-subtle', this.value !== ''); this.classList.toggle('border-success', this.value !== '');">
                                    <option value="">-- بدون --</option>
                                    <?php foreach ($activeBuses as $bus): ?>
                                        <option value="<?php echo $bus['id']; ?>"
                                            <?php echo ($student['assigned_backup_bus_id'] ?? '') == $bus['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bus['bus_number']); ?>
                                            <?php if ($bus['area']): ?>(<?php echo htmlspecialchars($bus['area']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td data-col="col_notes">
                                <input type="text" name="notes_arr[]" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($student['bus_notes'] ?? ''); ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <div class="text-muted small">تأكد من مراجعة الباصات قبل الحفظ.</div>
                <button type="submit" class="btn btn-success px-4 py-2 shadow-sm"><i class="fas fa-save me-1"></i>حفظ جميع التعيينات</button>
            </div>
    </form>
</div>

<!-- مودال إعدادات الجدول -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="tableSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">اختر الأعمدة التي ترغب في إظهارها في الجدول:</p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_num" checked>
                            <label class="form-check-label" for="col_num">#</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_name" checked>
                            <label class="form-check-label" for="col_name">اسم الطالب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_grade" checked>
                            <label class="form-check-label" for="col_grade">الصف</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_class" checked>
                            <label class="form-check-label" for="col_class">الفصل</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_area" checked>
                            <label class="form-check-label" for="col_area">المنطقة</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_address" checked>
                            <label class="form-check-label" for="col_address">العنوان التفصيلي</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_prev_bus" checked>
                            <label class="form-check-label" for="col_prev_bus">باص العام السابق</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_primary_bus" checked>
                            <label class="form-check-label" for="col_primary_bus">الحافلة الأساسية</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_backup_bus" checked>
                            <label class="form-check-label" for="col_backup_bus">الحافلة الاحتياطية</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_notes" checked>
                            <label class="form-check-label" for="col_notes">ملاحظات</label>
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

<script src="../assets/js/admin_table_actions.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // منع تنفيذ نفس سكربت الصفحة أكثر من مرة في نفس دورة حياة الصفحة.
    if (window.__studentBusesPageInitDone) {
        return;
    }
    window.__studentBusesPageInitDone = true;

    // ======= Cascading Dropdowns Logic =======
    var stageF = document.getElementById('filterStage');
    var gradeF = document.getElementById('filterGrade');
    var classF = document.getElementById('filterClass');

    if (stageF) stageF.addEventListener('change', function() {
        var sid = this.value;
        gradeF.value = ''; 
        classF.value = '';
        Array.from(gradeF.querySelectorAll('option[data-stage]')).forEach(function(o) {
            o.style.display = (!sid || o.dataset.stage === sid) ? '' : 'none';
        });
        filterClasses();
    });

    if (gradeF) gradeF.addEventListener('change', function() {
        classF.value = '';
        filterClasses();
    });

    function filterClasses() {
        var gid = gradeF.value;
        Array.from(classF.querySelectorAll('option[data-grade]')).forEach(function(o) {
            o.style.display = (!gid || o.dataset.grade === gid) ? '' : 'none';
        });
    }

    // ======= Dynamic Row Striping (تخطيط الصفوف الملونة الحية) =======
    function applyStriping() {
        var visibleIndex = 0;
        var rows = document.querySelectorAll('#studentBusesTable tbody tr');
        rows.forEach(function(row) {
            if (row.style.display !== 'none') {
                var bgColor = (visibleIndex % 2 === 0) ? '#ffffff' : '#f9f9f9';
                row.querySelectorAll('td').forEach(function(td) {
                    td.style.backgroundColor = bgColor;
                });
                visibleIndex++;
            }
        });
    }

    // ======= Vanilla JS Live Search (البحث الحي) =======
    var searchInput = document.getElementById('liveStudentSearch');
    if (searchInput) {
        // منع إرسال النموذج عند الضغط على Enter في حقل البحث
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        
        searchInput.addEventListener('input', function() {
            var filter = this.value.trim().toLowerCase();
            var rows = document.querySelectorAll('#studentBusesTable tbody tr');
            rows.forEach(function(row) {
                var nameCell = row.querySelector('[data-col="col_name"]');
                if (nameCell) {
                    var nameText = nameCell.textContent || nameCell.innerText;
                    if (nameText.toLowerCase().indexOf(filter) > -1) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
            applyStriping(); // إعادة تطبيق التلوين المخطط على الصفوف الظاهرة فقط بعد الفلترة
        });
    }

    // ======= Vanilla JS Column Sorting (ترتيب الأعمدة) =======
    var headers = document.querySelectorAll('#studentBusesTable thead th');
    headers.forEach(function(th, index) {
        th.style.cursor = 'pointer';
        th.style.userSelect = 'none';
        
        th.addEventListener('click', function() {
            var table = th.closest('table');
            var tbody = table.querySelector('tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var asc = th.getAttribute('data-sort') !== 'asc';
            
            // إعادة ضبط مؤشرات الترتيب لجميع الترويسات الأخرى
            headers.forEach(function(h) {
                h.removeAttribute('data-sort');
                var icon = h.querySelector('.sort-icon');
                if (icon) icon.className = 'sort-icon fas fa-sort text-muted ms-1';
            });
            
            th.setAttribute('data-sort', asc ? 'asc' : 'desc');
            var icon = th.querySelector('.sort-icon');
            if (icon) {
                icon.className = 'sort-icon fas ' + (asc ? 'fa-sort-up' : 'fa-sort-down') + ' text-primary ms-1';
            }
            
            rows.sort(function(a, b) {
                var valA = getCellValue(a, index);
                var valB = getCellValue(b, index);
                
                if (valA === valB) return 0;
                
                // الترتيب الرقمي إذا كانت القيم أرقاماً
                if (!isNaN(valA) && !isNaN(valB) && valA !== '' && valB !== '') {
                    return (parseFloat(valA) - parseFloat(valB)) * (asc ? 1 : -1);
                }
                // الترتيب الأبجدي الذكي للغة العربية
                return valA.localeCompare(valB, 'ar', { sensitivity: 'base' }) * (asc ? 1 : -1);
            });
            
            rows.forEach(function(row) { tbody.appendChild(row); });
            applyStriping(); // إعادة تطبيق التلوين المخطط على الصفوف بعد إعادة الترتيب
        });
        
        // إضافة أيقونة الترتيب الافتراضية
        if (!th.querySelector('.sort-icon')) {
            var icon = document.createElement('i');
            icon.className = 'sort-icon fas fa-sort text-muted ms-1';
            icon.style.fontSize = '0.8rem';
            th.appendChild(icon);
        }
    });

    function getCellValue(row, index) {
        var cell = row.children[index];
        if (!cell) return '';
        var select = cell.querySelector('select');
        if (select) return select.options[select.selectedIndex].text.trim();
        var input = cell.querySelector('input[type="text"]');
        if (input) return input.value.trim();
        return (cell.textContent || cell.innerText).trim();
    }

    // ======= Initialize Table Column Settings (إخفاء وإظهار الأعمدة) =======
    initializeTableColumnSettings('studentBusesTable', {
        col_num: 0,
        col_name: 1,
        col_grade: 2,
        col_class: 3,
        col_area: 4,
        col_address: 5,
        col_prev_bus: 6,
        col_primary_bus: 7,
        col_backup_bus: 8,
        col_notes: 9
    }, 'student_buses_columns');

    // ======= Handle Bulk Form Loading State =======
    var bulkForm = document.getElementById('bulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function() {
            var btn = bulkForm.querySelector('button[type="submit"]');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> جاري الحفظ...';
                btn.disabled = true;
            }
        });
    }

    // تلوين الصفوف الأولي عند تحميل الصفحة
    applyStriping();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
