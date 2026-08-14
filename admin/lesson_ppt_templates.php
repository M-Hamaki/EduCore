<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/LessonPptTemplateLibrary.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/FileUploadGuard.php';

Utilities::validateSession('admin');

$db = (new Database())->getConnection();
$library = new LessonPptTemplateLibrary($db);

$success_message = $_SESSION['ppt_tpl_success'] ?? null;
$error_message = $_SESSION['ppt_tpl_error'] ?? null;
unset($_SESSION['ppt_tpl_success'], $_SESSION['ppt_tpl_error']);

function ppt_template_upload(string $field, array $allowedExt, string $dir, string $prefix): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('تعذر رفع الملف.');
    }

    $mimeMap = ppt_template_mime_map($allowedExt);
    $validatedFile = FileUploadGuard::validate($_FILES[$field], $mimeMap, 50 * 1024 * 1024);
    $ext = $validatedFile['extension'];

    $fullDir = dirname(__DIR__) . '/' . trim($dir, '/\\') . '/';
    if (!is_dir($fullDir) && !mkdir($fullDir, 0775, true) && !is_dir($fullDir)) {
        throw new RuntimeException('تعذر إنشاء مجلد القوالب.');
    }

    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $fullDir . $filename;
    if (!move_uploaded_file($validatedFile['tmp_name'], $target)) {
        throw new RuntimeException('فشل حفظ الملف المرفوع.');
    }

    return trim($dir, '/\\') . '/' . $filename;
}

function ppt_template_store_uploaded_file(array $file, array $allowedExt, string $dir, string $prefix): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('تعذر رفع الملف.');
    }

    $validatedFile = FileUploadGuard::validate($file, ppt_template_mime_map($allowedExt), 50 * 1024 * 1024);
    $name = $validatedFile['original_name'];
    $ext = $validatedFile['extension'];

    $fullDir = dirname(__DIR__) . '/' . trim($dir, '/\\') . '/';
    if (!is_dir($fullDir) && !mkdir($fullDir, 0775, true) && !is_dir($fullDir)) {
        throw new RuntimeException('تعذر إنشاء مجلد القوالب.');
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', pathinfo($name, PATHINFO_FILENAME));
    $filename = $prefix . '_' . date('Ymd_His') . '_' . ($safeBase ?: bin2hex(random_bytes(4))) . '.' . $ext;
    $target = $fullDir . $filename;
    if (!move_uploaded_file($validatedFile['tmp_name'], $target)) {
        throw new RuntimeException('فشل حفظ الملف المرفوع: ' . $name);
    }

    return trim($dir, '/\\') . '/' . $filename;
}

function ppt_template_mime_map(array $allowedExt): array
{
    $known = [
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];
    return array_intersect_key($known, array_flip($allowedExt));
}

function ppt_template_delete_stored_path(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $normalized = str_replace('\\', '/', ltrim($relativePath, '/\\'));
    if (!str_starts_with($normalized, 'storage/ppt_templates/')) {
        return;
    }
    @unlink(dirname(__DIR__) . '/' . $normalized);
}

