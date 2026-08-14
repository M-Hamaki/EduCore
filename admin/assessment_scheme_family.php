<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AssessmentSchemeBatchService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = (int) ($currentAcademicYear['id'] ?? 0);
$familyId = (int) ($_GET['family_id'] ?? $_POST['family_id'] ?? 0);
$successMessage = $_SESSION['success_message'] ?? null;
$errorMessage = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function scheme_family_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function scheme_family_public_error(Throwable $error): string
{
    $cursor = $error;
    do {
        if ($cursor instanceof PDOException) {
            error_log('Assessment scheme family database operation failed: ' . $error->getMessage());
            return 'تعذر تنفيذ العملية في قاعدة البيانات. لم يتم حفظ أي تغييرات جزئية.';
        }
        $cursor = $cursor->getPrevious();
    } while ($cursor instanceof Throwable);

    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        return $error->getMessage();
    }

    error_log('Assessment scheme family operation failed [' . get_class($error) . ']: ' . $error->getMessage());
    return 'تعذر تنفيذ العملية. راجع البيانات ثم حاول مرة أخرى.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($currentAcademicYearId <= 0 || $familyId <= 0) {
            throw new RuntimeException('تعذر تحديد مجموعة الخطة أو العام الدراسي الحالي.');
        }
        $service = new AssessmentSchemeBatchService($db);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'activate_family') {
            $affected = $service->activateFamily($currentAcademicYearId, $familyId);
            $_SESSION['success_message'] = 'تم تفعيل ' . count($affected) . ' خطة ضمن المجموعة بعد التحقق من نطاقاتها وربط المادة وبنود التقييم.';
        } elseif ($action === 'archive_family') {
            $affected = $service->archiveFamily($currentAcademicYearId, $familyId);
            $_SESSION['success_message'] = 'تم تعطيل ' . count($affected) . ' خطة ضمن المجموعة كوحدة واحدة.';
        } elseif ($action === 'update_family_configuration') {
            $updated = $service->updateFamilyConfiguration($currentAcademicYearId, $familyId, $_POST);
            $_SESSION['success_message'] = 'تم تحديث نطاق المجموعة' . (!empty($updated['annual_enabled']) ? ' وسياسة أوزان نهاية العام' : '') . ' لجميع الترمات وهي ما زالت مسودات.';
        } else {
            throw new InvalidArgumentException('إجراء إدارة المجموعة غير صالح.');
        }
        header('Location: assessment_scheme_family.php?family_id=' . $familyId);
        exit;
    } catch (Throwable $error) {
        $errorMessage = scheme_family_public_error($error);
    }
}

