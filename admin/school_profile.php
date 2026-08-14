<?php
$page_title = "بيانات المدرسة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/FileUploadGuard.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$success_message = $_SESSION['school_profile_success'] ?? '';
$error_message = $_SESSION['school_profile_error'] ?? '';
unset($_SESSION['school_profile_success'], $_SESSION['school_profile_error']);

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    $providedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
        $_SESSION['school_profile_error'] = 'خطأ في التحقق من الأمان. يرجى إعادة المحاولة.';
        header('Location: school_profile.php');
        exit();
    }

    $newLogoPath = null;
    $oldLogoPath = null;
    try {
        $targetSection = is_string($_POST['target_section'] ?? null) ? trim($_POST['target_section']) : '';
        $allowedSections = ['', 'basic', 'services', 'directors', 'other'];
        if (!in_array($targetSection, $allowedSections, true)) {
            throw new InvalidArgumentException('Invalid school profile section.');
        }

        $builtinKeys = [
            'school_name', 'educational_directorate', 'educational_administration',
            'kg_director', 'primary_director', 'prep_sec_director',
            'student_affairs_officer', 'transport_movement_officer',
            'general_secretary', 'accounts_manager',
            'school_director', 'admin_director', 'financial_director', 'ceo',
            'school_name_en', 'educational_directorate_en', 'educational_administration_en',
            'kg_director_en', 'primary_director_en', 'prep_sec_director_en',
            'school_director_en', 'admin_director_en', 'financial_director_en', 'ceo_en',
            'student_affairs_officer_en', 'transport_movement_officer_en', 'general_secretary_en', 'accounts_manager_en'
        ];
        $settingChanges = [];
        foreach ($builtinKeys as $k) {
            if (isset($_POST[$k])) {
                $val = trim((string)$_POST[$k]);
                if (mb_strlen($val, 'UTF-8') > 1000) {
                    throw new InvalidArgumentException('School profile value is too long.');
                }
                $settingChanges[$k] = $val;
            }
        }

        if ($targetSection !== '') {
            $customInputKeys = ['custom_field_name', 'custom_field_name_en', 'custom_field_value', 'custom_field_value_en'];
            foreach ($customInputKeys as $inputKey) {
                if (isset($_POST[$inputKey]) && !is_array($_POST[$inputKey])) {
                    throw new InvalidArgumentException('Invalid custom field payload.');
                }
            }

            $customNames = $_POST['custom_field_name'] ?? [];
            $customNamesEn = $_POST['custom_field_name_en'] ?? [];
            $customValues = $_POST['custom_field_value'] ?? [];
            $customValuesEn = $_POST['custom_field_value_en'] ?? [];
            if (count($customNames) > 50) {
                throw new InvalidArgumentException('Too many custom fields.');
            }

            $oldCustomStmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'custom_school_fields'");
            $oldJson = $oldCustomStmt ? $oldCustomStmt->fetchColumn() : '';
            $allCustom = json_decode($oldJson ?: '[]', true);
            if (!is_array($allCustom)) { $allCustom = []; }

            $newCustomList = [];
            foreach ($allCustom as $customField) {
                if (!is_array($customField)) {
                    continue;
                }
                $savedSection = (string) ($customField['section'] ?? 'other');
                if (!in_array($savedSection, array_slice($allowedSections, 1), true)) {
                    $savedSection = 'other';
                }
                if ($savedSection !== $targetSection) {
                    $customField['section'] = $savedSection;
                    $newCustomList[] = $customField;
                }
            }

            foreach ($customNames as $idx => $customName) {
                $customName = trim((string) $customName);
                $customNameEn = trim((string) ($customNamesEn[$idx] ?? ''));
                $customValue = trim((string) ($customValues[$idx] ?? ''));
                $customValueEn = trim((string) ($customValuesEn[$idx] ?? ''));
                if (mb_strlen($customName, 'UTF-8') > 150 || mb_strlen($customNameEn, 'UTF-8') > 150
                    || mb_strlen($customValue, 'UTF-8') > 1000 || mb_strlen($customValueEn, 'UTF-8') > 1000) {
                    throw new InvalidArgumentException('Custom school profile value is too long.');
                }
                if ($customName !== '') {
                    $newCustomList[] = [
                        'section' => $targetSection,
                        'name' => $customName,
                        'name_en' => $customNameEn,
                        'value' => $customValue,
                        'value_en' => $customValueEn,
                    ];
                }
            }

            $settingChanges['custom_school_fields'] = json_encode($newCustomList, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $logoDir = __DIR__ . '/../uploads/';
        $removeLogo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';
        $logoUpload = $_FILES['school_logo'] ?? null;
        $hasLogoUpload = is_array($logoUpload)
            && (int) ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($removeLogo) {
            $settingChanges['school_logo'] = '';
        } elseif ($hasLogoUpload) {
            $validatedLogo = FileUploadGuard::validate($_FILES['school_logo'], [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
                'gif' => ['image/gif'],
            ], 2 * 1024 * 1024);
            $imgExt = $validatedLogo['extension'];
            $logoName = FileUploadGuard::randomFileName('school_logo', $imgExt);
            if (!is_dir($logoDir)) {
                if (!mkdir($logoDir, 0775, true) && !is_dir($logoDir)) {
                    throw new RuntimeException('Logo storage is unavailable.');
                }
            }
            $newLogoPath = $logoDir . $logoName;
            if (!move_uploaded_file($validatedLogo['tmp_name'], $newLogoPath)) {
                throw new RuntimeException('Logo upload failed.');
            }
            $settingChanges['school_logo'] = $logoName;
        }

        $db->beginTransaction();
        $auditService = new \EduCore\Modules\Operations\Audit\AuditService($db);
        $batchId = UndoManager::newBatchId();
        foreach ($settingChanges as $settingKey => $settingValue) {
            $beforeStmt = $db->prepare('SELECT * FROM settings WHERE setting_key = ? FOR UPDATE');
            $beforeStmt->execute([$settingKey]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($settingKey === 'school_logo' && $before && (string) ($before['setting_value'] ?? '') !== '') {
                $oldLogoPath = $logoDir . basename((string) $before['setting_value']);
            }

            if ($before && (string) ($before['setting_value'] ?? '') === $settingValue) {
                continue;
            }
            if ($before) {
                $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute([$settingValue, $before['id']]);
                $afterStmt = $db->prepare('SELECT * FROM settings WHERE id = ?');
                $afterStmt->execute([$before['id']]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditService->recordUpdate('setting', 'settings', $before['id'], $settingKey, $before, $after, 'تحديث إعداد المدرسة: ' . $settingKey, $batchId);
            } else {
                $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$settingKey, $settingValue]);
                $settingId = (int) $db->lastInsertId();
                $afterStmt = $db->prepare('SELECT * FROM settings WHERE id = ?');
                $afterStmt->execute([$settingId]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditService->recordInsert('setting', 'settings', $settingId, $settingKey, $after, 'إضافة إعداد المدرسة: ' . $settingKey, $batchId);
            }
        }

        $db->commit();
        if ($oldLogoPath !== null && is_file($oldLogoPath) && $oldLogoPath !== $newLogoPath) {
            @unlink($oldLogoPath);
        }
        $_SESSION['school_profile_success'] = 'تم حفظ التعديلات بنجاح.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($newLogoPath !== null && is_file($newLogoPath)) {
            @unlink($newLogoPath);
        }
        error_log('School profile update failed: ' . $e->getMessage());
        $_SESSION['school_profile_error'] = 'تعذر حفظ بيانات المدرسة. تحقق من المدخلات ثم أعد المحاولة.';
    }

    header('Location: school_profile.php');
    exit();
}

$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$savedCustomFields = json_decode($settings['custom_school_fields'] ?? '[]', true);
$customBySection = [
    'basic' => [],
    'services' => [],
    'directors' => [],
    'other' => []
];
if (is_array($savedCustomFields)) {
    foreach ($savedCustomFields as $cf) {
        if (!is_array($cf)) {
            continue;
        }
        $sec = $cf['section'] ?? 'other';
        if (!isset($customBySection[$sec])) { $sec = 'other'; }
        $customBySection[$sec][] = [
            'section' => $sec,
            'name' => (string) ($cf['name'] ?? ''),
            'name_en' => (string) ($cf['name_en'] ?? ''),
            'value' => (string) ($cf['value'] ?? ''),
            'value_en' => (string) ($cf['value_en'] ?? ''),
        ];
    }
}

require_once '../includes/admin_header.php';
?>

<style>
.logo-container {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
}
.logo-preview {
    width: 160px;
    height: 160px;
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
}
.logo-preview:hover {
    border-color: #0d6efd;
    background: #f1f5f9;
}
.logo-preview img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
}
.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
}
.form-section {
    background: #ffffff;
    border-radius: 12px;
}
.info-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    transition: all 0.2s ease;
}
.info-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}
.logo-card-relative {
    position: relative !important;
    overflow: hidden !important;
}
.logo-upload-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.96);
    z-index: 100;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    animation: logoFadeIn 0.2s ease-in-out;
}
@keyframes logoFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-school me-2 text-primary"></i>بيانات المدرسة</h1>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
            <!-- قسم شعار المدرسة والتحكم به -->
            <div class="col-lg-3 col-md-4">
                <form method="POST" enctype="multipart/form-data" id="logoForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="remove_logo" id="removeLogoInput" value="0">
                    <div class="card border-0 shadow-sm h-100 logo-card-relative" id="logoCard">
                        <div class="card-body p-4 text-center d-flex flex-column align-items-center justify-content-center">
                            <label class="form-label fw-bold d-block mb-3 text-secondary">
                                <i class="fas fa-image me-1 text-primary"></i>شعار المدرسة
                            </label>
                            
                            <?php
                            $logoPath = $settings['school_logo'] ?? '';
                            $hasCustomLogo = ($logoPath && file_exists(__DIR__ . '/../uploads/' . basename((string) $logoPath)));
                            ?>
                            <div class="logo-preview mx-auto mb-3" onclick="document.getElementById('logoInput').click()" title="انقر لتغيير الشعار">
                                <img src="<?php echo get_school_logo('../'); ?>" alt="شعار المدرسة" id="logoPreviewImg">
                            </div>

                            <div class="w-100 px-3 d-none mb-3" id="logoProgressWrapper">
                                <div class="progress shadow-sm" style="height: 8px; border-radius: 4px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="logoProgressBar" role="progressbar" style="width: 0%;"></div>
                                </div>
                                <small class="text-primary d-block mt-1 fw-bold fs-7" id="logoProgressText">جاري الرفع... 0%</small>
                            </div>

                            <input type="file" id="logoInput" name="school_logo" class="d-none" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" onchange="uploadLogoWithProgress(this);">
                            
                            <div class="d-flex justify-content-center gap-2 mb-2 w-100 flex-wrap" id="logoActionButtons">
                                <button type="button" class="btn btn-sm btn-profile-basic px-3 fw-semibold shadow-sm" onclick="document.getElementById('logoInput').click()">
                                    <i class="fas fa-upload me-1"></i>تغيير
                                </button>
                                <?php if ($hasCustomLogo): ?>
                                    <button type="button" class="btn btn-sm btn-profile-danger px-3 fw-semibold shadow-sm" id="removeLogoBtn" onclick="removeSchoolLogo()">
                                        <i class="fas fa-trash-alt me-1"></i>حذف
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <small class="text-muted fs-7 mt-1">الحد الأقصى: 2 ميجابايت (PNG, JPG, WEBP)</small>
                        </div>
                    </div>
                </form>
            </div>

            <!-- عرض أقسام البيانات الثابتة وتوفير زر إدارة لكل قسم -->
            <div class="col-lg-9 col-md-8">

                <!-- Section 1: البيانات الأساسية -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center border-bottom py-3 px-4">
                        <h5 class="section-title mb-0 fw-bold" style="color: #0284c7;"><i class="fas fa-landmark me-2"></i>البيانات الأساسية</h5>
                        <button type="button" class="btn btn-sm btn-profile-basic shadow-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal_section_basic">
                            <i class="fas fa-edit me-1"></i>تعديل وإدارة القسم
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-4">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-school me-1" style="color: #0284c7;"></i>اسم المدرسة</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['school_name'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['school_name_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['school_name_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-building-columns me-1" style="color: #0284c7;"></i>المديرية التعليمية</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['educational_directorate'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['educational_directorate_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['educational_directorate_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-map-marker-alt me-1" style="color: #0284c7;"></i>الإدارة التعليمية</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['educational_administration'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['educational_administration_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['educational_administration_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php foreach ($customBySection['basic'] as $cf): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="info-card bg-light border-0 shadow-none">
                                        <span class="text-secondary small fw-bold d-block">
                                            <i class="fas fa-tag me-1" style="color: #0284c7;"></i>
                                            <?php echo htmlspecialchars($cf['name']); ?>
                                            <?php if (!empty($cf['name_en'])): ?> (<?php echo htmlspecialchars($cf['name_en']); ?>)<?php endif; ?>
                                        </span>
                                        <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($cf['value'] ?: 'غير محدد'); ?></span>
                                        <?php if (!empty($cf['value_en'])): ?>
                                            <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($cf['value_en']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Section 2: الإداريين -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center border-bottom py-3 px-4">
                        <h5 class="section-title mb-0 fw-bold" style="color: #15803d;"><i class="fas fa-user-shield me-2"></i>الإداريين</h5>
                        <button type="button" class="btn btn-sm btn-profile-services shadow-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal_section_services">
                            <i class="fas fa-edit me-1"></i>تعديل وإدارة القسم
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-user-graduate me-1" style="color: #15803d;"></i>مسؤول شؤون الطلاب</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['student_affairs_officer'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['student_affairs_officer_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['student_affairs_officer_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-bus me-1" style="color: #15803d;"></i>مسؤول الحركة والتنقلات</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['transport_movement_officer'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['transport_movement_officer_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['transport_movement_officer_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-file-signature me-1" style="color: #15803d;"></i>سكرتير عام الإدارة</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['general_secretary'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['general_secretary_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['general_secretary_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-wallet me-1" style="color: #15803d;"></i>مدير الحسابات</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['accounts_manager'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['accounts_manager_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['accounts_manager_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php foreach ($customBySection['services'] as $cf): ?>
                                <div class="col-md-6 col-lg-3">
                                    <div class="info-card bg-light border-0 shadow-none">
                                        <span class="text-secondary small fw-bold d-block">
                                            <i class="fas fa-tag me-1" style="color: #15803d;"></i>
                                            <?php echo htmlspecialchars($cf['name']); ?>
                                            <?php if (!empty($cf['name_en'])): ?> (<?php echo htmlspecialchars($cf['name_en']); ?>)<?php endif; ?>
                                        </span>
                                        <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($cf['value'] ?: 'غير محدد'); ?></span>
                                        <?php if (!empty($cf['value_en'])): ?>
                                            <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($cf['value_en']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Section 3: المديرون والقيادات الإدارية -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center border-bottom py-3 px-4">
                        <h5 class="section-title mb-0 fw-bold" style="color: #7e22ce;"><i class="fas fa-user-tie me-2"></i>المديرون والقيادات الإدارية</h5>
                        <button type="button" class="btn btn-sm btn-profile-directors shadow-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal_section_directors">
                            <i class="fas fa-edit me-1"></i>تعديل وإدارة القسم
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-child me-1" style="color: #7e22ce;"></i>مدير مرحلة رياض الأطفال</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['kg_director'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['kg_director_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['kg_director_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-book-reader me-1" style="color: #7e22ce;"></i>مدير المرحلة الإبتدائية</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['primary_director'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['primary_director_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['primary_director_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-user-graduate me-1" style="color: #7e22ce;"></i>مدير المرحلة الإعدادية والثانوية</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['prep_sec_director'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['prep_sec_director_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['prep_sec_director_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-briefcase me-1" style="color: #7e22ce;"></i>المدير الإداري</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['admin_director'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['admin_director_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['admin_director_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-calculator me-1" style="color: #7e22ce;"></i>المدير المالي</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['financial_director'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['financial_director_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['financial_director_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-user-tie me-1" style="color: #7e22ce;"></i>مدير المدرسة</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['school_director'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['school_director_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['school_director_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="info-card bg-light border-0 shadow-none">
                                    <span class="text-secondary small fw-bold d-block"><i class="fas fa-user-astronaut me-1" style="color: #7e22ce;"></i>الرئيس التنفيذي (CEO)</span>
                                    <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($settings['ceo'] ?? 'غير محدد'); ?></span>
                                    <?php if (!empty($settings['ceo_en'])): ?>
                                        <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($settings['ceo_en']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php foreach ($customBySection['directors'] as $cf): ?>
                                <div class="col-md-6 col-lg-3">
                                    <div class="info-card bg-light border-0 shadow-none">
                                        <span class="text-secondary small fw-bold d-block">
                                            <i class="fas fa-tag me-1" style="color: #7e22ce;"></i>
                                            <?php echo htmlspecialchars($cf['name']); ?>
                                            <?php if (!empty($cf['name_en'])): ?> (<?php echo htmlspecialchars($cf['name_en']); ?>)<?php endif; ?>
                                        </span>
                                        <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($cf['value'] ?: 'غير محدد'); ?></span>
                                        <?php if (!empty($cf['value_en'])): ?>
                                            <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($cf['value_en']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Section 4: بيانات وحقول إضافية مخصصة -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center border-bottom py-3 px-4">
                        <h5 class="section-title mb-0 fw-bold" style="color: #4f46e5;"><i class="fas fa-plus-circle me-2"></i>بيانات وحقول إضافية مخصصة</h5>
                        <button type="button" class="btn btn-sm btn-profile-other shadow-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal_section_other">
                            <i class="fas fa-edit me-1"></i>تعديل وإدارة القسم
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php if (empty($customBySection['other'])): ?>
                                <div class="col-12 text-muted small text-center py-2">لا توجد بيانات إضافية عامة مضافة حالياً. يمكنك التعديل والإضافة من خلال زر القسم.</div>
                            <?php else: ?>
                                <?php foreach ($customBySection['other'] as $cf): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="info-card bg-light border-0 shadow-none">
                                            <span class="text-secondary small fw-bold d-block">
                                                <i class="fas fa-tag me-1" style="color: #4f46e5;"></i>
                                                <?php echo htmlspecialchars($cf['name']); ?>
                                                <?php if (!empty($cf['name_en'])): ?> (<?php echo htmlspecialchars($cf['name_en']); ?>)<?php endif; ?>
                                            </span>
                                            <span class="fw-bold text-dark fs-6 d-block mt-1"><?php echo htmlspecialchars($cf['value'] ?: 'غير محدد'); ?></span>
                                            <?php if (!empty($cf['value_en'])): ?>
                                                <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($cf['value_en']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

<!-- ================================================================= -->
<!-- MODAL SECTION 1: البيانات الأساسية -->
<!-- ================================================================= -->
<div class="modal fade" id="modal_section_basic" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="target_section" value="basic">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-landmark me-2"></i>تعديل وإدارة البيانات الأساسية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold text-secondary mb-3 border-bottom pb-2"><i class="fas fa-lock me-1"></i>البيانات الثابتة بالنظام (لا يمكن حذفها)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم المدرسة <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="school_name" value="<?php echo htmlspecialchars($settings['school_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">School Name</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="school_name_en" value="<?php echo htmlspecialchars($settings['school_name_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">المديرية التعليمية</label>
                            <input type="text" class="form-control" name="educational_directorate" value="<?php echo htmlspecialchars($settings['educational_directorate'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Educational Directorate</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="educational_directorate_en" value="<?php echo htmlspecialchars($settings['educational_directorate_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الإدارة التعليمية</label>
                            <input type="text" class="form-control" name="educational_administration" value="<?php echo htmlspecialchars($settings['educational_administration'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Educational Administration</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="educational_administration_en" value="<?php echo htmlspecialchars($settings['educational_administration_en'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                        <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-plus-circle me-1"></i>بيانات مخصصة إضافية للقسم</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addCustomFieldToModal('modal_custom_container_basic', 'basic');">
                            <i class="fas fa-plus me-1"></i>إضافة بيان جديد
                        </button>
                    </div>
                    <div id="modal_custom_container_basic" class="row g-3">
                        <?php foreach ($customBySection['basic'] as $cf): ?>
                            <div class="col-12 modal-custom-item mb-3">
                                <div class="p-3 border rounded bg-light position-relative">
                                    <button type="button" class="btn btn-sm btn-danger position-absolute" style="top: 10px; left: 10px; z-index: 10;" onclick="this.closest('.modal-custom-item').remove();" title="حذف هذا البيان">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <div class="row g-3">
                                        <!-- الجانب الأيمن: البيانات باللغة العربية -->
                                        <div class="col-md-6 border-start">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block text-end">البيانات باللغة العربية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name[]" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($cf['name']); ?>" placeholder="اسم البيان بالعربية" required>
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['value']); ?>" placeholder="القيمة بالعربية">
                                            </div>
                                        </div>
                                        <!-- الجانب الأيسر: البيانات باللغة الإنجليزية -->
                                        <div class="col-md-6">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block" style="text-align: left;" dir="ltr">البيانات باللغة الإنجليزية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name_en[]" class="form-control form-control-sm fw-bold" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['name_en'] ?? ''); ?>" placeholder="اسم البيان بالإنجليزية">
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value_en[]" class="form-control form-control-sm" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['value_en'] ?? ''); ?>" placeholder="القيمة بالإنجليزية">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL SECTION 2: الإداريين -->
<!-- ================================================================= -->
<div class="modal fade" id="modal_section_services" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="target_section" value="services">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-shield me-2"></i>تعديل وإدارة الإداريين</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold text-secondary mb-3 border-bottom pb-2"><i class="fas fa-lock me-1"></i>البيانات الثابتة بالنظام (لا يمكن حذفها)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مسؤول شؤون الطلاب</label>
                            <input type="text" class="form-control" name="student_affairs_officer" value="<?php echo htmlspecialchars($settings['student_affairs_officer'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Student Affairs Officer</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="student_affairs_officer_en" value="<?php echo htmlspecialchars($settings['student_affairs_officer_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مسؤول الحركة والتنقلات</label>
                            <input type="text" class="form-control" name="transport_movement_officer" value="<?php echo htmlspecialchars($settings['transport_movement_officer'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Transport Movement Officer</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="transport_movement_officer_en" value="<?php echo htmlspecialchars($settings['transport_movement_officer_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">سكرتير عام الإدارة</label>
                            <input type="text" class="form-control" name="general_secretary" value="<?php echo htmlspecialchars($settings['general_secretary'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">General Secretary</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="general_secretary_en" value="<?php echo htmlspecialchars($settings['general_secretary_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مدير الحسابات</label>
                            <input type="text" class="form-control" name="accounts_manager" value="<?php echo htmlspecialchars($settings['accounts_manager'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Accounts Manager</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="accounts_manager_en" value="<?php echo htmlspecialchars($settings['accounts_manager_en'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                        <h6 class="fw-bold mb-0 text-success"><i class="fas fa-plus-circle me-1"></i>بيانات مخصصة إضافية للقسم</h6>
                        <button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="addCustomFieldToModal('modal_custom_container_services', 'services');">
                            <i class="fas fa-plus me-1"></i>إضافة بيان جديد
                        </button>
                    </div>
                    <div id="modal_custom_container_services" class="row g-3">
                        <?php foreach ($customBySection['services'] as $cf): ?>
                            <div class="col-12 modal-custom-item mb-3">
                                <div class="p-3 border rounded bg-light position-relative">
                                    <button type="button" class="btn btn-sm btn-danger position-absolute" style="top: 10px; left: 10px; z-index: 10;" onclick="this.closest('.modal-custom-item').remove();" title="حذف هذا البيان">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <div class="row g-3">
                                        <!-- الجانب الأيمن: البيانات باللغة العربية -->
                                        <div class="col-md-6 border-start">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block text-end">البيانات باللغة العربية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name[]" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($cf['name']); ?>" placeholder="اسم البيان بالعربية" required>
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['value']); ?>" placeholder="القيمة بالعربية">
                                            </div>
                                        </div>
                                        <!-- الجانب الأيسر: البيانات باللغة الإنجليزية -->
                                        <div class="col-md-6">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block" style="text-align: left;" dir="ltr">البيانات باللغة الإنجليزية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name_en[]" class="form-control form-control-sm fw-bold" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['name_en'] ?? ''); ?>" placeholder="اسم البيان بالإنجليزية">
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value_en[]" class="form-control form-control-sm" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['value_en'] ?? ''); ?>" placeholder="القيمة بالإنجليزية">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL SECTION 3: المديرون والقيادات الإدارية -->
<!-- ================================================================= -->
<div class="modal fade" id="modal_section_directors" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="target_section" value="directors">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-tie me-2"></i>تعديل وإدارة المديرون والقيادات الإدارية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold text-secondary mb-3 border-bottom pb-2"><i class="fas fa-lock me-1"></i>البيانات الثابتة بالنظام (لا يمكن حذفها)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مدير مرحلة رياض الأطفال</label>
                            <input type="text" class="form-control" name="kg_director" value="<?php echo htmlspecialchars($settings['kg_director'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">KG Director</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="kg_director_en" value="<?php echo htmlspecialchars($settings['kg_director_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مدير المرحلة الإبتدائية</label>
                            <input type="text" class="form-control" name="primary_director" value="<?php echo htmlspecialchars($settings['primary_director'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Primary Stage Director</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="primary_director_en" value="<?php echo htmlspecialchars($settings['primary_director_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مدير المرحلة الإعدادية والثانوية</label>
                            <input type="text" class="form-control" name="prep_sec_director" value="<?php echo htmlspecialchars($settings['prep_sec_director'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Prep & Secondary Stage Director</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="prep_sec_director_en" value="<?php echo htmlspecialchars($settings['prep_sec_director_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">المدير الإداري</label>
                            <input type="text" class="form-control" name="admin_director" value="<?php echo htmlspecialchars($settings['admin_director'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Administrative Director</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="admin_director_en" value="<?php echo htmlspecialchars($settings['admin_director_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">المدير المالي</label>
                            <input type="text" class="form-control" name="financial_director" value="<?php echo htmlspecialchars($settings['financial_director'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">Financial Director</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="financial_director_en" value="<?php echo htmlspecialchars($settings['financial_director_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">مدير المدرسة</label>
                            <input type="text" class="form-control" name="school_director" value="<?php echo htmlspecialchars($settings['school_director'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">School Director</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="school_director_en" value="<?php echo htmlspecialchars($settings['school_director_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الرئيس التنفيذي (CEO)</label>
                            <input type="text" class="form-control" name="ceo" value="<?php echo htmlspecialchars($settings['ceo'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold d-block" style="text-align: left;" dir="ltr">CEO</label>
                            <input type="text" class="form-control" style="text-align: left;" dir="ltr" name="ceo_en" value="<?php echo htmlspecialchars($settings['ceo_en'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-plus-circle me-1"></i>بيانات مخصصة إضافية للقسم</h6>
                        <button type="button" class="btn btn-sm btn-outline-dark fw-bold" onclick="addCustomFieldToModal('modal_custom_container_directors', 'directors');">
                            <i class="fas fa-plus me-1"></i>إضافة بيان جديد
                        </button>
                    </div>
                    <div id="modal_custom_container_directors" class="row g-3">
                        <?php foreach ($customBySection['directors'] as $cf): ?>
                            <div class="col-12 modal-custom-item mb-3">
                                <div class="p-3 border rounded bg-light position-relative">
                                    <button type="button" class="btn btn-sm btn-danger position-absolute" style="top: 10px; left: 10px; z-index: 10;" onclick="this.closest('.modal-custom-item').remove();" title="حذف هذا البيان">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <div class="row g-3">
                                        <!-- الجانب الأيمن: البيانات باللغة العربية -->
                                        <div class="col-md-6 border-start">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block text-end">البيانات باللغة العربية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name[]" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($cf['name']); ?>" placeholder="اسم البيان بالعربية" required>
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['value']); ?>" placeholder="القيمة بالعربية">
                                            </div>
                                        </div>
                                        <!-- الجانب الأيسر: البيانات باللغة الإنجليزية -->
                                        <div class="col-md-6">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block" style="text-align: left;" dir="ltr">البيانات باللغة الإنجليزية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name_en[]" class="form-control form-control-sm fw-bold" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['name_en'] ?? ''); ?>" placeholder="اسم البيان بالإنجليزية">
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value_en[]" class="form-control form-control-sm" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['value_en'] ?? ''); ?>" placeholder="القيمة بالإنجليزية">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL SECTION 4: بيانات وحقول إضافية مخصصة -->
<!-- ================================================================= -->
<div class="modal fade" id="modal_section_other" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="target_section" value="other">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>تعديل وإدارة البيانات والحقول الإضافية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                        <h6 class="fw-bold mb-0" style="color: #4f46e5;"><i class="fas fa-plus-circle me-1"></i>البيانات الإضافية العامة</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addCustomFieldToModal('modal_custom_container_other', 'other');">
                            <i class="fas fa-plus me-1"></i>إضافة بيان جديد
                        </button>
                    </div>
                    <div id="modal_custom_container_other" class="row g-3">
                        <?php foreach ($customBySection['other'] as $cf): ?>
                            <div class="col-12 modal-custom-item mb-3">
                                <div class="p-3 border rounded bg-light position-relative">
                                    <button type="button" class="btn btn-sm btn-danger position-absolute" style="top: 10px; left: 10px; z-index: 10;" onclick="this.closest('.modal-custom-item').remove();" title="حذف هذا البيان">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <div class="row g-3">
                                        <!-- الجانب الأيمن: البيانات باللغة العربية -->
                                        <div class="col-md-6 border-start">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block text-end">البيانات باللغة العربية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name[]" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($cf['name']); ?>" placeholder="اسم البيان بالعربية" required>
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['value']); ?>" placeholder="القيمة بالعربية">
                                            </div>
                                        </div>
                                        <!-- الجانب الأيسر: البيانات باللغة الإنجليزية -->
                                        <div class="col-md-6">
                                            <label class="form-label small text-secondary fw-bold mb-1 d-block" style="text-align: left;" dir="ltr">البيانات باللغة الإنجليزية</label>
                                            <div class="mb-2">
                                                <input type="text" name="custom_field_name_en[]" class="form-control form-control-sm fw-bold" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['name_en'] ?? ''); ?>" placeholder="اسم البيان بالإنجليزية">
                                            </div>
                                            <div>
                                                <input type="text" name="custom_field_value_en[]" class="form-control form-control-sm" style="text-align: left;" dir="ltr" value="<?php echo htmlspecialchars($cf['value_en'] ?? ''); ?>" placeholder="القيمة بالإنجليزية">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function removeSchoolLogo() {
    document.getElementById('logoInput').value = '';
    document.getElementById('removeLogoInput').value = '1';
    document.getElementById('logoForm').submit();
}

function uploadLogoWithProgress(input) {
    if (!input.files || input.files.length === 0) return;
    
    const file = input.files[0];
    
    // التحقق من الحجم والنوع في المتصفح فوراً قبل الرفع
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    if (!allowedTypes.includes(file.type)) {
        alert('نوع ملف الشعار غير مسموح. الأنواع المسموحة: PNG, JPG, WEBP, GIF');
        input.value = '';
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        alert('حجم الشعار يجب ألا يتجاوز 2 ميجابايت.');
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('school_logo', file);
    formData.append('csrf_token', document.querySelector('#logoForm input[name="csrf_token"]').value);

    // إنشاء وحقن غطاء التحميل البصري داخل كارت الشعار
    const logoCard = document.getElementById('logoCard');
    let overlay = document.querySelector('.logo-upload-overlay');
    if (!overlay && logoCard) {
        overlay = document.createElement('div');
        overlay.className = 'logo-upload-overlay';
        overlay.innerHTML = `
            <div class="spinner-border text-primary mb-3" role="status" style="width: 2.5rem; height: 2.5rem;"></div>
            <h6 class="fw-bold text-dark mb-2">جاري رفع الشعار...</h6>
            <div class="progress w-100 mb-2" style="height: 10px; border-radius: 5px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="overlayProgressBar" role="progressbar" style="width: 0%;"></div>
            </div>
            <span class="fw-bold text-primary fs-6" id="overlayProgressText">0%</span>
        `;
        logoCard.appendChild(overlay);
    }

    const progressBar = document.getElementById('overlayProgressBar');
    const progressText = document.getElementById('overlayProgressText');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'school_profile.php', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    let targetPercent = 0;
    let currentPercent = 0;
    let uploadCompleted = false;

    // حركة تدريجية سلسة لمؤشر التقدم من 0 إلى 100
    const interval = setInterval(() => {
        if (currentPercent < targetPercent) {
            currentPercent += Math.min(2, targetPercent - currentPercent); // خطوة تقدم بطيئة وسلسة لملاحظتها بوضوح
            updateUI(currentPercent);
        }
        
        if (uploadCompleted && currentPercent >= 100) {
            clearInterval(interval);
            window.location.reload();
        }
    }, 15);

    function updateUI(percent) {
        if (progressBar && progressText) {
            progressBar.style.width = percent + '%';
            progressText.textContent = percent + '%';
        }
    }

    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            targetPercent = Math.round((e.loaded / e.total) * 100);
        }
    });

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            targetPercent = 100;
            uploadCompleted = true;
        } else {
            clearInterval(interval);
            alert('حدث خطأ في الخادم أثناء رفع الشعار.');
            resetUploadUI();
        }
    };

    xhr.onerror = function() {
        clearInterval(interval);
        alert('فشل الاتصال بالخادم.');
        resetUploadUI();
    };

    xhr.send(formData);

    function resetUploadUI() {
        if (overlay) overlay.remove();
        input.value = '';
    }
}

// دالة إضافة عنصر مخصص جديد داخل مودال القسم
function addCustomFieldToModal(containerId, sectionKey) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'col-12 modal-custom-item mb-3';
    div.innerHTML = `
        <div class="p-3 border rounded bg-light position-relative">
            <button type="button" class="btn btn-sm btn-danger position-absolute" style="top: 10px; left: 10px; z-index: 10;" onclick="this.closest('.modal-custom-item').remove();" title="حذف هذا البيان">
                <i class="fas fa-trash-alt"></i>
            </button>
            <div class="row g-3">
                <!-- الجانب الأيمن: البيانات باللغة العربية -->
                <div class="col-md-6 border-start">
                    <label class="form-label small text-secondary fw-bold mb-1 d-block text-end">البيانات باللغة العربية</label>
                    <div class="mb-2">
                        <input type="text" name="custom_field_name[]" class="form-control form-control-sm fw-bold" placeholder="اسم البيان بالعربية" required>
                    </div>
                    <div>
                        <input type="text" name="custom_field_value[]" class="form-control form-control-sm" placeholder="القيمة بالعربية">
                    </div>
                </div>
                <!-- الجانب الأيسر: البيانات باللغة الإنجليزية -->
                <div class="col-md-6">
                    <label class="form-label small text-secondary fw-bold mb-1 d-block" style="text-align: left;" dir="ltr">البيانات باللغة الإنجليزية</label>
                    <div class="mb-2">
                        <input type="text" name="custom_field_name_en[]" class="form-control form-control-sm fw-bold" style="text-align: left;" dir="ltr" placeholder="اسم البيان بالإنجليزية">
                    </div>
                    <div>
                        <input type="text" name="custom_field_value_en[]" class="form-control form-control-sm" style="text-align: left;" dir="ltr" placeholder="القيمة بالإنجليزية">
                    </div>
                </div>
            </div>
        </div>
    `;
    container.appendChild(div);
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
