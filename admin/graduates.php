<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// معالجة إلغاء التخرج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'خطأ في رمز الأمان.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'cancel_graduation') {
            $promotionId = (int)($_POST['promotion_id'] ?? 0);
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM student_promotions WHERE id = ? AND promotion_type = 'graduated' AND is_reversed = 0 FOR UPDATE");
                $stmt->execute([$promotionId]);
                $rec = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$rec) {
                    throw new Exception('سجل التخرج غير موجود أو ملغي مسبقاً.');
                }
                $studentStmt = $db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
                $studentStmt->execute([(int)$rec['student_id']]);
                $studentBefore = $studentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$studentBefore) {
                    throw new RuntimeException('Student not found.');
                }
                
                // إعادة الطالب
                $updateStmt = $db->prepare("UPDATE users SET status = 'active', class_id = ? WHERE id = ?");
                $updateStmt->execute([$rec['from_class_id'], $rec['student_id']]);
                
                // تسجيل الإلغاء
                $reverseStmt = $db->prepare("UPDATE student_promotions SET is_reversed = 1, reversed_at = NOW(), reversed_by = ? WHERE id = ?");
                $reverseStmt->execute([$_SESSION['user_id'], $promotionId]);
                
                // سجل الحركة
                $logStmt = $db->prepare("INSERT INTO student_transfers (student_id, from_class_id, to_class_id, transfer_date, reason, transferred_by) VALUES (?, NULL, ?, CURDATE(), ?, ?)");
                $logStmt->execute([$rec['student_id'], $rec['from_class_id'], 'إلغاء تخرج - ' . $rec['academic_year'], $_SESSION['user_id']]);
                $transferId = (int)$db->lastInsertId();
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    'reverse', 'student_graduation', $promotionId,
                    (string)($studentBefore['name'] ?? ('طالب #' . $rec['student_id'])),
                    [
                        'student_id' => (int)$rec['student_id'],
                        'student_before' => $studentBefore,
                        'student_after' => array_merge($studentBefore, [
                            'status' => 'active',
                            'class_id' => $rec['from_class_id'],
                        ]),
                        'promotion_before' => $rec,
                        'promotion_after' => [
                            'is_reversed' => 1,
                            'reversed_by' => (int)$_SESSION['user_id'],
                        ],
                        'transfer_id' => $transferId,
                        'undo_policy' => 'business_reversal_not_direct_undo',
                    ]
                );
                $db->commit();
                $_SESSION['success_message'] = 'تم إلغاء التخرج وإعادة الطالب بنجاح.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('cancel graduation error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر إلغاء التخرج وإعادة الطالب.';
            }
        }
        $query = Utilities::buildQueryString([
            'year' => $_GET['year'] ?? '',
            'stage' => $_GET['stage'] ?? '',
            'grade' => $_GET['grade'] ?? ''
        ]);
        header("Location: graduates.php" . $query);
        exit();
    }
}

// فلاتر
$filterYear = $_GET['year'] ?? '';
$filterStage = $_GET['stage'] ?? '';
$filterGrade = $_GET['grade'] ?? '';

