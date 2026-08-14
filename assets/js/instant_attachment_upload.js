/**
 * رفع المرفقات الفوري مع شريط تقدم لكل ملف.
 *
 * يعمل عبر XMLHttpRequest (وليس fetch) لأن fetch لا يوفّر حدث تقدم الرفع.
 * يُستخدم من admin/students.php و admin/staff.php لتبويب المرفقات.
 *
 * الاستخدام:
 *   uploadAttachmentInstantly({
 *       file: File,
 *       entityType: 'student' | 'staff',
 *       entityId: number,
 *       label: string,            // اختياري
 *       endpoint: 'ajax/upload_attachment.php', // مسار نسبي من الصفحة
 *       onProgress: function(pct){},   // pct: 0..100
 *       onSuccess: function(data){},   // data.attachment = {id,label,original_name,file_name,file_size,uploaded_at,ext}
 *       onError: function(message){},
 *   });
 */
function uploadAttachmentInstantly(opts) {
    if (!opts || !opts.file) {
        if (typeof opts.onError === 'function') opts.onError('لم يتم اختيار ملف.');
        return;
    }
    var entityType = opts.entityType || 'student';
    var entityId = opts.entityId || 0;
    var endpoint = opts.endpoint || 'ajax/upload_attachment.php';

    var formData = new FormData();
    formData.append('entity_type', entityType);
    formData.append('entity_id', entityId);
    formData.append('label', opts.label || '');
    formData.append('file', opts.file);

    var token = '';
    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (tokenMeta) token = tokenMeta.getAttribute('content') || '';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    if (token) {
        xhr.setRequestHeader('X-CSRF-Token', token);
    }

    // حدث تقدّم الرفع
    xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable && typeof opts.onProgress === 'function') {
            opts.onProgress(Math.round((e.loaded / e.total) * 100));
        }
    });

    xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (err) {
                if (typeof opts.onError === 'function') opts.onError('استجابة غير صالحة من الخادم.');
                return;
            }
            if (data && data.success === true) {
                if (typeof opts.onSuccess === 'function') opts.onSuccess(data);
            } else {
                var msg = (data && data.message) ? data.message : 'فشل في رفع الملف.';
                if (typeof opts.onError === 'function') opts.onError(msg);
            }
        } else {
            if (typeof opts.onError === 'function') opts.onError('خطأ في الخادم (رمز ' + xhr.status + ').');
        }
    };

    xhr.onerror = function () {
        if (typeof opts.onError === 'function') opts.onError('فشل الاتصال بالخادم.');
    };

    xhr.send(formData);
    return xhr;
}

/**
 * تنفيذ عملية بيانات وصفية على مرفق موجود دون إعادة تحميل نموذج الملف الشخصي.
 */
function mutateProfileAttachment(opts) {
    if (!opts || !['rename', 'delete'].includes(opts.action)) {
        if (opts && typeof opts.onError === 'function') opts.onError('عملية المرفق غير صالحة.');
        return;
    }

    var formData = new FormData();
    formData.append('attachment_action', opts.action);
    formData.append('entity_type', opts.entityType || 'student');
    formData.append('entity_id', Number(opts.entityId) || 0);
    formData.append('attachment_id', Number(opts.attachmentId) || 0);
    if (opts.action === 'rename') formData.append('label', opts.label || '');

    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    var token = tokenMeta ? (tokenMeta.getAttribute('content') || '') : '';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', opts.endpoint || 'ajax/upload_attachment.php', true);
    if (token) xhr.setRequestHeader('X-CSRF-Token', token);

    xhr.onload = function () {
        var data = null;
        try {
            data = JSON.parse(xhr.responseText);
        } catch (error) {
            if (typeof opts.onError === 'function') opts.onError('استجابة غير صالحة من الخادم.');
            return;
        }
        if (xhr.status >= 200 && xhr.status < 300 && data && data.success === true) {
            if (typeof opts.onSuccess === 'function') opts.onSuccess(data);
            return;
        }
        if (typeof opts.onError === 'function') {
            opts.onError(data && data.message ? data.message : 'تعذر تنفيذ العملية على المرفق.');
        }
    };
    xhr.onerror = function () {
        if (typeof opts.onError === 'function') opts.onError('فشل الاتصال بالخادم.');
    };
    xhr.send(formData);
    return xhr;
}

