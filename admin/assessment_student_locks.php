<?php
$page_title = "أقفال درجات الطلاب";
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

function student_locks_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function student_locks_redirect(): void
{
    header('Location: assessment_student_locks.php');
    exit();
}

function student_locks_assert_current_year(?int $currentAcademicYearId, int $academicYearId, string $message = 'هذا القفل لا يتبع العام الدراسي المختار.'): void
{
    if ((int) $currentAcademicYearId > 0 && $academicYearId !== (int) $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

$studentLocksReady = student_locks_table_exists($db, 'assessment_student_locks');
$enrollmentsReady = student_locks_table_exists($db, 'student_enrollments');
$calendarReady = student_locks_table_exists($db, 'academic_years');
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';
$lockLabels = ['graduated' => 'خريج', 'transferred' => 'منقول', 'manual' => 'يدوي'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$studentLocksReady) {
            throw new RuntimeException('جدول أقفال درجات الطلاب غير مطبق بعد.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'sync_student_locks') {
            $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
            if ($academicYearId <= 0) {
                throw new InvalidArgumentException('اختر العام الدراسي لمزامنة الأقفال.');
            }
            student_locks_assert_current_year($currentAcademicYearId, $academicYearId, 'لا يمكن مزامنة أقفال طلاب خارج العام الدراسي المختار.');
            $affected = (new AssessmentEngine($db))->syncStudentLocksFromEnrollments($academicYearId, (int) ($_SESSION['user_id'] ?? 0) ?: null);
            ActivityLog::logUpdate('assessment_student_lock', $academicYearId, 'مزامنة أقفال الطلاب', [
                'academic_year' => $academicYearId,
                'count' => $affected,
            ]);
            $_SESSION['success_message'] = "تمت مزامنة أقفال الطلاب. عدد السجلات المتأثرة: {$affected}.";
            student_locks_redirect();
        }

        if ($action === 'add_manual_student_lock') {
            $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($academicYearId <= 0 || $studentId <= 0) {
                throw new InvalidArgumentException('اختر الطالب والعام الدراسي للقفل اليدوي.');
            }
            student_locks_assert_current_year($currentAcademicYearId, $academicYearId, 'لا يمكن إضافة قفل يدوي خارج العام الدراسي المختار.');
            $studentStmt = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'student' LIMIT 1");
            $studentStmt->execute([$studentId]);
            $studentName = $studentStmt->fetchColumn();
            if (!$studentName) {
                throw new InvalidArgumentException('الطالب المحدد غير موجود.');
            }
            $existingLockStmt = $db->prepare('SELECT lock_reason FROM assessment_student_locks WHERE student_id = ? AND academic_year_id = ? LIMIT 1');
            $existingLockStmt->execute([$studentId, $academicYearId]);
            $existingLockReason = $existingLockStmt->fetchColumn();
            if ($existingLockReason && $existingLockReason !== 'manual') {
                throw new InvalidArgumentException('هذا الطالب مقفول بالفعل بسبب التخرج أو النقل. لا يمكن تحويل هذا القفل إلى قفل يدوي من هنا.');
            }
            $stmt = $db->prepare("INSERT INTO assessment_student_locks
                (student_id, academic_year_id, lock_reason, locked_by, notes)
                VALUES (?, ?, 'manual', ?, ?)
                ON DUPLICATE KEY UPDATE
                    lock_reason = IF(lock_reason = 'manual', VALUES(lock_reason), lock_reason),
                    locked_by = IF(lock_reason = 'manual', VALUES(locked_by), locked_by),
                    notes = IF(lock_reason = 'manual', VALUES(notes), notes)");
            $stmt->execute([$studentId, $academicYearId, (int) ($_SESSION['user_id'] ?? 0) ?: null, $notes !== '' ? $notes : 'قفل يدوي من الأدمن']);
            ActivityLog::logCreate('assessment_student_lock', $studentId, (string) $studentName, [
                'academic_year' => $academicYearId,
                'student_id' => $studentId,
                'lock_reason' => 'manual',
                'notes' => $notes !== '' ? $notes : 'قفل يدوي من الأدمن',
            ]);
            $_SESSION['success_message'] = 'تم حفظ القفل اليدوي للطالب.';
            student_locks_redirect();
        }

        if ($action === 'update_manual_student_lock') {
            $lockId = (int) ($_POST['lock_id'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $lockStmt = $db->prepare("SELECT asl.*, u.name AS student_name
                FROM assessment_student_locks asl
                JOIN users u ON u.id = asl.student_id
                WHERE asl.id = ? LIMIT 1");
            $lockStmt->execute([$lockId]);
            $lock = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lock) {
                throw new InvalidArgumentException('القفل غير موجود.');
            }
            if (($lock['lock_reason'] ?? '') !== 'manual') {
                throw new InvalidArgumentException('لا يمكن تعديل قفل التخرج أو النقل من هنا. غيّر حالة قيد الطالب أو نفذ المزامنة.');
            }
            student_locks_assert_current_year($currentAcademicYearId, (int) $lock['academic_year_id'], 'لا يمكن تعديل قفل يدوي خارج العام الدراسي المختار.');
            $db->prepare('UPDATE assessment_student_locks SET notes = ?, locked_by = ? WHERE id = ? AND lock_reason = ?')
                ->execute([$notes !== '' ? $notes : 'قفل يدوي من الأدمن', (int) ($_SESSION['user_id'] ?? 0) ?: null, $lockId, 'manual']);
            ActivityLog::logUpdate('assessment_student_lock', (int) $lock['student_id'], (string) $lock['student_name'], [
                'academic_year' => (int) $lock['academic_year_id'],
                'old_notes' => $lock['notes'] ?? null,
                'new_notes' => $notes !== '' ? $notes : 'قفل يدوي من الأدمن',
            ]);
            $_SESSION['success_message'] = 'تم تعديل القفل اليدوي.';
            student_locks_redirect();
        }

        if ($action === 'delete_manual_student_lock') {
            $lockId = (int) ($_POST['lock_id'] ?? 0);
            $lockStmt = $db->prepare("SELECT asl.*, u.name AS student_name
                FROM assessment_student_locks asl
                JOIN users u ON u.id = asl.student_id
                WHERE asl.id = ? LIMIT 1");
            $lockStmt->execute([$lockId]);
            $lock = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lock) {
                throw new InvalidArgumentException('القفل غير موجود.');
            }
            if (($lock['lock_reason'] ?? '') !== 'manual') {
                throw new InvalidArgumentException('لا يمكن فك قفل التخرج أو النقل من هنا. غيّر حالة قيد الطالب أو نفذ المزامنة.');
            }
            student_locks_assert_current_year($currentAcademicYearId, (int) $lock['academic_year_id'], 'لا يمكن فك قفل يدوي خارج العام الدراسي المختار.');
            $db->prepare('DELETE FROM assessment_student_locks WHERE id = ? AND lock_reason = ?')->execute([$lockId, 'manual']);
            ActivityLog::logDelete('assessment_student_lock', (int) $lock['student_id'], (string) $lock['student_name'], [
                'academic_year' => (int) $lock['academic_year_id'],
                'student_id' => (int) $lock['student_id'],
                'lock_reason' => 'manual',
                'notes' => $lock['notes'] ?? null,
            ]);
            $_SESSION['success_message'] = 'تم فك القفل اليدوي.';
            student_locks_redirect();
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        student_locks_redirect();
    }
}

$academicYears = [];
$lockableStudents = [];
$studentLocks = [];
$locksCount = 0;
$manualLocksCount = 0;
$graduatedLocksCount = 0;
$transferredLocksCount = 0;

if ($calendarReady) {
    $academicYears = $db->query("SELECT id, name, is_active FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($currentAcademicYearId > 0 && $enrollmentsReady) {
    $studentStmt = $db->prepare("SELECT u.id, u.name, u.username, c.name AS class_name
        FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        LEFT JOIN classes c ON c.id = se.class_id
        WHERE se.academic_year_id = ?
          AND se.enrollment_status = 'enrolled'
          AND u.role = 'student'
          AND u.status = 'active'
          AND u.deleted_at IS NULL
        ORDER BY c.name, u.name");
    $studentStmt->execute([$currentAcademicYearId]);
    $lockableStudents = $studentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($studentLocksReady) {
    $lockParams = [];
    $lockYearWhere = '';
    if ($currentAcademicYearId > 0) {
        $lockYearWhere = 'WHERE asl.academic_year_id = ?';
        $lockParams[] = $currentAcademicYearId;
    }
    $lockStmt = $db->prepare("SELECT asl.*, u.name AS student_name, u.username, ay.name AS academic_year_name,
            locker.name AS locked_by_name
        FROM assessment_student_locks asl
        JOIN users u ON u.id = asl.student_id
        JOIN academic_years ay ON ay.id = asl.academic_year_id
        LEFT JOIN users locker ON locker.id = asl.locked_by
        {$lockYearWhere}
        ORDER BY asl.locked_at DESC, asl.id DESC
        LIMIT 300");
    $lockStmt->execute($lockParams);
    $studentLocks = $lockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $locksCount = count($studentLocks);
    foreach ($studentLocks as $lock) {
        if (($lock['lock_reason'] ?? '') === 'manual') {
            $manualLocksCount++;
        } elseif (($lock['lock_reason'] ?? '') === 'graduated') {
            $graduatedLocksCount++;
        } elseif (($lock['lock_reason'] ?? '') === 'transferred') {
            $transferredLocksCount++;
        }
    }
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-lock me-2 text-primary"></i>أقفال درجات الطلاب</h1>
    <div class="admin-top-actions no-print">
        <?php if ($studentLocksReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addManualLockModal">
                <i class="fas fa-lock me-2"></i>قفل يدوي
            </button>
            <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#syncLocksModal">
                <i class="fas fa-arrows-rotate me-2"></i>مزامنة
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>



<?php if (!$studentLocksReady): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>جدول أقفال درجات الطلاب غير مطبق بعد.</div>
<?php else: ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-user-lock"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$locksCount; ?>">0</div><div class="stat-card-label">إجمالي الأقفال</div><div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'كل الأعوام', ENT_QUOTES, 'UTF-8'); ?></div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-lock"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$manualLocksCount; ?>">0</div><div class="stat-card-label">أقفال يدوية</div><div class="stat-card-sub">من الإدارة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$graduatedLocksCount; ?>">0</div><div class="stat-card-label">خريجون</div><div class="stat-card-sub">عرض فقط</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #6b7280, #374151);"><div class="stat-card-icon"><i class="fas fa-school-circle-xmark"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$transferredLocksCount; ?>">0</div><div class="stat-card-label">منقولون</div><div class="stat-card-sub">عرض فقط</div></div></div></div>
</div>

<div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>الطالب الخريج أو المنقول إلى مدرسة أخرى تُقفل درجاته في محرك الدرجات الجديد وتظل قابلة للعرض فقط.</div>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle datatable admin-data-table">
                <thead><tr><th>الطالب</th><th>العام</th><th>سبب القفل</th><th>وقت القفل</th><th>بواسطة</th><th>ملاحظات</th><th class="admin-col-120px">إجراء</th></tr></thead>
                <tbody>
                <?php if (empty($studentLocks)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">لا توجد أقفال درجات في العام المختار حتى الآن.</td></tr>
                <?php else: ?>
                    <?php foreach ($studentLocks as $lock): ?>
                        <?php $lockClass = $lock['lock_reason'] === 'graduated' ? 'bg-primary' : ($lock['lock_reason'] === 'transferred' ? 'bg-secondary' : 'bg-warning text-dark'); ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($lock['student_name'], ENT_QUOTES, 'UTF-8'); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($lock['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></td>
                            <td><?php echo htmlspecialchars($lock['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge <?php echo $lockClass; ?>"><?php echo htmlspecialchars($lockLabels[$lock['lock_reason']] ?? $lock['lock_reason'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><span dir="ltr"><?php echo htmlspecialchars($lock['locked_at'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($lock['locked_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($lock['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="actions-column admin-table-actions">
                                <?php if (($lock['lock_reason'] ?? '') === 'manual'): ?>
                                    <button type="button" class="btn btn-action-pills btn-edit me-1 edit-lock-btn" data-bs-toggle="tooltip" title="تعديل" data-lock-id="<?php echo (int) $lock['id']; ?>" data-lock-notes="<?php echo htmlspecialchars((string) ($lock['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-student-name="<?php echo htmlspecialchars($lock['student_name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-action-pills btn-deactivate unlock-lock-btn" data-bs-toggle="tooltip" title="فك القفل اليدوي" data-lock-id="<?php echo (int) $lock['id']; ?>" data-student-name="<?php echo htmlspecialchars($lock['student_name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-unlock"></i></button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>

<?php
$renderSelectedAcademicYearField = static function (string $label) use ($currentAcademicYearId, $currentAcademicYearName, $academicYears): void {
    $displayYearName = $currentAcademicYearName !== '' ? $currentAcademicYearName : 'العام المختار رقم ' . $currentAcademicYearId;
    ?>
    <label class="form-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></label>
    <?php if ($currentAcademicYearId > 0): ?>
        <input type="hidden" name="academic_year_id" value="<?php echo (int) $currentAcademicYearId; ?>">
        <div class="form-control bg-light"><?php echo htmlspecialchars($displayYearName, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="form-text">يتم استخدام العام المختار من الشريط العلوي.</div>
    <?php else: ?>
        <select name="academic_year_id" class="form-select" required>
            <option value="">اختر العام</option>
            <?php foreach ($academicYears as $year): ?>
                <option value="<?php echo (int) $year['id']; ?>" <?php echo !empty($year['is_active']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($year['name'] . (!empty($year['is_active']) ? ' - النشط' : ''), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <?php
};
?>

<div class="modal fade" id="syncLocksModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="post" action="assessment_student_locks.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="sync_student_locks">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-arrows-rotate me-2"></i>مزامنة أقفال الطلاب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php $renderSelectedAcademicYearField('العام الدراسي'); ?>
                    <div class="alert alert-info mt-3 mb-0">تغلق المزامنة درجات الطلاب الخريجين والمنقولين حسب بيانات القيد الحالية.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-arrows-rotate me-1"></i>مزامنة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addManualLockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="post" action="assessment_student_locks.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_manual_student_lock">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-lock me-2"></i>قفل يدوي لطالب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <?php $renderSelectedAcademicYearField('العام'); ?>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">الطالب</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">اختر طالبا للقفل اليدوي</option>
                                <?php foreach ($lockableStudents as $student): ?>
                                    <option value="<?php echo (int) $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name'] . ' - ' . ($student['class_name'] ?? '-') . ' - ' . ($student['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">ملاحظات</label>
                            <input type="text" name="notes" class="form-control" maxlength="500" placeholder="سبب القفل اليدوي">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-lock me-1"></i>قفل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editManualLockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning"><form method="post" action="assessment_student_locks.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_manual_student_lock"><input type="hidden" name="lock_id" id="editLockId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل القفل اليدوي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p>الطالب: <span id="editLockStudentName" class="fw-bold text-primary"></span></p><label class="form-label">ملاحظات</label><input type="text" name="notes" id="editLockNotes" class="form-control" maxlength="500"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="unlockManualLockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning"><form method="post" action="assessment_student_locks.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_manual_student_lock"><input type="hidden" name="lock_id" id="unlockLockId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-unlock me-2"></i>فك القفل اليدوي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-unlock text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد فك القفل اليدوي عن <span class="fw-bold text-primary" id="unlockLockStudentName"></span>؟</p><div class="alert alert-warning text-start">هذا الإجراء لا يفك أقفال التخرج أو النقل، وإنما الأقفال اليدوية فقط.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-unlock me-1"></i>فك القفل</button></div>
    </form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) { const el = document.getElementById(id); if (el && window.bootstrap) new bootstrap.Modal(el).show(); }
    function setValue(id, value) { const el = document.getElementById(id); if (el) el.value = value || ''; }
    document.querySelectorAll('.edit-lock-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('editLockId', this.dataset.lockId);
            setValue('editLockNotes', this.dataset.lockNotes);
            document.getElementById('editLockStudentName').textContent = this.dataset.studentName || '';
            showModal('editManualLockModal');
        });
    });
    document.querySelectorAll('.unlock-lock-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('unlockLockId', this.dataset.lockId);
            document.getElementById('unlockLockStudentName').textContent = this.dataset.studentName || '';
            showModal('unlockManualLockModal');
        });
    });
});
</script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>

