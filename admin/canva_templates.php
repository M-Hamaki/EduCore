<?php
/**
 * إدارة قوالب Canva
 * تصفح التصاميم، تنزيل PPTX، واختيار القالب النشط
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/CanvaIntegration.php';
require_once '../classes/ActivityLog.php';

Utilities::validateSession('admin');

$database = new Database();
$db       = $database->getConnection();
$canva    = new CanvaIntegration($db);

// التحقق من حالة الاتصال بـ Canva
$isConnected = $canva->isConnected();

// --- استخراج رسائل الجلسة ---
$success_message = $_SESSION['canva_success'] ?? null;
$error_message   = $_SESSION['canva_error']   ?? null;
unset($_SESSION['canva_success'], $_SESSION['canva_error']);

// =========================================================
// معالجة الإجراءات (PRG Pattern)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $action = $_POST['action'] ?? '';

    // --- تنزيل تصميم كـ PPTX ---
    if ($action === 'download_template') {
        $isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_POST['ajax'] ?? '') === '1';
        $designId   = trim($_POST['design_id']   ?? '');
        $designName = trim($_POST['design_name'] ?? '');
        $thumbUrl   = trim($_POST['thumb_url']   ?? '');

        if ($designId === '') {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'معرّف التصميم مطلوب.'], JSON_UNESCAPED_UNICODE); exit(); }
            $_SESSION['canva_error'] = 'معرّف التصميم مطلوب.';
            header('Location: canva_templates.php'); exit();
        }

        set_time_limit(300); // السماح بوقت كافٍ للتصدير

        // حفظ البيانات الأساسية أولاً
        $canva->saveTemplate($designId, $designName, $thumbUrl, null);

        // تصدير PPTX (قد يستغرق وقتاً)
        $result = $canva->exportDesignAsPptx($designId, $designName);

        if ($result['success']) {
            $canva->saveTemplate($designId, $designName, $thumbUrl, $result['path']);
            ActivityLog::logCreate('canva_template', 0, $designName, [
                'design_id' => $designId,
                'path'      => $result['path'],
            ]);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>'تم تنزيل القالب بنجاح!'], JSON_UNESCAPED_UNICODE); exit(); }
            $_SESSION['canva_success'] = 'تم تنزيل القالب "' . $designName . '" بنجاح!';
        } else {
            $errTxt = 'فشل تنزيل القالب: ' . ($result['error'] ?? 'خطأ غير معروف');
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>$errTxt], JSON_UNESCAPED_UNICODE); exit(); }
            $_SESSION['canva_error'] = $errTxt;
        }

        header('Location: canva_templates.php');
        exit();
    }

    // --- حفظ Brand Template لاستخدامه عبر Canva Autofill ---
    if ($action === 'save_brand_template') {
        $brandTemplateId = trim($_POST['brand_template_id'] ?? '');
        $templateName = trim($_POST['template_name'] ?? '');
        $thumbUrl = trim($_POST['thumb_url'] ?? '');

        if ($brandTemplateId === '') {
            $_SESSION['canva_error'] = 'معرّف Brand Template مطلوب.';
            header('Location: canva_templates.php');
            exit();
        }

        $dataset = $canva->getBrandTemplateDataset($brandTemplateId);
        if (empty($dataset)) {
            $_SESSION['canva_error'] = 'هذا القالب لا يحتوي حقول Autofill قابلة للتعبئة. افتح القالب في Canva وأضف حقول Data Autofill أولاً.';
            header('Location: canva_templates.php');
            exit();
        }

        $canva->saveBrandTemplate($brandTemplateId, $templateName ?: $brandTemplateId, $thumbUrl, $dataset);
        $_SESSION['canva_success'] = 'تم حفظ Brand Template وتفعيله للاستخدام التلقائي في PowerPoint.';

        $saved = $canva->getAllTemplates();
        foreach ($saved as $tpl) {
            if (($tpl['design_id'] ?? '') === $brandTemplateId) {
                $canva->setActiveTemplate((int)$tpl['id']);
                break;
            }
        }

        ActivityLog::logCreate('canva_brand_template', 0, $templateName ?: $brandTemplateId, [
            'brand_template_id' => $brandTemplateId,
        ]);

        header('Location: canva_templates.php');
        exit();
    }

    // --- تفعيل قالب ---
    if ($action === 'set_active') {
        $id = (int)($_POST['template_id'] ?? 0);
        if ($id > 0) {
            $canva->setActiveTemplate($id);
            $_SESSION['canva_success'] = 'تم تفعيل القالب بنجاح. سيُستخدم في ملفات PowerPoint القادمة.';
        }
        header('Location: canva_templates.php');
        exit();
    }

    // --- إلغاء تفعيل القالب ---
    if ($action === 'clear_active') {
        $canva->clearActiveTemplate();
        $_SESSION['canva_success'] = 'تم إلغاء تفعيل القالب. ستُستخدم القوالب الافتراضية.';
        header('Location: canva_templates.php');
        exit();
    }

    // --- حذف قالب ---
    if ($action === 'delete_template') {
        $id = (int)($_POST['template_id'] ?? 0);
        if ($id > 0) {
            $canva->deleteTemplate($id);
            $_SESSION['canva_success'] = 'تم حذف القالب.';
        }
        header('Location: canva_templates.php');
        exit();
    }

    header('Location: canva_templates.php');
    exit();
}

// =========================================================
// جلب البيانات للعرض
// =========================================================

// البيانات والتهيئة الافتراضية
$savedTemplates  = [];
$activeTemplate  = null;
$savedTemplateIds = [];
$canvaDesigns  = [];
$brandTemplates = [];
$continuation  = null;
$brandContinuation = null;
$fetchError    = null;
$brandFetchError = null;

if ($isConnected) {
    // القوالب المحفوظة في قاعدة البيانات
    $savedTemplates  = $canva->getAllTemplates();
    $activeTemplate  = $canva->getActiveTemplate();
    $savedTemplateIds = array_column($savedTemplates, 'design_id');

    // تصاميم Canva المتاحة (من API)
    $listResult = $canva->listDesigns(24);
    if (isset($listResult['designs'])) {
        $canvaDesigns = $listResult['designs'];
        $continuation = $listResult['continuation'];
    } else {
        $fetchError = 'تعذّر جلب التصاميم من Canva. تحقق من صلاحيات الـ Scopes.';
    }

    $brandResult = $canva->listBrandTemplates(24, null, 'non_empty');
    if (isset($brandResult['templates'])) {
        $brandTemplates = $brandResult['templates'];
        $brandContinuation = $brandResult['continuation'];
    } else {
        $brandFetchError = 'تعذّر جلب Brand Templates من Canva. تأكد من صلاحيات brandtemplate ومن أن حسابك يدعم Brand Templates.';
    }
}

$page_title = 'إدارة قوالب Canva';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<style>
.canva-design-card {
    transition: transform .15s, box-shadow .15s;
    cursor: pointer;
}
.canva-design-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,.12) !important;
}
.canva-design-card .thumb-wrap {
    height: 140px;
    overflow: hidden;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
}
.canva-design-card .thumb-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.canva-design-card .thumb-wrap .no-thumb {
    color: #94a3b8;
    font-size: 2.5rem;
}
.active-badge {
    position: absolute;
    top: 8px;
    right: 8px;
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-layer-group me-2 text-primary"></i>إدارة قوالب Canva</h1>
    <div class="admin-top-actions no-print">
        <a href="canva_settings.php" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-arrow-right me-1"></i>الإعدادات
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($fetchError): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($fetchError, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($brandFetchError): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($brandFetchError, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if (!$isConnected): ?>
<div class="row justify-content-center py-5">
    <div class="col-md-8 text-center">
        <div class="card shadow-sm border-0 p-5 bg-white rounded-4">
            <div class="mb-4">
                <i class="fas fa-palette text-purple" style="font-size: 4.5rem; color: #8b3dff;"></i>
            </div>
            <h3 class="fw-bold mb-3">تكامل Canva غير متصل</h3>
            <p class="text-muted mb-4 fs-6">
                يرجى ربط وتكامل حسابك في Canva أولاً لتتمكن من إدارة وتصفح القوالب، وتنزيلها وتفعيلها للاستخدام التلقائي في النظام.
            </p>
            <div class="d-flex justify-content-center gap-3">
                <a href="canva_settings.php" class="btn btn-primary px-4 py-2 fw-semibold">
                    <i class="fas fa-link me-2"></i>الذهاب لربط الحساب الآن
                </a>
            </div>
        </div>
    </div>
</div>
<?php
require_once '../includes/admin_footer.php';
exit();
endif;
?>

<!-- ===== القالب النشط ===== -->
<?php if ($activeTemplate): ?>
<div class="alert alert-success d-flex align-items-center mb-4" role="alert">
    <i class="fas fa-star me-3 fs-4"></i>
    <div class="flex-grow-1">
        <strong>القالب النشط:</strong>
        <?= htmlspecialchars($activeTemplate['name'] ?? $activeTemplate['design_id'], ENT_QUOTES, 'UTF-8') ?>
        <?php if (($activeTemplate['template_type'] ?? 'design') === 'brand_template'): ?>
        <span class="badge bg-info text-dark ms-2">Canva Autofill</span>
        <small class="text-muted ms-2">— سيُنشئ Canva العرض تلقائياً من هذا القالب ثم يصدّره PPTX</small>
        <?php else: ?>
        <span class="badge bg-secondary ms-2">PPTX محلي</span>
        <small class="text-muted ms-2">— سيُدمج مع الشرائح المولدة محلياً</small>
        <?php endif; ?>
    </div>
    <form method="post" action="canva_templates.php" class="ms-3">
        <input type="hidden" name="action" value="clear_active">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">
            <i class="fas fa-times me-1"></i>إلغاء التفعيل
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ===== القوالب المحفوظة ===== -->
<?php if (!empty($savedTemplates)): ?>
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-download me-2"></i>القوالب المنزَّلة
            <span class="badge bg-light text-dark ms-2"><?= count($savedTemplates) ?></span>
        </h5>
    </div>
    <div class="card-body">
        <div class="row row-cols-2 row-cols-md-4 g-3">
            <?php foreach ($savedTemplates as $tpl): ?>
            <?php $isActive = (int)$tpl['is_active'] === 1; ?>
            <div class="col">
                <div class="card canva-design-card h-100 shadow-sm position-relative <?= $isActive ? 'border-success border-2' : '' ?>">
                    <?php if ($isActive): ?>
                    <span class="badge bg-success active-badge">
                        <i class="fas fa-star me-1"></i>نشط
                    </span>
                    <?php endif; ?>
                    <div class="thumb-wrap">
                        <?php if (!empty($tpl['thumbnail_url'])): ?>
                        <img src="<?= htmlspecialchars($tpl['thumbnail_url'], ENT_QUOTES, 'UTF-8') ?>" alt="thumbnail">
                        <?php else: ?>
                        <i class="fas fa-file-powerpoint no-thumb"></i>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <p class="mb-1 fw-semibold small text-truncate" title="<?= htmlspecialchars($tpl['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($tpl['name'] ?? 'بدون اسم', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <p class="mb-0 text-muted" style="font-size:.75rem;">
                            <?php if (($tpl['template_type'] ?? 'design') === 'brand_template'): ?>
                            <i class="fab fa-canva text-primary me-1"></i>Brand Template Autofill
                            <?php else: ?>
                            <?= $tpl['pptx_local_path'] ? '<i class="fas fa-check-circle text-success me-1"></i>PPTX متاح' : '<i class="fas fa-exclamation-circle text-warning me-1"></i>لم يُنزَّل بعد' ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="card-footer p-2 d-flex gap-1 flex-wrap">
                        <?php if (!$isActive): ?>
                        <form method="post" action="canva_templates.php" class="d-inline">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-success" data-bs-toggle="tooltip" title="تفعيل">
                                <i class="fas fa-star"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal"
                                data-bs-target="#deleteModal"
                                data-id="<?= (int)$tpl['id'] ?>"
                                data-name="<?= htmlspecialchars($tpl['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                title="حذف">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== Brand Templates من Canva ===== -->
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fab fa-canva me-2"></i>Brand Templates القابلة للتعبئة من Canva
            <?php if (!empty($brandTemplates)): ?>
            <span class="badge bg-light text-dark ms-2"><?= count($brandTemplates) ?></span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info small">
            <i class="fas fa-info-circle me-1"></i>
            هذه القوالب هي التي يمكن استخدامها تلقائياً أثناء توليد PowerPoint عبر Canva Autofill. يجب أن يحتوي القالب في Canva على حقول Data Autofill نصية.
        </div>
        <?php if (empty($brandTemplates) && !$brandFetchError): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-wand-magic-sparkles fs-1 mb-3 d-block"></i>
            <p>لم يتم العثور على Brand Templates تحتوي Dataset.</p>
            <p class="small">أنشئ Brand Template في Canva ثم أضف له حقول Data Autofill.</p>
        </div>
        <?php else: ?>
        <div class="row row-cols-2 row-cols-md-4 g-3">
            <?php foreach ($brandTemplates as $tpl): ?>
            <?php
                $btId = $tpl['id'] ?? $tpl['brand_template_id'] ?? '';
                $btName = $tpl['title'] ?? $tpl['name'] ?? 'Brand Template';
                $btThumb = $tpl['thumbnail']['url'] ?? $tpl['thumbnail_url'] ?? '';
                $alreadySaved = in_array($btId, $savedTemplateIds, true);
            ?>
            <div class="col">
                <div class="card canva-design-card h-100 shadow-sm">
                    <div class="thumb-wrap">
                        <?php if ($btThumb): ?>
                        <img src="<?= htmlspecialchars($btThumb, ENT_QUOTES, 'UTF-8') ?>" alt="brand template thumbnail">
                        <?php else: ?>
                        <i class="fab fa-canva no-thumb"></i>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <p class="mb-1 fw-semibold small text-truncate" title="<?= htmlspecialchars($btName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($btName, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <?php if ($alreadySaved): ?>
                        <span class="badge bg-success-subtle text-success" style="font-size:.7rem;">
                            <i class="fas fa-check me-1"></i>محفوظ
                        </span>
                        <?php else: ?>
                        <span class="badge bg-info-subtle text-info" style="font-size:.7rem;">
                            Autofill
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer p-2">
                        <form method="post" action="canva_templates.php">
                            <input type="hidden" name="action" value="save_brand_template">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="brand_template_id" value="<?= htmlspecialchars($btId, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="template_name" value="<?= htmlspecialchars($btName, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="thumb_url" value="<?= htmlspecialchars($btThumb, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="fas fa-star me-1"></i><?= $alreadySaved ? 'تحديث وتفعيل' : 'حفظ وتفعيل' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($brandContinuation): ?>
        <div class="text-center mt-3">
            <p class="text-muted small">يوجد المزيد من Brand Templates. سيتم دعم التحميل الجزئي لاحقاً.</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ===== تصاميم Canva المتاحة ===== -->
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fab fa-canva me-2"></i>تصاميمك في Canva
            <?php if (!empty($canvaDesigns)): ?>
            <span class="badge bg-light text-dark ms-2"><?= count($canvaDesigns) ?></span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-secondary small">
            <i class="fas fa-search me-1"></i>
            عند عدم اختيار قالب نشط، سيحاول مولد PowerPoint البحث تلقائياً داخل تصاميم Canva الموجودة في هذا الحساب واستخدام التصميم الأقرب للدرس كقالب بصري مؤقت، ثم يعود للقوالب المحلية إذا لم يجد نتيجة مناسبة.
        </div>
        <?php if (empty($canvaDesigns) && !$fetchError): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-folder-open fs-1 mb-3 d-block"></i>
            <p>لا توجد تصاميم في حسابك أو لم يتم التحميل بعد.</p>
            <p class="small">تأكد من أن Scopes تتضمن <code>design:meta:read</code> و <code>design:content:read</code></p>
        </div>
        <?php else: ?>
        <div class="row row-cols-2 row-cols-md-4 g-3">
            <?php foreach ($canvaDesigns as $design): ?>
            <?php
                $dId    = $design['id'] ?? '';
                $dName  = $design['title'] ?? 'بدون عنوان';
                $dThumb = $design['thumbnail']['url'] ?? '';
                $alreadySaved = in_array($dId, $savedTemplateIds, true);
            ?>
            <div class="col">
                <div class="card canva-design-card h-100 shadow-sm">
                    <div class="thumb-wrap">
                        <?php if ($dThumb): ?>
                        <img src="<?= htmlspecialchars($dThumb, ENT_QUOTES, 'UTF-8') ?>" alt="thumbnail">
                        <?php else: ?>
                        <i class="fas fa-image no-thumb"></i>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <p class="mb-1 fw-semibold small text-truncate" title="<?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <?php if ($alreadySaved): ?>
                        <span class="badge bg-success-subtle text-success" style="font-size:.7rem;">
                            <i class="fas fa-check me-1"></i>محفوظ
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer p-2">
                        <?php if (!$alreadySaved): ?>
                        <button class="btn btn-sm btn-primary w-100"
                                data-bs-toggle="modal" data-bs-target="#downloadModal"
                                data-id="<?= htmlspecialchars($dId, ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>"
                                data-thumb="<?= htmlspecialchars($dThumb, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-download me-1"></i>تنزيل كـ PPTX
                        </button>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary w-100" disabled>
                            <i class="fas fa-check me-1"></i>محفوظ مسبقاً
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($continuation): ?>
        <div class="text-center mt-3">
            <p class="text-muted small">يوجد المزيد من التصاميم. سيتم دعم التحميل الجزئي قريباً.</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Modal: تنزيل تصميم ===== -->
<div class="modal fade" id="downloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-download me-2"></i>تنزيل القالب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="dlCloseBtn"></button>
            </div>
            <div class="modal-body" id="dlModalBody">
                <div class="text-center mb-3">
                    <i class="fas fa-file-powerpoint text-primary" style="font-size:3rem;"></i>
                </div>
                <p class="text-center">هل تريد تنزيل التصميم <span class="fw-bold text-primary" id="dlDesignNameDisplay"></span> كقالب PowerPoint؟</p>
                <div class="alert alert-info">
                    <i class="fas fa-clock me-2"></i>
                    قد يستغرق التصدير من Canva دقيقة أو أكثر. الرجاء الانتظار.
                </div>
                <!-- مؤشر التقدم (مخفي مبدئياً) -->
                <div id="dlProgress" class="d-none">
                    <div class="text-center mb-2">
                        <div class="spinner-border text-primary" style="width:2.5rem;height:2.5rem;"></div>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary w-100"></div>
                    </div>
                    <p class="text-center text-muted small mt-2" id="dlProgressMsg">جارٍ إرسال طلب التصدير إلى Canva...</p>
                </div>
                <!-- رسالة نجاح -->
                <div id="dlSuccess" class="d-none alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><span id="dlSuccessMsg"></span>
                </div>
                <!-- رسالة خطأ -->
                <div id="dlError" class="d-none alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><span id="dlErrorMsg"></span>
                </div>
            </div>
            <div class="modal-footer" id="dlModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-primary" id="dlSubmitBtn">
                    <i class="fas fa-download me-1"></i>تنزيل
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Modal: حذف قالب ===== -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="canva_templates.php">
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="template_id" id="delTemplateId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف القالب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-trash text-danger" style="font-size:3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد حذف القالب <span class="fw-bold text-danger" id="delTemplateName"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيُحذف ملف PPTX المحلي أيضاً. لا يمكن التراجع.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>حذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ملء بيانات Modal التنزيل
var dlCurrentId = '', dlCurrentName = '', dlCurrentThumb = '';

document.getElementById('downloadModal').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    dlCurrentId    = btn.dataset.id;
    dlCurrentName  = btn.dataset.name;
    dlCurrentThumb = btn.dataset.thumb || '';
    document.getElementById('dlDesignNameDisplay').textContent = dlCurrentName;
    // إعادة ضبط الحالة
    document.getElementById('dlProgress').classList.add('d-none');
    document.getElementById('dlSuccess').classList.add('d-none');
    document.getElementById('dlError').classList.add('d-none');
    document.getElementById('dlSubmitBtn').classList.remove('d-none');
    document.getElementById('dlCloseBtn').disabled = false;
});

// زر التنزيل — AJAX
document.getElementById('dlSubmitBtn').addEventListener('click', function () {
    var btn = this;
    btn.classList.add('d-none');
    document.getElementById('dlCloseBtn').disabled = true;
    document.getElementById('dlProgress').classList.remove('d-none');
    document.getElementById('dlError').classList.add('d-none');

    // إرسال AJAX
    var fd = new FormData();
    fd.append('action',       'download_template');
    fd.append('ajax',         '1');
    fd.append('csrf_token',   '<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>');
    fd.append('design_id',    dlCurrentId);
    fd.append('design_name',  dlCurrentName);
    fd.append('thumb_url',    dlCurrentThumb);

    // تحديث رسالة التقدم بعد 5 ثواني
    var msgEl = document.getElementById('dlProgressMsg');
    setTimeout(function() { msgEl.textContent = 'جارٍ انتظار Canva لإنهاء التصدير...'; }, 5000);
    setTimeout(function() { msgEl.textContent = 'قد يستغرق التصدير حتى دقيقتين، يرجى الانتظار...'; }, 30000);

    fetch('canva_templates.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('dlProgress').classList.add('d-none');
            document.getElementById('dlCloseBtn').disabled = false;
            if (data.success) {
                document.getElementById('dlSuccessMsg').textContent = data.message || 'تم التنزيل بنجاح!';
                document.getElementById('dlSuccess').classList.remove('d-none');
                // إعادة تحميل الصفحة بعد 2 ثانية
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                document.getElementById('dlErrorMsg').textContent = data.error || 'حدث خطأ غير معروف';
                document.getElementById('dlError').classList.remove('d-none');
                btn.classList.remove('d-none');
            }
        })
        .catch(function(err) {
            document.getElementById('dlProgress').classList.add('d-none');
            document.getElementById('dlCloseBtn').disabled = false;
            document.getElementById('dlErrorMsg').textContent = 'خطأ في الاتصال: ' + err.message;
            document.getElementById('dlError').classList.remove('d-none');
            btn.classList.remove('d-none');
        });
});

// ملء بيانات Modal الحذف
document.getElementById('deleteModal').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('delTemplateId').value          = btn.dataset.id;
    document.getElementById('delTemplateName').textContent  = btn.dataset.name;
});

// تفعيل Tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>

