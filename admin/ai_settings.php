<?php
/**
 * إعدادات الذكاء الاصطناعي
 * AI Settings — Admin Panel
 * إدارة مزوّدي AI (Gemini + Ollama) واختبار الاتصال
 */
$page_title = "إعدادات الذكاء الاصطناعي";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

require_once '../classes/AIProvider.php';

// --- Handle POST actions (PRG pattern) ---
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        $settings = [
            'ai_provider' => $_POST['ai_provider'] ?? 'auto',
            'ollama_model' => $_POST['ollama_model'] ?? 'gemma3:4b',
        ];
        
        // Validate provider value
        if (!in_array($settings['ai_provider'], ['gemini', 'ollama', 'auto'])) {
            $settings['ai_provider'] = 'auto';
        }
        
        try {
            $db->beginTransaction();
            $beforeStmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('ai_provider', 'ollama_model') FOR UPDATE");
            $beforeStmt->execute();
            $before = $beforeStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($settings as $key => $value) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                if ($stmt->fetchColumn() > 0) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
            }
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'update', 'ai_settings', null, 'إعدادات الذكاء الاصطناعي',
                [
                    'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($before, $settings),
                    'undo_policy' => 'settings_batch_restore_not_enabled',
                ]
            );
            $db->commit();
            $_SESSION['success_message'] = "تم حفظ إعدادات الذكاء الاصطناعي بنجاح";
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('AI settings save error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حفظ إعدادات الذكاء الاصطناعي.';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    if ($action === 'test_provider') {
        $provider = $_POST['test_provider'] ?? 'ollama';
        if (!in_array($provider, ['gemini', 'ollama'])) {
            $provider = 'ollama';
        }
        $testResult = AIProvider::testProvider($db, $provider);
        // Store in session to show after redirect
        $_SESSION['test_result'] = $testResult;
        $_SESSION['test_provider'] = $provider;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Retrieve test result from session
$testResult = $_SESSION['test_result'] ?? null;
$testProvider = $_SESSION['test_provider'] ?? null;
unset($_SESSION['test_result'], $_SESSION['test_provider']);

// --- Load current settings ---
$currentSettings = [];
$settingKeys = ['ai_provider', 'ollama_model'];
$placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
$stmt->execute($settingKeys);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

$aiProvider = $currentSettings['ai_provider'] ?? (defined('AI_DEFAULT_PROVIDER') ? AI_DEFAULT_PROVIDER : 'auto');
$ollamaModel = $currentSettings['ollama_model'] ?? (defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'gemma3:4b');

// --- Check providers status ---
$providerStatus = AIProvider::checkStatus($db);

// --- API usage stats ---
$apiStats = ['gemini_calls' => 0, 'ollama_calls' => 0, 'gemini_tokens' => 0, 'ollama_tokens' => 0];
try {
    $stats = $db->query("SELECT 
        api_type,
        COUNT(*) as calls,
        COALESCE(SUM(tokens_used), 0) as tokens,
        COALESCE(ROUND(AVG(response_time_ms)), 0) as avg_time
        FROM ai_api_logs 
        GROUP BY api_type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stats as $s) {
        $key = $s['api_type'];
        $apiStats[$key . '_calls'] = $s['calls'];
        $apiStats[$key . '_tokens'] = $s['tokens'];
        $apiStats[$key . '_avg_time'] = $s['avg_time'];
    }
} catch (PDOException $e) {
    // جدول قد لا يكون موجوداً
}

require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="fas fa-brain me-2 text-primary"></i>إعدادات الذكاء الاصطناعي</h1>
        <small class="text-muted">إدارة مزوّدي AI — Gemini (سحابي) + Ollama/Gemma (محلي)</small>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Test Result -->
<?php if ($testResult): ?>
<div class="alert alert-<?= $testResult['success'] ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <h6 class="alert-heading mb-1">
        <i class="fas fa-<?= $testResult['success'] ? 'check-circle' : 'times-circle' ?> me-2"></i>
        نتيجة اختبار <?= $testProvider === 'gemini' ? 'Gemini' : 'Ollama' ?>
    </h6>
    <?php if ($testResult['success']): ?>
    <p class="mb-1"><strong>الاستجابة:</strong> <?= htmlspecialchars($testResult['response']) ?></p>
    <small>
        <i class="fas fa-clock me-1"></i>الوقت: <?= number_format($testResult['time_ms']) ?> مللي ثانية
        <?php if ($testResult['tokens']): ?> | <i class="fas fa-coins me-1"></i>الرموز: <?= number_format($testResult['tokens']) ?><?php endif; ?>
        <?php if ($testResult['did_fallback']): ?> | <span class="badge bg-warning text-dark">تم التحويل للاحتياطي</span><?php endif; ?>
    </small>
    <?php else: ?>
    <p class="mb-0"><strong>الخطأ:</strong> <?= htmlspecialchars($testResult['error']) ?></p>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<style>
.provider-card{border-radius:16px;border:2px solid #e5e7eb;transition:all .3s;cursor:pointer}
.provider-card:hover{border-color:#3b82f6;box-shadow:0 4px 15px rgba(59,130,246,.15)}
.provider-card.active-provider{border-color:#3b82f6;background:linear-gradient(135deg,#eff6ff,#dbeafe)}
.provider-card .status-dot{width:12px;height:12px;border-radius:50%;display:inline-block}
.provider-card .status-dot.online{background:#10b981;box-shadow:0 0 8px rgba(16,185,129,.5)}
.provider-card .status-dot.offline{background:#ef4444;box-shadow:0 0 8px rgba(239,68,68,.5)}
</style>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-cloud"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)($apiStats['gemini_calls'] ?? 0) ?>">0</div>
                <div class="stat-card-label">طلبات Gemini</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-server"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)($apiStats['ollama_calls'] ?? 0) ?>">0</div>
                <div class="stat-card-label">طلبات Ollama</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-coins"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)($apiStats['gemini_tokens'] ?? 0) ?>">0</div>
                <div class="stat-card-label">رموز Gemini</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-coins"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)($apiStats['ollama_tokens'] ?? 0) ?>">0</div>
                <div class="stat-card-label">رموز Ollama</div>
            </div>
        </div>
    </div>
</div>

<!-- Provider Status + Settings -->
<div class="row g-4 mb-4">
    <!-- Gemini Status -->
    <div class="col-md-6">
        <div class="card shadow provider-card <?= $aiProvider === 'gemini' ? 'active-provider' : '' ?>">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fab fa-google me-2"></i>Google Gemini <small class="opacity-75">(سحابي)</small></h5>
                <span class="status-dot <?= $providerStatus['gemini']['available'] ? 'online' : 'offline' ?>"></span>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr>
                        <td class="fw-bold" width="130"><i class="fas fa-circle-check text-<?= $providerStatus['gemini']['available'] ? 'success' : 'danger' ?> me-1"></i>الحالة</td>
                        <td><?= $providerStatus['gemini']['available'] ? '<span class="badge bg-success">متصل</span>' : '<span class="badge bg-danger">غير متصل</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold"><i class="fas fa-key text-warning me-1"></i>مفتاح API</td>
                        <td><?= $providerStatus['gemini']['hasKey'] ? '<span class="badge bg-success">موجود</span>' : '<span class="badge bg-danger">غير معد</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold"><i class="fas fa-microchip text-info me-1"></i>النموذج</td>
                        <td><code><?= htmlspecialchars($providerStatus['gemini']['model']) ?></code></td>
                    </tr>
                    <tr>
                        <td class="fw-bold"><i class="fas fa-tachometer-alt text-primary me-1"></i>متوسط الوقت</td>
                        <td><?= number_format($apiStats['gemini_avg_time'] ?? 0) ?> مللي ثانية</td>
                    </tr>
                </table>
                <div class="d-flex gap-2">
<form method="post" class="d-inline">
    <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="test_provider">
                        <input type="hidden" name="test_provider" value="gemini">
                        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-vial me-1"></i>اختبار الاتصال</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Ollama Status -->
    <div class="col-md-6">
        <div class="card shadow provider-card <?= $aiProvider === 'ollama' ? 'active-provider' : '' ?>">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-server me-2"></i>Ollama / Gemma 3 <small class="opacity-75">(محلي)</small></h5>
                <span class="status-dot <?= $providerStatus['ollama']['available'] ? 'online' : 'offline' ?>"></span>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr>
                        <td class="fw-bold" width="130"><i class="fas fa-circle-check text-<?= $providerStatus['ollama']['available'] ? 'success' : 'danger' ?> me-1"></i>الحالة</td>
                        <td>
                            <?= $providerStatus['ollama']['available'] 
                                ? '<span class="badge bg-success">يعمل</span>' 
                                : '<span class="badge bg-danger">غير متاح</span> <small class="text-muted">— تأكد من تشغيل Ollama</small>' ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold"><i class="fas fa-microchip text-info me-1"></i>النموذج</td>
                        <td><code><?= htmlspecialchars($ollamaModel) ?></code></td>
                    </tr>
                    <tr>
                        <td class="fw-bold"><i class="fas fa-database text-purple me-1"></i>النماذج المثبتة</td>
                        <td>
                            <?php if (!empty($providerStatus['ollama']['models'])): ?>
                                <?php foreach ($providerStatus['ollama']['models'] as $m): ?>
                                <span class="badge bg-info me-1"><?= htmlspecialchars($m['name']) ?> (<?= $m['size'] ?>)</span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold"><i class="fas fa-tachometer-alt text-primary me-1"></i>متوسط الوقت</td>
                        <td><?= number_format($apiStats['ollama_avg_time'] ?? 0) ?> مللي ثانية</td>
                    </tr>
                </table>
                <div class="d-flex gap-2">
<form method="post" class="d-inline">
    <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="test_provider">
                        <input type="hidden" name="test_provider" value="ollama">
                        <button type="submit" class="btn btn-outline-primary btn-sm" <?= $providerStatus['ollama']['available'] ? '' : 'disabled' ?>>
                            <i class="fas fa-vial me-1"></i>اختبار الاتصال
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Settings Form -->
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-cog me-2"></i>إعدادات المزوّد</h5>
    </div>
    <div class="card-body">
<form method="post" action="ai_settings.php">
    <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_settings">
            
            <div class="row g-3">
                <!-- Provider Selection -->
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="fas fa-exchange-alt text-primary me-1"></i>المزوّد الافتراضي</label>
                    <select name="ai_provider" class="form-select" id="providerSelect">
                        <option value="auto" <?= $aiProvider === 'auto' ? 'selected' : '' ?>>
                            🔄 تلقائي — Gemini أولاً، ثم Ollama عند الفشل
                        </option>
                        <option value="gemini" <?= $aiProvider === 'gemini' ? 'selected' : '' ?>>
                            ☁️ Gemini فقط — سحابي (أقوى، يحتاج إنترنت)
                        </option>
                        <option value="ollama" <?= $aiProvider === 'ollama' ? 'selected' : '' ?>>
                            🖥️ Ollama فقط — محلي (مجاني، بدون إنترنت)
                        </option>
                    </select>
                    <div class="form-text" id="providerHelp"></div>
                </div>
                
                <!-- Ollama Model -->
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="fas fa-microchip text-info me-1"></i>نموذج Ollama</label>
                    <?php if (!empty($providerStatus['ollama']['models'])): ?>
                    <select name="ollama_model" class="form-select">
                        <?php foreach ($providerStatus['ollama']['models'] as $m): ?>
                        <option value="<?= htmlspecialchars($m['name']) ?>" <?= $ollamaModel === $m['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['name']) ?> (<?= $m['size'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" name="ollama_model" class="form-control" value="<?= htmlspecialchars($ollamaModel) ?>" placeholder="gemma3:4b">
                    <?php endif; ?>
                    <div class="form-text">النموذج المثبت على Ollama — يمكنك تنزيل نماذج إضافية عبر: <code>ollama pull model_name</code></div>
                </div>
            </div>
            
            <!-- Info Box -->
            <div class="alert alert-info mt-3 mb-3">
                <div class="row">
                    <div class="col-md-4">
                        <h6 class="fw-bold"><i class="fab fa-google me-1"></i>Gemini (سحابي)</h6>
                        <ul class="mb-0 small">
                            <li>أقوى وأدق في التوليد</li>
                            <li>يحتاج إنترنت ومفتاح API</li>
                            <li>حدود يومية (<?= defined('GEMINI_DAILY_LIMIT') ? GEMINI_DAILY_LIMIT : 100 ?> طلب/معلم)</li>
                            <li>يدعم الصور وPDF</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6 class="fw-bold"><i class="fas fa-server me-1"></i>Ollama (محلي)</h6>
                        <ul class="mb-0 small">
                            <li>مجاني بالكامل بلا حدود</li>
                            <li>يعمل بدون إنترنت</li>
                            <li>الخصوصية: البيانات لا تغادر الجهاز</li>
                            <li>يدعم الصور (Gemma 3)</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6 class="fw-bold"><i class="fas fa-sync-alt me-1"></i>تلقائي (موصى به)</h6>
                        <ul class="mb-0 small">
                            <li>يستخدم Gemini كأساسي</li>
                            <li>يتحول لـ Ollama عند فشل Gemini</li>
                            <li>أفضل توازن بين الجودة والتوفر</li>
                            <li>لا انقطاع في الخدمة</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="text-end">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-save me-1"></i>حفظ الإعدادات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Comparison Table -->
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-balance-scale me-2"></i>مقارنة المزوّدين</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>الميزة</th>
                        <th class="text-center"><i class="fab fa-google me-1"></i>Gemini</th>
                        <th class="text-center"><i class="fas fa-server me-1"></i>Ollama/Gemma 3</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-bold">التكلفة</td>
                        <td class="text-center"><span class="badge bg-warning text-dark">مفتاح API مجاني/مدفوع</span></td>
                        <td class="text-center"><span class="badge bg-success">مجاني تماماً</span></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">الإنترنت</td>
                        <td class="text-center"><span class="badge bg-danger">مطلوب</span></td>
                        <td class="text-center"><span class="badge bg-success">غير مطلوب</span></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">حدود الاستخدام</td>
                        <td class="text-center"><?= defined('GEMINI_DAILY_LIMIT') ? GEMINI_DAILY_LIMIT : 100 ?> طلب/يوم/معلم</td>
                        <td class="text-center"><span class="badge bg-success">بلا حدود</span></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">جودة التوليد</td>
                        <td class="text-center"><span class="badge bg-success">ممتازة</span></td>
                        <td class="text-center"><span class="badge bg-info">جيدة</span></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">سرعة الاستجابة</td>
                        <td class="text-center">~2-5 ثوانٍ</td>
                        <td class="text-center">~5-15 ثانية (حسب الجهاز)</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">تحضير الدروس</td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">توليد الاختبارات</td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">تحليل الصور</td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                        <td class="text-center"><i class="fas fa-check text-success"></i> (Gemma 3)</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">توليد الصور</td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">تحليل PDF</td>
                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">شات بوت/محادثة</td>
                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                        <td class="text-center"><i class="fas fa-check text-success"></i> (مجاني)</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">خصوصية البيانات</td>
                        <td class="text-center">تُرسل لـ Google</td>
                        <td class="text-center"><span class="badge bg-success">تبقى على الجهاز</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('providerSelect').addEventListener('change', function() {
    var help = document.getElementById('providerHelp');
    switch (this.value) {
        case 'auto':
            help.innerHTML = '<i class="fas fa-info-circle text-info me-1"></i>يستخدم Gemini أساسياً ويتحول تلقائياً لـ Ollama عند تجاوز الحد اليومي أو انقطاع الإنترنت.';
            break;
        case 'gemini':
            help.innerHTML = '<i class="fas fa-exclamation-triangle text-warning me-1"></i>يعتمد فقط على Gemini السحابي. لن يعمل بدون إنترنت أو عند تجاوز الحد اليومي.';
            break;
        case 'ollama':
            help.innerHTML = '<i class="fas fa-info-circle text-success me-1"></i>يعتمد فقط على النموذج المحلي. مجاني وبدون حدود، لكن توليد الصور وتحليل PDF لن يكونا متاحين.';
            break;
    }
});
document.getElementById('providerSelect').dispatchEvent(new Event('change'));
</script>

<?php require_once '../includes/admin_footer.php'; ?>
