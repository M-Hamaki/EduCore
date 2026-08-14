<?php
/**
 * طاقم الحركة والنقل (السائقين والمشرفين) - Bus Staff Management
 */
$page_title = "طاقم الحافلات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/UndoManager.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
UndoManager::setDb($db);
$db->exec("SET NAMES 'utf8mb4'");

// PRG: Session feedback messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// =============== POST HANDLERS ===============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "خطأ في التحقق من الأمان";
        header("Location: bus_staff.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // ===== إضافة/تعديل عضو طاقم =====
    if ($action === 'save_staff') {
        $staffId = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
        $name    = trim($_POST['name'] ?? '');
        $role    = in_array($_POST['role'] ?? '', ['driver', 'supervisor']) ? $_POST['role'] : 'supervisor';
        $phones  = trim($_POST['phones'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            $_SESSION['error_message'] = 'الاسم مطلوب.';
            header("Location: bus_staff.php");
            exit();
        }

        try {
            $db->beginTransaction();
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);

            if ($staffId) {
                // UPDATE
                $oldData = UndoManager::fetchRecord('bus_staff', $staffId);
                $stmt = $db->prepare("UPDATE bus_staff SET name = ?, role = ?, phones = ?, notes = ? WHERE id = ?");
                $stmt->execute([$name, $role, $phones ?: null, $notes ?: null, $staffId]);
                $newData = UndoManager::fetchRecord('bus_staff', $staffId);
                $audit->recordUpdate('bus_staff', 'bus_staff', $staffId, $name, $oldData ?: [], $newData ?: [], 'تعديل بيانات عضو طاقم حافلة');
                $_SESSION['success_message'] = 'تم تعديل بيانات عضو الطاقم بنجاح.';
            } else {
                // INSERT
                $stmt = $db->prepare("INSERT INTO bus_staff (name, role, phones, notes, bus_id) VALUES (?, ?, ?, ?, NULL)");
                $stmt->execute([$name, $role, $phones ?: null, $notes ?: null]);
                $newId = $db->lastInsertId();
                $newData = UndoManager::fetchRecord('bus_staff', $newId);
                $audit->recordInsert('bus_staff', 'bus_staff', $newId, $name, $newData ?: [], 'إضافة عضو طاقم حافلة جديد');
                $_SESSION['success_message'] = 'تم إضافة عضو الطاقم بنجاح.';
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('bus_staff save error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حفظ بيانات عضو الطاقم.';
        }
        header("Location: bus_staff.php");
        exit();
    }

    // ===== حذف عضو طاقم =====
    if ($action === 'delete_staff' && !empty($_POST['staff_id'])) {
        $staffId = (int)$_POST['staff_id'];
        
        try {
            $db->beginTransaction();
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
            $oldData = UndoManager::fetchRecord('bus_staff', $staffId);
            if ($oldData) {
                $stmt = $db->prepare("DELETE FROM bus_staff WHERE id = ?");
                $stmt->execute([$staffId]);
                $audit->recordDelete('bus_staff', 'bus_staff', $staffId, (string)($oldData['name'] ?? ''), $oldData, 'حذف عضو طاقم حافلة');
                $_SESSION['success_message'] = 'تم حذف عضو الطاقم بنجاح.';
            } else {
                $_SESSION['error_message'] = 'عضو الطاقم غير موجود.';
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('bus_staff delete error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حذف عضو الطاقم.';
        }
        header("Location: bus_staff.php");
        exit();
    }

    header("Location: bus_staff.php");
    exit();
}

// =============== FETCH DATA ===============
// Stats calculations
$totalStaff = $db->query("SELECT COUNT(*) FROM bus_staff")->fetchColumn();
$driversCount = $db->query("SELECT COUNT(*) FROM bus_staff WHERE role = 'driver'")->fetchColumn();
$supervisorsCount = $db->query("SELECT COUNT(*) FROM bus_staff WHERE role = 'supervisor'")->fetchColumn();
$unassignedCount = $db->query("SELECT COUNT(*) FROM bus_staff bs WHERE NOT EXISTS (SELECT 1 FROM bus_staff_assignments bsa WHERE bsa.staff_id = bs.id)")->fetchColumn();
$assignedCount = $totalStaff - $unassignedCount;

// Staff List with Bus numbers (Many-to-Many via pivot)
$staffList = $db->query("SELECT bs.*, 
    (SELECT GROUP_CONCAT(b.bus_number ORDER BY b.bus_number SEPARATOR '، ') 
     FROM bus_staff_assignments bsa 
     JOIN buses b ON bsa.bus_id = b.id 
     WHERE bsa.staff_id = bs.id) as bus_number 
    FROM bus_staff bs
    ORDER BY bs.role, bs.name")->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-users me-2 text-primary"></i>طاقم الحافلات (السائقين والمشرفين)</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" onclick="openAddModal()">
            <i class="fas fa-plus-circle me-1"></i>إضافة عضو طاقم
        </button>
        <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#importStaffModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
    </div>
</div>

<!-- Alerts -->
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

<!-- Stat Cards Grid -->
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
    <!-- Card 1 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalStaff; ?>">0</div>
                <div class="stat-card-label">إجمالي الطاقم</div>
            </div>
        </div>
    </div>
    <!-- Card 2 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-id-card"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $driversCount; ?>">0</div>
                <div class="stat-card-label">السائقات / السائقين</div>
            </div>
        </div>
    </div>
    <!-- Card 3 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-user-shield"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $supervisorsCount; ?>">0</div>
                <div class="stat-card-label">المشرفين</div>
            </div>
        </div>
    </div>
    <!-- Card 4 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-user-clock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $unassignedCount; ?>">0</div>
                <div class="stat-card-label">غير معينين لحافلة</div>
            </div>
        </div>
    </div>
    <!-- Card 5 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $assignedCount; ?>">0</div>
                <div class="stat-card-label">معينين للحافلات</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form id="staffFilterForm" class="admin-filter-bar no-print mb-4" novalidate>
    <div class="admin-filter-controls">
        <select id="filterStaffRole" class="form-select form-select-sm" style="min-width: 150px;">
            <option value="">الدور: الكل</option>
            <option value="سائق">سائق</option>
            <option value="مشرف">مشرف</option>
        </select>
        <select id="filterStaffAssigned" class="form-select form-select-sm ms-2" style="min-width: 180px;">
            <option value="">حالة التعيين: الكل</option>
            <option value="معين للحافلات">معين على حافلة</option>
            <option value="غير معين">غير معين</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="btnResetStaffFilters"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
    </div>
</form>

<!-- Main Table Card -->
<div class="admin-list-surface">
        <?php if (empty($staffList)): ?>
            <div class="text-center py-5 text-muted p-3">
                <i class="fas fa-users fa-3x mb-3 opacity-50"></i>
                <p class="mb-0">لا يوجد طاقم مسجل حالياً. ابدأ بإضافة عضو جديد.</p>
                <button class="btn btn-success btn-sm mt-3" onclick="openAddModal()">
                    <i class="fas fa-plus me-1"></i>إضافة عضو طاقم
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover table-striped align-middle admin-data-table" id="staffTable">
                    <thead class="table-light">
                        <tr>
                            <th width="60">#</th>
                            <th>الاسم</th>
                            <th width="150">الدور</th>
                            <th width="200">أرقام الهواتف</th>
                            <th width="220">حالة التعيين</th>
                            <th>ملاحظات</th>
                            <th width="120">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 0; foreach ($staffList as $s): $i++; ?>
                        <tr>
                            <td class="text-secondary text-center"><?php echo $i; ?></td>
                            <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                            <td>
                                <?php if ($s['role'] === 'driver'): ?>
                                    <span class="badge bg-primary-subtle text-primary px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.85rem;">
                                        <i class="fas fa-id-card me-1"></i>سائق
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.85rem;">
                                        <i class="fas fa-user-shield me-1"></i>مشرف
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['phones']): ?>
                                    <a href="tel:<?php echo htmlspecialchars($s['phones']); ?>" class="text-decoration-none fw-bold text-secondary d-inline-flex align-items-center gap-2">
                                        <span dir="ltr"><?php echo htmlspecialchars($s['phones']); ?></span>
                                        <i class="fas fa-phone text-muted" style="font-size: 0.85rem;"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['bus_number']): ?>
                                    <span class="badge bg-success-subtle text-success px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.85rem;">
                                        <i class="fas fa-bus me-1"></i>معين للحافلات: <?php echo htmlspecialchars($s['bus_number']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.85rem;">
                                        <i class="fas fa-clock me-1"></i>غير معين
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><span class="text-secondary small"><?php echo htmlspecialchars($s['notes'] ?: '—'); ?></span></td>
                            <td class="actions-column">
                                <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل"
                                        onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف"
                                        onclick="openDeleteModal(<?php echo (int)$s['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$s['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
</div>

<!-- ====== ADD / EDIT MODAL ====== -->
<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="staffModalContent">
            <form method="post" action="bus_staff.php" id="staffForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save_staff">
                <input type="hidden" name="staff_id" id="staffId">

                <div class="modal-header">
                    <h5 class="modal-title" id="staffModalLabel">إضافة عضو طاقم جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الاسم كامل <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="staffName" class="form-control" required placeholder="مثال: أحمد محمد علي">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">الدور الوظيفي <span class="text-danger">*</span></label>
                        <select name="role" id="staffRole" class="form-select" required>
                            <option value="driver">سائق</option>
                            <option value="supervisor">مشرف</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">أرقام الهواتف</label>
                        <input type="text" name="phones" id="staffPhones" class="form-control" placeholder="مثال: 01000000000, 01100000000" dir="ltr">
                        <small class="text-muted d-block mt-1">افصل بين الأرقام بفاصلة (،) في حال وجود أكثر من رقم.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" id="staffNotes" class="form-control" rows="2" placeholder="أية ملاحظات إضافية..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn" id="staffSubmitBtn"><i class="fas fa-save me-1"></i>حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====== DELETE CONFIRMATION MODAL ====== -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="bus_staff.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="delete_staff">
                <input type="hidden" name="staff_id" id="deleteStaffId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>تأكيد حذف عضو الطاقم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-trash text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من حذف عضو الطاقم <span class="fw-bold text-primary" id="deleteStaffName"></span> من النظام؟</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-info-circle me-2"></i>
                        حذف العضو سيؤدي لفك ارتباطه تلقائياً بالحافلة المعين بها، ولن يتم حذفه من سجلات الحضور أو العمليات السابقة.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
// ====== Add Modal ======
function openAddModal() {
    document.getElementById('staffForm').reset();
    document.getElementById('staffId').value = '';
    
    document.getElementById('staffModalLabel').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة عضو طاقم جديد';
    document.getElementById('staffModalContent').classList.remove('admin-modal-edit');
    document.getElementById('staffModalContent').classList.add('admin-modal-create');
    document.getElementById('staffSubmitBtn').innerHTML = '<i class="fas fa-plus me-1"></i>إضافة';
    document.getElementById('staffSubmitBtn').className = 'btn btn-success';
    
    new bootstrap.Modal(document.getElementById('staffModal')).show();
}

// ====== Edit Modal ======
function openEditModal(data) {
    document.getElementById('staffForm').reset();
    document.getElementById('staffId').value = data.id;
    document.getElementById('staffName').value = data.name;
    document.getElementById('staffRole').value = data.role;
    document.getElementById('staffPhones').value = data.phones || '';
    document.getElementById('staffNotes').value = data.notes || '';
    
    document.getElementById('staffModalLabel').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل بيانات عضو الطاقم';
    document.getElementById('staffModalContent').classList.remove('admin-modal-create');
    document.getElementById('staffModalContent').classList.add('admin-modal-edit');
    document.getElementById('staffSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>حفظ';
    document.getElementById('staffSubmitBtn').className = 'btn btn-primary';
    
    new bootstrap.Modal(document.getElementById('staffModal')).show();
}

// ====== Delete Modal ======
function openDeleteModal(id, name) {
    document.getElementById('deleteStaffId').value = id;
    document.getElementById('deleteStaffName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<div class="modal fade" id="importStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد طاقم الحافلات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>ارفع ملف Excel أو CSV لاستيراد بيانات الطاقم (السائقين والمشرفين).</p>
                <form id="importStaffForm" method="post" enctype="multipart/form-data" action="import_bus_staff.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <div class="mb-3">
                        <label for="staffFile" class="form-label">اختر الملف</label>
                        <input type="file" class="form-control" id="staffFile" name="file" accept=".xlsx,.xls,.csv" required>
                    </div>
                    <div class="alert alert-info">
                        <strong>صيغة الملف المطلوبة:</strong><br>
                        العمود الأول: الاسم<br>
                        العمود الثاني: الوظيفة (سائق / مشرف)<br>
                        العمود الثالث: الهواتف (اختياري)<br>
                        العمود الرابع: الملاحظات (اختياري)
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-success" form="importStaffForm"><i class="fas fa-upload me-1"></i>استيراد</button>
            </div>
        </div>
    </div>
</div>

<script>
// ====== DataTables Localization ======
$(document).ready(function() {
    if ($('#staffTable').length && typeof $.fn.DataTable !== 'undefined') {
        var table = $('#staffTable').DataTable({
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
            order: [[2, 'asc'], [1, 'asc']], // Order by role then name
            pageLength: 50,
            responsive: true
        });

        // Filter event listeners
        $('#filterStaffRole').on('change', function() {
            var val = $(this).val();
            table.column(2).search(val ? val : '').draw();
        });

        $('#filterStaffAssigned').on('change', function() {
            var val = $(this).val();
            table.column(4).search(val ? val : '').draw();
        });

        $('#btnResetStaffFilters').on('click', function() {
            $('#filterStaffRole').val('');
            $('#filterStaffAssigned').val('');
            table.column(2).search('');
            table.column(4).search('').draw();
        });
    }
    $('[data-bs-toggle="tooltip"]').tooltip();
});
</script>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
function exportStaffTableToCSV() {
    exportTableToCsv('staffTable', 'bus_staff_' + new Date().toISOString().slice(0,10) + '.csv');
}

function exportStaffTableToPDF() {
    exportTableToPdf('staffTable', 'طاقم الحافلات');
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
