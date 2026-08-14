<?php
// Set page title
$page_title = "إدارة أنواع التقييم";
$custom_page_title = true; // This page has its own custom title

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/csrf.php';

// Auth validation before any processing
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Initialize evaluation type object
$evaluation_type = new EvaluationType($db);

// Determine action
$action = $_GET['action'] ?? '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    if (isset($_POST['add_evaluation_type'])) {
        // Set evaluation type properties
        $evaluation_type->name = $_POST['name'];
        $evaluation_type->type = $_POST['type'];
        $evaluation_type->points = $_POST['points'];
        
        // Create evaluation type
        if ($evaluation_type->create()) {
            $_SESSION['success_message'] = "تم إضافة نوع التقييم بنجاح.";
            ActivityLog::logCreate('evaluation_type', $evaluation_type->id, $evaluation_type->name);
        } else {
            $_SESSION['error_message'] = !empty($evaluation_type->error_message) ? $evaluation_type->error_message : "حدث خطأ أثناء إضافة نوع التقييم.";
        }
        header("Location: evaluation_types.php");
        exit();
    } elseif (isset($_POST['edit_evaluation_type'])) {
        // Set evaluation type properties
        $evaluation_type->id = $_POST['id'];
        $evaluation_type->name = $_POST['name'];
        $evaluation_type->type = $_POST['type'];
        $evaluation_type->points = $_POST['points'];
        
        // Update evaluation type
        if ($evaluation_type->update()) {
            $_SESSION['success_message'] = "تم تحديث نوع التقييم بنجاح.";
            ActivityLog::logUpdate('evaluation_type', $evaluation_type->id, $evaluation_type->name);
        } else {
            $_SESSION['error_message'] = !empty($evaluation_type->error_message) ? $evaluation_type->error_message : "حدث خطأ أثناء تحديث نوع التقييم.";
        }
        header("Location: evaluation_types.php");
        exit();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_eval_type') {
        $evaluation_type->id = $_POST['id'];
        if ($evaluation_type->delete()) {
            $_SESSION['success_message'] = "تم حذف نوع التقييم بنجاح.";
            ActivityLog::logDelete('evaluation_type', $_POST['id'], $_POST['id']);
        } else {
            $_SESSION['error_message'] = "لا يمكن حذف نوع التقييم لأنه مرتبط بتقييمات مسجلة.";
        }
        header("Location: evaluation_types.php");
        exit();
    }
}

// Get evaluation type by ID for editing
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $evaluation_type->id = $_GET['id'];
    $evaluation_type->readOne();
}

// Include header
include_once '../includes/admin_header.php';

// Get stats for cards
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN type = 'positive' THEN 1 ELSE 0 END) as positive_count,
    SUM(CASE WHEN type = 'negative' THEN 1 ELSE 0 END) as negative_count,
    SUM(CASE WHEN type = 'positive' THEN points ELSE 0 END) as positive_points,
    SUM(CASE WHEN type = 'negative' THEN points ELSE 0 END) as negative_points
    FROM evaluation_types";
$stats_row = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

?>
<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-star me-2"></i>إدارة أنواع التقييم</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addEvalTypeModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة نوع تقييم
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4 evaluation-types-stat-cards">
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
            <div class="stat-card-icon"><i class="fas fa-star"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$stats_row['total']; ?>">0</div>
                <div class="stat-card-label">إجمالي الأنواع</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--success-gradient);">
            <div class="stat-card-icon"><i class="fas fa-thumbs-up"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$stats_row['positive_count']; ?>">0</div>
                <div class="stat-card-label">تقييمات إيجابية</div>
                <div class="stat-card-sub"><i class="fas fa-plus-circle"></i> +<?php echo $stats_row['positive_points'] ?? 0; ?> نقطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
            <div class="stat-card-icon"><i class="fas fa-thumbs-down"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$stats_row['negative_count']; ?>">0</div>
                <div class="stat-card-label">تقييمات سلبية</div>
                <div class="stat-card-sub"><i class="fas fa-minus-circle"></i> -<?php echo $stats_row['negative_points'] ?? 0; ?> نقطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #6d28d9);">
            <div class="stat-card-icon"><i class="fas fa-balance-scale"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)(($stats_row['positive_points'] ?? 0) + ($stats_row['negative_points'] ?? 0)); ?>">0</div>
                <div class="stat-card-label">إجمالي النقاط</div>
            </div>
        </div>
    </div>
</div>


