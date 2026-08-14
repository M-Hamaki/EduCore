<script>
let staffInlineDeleteConfirmCallback = null;
let staffUnsavedLeaveCallback = null;
let staffConfirmActioned = false; // true عند التأكيد، false عند الإلغاء
let staffProfileModalInstance = null;

function showStaffChildModal(modalEl) {
    if (!modalEl || !window.bootstrap) return;
    const childModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const profileModalEl = document.getElementById('staffProfileModal');
    if (!profileModalEl || !profileModalEl.classList.contains('show')) {
        childModal.show();
        return;
    }

    const profileModal = bootstrap.Modal.getOrCreateInstance(profileModalEl);
    profileModalEl.addEventListener('hidden.bs.modal', function openStaffChildModal() {
        modalEl.dataset.restoreStaffProfile = 'true';
        modalEl.addEventListener('hidden.bs.modal', function restoreStaffProfile() {
            if (modalEl.dataset.restoreStaffProfile === 'true') {
                delete modalEl.dataset.restoreStaffProfile;
                bootstrap.Modal.getOrCreateInstance(profileModalEl).show();
            }
        }, { once: true });
        childModal.show();
    }, { once: true });
    profileModal.hide();
}

document.addEventListener('DOMContentLoaded', function () {
    const profileModalEl = document.getElementById('staffProfileModal');
    if (!profileModalEl || !window.bootstrap) return;

    staffProfileModalInstance = bootstrap.Modal.getOrCreateInstance(profileModalEl);
    profileModalEl.addEventListener('shown.bs.modal', function () {
        const invalidField = profileModalEl.querySelector(':invalid, [aria-invalid="true"]');
        if (invalidField) invalidField.focus();
    }, { once: true });
    staffProfileModalInstance.show();

    profileModalEl.querySelector('[data-staff-modal-close]')?.addEventListener('click', function () {
        const leave = function () {
            if (typeof window.bypassStaffUnsavedGuard === 'function') window.bypassStaffUnsavedGuard();
            window.location.href = 'staff.php';
        };
        if (hasStaffUnsavedChanges()) {
            confirmStaffUnsavedExit(leave);
        } else {
            leave();
        }
    });
});

function confirmStaffInlineDelete(message, onConfirm, options) {
    const modalEl = document.getElementById('staffInlineDeleteConfirmModal');
    const messageEl = document.getElementById('staffInlineDeleteConfirmMessage');
    const confirmBtn = document.getElementById('staffInlineDeleteConfirmBtn');
    if (!modalEl || !messageEl || !confirmBtn || !window.bootstrap) {
        return;
    }

    messageEl.textContent = message || 'هل أنت متأكد من الحذف؟';
    staffInlineDeleteConfirmCallback = onConfirm;
    staffConfirmActioned = false;
    modalEl.dataset.restoreProfileAfterConfirm = options && options.restoreProfileAfterConfirm === false
        ? 'false'
        : 'true';

    showStaffChildModal(modalEl);
}

function confirmStaffUnsavedExit(onConfirm) {
    const modalEl = document.getElementById('staffUnsavedChangesModal');
    if (!modalEl || !window.bootstrap) {
        return;
    }
    staffUnsavedLeaveCallback = onConfirm;
    showStaffChildModal(modalEl);
}

document.getElementById('staffInlineDeleteConfirmBtn')?.addEventListener('click', function() {
    staffConfirmActioned = true; // تم التأكيد — لا تصفّر الحارس عند الإخفاء
    const modalEl = document.getElementById('staffInlineDeleteConfirmModal');
    if (modalEl && modalEl.dataset.restoreProfileAfterConfirm === 'false') {
        delete modalEl.dataset.restoreStaffProfile;
    }
    if (typeof staffInlineDeleteConfirmCallback === 'function') {
        staffInlineDeleteConfirmCallback();
    }
    const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
    if (modal) {
        modal.hide();
    }
    if (modalEl) delete modalEl.dataset.restoreProfileAfterConfirm;
    staffInlineDeleteConfirmCallback = null;
});

// عند إغلاق modal التأكيد بدون تأكيد (إلغاء المستخدم)، أعِد ضبط الحارس حتى
// يبقى تحذير "التغييرات غير المحفوظة" فعالاً للتغييرات الموجودة فعلاً.
(function () {
    const inlineDeleteModalEl = document.getElementById('staffInlineDeleteConfirmModal');
    if (inlineDeleteModalEl) {
        inlineDeleteModalEl.addEventListener('hidden.bs.modal', function () {
            // فقط عند الإلغاء (لم يُضغط تأكيد): صفّر الحارس الذي ضبطه المستمع العام submit
            if (!staffConfirmActioned && typeof window.bypassStaffUnsavedGuardReset === 'function') {
                window.bypassStaffUnsavedGuardReset();
            }
            delete inlineDeleteModalEl.dataset.restoreProfileAfterConfirm;
        });
    }
})();

document.getElementById('staffUnsavedLeaveBtn')?.addEventListener('click', function() {
    const modalEl = document.getElementById('staffUnsavedChangesModal');
    if (modalEl) delete modalEl.dataset.restoreStaffProfile;
    if (typeof staffUnsavedLeaveCallback === 'function') {
        staffUnsavedLeaveCallback();
    }
    const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
    if (modal) {
        modal.hide();
    }
    staffUnsavedLeaveCallback = null;
});

// ===== وظائف المرفقات للموظف =====
function uploadStaffProfileImage() {
    var fileInput = document.getElementById('staff_profile_image_file');
    if (!fileInput || !fileInput.files.length) { alert('يرجى اختيار ملف صورة للرفع'); return; }
    var dt = new DataTransfer();
    dt.items.add(fileInput.files[0]);
    document.getElementById('hidden_staff_profile_image_file').files = dt.files;
    if (typeof window.bypassStaffUnsavedGuard === 'function') window.bypassStaffUnsavedGuard();
    document.getElementById('uploadStaffProfileImageForm').submit();
}

function deleteStaffAttachment(id, label, row) {
    confirmStaffInlineDelete('هل أنت متأكد من حذف المرفق: ' + label + '؟', function() {
        var entityId = parseInt(document.querySelector('#deleteStaffAttachmentForm input[name="id"]')?.value, 10) || 0;
        var deleteBtn = row?.querySelector('.att-delete-btn');
        if (deleteBtn) deleteBtn.disabled = true;
        mutateProfileAttachment({
            action: 'delete',
            entityType: 'staff',
            entityId: entityId,
            attachmentId: id,
            endpoint: 'ajax/upload_attachment.php',
            onSuccess: function(data) {
                row?.remove();
                document.querySelectorAll('#staffAttachmentsTableBody .att-index').forEach(function(cell, index) {
                    cell.textContent = index + 1;
                });
                var tbody = document.getElementById('staffAttachmentsTableBody');
                if (tbody && tbody.children.length === 0) {
                    document.getElementById('staffAttachmentsTableWrap').style.display = 'none';
                    document.getElementById('staffAttachmentsEmpty').style.display = '';
                }
                window.setTimeout(function() {
                    showProfileAttachmentFeedback('success', data.message || 'تم حذف المرفق بنجاح.');
                }, 220);
            },
            onError: function(message) {
                if (deleteBtn) deleteBtn.disabled = false;
                window.setTimeout(function() {
                    showProfileAttachmentFeedback('danger', message);
                }, 220);
            }
        });
    });
}