function showProfileAttachmentFeedback(type, message) {
    var alert = document.createElement('div');
    alert.className = 'alert alert-' + (type === 'success' ? 'success' : 'danger') + ' alert-dismissible fade show';
    alert.setAttribute('role', 'alert');
    var icon = document.createElement('i');
    icon.className = 'fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + ' me-2';
    alert.appendChild(icon);
    alert.appendChild(document.createTextNode(String(message || '')));
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.setAttribute('data-bs-dismiss', 'alert');
    close.setAttribute('aria-label', 'إغلاق');
    alert.appendChild(close);

    var container = document.querySelector('.modal.show .modal-body') || document.querySelector('main') || document.body;
    container.insertBefore(alert, container.firstChild);
    window.setTimeout(function () {
        if (alert.parentNode) alert.remove();
    }, 5000);
}

function openProfileAttachmentLabelEditor(opts) {
    var modalEl = document.getElementById('profileAttachmentLabelModal');
    var form = document.getElementById('profileAttachmentLabelForm');
    var input = document.getElementById('profileAttachmentLabelInput');
    var errorEl = document.getElementById('profileAttachmentLabelError');
    var saveBtn = document.getElementById('profileAttachmentLabelSaveBtn');
    if (!modalEl || !form || !input || !saveBtn || !window.bootstrap) return;

    input.value = String(opts.label || '');
    input.classList.remove('is-invalid');
    if (errorEl) {
        errorEl.textContent = '';
        errorEl.style.display = 'none';
    }
    saveBtn.disabled = false;

    form.onsubmit = function (event) {
        event.preventDefault();
        var label = input.value.trim();
        if (!label) {
            input.classList.add('is-invalid');
            if (errorEl) {
                errorEl.textContent = 'يرجى إدخال اسم المرفق.';
                errorEl.style.display = '';
            }
            input.focus();
            return;
        }

        input.classList.remove('is-invalid');
        if (errorEl) errorEl.style.display = 'none';
        saveBtn.disabled = true;
        mutateProfileAttachment({
            action: 'rename',
            entityType: opts.entityType,
            entityId: opts.entityId,
            attachmentId: opts.attachmentId,
            label: label,
            endpoint: opts.endpoint,
            onSuccess: function (data) {
                saveBtn.disabled = false;
                if (typeof opts.onSuccess === 'function') opts.onSuccess(data);
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                window.setTimeout(function () {
                    showProfileAttachmentFeedback('success', data.message || 'تم تعديل اسم المرفق بنجاح.');
                }, 220);
            },
            onError: function (message) {
                saveBtn.disabled = false;
                input.classList.add('is-invalid');
                if (errorEl) {
                    errorEl.textContent = message;
                    errorEl.style.display = '';
                }
            }
        });
    };

    modalEl.addEventListener('shown.bs.modal', function () {
        input.focus();
        input.select();
    }, { once: true });
    if (typeof opts.showModal === 'function') {
        opts.showModal(modalEl);
    } else {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

/**
 * أداة مساعدة: تنسيق حجم الملف بصيغة مقروءة.
 * @param {number} bytes
 * @returns {string}
 */
function formatFileSize(bytes) {
    bytes = Number(bytes) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

/**
 * أداة مساعدة: تحديد أيقونة FontAwesome المناسبة لامتداد ملف.
 * @param {string} ext
 * @returns {string} كلاسات الأيقونة مثل "fa-file-pdf text-danger"
 */
function fileIconClass(ext) {
    ext = String(ext || '').toLowerCase();
    if (ext === 'pdf') return 'fa-file-pdf text-danger';
    if (ext === 'doc' || ext === 'docx') return 'fa-file-word text-primary';
    if (ext === 'xls' || ext === 'xlsx') return 'fa-file-excel text-success';
    if (['jpg', 'jpeg', 'png', 'webp'].indexOf(ext) !== -1) return 'fa-file-image text-info';
    return 'fa-file text-secondary';
}

/**
 * أداة مساعدة: تنسيق التاريخ بصيغة YYYY/MM/DD.
 * @param {string} dateString
 * @returns {string}
 */
function formatDate(dateString) {
    if (!dateString) return '-';
    var d = new Date(dateString);
    if (isNaN(d.getTime())) return dateString;
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '/' + mm + '/' + dd;
}