$details = null;
if ($currentAcademicYearId > 0 && $familyId > 0) {
    try {
        $details = (new AssessmentSchemeBatchService($db))->familyDetails($currentAcademicYearId, $familyId);
    } catch (Throwable $error) {
        $errorMessage = $errorMessage ?: scheme_family_public_error($error);
    }
} elseif ($errorMessage === null) {
    $errorMessage = 'اختر مجموعة خطة درجات صحيحة من صفحة خطط الدرجات.';
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-layer-group me-2 text-primary"></i>إدارة مجموعة خطط الدرجات</h1>
    <div class="admin-top-actions">
        <a class="btn btn-outline-secondary shadow-sm" href="assessment_schemes.php"><i class="fas fa-arrow-right me-2"></i>العودة إلى خطط الدرجات</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo scheme_family_h($successMessage); ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-triangle-exclamation me-2"></i><?php echo scheme_family_h($errorMessage); ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>
<?php endif; ?>

<?php if ($details !== null): ?>
    <?php
    $family = $details['family'];
    $schemes = $details['schemes'];
    $allActive = !empty($schemes) && count(array_filter($schemes, static fn(array $scheme): bool => (string) $scheme['status'] === 'active')) === count($schemes);
    $allDraft = !empty($schemes) && count(array_filter($schemes, static fn(array $scheme): bool => (string) $scheme['status'] === 'draft')) === count($schemes);
    $allArchived = !empty($schemes) && count(array_filter($schemes, static fn(array $scheme): bool => (string) $scheme['status'] === 'archived')) === count($schemes);
    $hasArchived = count(array_filter($schemes, static fn(array $scheme): bool => (string) $scheme['status'] === 'archived')) > 0;
    $groupStatusLabel = $allActive
        ? 'كل الترمات نشطة'
        : ($allDraft ? 'كل الترمات مسودات' : ($allArchived ? 'المجموعة معطلة' : 'حالة مختلطة تحتاج مراجعة'));
    $termNames = [];
    foreach ($schemes as $scheme) {
        $termNames[(int) $scheme['term_id']] = (string) $scheme['term_name'];
    }
    $annual = $details['annual'];
    $firstScheme = $schemes[0];
    $firstSchemeScopes = $details['scopes'][(int) $firstScheme['id']] ?? [];
    $scopeIsGradeWide = count(array_filter($firstSchemeScopes, static fn(array $scope): bool => (string) ($scope['scope_kind'] ?? '') === 'grade')) > 0;
    $selectedScopeClassIds = array_values(array_unique(array_map(
        static fn(array $scope): int => (int) ($scope['class_id'] ?? 0),
        array_filter($firstSchemeScopes, static fn(array $scope): bool => (string) ($scope['scope_kind'] ?? '') === 'class')
    )));
    $selectedScopeClassIds = array_values(array_filter($selectedScopeClassIds, static fn(int $id): bool => $id > 0));
    $classesStmt = $db->prepare("SELECT id, name FROM classes WHERE grade_id = ? AND status = 'active' ORDER BY name");
    $classesStmt->execute([(int) $firstScheme['grade_id']]);
    $availableClasses = $classesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $annualWeights = [];
    if (!empty($annual['enabled']) && !empty($annual['weights_by_term_id'])) {
        foreach ($annual['weights_by_term_id'] as $termId => $weight) {
            $annualWeights[(int) $termId] = (float) $weight;
        }
    } else {
        $termCount = count($schemes);
        $remainingWeight = 100.0;
        foreach ($schemes as $index => $scheme) {
            $weight = $index === $termCount - 1 ? $remainingWeight : round(100 / $termCount, 3);
            $annualWeights[(int) $scheme['term_id']] = $weight;
            $remainingWeight -= $weight;
        }
    }
    ?>
    <div class="card shadow-sm admin-card-surface mb-3">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-diagram-project me-2"></i><?php echo scheme_family_h($family['name']); ?></h5>
            <span class="badge bg-light text-dark"><i class="fas fa-book me-1"></i><?php echo scheme_family_h($family['subject_name']); ?></span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">الترمات داخل المجموعة</div><div class="fw-semibold"><?php echo count($schemes); ?> ترم</div></div></div>
                <div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">نتيجة نهاية العام</div><div class="fw-semibold"><?php echo !empty($annual['enabled']) ? 'مفعّلة' : 'غير مفعّلة'; ?></div></div></div>
                <div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">حالة المجموعة</div><div class="fw-semibold"><?php echo scheme_family_h($groupStatusLabel); ?></div></div></div>
            </div>
            <?php if (!empty($annual['enabled']) && !empty($annual['weights_by_term_id'])): ?>
                <div class="mt-3 small text-muted"><i class="fas fa-scale-balanced me-1"></i>
                    <?php foreach ($annual['weights_by_term_id'] as $termId => $weight): ?>
                        <span class="badge bg-light text-dark border me-1"><?php echo scheme_family_h($termNames[(int) $termId] ?? ('ترم #' . $termId)); ?>: <?php echo scheme_family_h($weight); ?>%</span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted"><i class="fas fa-shield-halved me-1"></i>التفعيل والتعطيل يطبّقان على الترمات التابعة لهذه المجموعة معًا.</span>
            <?php if ($allActive): ?>
                <button class="btn btn-warning" type="button" data-bs-toggle="modal" data-bs-target="#archiveFamilyModal"><i class="fas fa-ban me-1"></i>تعطيل المجموعة</button>
            <?php elseif (!$hasArchived || $allArchived): ?>
                <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#activateFamilyModal"><i class="fas fa-check me-1"></i>تفعيل المجموعة</button>
            <?php else: ?>
                <span class="badge bg-secondary"><i class="fas fa-box-archive me-1"></i>حالة مختلطة تحتاج مراجعة</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($allActive): ?>
        <div class="modal fade" id="archiveFamilyModal" tabindex="-1" aria-labelledby="archiveFamilyModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium">
                    <form method="post" action="assessment_scheme_family.php?family_id=<?php echo $familyId; ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="family_id" value="<?php echo $familyId; ?>">
                        <input type="hidden" name="action" value="archive_family">
                        <div class="modal-header"><h5 class="modal-title" id="archiveFamilyModalLabel"><i class="fas fa-ban me-2"></i>تعطيل مجموعة خطط الدرجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-ban text-warning" style="font-size: 3rem;"></i></div><p class="text-center mb-0">سيتم تعطيل جميع الترمات في مجموعة «<?php echo scheme_family_h($family['name']); ?>» معًا.</p></div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning"><i class="fas fa-ban me-1"></i>تعطيل المجموعة</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php elseif (!$hasArchived || $allArchived): ?>
        <div class="modal fade" id="activateFamilyModal" tabindex="-1" aria-labelledby="activateFamilyModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium">
                    <form method="post" action="assessment_scheme_family.php?family_id=<?php echo $familyId; ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="family_id" value="<?php echo $familyId; ?>">
                        <input type="hidden" name="action" value="activate_family">
                        <div class="modal-header"><h5 class="modal-title" id="activateFamilyModalLabel"><i class="fas fa-check me-2"></i>تفعيل مجموعة خطط الدرجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i></div><p class="text-center mb-0">سيُتحقق من ربط المادة والبنود والنطاق ثم تُفعَّل كل الترمات معًا، أو لن يُنفّذ أي تفعيل.</p></div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>تأكيد التفعيل</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($allDraft): ?>
        <form method="post" action="assessment_scheme_family.php?family_id=<?php echo $familyId; ?>" class="card shadow-sm admin-card-surface mb-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="family_id" value="<?php echo $familyId; ?>">
            <input type="hidden" name="action" value="update_family_configuration">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-sliders me-2"></i>تعديل نطاق المجموعة وسياسة نهاية العام</h5>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <h6 class="mb-0"><i class="fas fa-school me-1 text-primary"></i>نطاق الصف والفصول</h6>
                            <span class="badge bg-light text-dark border"><?php echo scheme_family_h($firstScheme['grade_name'] ?? 'الصف'); ?></span>
                        </div>
                        <div class="form-check form-switch border rounded p-3 mb-3">
                            <input class="form-check-input ms-2" type="checkbox" role="switch" id="scopeAllClasses" name="scope_all_classes" value="1"<?php echo $scopeIsGradeWide ? ' checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="scopeAllClasses">تطبيق الخطة على الصف بالكامل</label>
                        </div>
                        <?php if ($availableClasses !== []): ?>
                            <div id="specificClassesPanel" class="row row-cols-1 row-cols-md-2 g-2">
                                <?php foreach ($availableClasses as $class): ?>
                                    <?php $classId = (int) $class['id']; ?>
                                    <div class="col">
                                        <label class="border rounded p-2 d-flex align-items-center gap-2 w-100">
                                            <input class="form-check-input m-0 scope-class-checkbox" type="checkbox" name="scope_class_ids[]" value="<?php echo $classId; ?>"<?php echo in_array($classId, $selectedScopeClassIds, true) ? ' checked' : ''; ?>>
                                            <span><?php echo scheme_family_h($class['name']); ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i>لا توجد فصول نشطة داخل هذا الصف؛ يبقى النطاق الكامل هو الخيار المتاح.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-5">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <h6 class="mb-0"><i class="fas fa-scale-balanced me-1 text-primary"></i>أوزان نهاية العام</h6>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input ms-2" type="checkbox" role="switch" id="annualEnabled" name="annual_enabled" value="1"<?php echo !empty($annual['enabled']) ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="annualEnabled">تفعيل</label>
                            </div>
                        </div>
                        <div id="annualWeightsPanel" class="row g-2">
                            <?php foreach ($schemes as $scheme): ?>
                                <?php $termId = (int) $scheme['term_id']; ?>
                                <div class="col-12">
                                    <label class="form-label small mb-1" for="annualWeight<?php echo $termId; ?>"><?php echo scheme_family_h($scheme['term_name']); ?></label>
                                    <div class="input-group">
                                        <input class="form-control annual-weight" type="number" min="0" max="100" step="0.001" id="annualWeight<?php echo $termId; ?>" name="annual_weights[<?php echo $termId; ?>]" value="<?php echo scheme_family_h($annualWeights[$termId] ?? 0); ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-muted mt-2">عند التفعيل يجب أن يكون مجموع الأوزان 100%، وأن يساهم ترمان على الأقل.</div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex justify-content-end">
                <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>حفظ إعدادات المجموعة</button>
            </div>
        </form>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const allClasses = document.getElementById('scopeAllClasses');
            const classCheckboxes = document.querySelectorAll('.scope-class-checkbox');
            const annualEnabled = document.getElementById('annualEnabled');
            const annualWeights = document.querySelectorAll('.annual-weight');
            const syncScopes = function () {
                classCheckboxes.forEach(function (checkbox) {
                    checkbox.disabled = allClasses && allClasses.checked;
                });
            };
            const syncAnnual = function () {
                annualWeights.forEach(function (input) {
                    input.disabled = annualEnabled && !annualEnabled.checked;
                });
            };
            if (allClasses) { allClasses.addEventListener('change', syncScopes); syncScopes(); }
            if (annualEnabled) { annualEnabled.addEventListener('change', syncAnnual); syncAnnual(); }
        });
        </script>
    <?php else: ?>
        <div class="alert alert-secondary mb-3"><i class="fas fa-lock me-2"></i>يبقى تعديل النطاق وأوزان نهاية العام متاحًا للمسودات فقط. بعد التفعيل أو التعطيل، أنشئ مجموعة بديلة إذا احتجت إلى تغيير تشغيلي.</div>
    <?php endif; ?>

    <div class="admin-list-surface">
        <div class="admin-table-wrap table-responsive">
            <table class="table table-hover table-striped align-middle admin-data-table">
                <thead><tr><th>الترم</th><th>الصف والفصول</th><th>البنود</th><th>الجاهزية</th><th>الحالة</th></tr></thead>
                <tbody>
                <?php foreach ($schemes as $scheme): ?>
                    <?php
                    $schemeScopes = $details['scopes'][(int) $scheme['id']] ?? [];
                    $scopeLabel = [];
                    foreach ($schemeScopes as $scope) {
                        $scopeLabel[] = (string) ($scope['scope_kind'] ?? '') === 'grade'
                            ? (string) $scope['grade_name'] . ' بالكامل'
                            : (string) $scope['grade_name'] . ' — ' . (string) ($scope['class_name'] ?? 'فصل غير معروف');
                    }
                    $readiness = (string) ($scheme['readiness_status'] ?? 'legacy');
                    $readinessLabel = ['ready' => 'جاهزة', 'waiting_for_subject_link' => 'تنتظر ربط المادة', 'needs_components' => 'تحتاج بنودًا', 'legacy' => 'تحتاج ترقية'][$readiness] ?? $readiness;
                    $readinessClass = $readiness === 'ready' ? 'bg-success' : ($readiness === 'legacy' ? 'bg-secondary' : 'bg-warning text-dark');
                    ?>
                    <tr>
                        <td><strong><?php echo scheme_family_h($scheme['term_name']); ?></strong><div class="small text-muted"><?php echo scheme_family_h($scheme['name']); ?></div></td>
                        <td><?php echo scheme_family_h(implode('، ', $scopeLabel)); ?></td>
                        <td><?php echo (int) $scheme['components_count']; ?> بندًا <span class="text-muted">(<?php echo scheme_family_h($scheme['components_total']); ?> / <?php echo scheme_family_h($scheme['total_grade']); ?>)</span></td>
                        <td><span class="badge <?php echo $readinessClass; ?>" title="<?php echo scheme_family_h($scheme['readiness_reason'] ?? ''); ?>"><?php echo scheme_family_h($readinessLabel); ?></span></td>
                        <td><span class="badge <?php echo (string) $scheme['status'] === 'active' ? 'bg-success' : ((string) $scheme['status'] === 'archived' ? 'bg-secondary' : 'bg-warning text-dark'); ?>"><?php echo scheme_family_h(['active' => 'نشطة', 'archived' => 'معطلة', 'draft' => 'مسودة'][(string) $scheme['status']] ?? $scheme['status']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
