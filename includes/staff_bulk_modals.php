<?php
/**
 * Bulk action modals and script initialization for staff_accounts.php
 */
?>
<!-- ===== Modal: تطبيق الأدوار والنطاق الجماعي للعاملين ===== -->
<div class="modal fade" id="bulkStaffRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit border-0 shadow-lg rounded-4 overflow-hidden">
            
            <!-- Modal Header with Dark/Blue Gradient & Glowing Icon -->
            <div class="modal-header-premium d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-header-icon-wrap">
                        <i class="fas fa-user-shield fs-5 text-white"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0 text-white fs-6">تطبيق الأدوار والنطاق الأكاديمي جماعياً</h5>
                        <small class="text-white-50 fs-7">تخصيص الأدوار والصلاحيات والنطاق الأكاديمي للحسابات المختارة</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>

            <div class="modal-body p-4 bg-light-subtle">
                
                <!-- Target Count Banner -->
                <div class="card border-0 shadow-sm rounded-3 bg-white p-3 mb-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-primary-subtle rounded-circle p-2 text-primary d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                <i class="fas fa-users-cog fs-6"></i>
                            </div>
                            <div>
                                <span class="fw-bold text-dark fs-7 d-block">الحسابات المستهدفة بالعملية</span>
                                <small class="text-muted fs-8">سيتم تطبيق التعديلات المحددة أدناه على الحسابات المحددة</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1.5 bg-primary text-white px-3 py-1.5 rounded-pill shadow-sm">
                            <span class="fs-7 fw-semibold">المحدد:</span>
                            <strong class="fs-6" id="bulkStaffRoleTargetCount">0</strong>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Mode Selection -->
                <div class="mb-4">
                    <div class="section-label-heading">
                        <span class="section-label-number">1</span>
                        <span>اختر طريقة تطبيق الأدوار <span class="text-danger">*</span></span>
                    </div>
                    <div class="row g-2.5">
                        <div class="col-md-4">
                            <label class="role-mode-card-premium" for="roleModeAdd">
                                <input class="form-check-input d-none" type="radio" name="bulk_staff_role_mode" id="roleModeAdd" value="add" checked>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-bold text-success fs-7 d-flex align-items-center gap-1">
                                        <i class="fas fa-plus-circle fs-6"></i> إضافة أدوار
                                    </span>
                                    <span class="badge-tag bg-success-subtle text-success border border-success-subtle">موصى به</span>
                                </div>
                                <small class="text-muted fs-8">الحفاظ على الأدوار الحالية للوظائف وإضافة الأدوار الجديدة إليها</small>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <label class="role-mode-card-premium" for="roleModeRemove">
                                <input class="form-check-input d-none" type="radio" name="bulk_staff_role_mode" id="roleModeRemove" value="remove">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-bold text-danger fs-7 d-flex align-items-center gap-1">
                                        <i class="fas fa-minus-circle fs-6"></i> إزالة أدوار
                                    </span>
                                    <span class="badge-tag bg-danger-subtle text-danger border border-danger-subtle">سحب أدوار</span>
                                </div>
                                <small class="text-muted fs-8">إزالة الأدوار المحددة أدناه فقط من الحسابات دون مسح باقي الصلاحيات</small>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <label class="role-mode-card-premium" for="roleModeReplace">
                                <input class="form-check-input d-none" type="radio" name="bulk_staff_role_mode" id="roleModeReplace" value="replace">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-bold text-warning-emphasis fs-7 d-flex align-items-center gap-1">
                                        <i class="fas fa-sync-alt fs-6"></i> استبدال بالكامل
                                    </span>
                                    <span class="badge-tag bg-warning-subtle text-warning-emphasis border border-warning-subtle">استبدال</span>
                                </div>
                                <small class="text-muted fs-8">مسح جميع الأدوار الحالية بالكامل وتعيين المجموعة المختارة</small>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Target Roles Grid -->
                <div class="mb-4">
                    <div class="section-label-heading">
                        <span class="section-label-number">2</span>
                        <span>حدد الأدوار المستهدفة <span class="text-danger">*</span></span>
                    </div>
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2.5">
                        <?php 
                        $roleIconMap = [
                            'teacher' => 'fa-chalkboard-teacher',
                            'specialist' => 'fa-user-md',
                            'doctor' => 'fa-stethoscope',
                            'librarian' => 'fa-book-reader',
                            'student_affairs' => 'fa-user-graduate',
                            'bus_manager' => 'fa-bus',
                            'role_manager' => 'fa-user-shield',
                        ];
                        foreach ($portalRoleLabels as $rKey => $rLabel):
                            if (in_array($rKey, ['admin', 'super_admin'], true)) continue; 
                            $iconClass = $roleIconMap[$rKey] ?? 'fa-id-badge';
                            $requiresScope = !empty($roleScopeRequirements[$rKey]);
                        ?>
                            <div class="col">
                                <label class="role-select-card-premium mb-0" for="bulk_role_<?php echo htmlspecialchars($rKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input class="form-check-input bulk-staff-role-cb d-none" type="checkbox" name="bulk_roles[]" value="<?php echo htmlspecialchars($rKey, ENT_QUOTES, 'UTF-8'); ?>" id="bulk_role_<?php echo htmlspecialchars($rKey, ENT_QUOTES, 'UTF-8'); ?>" data-requires-scope="<?php echo $requiresScope ? '1' : '0'; ?>">
                                    <div class="role-icon-box">
                                        <i class="fas <?php echo $iconClass; ?>"></i>
                                    </div>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($rLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($requiresScope): ?>
                                            <span class="text-primary fs-8"><i class="fas fa-graduation-cap me-1"></i>يتطلب نطاق</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="role-check-indicator">
                                        <i class="fas fa-check"></i>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Section 3: Primary Role Selection -->
                <div class="mb-4">
                    <div class="section-label-heading">
                        <span class="section-label-number">3</span>
                        <span>تخصيص الدور الأساسي (اختياري)</span>
                    </div>
                    <div class="input-group shadow-sm rounded-3">
                        <span class="input-group-text bg-white border-end-0 text-warning px-3">
                            <i class="fas fa-crown fs-6"></i>
                        </span>
                        <select name="primary_role_key" id="bulk_primary_role_key" class="form-select border-start-0 ps-0 rounded-start-3">
                            <option value="">(تلقائي بناءً على الأدوار الحالية / الجديدة)</option>
                            <?php foreach ($portalRoleLabels as $rKey => $rLabel):
                                if (in_array($rKey, ['admin', 'super_admin'], true)) continue; ?>
                                <option value="<?php echo htmlspecialchars($rKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-text fs-8 text-muted mt-1 ms-1">يحدد الواجهة الافتراضية التي يراها المستفيد عند تسجيل الدخول</div>
                </div>

                <!-- Section 4: Academic Scope (Conditional) -->
                <div id="bulkStaffScopeSection" class="scope-panel-premium mb-4 d-none">
                    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom border-primary-subtle">
                        <div class="bg-primary text-white rounded-circle p-1.5 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                            <i class="fas fa-graduation-cap fs-7"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-primary mb-0 fs-6">النطاق الأكاديمي الجماعي (للعام الدراسي الحالي)</h6>
                            <small class="text-muted fs-8">تحديد الصفوف والفصول المتاحة للمدرسين والأخصائيين المحددين</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark mb-2">طريقة تطبيق النطاق:</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulk_scope_mode" id="scopeModeMerge" value="merge" checked>
                                <label class="form-check-label small fw-bold text-dark" for="scopeModeMerge">دمج مع النطاق الحالي</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulk_scope_mode" id="scopeModeReplace" value="replace">
                                <label class="form-check-label small fw-bold text-dark" for="scopeModeReplace">استبدال النطاق الحالي</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulk_scope_mode" id="scopeModeRemove" value="remove">
                                <label class="form-check-label small fw-bold text-danger" for="scopeModeRemove">إزالة عناصر من النطاق</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-1">
                                <span><i class="fas fa-layer-group me-1 text-primary"></i>الصفوف الدراسية:</span>
                                <small class="text-muted fs-8">(Ctrl للتحديد المتعدد)</small>
                            </label>
                            <select id="bulkScopeGradeIds" class="form-select form-select-sm rounded-3 border-secondary-subtle" multiple style="height: 110px;">
                                <?php
                                $gradeQuery = $db->query("SELECT id, grade_name FROM grades ORDER BY grade_name")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($gradeQuery as $g): ?>
                                    <option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['grade_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-1">
                                <span><i class="fas fa-door-open me-1 text-primary"></i>الفصول الدراسية:</span>
                                <small class="text-muted fs-8">(Ctrl للتحديد المتعدد)</small>
                            </label>
                            <select id="bulkScopeClassIds" class="form-select form-select-sm rounded-3 border-secondary-subtle" multiple style="height: 110px;">
                                <?php
                                $classQuery = $db->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($classQuery as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section 5: Error Handling -->
                <div class="mb-2">
                    <div class="section-label-heading">
                        <span class="section-label-number">4</span>
                        <span>سياسة التعامل عند وجود خطأ بحساب</span>
                    </div>
                    <div class="row g-2.5">
                        <div class="col-md-6">
                            <label class="role-mode-card-premium py-2.5 px-3" for="bulkStaffErrStop">
                                <div class="d-flex align-items-center gap-2">
                                    <input class="form-check-input mt-0 flex-shrink-0" type="radio" name="bulk_staff_on_error" id="bulkStaffErrStop" value="stop" checked>
                                    <span class="fs-7 text-dark fw-bold">
                                        <i class="fas fa-shield-alt text-danger me-1"></i> إلغاء العملية بالكامل عند حدوث أي خطأ
                                    </span>
                                </div>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="role-mode-card-premium py-2.5 px-3" for="bulkStaffErrSkip">
                                <div class="d-flex align-items-center gap-2">
                                    <input class="form-check-input mt-0 flex-shrink-0" type="radio" name="bulk_staff_on_error" id="bulkStaffErrSkip" value="skip">
                                    <span class="fs-7 text-dark fw-bold">
                                        <i class="fas fa-check-double text-success me-1"></i> تجاوز الحسابات المرفوضة والتنفيذ على الصالحة
                                    </span>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-footer bg-white p-3 px-4 border-top d-flex align-items-center justify-content-between">
                <button type="button" class="btn btn-secondary px-4 py-2" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1.5"></i>إلغاء
                </button>
                <button type="button" class="btn btn-primary px-4 py-2 shadow" id="submitStaffBulkRoleBtn">
                    <i class="fas fa-check-circle me-1.5"></i>تطبيق الأدوار والنطاق
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ===== Modal: منح / إلغاء صفة المشرف جماعياً ===== -->
<div class="modal fade" id="bulkStaffSupervisorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-check me-2"></i>منح / إلغاء صفة المشرف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="fas fa-info-circle me-2"></i>صفة المشرف تُمنح للحسابات التي تمتلك دور معلم فقط.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">اختر إجراء صفة المشرف:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="bulk_supervisor_value" id="supGrant" value="1" checked>
                        <label class="form-check-label fw-bold text-success" for="supGrant"><i class="fas fa-check me-1"></i>منح صفة المشرف</label>
                    </div>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="radio" name="bulk_supervisor_value" id="supRevoke" value="0">
                        <label class="form-check-label fw-bold text-danger" for="supRevoke"><i class="fas fa-times me-1"></i>إلغاء صفة المشرف</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-primary" id="submitStaffSupervisorBtn"><i class="fas fa-check me-1"></i>تنفيذ الإجراء</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Modal: الإجراءات الجماعية العامة لحسابات العاملين ===== -->
<div class="modal fade" id="bulkStaffActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkStaffActionTitle"><i class="fas fa-layer-group me-2"></i>تأكيد الإجراء الجماعي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bulkStaffActionValue" value="">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="fas fa-users me-2"></i>عدد الحسابات المحددة للعملية: <strong id="bulkStaffActionTargetCount">0</strong>
                </div>
                <div id="bulkStaffActionDescription" class="mb-3 fw-semibold text-dark"></div>

                <div class="mb-3">
                    <label class="form-label fw-bold">وضع التعامل عند وجود خطأ بحساب:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="bulk_staff_action_on_error" id="bulkStaffActStop" value="stop" checked>
                        <label class="form-check-label" for="bulkStaffActStop"><strong>إلغاء العملية بالكامل</strong></label>
                    </div>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="radio" name="bulk_staff_action_on_error" id="bulkStaffActSkip" value="skip">
                        <label class="form-check-label" for="bulkStaffActSkip"><strong>تجاوز الحسابات المرفوضة</strong></label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-primary" id="submitStaffBulkActionBtn"><i class="fas fa-check me-1"></i>تأكيد وتنفيذ</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Modal: الإدارة الجماعية لصلاحيات الأدوار والصفحات ===== -->
<div class="modal fade" id="bulkRolePagesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>الإدارة الجماعية لصلاحيات الأدوار المخصصة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="fas fa-shield-alt me-2"></i>عدد الأدوار المستهدفة المحددة: <strong id="bulkRoleTargetCount">0</strong>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">العملية الجماعية <span class="text-danger">*</span></label>
                    <select id="bulkRoleOperationSelect" class="form-select">
                        <option value="copy_from">نسخ مجموعة صفحات من دور مصدر إلى الأدوار الهدف</option>
                        <option value="add">إضافة صفحات محددة إلى الأدوار الهدف</option>
                        <option value="remove">إزالة صفحات محددة من الأدوار الهدف</option>
                        <option value="replace">استبدال صفحات الأدوار الهدف بمجموعة جديدة</option>
                    </select>
                </div>

                <div class="mb-3" id="bulkRoleSourceGroup">
                    <label for="bulkRoleSourceSelect" class="form-label fw-bold">اختر الدور المصدر</label>
                    <select id="bulkRoleSourceSelect" class="form-select">
                        <option value="">-- اختر الدور المصدر --</option>
                        <?php foreach ($customRoles as $cr):
                            $crKey = (string)$cr['role_key'];
                            $crFamily = trim((string)($cr['base_role_key'] ?? ''));
                            if ($crFamily === '') $crFamily = $crKey;
                            if (!AdminRolePageCatalog::isCustomizableRole($crFamily)) continue; ?>
                            <option value="<?php echo htmlspecialchars($crKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cr['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3 d-none" id="bulkRolePagesGroup">
                    <label class="form-label fw-bold">اختر الصفحات الإدارية المسموح بها:</label>
                    <div class="border rounded p-3 bg-light" style="max-height: 250px; overflow-y: auto;">
                        <div class="row row-cols-1 row-cols-md-2 g-2">
                            <?php foreach ($availableAdminPages as $pName => $pLabel): ?>
                                <div class="col">
                                    <div class="form-check">
                                        <input class="form-check-input bulk-role-page-cb" type="checkbox" value="<?php echo htmlspecialchars($pName, ENT_QUOTES, 'UTF-8'); ?>" id="brp_<?php echo md5($pName); ?>">
                                        <label class="form-check-label small" for="brp_<?php echo md5($pName); ?>">
                                            <?php echo htmlspecialchars($pLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div id="bulkRolePreviewBox" class="border rounded p-3 bg-white mb-3 d-none">
                    <h6 class="fw-bold text-primary mb-2"><i class="fas fa-eye me-2"></i>معاينة التغييرات قبل الحفظ</h6>
                    <div id="bulkRolePreviewContent" class="small"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-outline-primary" id="previewBulkRolePagesBtn"><i class="fas fa-search me-1"></i>معاينة التغييرات</button>
                <button type="button" class="btn btn-primary" id="submitBulkRolePagesBtn"><i class="fas fa-check me-1"></i>تأكيد وحفظ</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_bulk_actions.js"></script>
<script>
var staffBulkHandler = null;

document.addEventListener('DOMContentLoaded', function () {
    if (window.AdminBulkActions && document.getElementById('staffAccountsTable')) {
        staffBulkHandler = window.AdminBulkActions({
            tableSelector: '#staffAccountsTable',
            barSelector: '#staffBulkActionBar',
            endpointUrl: 'ajax_bulk_staff_accounts.php',
            filterFormSelector: '#staffAccountFilters',
            filterInputSelectors: ['.role-checkbox', '.status-checkbox', '.access-checkbox'],
            getFilterData: function () {
                var parseArray = function (selector) {
                    return Array.from(document.querySelectorAll(selector + ':checked')).map(function (cb) { return cb.value; });
                };
                return {
                    role: parseArray('.role-checkbox'),
                    status: parseArray('.status-checkbox'),
                    access: parseArray('.access-checkbox'),
                    tab: <?php echo json_encode($activeTab); ?>
                };
            }
        });
    }

    // Role checkbox toggle in bulk staff role modal
    document.querySelectorAll('.bulk-staff-role-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var anyScoped = Array.from(document.querySelectorAll('.bulk-staff-role-cb:checked')).some(function (input) {
                return input.getAttribute('data-requires-scope') === '1';
            });
            document.getElementById('bulkStaffScopeSection').classList.toggle('d-none', !anyScoped);
        });
    });

    // Submit bulk staff role & scope
    var submitStaffRoleBtn = document.getElementById('submitStaffBulkRoleBtn');
    if (submitStaffRoleBtn) {
        submitStaffRoleBtn.addEventListener('click', function () {
            if (!staffBulkHandler) return;
            var roleMode = document.querySelector('input[name="bulk_staff_role_mode"]:checked')?.value || 'add';
            var roleKeys = Array.from(document.querySelectorAll('.bulk-staff-role-cb:checked')).map(function (cb) { return cb.value; });
            var primaryRoleKey = document.getElementById('bulk_primary_role_key').value;
            var scopeMode = document.querySelector('input[name="bulk_scope_mode"]:checked')?.value || 'merge';
            var gradeIds = Array.from(document.getElementById('bulkScopeGradeIds').selectedOptions).map(function (opt) { return opt.value; });
            var classIds = Array.from(document.getElementById('bulkScopeClassIds').selectedOptions).map(function (opt) { return opt.value; });
            var onError = document.querySelector('input[name="bulk_staff_on_error"]:checked')?.value || 'stop';
            var scopedRoleKeys = roleMode === 'remove' ? [] : roleKeys.filter(function (r) {
                var el = document.getElementById('bulk_role_' + r);
                return el && el.getAttribute('data-requires-scope') === '1';
            });

            if (roleKeys.length === 0) {
                alert('يرجى تحديد دور مستهدف واحد على الأقل.');
                return;
            }
            if (scopedRoleKeys.length > 0 && gradeIds.length === 0 && classIds.length === 0) {
                alert('حدد صفاً أو فصلاً واحداً على الأقل للأدوار التي تحتاج نطاقاً أكاديمياً.');
                return;
            }

            var extra = {
                role_mode: roleMode,
                role_keys: roleKeys,
                primary_role_key: primaryRoleKey,
                scope_mode: scopeMode,
                scope_role_keys: scopedRoleKeys,
                grade_ids: gradeIds,
                class_ids: classIds,
                on_error: onError
            };

            staffBulkHandler.executeAction('assign_roles', extra, $('#bulkStaffRoleModal'), '#submitStaffBulkRoleBtn');
        });
    }

    // Submit bulk supervisor toggle
    var submitSupervisorBtn = document.getElementById('submitStaffSupervisorBtn');
    if (submitSupervisorBtn) {
        submitSupervisorBtn.addEventListener('click', function () {
            if (!staffBulkHandler) return;
            var isSupervisor = document.querySelector('input[name="bulk_supervisor_value"]:checked')?.value || '1';
            staffBulkHandler.executeAction('set_supervisor', { is_supervisor: isSupervisor }, $('#bulkStaffSupervisorModal'), '#submitStaffSupervisorBtn');
        });
    }

    // Submit generic staff action
    var submitStaffActionBtn = document.getElementById('submitStaffBulkActionBtn');
    if (submitStaffActionBtn) {
        submitStaffActionBtn.addEventListener('click', function () {
            if (!staffBulkHandler) return;
            var action = document.getElementById('bulkStaffActionValue').value;
            var onError = document.querySelector('input[name="bulk_staff_action_on_error"]:checked')?.value || 'stop';
            staffBulkHandler.executeAction(action, { on_error: onError }, $('#bulkStaffActionModal'), '#submitStaffBulkActionBtn');
        });
    }

    // ===== Roles & Permissions Tab Bulk Management =====
    var selectedRoleKeys = new Set();
    function updateBulkRolesBar() {
        var count = selectedRoleKeys.size;
        var $bar = $('#bulkRolePermissionsBar');
        if (count > 0) {
            $bar.removeClass('d-none');
            $bar.find('.bulk-selected-role-count').text(count);
        } else {
            $bar.addClass('d-none');
        }
    }

    $(document).on('change', '.select-all-custom-roles', function () {
        var isChecked = this.checked;
        $('.role-bulk-cb').each(function () {
            this.checked = isChecked;
            if (isChecked) selectedRoleKeys.add(this.value); else selectedRoleKeys.delete(this.value);
        });
        updateBulkRolesBar();
    });

    $(document).on('change', '.role-bulk-cb', function () {
        if (this.checked) selectedRoleKeys.add(this.value); else selectedRoleKeys.delete(this.value);
        updateBulkRolesBar();
    });

    $(document).on('click', '.btn-clear-role-selection', function () {
        selectedRoleKeys.clear();
        $('.role-bulk-cb, .select-all-custom-roles').prop('checked', false);
        updateBulkRolesBar();
    });

    var roleOpSelect = document.getElementById('bulkRoleOperationSelect');
    if (roleOpSelect) {
        roleOpSelect.addEventListener('change', function () {
            var op = this.value;
            document.getElementById('bulkRoleSourceGroup').classList.toggle('d-none', op !== 'copy_from');
            document.getElementById('bulkRolePagesGroup').classList.toggle('d-none', op === 'copy_from');
            document.getElementById('bulkRolePreviewBox').classList.add('d-none');
        });
    }

    function executeBulkRolePages(isDryRun) {
        var csrfToken = (window.EduCore && window.EduCore.csrfToken) || $('meta[name="csrf-token"]').attr('content') || $('input[name="csrf_token"]').val();
        var op = document.getElementById('bulkRoleOperationSelect').value;
        var sourceKey = document.getElementById('bulkRoleSourceSelect').value;
        var pages = Array.from(document.querySelectorAll('.bulk-role-page-cb:checked')).map(function (cb) { return cb.value; });

        if (selectedRoleKeys.size === 0) {
            alert('يرجى تحديد دور مخصص واحد على الأقل أولاً.');
            return;
        }

        var payload = {
            csrf_token: csrfToken,
            operation: op,
            target_role_keys: Array.from(selectedRoleKeys),
            source_role_key: sourceKey,
            pages: pages,
            dry_run: isDryRun ? 1 : 0
        };

        var $btn = isDryRun ? $('#previewBulkRolePagesBtn') : $('#submitBulkRolePagesBtn');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>جاري المعالجة…');

        $.ajax({
            url: 'ajax_bulk_role_pages.php',
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function (res) {
                $btn.prop('disabled', false).html(origHtml);
                if (res && res.success) {
                    if (isDryRun) {
                        var escapeHtml = function (value) {
                            return $('<div>').text(String(value == null ? '' : value)).html();
                        };
                        var html = '';
                        if (res.preview) {
                            $.each(res.preview, function (rKey, info) {
                                html += '<div class="mb-2"><strong>' + escapeHtml(info.role_name) + ':</strong> ';
                                if (info.added && info.added.length) {
                                    html += '<span class="text-success me-2">+ سيُضاف: '
                                        + info.added.map(escapeHtml).join('، ') + '</span> ';
                                }
                                if (info.removed && info.removed.length) {
                                    html += '<span class="text-danger">- سيُحذف: '
                                        + info.removed.map(escapeHtml).join('، ') + '</span>';
                                }
                                if ((!info.added || !info.added.length) && (!info.removed || !info.removed.length)) {
                                    html += '<span class="text-muted">لا يوجد تغيير</span>';
                                }
                                html += '</div>';
                            });
                        }
                        $('#bulkRolePreviewContent').html(html || 'لا توجد تغييرات.');
                        $('#bulkRolePreviewBox').removeClass('d-none');
                    } else {
                        var modalInst = bootstrap.Modal.getInstance(document.getElementById('bulkRolePagesModal'));
                        if (modalInst) modalInst.hide();
                        alert(res.message || 'تم تحديث صفحات الأدوار المحددة بنجاح.');
                        window.location.reload();
                    }
                } else {
                    alert((res && res.message) ? res.message : 'حدث خطأ أثناء معالجة الصلاحيات.');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).html(origHtml);
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'حدث خطأ في الخادم.';
                alert(msg);
            }
        });
    }

    var previewRolePagesBtn = document.getElementById('previewBulkRolePagesBtn');
    if (previewRolePagesBtn) {
        previewRolePagesBtn.addEventListener('click', function () { executeBulkRolePages(true); });
    }
    var submitRolePagesBtn = document.getElementById('submitBulkRolePagesBtn');
    if (submitRolePagesBtn) {
        submitRolePagesBtn.addEventListener('click', function () { executeBulkRolePages(false); });
    }
});