function ppt_template_uploaded_files(string $field): array
{
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
        return [];
    }

    if (!is_array($_FILES[$field]['name'])) {
        return [$_FILES[$field]];
    }

    $files = [];
    foreach ($_FILES[$field]['name'] as $index => $name) {
        if (($name ?? '') === '') {
            continue;
        }
        $files[] = [
            'name' => $_FILES[$field]['name'][$index],
            'type' => $_FILES[$field]['type'][$index],
            'tmp_name' => $_FILES[$field]['tmp_name'][$index],
            'error' => $_FILES[$field]['error'][$index],
            'size' => $_FILES[$field]['size'][$index],
        ];
    }
    return $files;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrfPost();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            $existing = $id > 0 ? $library->find($id) : null;
            $bulkFiles = ppt_template_uploaded_files('pptx_files');

            if ($bulkFiles && !$existing) {
                $count = 0;
                $storedPaths = [];
                $db->beginTransaction();
                try {
                    foreach ($bulkFiles as $file) {
                        $filePath = ppt_template_store_uploaded_file($file, ['pptx'], 'storage/ppt_templates', 'lesson_tpl');
                        $storedPaths[] = $filePath;
                        $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
                        $library->save([
                            'name' => trim($_POST['name'] ?? '') ?: $baseName,
                            'subject' => trim($_POST['subject'] ?? ''),
                            'stage' => trim($_POST['stage'] ?? ''),
                            'lesson_type' => trim($_POST['lesson_type'] ?? ''),
                            'language' => trim($_POST['language'] ?? ''),
                            'min_slides' => max(0, (int)($_POST['min_slides'] ?? 0)),
                            'max_slides' => max(0, (int)($_POST['max_slides'] ?? 0)),
                            'theme_hint' => trim($_POST['theme_hint'] ?? ''),
                            'keywords' => trim($_POST['keywords'] ?? ''),
                            'file_path' => $filePath,
                            'thumbnail_path' => '',
                            'is_active' => ($_POST['is_active'] ?? '0') === '1',
                        ]);
                        $count++;
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    foreach ($storedPaths as $storedPath) {
                        ppt_template_delete_stored_path($storedPath);
                    }
                    throw $e;
                }

                $_SESSION['ppt_tpl_success'] = 'تم رفع ' . $count . ' قالب PowerPoint بنجاح.';
                header('Location: lesson_ppt_templates.php');
                exit();
            }

            $filePath = null;
            $thumbPath = null;
            try {
                $filePath = ppt_template_upload('pptx_file', ['pptx'], 'storage/ppt_templates', 'lesson_tpl');
                $thumbPath = ppt_template_upload('thumbnail_file', ['jpg', 'jpeg', 'png', 'webp'], 'storage/ppt_templates/thumbs', 'lesson_tpl_thumb');

                if (!$filePath && !$existing) {
                    throw new RuntimeException('يرجى رفع ملف PowerPoint للقالب.');
                }

                $savedId = $library->save([
                'id' => $id,
                'name' => trim($_POST['name'] ?? ''),
                'subject' => trim($_POST['subject'] ?? ''),
                'stage' => trim($_POST['stage'] ?? ''),
                'lesson_type' => trim($_POST['lesson_type'] ?? ''),
                'language' => trim($_POST['language'] ?? ''),
                'min_slides' => max(0, (int)($_POST['min_slides'] ?? 0)),
                'max_slides' => max(0, (int)($_POST['max_slides'] ?? 0)),
                'theme_hint' => trim($_POST['theme_hint'] ?? ''),
                'keywords' => trim($_POST['keywords'] ?? ''),
                'file_path' => $filePath ?? '',
                'thumbnail_path' => $thumbPath ?? '',
                'is_active' => ($_POST['is_active'] ?? '0') === '1',
                ]);
            } catch (Throwable $e) {
                ppt_template_delete_stored_path($filePath);
                ppt_template_delete_stored_path($thumbPath);
                throw $e;
            }

            if ($existing && $filePath) {
                ppt_template_delete_stored_path($existing['file_path'] ?? null);
            }
            if ($existing && $thumbPath) {
                ppt_template_delete_stored_path($existing['thumbnail_path'] ?? null);
            }

            ActivityLog::log('settings', 'lesson_ppt_template', $savedId, trim($_POST['name'] ?? 'قالب PowerPoint'), [
                'action' => $id > 0 ? 'update' : 'create',
            ]);
            $_SESSION['ppt_tpl_success'] = 'تم حفظ قالب PowerPoint بنجاح.';
            header('Location: lesson_ppt_templates.php');
            exit();
        }

        if ($action === 'delete_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            if ($id > 0) {
                $library->delete($id);
                ActivityLog::logDelete('lesson_ppt_template', $id, 'قالب PowerPoint');
                $_SESSION['ppt_tpl_success'] = 'تم حذف القالب.';
            }
            header('Location: lesson_ppt_templates.php');
            exit();
        }
    } catch (Throwable $e) {
        $_SESSION['ppt_tpl_error'] = $e->getMessage();
        header('Location: lesson_ppt_templates.php');
        exit();
    }
}

$templates = $library->all();
$page_title = 'مكتبة قوالب PowerPoint التعليمية';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<style>
.ppt-template-card > .card-body {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    min-height: 260px;
    background: #fff;
    padding: 1rem !important;
}
.ppt-template-card .form-label {
    font-weight: 700;
    color: #334155;
}
</style>