// ===== رفع المرفقات الفوري مع شريط تقدم =====
document.addEventListener('DOMContentLoaded', function () {
    var staffEntityId = <?php echo isset($user->id) ? (int) $user->id : 0; ?>;
    var uploadBtn = document.getElementById('staff_upload_attachment_btn');
    var fileInput = document.getElementById('staff_attachment_file_input');

    if (uploadBtn && fileInput && staffEntityId > 0) {
        uploadBtn.addEventListener('click', function () { fileInput.click(); });

        fileInput.addEventListener('change', function () {
            Array.prototype.forEach.call(fileInput.files, function (file) {
                uploadStaffAttachmentRow(file, staffEntityId);
            });
            // إعادة ضبط حقل الملف للسماح بإعادة اختيار نفس الملف لاحقاً
            fileInput.value = '';
        });
    }

    // ربط اختيار الصورة الشخصية للموظف بالرفع الفوري التلقائي
    var staffProfileImageInput = document.getElementById('staff_profile_image_file');
    if (staffProfileImageInput && staffEntityId > 0) {
        staffProfileImageInput.addEventListener('change', function () {
            if (staffProfileImageInput.files.length > 0) {
                uploadStaffProfileImageInstantly(staffProfileImageInput.files[0], staffEntityId);
            }
        });
    }

    // رفع ملف واحد مع شريط تقدم، وتحديث الجدول ديناميكياً
    function uploadStaffAttachmentRow(file, entityId) {
        var tbody = document.getElementById('staffAttachmentsTableBody');
        if (!tbody) return;
        // إظهار حاوية الجدول وإخفاء رسالة "لا توجد مرفقات"
        var tableWrap = document.getElementById('staffAttachmentsTableWrap');
        if (tableWrap) tableWrap.style.display = '';
        var emptyMsg = document.getElementById('staffAttachmentsEmpty');
        if (emptyMsg) emptyMsg.style.display = 'none';

        // إنشاء صف مؤقت يعرض شريط التقدم
        var tr = document.createElement('tr');
        tr.className = 'att-uploading-row';
        tr.innerHTML =
            '<td class="att-index">' + (tbody.children.length + 1) + '</td>' +
            '<td colspan="4">' +
                '<div class="d-flex align-items-center gap-2 mb-1">' +
                    '<i class="fas fa-spinner fa-spin text-primary"></i>' +
                    '<strong class="small">' + escapeHtml(file.name) + '</strong>' +
                    '<span class="text-muted small att-pct">0%</span>' +
                '</div>' +
                '<div class="progress" style="height:8px;">' +
                    '<div class="progress-bar progress-bar-striped progress-bar-animated bg-primary att-bar" role="progressbar" style="width:0%;"></div>' +
                '</div>' +
                '<div class="att-err text-danger small mt-1" style="display:none;"></div>' +
            '</td>' +
            '<td></td>';
        tbody.appendChild(tr);

        var pctEl = tr.querySelector('.att-pct');
        var barEl = tr.querySelector('.att-bar');
        var errEl = tr.querySelector('.att-err');

        uploadAttachmentInstantly({
            file: file,
            entityType: 'staff',
            entityId: entityId,
            label: '',  // اسم افتراضي = اسم الملف (يعالجه الخادم)
            endpoint: 'ajax/upload_attachment.php',
            onProgress: function (pct) {
                pctEl.textContent = pct + '%';
                barEl.style.width = pct + '%';
            },
            onSuccess: function (data) {
                var att = data.attachment || {};
                tr.setAttribute('data-attachment-id', att.id);
                tr.className = '';
                var fileIcon = fileIconClass(att.ext);
                tr.innerHTML =
                    '<td class="att-index">' + (tbody.children.length) + '</td>' +
                    '<td><strong class="att-label">' + escapeHtml(att.label) + '</strong></td>' +
                    '<td><a href="profile_attachment.php?entity=staff&id=' + encodeURIComponent(att.id) + '" target="_blank" class="text-decoration-none">' +
                        '<i class="fas ' + fileIcon + ' me-1"></i>' + escapeHtml(att.original_name) + '</a></td>' +
                    '<td>' + formatFileSize(att.file_size) + '</td>' +
                    '<td>' + formatDate(att.uploaded_at) + '</td>' +
                    '<td class="actions-column text-center" style="white-space: nowrap;"><div class="d-inline-flex align-items-center justify-content-center gap-1"><button type="button" class="btn btn-action-pills btn-edit att-rename-btn" data-bs-toggle="tooltip" title="تعديل الاسم" ' +
                        'data-attachment-id="' + att.id + '" data-attachment-label="' + escapeHtml(att.label) + '">' +
                        '<i class="fas fa-edit"></i></button>' +
                    '<button type="button" class="btn btn-action-pills btn-delete att-delete-btn" data-bs-toggle="tooltip" title="حذف" ' +
                        'data-attachment-id="' + att.id + '" data-attachment-label="' + escapeHtml(att.label) + '">' +
                        '<i class="fas fa-trash"></i></button></div></td>';
                if (window.bootstrap && bootstrap.Tooltip) {
                    tr.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
                }
            },
            onError: function (msg) {
                errEl.style.display = '';
                errEl.textContent = msg;
                barEl.classList.remove('bg-primary');
                barEl.classList.add('bg-danger');
                barEl.style.width = '100%';
                tr.querySelector('.fa-spinner')?.classList.remove('fa-spin');
                tr.querySelector('.fa-spinner')?.classList.add('fa-exclamation-triangle', 'text-danger');
                // إزالة الصف بعد فترة قصيرة ليعيد المستخدم المحاولة
                setTimeout(function () {
                    if (tr.parentNode) tr.remove();
                    // إن أصبح الجدول فارغاً بالكامل، أعد إظهار رسالة "لا توجد مرفقات"
                    var tbodyNow = document.getElementById('staffAttachmentsTableBody');
                    if (tbodyNow && tbodyNow.querySelectorAll('tr').length === 0) {
                        var wrap = document.getElementById('staffAttachmentsTableWrap');
                        if (wrap) wrap.style.display = 'none';
                        var emptyNow = document.getElementById('staffAttachmentsEmpty');
                        if (emptyNow) emptyNow.style.display = '';
                    }
                }, 4000);
            }
        });
    }

    // حذف المرفق المرفوع حديثاً عبر delegation (آمن من XSS بدل inline onclick)
    var staffAttTbody = document.getElementById('staffAttachmentsTableBody');
    if (staffAttTbody) {
        staffAttTbody.addEventListener('click', function (e) {
            var renameBtn = e.target.closest('.att-rename-btn');
            if (renameBtn) {
                var renameRow = renameBtn.closest('tr');
                var renameId = parseInt(renameBtn.getAttribute('data-attachment-id'), 10) || 0;
                var renameLabel = renameBtn.getAttribute('data-attachment-label') || '';
                if (renameId > 0 && renameRow) {
                    openProfileAttachmentLabelEditor({
                        entityType: 'staff',
                        entityId: staffEntityId,
                        attachmentId: renameId,
                        label: renameLabel,
                        endpoint: 'ajax/upload_attachment.php',
                        showModal: showStaffChildModal,
                        onSuccess: function(data) {
                            var finalLabel = data.attachment?.label || renameLabel;
                            var labelEl = renameRow.querySelector('.att-label');
                            if (labelEl) labelEl.textContent = finalLabel;
                            renameRow.querySelectorAll('.att-rename-btn, .att-delete-btn').forEach(function(button) {
                                button.setAttribute('data-attachment-label', finalLabel);
                            });
                        }
                    });
                }
                return;
            }
            var delBtn = e.target.closest('.att-delete-btn');
            if (!delBtn) return;
            var attId = parseInt(delBtn.getAttribute('data-attachment-id'), 10) || 0;
            var attLabel = delBtn.getAttribute('data-attachment-label') || '';
            if (attId > 0) {
                deleteStaffAttachment(attId, attLabel, delBtn.closest('tr'));
            }
        });
    }

    // رفع الصورة الشخصية للموظف فوراً وتحديث المعاينة
    function uploadStaffProfileImageInstantly(file, entityId) {
        var fileInput = document.getElementById('staff_profile_image_file');
        if (!fileInput) return;

        fileInput.disabled = true;

        uploadAttachmentInstantly({
            file: file,
            entityType: 'staff',
            entityId: entityId,
            label: 'الصورة الشخصية',
            endpoint: 'ajax/upload_attachment.php',
            onProgress: function (pct) {
                // تقدم الرفع
            },
            onSuccess: function (data) {
                fileInput.disabled = false;
                var att = data.attachment || {};
                var downloadUrl = data.url || ('../uploads/staff/' + att.file_name);

                // تحديث المعاينة الفورية
                var imgEl = document.getElementById('current_staff_avatar_img');
                var linkEl = document.getElementById('current_staff_avatar_link');
                var containerEl = document.getElementById('current_staff_avatar_container');
                if (imgEl && linkEl && containerEl) {
                    imgEl.src = downloadUrl;
                    linkEl.href = downloadUrl;
                    containerEl.style.setProperty('display', 'flex', 'important');
                }

                fileInput.value = '';
            },
            onError: function (msg) {
                fileInput.disabled = false;
                alert('خطأ أثناء رفع الصورة الشخصية: ' + msg);
                fileInput.value = '';
            }
        });
    }

    // دالة مساعدة للهروب (آمنة للاستخدام في innerHTML)
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
});

// إظهار حقل الكتابة عند اختيار "أخرى" في أي قائمة منسدلة
document.querySelectorAll('.other-toggle').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var target = document.getElementById(this.dataset.otherTarget);
        if (!target) return;
        var isOther = (this.value === 'other' || this.value === 'أخرى');
        target.style.display = isOther ? 'block' : 'none';
        if (!isOther) target.value = '';
    });
});

// توحيد إدخال الأرقام (رقم قومي / موبايل / أرضي)
function sanitizeDigitsInputsStaff() {
    document.querySelectorAll('.national-id-input, .mobile-input, .landline-input, .extra-phone-input').forEach(function(input) {
        input.addEventListener('input', function() {
            this.value = (this.value || '').replace(/\D+/g, '');
            if (this.classList.contains('national-id-input')) {
                this.value = this.value.slice(0, 14);
            }
            if (this.classList.contains('mobile-input')) {
                this.value = this.value.slice(0, 11);
            }
        });
    });
}
sanitizeDigitsInputsStaff();