function openBulkStaffRoleModal() {
    if (!staffBulkHandler || staffBulkHandler.getSelectedCount() === 0) {
        alert('يرجى تحديد حساب عامل واحد على الأقل أولاً.');
        return;
    }
    document.getElementById('bulkStaffRoleTargetCount').textContent = staffBulkHandler.getSelectedCount();
    new bootstrap.Modal(document.getElementById('bulkStaffRoleModal')).show();
}

function openBulkStaffSupervisorModal() {
    if (!staffBulkHandler || staffBulkHandler.getSelectedCount() === 0) {
        alert('يرجى تحديد حساب عامل واحد على الأقل أولاً.');
        return;
    }
    new bootstrap.Modal(document.getElementById('bulkStaffSupervisorModal')).show();
}

function openBulkStaffActionModal(action) {
    if (!staffBulkHandler || staffBulkHandler.getSelectedCount() === 0) {
        alert('يرجى تحديد حساب عامل واحد على الأقل أولاً.');
        return;
    }

    var actionLabels = {
        'activate': 'تفعيل الحسابات المحددة والسماح لها بتسجيل الدخول للبوابة.',
        'deactivate': 'تعطيل الحسابات المحددة ومنعها من تسجيل الدخول.',
        'generate_credentials': 'توليد اسم مستخدم وكلمة مرور فريدة للحسابات غير المهيأة فقط.',
        'reset_passwords': 'إعادة تعيين كلمة المرور وإنشاء كلمات مرور فريدة للحسابات المحددة.',
        'export_credentials': 'تصدير بيانات الدخول وكلمات المرور للحسابات المحددة في ملف CSV آمن.'
    };

    var actionTitles = {
        'activate': 'تفعيل حسابات العاملين جماعياً',
        'deactivate': 'تعطيل حسابات العاملين جماعياً',
        'generate_credentials': 'توليد بيانات الدخول',
        'reset_passwords': 'إعادة تعيين كلمات المرور',
        'export_credentials': 'تصدير بيانات الدخول (CSV)'
    };

    document.getElementById('bulkStaffActionValue').value = action;
    document.getElementById('bulkStaffActionTargetCount').textContent = staffBulkHandler.getSelectedCount();
    document.getElementById('bulkStaffActionTitle').innerHTML = '<i class="fas fa-layer-group me-2"></i>' + (actionTitles[action] || 'تأكيد الإجراء الجماعي');
    document.getElementById('bulkStaffActionDescription').textContent = actionLabels[action] || 'هل أنت تأكد من تنفيذ هذا الإجراء الجماعي؟';

    new bootstrap.Modal(document.getElementById('bulkStaffActionModal')).show();
}

function openBulkRolePagesModal() {
    var count = document.querySelectorAll('.role-bulk-cb:checked').length;
    if (count === 0) {
        alert('يرجى تحديد دور مخصص واحد على الأقل أولاً من الجدول.');
        return;
    }
    document.getElementById('bulkRoleTargetCount').textContent = count;
    document.getElementById('bulkRolePreviewBox').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('bulkRolePagesModal')).show();
}
</script>