<div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-file-powerpoint me-2 text-danger"></i>مكتبة قوالب PowerPoint التعليمية</h1>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow mb-4 ppt-template-card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-upload me-2"></i>إضافة قالب جديد</h5>
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_template">

            <div class="col-md-4">
                <label class="form-label">اسم القالب</label>
                <input type="text" name="name" class="form-control" required placeholder="مثال: علوم حديث - المرحلة الابتدائية">
            </div>
            <div class="col-md-2">
                <label class="form-label">المادة</label>
                <input type="text" name="subject" class="form-control" placeholder="علوم / رياضيات">
            </div>
            <div class="col-md-2">
                <label class="form-label">المرحلة</label>
                <input type="text" name="stage" class="form-control" placeholder="ابتدائي / إعدادي">
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع الدرس</label>
                <input type="text" name="lesson_type" class="form-control" placeholder="شرح / مراجعة">
            </div>
            <div class="col-md-2">
                <label class="form-label">اللغة</label>
                <select name="language" class="form-select">
                    <option value="">أي لغة</option>
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                    <option value="fr">Français</option>
                    <option value="de">Deutsch</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">أقل شرائح</label>
                <input type="number" name="min_slides" min="0" class="form-control" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">أكثر شرائح</label>
                <input type="number" name="max_slides" min="0" class="form-control" value="0">
            </div>
            <div class="col-md-3">
                <label class="form-label">النمط البصري</label>
                <input type="text" name="theme_hint" class="form-control" placeholder="أطفال / رسمي / علمي">
            </div>
            <div class="col-md-5">
                <label class="form-label">كلمات مفتاحية</label>
                <input type="text" name="keywords" class="form-control" placeholder="خلية، نبات، معادلات، هندسة">
            </div>

            <div class="col-md-5">
                <label class="form-label">ملف القالب PPTX</label>
                <input type="file" name="pptx_file" class="form-control" accept=".pptx">
                <div class="form-text">يمكن تصدير القالب يدويًا من Canva بصيغة PPTX ثم رفعه هنا.</div>
            </div>
            <div class="col-md-7">
                <label class="form-label">رفع عدة قوالب دفعة واحدة</label>
                <input type="file" name="pptx_files[]" class="form-control" accept=".pptx" multiple>
                <div class="form-text">عند اختيار عدة ملفات، سيتم إنشاء قالب مستقل لكل ملف بنفس التصنيف والكلمات المفتاحية.</div>
            </div>
            <div class="col-md-5">
                <label class="form-label">صورة مصغرة اختيارية</label>
                <input type="file" name="thumbnail_file" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" id="tplActive" checked>
                    <label for="tplActive" class="form-check-label">نشط</label>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-save me-1"></i>حفظ القالب
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-layer-group me-2"></i>القوالب المسجلة</h5>
        <span class="badge bg-light text-dark"><?= count($templates) ?></span>
    </div>
    <div class="card-body">
        <?php if (!$templates): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-file-powerpoint fs-1 mb-3 d-block"></i>
            لا توجد قوالب بعد.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>القالب</th>
                        <th>التصنيف</th>
                        <th>الشرائح</th>
                        <th>الكلمات</th>
                        <th>الحالة</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <small class="text-muted"><?= htmlspecialchars($tpl['file_path'], ENT_QUOTES, 'UTF-8') ?></small>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark"><?= htmlspecialchars($tpl['subject'] ?: 'أي مادة', ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge bg-light text-dark"><?= htmlspecialchars($tpl['stage'] ?: 'أي مرحلة', ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge bg-light text-dark"><?= htmlspecialchars($tpl['lesson_type'] ?: 'أي نوع', ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td><?= (int)$tpl['min_slides'] ?> - <?= (int)$tpl['max_slides'] ?: 'مفتوح' ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($tpl['keywords'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= (int)$tpl['is_active'] === 1 ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">متوقف</span>' ?>
                        </td>
                        <td class="text-center">
                            <form method="post" class="d-inline" data-confirm-message="هل تريد حذف هذا القالب؟" data-confirm-operation="delete">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