function addStaffMobileRow(number = '', note = '') {
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 extra-contact-row';
    row.innerHTML = `
        <div class="col-md-4 col-5">
            <input type="text" class="form-control" name="staff_mobile_notes[]" placeholder="ملاحظة لهذا الرقم" value="${note}">
        </div>
        <div class="col">
            <input type="text" class="form-control extra-phone-input" name="staff_mobile_numbers[]" pattern="[0-9]*" inputmode="numeric" placeholder="رقم الهاتف الإضافي" value="${number}">
        </div>
        <div class="col-auto d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-danger remove-extra-row" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
        </div>`;
    document.getElementById('staffMobilesContainer')?.appendChild(row);
    sanitizeDigitsInputsStaff();
    if (window.bootstrap && bootstrap.Tooltip) { row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
}

function addStaffLandlineRow(number = '', note = '') {
    addStaffMobileRow(number, note);
}

function addAdditionalDataRowStaff(label = '', value = '') {
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 extra-contact-row';
    row.innerHTML = `
        <div class="col-md-4 col-5">
            <input type="text" class="form-control" name="additional_data_labels[]" placeholder="مسمى البيانات" value="${label}">
        </div>
        <div class="col">
            <input type="text" class="form-control" name="additional_data_values[]" placeholder="بيانها" value="${value}">
        </div>
        <div class="col-auto d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-danger remove-extra-row" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
        </div>`;
    document.getElementById('additionalDataContainer')?.appendChild(row);
    if (window.bootstrap && bootstrap.Tooltip) { row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
}

function addEmploymentExtraDataRowStaff(label = '', value = '') {
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 extra-contact-row';
    row.innerHTML = `
        <div class="col-md-4 col-5">
            <input type="text" class="form-control" name="additional_employment_data_labels[]" placeholder="مسمى البيانات" value="${label}">
        </div>
        <div class="col">
            <input type="text" class="form-control" name="additional_employment_data_values[]" placeholder="بيانها" value="${value}">
        </div>
        <div class="col-auto d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-danger remove-extra-row" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
        </div>`;
    document.getElementById('employmentExtraDataContainer')?.appendChild(row);
    if (window.bootstrap && bootstrap.Tooltip) { row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
}



document.getElementById('addStaffMobileBtn')?.addEventListener('click', function() { addStaffMobileRow(); });
document.getElementById('addStaffLandlineBtn')?.addEventListener('click', function() { addStaffLandlineRow(); });
document.getElementById('addAdditionalDataBtn')?.addEventListener('click', function() { addAdditionalDataRowStaff(); });
document.getElementById('addEmploymentExtraDataBtn')?.addEventListener('click', function() { addEmploymentExtraDataRowStaff(); });

<?php if ($action === 'edit' && $staffProfile): ?>
// تعبئة مسبقة للأرقام والبيانات الإضافية المحفوظة
<?php foreach ($editExtraPhones as $ph): ?><?php if (($ph['type'] ?? '') === 'mobile'): ?>
addStaffMobileRow(<?php echo json_encode($ph['number'] ?? ''); ?>, <?php echo json_encode($ph['note'] ?? ''); ?>);
<?php else: ?>addStaffLandlineRow(<?php echo json_encode($ph['number'] ?? ''); ?>, <?php echo json_encode($ph['note'] ?? ''); ?>);
<?php endif; ?><?php endforeach; ?>
<?php foreach ($editExtraData as $item): ?>
addAdditionalDataRowStaff(<?php echo json_encode($item['label'] ?? ''); ?>, <?php echo json_encode($item['value'] ?? ''); ?>);
<?php endforeach; ?>
<?php foreach ($editExtraEmploymentData as $item): ?>
addEmploymentExtraDataRowStaff(<?php echo json_encode($item['label'] ?? ''); ?>, <?php echo json_encode($item['value'] ?? ''); ?>);
<?php endforeach; ?>
<?php endif; ?>

document.addEventListener('click', function(e) {
    var removeBtn = e.target.closest('.remove-extra-row');
    if (removeBtn) {
        confirmStaffInlineDelete('هل تريد حذف هذا السطر؟', function() {
            removeBtn.closest('.extra-contact-row')?.remove();
        });
        return;
    }
});

document.getElementById('departments_select')?.addEventListener('change', function() {
    var otherInput = document.getElementById('departments_other_input');
    if (!otherInput) {
        return;
    }
    var selectedValues = Array.from(this.selectedOptions).map(function(option) { return option.value; });
    var showOther = selectedValues.includes('أخرى');
    otherInput.style.display = showOther ? 'block' : 'none';
    if (!showOther) {
        otherInput.value = '';
    }
});

// === مزامنة الاسم الخماسي إلى حقول الاسم الكاملة ===
var fullNameArInput = document.getElementById('full_name_ar_input');
var fullNameEnInput = document.getElementById('full_name_en_input');
var hiddenNameInput = document.getElementById('name');
var namePartsArTouchedInput = document.getElementById('name_parts_ar_touched');
var namePartsEnTouchedInput = document.getElementById('name_parts_en_touched');
var arabicNamePartsInputs = document.querySelectorAll('.staff-name-part-ar');
var englishNamePartsInputs = document.querySelectorAll('.staff-name-part-en');

function joinStaffNameParts(inputs) {
    return Array.from(inputs).map(function(input) {
        return (input.value || '').trim();
    }).filter(Boolean).join(' ');
}

function syncStaffFullNames() {
    var arabicFullName = joinStaffNameParts(arabicNamePartsInputs);
    var englishFullName = joinStaffNameParts(englishNamePartsInputs);

    if (fullNameArInput) {
        fullNameArInput.value = arabicFullName;
    }
    if (fullNameEnInput) {
        fullNameEnInput.value = englishFullName;
    }
    if (hiddenNameInput) {
        hiddenNameInput.value = arabicFullName;
    }
}

arabicNamePartsInputs.forEach(function(input) {
    input.addEventListener('input', function() {
        if (namePartsArTouchedInput) {
            namePartsArTouchedInput.value = '1';
        }
        syncStaffFullNames();
    });
});
englishNamePartsInputs.forEach(function(input) {
    input.addEventListener('input', function() {
        if (namePartsEnTouchedInput) {
            namePartsEnTouchedInput.value = '1';
        }
        syncStaffFullNames();
    });
});

// === حساب العمر الحالي للموظف ===
function calculateStaffAge() {
    const bd = document.getElementById('staff_birth_date')?.value;
    const display = document.getElementById('staff_age_display');
    if (!bd || !display) return;
    const parts = bd.split('-');
    if (parts.length !== 3) return;
    const birth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    const now = new Date();
    if (birth >= now) { display.value = 'تاريخ الميلاد غير صالح'; return; }

    let years = now.getFullYear() - birth.getFullYear();
    let months = now.getMonth() - birth.getMonth();
    let days = now.getDate() - birth.getDate();
    if (days < 0) {
        months--;
        const prevMonthLastDay = new Date(now.getFullYear(), now.getMonth(), 0).getDate();
        days += prevMonthLastDay;
    }
    if (months < 0) {
        years--;
        months += 12;
    }
    display.value = years + ' سنة و ' + months + ' شهر و ' + days + ' يوم';
}
calculateStaffAge();


// === المؤهلات الدراسية والشهادات العلمية الأخرى ===
var otherQualificationsData = [];
try {
    var rawQuals = document.getElementById('other_qualifications_data')?.value || '[]';
    otherQualificationsData = JSON.parse(rawQuals);
} catch(e) {
    var oldText = document.getElementById('other_qualifications_data')?.value || '';
    if (oldText && !oldText.startsWith('[')) {
        otherQualificationsData = [{qualification: oldText, date: '', school: ''}];
    } else {
        otherQualificationsData = [];
    }
}
if (!Array.isArray(otherQualificationsData)) {
    otherQualificationsData = [];
}

function renderOtherQualifications() {
    var container = document.getElementById('other_qualifications_container');
    if (!container) return;
    container.innerHTML = '';
    otherQualificationsData.forEach(function(item, idx) {
        var card = document.createElement('div');
        card.className = 'row g-2 align-items-center mb-2 other-qualification-row';
        card.innerHTML =
            '<div class="col">' +
            '<input type="text" class="form-control form-control-sm oq-qual" value="' + (item.qualification||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="المؤهل الدراسي (مثال: دبلوم تربوي، ماجستير...)"></div>' +
            '<div class="col-md-4">' +
            '<input type="text" class="form-control form-control-sm oq-school" value="' + (item.school||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="الجهة المانحة (مثال: جامعة القاهرة...)"></div>' +
            '<div class="col-md-2">' +
            '<input type="text" class="form-control form-control-sm oq-date" value="' + (item.date||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="تاريخ الحصول عليه (مثال: 2024...)"></div>' +
            '<div class="col-auto d-flex align-items-center">' +
            '<button type="button" class="btn btn-sm btn-danger oq-remove" data-idx="' + idx + '" title="حذف"><i class="fas fa-trash"></i></button></div>';
        container.appendChild(card);
    });
    syncOtherQualificationsData();
}

function syncOtherQualificationsData() {
    var el = document.getElementById('other_qualifications_data');
    if (el) {
        el.value = JSON.stringify(otherQualificationsData.map(function(item) {
            return {
                qualification: item.qualification || '',
                date: item.date || '',
                school: item.school || ''
            };
        }));
    }
}

document.getElementById('add_other_qualification_btn')?.addEventListener('click', function() {
    otherQualificationsData.push({qualification: '', date: '', school: ''});
    renderOtherQualifications();
});

document.getElementById('other_qualifications_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !otherQualificationsData[idx]) return;
    if (e.target.classList.contains('oq-qual')) otherQualificationsData[idx].qualification = e.target.value;
    if (e.target.classList.contains('oq-school')) otherQualificationsData[idx].school = e.target.value;
    if (e.target.classList.contains('oq-date')) otherQualificationsData[idx].date = e.target.value;
    syncOtherQualificationsData();
});

document.getElementById('other_qualifications_container')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.oq-remove');
    if (!btn) return;
    confirmStaffInlineDelete('هل تريد حذف سجل المؤهل الدراسي هذا؟', function() {
        otherQualificationsData.splice(parseInt(btn.dataset.idx), 1);
        renderOtherQualifications();
    });
});

renderOtherQualifications();

// === الدورات التدريبية والشهادات العلمية ===
var trainingCoursesData = [];
try {
    var rawCourses = document.getElementById('training_courses_data')?.value || '[]';
    trainingCoursesData = JSON.parse(rawCourses);
} catch(e) {
    var oldText = document.getElementById('training_courses_data')?.value || '';
    if (oldText && !oldText.startsWith('[')) {
        trainingCoursesData = oldText.split('\n').map(function(line) {
            return {course: line.trim(), date: ''};
        }).filter(function(item) { return item.course !== ''; });
    } else {
        trainingCoursesData = [];
    }
}
if (!Array.isArray(trainingCoursesData)) {
    trainingCoursesData = [];
}

function renderTrainingCourses() {
    var container = document.getElementById('training_courses_container');
    if (!container) return;
    container.innerHTML = '';
    trainingCoursesData.forEach(function(item, idx) {
        var card = document.createElement('div');
        card.className = 'row g-2 align-items-center mb-2 training-course-row';
        card.innerHTML =
            '<div class="col">' +
            '<input type="text" class="form-control form-control-sm tc-course" value="' + (item.course||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="الدورة التدريبية / الشهادة (مثال: دورة أساسيات التدريس...)"></div>' +
            '<div class="col-md-3">' +
            '<input type="text" class="form-control form-control-sm tc-date" value="' + (item.date||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="تاريخ الحصول عليها (مثال: 2025/01/01...)"></div>' +
            '<div class="col-auto d-flex align-items-center">' +
            '<button type="button" class="btn btn-sm btn-danger tc-remove" data-idx="' + idx + '" title="حذف"><i class="fas fa-trash"></i></button></div>';
        container.appendChild(card);
    });
    syncTrainingCoursesData();
}

function syncTrainingCoursesData() {
    var el = document.getElementById('training_courses_data');
    if (el) {
        el.value = JSON.stringify(trainingCoursesData.map(function(item) {
            return {
                course: item.course || '',
                date: item.date || ''
            };
        }));
    }
}

document.getElementById('add_training_course_btn')?.addEventListener('click', function() {
    trainingCoursesData.push({course: '', date: ''});
    renderTrainingCourses();
});

document.getElementById('training_courses_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !trainingCoursesData[idx]) return;
    if (e.target.classList.contains('tc-course')) trainingCoursesData[idx].course = e.target.value;
    if (e.target.classList.contains('tc-date')) trainingCoursesData[idx].date = e.target.value;
    syncTrainingCoursesData();
});

document.getElementById('training_courses_container')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.tc-remove');
    if (!btn) return;
    confirmStaffInlineDelete('هل تريد حذف سجل الدورة التدريبية هذا؟', function() {
        trainingCoursesData.splice(parseInt(btn.dataset.idx), 1);
        renderTrainingCourses();
    });
});

renderTrainingCourses();

// === الخبرات وأماكن العمل السابقة ===
var workHistoryData = [];
try { workHistoryData = JSON.parse(document.getElementById('work_history_data')?.value || '[]'); } catch(e) { workHistoryData = []; }
if (!Array.isArray(workHistoryData)) {
    workHistoryData = [];
}

function renderWorkHistory() {
    var container = document.getElementById('work_history_container');
    if (!container) return;
    container.innerHTML = '';
    workHistoryData.forEach(function(item, idx) {
        var duration = '';
        if (item.start_date && item.end_date) {
            var d1 = new Date(item.start_date);
            var d2 = new Date(item.end_date);
            if (d2 >= d1) {
                var years = d2.getFullYear() - d1.getFullYear();
                var months = d2.getMonth() - d1.getMonth();
                var days = d2.getDate() - d1.getDate();

                if (days < 0) {
                    months--;
                    var prevMonth = new Date(d2.getFullYear(), d2.getMonth(), 0);
                    days += prevMonth.getDate();
                }
                if (months < 0) {
                    years--;
                    months += 12;
                }

                var parts = [];
                if (years > 0) parts.push(years + ' سنة');
                if (months > 0) parts.push(months + ' شهر');
                if (days > 0) parts.push(days + ' يوم');

                duration = parts.join(' و ');
                if (!duration) duration = '0 يوم';
            } else {
                duration = 'تاريخ غير صالح';
            }
        }

        var typeStart = item.start_date ? 'date' : 'text';
        var typeEnd = item.end_date ? 'date' : 'text';

        var card = document.createElement('div');
        card.className = 'row g-2 align-items-center mb-2 work-history-row';
        card.innerHTML =
            '<div class="col">' +
            '<input type="text" class="form-control form-control-sm wh-name" value="' + (item.name||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="مكان العمل (اسم الجهة أو المدرسة السابقة)"></div>' +
            '<div class="col-md-2" title="تاريخ الالتحاق">' +
            '<input type="' + typeStart + '" class="form-control form-control-sm wh-start" value="' + (item.start_date||'') + '" data-idx="' + idx + '" placeholder="تاريخ الالتحاق" onfocus="this.type=\'date\'" onblur="if(!this.value)this.type=\'text\'"></div>' +
            '<div class="col-md-2" title="تاريخ المغادرة">' +
            '<input type="' + typeEnd + '" class="form-control form-control-sm wh-end" value="' + (item.end_date||'') + '" data-idx="' + idx + '" placeholder="تاريخ المغادرة" onfocus="this.type=\'date\'" onblur="if(!this.value)this.type=\'text\'"></div>' +
            '<div class="col-md-3">' +
            '<input type="text" class="form-control form-control-sm text-center fw-bold text-primary bg-white" readonly placeholder="المدة المحسوبة" value="' + (duration || '') + '" title="المدة"></div>' +
            '<div class="col-auto d-flex align-items-center">' +
            '<button type="button" class="btn btn-sm btn-danger wh-remove" data-idx="' + idx + '" title="حذف"><i class="fas fa-trash"></i></button></div>';
        container.appendChild(card);
    });
    syncWorkHistoryData();
}

function syncWorkHistoryData() {
    var el = document.getElementById('work_history_data');
    if (el) {
        el.value = JSON.stringify(workHistoryData.map(function(item) {
            return {
                name: item.name || '',
                start_date: item.start_date || '',
                end_date: item.end_date || ''
            };
        }));
    }
}

document.getElementById('add_work_history')?.addEventListener('click', function() {
    workHistoryData.push({name: '', start_date: '', end_date: ''});
    renderWorkHistory();
});

document.getElementById('work_history_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !workHistoryData[idx]) return;
    if (e.target.classList.contains('wh-name')) workHistoryData[idx].name = e.target.value;
    if (e.target.classList.contains('wh-start')) workHistoryData[idx].start_date = e.target.value;
    if (e.target.classList.contains('wh-end')) workHistoryData[idx].end_date = e.target.value;
    syncWorkHistoryData();
});

document.getElementById('work_history_container')?.addEventListener('change', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !workHistoryData[idx]) return;
    if (e.target.classList.contains('wh-start') || e.target.classList.contains('wh-end')) {
        if (e.target.classList.contains('wh-start')) workHistoryData[idx].start_date = e.target.value;
        if (e.target.classList.contains('wh-end')) workHistoryData[idx].end_date = e.target.value;
        syncWorkHistoryData();
        renderWorkHistory();
    }
});

