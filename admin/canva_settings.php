<?php
/**
 * إعدادات تكامل Canva
 * صفحة الربط / قطع الربط مع حساب Canva
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/CanvaIntegration.php';

Utilities::validateSession('admin');

$database = new Database();
$db       = $database->getConnection();
$canva    = new CanvaIntegration($db);

// --- استخراج رسائل الجلسة (PRG) ---
$success_message = $_SESSION['canva_success'] ?? null;
$error_message   = $_SESSION['canva_error']   ?? null;
unset($_SESSION['canva_success'], $_SESSION['canva_error']);

// --- معالجة قطع الاتصال ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect') {
    requireCsrfPost();
    $canva->disconnect();
    $_SESSION['canva_success'] = 'تم قطع الاتصال مع Canva بنجاح.';
    header('Location: canva_settings.php');
    exit();
}

// --- توليد رابط التفويض ---
$authUrl     = $canva->getAuthorizationUrl();
$isConnected = $canva->isConnected();
$hasRequiredScopes = $isConnected ? $canva->hasRequiredScopes() : false;
$missingScopes = $isConnected ? $canva->getMissingScopes() : [];
$activeTemplate = $canva->getActiveTemplate();

$page_title = 'إعدادات تكامل Canva';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fab fa-canva me-2 text-primary"></i>إعدادات تكامل Canva</h1>
    <div class="admin-top-actions no-print">
        <?php if ($isConnected): ?>
        <a href="canva_templates.php" class="btn btn-header-premium btn-outline-primary">
            <i class="fas fa-layer-group me-1"></i>إدارة القوالب
        </a>
        <?php endif; ?>
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

<div class="row g-4">

    <!-- بطاقة حالة الاتصال -->
    <div class="col-md-7">
        <div class="card shadow h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plug me-2"></i>حالة الاتصال</h5>
            </div>
            <div class="card-body">
                <?php if ($isConnected): ?>
                <!-- متصل -->
                <div class="d-flex align-items-center mb-4">
                    <div class="rounded-circle bg-success d-flex align-items-center justify-content-center me-3"
                         style="width:56px;height:56px;min-width:56px;">
                        <i class="fas fa-check text-white fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-1 text-success">متصل بـ Canva</h5>
                        <p class="mb-0 text-muted small">
                            <?= $hasRequiredScopes
                                ? 'يمكنك الآن استخدام قوالب Canva في ملفات PowerPoint المُولَّدة'
                                : 'الاتصال يعمل، لكن يحتاج تحديث الصلاحيات الجديدة لاستخدام قوالب Canva المتقدمة' ?>
                        </p>
                    </div>
                </div>

                <?php if (!$hasRequiredScopes): ?>
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-key me-2"></i>
                    تمت إضافة صلاحيات جديدة للتكامل، لكن التوكن الحالي لا يحتوي عليها بعد.
                    <?php if (!empty($missingScopes)): ?>
                    <div class="small mt-2">
                        <strong>الصلاحيات الناقصة:</strong>
                        <?= htmlspecialchars(implode('، ', $missingScopes), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="<?= htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-warning btn-sm">
                            <i class="fas fa-sync-alt me-1"></i>تحديث صلاحيات Canva الآن
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($activeTemplate): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-star me-2"></i>
                    <strong>القالب النشط:</strong>
                    <?= htmlspecialchars($activeTemplate['name'] ?? $activeTemplate['design_id'], ENT_QUOTES, 'UTF-8') ?>
                    <a href="canva_templates.php" class="ms-2 alert-link">تغيير</a>
                </div>
                <?php else: ?>
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    لم يُحدَّد قالب نشط بعد.
                    <a href="canva_templates.php" class="ms-1 alert-link">اختر قالباً</a>
                </div>
                <?php endif; ?>

                <form method="post" action="canva_settings.php" id="disconnectForm">
                    <input type="hidden" name="action" value="disconnect">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#disconnectModal">
                        <i class="fas fa-unlink me-2"></i>قطع الاتصال
                    </button>
                    <?php if (!$hasRequiredScopes): ?>
                    <a href="<?= htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-warning ms-2">
                        <i class="fas fa-sync-alt me-2"></i>تحديث صلاحيات Canva
                    </a>
                    <?php endif; ?>
                    <a href="canva_templates.php" class="btn btn-primary ms-2">
                        <i class="fas fa-layer-group me-2"></i>إدارة القوالب
                    </a>
                </form>

                <?php else: ?>
                <!-- غير متصل -->
                <div class="d-flex align-items-center mb-4">
                    <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center me-3"
                         style="width:56px;height:56px;min-width:56px;">
                        <i class="fas fa-unlink text-white fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-1 text-secondary">غير متصل بـ Canva</h5>
                        <p class="mb-0 text-muted small">اربط حساب Canva Pro لاستخدام قوالب احترافية</p>
                    </div>
                </div>

                <a href="<?= htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-success px-4 py-2">
                    <i class="fab fa-canva me-2"></i>ربط حساب Canva
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- بطاقة المعلومات -->
    <div class="col-md-5">
        <div class="card shadow h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>كيف يعمل التكامل؟</h5>
            </div>
            <div class="card-body">
                <ol class="ps-3 mb-0" style="line-height: 2;">
                    <li>اربط حسابك في Canva بالنقر على الزر أعلاه</li>
                    <li>صمّم قالب عرض تقديمي في Canva (غلاف + خاتمة)</li>
                    <li>اذهب إلى <strong>إدارة القوالب</strong> واختر تصميمك</li>
                    <li>سيُنزَّل القالب تلقائياً ويُدمج مع شرائح الدرس المُولَّدة بالذكاء الاصطناعي</li>
                </ol>

                <hr>
                <h6 class="text-muted mb-2"><i class="fas fa-shield-alt me-1"></i>ملاحظات الأمان</h6>
                <ul class="ps-3 small text-muted mb-0">
                    <li>الاتصال يستخدم OAuth 2.0 + PKCE (المعيار الأكثر أماناً)</li>
                    <li>بيانات الاعتماد مشفّرة ومخزّنة في قاعدة البيانات فقط</li>
                    <li>يمكنك قطع الاتصال في أي وقت</li>
                </ul>
            </div>
        </div>
    </div>

</div><!-- /row -->

<!-- ===== Modal قطع الاتصال ===== -->
<div class="modal fade" id="disconnectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-unlink me-2"></i>تأكيد قطع الاتصال</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-danger" style="font-size:3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من قطع الاتصال مع Canva؟</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    سيتم حذف التوكنات المحفوظة وإلغاء تفعيل القالب النشط. لن تُحذف ملفات PPTX المنزَّلة.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-warning" id="confirmDisconnectBtn">
                    <i class="fas fa-unlink me-1"></i>قطع الاتصال
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('confirmDisconnectBtn')?.addEventListener('click', function () {
    document.getElementById('disconnectForm').submit();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
