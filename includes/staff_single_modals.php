<?php
/**
 * Single staff account action modals for staff_accounts.php
 */
?>
<!-- ===== Modal: تعديل الدور والصلاحيات والنطاق ===== -->
<div class="modal fade" id="roleAccessModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable assessment-subject-assignment-modal-dialog staff-role-access-modal-dialog">
        <form method="post" action="staff_accounts.php?tab=<?php echo urlencode($activeTab); ?>" id="roleAccessForm" class="modal-content admin-modal admin-modal-premium admin-modal-edit assessment-subject-assignment-modal staff-role-access-modal" data-no-form-safety="true" data-datatable-ajax="manual" data-datatable-return-table="staffAccountsTable" data-datatable-return-row-field="user_id">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="update_role_access">
            <input type="hidden" name="user_id" id="role_user_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-shield me-2"></i>تحديد الدور والصلاحيات — <strong id="role_staff_name" class="text-primary">—</strong></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body assessment-subject-assignment-modal-body staff-role-access-modal-body">
                <div class="staff-role-access-top-notices">
                    <div class="alert alert-info py-2 mb-0 staff-role-access-guidance">
                        <i class="fas fa-circle-info me-1"></i>
                        يمكن تعيين أكثر من دور للحساب، لكن العامل يستخدم دوراً نشطاً واحداً في كل مرة ولا تُجمع صلاحيات الأدوار.
                    </div>
                    <div class="alert alert-info py-2 mb-0 staff-role-access-year">
                        <i class="fas fa-calendar-alt me-1"></i>
                        نطاق الصفوف والفصول للعام الدراسي:
                        <strong><?php echo htmlspecialchars($staffScopeYearName ?: 'غير محدد', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>
                <div class="alert alert-warning py-2 d-none" id="selfSuperAdminRoleNotice">
                    <i class="fas fa-shield-halved me-1"></i>
                    يمكنك تعديل أدوارك الثانوية، بينما يظل دور «مدير النظام الأعلى» مُعيّناً ودوراً أساسياً لحماية الوصول الإداري.
                </div>
                <div class="row g-3 staff-role-access-roles-layout">
                    <div class="col-12 staff-role-access-roles-field">
                        <label class="form-label fw-bold staff-role-access-roles-label">الأدوار <span class="text-danger">*</span></label>
                        <div class="row row-cols-1 row-cols-md-2 g-2" id="roleAccessOptions">
                            <?php foreach ($roleLabels as $key => $label): ?>
                                <div class="col">
                                    <label class="border rounded p-3 d-flex align-items-center gap-2 h-100 bg-light" for="role_access_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="checkbox" name="roles[]"
                                               id="role_access_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>"
                                               class="form-check-input role-access-checkbox mt-0"
                                               value="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>"
                                               data-role-label="<?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?>"
                                               data-requires-scope="<?php echo !empty($roleScopeRequirements[(string)$key]) ? '1' : '0'; ?>">
                                        <span class="fw-semibold"><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-6" id="primaryRoleField">
                        <label for="role_primary_value" class="form-label fw-bold">الدور الأساسي</label>
                        <select name="primary_role" id="role_primary_value" class="form-select"></select>
                        <div class="form-text">يُستخدم للتوافق مع أجزاء النظام القديمة، ولا يمنع اختيار بوابة أخرى عند الدخول.</div>
                    </div>
                </div>

                <div class="alert alert-secondary mt-3 d-none" id="employeeAccessNotice">
                    <i class="fas fa-user-lock me-2"></i>
                    اختيار «موظف» يلغي بوابة الموظف وبيانات دخوله ويجعل حسابه غير قابل لتسجيل الدخول.
                </div>

                <div class="alert alert-info mt-2 mb-0 d-none staff-role-access-supervisor" id="teacherSupervisorField">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_supervisor"
                               id="role_is_supervisor" value="1" aria-describedby="supervisorAccessHelp">
                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="role_is_supervisor">
                            <i class="fas fa-user-shield me-1 text-primary"></i>منح صلاحية المشرف
                        </label>
                    </div>
                    <div class="small text-muted mt-1" id="supervisorAccessHelp">
                        <i class="fas fa-info-circle me-1 text-info"></i>
                        يستطيع المعلم عند تسجيل الدخول الاختيار بين بوابة المعلم ووضع المشرف. لا تمنحه هذه الصلاحية صلاحيات الأدمن أو الأخصائي.
                    </div>
                </div>

                <section class="mt-4 d-none staff-role-scope-section" id="staffScopeSection">
                    <div id="staffScopeStatus" class="alert d-none" role="alert"></div>
                    <div id="staffScopeContent" class="staff-scope-content"></div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="submit" class="btn btn-primary" id="saveRoleAccessButton"><i class="fas fa-save me-1"></i>حفظ الدور والصلاحيات</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== Modal: استيراد بيانات الدخول ===== -->
<div class="modal fade" id="importCredentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="staff_accounts.php?tab=<?php echo urlencode($activeTab); ?>" enctype="multipart/form-data" class="modal-content admin-modal admin-modal-premium admin-modal-edit" data-no-form-safety="true">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="import_credentials">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد بيانات دخول العاملين</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-circle-info me-1"></i>
                    صدّر الحسابات أولًا، ثم عدّل عمود <code>username</code> واكتب كلمة المرور الجديدة في <code>new_password</code>. لا يغيّر الاستيراد الدور أو الحالة أو النطاق الأكاديمي.
                </div>
                <label for="staffAccountsFile" class="form-label fw-bold">ملف CSV</label>
                <input type="file" name="accounts_file" id="staffAccountsFile" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text">الحد الأقصى 2 ميجابايت و2000 حساب. تُطبق العملية بالكامل أو تُلغى بالكامل عند وجود خطأ.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-file-import me-1"></i>استيراد وتطبيق</button>
            </div>
        </form>
    </div>
</div>
