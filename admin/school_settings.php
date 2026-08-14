<?php
/**
 * حسابات المدرسة
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/user.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

// --- استخراج رسائل الجلسة ---
$success_message = $_SESSION['settings_success'] ?? null;
$error_message = $_SESSION['settings_error'] ?? null;
unset($_SESSION['settings_success'], $_SESSION['settings_error']);

// ==========================================
// معالجة الإجراءات (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- إضافة حساب جديد ----
    if (isset($_POST['add_email'])) {
        try {
            $service = trim($_POST['service_name'] ?? '');
            $username = trim($_POST['email_username'] ?? '');
            $password = $_POST['email_password'] ?? '';
            $notes = trim($_POST['email_notes'] ?? '');

            if (empty($service)) {
                $_SESSION['settings_error'] = "يرجى ملء جميع الحقول المطلوبة.";
            } else {
                $encrypted = !empty($password) ? openssl_encrypt($password, 'AES-256-CBC',
                    hash('sha256', DB_PASSWORD), 0,
                    substr(hash('sha256', 'educore_iv'), 0, 16)) : '';

                $stmt = $db->prepare("INSERT INTO school_emails (service_name, email_username, email_password, notes, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$service, $username, $encrypted, $notes, $_SESSION['user_id']]);
                $newEmailId = $db->lastInsertId();

                $_SESSION['settings_success'] = "تم إضافة الحساب بنجاح.";
                ActivityLog::logCreate('school_email', $newEmailId, $service);
                UndoManager::logInsert('school_emails', $newEmailId, ['service_name' => $service], 'إضافة حساب: ' . $service);
            }
            header("Location: school_settings.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "حدث خطأ: " . $e->getMessage();
            header("Location: school_settings.php");
            exit();
        }
    }

    // ---- تعديل حساب ----
    elseif (isset($_POST['edit_email'])) {
        try {
            $id = (int)($_POST['email_id'] ?? 0);
            $service = trim($_POST['service_name'] ?? '');
            $username = trim($_POST['email_username'] ?? '');
            $password = $_POST['email_password'] ?? '';
            $notes = trim($_POST['email_notes'] ?? '');

            if (empty($service)) {
                $_SESSION['settings_error'] = "يرجى ملء جميع الحقول المطلوبة.";
            } else {
                // جلب البيانات القديمة للتراجع
                $oldEmailData = UndoManager::fetchRecord('school_emails', $id);

                if (!empty($password)) {
                    $encrypted = openssl_encrypt($password, 'AES-256-CBC',
                        hash('sha256', DB_PASSWORD), 0,
                        substr(hash('sha256', 'educore_iv'), 0, 16));
                    $stmt = $db->prepare("UPDATE school_emails SET service_name = ?, email_username = ?, email_password = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$service, $username, $encrypted, $notes, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE school_emails SET service_name = ?, email_username = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$service, $username, $notes, $id]);
                }

                $_SESSION['settings_success'] = "تم تحديث الحساب بنجاح.";
                ActivityLog::logUpdate('school_email', $id, $service);
                if ($oldEmailData) {
                    UndoManager::logUpdate('school_emails', $id, $oldEmailData, null, 'تعديل حساب: ' . $service);
                }
            }
            header("Location: school_settings.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "حدث خطأ: " . $e->getMessage();
            header("Location: school_settings.php");
            exit();
        }
    }

    // ---- حذف حساب ----
    elseif (isset($_POST['delete_email'])) {
        try {
            $id = (int)($_POST['email_id'] ?? 0);

            // جلب البيانات قبل الحذف للتراجع
            $oldEmailData = UndoManager::fetchRecord('school_emails', $id);

            $stmt = $db->prepare("SELECT service_name FROM school_emails WHERE id = ?");
            $stmt->execute([$id]);
            $emailName = $stmt->fetchColumn();

            $stmt = $db->prepare("DELETE FROM school_emails WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION['settings_success'] = "تم حذف الحساب بنجاح.";
            ActivityLog::logDelete('school_email', $id, $emailName ?: '');
            if ($oldEmailData) {
                UndoManager::logDelete('school_emails', $id, $oldEmailData, 'حذف حساب: ' . ($emailName ?: ''));
            }
            header("Location: school_settings.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "حدث خطأ: " . $e->getMessage();
            header("Location: school_settings.php");
            exit();
        }
    }
}

// ==========================================
// جلب البيانات
// ==========================================
$emails = [];
try {
    $stmt = $db->query("SELECT * FROM school_emails ORDER BY created_at DESC");
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    //
}

// فك تشفير كلمات المرور
function decryptPassword($encrypted) {
    return openssl_decrypt($encrypted, 'AES-256-CBC',
        hash('sha256', DB_PASSWORD), 0,
        substr(hash('sha256', 'educore_iv'), 0, 16));
}

$page_title = 'حسابات المدرسة';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>


<!-- Admin Page Heading -->
<div class="admin-page-heading">
    <div>
        <h1 class="h2"><i class="fas fa-envelope me-2 text-primary"></i>حسابات المدرسة</h1>
        <p class="text-muted m-0">إدارة حسابات البريد الإلكتروني والـ SMTP الخاصة بالمدرسة</p>
    </div>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addEmailModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة حساب جديد
        </button>
    </div>
</div>

<!-- الإحصائيات -->
<div class="dashboard-canvas mb-4">
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-envelope"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo count($emails); ?>">0</div>
                    <div class="stat-card-label">إجمالي الحسابات</div>
                    <div class="stat-card-sub"><i class="fas fa-server me-1"></i> حسابات البريد الإلكتروني</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-globe"></i></div>
                <div class="stat-card-info">
                    <?php
                        $services_count = count(array_unique(array_column($emails, 'service_name')));
                    ?>
                    <div class="stat-card-number counter" data-target="<?php echo $services_count; ?>">0</div>
                    <div class="stat-card-label">خدمات البريد</div>
                    <div class="stat-card-sub"><i class="fas fa-check-circle me-1"></i> خدمات مسجلة</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- رسائل النجاح والخطأ -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<div class="admin-list-surface mb-4">
    <?php if (empty($emails)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-inbox fa-3x mb-3 text-primary opacity-50"></i>
            <p class="mb-3 fs-5">لا توجد حسابات بريد مسجلة بعد.</p>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addEmailModal">
                <i class="fas fa-plus-circle me-1"></i>إضافة حساب جديد
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover align-middle datatable admin-data-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الخدمة</th>
                        <th>اسم المستخدم</th>
                        <th>كلمة المرور</th>
                        <th>ملاحظات</th>
                        <th>تاريخ الإضافة</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $i => $email): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <i class="fas fa-globe text-primary me-1"></i>
                            <strong><?php echo htmlspecialchars($email['service_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </td>
                        <td>
                            <?php if (!empty($email['email_username'])): ?>
                                <div class="glass-credential-chip">
                                    <code class="glass-chip-code me-2 dir-ltr"><?php echo htmlspecialchars($email['email_username'], ENT_QUOTES, 'UTF-8'); ?></code>
                                    <button type="button" class="glass-chip-btn copy-btn" data-copy="<?php echo htmlspecialchars($email['email_username'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $decryptedPwd = decryptPassword($email['email_password']);
                            ?>
                            <?php if (!empty($decryptedPwd)): ?>
                                <div class="glass-credential-chip">
                                    <span class="glass-chip-code text-muted me-2 pwd-dots" id="pwd-dots-<?php echo (int)$email['id']; ?>" style="letter-spacing: 2px;">••••••••</span>
                                    <code class="glass-chip-code text-primary fw-bold me-2 d-none pwd-text dir-ltr" id="pwd-text-<?php echo (int)$email['id']; ?>"><?php echo htmlspecialchars($decryptedPwd, ENT_QUOTES, 'UTF-8'); ?></code>
                                    <button type="button" class="glass-chip-btn toggle-password me-1" data-id="<?php echo (int)$email['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" class="glass-chip-btn copy-btn" data-copy="<?php echo htmlspecialchars($decryptedPwd, ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($email['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><small class="text-muted"><?php echo date('Y/m/d', strtotime($email['created_at'])); ?></small></td>
                        <td class="text-center actions-column admin-table-actions">
                            <button type="button" class="btn btn-action-pills btn-edit me-1 edit-email-btn"
                                    data-id="<?php echo (int)$email['id']; ?>"
                                    data-service="<?php echo htmlspecialchars($email['service_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-username="<?php echo htmlspecialchars($email['email_username'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-notes="<?php echo htmlspecialchars($email['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-bs-toggle="tooltip" title="تعديل">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-action-pills btn-delete delete-email-btn"
                                    data-id="<?php echo (int)$email['id']; ?>"
                                    data-service="<?php echo htmlspecialchars($email['service_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-bs-toggle="tooltip" title="حذف">
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

<!-- ==========================================
     مودال إضافة بريد
     ========================================== -->
<div class="modal fade" id="addEmailModal" tabindex="-1" aria-labelledby="addEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addEmailModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة حساب جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الخدمة / الموقع <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="service_name" placeholder="مثال: Microsoft 365, Google..." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم المستخدم / البريد <small class="text-muted">(اختياري)</small></label>
                        <input type="text" class="form-control" name="email_username" placeholder="أدخل اسم المستخدم أو البريد">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">كلمة المرور <small class="text-muted">(اختياري)</small></label>
                        <div class="position-relative">
                            <input type="password" class="form-control pe-5" name="email_password" placeholder="أدخل كلمة المرور">
                            <span class="position-absolute start-0 top-50 translate-middle-y ms-3" onclick="togglePasswordField(this)" style="cursor:pointer; z-index:5;">
                                <i class="fas fa-eye text-muted"></i>
                            </span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea class="form-control" name="email_notes" rows="2" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="add_email" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     مودال تعديل حساب
     ========================================== -->
<div class="modal fade" id="editEmailModal" tabindex="-1" aria-labelledby="editEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="email_id" id="editEmailId">
                <div class="modal-header">
                    <h5 class="modal-title" id="editEmailModalTitle"><i class="fas fa-pen me-2"></i>تعديل حساب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الخدمة / الموقع <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="service_name" id="editEmailService" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم المستخدم / البريد <small class="text-muted">(اختياري)</small></label>
                        <input type="text" class="form-control" name="email_username" id="editEmailUsername">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">كلمة المرور <small class="text-muted">(اتركها فارغة إذا لا تريد التغيير)</small></label>
                        <div class="position-relative">
                            <input type="password" class="form-control pe-5" name="email_password" placeholder="كلمة مرور جديدة (اختياري)">
                            <span class="position-absolute start-0 top-50 translate-middle-y ms-3" onclick="togglePasswordField(this)" style="cursor:pointer; z-index:5;">
                                <i class="fas fa-eye text-muted"></i>
                            </span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea class="form-control" name="email_notes" id="editEmailNotes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="edit_email" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     مودال حذف حساب
     ========================================== -->
<div class="modal fade" id="deleteEmailModal" tabindex="-1" aria-labelledby="deleteEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="email_id" id="deleteEmailId">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteEmailModalTitle"><i class="fas fa-trash me-2"></i>حذف حساب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-triangle-exclamation text-danger admin-modal-icon-lg mb-3"></i>
                    <p class="mb-2">هل أنت متأكد من حذف حساب <strong id="deleteEmailName" class="text-primary"></strong>؟</p>
                    <p class="text-danger small mb-0"><i class="fas fa-exclamation-circle me-1"></i>هذا الإجراء لا يمكن التراجع عنه.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="delete_email" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>حذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // نقل كافة المودالات إلى body لمنع مشاكل الـ Backdrop والـ stacking context
    document.querySelectorAll('.modal').forEach(function(modal) {
        document.body.appendChild(modal);
    });

    // تفعيل Tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        if (typeof bootstrap !== 'undefined') {
            new bootstrap.Tooltip(el);
        }
    });

    // تعديل حساب
    document.querySelectorAll('.edit-email-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('editEmailId').value = this.dataset.id;
            document.getElementById('editEmailService').value = this.dataset.service;
            document.getElementById('editEmailUsername').value = this.dataset.username;
            document.getElementById('editEmailNotes').value = this.dataset.notes;
            new bootstrap.Modal(document.getElementById('editEmailModal')).show();
        });
    });

    // حذف حساب
    document.querySelectorAll('.delete-email-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('deleteEmailId').value = this.dataset.id;
            document.getElementById('deleteEmailName').textContent = this.dataset.service;
            new bootstrap.Modal(document.getElementById('deleteEmailModal')).show();
        });
    });

    // كشف / إخفاء كلمة المرور للبطاقة البرمجية الحديثة مع الإخفاء التلقائي بعد 10 ثوانٍ (Soft Chip Auto-Hide)
    const pwdTimers = {};
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const dots = document.getElementById('pwd-dots-' + id);
            const text = document.getElementById('pwd-text-' + id);
            const icon = this.querySelector('i');

            if (!text || !dots || !icon) return;

            const hidePassword = () => {
                text.classList.add('d-none');
                dots.classList.remove('d-none');
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                icon.classList.replace('text-primary', 'text-muted');
                if (pwdTimers[id]) {
                    clearTimeout(pwdTimers[id]);
                    delete pwdTimers[id];
                }
            };

            const showPassword = () => {
                text.classList.remove('d-none');
                dots.classList.add('d-none');
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                icon.classList.replace('text-muted', 'text-primary');

                if (pwdTimers[id]) clearTimeout(pwdTimers[id]);
                pwdTimers[id] = setTimeout(() => {
                    hidePassword();
                }, 10000); // إخفاء تلقائي بعد 10 ثوانٍ
            };

            if (text.classList.contains('d-none')) {
                showPassword();
            } else {
                hidePassword();
            }
        });
    });

    // نسخ النص
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const text = this.dataset.copy;
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                const icon = this.querySelector('i');
                if (!icon) return;
                icon.classList.replace('fa-copy', 'fa-check');
                icon.classList.add('text-success');
                setTimeout(() => {
                    icon.classList.replace('fa-check', 'fa-copy');
                    icon.classList.remove('text-success');
                }, 1500);
            });
        });
    });
});

// إظهار/إخفاء كلمة المرور في المودال
function togglePasswordField(el) {
    const input = el.parentElement.querySelector('input');
    const icon = el.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