<!-- Evaluation Types List -->
        <?php
        // Get all evaluation types
        $stmt = $evaluation_type->readAll();
        $num = $stmt->rowCount();
        
        if ($num > 0):
        ?>
            <div class="admin-list-surface">
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped datatable admin-data-table">
                        <thead>
                            <tr>
                                <th width="70">الرقم</th>
                                <th>اسم التقييم</th>
                                <th>النوع</th>
                                <th>النقاط</th>
                                <th width="150" class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $counter = 1;
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $row['type'] == 'positive' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $row['type'] == 'positive' ? 'إيجابي' : 'سلبي'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $row['type'] == 'positive' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $row['type'] == 'positive' ? '+' : '-'; ?><?php echo $row['points']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center actions-column admin-table-actions">
                                        <button type="button" class="btn btn-action-pills btn-edit edit-eval-type has-tooltip me-1" 
                                                data-id="<?php echo $row['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($row['name']); ?>" 
                                                data-type="<?php echo $row['type']; ?>" 
                                                data-points="<?php echo $row['points']; ?>" 
                                                title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-action-pills btn-delete delete-eval-type has-tooltip" 
                                                data-id="<?php echo $row['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($row['name']); ?>" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#deleteEvalTypeModal" 
                                                title="حذف">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                لا توجد أنواع تقييم حتى الآن. يمكنك إضافة نوع تقييم جديد من خلال النقر على زر "إضافة نوع تقييم جديد".
            </div>
        <?php endif; ?>

<!-- Delete Evaluation Type Modal -->
<div class="modal fade" id="deleteEvalTypeModal" tabindex="-1" aria-labelledby="deleteEvalTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteEvalTypeModalLabel"><i class="fas fa-trash me-2"></i>حذف نوع تقييم</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف نوع التقييم <span class="fw-bold text-primary" id="delete_eval_type_name"></span>؟</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    سيتم حذف نوع التقييم وجميع التقييمات المرتبطة به.
                </div>
                <p class="text-danger text-center mb-0">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    هذا الإجراء لا يمكن التراجع عنه.
                </p>
            </div>
            <div class="modal-footer">
                <form method="post" action="evaluation_types.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="id" id="delete_eval_type_id">
                    <input type="hidden" name="action" value="delete_eval_type">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>حذف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Evaluation Type Modal -->
<div class="modal fade" id="addEvalTypeModal" tabindex="-1" aria-labelledby="addEvalTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST" action="evaluation_types.php">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="addEvalTypeModalLabel"><i class="fas fa-plus-circle me-2"></i>إضافة نوع تقييم جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_name" class="form-label">اسم التقييم</label>
                        <input type="text" class="form-control" id="add_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_type" class="form-label">نوع التقييم</label>
                        <select class="form-select" id="add_type" name="type" required>
                            <option value="">اختر نوع التقييم</option>
                            <option value="positive">إيجابي</option>
                            <option value="negative">سلبي</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_points" class="form-label">عدد النقاط</label>
                        <input type="number" class="form-control" id="add_points" name="points" min="1" value="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="add_evaluation_type" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>إضافة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Evaluation Type Modal -->
<div class="modal fade" id="editEvalTypeModal" tabindex="-1" aria-labelledby="editEvalTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST" action="evaluation_types.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editEvalTypeModalLabel"><i class="fas fa-edit me-2"></i>تعديل نوع التقييم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">اسم التقييم</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">نوع التقييم</label>
                        <select class="form-select" id="edit_type" name="type" required>
                            <option value="">اختر نوع التقييم</option>
                            <option value="positive">إيجابي</option>
                            <option value="negative">سلبي</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_points" class="form-label">عدد النقاط</label>
                        <input type="number" class="form-control" id="edit_points" name="points" min="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="edit_evaluation_type" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    // Handle edit evaluation type button
    document.querySelectorAll('.edit-eval-type').forEach(btn => {
        btn.addEventListener('click', function() {
            var evalId = this.getAttribute('data-id');
            var evalName = this.getAttribute('data-name');
            var evalType = this.getAttribute('data-type');
            var evalPoints = this.getAttribute('data-points');
            
            document.getElementById('edit_id').value = evalId;
            document.getElementById('edit_name').value = evalName;
            document.getElementById('edit_type').value = evalType;
            document.getElementById('edit_points').value = evalPoints;
            
            var modal = new bootstrap.Modal(document.getElementById('editEvalTypeModal'));
            modal.show();
        });
    });

    // Handle delete evaluation type button
    document.querySelectorAll('.delete-eval-type').forEach(btn=>{
        btn.addEventListener('click', function(){
            document.getElementById('delete_eval_type_id').value = this.getAttribute('data-id');
            document.getElementById('delete_eval_type_name').textContent = this.getAttribute('data-name');
        });
    });

    // Initialize tooltips for actions
    document.querySelectorAll('.has-tooltip').forEach(el => { new bootstrap.Tooltip(el); });
});
</script>

<?php
// Include footer
include_once '../includes/admin_footer.php';
?>