// جلب الأعوام الدراسية المتاحة
$years = $db->query("SELECT DISTINCT academic_year FROM student_promotions WHERE promotion_type = 'graduated' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// جلب المراحل والصفوف
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT g.id, g.grade_name, g.stage_id FROM grades g JOIN stages s ON g.stage_id = s.id ORDER BY s.stage_order, g.grade_order")->fetchAll(PDO::FETCH_ASSOC);

// بناء الاستعلام
$where = ["sp.promotion_type = 'graduated'", "sp.is_reversed = 0"];
$params = [];

if ($filterYear) {
    $where[] = "sp.academic_year = ?";
    $params[] = $filterYear;
}
if ($filterGrade) {
    $where[] = "sp.from_grade_id = ?";
    $params[] = $filterGrade;
} elseif ($filterStage) {
    $where[] = "fg.stage_id = ?";
    $params[] = $filterStage;
}

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("SELECT sp.*, u.name as student_name, u.username, u.email,
    fg.grade_name as from_grade_name, fc.name as from_class_name,
    pb.name as promoted_by_name,
    s.stage_name
    FROM student_promotions sp
    JOIN users u ON sp.student_id = u.id
    LEFT JOIN grades fg ON sp.from_grade_id = fg.id
    LEFT JOIN stages s ON fg.stage_id = s.id
    LEFT JOIN classes fc ON sp.from_class_id = fc.id
    LEFT JOIN users pb ON sp.promoted_by = pb.id
    WHERE $whereClause
    ORDER BY sp.created_at DESC");
$stmt->execute($params);
$graduates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات
$totalGraduates = $db->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='graduated'")->fetchColumn();
$graduatesByYear = $db->query("SELECT academic_year, COUNT(*) as cnt FROM student_promotions WHERE promotion_type='graduated' AND is_reversed=0 GROUP BY academic_year ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'الخريجون';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-graduation-cap me-2"></i>الخريجون</h1>
    <div class="btn-toolbar admin-top-actions">
        <a href="student_promotion.php" class="btn-header-premium btn-import-soft">
            <i class="fas fa-exchange-alt me-1"></i>ترحيل الطلاب
        </a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- إحصائيات -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalGraduates; ?>">0</div>
                <div class="stat-card-label">إجمالي الخريجين</div>
            </div>
        </div>
    </div>
    <?php foreach (array_slice($graduatesByYear, 0, 3) as $gy): ?>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-calendar"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$gy['cnt']; ?>">0</div>
                <div class="stat-card-label"><?php echo htmlspecialchars($gy['academic_year']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- جدول الخريجين -->
<form method="GET" class="admin-filter-bar">
    <div class="admin-filter-controls">
            <!-- الفلاتر من جهة اليمين -->
                <select class="form-select form-select-sm" name="year" style="width:auto; min-width:140px;">
                    <option value="">كل الأعوام</option>
                    <?php foreach ($years as $y): ?>
                    <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filterYear === $y ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" name="stage" id="filterStage" style="width:auto; min-width:120px;" onchange="filterStageChange(this.value)">
                    <option value="">كل المراحل</option>
                    <?php foreach ($stages as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $filterStage == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['stage_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" name="grade" id="filterGrade" style="width:auto; min-width:140px;">
                    <option value="">كل الصفوف</option>
                    <?php foreach ($grades as $g): ?>
                    <option value="<?php echo $g['id']; ?>" data-stage="<?php echo $g['stage_id']; ?>" <?php echo $filterGrade == $g['id'] ? 'selected' : ''; ?>
                        <?php if ($filterStage && $g['stage_id'] != $filterStage) echo 'style="display:none;"'; ?>>
                        <?php echo htmlspecialchars($g['grade_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
    </div>
    <div class="admin-filter-actions">
            <!-- الأزرار من جهة اليسار -->
                <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
                <?php if ($filterYear || $filterStage || $filterGrade): ?>
                <a href="graduates.php" class="btn btn-light btn-sm"><i class="fas fa-undo me-1"></i>إعادة تعيين</a>
                <?php endif; ?>
    </div>
</form>

<div class="admin-list-surface">
        <?php if (empty($graduates)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-graduation-cap" style="font-size:3rem;"></i>
            <p class="mt-3">لا يوجد خريجون<?php echo $filterYear ? ' في هذا العام الدراسي' : ''; ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle admin-data-table" id="graduatesTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>اسم الطالب</th>
                        <th>اسم المستخدم</th>
                        <th>المرحلة</th>
                        <th>الصف</th>
                        <th>الفصل الأخير</th>
                        <th>العام الدراسي</th>
                        <th>تاريخ التخرج</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($graduates as $i => $g): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($g['student_name']); ?></td>
                    <td><code><?php echo htmlspecialchars($g['username'] ?? ''); ?></code></td>
                    <td><?php echo htmlspecialchars($g['stage_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($g['from_grade_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($g['from_class_name'] ?? ''); ?></td>
                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($g['academic_year']); ?></span></td>
                    <td><?php echo date('Y-m-d', strtotime($g['created_at'])); ?></td>
                    <td class="actions-column admin-table-actions">
                        <button class="btn-action-pills btn-deactivate" onclick="cancelGraduation(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['student_name'], ENT_QUOTES); ?>')" data-bs-toggle="tooltip" title="إلغاء التخرج">
                            <i class="fas fa-undo"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
</div>

<!-- Modal إلغاء التخرج -->
<div class="modal fade" id="cancelGradModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="cancel_graduation">
                <input type="hidden" name="promotion_id" id="cancelGradId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-undo me-2"></i>إلغاء التخرج</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من إلغاء تخرج <span class="fw-bold text-primary" id="cancelGradName"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم إعادة الطالب إلى فصله السابق وتحويل حالته إلى "نشط".
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-undo me-1"></i>تأكيد إلغاء التخرج</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// DataTables
$(document).ready(function() {
    if ($.fn.DataTable && document.getElementById('graduatesTable')) {
        $('#graduatesTable').DataTable({
            language: { url: '../assets/js/datatables-ar.json' },
            order: [[7, 'desc']],
            pageLength: 50
        });
    }
});

function cancelGraduation(id, name) {
    document.getElementById('cancelGradId').value = id;
    document.getElementById('cancelGradName').textContent = name;
    new bootstrap.Modal(document.getElementById('cancelGradModal')).show();
}

// فلتر المراحل → الصفوف
function filterStageChange(stageId) {
    var gradeSelect = document.getElementById('filterGrade');
    gradeSelect.value = '';
    gradeSelect.querySelectorAll('option[data-stage]').forEach(function(opt) {
        opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
    });
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
