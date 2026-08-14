function openCredentialsModal(userId, currentUsername, staffName) {
    document.getElementById('cred_user_id').value = userId;
    document.getElementById('cred_username').value = currentUsername || '';
    document.getElementById('cred_password').value = '';
    document.getElementById('cred_staff_name').textContent = staffName || '—';
    new bootstrap.Modal(document.getElementById('credentialsModal')).show();
}

function openRoleAccessModal(userId, staffName, currentRoles, primaryRole, currentSupervisor, protectSelfSuperAdmin) {
    var roleAccessForm = document.getElementById('roleAccessForm');
    var protectSelf = protectSelfSuperAdmin === true;
    roleAccessForm.dataset.protectSelfSuperAdmin = protectSelf ? '1' : '0';
    document.getElementById('role_user_id').value = userId;
    document.getElementById('role_staff_name').textContent = staffName || '—';
    document.getElementById('selfSuperAdminRoleNotice').classList.toggle('d-none', !protectSelf);
    currentRoles = Array.isArray(currentRoles) && currentRoles.length ? currentRoles : ['employee'];
    document.querySelectorAll('.role-access-checkbox').forEach(function (input) {
        input.checked = currentRoles.indexOf(input.value) !== -1;
        input.disabled = protectSelf && input.value === 'employee';
    });
    document.getElementById('role_primary_value').setAttribute('data-current-primary', protectSelf ? 'super_admin' : (primaryRole || currentRoles[0]));
    document.getElementById('role_is_supervisor').checked = Number(currentSupervisor) === 1;
    if (typeof window.resetStaffRoleScopes === 'function') window.resetStaffRoleScopes();
    document.getElementById('staffScopeStatus').className = 'alert d-none';
    document.querySelector('.role-access-checkbox:checked')?.dispatchEvent(new Event('change'));
    new bootstrap.Modal(document.getElementById('roleAccessModal')).show();
}

function resetRoleFormState() {
    var form = document.getElementById('roleForm');
    if (!form) return;
    form.reset();
    document.getElementById('role_id').value = '';
    document.getElementById('clone_source_role_key').value = '';
    document.getElementById('role_key').readOnly = false;
    document.getElementById('role_name').readOnly = false;
    document.getElementById('roleFormTitle').textContent = 'إضافة دور جديد';
    document.getElementById('saveRoleButton').innerHTML = '<i class="fas fa-save me-1"></i>حفظ الدور';
    document.querySelectorAll('.role-page-checkbox').forEach(function (cb) { cb.checked = false; });
    applyRolePageAvailability([], []);
}

function roleButtonJson(button, attribute) {
    try { return JSON.parse(button.getAttribute(attribute) || '[]'); } catch (e) { return []; }
}

function applyRolePageAvailability(allowedPages, mandatoryPages) {
    var restrictPages = Array.isArray(allowedPages) && allowedPages.length > 0;
    mandatoryPages = Array.isArray(mandatoryPages) ? mandatoryPages : [];
    document.querySelectorAll('.role-page-option').forEach(function (option) {
        var pageName = option.getAttribute('data-page-name');
        var visible = !restrictPages || allowedPages.indexOf(option.getAttribute('data-page-name')) !== -1;
        option.classList.toggle('d-none', !visible);
        var checkbox = option.querySelector('.role-page-checkbox');
        if (!checkbox) return;
        checkbox.disabled = visible && mandatoryPages.indexOf(pageName) !== -1;
        if (!visible) {
            checkbox.checked = false;
        } else if (checkbox.disabled) {
            checkbox.checked = true;
            option.setAttribute('title', 'صفحة أساسية للدور ولا يمكن إلغاؤها');
        } else {
            option.removeAttribute('title');
        }
    });
}

function editRoleFromButton(button) {
    var roleId = button.getAttribute('data-role-id') || '';
    var roleKey = button.getAttribute('data-role-key') || '';
    var roleName = button.getAttribute('data-role-name') || '';
    var pages = roleButtonJson(button, 'data-pages');
    var allowedPages = roleButtonJson(button, 'data-allowed-pages');
    var mandatoryPages = roleButtonJson(button, 'data-mandatory-pages');
    var pagesOnly = button.getAttribute('data-pages-only') === '1';

    document.getElementById('clone_source_role_key').value = '';
    document.getElementById('role_id').value = roleId;
    document.getElementById('role_key').value = roleKey;
    document.getElementById('role_key').readOnly = true;
    document.getElementById('role_name').value = roleName;
    document.getElementById('role_name').readOnly = pagesOnly;
    document.getElementById('roleFormTitle').textContent = pagesOnly ? 'تعديل صفحات الدور' : 'تعديل دور';
    document.getElementById('saveRoleButton').innerHTML = '<i class="fas fa-save me-1"></i>حفظ التعديلات';
    applyRolePageAvailability(allowedPages, mandatoryPages);
    document.querySelectorAll('.role-page-checkbox').forEach(function (cb) {
        cb.checked = cb.disabled || pages.indexOf(cb.value) !== -1;
    });
    document.getElementById('roleForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cloneRoleFromButton(button) {
    var roleKey = button.getAttribute('data-role-key') || '';
    var roleName = button.getAttribute('data-role-name') || '';
    var pages = roleButtonJson(button, 'data-pages');
    var allowedPages = roleButtonJson(button, 'data-allowed-pages');
    var mandatoryPages = roleButtonJson(button, 'data-mandatory-pages');

    resetRoleFormState();
    document.getElementById('clone_source_role_key').value = roleKey;
    document.getElementById('role_name').value = 'نسخة من ' + roleName;
    document.getElementById('role_key').value = roleKey + '_copy';
    document.getElementById('roleFormTitle').textContent = 'استنساخ دور';
    document.getElementById('saveRoleButton').innerHTML = '<i class="fas fa-copy me-1"></i>إنشاء النسخة';
    applyRolePageAvailability(allowedPages, mandatoryPages);
    document.querySelectorAll('.role-page-checkbox').forEach(function (cb) {
        cb.checked = cb.disabled || pages.indexOf(cb.value) !== -1;
    });
    document.getElementById('roleForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('role_name').focus();
}

function openToggleStatusModal(userId, staffName, newStatus) {
    var isActivating = newStatus === 'active';
    document.getElementById('toggleStatusUserId').value = userId;
    document.getElementById('toggleStatusName').textContent = staffName || '—';
    document.getElementById('toggleStatusTitle').textContent = isActivating ? 'تفعيل الحساب' : 'تعطيل الحساب';
    document.getElementById('toggleStatusIcon').className = 'fas me-2 ' + (isActivating ? 'fa-check-circle text-success' : 'fa-ban text-danger');
    document.getElementById('toggleStatusConsequence').textContent = isActivating
        ? 'سيتمكن العامل من تسجيل الدخول للنظام مرة أخرى.'
        : 'لن يتمكن العامل من تسجيل الدخول للنظام حتى إعادة التفعيل.';

    var submitBtn = document.getElementById('toggleStatusSubmit');
    var submitIcon = document.getElementById('toggleStatusSubmitIcon');
    var submitLabel = document.getElementById('toggleStatusSubmitLabel');
    submitBtn.className = 'btn ' + (isActivating ? 'btn-success' : 'btn-danger');
    submitIcon.className = 'fas me-1 ' + (isActivating ? 'fa-check' : 'fa-ban');
    submitLabel.textContent = isActivating ? 'تفعيل' : 'تعطيل';

    new bootstrap.Modal(document.getElementById('toggleStatusModal')).show();
}