document.getElementById('work_history_container')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.wh-remove');
    if (!btn) return;
    confirmStaffInlineDelete('هل تريد حذف سجل مكان العمل هذا؟', function() {
        workHistoryData.splice(parseInt(btn.dataset.idx), 1);
        renderWorkHistory();
    });
});

renderWorkHistory();

// === نظام الترقيات والتدرج الوظيفي الديناميكي ===
var promotionsData = [];
try {
    var rawPromotions = document.getElementById('promotions_data')?.value || '[]';
    promotionsData = JSON.parse(rawPromotions);
} catch(e) {
    promotionsData = [];
}
if (!Array.isArray(promotionsData)) {
    promotionsData = [];
}

function renderPromotions() {
    var container = document.getElementById('promotions_container');
    if (!container) return;
    container.innerHTML = '';
    promotionsData.forEach(function(item, idx) {
        var card = document.createElement('div');
        card.className = 'card mb-3 border border-1 p-3 bg-light rounded promotion-row';
        var storedMovementType = item.type || item.movement_type || '';
        var standardMovementTypes = ['ترقية', 'تسوية', 'نقل درجة', 'تعديل مسمى', 'ندب', 'نقل قسم', 'تغيير نوع تعاقد', 'تجديد عقد'];
        var typeIsOther = storedMovementType === 'أخرى' || (storedMovementType && !standardMovementTypes.includes(storedMovementType));
        var movementType = typeIsOther ? 'أخرى' : storedMovementType;
        var customMovementType = item.type_custom || (typeIsOther && storedMovementType !== 'أخرى' ? storedMovementType : '');
        var hasAdvancedData = !!(
            item.previous_job_title || item.previous_job_grade || item.new_job_grade ||
            item.previous_department || item.new_department ||
            item.previous_contract_type || item.new_contract_type || item.notes
        );

        card.innerHTML =
            '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                '<span class="fw-bold text-secondary small"><i class="fas fa-level-up-alt me-1"></i>حركة وظيفية رقم ' + (idx + 1) + '</span>' +
                '<button type="button" class="btn btn-sm btn-danger promo-remove" data-idx="' + idx + '" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>' +
            '</div>' +
            '<div class="row g-2">' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">نوع الحركة الوظيفية</label>' +
                    '<select class="form-select form-select-sm promo-type" data-idx="' + idx + '">' +
                        '<option value="">-- اختر --</option>' +
                        '<option value="ترقية" ' + (movementType === 'ترقية' ? 'selected' : '') + '>ترقية</option>' +
                        '<option value="تسوية" ' + (movementType === 'تسوية' ? 'selected' : '') + '>تسوية</option>' +
                        '<option value="نقل درجة" ' + (movementType === 'نقل درجة' ? 'selected' : '') + '>نقل درجة</option>' +
                        '<option value="تعديل مسمى" ' + (movementType === 'تعديل مسمى' ? 'selected' : '') + '>تعديل مسمى</option>' +
                        '<option value="ندب" ' + (movementType === 'ندب' ? 'selected' : '') + '>ندب</option>' +
                        '<option value="نقل قسم" ' + (movementType === 'نقل قسم' ? 'selected' : '') + '>نقل قسم</option>' +
                        '<option value="تغيير نوع تعاقد" ' + (movementType === 'تغيير نوع تعاقد' ? 'selected' : '') + '>تغيير نوع تعاقد</option>' +
                        '<option value="تجديد عقد" ' + (movementType === 'تجديد عقد' ? 'selected' : '') + '>تجديد عقد</option>' +
                        '<option value="أخرى" ' + (typeIsOther ? 'selected' : '') + '>أخرى (تسجيل يدوي)</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-md-3 promo-type-other-col" style="display: ' + (typeIsOther ? 'block' : 'none') + ';">' +
                    '<label class="form-label small fw-bold">نوع الحركة البديل</label>' +
                    '<input type="text" class="form-control form-control-sm promo-type-custom" value="' + (customMovementType||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="اكتب نوع الحركة">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">المسمى السابق</label>' +
                    '<input type="text" class="form-control form-control-sm promo-prev-title" value="' + (item.previous_job_title||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="المسمى السابق">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">المسمى الجديد</label>' +
                    '<input type="text" class="form-control form-control-sm promo-new-title" value="' + (item.new_job_title||item.new_title||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="المسمى الجديد">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">الدرجة السابقة</label>' +
                    '<input type="text" class="form-control form-control-sm promo-prev-grade" value="' + (item.previous_job_grade||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="الدرجة السابقة">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">الدرجة الجديدة</label>' +
                    '<input type="text" class="form-control form-control-sm promo-new-grade" value="' + (item.new_job_grade||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="الدرجة الجديدة">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">القوة/القسم السابق</label>' +
                    '<input type="text" class="form-control form-control-sm promo-prev-dept" value="' + (item.previous_department||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="القسم السابق">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">القوة/القسم الجديد</label>' +
                    '<input type="text" class="form-control form-control-sm promo-new-dept" value="' + (item.new_department||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="القسم الجديد">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">نوع التعاقد السابق</label>' +
                    '<input type="text" class="form-control form-control-sm promo-prev-contract" value="' + (item.previous_contract_type||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="نوع التعاقد السابق">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">نوع التعاقد الجديد</label>' +
                    '<input type="text" class="form-control form-control-sm promo-new-contract" value="' + (item.new_contract_type||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="نوع التعاقد الجديد">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">تاريخ القرار</label>' +
                    '<input type="text" class="form-control form-control-sm promo-decision-date flatpickr-date" value="' + (item.decision_date||'') + '" data-idx="' + idx + '" placeholder="اختر التاريخ...">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">تاريخ السريان</label>' +
                    '<input type="text" class="form-control form-control-sm promo-effective-date flatpickr-date" value="' + (item.effective_date||'') + '" data-idx="' + idx + '" placeholder="اختر التاريخ...">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">رقم القرار</label>' +
                    '<input type="text" class="form-control form-control-sm promo-decision-no" value="' + (item.decision_no||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="رقم القرار">' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label class="form-label small fw-bold">الجهة/الإدارة المصدرة للقرار</label>' +
                    '<input type="text" class="form-control form-control-sm promo-issuer" value="' + (item.issuer||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="الجهة/الإدارة المصدرة للقرار">' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label class="form-label small fw-bold">سبب الترقية</label>' +
                    '<input type="text" class="form-control form-control-sm promo-reason" value="' + (item.reason||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="سبب الحركة الوظيفية">' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label class="form-label small fw-bold">ملاحظات</label>' +
                    '<input type="text" class="form-control form-control-sm promo-notes" value="' + (item.notes||'').replace(/"/g,'&quot;') + '" data-idx="' + idx + '" placeholder="ملاحظات وتفاصيل إضافية">' +
                '</div>' +
            '</div>';
        container.appendChild(card);
        // تهيئة Air Datepicker على حقول التاريخ المُحقنة ديناميكياً (الترقيات)
        if (typeof initAirDatepickers === 'function') {
            initAirDatepickers(card);
        }
        var advancedInputs = card.querySelectorAll('.promo-prev-title, .promo-prev-grade, .promo-new-grade, .promo-prev-dept, .promo-new-dept, .promo-prev-contract, .promo-new-contract, .promo-notes');
        var advancedRows = [];
        advancedInputs.forEach(function(input) {
            var fieldCol = input.closest('[class*="col-md-"]');
            if (fieldCol) {
                fieldCol.classList.add('promo-advanced-field');
                if (!hasAdvancedData) {
                    fieldCol.classList.add('d-none');
                }
                advancedRows.push(fieldCol);
            }
        });
        var advancedToggleWrap = document.createElement('div');
        advancedToggleWrap.className = 'col-12 mt-1';
        advancedToggleWrap.innerHTML = '<button type="button" class="btn btn-outline-secondary btn-sm promo-advanced-toggle"><i class="fas fa-sliders-h me-1"></i>تفاصيل متقدمة</button>';
        var row = card.querySelector('.row.g-2');
        if (row && advancedRows.length > 0) {
            row.appendChild(advancedToggleWrap);
            advancedToggleWrap.querySelector('.promo-advanced-toggle').addEventListener('click', function() {
                advancedRows.forEach(function(col) { col.classList.toggle('d-none'); });
            });
        }
        if (window.bootstrap && bootstrap.Tooltip) { card.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
    });
    syncPromotionsData();
}

function syncPromotionsData() {
    var el = document.getElementById('promotions_data');
    if (el) {
        el.value = JSON.stringify(promotionsData.map(function(item) {
            return {
                movement_type: item.movement_type || item.type || '',
                type: item.type || item.movement_type || '',
                type_custom: item.type_custom || '',
                previous_job_title: item.previous_job_title || '',
                new_job_title: item.new_job_title || item.new_title || '',
                previous_job_grade: item.previous_job_grade || '',
                new_job_grade: item.new_job_grade || '',
                previous_department: item.previous_department || '',
                new_department: item.new_department || '',
                previous_contract_type: item.previous_contract_type || '',
                new_contract_type: item.new_contract_type || '',
                decision_date: item.decision_date || '',
                effective_date: item.effective_date || '',
                decision_no: item.decision_no || '',
                issuer: item.issuer || '',
                reason: item.reason || '',
                notes: item.notes || ''
            };
        }));
    }
}

document.getElementById('add_promotion_btn')?.addEventListener('click', function() {
    promotionsData.push({
        movement_type: '',
        type: '',
        type_custom: '',
        previous_job_title: '',
        new_job_title: '',
        previous_job_grade: '',
        new_job_grade: '',
        previous_department: '',
        new_department: '',
        previous_contract_type: '',
        new_contract_type: '',
        decision_date: '',
        effective_date: '',
        decision_no: '',
        issuer: '',
        reason: '',
        notes: ''
    });
    renderPromotions();
});

document.getElementById('promotions_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !promotionsData[idx]) return;
    if (e.target.classList.contains('promo-type-custom')) promotionsData[idx].type_custom = e.target.value;
    if (e.target.classList.contains('promo-prev-title')) promotionsData[idx].previous_job_title = e.target.value;
    if (e.target.classList.contains('promo-new-title')) {
        promotionsData[idx].new_job_title = e.target.value;
        promotionsData[idx].new_title = e.target.value;
    }
    if (e.target.classList.contains('promo-prev-grade')) promotionsData[idx].previous_job_grade = e.target.value;
    if (e.target.classList.contains('promo-new-grade')) promotionsData[idx].new_job_grade = e.target.value;
    if (e.target.classList.contains('promo-prev-dept')) promotionsData[idx].previous_department = e.target.value;
    if (e.target.classList.contains('promo-new-dept')) promotionsData[idx].new_department = e.target.value;
    if (e.target.classList.contains('promo-prev-contract')) promotionsData[idx].previous_contract_type = e.target.value;
    if (e.target.classList.contains('promo-new-contract')) promotionsData[idx].new_contract_type = e.target.value;
    if (e.target.classList.contains('promo-decision-no')) promotionsData[idx].decision_no = e.target.value;
    if (e.target.classList.contains('promo-issuer')) promotionsData[idx].issuer = e.target.value;
    if (e.target.classList.contains('promo-reason')) promotionsData[idx].reason = e.target.value;
    if (e.target.classList.contains('promo-notes')) promotionsData[idx].notes = e.target.value;
    syncPromotionsData();
});

document.getElementById('promotions_container')?.addEventListener('change', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !promotionsData[idx]) return;
    if (e.target.classList.contains('promo-type')) {
        promotionsData[idx].type = e.target.value;
        promotionsData[idx].movement_type = e.target.value;
        if (e.target.value !== 'أخرى') {
            promotionsData[idx].type_custom = '';
        }
        syncPromotionsData();
        renderPromotions();
    }
    if (e.target.classList.contains('promo-decision-date')) {
        promotionsData[idx].decision_date = e.target.value;
        syncPromotionsData();
    }
    if (e.target.classList.contains('promo-effective-date')) {
        promotionsData[idx].effective_date = e.target.value;
        syncPromotionsData();
    }
});

document.getElementById('promotions_container')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.promo-remove');
    if (!btn) return;
    confirmStaffInlineDelete('هل تريد حذف سجل الحركة الوظيفية هذا؟', function() {
        promotionsData.splice(parseInt(btn.dataset.idx), 1);
        renderPromotions();
    });
});

renderPromotions();

// === نظام حالة الموظف الديناميكي ===
var statusHistoryData = [];
try {
    var rawStatusHistory = document.getElementById('status_history_data')?.value || '[]';
    statusHistoryData = JSON.parse(rawStatusHistory);
} catch(e) {
    statusHistoryData = [];
}
if (!Array.isArray(statusHistoryData)) {
    statusHistoryData = [];
}
if (statusHistoryData.length === 0) {
    statusHistoryData.push({
        movement_type: 'تعيين / بداية عمل',
        status_after: '<?php echo htmlspecialchars($sp['current_work_status'] ?? 'on_duty'); ?>',
        status_label: 'على رأس العمل',
        status: 'على رأس العمل',
        status_custom: '',
        effective_date: '<?php echo htmlspecialchars($sp['hire_date'] ?? ''); ?>',
        decision_no: '',
        decision_date: '',
        issuer: '',
        contract_type: '<?php echo htmlspecialchars($contractLabels[$sp['contract_type'] ?? ''] ?? ($sp['contract_type'] ?? '')); ?>',
        contract_type_custom: '',
        contract_start: '<?php echo htmlspecialchars($sp['hire_date'] ?? ''); ?>',
        contract_end: '',
        job_title: '<?php echo htmlspecialchars($sp['job_title'] ?? ''); ?>',
        job_title_custom: '',
        job_grade: '<?php echo htmlspecialchars($sp['job_grade'] ?? ''); ?>',
        department: '<?php echo htmlspecialchars($sp['department'] ?? ''); ?>',
        department_custom: '',
        last_working_day: '',
        can_rehire: '',
        rehire: '',
        status_reason: 'تسجيل أولي معتمد من بيانات الموظف',
        reason: '',
        notes: ''
    });
}

function renderStatusHistory() {
    var container = document.getElementById('status_history_container');
    if (!container) return;
    container.innerHTML = '';
    var movementTypes = ['تعيين / بداية عمل', 'خروج مؤقت', 'إنهاء خدمة', 'استقالة', 'انتهاء عقد', 'عودة للعمل', 'إعادة تعيين', 'أخرى'];
    var exitMovementTypes = ['خروج مؤقت', 'إنهاء خدمة', 'استقالة', 'انتهاء عقد'];
    var contractTypes = ['دائم', 'مؤقت', 'جزئي', 'أخرى'];
    var statusJobTitles = <?php echo json_encode($jobTitles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var statusDepartments = <?php echo json_encode(array_merge($departments, ['أخرى']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    function statusEscape(value) {
        return String(value || '').replace(/"/g, '&quot;');
    }
    function statusOptions(options, selectedValue) {
        return options.map(function(option) {
            return '<option value="' + statusEscape(option) + '" ' + (selectedValue === option ? 'selected' : '') + '>' + option + '</option>';
        }).join('');
    }
    statusHistoryData.forEach(function(item, idx) {
        var card = document.createElement('div');
        card.className = 'card mb-3 border border-1 p-3 bg-light rounded status-row';
        var storedMovementType = item.movement_type || '';
        var movementIsOther = storedMovementType && !movementTypes.includes(storedMovementType);
        var movementType = movementIsOther ? 'أخرى' : storedMovementType;
        var customMovementType = item.movement_type_custom || (movementIsOther ? storedMovementType : '');
        var statusAfter = item.status_after || (exitMovementTypes.includes(storedMovementType) ? 'off_duty' : 'on_duty');
        var rehireValue = item.rehire || item.can_rehire || '';
        if (rehireValue === 1 || rehireValue === '1') rehireValue = 'نعم';
        if (rehireValue === 0 || rehireValue === '0') rehireValue = 'لا';
        var rawContractType = item.contract_type || '';
        if (rawContractType === 'permanent') rawContractType = 'دائم';
        if (rawContractType === 'temporary') rawContractType = 'مؤقت';
        if (rawContractType === 'parttime') rawContractType = 'جزئي';
        if (rawContractType === 'other') rawContractType = 'أخرى';
        var contractIsOther = rawContractType && !contractTypes.includes(rawContractType);
        var contractType = contractIsOther ? 'أخرى' : rawContractType;
        var customContractType = item.contract_type_custom || (contractIsOther ? rawContractType : '');
        var rawJobTitle = item.job_title || '';
        var jobTitleIsOther = rawJobTitle && !statusJobTitles.includes(rawJobTitle);
        var jobTitle = jobTitleIsOther ? 'أخرى' : rawJobTitle;
        var customJobTitle = item.job_title_custom || (jobTitleIsOther ? rawJobTitle : '');
        var rawDepartment = item.department || '';
        var departmentIsOther = rawDepartment && !statusDepartments.includes(rawDepartment);
        var department = departmentIsOther ? 'أخرى' : rawDepartment;
        var customDepartment = item.department_custom || (departmentIsOther ? rawDepartment : '');
        var showRehire = exitMovementTypes.includes(storedMovementType);
        var cardTitle = idx === 0 ? 'الحالة الأساسية' : 'حالة إضافية رقم ' + (idx + 1);
        var removeButton = idx === 0 ? '' : '<button type="button" class="btn btn-sm btn-danger status-remove" data-idx="' + idx + '" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>';

        card.innerHTML =
            '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                '<span class="fw-bold text-secondary small"><i class="fas fa-history me-1"></i>' + cardTitle + '</span>' +
                removeButton +
            '</div>' +
            '<div class="row g-2">' +
                '<div class="col-md-2">' +
                    '<label class="form-label small fw-bold">نوع الحركة</label>' +
                    '<select class="form-select form-select-sm status-movement-type" data-idx="' + idx + '">' +
                        '<option value="">-- اختر --</option>' +
                        statusOptions(movementTypes, movementType) +
                    '</select>' +
                    '<input type="text" class="form-control form-control-sm status-movement-custom mt-2" value="' + statusEscape(customMovementType) + '" data-idx="' + idx + '" placeholder="اكتب نوع الحركة" style="display: ' + (movementType === 'أخرى' ? 'block' : 'none') + ';">' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<label class="form-label small fw-bold">الحالة بعد الحركة</label>' +
                    '<select class="form-select form-select-sm status-after" data-idx="' + idx + '">' +
                        '<option value="on_duty" ' + (statusAfter === 'on_duty' ? 'selected' : '') + '>على رأس العمل</option>' +
                        '<option value="off_duty" ' + (statusAfter === 'off_duty' ? 'selected' : '') + '>ليس على رأس العمل</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<label class="form-label small fw-bold">تاريخ الحالة</label>' +
                    '<input type="text" class="form-control form-control-sm status-effective-date flatpickr-date" value="' + (item.effective_date||'') + '" data-idx="' + idx + '" placeholder="اختر التاريخ...">' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<label class="form-label small fw-bold">رقم القرار</label>' +
                    '<input type="text" class="form-control form-control-sm status-decision-no" value="' + statusEscape(item.decision_no) + '" data-idx="' + idx + '" placeholder="رقم القرار">' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<label class="form-label small fw-bold">تاريخ القرار</label>' +
                    '<input type="text" class="form-control form-control-sm status-decision-date flatpickr-date" value="' + (item.decision_date||'') + '" data-idx="' + idx + '" placeholder="اختر التاريخ...">' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<label class="form-label small fw-bold">الجهة / الإدارة المصدرة للقرار</label>' +
                    '<input type="text" class="form-control form-control-sm status-issuer" value="' + statusEscape(item.issuer) + '" data-idx="' + idx + '" placeholder="الجهة / الإدارة">' +
                '</div>' +
                '<div class="col-md">' +
                    '<label class="form-label small fw-bold">نوع التعاقد</label>' +
                    '<select class="form-select form-select-sm status-contract-type" data-idx="' + idx + '">' +
                        '<option value="">-- اختر --</option>' +
                        statusOptions(contractTypes, contractType) +
                    '</select>' +
                    '<input type="text" class="form-control form-control-sm status-contract-custom mt-2" value="' + statusEscape(customContractType) + '" data-idx="' + idx + '" placeholder="حدد نوع التعاقد" style="display: ' + (contractType === 'أخرى' ? 'block' : 'none') + ';">' +
                '</div>' +
                '<div class="col-md">' +
                    '<label class="form-label small fw-bold">تاريخ بداية التعاقد</label>' +
                    '<input type="text" class="form-control form-control-sm status-contract-start flatpickr-date" value="' + (item.contract_start||'') + '" data-idx="' + idx + '" placeholder="اختر التاريخ...">' +
                '</div>' +
                '<div class="col-md">' +
                    '<label class="form-label small fw-bold">تاريخ نهاية التعاقد</label>' +
                    '<input type="text" class="form-control form-control-sm status-contract-end flatpickr-date" value="' + (item.contract_end||'') + '" data-idx="' + idx + '" placeholder="اختر التاريخ...">' +
                '</div>' +
                '<div class="col-md">' +
                    '<label class="form-label small fw-bold">المسمى الوظيفي</label>' +
                    '<select class="form-select form-select-sm status-job-title" data-idx="' + idx + '">' +
                        '<option value="">-- اختر --</option>' +
                        statusOptions(statusJobTitles, jobTitle) +
                    '</select>' +
                '</div>' +
                '<div class="col-md status-job-title-other-col" style="display: ' + (jobTitle === 'أخرى' ? 'block' : 'none') + ';">' +
                    '<label class="form-label small fw-bold">مسمى آخر</label>' +
                    '<input type="text" class="form-control form-control-sm status-job-title-custom" value="' + statusEscape(customJobTitle) + '" data-idx="' + idx + '" placeholder="حدد المسمى">' +
                '</div>' +
                '<div class="col-md">' +
                    '<label class="form-label small fw-bold">الدرجة الوظيفية</label>' +
                    '<input type="text" class="form-control form-control-sm status-job-grade" value="' + statusEscape(item.job_grade) + '" data-idx="' + idx + '" placeholder="الدرجة">' +
                '</div>' +
                '<div class="w-100"></div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">القوة التابع لها</label>' +
                    '<select class="form-select form-select-sm status-department" data-idx="' + idx + '">' +
                        '<option value="">-- اختر --</option>' +
                        statusOptions(statusDepartments, department) +
                    '</select>' +
                '</div>' +
                '<div class="col-md-3 status-department-other-col" style="display: ' + (department === 'أخرى' ? 'block' : 'none') + ';">' +
                    '<label class="form-label small fw-bold">قوة أخرى</label>' +
                    '<input type="text" class="form-control form-control-sm status-department-custom" value="' + statusEscape(customDepartment) + '" data-idx="' + idx + '" placeholder="حدد القوة">' +
                '</div>' +
                '<div class="col-md-3 status-rehire-col" style="display: ' + (showRehire ? 'block' : 'none') + ';">' +
                    '<label class="form-label small fw-bold">هل يمكن إعادة التعيين؟</label>' +
                    '<select class="form-select form-select-sm status-rehire" data-idx="' + idx + '">' +
                        '<option value="">-- اختر --</option>' +
                        '<option value="نعم" ' + (rehireValue === 'نعم' ? 'selected' : '') + '>نعم</option>' +
                        '<option value="لا" ' + (rehireValue === 'لا' ? 'selected' : '') + '>لا</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label class="form-label small fw-bold">ملاحظات</label>' +
                    '<input type="text" class="form-control form-control-sm status-notes" value="' + statusEscape(item.notes) + '" data-idx="' + idx + '" placeholder="ملاحظات">' +
                '</div>' +
            '</div>';
        container.appendChild(card);
        // تهيئة Air Datepicker على حقول التاريخ المُحقنة ديناميكياً (الحالات الوظيفية)
        if (typeof initAirDatepickers === 'function') {
            initAirDatepickers(card);
        }
        if (window.bootstrap && bootstrap.Tooltip) { card.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
    });
    syncStatusHistoryData();
}

function syncStatusHistoryData() {
    var el = document.getElementById('status_history_data');
    if (el) {
        el.value = JSON.stringify(statusHistoryData.map(function(item) {
            var labelFromStatus = item.status_after === 'off_duty' ? 'ليس على رأس العمل' : 'على رأس العمل';
            return {
                movement_type: item.movement_type || '',
                status_after: item.status_after || '',
                status_label: labelFromStatus,
                status: labelFromStatus,
                status_custom: item.status_custom || '',
                movement_type_custom: item.movement_type_custom || '',
                effective_date: item.effective_date || '',
                decision_no: item.decision_no || '',
                decision_date: item.decision_date || '',
                issuer: item.issuer || '',
                contract_type: item.contract_type || '',
                contract_type_custom: item.contract_type_custom || '',
                contract_start: item.contract_start || '',
                contract_end: item.contract_end || '',
                job_title: item.job_title || '',
                job_title_custom: item.job_title_custom || '',
                job_grade: item.job_grade || '',
                department: item.department || '',
                department_custom: item.department_custom || '',
                last_working_day: item.last_working_day || '',
                can_rehire: item.can_rehire || item.rehire || '',
                rehire: item.rehire || item.can_rehire || '',
                status_reason: item.status_reason || item.reason || '',
                reason: item.reason || item.status_reason || '',
                notes: item.notes || ''
            };
        }));
    }
}

document.getElementById('add_status_btn')?.addEventListener('click', function() {
    statusHistoryData.push({
        movement_type: '',
        status_after: 'off_duty',
        status_label: '',
        status: '',
        status_custom: '',
        effective_date: '',
        decision_no: '',
        decision_date: '',
        issuer: '',
        contract_type: '',
        contract_type_custom: '',
        contract_start: '',
        contract_end: '',
        job_title: '',
        job_title_custom: '',
        job_grade: '',
        department: '',
        department_custom: '',
        last_working_day: '',
        can_rehire: '',
        rehire: '',
        status_reason: '',
        reason: '',
        notes: ''
    });
    renderStatusHistory();
});

document.getElementById('status_history_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !statusHistoryData[idx]) return;
    if (e.target.classList.contains('status-movement-custom')) statusHistoryData[idx].movement_type_custom = e.target.value;
    if (e.target.classList.contains('status-contract-custom')) statusHistoryData[idx].contract_type_custom = e.target.value;
    if (e.target.classList.contains('status-job-title-custom')) statusHistoryData[idx].job_title_custom = e.target.value;
    if (e.target.classList.contains('status-department-custom')) statusHistoryData[idx].department_custom = e.target.value;
    if (e.target.classList.contains('status-job-grade')) statusHistoryData[idx].job_grade = e.target.value;
    if (e.target.classList.contains('status-decision-no')) statusHistoryData[idx].decision_no = e.target.value;
    if (e.target.classList.contains('status-issuer')) statusHistoryData[idx].issuer = e.target.value;
    if (e.target.classList.contains('status-reason')) {
        statusHistoryData[idx].reason = e.target.value;
        statusHistoryData[idx].status_reason = e.target.value;
    }
    if (e.target.classList.contains('status-notes')) statusHistoryData[idx].notes = e.target.value;
    syncStatusHistoryData();
});

document.getElementById('status_history_container')?.addEventListener('change', function(e) {
    var idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx) || !statusHistoryData[idx]) return;
    if (e.target.classList.contains('status-movement-type')) {
        statusHistoryData[idx].movement_type = e.target.value;
        if (['تعيين / بداية عمل', 'تعيين', 'عودة للعمل', 'إعادة تعيين'].includes(e.target.value)) {
            statusHistoryData[idx].status_after = 'on_duty';
            statusHistoryData[idx].status = 'على رأس العمل';
            statusHistoryData[idx].status_label = 'على رأس العمل';
        }
        if (['خروج مؤقت', 'إنهاء خدمة', 'استقالة', 'انتهاء عقد'].includes(e.target.value)) {
            statusHistoryData[idx].status_after = 'off_duty';
            statusHistoryData[idx].status = 'ليس على رأس العمل';
            statusHistoryData[idx].status_label = 'ليس على رأس العمل';
        }
        if (e.target.value !== 'أخرى') {
            statusHistoryData[idx].movement_type_custom = '';
        }
        syncStatusHistoryData();
        renderStatusHistory();
    }
    if (e.target.classList.contains('status-after')) {
        statusHistoryData[idx].status_after = e.target.value;
        if (e.target.value === 'on_duty') {
            statusHistoryData[idx].status = 'على رأس العمل';
            statusHistoryData[idx].status_label = 'على رأس العمل';
            statusHistoryData[idx].last_working_day = '';
            statusHistoryData[idx].rehire = '';
            statusHistoryData[idx].can_rehire = '';
        }
        if (e.target.value === 'off_duty') {
            statusHistoryData[idx].status = 'ليس على رأس العمل';
            statusHistoryData[idx].status_label = 'ليس على رأس العمل';
        }
        syncStatusHistoryData();
        renderStatusHistory();
    }
    if (e.target.classList.contains('status-type')) {
        statusHistoryData[idx].status = e.target.value;
        statusHistoryData[idx].status_label = e.target.value;
        statusHistoryData[idx].status_after = (e.target.value === 'على رأس العمل') ? 'on_duty' : 'off_duty';
        if (e.target.value !== 'أخرى') {
            statusHistoryData[idx].status_custom = '';
        }
        if (e.target.value === 'على رأس العمل') {
            statusHistoryData[idx].rehire = '';
            statusHistoryData[idx].can_rehire = '';
            statusHistoryData[idx].last_working_day = '';
        }
        syncStatusHistoryData();
        renderStatusHistory();
    }
    if (e.target.classList.contains('status-effective-date')) {
        statusHistoryData[idx].effective_date = e.target.value;
        syncStatusHistoryData();
    }
    if (e.target.classList.contains('status-decision-date')) {
        statusHistoryData[idx].decision_date = e.target.value;
        syncStatusHistoryData();
    }
    if (e.target.classList.contains('status-contract-type')) {
        statusHistoryData[idx].contract_type = e.target.value;
        if (e.target.value !== 'أخرى') statusHistoryData[idx].contract_type_custom = '';
        syncStatusHistoryData();
        renderStatusHistory();
    }
    if (e.target.classList.contains('status-contract-start')) {
        statusHistoryData[idx].contract_start = e.target.value;
        syncStatusHistoryData();
    }
    if (e.target.classList.contains('status-contract-end')) {
        statusHistoryData[idx].contract_end = e.target.value;
        syncStatusHistoryData();
    }
    if (e.target.classList.contains('status-job-title')) {
        statusHistoryData[idx].job_title = e.target.value;
        if (e.target.value !== 'أخرى') statusHistoryData[idx].job_title_custom = '';
        syncStatusHistoryData();
        renderStatusHistory();
    }
    if (e.target.classList.contains('status-department')) {
        statusHistoryData[idx].department = e.target.value;
        if (e.target.value !== 'أخرى') statusHistoryData[idx].department_custom = '';
        syncStatusHistoryData();
        renderStatusHistory();
    }
    if (e.target.classList.contains('status-rehire')) {
        statusHistoryData[idx].rehire = e.target.value;
        statusHistoryData[idx].can_rehire = e.target.value;
        syncStatusHistoryData();
    }
});

document.getElementById('status_history_container')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.status-remove');
    if (!btn) return;
    if (parseInt(btn.dataset.idx, 10) === 0) return;
    confirmStaffInlineDelete('هل تريد حذف سجل حالة الموظف هذا؟', function() {
        statusHistoryData.splice(parseInt(btn.dataset.idx), 1);
        renderStatusHistory();
    });
});

renderStatusHistory();

// ===== تحذير الخروج بدون حفظ =====
const staffForm = document.getElementById('staffForm');
let staffFormDirty = false;
let staffBypassUnsavedGuard = false;

function hasStaffUnsavedChanges() {
    return Boolean(staffForm && staffFormDirty);
}

// دالة عامة لتمكين النماذج المنفصلة (رفع/حذف المرفقات) من تجاوز حارس
// "التغييرات غير المحفوظة" حتى لا تظهر رسالة المتصفح المزعجة عند الإرسال.
window.bypassStaffUnsavedGuard = function () {
    staffBypassUnsavedGuard = true;
    staffFormDirty = false;
};
// إعادة ضبط الحارس إلى الحالة الطبيعية (لإلغاء التجاوز عند إغلاق modal تأكيد بالإلغاء)
window.bypassStaffUnsavedGuardReset = function () {
    staffBypassUnsavedGuard = false;
};

// حماية شاملة عبر event delegation: أي نموذج على الصفحة يُرسل سيضبط الحارس فلا تظهر
// رسالة المتصفح "Changes you made may not be saved". النماذج المنفصلة (التي ينقلها
// المتصفح خارج النموذج الرئيسي) مشمولة. النماذج المرسلة برمجياً عبر form.submit()
// تستدعي bypassStaffUnsavedGuard() يدوياً.
document.addEventListener('submit', function () {
    staffBypassUnsavedGuard = true;
    staffFormDirty = false;
});

if (staffForm) {
    staffForm.addEventListener('submit', function (event) {
        if (staffForm.checkValidity()) return;

        event.preventDefault();
        const invalidField = staffForm.querySelector(':invalid');
        if (!invalidField) return;

        invalidField.setAttribute('aria-invalid', 'true');
        const pane = invalidField.closest('.tab-pane');
        if (pane && pane.id) {
            const tab = document.querySelector('#staffTabs [data-bs-target="#' + pane.id + '"]');
            if (tab && window.bootstrap) bootstrap.Tab.getOrCreateInstance(tab).show();
        }
        setTimeout(function () {
            invalidField.focus();
            invalidField.reportValidity();
        }, 80);
    });

    staffForm.addEventListener('input', function(e) {
        if (e.target && !e.target.readOnly && !e.target.disabled) staffFormDirty = true;
    });
    staffForm.addEventListener('change', function(e) {
        if (e.target && !e.target.readOnly && !e.target.disabled && e.target.name !== 'active_tab') staffFormDirty = true;
    });

    staffForm.addEventListener('submit', function() {
        staffBypassUnsavedGuard = true;
        staffFormDirty = false;
    });
    window.addEventListener('pageshow', function() {
        staffBypassUnsavedGuard = false;
        staffFormDirty = false;
    });

    window.addEventListener('beforeunload', function(e) {
        if (staffBypassUnsavedGuard || !hasStaffUnsavedChanges()) {
            return;
        }
        e.preventDefault();
        e.returnValue = '';
    });

    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href]');
        if (!link) {
            return;
        }
        const href = (link.getAttribute('href') || '').trim();
        if (!href || href.startsWith('#') || href.toLowerCase().startsWith('javascript:') || link.target === '_blank' || link.hasAttribute('download')) {
            return;
        }
        if (staffBypassUnsavedGuard || !hasStaffUnsavedChanges()) {
            return;
        }
        if (link.closest('#staffUnsavedChangesModal') || link.closest('#staffInlineDeleteConfirmModal')) {
            return;
        }

        e.preventDefault();
        confirmStaffUnsavedExit(function() {
            staffBypassUnsavedGuard = true;
            window.location.href = href;
        });
    });
}
</script>
