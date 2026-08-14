<?php if ($page_action === 'add' || $page_action === 'edit'): ?>
    <!-- Student Profile Form JavaScript -->
    <script>
        // ===== وظائف مرفقات الطالب =====
        function uploadStudentAttachment(customLabel, customFileInputId) {
            var label = (customLabel || '').trim();
            var fileInput = document.getElementById(customFileInputId || 'student_profile_image_file');
            if (!label) { alert('يرجى إدخال اسم المرفق'); return; }
            if (!fileInput || !fileInput.files.length) { alert('يرجى اختيار ملف للرفع'); return; }
            document.getElementById('hidden_student_attachment_label').value = label;
            var dt = new DataTransfer();
            dt.items.add(fileInput.files[0]);
            document.getElementById('hidden_student_attachment_file').files = dt.files;
            if (typeof window.bypassStudentUnsavedGuard === 'function') window.bypassStudentUnsavedGuard();
            document.getElementById('uploadStudentAttachmentForm').submit();
        }

        function uploadStudentProfileImage() {
            uploadStudentAttachment('الصورة الشخصية', 'student_profile_image_file');
        }

        function deleteStudentAttachment(id, label, row) {
            confirmStudentInlineDelete('هل أنت متأكد من حذف المرفق: ' + label + '؟', function () {
                var entityId = parseInt(document.querySelector('#deleteStudentAttachmentForm input[name="id"]')?.value, 10) || 0;
                var deleteBtn = row?.querySelector('.att-delete-btn');
                if (deleteBtn) deleteBtn.disabled = true;
                mutateProfileAttachment({
                    action: 'delete',
                    entityType: 'student',
                    entityId: entityId,
                    attachmentId: id,
                    endpoint: 'ajax/upload_attachment.php',
                    onSuccess: function (data) {
                        row?.remove();
                        reindexStudentAttachments();
                        var tbody = document.getElementById('studentAttachmentsTableBody');
                        if (tbody && tbody.children.length === 0) {
                            document.getElementById('studentAttachmentsTableWrap').style.display = 'none';
                            document.getElementById('studentAttachmentsEmpty').style.display = '';
                        }
                        if (label === 'الصورة الشخصية') {
                            var avatar = document.getElementById('current_student_avatar_container');
                            if (avatar) avatar.style.display = 'none';
                        }
                        window.setTimeout(function () {
                            showProfileAttachmentFeedback('success', data.message || 'تم حذف المرفق بنجاح.');
                        }, 220);
                    },
                    onError: function (message) {
                        if (deleteBtn) deleteBtn.disabled = false;
                        window.setTimeout(function () {
                            showProfileAttachmentFeedback('danger', message);
                        }, 220);
                    }
                });
            });
        }

        let studentInlineDeleteConfirmCallback = null;
        let studentUnsavedLeaveCallback = null;
        let studentConfirmActioned = false; // true عند التأكيد، false عند الإلغاء
        let studentProfileModalInstance = null;

        function showStudentChildModal(modalEl) {
            if (!modalEl || !window.bootstrap) return;
            const childModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const profileModalEl = document.getElementById('studentProfileModal');
            if (!profileModalEl || !profileModalEl.classList.contains('show')) {
                childModal.show();
                return;
            }

            const profileModal = bootstrap.Modal.getOrCreateInstance(profileModalEl);
            profileModalEl.addEventListener('hidden.bs.modal', function openChildModal() {
                modalEl.dataset.restoreStudentProfile = 'true';
                childModal.show();
            }, { once: true });
            modalEl.addEventListener('hidden.bs.modal', function restoreProfileModal() {
                if (modalEl.dataset.restoreStudentProfile === 'true' && !studentBypassUnsavedGuard) {
                    profileModal.show();
                }
                delete modalEl.dataset.restoreStudentProfile;
            }, { once: true });
            profileModal.hide();
        }

        function confirmStudentInlineDelete(message, onConfirm) {
            const modalEl = document.getElementById('studentInlineDeleteConfirmModal');
            const messageEl = document.getElementById('studentInlineDeleteConfirmMessage');
            const confirmBtn = document.getElementById('studentInlineDeleteConfirmBtn');
            if (!modalEl || !messageEl || !confirmBtn || !window.bootstrap) {
                return;
            }

            messageEl.textContent = message || 'هل أنت متأكد من الحذف؟';
            studentInlineDeleteConfirmCallback = onConfirm;
            studentConfirmActioned = false;

            showStudentChildModal(modalEl);
        }

        // ===== رفع المرفقات الفوري مع شريط تقدم =====
        document.addEventListener('DOMContentLoaded', function () {
            var studentEntityId = <?php echo isset($formUserId) ? (int) $formUserId : (isset($editStudent->id) ? (int) $editStudent->id : 0); ?>;
            var uploadBtn = document.getElementById('student_upload_attachment_btn');
            var fileInput = document.getElementById('student_attachment_file_input');

            if (uploadBtn && fileInput && studentEntityId > 0) {
                uploadBtn.addEventListener('click', function () { fileInput.click(); });

                fileInput.addEventListener('change', function () {
                    Array.prototype.forEach.call(fileInput.files, function (file) {
                        uploadStudentAttachmentRow(file, studentEntityId);
                    });
                    // إعادة ضبط حقل الملف للسماح بإعادة اختيار نفس الملف لاحقاً
                    fileInput.value = '';
                });
            }

            // ربط اختيار الصورة الشخصية بالرفع الفوري التلقائي
            var profileImageInput = document.getElementById('student_profile_image_file');
            if (profileImageInput && studentEntityId > 0) {
                profileImageInput.addEventListener('change', function () {
                    if (profileImageInput.files.length > 0) {
                        uploadStudentProfileImageRow(profileImageInput.files[0], studentEntityId);
                    }
                });
            }

            // رفع ملف واحد مع شريط تقدم، وتحديث الجدول ديناميكياً
            function uploadStudentAttachmentRow(file, entityId) {
                var tbody = document.getElementById('studentAttachmentsTableBody');
                if (!tbody) return;
                // إظهار حاوية الجدول وإخفاء رسالة "لا توجد مرفقات"
                var tableWrap = document.getElementById('studentAttachmentsTableWrap');
                if (tableWrap) tableWrap.style.display = '';
                var emptyMsg = document.getElementById('studentAttachmentsEmpty');
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
                    entityType: 'student',
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
                    '<td><a href="profile_attachment.php?entity=student&id=' + encodeURIComponent(att.id) + '" target="_blank" class="text-decoration-none">' +
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
                        var spinner = tr.querySelector('.fa-spinner');
                        if (spinner) { spinner.classList.remove('fa-spin'); spinner.classList.add('fa-exclamation-triangle', 'text-danger'); }
                        // إزالة الصف بعد فترة قصيرة ليعيد المستخدم المحاولة
                        setTimeout(function () {
                            if (tr.parentNode) tr.remove();
                            // إن أصبح الجدول فارغاً بالكامل، أعد إظهار رسالة "لا توجد مرفقات"
                            var tbodyNow = document.getElementById('studentAttachmentsTableBody');
                            if (tbodyNow && tbodyNow.querySelectorAll('tr').length === 0) {
                                var wrap = document.getElementById('studentAttachmentsTableWrap');
                                if (wrap) wrap.style.display = 'none';
                                var emptyNow = document.getElementById('studentAttachmentsEmpty');
                                if (emptyNow) emptyNow.style.display = '';
                            }
                        }, 4000);
                    }
                });
            }

            // حذف المرفق المرفوع حديثاً عبر delegation (آمن من XSS بدل inline onclick)
            var studentAttTbody = document.getElementById('studentAttachmentsTableBody');
            if (studentAttTbody) {
                studentAttTbody.addEventListener('click', function (e) {
                    var renameBtn = e.target.closest('.att-rename-btn');
                    if (renameBtn) {
                        var renameRow = renameBtn.closest('tr');
                        var renameId = parseInt(renameBtn.getAttribute('data-attachment-id'), 10) || 0;
                        var renameLabel = renameBtn.getAttribute('data-attachment-label') || '';
                        if (renameId > 0 && renameRow) {
                            openProfileAttachmentLabelEditor({
                                entityType: 'student',
                                entityId: studentEntityId,
                                attachmentId: renameId,
                                label: renameLabel,
                                endpoint: 'ajax/upload_attachment.php',
                                showModal: showStudentChildModal,
                                onSuccess: function (data) {
                                    var finalLabel = data.attachment?.label || renameLabel;
                                    var labelEl = renameRow.querySelector('.att-label');
                                    if (labelEl) labelEl.textContent = finalLabel;
                                    renameRow.querySelectorAll('.att-rename-btn, .att-delete-btn').forEach(function (button) {
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
                        deleteStudentAttachment(attId, attLabel, delBtn.closest('tr'));
                    }
                });
            }

            // رفع الصورة الشخصية فوراً كأول صف بالجدول وتحديث المعاينة
            function uploadStudentProfileImageRow(file, entityId) {
                var tbody = document.getElementById('studentAttachmentsTableBody');
                if (!tbody) return;

                // إظهار حاوية الجدول وإخفاء رسالة "لا توجد مرفقات"
                var tableWrap = document.getElementById('studentAttachmentsTableWrap');
                if (tableWrap) tableWrap.style.display = '';
                var emptyMsg = document.getElementById('studentAttachmentsEmpty');
                if (emptyMsg) emptyMsg.style.display = 'none';

                // إنشاء صف مؤقت يعرض شريط التقدم وإدراجه في البداية (أول صف)
                var tr = document.createElement('tr');
                tr.className = 'att-uploading-row';
                tr.innerHTML =
                    '<td class="att-index">1</td>' +
                    '<td colspan="4">' +
                    '<div class="d-flex align-items-center gap-2 mb-1">' +
                    '<i class="fas fa-spinner fa-spin text-primary"></i>' +
                    '<strong>رفع الصورة الشخصية...</strong>' +
                    '<span class="text-muted small att-pct">0%</span>' +
                    '</div>' +
                    '<div class="progress" style="height:8px;">' +
                    '<div class="progress-bar progress-bar-striped progress-bar-animated bg-primary att-bar" role="progressbar" style="width:0%;"></div>' +
                    '</div>' +
                    '<div class="att-err text-danger small mt-1" style="display:none;"></div>' +
                    '</td>' +
                    '<td></td>';

                // إدراج الصف في البداية
                tbody.insertBefore(tr, tbody.firstChild);
                reindexStudentAttachments();

                var pctEl = tr.querySelector('.att-pct');
                var barEl = tr.querySelector('.att-bar');
                var errEl = tr.querySelector('.att-err');

                uploadAttachmentInstantly({
                    file: file,
                    entityType: 'student',
                    entityId: entityId,
                    label: 'الصورة الشخصية',
                    endpoint: 'ajax/upload_attachment.php',
                    onProgress: function (pct) {
                        pctEl.textContent = pct + '%';
                        barEl.style.width = pct + '%';
                    },
                    onSuccess: function (data) {
                        var att = data.attachment || {};
                        tr.setAttribute('data-attachment-id', att.id);
                        tr.className = '';

                        // تحديث معاينة الصورة الحالية فوراً
                        var downloadUrl = 'profile_attachment.php?entity=student&id=' + att.id;
                        var imgEl = document.getElementById('current_student_avatar_img');
                        var linkEl = document.getElementById('current_student_avatar_link');
                        var containerEl = document.getElementById('current_student_avatar_container');
                        if (imgEl && linkEl && containerEl) {
                            imgEl.src = downloadUrl;
                            linkEl.href = downloadUrl;
                            containerEl.style.setProperty('display', 'flex', 'important');
                        }

                        // إزالة أي صف قديم للصورة الشخصية لتجنب التكرار في الجدول
                        var rows = tbody.querySelectorAll('tr');
                        rows.forEach(function (r) {
                            var labelTd = r.querySelector('td:nth-child(2)');
                            if (labelTd && labelTd.textContent.trim() === 'الصورة الشخصية' && r !== tr) {
                                r.remove();
                            }
                        });

                        tr.innerHTML =
                            '<td class="att-index">1</td>' +
                            '<td><strong>الصورة الشخصية</strong></td>' +
                            '<td><a href="' + downloadUrl + '" target="_blank" class="text-decoration-none">' +
                            '<i class="fas fa-file-image text-info me-1"></i>' + escapeHtml(att.original_name) + '</a></td>' +
                            '<td>' + formatFileSize(att.file_size) + '</td>' +
                            '<td>' + formatDate(att.uploaded_at) + '</td>' +
                            '<td class="actions-column"><button type="button" class="btn btn-action-pills btn-delete att-delete-btn" data-bs-toggle="tooltip" title="حذف" ' +
                            'data-attachment-id="' + att.id + '" data-attachment-label="الصورة الشخصية">' +
                            '<i class="fas fa-trash"></i></button></td>';

                        if (window.bootstrap && bootstrap.Tooltip) {
                            tr.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
                        }

                        // إعادة ترتيب أرقام الصفوف
                        reindexStudentAttachments();

                        // إعادة تعيين حقل الملف للسماح بإعادة اختيار نفس الملف لاحقاً
                        var profileImageInput2 = document.getElementById('student_profile_image_file');
                        if (profileImageInput2) profileImageInput2.value = '';
                    },
                    onError: function (msg) {
                        errEl.style.display = '';
                        errEl.textContent = msg;
                        barEl.classList.remove('bg-primary');
                        barEl.classList.add('bg-danger');
                        barEl.style.width = '100%';
                        var spinner = tr.querySelector('.fa-spinner');
                        if (spinner) { spinner.classList.remove('fa-spin'); spinner.classList.add('fa-exclamation-triangle', 'text-danger'); }
                        setTimeout(function () {
                            if (tr.parentNode) tr.remove();
                            reindexStudentAttachments();

                            var tbodyNow = document.getElementById('studentAttachmentsTableBody');
                            if (tbodyNow && tbodyNow.querySelectorAll('tr').length === 0) {
                                var wrap = document.getElementById('studentAttachmentsTableWrap');
                                if (wrap) wrap.style.display = 'none';
                                var emptyNow = document.getElementById('studentAttachmentsEmpty');
                                if (emptyNow) emptyNow.style.display = '';
                            }
                        }, 4000);
                    }
                });
            }

            // إعادة ترتيب مؤشر الصفوف في جدول المرفقات
            function reindexStudentAttachments() {
                var tbody = document.getElementById('studentAttachmentsTableBody');
                if (!tbody) return;
                var rows = tbody.querySelectorAll('tr:not(.att-uploading-row)');
                rows.forEach(function (r, index) {
                    var idxTd = r.querySelector('.att-index');
                    if (idxTd) {
                        idxTd.textContent = index + 1;
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
        function confirmStudentUnsavedExit(onConfirm) {
            const modalEl = document.getElementById('studentUnsavedChangesModal');
            if (!modalEl || !window.bootstrap) {
                return;
            }
            studentUnsavedLeaveCallback = onConfirm;
            showStudentChildModal(modalEl);
        }

        document.getElementById('studentInlineDeleteConfirmBtn')?.addEventListener('click', function () {
            studentConfirmActioned = true; // تم التأكيد — لا تصفّر الحارس عند الإخفاء
            if (typeof studentInlineDeleteConfirmCallback === 'function') {
                studentInlineDeleteConfirmCallback();
            }
            const modalEl = document.getElementById('studentInlineDeleteConfirmModal');
            const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
            if (modal) {
                modal.hide();
            }
            studentInlineDeleteConfirmCallback = null;
        });

        // عند إغلاق modal التأكيد بدون تأكيد (إلغاء المستخدم)، أعِد ضبط الحارس حتى
        // يبقى تحذير "التغييرات غير المحفوظة" فعالاً للتغييرات الموجودة فعلاً.
        (function () {
            const inlineDeleteModalEl = document.getElementById('studentInlineDeleteConfirmModal');
            if (inlineDeleteModalEl) {
                inlineDeleteModalEl.addEventListener('hidden.bs.modal', function () {
                    // فقط عند الإلغاء (لم يُضغط تأكيد): صفّر الحارس الذي ضبطه المستمع العام submit
                    if (!studentConfirmActioned && typeof window.bypassStudentUnsavedGuardReset === 'function') {
                        window.bypassStudentUnsavedGuardReset();
                    }
                });
            }
        })();

        document.getElementById('studentUnsavedLeaveBtn')?.addEventListener('click', function () {
            if (typeof studentUnsavedLeaveCallback === 'function') {
                studentUnsavedLeaveCallback();
            }
            const modalEl = document.getElementById('studentUnsavedChangesModal');
            const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
            if (modal) {
                modal.hide();
            }
            studentUnsavedLeaveCallback = null;
        });

        // حساب العمر الحالي والعمر في 1 أكتوبر من العام الحالي من المصدر نفسه (تاريخ الميلاد).
        function calculateAge() {
            const bd = document.getElementById('birth_date')?.value;
            const currentDisplay = document.getElementById('current_age_display');
            const octoberDisplay = document.getElementById('age_display');
            if (!currentDisplay || !octoberDisplay) return;
            if (!bd) {
                currentDisplay.value = '';
                octoberDisplay.value = '';
                return;
            }

            const parts = bd.split('-').map(Number);
            if (parts.length !== 3 || parts.some(Number.isNaN)) return;
            const birth = new Date(parts[0], parts[1] - 1, parts[2]);
            if (birth.getFullYear() !== parts[0] || birth.getMonth() !== parts[1] - 1 || birth.getDate() !== parts[2]) return;

            const formatAge = function (referenceDate) {
                let years = referenceDate.getFullYear() - birth.getFullYear();
                let months = referenceDate.getMonth() - birth.getMonth();
                let days = referenceDate.getDate() - birth.getDate();
                if (days < 0) {
                    months--;
                    days += new Date(referenceDate.getFullYear(), referenceDate.getMonth(), 0).getDate();
                }
                if (months < 0) {
                    years--;
                    months += 12;
                }
                return years + ' سنة ' + months + ' شهر ' + days + ' يوم';
            };

            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (birth > today) {
                currentDisplay.value = 'تاريخ الميلاد في المستقبل';
                octoberDisplay.value = 'تاريخ الميلاد بعد تاريخ المرجع';
                return;
            }

            currentDisplay.value = formatAge(today);
            const october = new Date(today.getFullYear(), 9, 1); // 1 أكتوبر من العام الحالي
            octoberDisplay.value = birth > october ? 'تاريخ الميلاد بعد تاريخ المرجع' : formatAge(october);
        }

        function sanitizeDigitsInputs() {
            document.querySelectorAll('.national-id-input, .mobile-input, .landline-input, .extra-phone-input').forEach(function (input) {
                input.addEventListener('input', function () {
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

        function initGradeClassFilter() {
            const stageSelect = document.getElementById('student_stage_filter');
            const gradeSelect = document.getElementById('student_grade_filter');
            const classSelect = document.getElementById('student_class_id');
            if (!gradeSelect || !classSelect) return;

            const applyClassFilter = function () {
                const gradeId = gradeSelect.value;
                classSelect.querySelectorAll('option[data-grade]').forEach(function (opt) {
                    opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
                });

                const selectedOption = classSelect.options[classSelect.selectedIndex];
                if (selectedOption && selectedOption.hasAttribute('data-grade')) {
                    const selectedGrade = selectedOption.getAttribute('data-grade');
                    if (gradeId && selectedGrade !== gradeId) {
                        classSelect.value = '';
                    }
                }
            };

            const applyStageFilter = function () {
                const stageId = stageSelect?.value || '';
                gradeSelect.querySelectorAll('option[data-stage]').forEach(function (opt) {
                    const visible = !stageId || opt.getAttribute('data-stage') === stageId;
                    opt.style.display = visible ? '' : 'none';
                    opt.disabled = !visible;
                });
                const selectedGrade = gradeSelect.options[gradeSelect.selectedIndex];
                if (selectedGrade?.hasAttribute('data-stage')
                    && stageId
                    && selectedGrade.getAttribute('data-stage') !== stageId) {
                    gradeSelect.value = '';
                }
                applyClassFilter();
            };

            if (classSelect.value) {
                const selected = classSelect.querySelector('option[value="' + classSelect.value + '"]');
                const selectedGrade = selected?.getAttribute('data-grade');
                if (selectedGrade) {
                    gradeSelect.value = selectedGrade;
                }
            }

            if (stageSelect && gradeSelect.value) {
                const selectedGrade = gradeSelect.options[gradeSelect.selectedIndex];
                const selectedStage = selectedGrade?.getAttribute('data-stage');
                if (selectedStage) stageSelect.value = selectedStage;
            }

            stageSelect?.addEventListener('change', applyStageFilter);
            gradeSelect.addEventListener('change', function () {
                const selectedGrade = gradeSelect.options[gradeSelect.selectedIndex];
                const selectedStage = selectedGrade?.getAttribute('data-stage');
                if (stageSelect && selectedStage) stageSelect.value = selectedStage;
                applyClassFilter();
            });
            applyStageFilter();
        }

        function toggleExternalTransferFields() {
            const status = document.getElementById('enrollment_status');
            const fields = document.getElementById('external_transfer_fields');
            if (!status || !fields) return;
            const transferred = status.value === 'transferred';
            fields.style.display = transferred ? '' : 'none';
            const destination = document.getElementById('transfer_destination');
            const transferDate = document.getElementById('external_transfer_date');
            if (destination) destination.required = transferred;
            if (transferDate) transferDate.required = transferred;
        }

        // إدارة أولياء الأمور
        // يبدأ الفهرس من آخر عنصر معروض لتفادي تعارض الأسماء عند إضافة أقارب جدد ديناميكياً
        let guardianIndex = <?php echo max(0, count($guardiansList ?? []) - 1); ?>;
        const guardianRoleLabels = <?php echo json_encode($relationshipLabels, JSON_UNESCAPED_UNICODE); ?>;
        sanitizeDigitsInputs();
        initGradeClassFilter();
        toggleExternalTransferFields();

        function getDerivedFatherNameFromStudentInputs() {
            const fields = ['second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'];
            const parts = fields
                .map(function (name) { return (document.querySelector('[name="' + name + '"]')?.value || '').trim(); })
                .filter(function (v) { return v !== ''; });
            return parts.join(' ').replace(/\s+/g, ' ').trim();
        }

        function updateGuardianTitlesAndRoles() {
            const entries = Array.from(document.querySelectorAll('#guardiansContainer .guardian-entry'));
            entries.forEach(function (entry, index) {
                const title = entry.querySelector('.guardian-title');
                const relSelect = entry.querySelector('.guardian-relationship');
                const relHidden = entry.querySelector('.guardian-rel-hidden');
                const removeBtn = entry.querySelector('.remove-guardian');
                const nameInput = entry.querySelector('input[name*="[guardian_name]"]');

                if (index === 0 || index === 1) {
                    const fixedRel = index === 0 ? 'father' : 'mother';
                    if (relSelect) {
                        relSelect.value = fixedRel;
                        relSelect.setAttribute('disabled', 'disabled');
                    }
                    if (relHidden) {
                        relHidden.value = fixedRel;
                    } else if (relSelect?.name) {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.className = 'guardian-rel-hidden';
                        hidden.name = relSelect.name;
                        hidden.value = fixedRel;
                        relSelect.insertAdjacentElement('afterend', hidden);
                    }
                    if (index === 0) {
                        const fatherName = getDerivedFatherNameFromStudentInputs();
                        if (nameInput && fatherName) {
                            nameInput.value = fatherName;
                        }
                        if (nameInput) {
                            nameInput.setAttribute('readonly', 'readonly');
                            nameInput.classList.add('bg-light');
                        }
                    } else if (nameInput) {
                        nameInput.removeAttribute('readonly');
                        nameInput.classList.remove('bg-light');
                    }
                    if (title) {
                        title.innerHTML = '<i class="fas fa-user-tie me-1"></i>بيانات ' + (guardianRoleLabels[fixedRel] || 'ولي الأمر');
                    }
                    if (removeBtn) {
                        removeBtn.style.display = 'none';
                    }
                } else {
                    if (relSelect) {
                        relSelect.removeAttribute('disabled');
                    }
                    if (relHidden) {
                        relHidden.remove();
                    }
                    const relValue = relSelect?.value || '';
                    const otherInput = entry.querySelector('.guardian-relationship-other');
                    const roleLabel = relValue === 'other'
                        ? ((otherInput?.value || '').trim() || 'أخرى')
                        : (guardianRoleLabels[relValue] || 'ولي الأمر');
                    if (title) {
                        title.innerHTML = '<i class="fas fa-user-tie me-1"></i>بيانات ' + roleLabel;
                    }
                    if (removeBtn) {
                        removeBtn.style.display = '';
                    }
                    if (nameInput) {
                        nameInput.removeAttribute('readonly');
                        nameInput.classList.remove('bg-light');
                    }
                }
            });
        }

        function toggleGuardianRelationshipOther(entry) {
            if (!entry) return;
            const relSelect = entry.querySelector('.guardian-relationship');
            const otherInput = entry.querySelector('.guardian-relationship-other');
            if (!relSelect || !otherInput) return;
            const isOther = relSelect.value === 'other' || relSelect.value === 'أخرى';
            otherInput.style.display = isOther ? 'block' : 'none';
            if (!isOther) {
                otherInput.value = '';
            }
        }

        function addStudentMobileRow(number = '', note = '') {
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2 extra-contact-row';
            row.innerHTML = `
        <div class="col-md-4 col-5">
            <input type="text" class="form-control" name="student_mobile_notes[]" placeholder="ملاحظة لهذا الرقم" value="${note}">
        </div>
        <div class="col">
            <input type="text" class="form-control extra-phone-input" name="student_mobile_numbers[]" pattern="[0-9]*" inputmode="numeric" placeholder="رقم الهاتف الإضافي" value="${number}">
        </div>
        <div class="col-auto d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-danger remove-extra-row" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
        </div>`;
            document.getElementById('studentMobilesContainer')?.appendChild(row);
            sanitizeDigitsInputs();
            if (window.bootstrap && bootstrap.Tooltip) { row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
        }

        function addStudentLandlineRow(number = '', note = '') {
            addStudentMobileRow(number, note);
        }

        function addAdditionalDataRow(label = '', value = '') {
            const row = document.createElement('div');
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

        function addGuardianMobileRow(guardianEntry, gi, number = '', note = '') {
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2 extra-contact-row';
            row.innerHTML = `
        <div class="col-md-4 col-5">
            <input type="text" class="form-control" name="guardians[${gi}][extra_mobile_notes][]" placeholder="ملاحظة لهذا الرقم" value="${note}">
        </div>
        <div class="col">
            <input type="text" class="form-control extra-phone-input" name="guardians[${gi}][extra_mobile_numbers][]" pattern="[0-9]*" inputmode="numeric" placeholder="رقم الهاتف الإضافي" value="${number}">
        </div>
        <div class="col-auto d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-danger remove-extra-row" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
        </div>`;
            guardianEntry.querySelector('.guardian-extra-mobiles')?.appendChild(row);
            sanitizeDigitsInputs();
            if (window.bootstrap && bootstrap.Tooltip) { row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
        }

        function addGuardianLandlineRow(guardianEntry, gi, number = '', note = '') {
            addGuardianMobileRow(guardianEntry, gi, number, note);
        }

        function addGuardianExtraDataRow(guardianEntry, gi, label = '', value = '') {
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2 extra-contact-row';
            row.innerHTML = `
        <div class="col-md-4 col-5">
            <input type="text" class="form-control" name="guardians[${gi}][extra_data_labels][]" placeholder="مسمى البيانات" value="${label}">
        </div>
        <div class="col">
            <input type="text" class="form-control" name="guardians[${gi}][extra_data_values][]" placeholder="بيانها" value="${value}">
        </div>
        <div class="col-auto d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-danger remove-extra-row" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
        </div>`;
            guardianEntry.querySelector('.guardian-extra-data-container')?.appendChild(row);
            if (window.bootstrap && bootstrap.Tooltip) { row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el)); }
        }

        function applyStudentExtraRowState(row, locked) {
            if (!row) {
                return;
            }
            row.dataset.locked = locked ? '1' : '0';
            row.querySelectorAll('input, textarea, select').forEach(function (field) {
                if (field.type === 'hidden') {
                    return;
                }
                if (locked) {
                    field.setAttribute('readonly', 'readonly');
                    field.classList.add('bg-light');
                } else {
                    field.removeAttribute('readonly');
                    field.classList.remove('bg-light');
                }
            });

            const saveBtn = row.querySelector('.save-extra-row');
            if (saveBtn) {
                if (locked) {
                    saveBtn.className = 'btn btn-sm btn-primary save-extra-row';
                    saveBtn.innerHTML = '<i class="fas fa-edit"></i>';
                    saveBtn.setAttribute('title', 'تعديل');
                    if (window.bootstrap && bootstrap.Tooltip) {
                        const tt = bootstrap.Tooltip.getInstance(saveBtn);
                        if (tt) { tt.setContent({ '.tooltip-inner': 'تعديل' }); }
                    }
                } else {
                    saveBtn.className = 'btn btn-sm btn-primary save-extra-row';
                    saveBtn.innerHTML = '<i class="fas fa-save"></i>';
                    saveBtn.setAttribute('title', 'حفظ');
                    if (window.bootstrap && bootstrap.Tooltip) {
                        const tt = bootstrap.Tooltip.getInstance(saveBtn);
                        if (tt) { tt.setContent({ '.tooltip-inner': 'حفظ' }); }
                    }
                }
            }
        }

        document.getElementById('addStudentMobileBtn')?.addEventListener('click', function () { addStudentMobileRow(); });
        document.getElementById('addStudentLandlineBtn')?.addEventListener('click', function () { addStudentLandlineRow(); });
        document.getElementById('addAdditionalDataBtn')?.addEventListener('click', function () { addAdditionalDataRow(); });

        function markStudentCompositeTouched(fieldName) {
            const marker = document.querySelector('#studentProfileForm input[name="' + fieldName + '"]');
            if (marker) marker.value = '1';
        }

        function markCompositeFromControl(control) {
            const name = control?.getAttribute?.('name') || '';
            if (/^student_(mobile|landline)_(numbers|notes)\[\]$/.test(name)) {
                markStudentCompositeTouched('student_extra_phones_touched');
            } else if (/^(additional_data_(labels|values)\[\]|educational_guardianship(_other)?)$/.test(name)) {
                markStudentCompositeTouched('student_extra_data_touched');
            } else if (name.startsWith('guardians[')) {
                markStudentCompositeTouched('student_guardians_touched');
            } else if (/^(transfer_destination|external_transfer_date|external_transfer_reason|external_transfer_notes)$/.test(name)) {
                markStudentCompositeTouched('student_external_transfer_touched');
            } else if (name === 'enrollment_status' && control.value === 'transferred') {
                markStudentCompositeTouched('student_external_transfer_touched');
            }
        }

        const studentCompositeTrackingForm = document.getElementById('studentProfileForm');
        ['input', 'change'].forEach(function (eventName) {
            studentCompositeTrackingForm?.addEventListener(eventName, function (event) {
                markCompositeFromControl(event.target);
            });
        });
        studentCompositeTrackingForm?.addEventListener('click', function (event) {
            const button = event.target.closest('button');
            if (!button) return;
            if (button.id === 'addStudentMobileBtn' || button.id === 'addStudentLandlineBtn') {
                markStudentCompositeTouched('student_extra_phones_touched');
            } else if (button.id === 'addAdditionalDataBtn') {
                markStudentCompositeTouched('student_extra_data_touched');
            } else if (button.id === 'addGuardianBtn'
                || button.classList.contains('remove-guardian')
                || button.classList.contains('add-guardian-mobile')
                || button.classList.contains('add-guardian-extra')) {
                markStudentCompositeTouched('student_guardians_touched');
            } else if (button.classList.contains('remove-extra-row')) {
                markCompositeFromControl(button.closest('.extra-contact-row')?.querySelector('[name]'));
            }
        });

        <?php if (($isEditing && $studentProfile) || !empty($oldFormInput)): ?>
                                        // تعبئة مسبقة للأرقام والبيانات الإضافية المحفوظة
                                        <?php foreach ($editExtraPhones as $ph): ?>                                                            <?php if (($ph['type'] ?? '') === 'mobile'): ?>
                    addStudentMobileRow(<?php echo json_encode($ph['number'] ?? ''); ?>, <?php echo json_encode($ph['note'] ?? ''); ?>);
                                                            <?php else: ?>addStudentLandlineRow(<?php echo json_encode($ph['number'] ?? ''); ?>, <?php echo json_encode($ph['note'] ?? ''); ?>);
                                                            <?php endif; ?>                                        <?php endforeach; ?>
            <?php foreach ($editExtraData as $item): ?>
                addAdditionalDataRow(<?php echo json_encode($item['label'] ?? ''); ?>, <?php echo json_encode($item['value'] ?? ''); ?>);
            <?php endforeach; ?>
                (function () {
                    const _gep = <?php echo json_encode($guardianExtraPhones); ?>;
                    const _ged = <?php echo json_encode($guardianExtraData); ?>;
                    document.querySelectorAll('.guardian-entry').forEach(function (entry) {
                        const gi = parseInt(entry.dataset.index);
                        (_gep[gi] || []).forEach(function (p) {
                            if (p.type === 'mobile') {
                                addGuardianMobileRow(entry, gi, p.number || '', p.note || '');
                            }
                            else if (p.type === 'landline') {
                                addGuardianLandlineRow(entry, gi, p.number || '', p.note || '');
                            }
                        });
                        (_ged[gi] || []).forEach(function (item) {
                            addGuardianExtraDataRow(entry, gi, item.label || '', item.value || '');
                        });
                    });
                })();
        <?php endif; ?>

        document.addEventListener('change', function (e) {
            const sel = e.target.closest('.other-toggle');
            if (!sel) return;
            const target = document.getElementById(sel.dataset.otherTarget);
            if (!target) return;
            const isOther = sel.value === 'other' || sel.value === 'أخرى';
            target.style.display = isOther ? 'block' : 'none';
            if (!isOther) {
                target.value = '';
            }
        });

        const educationalGuardianshipSelect = document.querySelector('select[name="educational_guardianship"]');
        const educationalGuardianshipOther = document.getElementById('educational_guardianship_other');
        if (educationalGuardianshipSelect && educationalGuardianshipOther) {
            educationalGuardianshipSelect.addEventListener('change', function () {
                const isOther = this.value === 'other' || this.value === 'أخرى';
                educationalGuardianshipOther.style.display = isOther ? 'block' : 'none';
                if (!isOther) {
                    educationalGuardianshipOther.value = '';
                }
            });
        }

        document.getElementById('addGuardianBtn')?.addEventListener('click', function () {
            guardianIndex++;
            const gi = guardianIndex;
            const relationships = <?php echo json_encode($relationshipLabels); ?>;
            let relOptions = '';
            for (const [k, v] of Object.entries(relationships)) {
                relOptions += `<option value="${k}">${v}</option>`;
            }

            const html = `
    <div class="guardian-entry border rounded p-4 mb-4 bg-transparent" data-index="${gi}">
        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2 guardian-head">
            <h6 class="mb-0 text-primary fw-bold guardian-title"><i class="fas fa-user-tie me-1"></i>بيانات ولي الأمر</h6>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-light guardian-collapse-btn" title="طي/فتح"><i class="fas fa-chevron-up"></i></button>
                <button type="button" class="btn btn-sm btn-danger remove-guardian"><i class="fas fa-trash me-1"></i>حذف ولي الأمر</button>
            </div>
        </div>

        <!-- 1. البيانات الشخصية -->
        <div class="tab-section-title blue"><i class="fas fa-id-card me-1"></i>البيانات الشخصية</div>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label">الاسم الرباعي <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="guardians[${gi}][guardian_name]">
            </div>
            <div class="col-md-2">
                <label class="form-label">صلة القرابة بالطالب <span class="text-danger">*</span></label>
                <select class="form-select guardian-relationship" name="guardians[${gi}][relationship]">${relOptions}</select>
                <input type="text" class="form-control mt-2 guardian-relationship-other" name="guardians[${gi}][relationship_other]" placeholder="اكتب صلة القرابة" style="display:none;">
            </div>
            <div class="col-md-2">
                <label class="form-label">تاريخ الميلاد</label>
                <input type="text" class="form-control flatpickr-date" name="guardians[${gi}][birth_date]" placeholder="اختر التاريخ..." dir="ltr">
            </div>
            <div class="col-md">
                <label class="form-label">محل الميلاد</label>
                <input type="text" class="form-control" name="guardians[${gi}][birth_place]">
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-2">
                <label class="form-label">الديانة</label>
                <select class="form-select other-toggle" name="guardians[${gi}][religion]" data-other-target="guardian_religion_other_${gi}">
                    <option value="">-- اختر --</option>
                    <option value="مسلم">مسلم</option>
                    <option value="مسيحي">مسيحي</option>
                    <option value="أخرى">أخرى</option>
                </select>
                <input type="text" class="form-control mt-2" id="guardian_religion_other_${gi}" name="guardians[${gi}][religion_other]" placeholder="يرجى تحديد الديانة" style="display:none;">
            </div>
            <div class="col-md-2">
                <label class="form-label">الجنسية</label>
                <select class="form-select other-toggle" name="guardians[${gi}][nationality]" data-other-target="guardian_nationality_other_${gi}">
                    <option value="">-- اختر --</option>
                    <?php foreach ($nationalityOptions as $nat): ?>
                        <option value="<?php echo htmlspecialchars($nat, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($nat, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="form-control mt-2" id="guardian_nationality_other_${gi}" name="guardians[${gi}][nationality_other]" placeholder="حدد الجنسية..." style="display:none;">
            </div>
            <div class="col-md-4">
                <label class="form-label">الرقم القومي للمصريين</label>
                <input type="text" class="form-control national-id-input" name="guardians[${gi}][national_id]" dir="ltr" maxlength="14" pattern="[0-9]{14}" inputmode="numeric" placeholder="14 رقمًا">
            </div>
            <div class="col-md-4">
                <label class="form-label">رقم جواز السفر</label>
                <input type="text" class="form-control" name="guardians[${gi}][passport_number]" dir="ltr">
            </div>
        </div>

        <!-- 2. العناوين وبيانات التواصل -->
        <div class="tab-section-title cyan"><i class="fas fa-phone-alt me-1"></i>العناوين وبيانات التواصل</div>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label">رقم الموبايل الأساسي</label>
                <input type="text" class="form-control mobile-input" name="guardians[${gi}][phone_primary]" dir="ltr" maxlength="11" pattern="[0-9]{11}" inputmode="numeric" placeholder="11 رقمًا">
            </div>
            <div class="col-md-4">
                <label class="form-label">رقم الهاتف الأرضي الأساسي</label>
                <input type="text" class="form-control landline-input" name="guardians[${gi}][phone_landline]" dir="ltr" pattern="[0-9]*" inputmode="numeric" placeholder="أرقام فقط">
            </div>
            <div class="col-md-4">
                <label class="form-label">البريد الإلكتروني</label>
                <input type="email" class="form-control" name="guardians[${gi}][email]" dir="ltr" placeholder="example@mail.com">
            </div>
            <div class="col-md-8">
                <label class="form-label">العنوان الحالي بالتفصيل</label>
                <input type="text" class="form-control" name="guardians[${gi}][address]" placeholder="الشارع، الحي، المدينة...">
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0 small text-muted">أرقام هواتف إضافية مع ملاحظة لكل رقم</label>
                                                    <button type="button" class="btn btn-success btn-sm add-guardian-mobile" data-gi="${gi}"><i class="fas fa-plus me-1"></i>إضافة موبايل أو رقم هاتف إضافي</button>
                </div>
                <div class="guardian-extra-mobiles"></div>
            </div>
        </div>

        <!-- 3. المؤهل وبيانات العمل -->
        <div class="tab-section-title purple"><i class="fas fa-briefcase me-1"></i>المؤهل وبيانات العمل</div>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">المؤهل الدراسي</label>
                <input type="text" class="form-control" name="guardians[${gi}][qualification]">
            </div>
            <div class="col-md-3">
                <label class="form-label">الوظيفة / المسمى الوظيفي</label>
                <input type="text" class="form-control" name="guardians[${gi}][job_title]" placeholder="مثال: معلم، مهندس...">
            </div>
            <div class="col-md-4">
                <label class="form-label">جهة العمل / الشركة</label>
                <input type="text" class="form-control" name="guardians[${gi}][employer]" placeholder="مثال: وزارة التعليم، شركة X...">
            </div>
            <div class="col-md-2">
                <label class="form-label">هاتف العمل</label>
                <input type="text" class="form-control work-phone-input" name="guardians[${gi}][work_phone]" dir="ltr" pattern="[0-9]*" inputmode="numeric" placeholder="أرقام فقط">
            </div>
        </div>

        <!-- 4. إضافة بيانات أخرى -->
        <div class="tab-section-title red"><i class="fas fa-plus-square me-1"></i>إضافة بيانات أخرى</div>
        <div class="row g-3">
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0 small text-muted">بيانات إضافية لولي الأمر (مسمى البيانات + بيانها)</label>
                                                    <button type="button" class="btn btn-success btn-sm add-guardian-extra" data-gi="${gi}"><i class="fas fa-plus me-1"></i>إضافة بيان</button>
                </div>
                <div class="guardian-extra-data-container"></div>
            </div>
        </div>
    </div>`;
            document.getElementById('guardiansContainer').insertAdjacentHTML('beforeend', html);
            // تهيئة Air Datepicker على حقول التاريخ المُحقنة ديناميكياً
            var lastGuardian = document.getElementById('guardiansContainer').lastElementChild;
            if (lastGuardian && typeof initAirDatepickers === 'function') {
                initAirDatepickers(lastGuardian);
            }
            updateGuardianTitlesAndRoles();
        });

        // إدارة الإضافات الديناميكية

        document.addEventListener('click', function (e) {
            const collapseBtn = e.target.closest('.guardian-collapse-btn');
            if (collapseBtn) {
                const entry = collapseBtn.closest('.guardian-entry');
                if (!entry) return;
                const hide = !entry.classList.contains('guardian-collapsed');
                entry.classList.toggle('guardian-collapsed', hide);
                Array.from(entry.children).forEach(function (ch) {
                    if (ch.classList.contains('guardian-head')) return;
                    ch.style.display = hide ? 'none' : '';
                });
                collapseBtn.innerHTML = hide ? '<i class="fas fa-chevron-down"></i>' : '<i class="fas fa-chevron-up"></i>';
                return;
            }

            const removeExtraBtn = e.target.closest('.remove-extra-row');
            if (removeExtraBtn) {
                confirmStudentInlineDelete('هل تريد حذف هذا السطر؟', function () {
                    removeExtraBtn.closest('.extra-contact-row')?.remove();
                });
                return;
            }

            const addGuardianMobileBtn = e.target.closest('.add-guardian-mobile');
            if (addGuardianMobileBtn) {
                const guardianEntry = addGuardianMobileBtn.closest('.guardian-entry');
                const gi = guardianEntry?.dataset.index;
                if (guardianEntry && gi !== undefined) {
                    addGuardianMobileRow(guardianEntry, gi);
                }
                return;
            }

            const addGuardianLandlineBtn = e.target.closest('.add-guardian-landline');
            if (addGuardianLandlineBtn) {
                const guardianEntry = addGuardianLandlineBtn.closest('.guardian-entry');
                const gi = guardianEntry?.dataset.index;
                if (guardianEntry && gi !== undefined) {
                    addGuardianLandlineRow(guardianEntry, gi);
                }
                return;
            }

            const addGuardianExtraBtn = e.target.closest('.add-guardian-extra');
            if (addGuardianExtraBtn) {
                const guardianEntry = addGuardianExtraBtn.closest('.guardian-entry');
                const gi = guardianEntry?.dataset.index;
                if (guardianEntry && gi !== undefined) {
                    addGuardianExtraDataRow(guardianEntry, gi);
                }
                return;
            }

            if (e.target.closest('.remove-guardian')) {
                const guardianEntry = e.target.closest('.guardian-entry');
                confirmStudentInlineDelete('هل تريد حذف بيانات ولي الأمر هذا؟', function () {
                    guardianEntry?.remove();
                    updateGuardianTitlesAndRoles();
                });
            }
        });

        document.addEventListener('change', function (e) {
            if (e.target.closest('.guardian-relationship')) {
                toggleGuardianRelationshipOther(e.target.closest('.guardian-entry'));
                updateGuardianTitlesAndRoles();
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.closest('.guardian-relationship-other')) {
                updateGuardianTitlesAndRoles();
            }
        });

        ['second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'].forEach(function (name) {
            document.querySelector('[name="' + name + '"]')?.addEventListener('input', function () {
                updateGuardianTitlesAndRoles();
            });
        });

        document.querySelectorAll('#guardiansContainer .guardian-entry').forEach(toggleGuardianRelationshipOther);
        updateGuardianTitlesAndRoles();

        document.addEventListener('submit', function (e) {
            const unlinkForm = e.target.closest('.unlink-sibling-form');
            if (!unlinkForm) return;

            e.preventDefault();
            confirmStudentInlineDelete('هل أنت متأكد من إلغاء ربط هذا الشقيق؟', function () {
                if (typeof window.bypassStudentUnsavedGuard === 'function') window.bypassStudentUnsavedGuard();
                unlinkForm.submit();
            });
        });

        // نموذج إلغاء ربط القرابة: نموذج منفصل (ينقله المتصفح خارج النموذج الرئيسي)
        // لذا لا يطلق مستمع submit الخاص بـ studentProfileForm. نضيف حماية beforeunload.
        document.addEventListener('submit', function (e) {
            const kinshipForm = e.target.closest('.unlink-kinship-form');
            if (!kinshipForm) return;

            e.preventDefault();
            confirmStudentInlineDelete('هل أنت متأكد من إلغاء ربط هذه الصلة؟', function () {
                if (typeof window.bypassStudentUnsavedGuard === 'function') window.bypassStudentUnsavedGuard();
                kinshipForm.submit();
            });
        });



        // ==== البحث التلقائي الموحد عن الأشقاء وصلات القرابة ====
        document.getElementById('btnFindAllRelations')?.addEventListener('click', function () {
            const studentId = document.querySelector('[name="edit_user_id"]')?.value;
            const second = document.querySelector('[name="second_name_ar"]')?.value || '';
            const third = document.querySelector('[name="third_name_ar"]')?.value || '';
            const family = document.querySelector('[name="family_name_ar"]')?.value || '';

            const area = document.getElementById('relationSuggestionsArea');
            area.style.display = 'block';
            area.innerHTML = '<div class="text-center p-3"><i class="fas fa-spinner fa-spin me-2"></i>جاري البحث عن الأشقاء وصلات القرابة...</div>';

            const sibParams = new URLSearchParams({
                action: 'find_siblings',
                student_id: studentId,
                second_name_ar: second,
                third_name_ar: third,
                family_name_ar: family
            });

            const kinParams = new URLSearchParams({
                action: 'find_kinship',
                student_id: studentId,
                second_name_ar: second,
                third_name_ar: third,
                family_name_ar: family
            });

            Promise.all([
                fetch('../includes/ajax_handlers.php?' + sibParams.toString()).then(r => r.json()),
                fetch('../includes/ajax_handlers.php?' + kinParams.toString()).then(r => r.json())
            ])
                .then(([sibData, kinData]) => {
                    let hasResults = false;
                    let html = '';

                    const allRelLabels = <?php echo json_encode($allRelLabels); ?>;
                    const csrfToken = document.querySelector('#studentProfileForm input[name="csrf_token"]')?.value || '';

                    function buildRelationTable(candidates, headerClass, headerIcon, headerText, isSibling) {
                        if (!candidates || candidates.length === 0) return '';
                        hasResults = true;
                        let tableHtml = `<div class="mb-4"><h6 class="${headerClass}"><i class="fas ${headerIcon} me-2"></i>${headerText} <span class="badge bg-light text-dark ms-2">${candidates.length}</span></h6>`;
                        tableHtml += '<div class="table-responsive"><table class="table table-sm table-hover table-bordered mb-0"><thead class="table-light"><tr><th>الاسم</th><th>الكود</th><th>الفصل</th><th>نسبة التشابه</th><th>نوع التطابق</th><th>صلة القرابة</th><th>ربط</th></tr></thead><tbody>';

                        candidates.forEach(c => {
                            const fullName = [c.first_name_ar, c.second_name_ar, c.third_name_ar, c.fourth_name_ar, c.family_name_ar].filter(Boolean).join(' ');

                            let matchLabel = '';
                            let defaultRel = 'brother';

                            if (isSibling) {
                                matchLabel = c.match_type === 'both' ? '<span class="badge bg-primary">أب وأم</span>' : c.match_type === 'mother' ? '<span class="badge bg-danger">من الأم</span>' : '<span class="badge bg-info">من الأب</span>';
                                defaultRel = (c.gender === 'female') ? 'sister' : 'brother';
                            } else {
                                matchLabel = c.match_type === 'both' ? '<span class="badge bg-primary">أب وأم</span>' : c.match_type === 'maternal' ? '<span class="badge bg-danger">من جهة الأم</span>' : '<span class="badge bg-info">من جهة الأب</span>';

                                if (c.kinship_label) {
                                    matchLabel += `<br><small class="text-muted">${c.kinship_label}</small>`;
                                    if (c.kinship_label.includes('عم')) {
                                        defaultRel = (c.gender === 'female') ? 'ابنة عم' : 'ابن عم';
                                    } else if (c.kinship_label.includes('عمة')) {
                                        defaultRel = (c.gender === 'female') ? 'ابنة عمة' : 'ابن عمة';
                                    } else if (c.kinship_label.includes('خال')) {
                                        defaultRel = (c.gender === 'female') ? 'ابنة خال' : 'ابن خال';
                                    } else if (c.kinship_label.includes('خالة')) {
                                        defaultRel = (c.gender === 'female') ? 'ابنة خالة' : 'ابن خالة';
                                    }
                                }
                            }

                            let currentOpts = '';
                            for (const [k, v] of Object.entries(allRelLabels)) {
                                const selected = k === defaultRel ? 'selected' : '';
                                currentOpts += `<option value="${k}" ${selected}>${v}</option>`;
                            }

                            tableHtml += `<tr>
                    <td>${fullName || c.user_name}</td>
                    <td>${c.student_code || '-'}</td>
                    <td>${c.class_name || '-'}</td>
                    <td><span class="badge bg-${c.similarity_score >= 70 ? 'success' : c.similarity_score >= 50 ? 'warning' : 'secondary'}">${c.similarity_score}%</span></td>
                    <td>${matchLabel}</td>
                    <td><select class="form-select form-select-sm relation-select">${currentOpts}</select></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="${csrfToken}">
                            <input type="hidden" name="student_id" value="${studentId}">
                            <input type="hidden" name="sibling_id" value="${c.uid}">
                            <input type="hidden" name="sibling_relationship" class="relation-val-input" value="${defaultRel}">
                                                        <button type="submit" name="link_sibling" class="btn btn-sm btn-success"><i class="fas fa-link me-1"></i>ربط</button>
                        </form>
                    </td>
                </tr>`;
                        });

                        tableHtml += '</tbody></table></div></div>';
                        return tableHtml;
                    }

                    if (sibData.success && sibData.candidates && sibData.candidates.length > 0) {
                        const fatherMatches = sibData.candidates.filter(c => c.match_type === 'father' || c.match_type === 'both');
                        const motherMatches = sibData.candidates.filter(c => c.match_type === 'mother' || c.match_type === 'both');

                        if (fatherMatches.length > 0) {
                            html += buildRelationTable(fatherMatches, 'text-info mb-2', 'fa-male', 'أشقاء محتملين من الأب', true);
                        }
                        if (motherMatches.length > 0) {
                            html += buildRelationTable(motherMatches, 'text-danger mb-2', 'fa-female', 'أشقاء محتملين من الأم', true);
                        }
                    }

                    if (kinData.success && kinData.candidates && kinData.candidates.length > 0) {
                        const paternalMatches = kinData.candidates.filter(c => c.match_type === 'paternal' || c.match_type === 'both');
                        const maternalMatches = kinData.candidates.filter(c => c.match_type === 'maternal' || c.match_type === 'both');

                        if (paternalMatches.length > 0) {
                            html += buildRelationTable(paternalMatches, 'text-info mb-2', 'fa-male', 'أقارب محتملين من جهة الأب (أبناء عم / عمة)', false);
                        }
                        if (maternalMatches.length > 0) {
                            html += buildRelationTable(maternalMatches, 'text-danger mb-2', 'fa-female', 'أقارب محتملين من جهة الأم (أبناء خال / خالة)', false);
                        }
                    }

                    if (hasResults) {
                        area.innerHTML = html;
                        area.querySelectorAll('.relation-select').forEach(sel => {
                            sel.addEventListener('change', function () {
                                this.closest('tr').querySelector('.relation-val-input').value = this.value;
                            });
                        });
                    } else {
                        area.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>لم يتم العثور على أشقاء أو أقارب محتملين.</div>';
                    }
                })
                .catch(err => {
                    area.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء البحث عن العلاقات.</div>';
                    console.error(err);
                });
        });

        // ==== البحث اليدوي عن أشقاء وأقارب ====
        function doManualSiblingSearch() {
            const studentId = document.querySelector('[name="edit_user_id"]')?.value;
            const searchTerm = document.getElementById('manualSiblingSearch')?.value?.trim();
            const relationship = document.getElementById('manualSiblingRelationship')?.value || 'brother';

            if (!searchTerm || searchTerm.length < 2) {
                alert('أدخل حرفين على الأقل للبحث.');
                return;
            }

            const area = document.getElementById('manualSiblingResults');
            area.style.display = 'block';
            area.innerHTML = '<div class="text-center p-3"><i class="fas fa-spinner fa-spin me-2"></i>جاري البحث...</div>';

            const params = new URLSearchParams({
                action: 'search_students_for_sibling',
                student_id: studentId,
                search: searchTerm
            });

            const allRelLabels = <?php echo json_encode($allRelLabels); ?>;
            const csrfToken = document.querySelector('#studentProfileForm input[name="csrf_token"]')?.value || '';

            fetch('../includes/ajax_handlers.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.students && data.students.length > 0) {
                        let relOpts = '';
                        for (const [k, v] of Object.entries(allRelLabels)) {
                            relOpts += `<option value="${k}" ${k === relationship ? 'selected' : ''}>${v}</option>`;
                        }

                        let html = '<div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-success"><tr><th>الاسم</th><th>الكود</th><th>الفصل</th><th>صلة القرابة</th><th>ربط</th></tr></thead><tbody>';
                        data.students.forEach(s => {
                            const fullName = [s.first_name_ar, s.second_name_ar, s.third_name_ar, s.fourth_name_ar, s.family_name_ar].filter(Boolean).join(' ');
                            html += `<tr>
                        <td>${fullName || s.user_name}</td>
                        <td>${s.student_code || '-'}</td>
                        <td>${s.class_name || '-'}</td>
                        <td><select class="form-select form-select-sm manual-sib-rel">${relOpts}</select></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="${csrfToken}">
                                <input type="hidden" name="student_id" value="${studentId}">
                                <input type="hidden" name="sibling_id" value="${s.uid}">
                                <input type="hidden" name="sibling_relationship" class="manual-sib-rel-value" value="${relationship}">
                                                            <button type="submit" name="link_sibling" class="btn btn-sm btn-success"><i class="fas fa-link me-1"></i>ربط</button>
                            </form>
                        </td>
                    </tr>`;
                        });
                        html += '</tbody></table></div>';
                        area.innerHTML = html;

                        area.querySelectorAll('.manual-sib-rel').forEach(sel => {
                            sel.addEventListener('change', function () {
                                this.closest('tr').querySelector('.manual-sib-rel-value').value = this.value;
                            });
                        });
                    } else {
                        area.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>لم يتم العثور على طلاب مطابقين. جرّب البحث باسم مختلف.</div>';
                    }
                })
                .catch(err => {
                    area.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء البحث.</div>';
                    console.error(err);
                });
        }

        document.getElementById('btnManualSiblingSearch')?.addEventListener('click', doManualSiblingSearch);

        document.getElementById('manualSiblingSearch')?.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                doManualSiblingSearch();
            }
        });

        // حساب العمر عند تحميل الصفحة
        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($studentDataScope === 'current'): ?>
                const profileModalEl = document.getElementById('studentProfileModal');
                if (profileModalEl) {
                    studentProfileModalInstance = bootstrap.Modal.getOrCreateInstance(profileModalEl);
                    profileModalEl.addEventListener('shown.bs.modal', function () {
                        const focusTarget = <?php echo $error_message ? 'profileModalEl.querySelector(\':invalid, [aria-invalid="true"]\')' : 'null'; ?>;
                        if (focusTarget) focusTarget.focus();
                    }, { once: true });
                    studentProfileModalInstance.show();
                }
            <?php endif; ?>

            calculateAge();

            // فرض أرقام إنجليزية فقط في حقول الهاتف
            document.querySelectorAll('input[pattern="[0-9]*"]').forEach(function (input) {
                input.addEventListener('input', function () {
                    // تحويل الأرقام العربية/الهندية إلى إنجليزية
                    this.value = this.value
                        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
                        .replace(/[۰-۹]/g, d => d.charCodeAt(0) - 1776)
                        .replace(/[^0-9]/g, '');
                });
            });
        });

        // ===== تحذير الخروج بدون حفظ =====
        const studentProfileForm = document.getElementById('studentProfileForm');
        let studentFormDirty = false;
        let studentBypassUnsavedGuard = false;

        function hasStudentUnsavedChanges() {
            return Boolean(studentProfileForm && studentFormDirty);
        }

        document.querySelectorAll('[data-student-modal-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                const leaveProfileModal = function () {
                    studentBypassUnsavedGuard = true;
                    window.location.href = <?php echo json_encode($studentsBasePage . $backQuery); ?>;
                };
                if (hasStudentUnsavedChanges()) {
                    confirmStudentUnsavedExit(leaveProfileModal);
                } else {
                    leaveProfileModal();
                }
            });
        });

        // دالة عامة لتمكين النماذج المنفصلة (رفع/حذف المرفقات) من تجاوز حارس
        // "التغييرات غير المحفوظة" حتى لا تظهر رسالة المتصفح المزعجة عند الإرسال.
        // النماذج المنفصلة خارج studentProfileForm لا تطلق مستمع submit الخاص به،
        // لذا يجب استدعاء هذه الدالة يدوياً قبل form.submit() لأي نموذج منفصل.
        window.bypassStudentUnsavedGuard = function () {
            studentBypassUnsavedGuard = true;
            studentFormDirty = false;
        };
        // إعادة ضبط الحارس إلى الحالة الطبيعية (لإلغاء التجاوز عند إغلاق modal تأكيد بالإلغاء)
        window.bypassStudentUnsavedGuardReset = function () {
            studentBypassUnsavedGuard = false;
        };

        // حماية شاملة عبر event delegation: أي نموذج على الصفحة يُرسل (بالضغط المباشر
        // على زر submit أو برمجياً) سيضبط الحارس فلا تظهر رسالة المتصفح "Changes you
        // made may not be saved". تشمل ذلك النماذج المنفصلة (التي ينقلها المتصفح خارج
        // النموذج الرئيسي): إلغاء ربط قرابة، ربط شقيق ديناميكي، إلخ.
        // النماذج التي تفتح modal تأكيداً (unlink-sibling/kinship) تُلغي الإرسال، فيعاد
        // ضبط الحارس عبر الاستدعاء اليدوي bypassStudentUnsavedGuard() عند التأكيد فقط.
        document.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;
            studentBypassUnsavedGuard = true;
            studentFormDirty = false;
        });

        if (studentProfileForm) {
            studentProfileForm.addEventListener('input', function (e) {
                if (e.target && !e.target.readOnly && !e.target.disabled) studentFormDirty = true;
            });
            studentProfileForm.addEventListener('change', function (e) {
                if (e.target && !e.target.readOnly && !e.target.disabled && e.target.name !== 'active_tab') studentFormDirty = true;
                if (e.target) e.target.removeAttribute('aria-invalid');
            });

            studentProfileForm.addEventListener('submit', function (event) {
                if (!studentProfileForm.checkValidity()) {
                    event.preventDefault();
                    const invalidField = studentProfileForm.querySelector(':invalid');
                    if (invalidField) {
                        invalidField.setAttribute('aria-invalid', 'true');
                        const pane = invalidField.closest('.tab-pane');
                        if (pane && !pane.classList.contains('active')) {
                            const tabTrigger = document.querySelector('[data-bs-target="#' + pane.id + '"]');
                            if (tabTrigger) bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
                            const activeTabInput = document.getElementById('active_tab_input');
                            if (activeTabInput) activeTabInput.value = pane.id.replace('pane-', '').replace('-', '_');
                        }
                        window.setTimeout(function () {
                            invalidField.focus();
                            invalidField.reportValidity();
                        }, 180);
                    }
                    return;
                }
                studentBypassUnsavedGuard = true;
                studentFormDirty = false;
            });

            window.addEventListener('pageshow', function () {
                studentBypassUnsavedGuard = false;
                studentFormDirty = false;
            });

            window.addEventListener('beforeunload', function (e) {
                if (studentBypassUnsavedGuard || !hasStudentUnsavedChanges()) {
                    return;
                }
                e.preventDefault();
                e.returnValue = '';
            });

            document.addEventListener('click', function (e) {
                const link = e.target.closest('a[href]');
                if (!link) {
                    return;
                }
                const href = (link.getAttribute('href') || '').trim();
                if (!href || href.startsWith('#') || href.toLowerCase().startsWith('javascript:') || link.target === '_blank' || link.hasAttribute('download')) {
                    return;
                }
                if (studentBypassUnsavedGuard || !hasStudentUnsavedChanges()) {
                    return;
                }
                if (link.closest('#studentUnsavedChangesModal') || link.closest('#studentInlineDeleteConfirmModal')) {
                    return;
                }

                e.preventDefault();
                confirmStudentUnsavedExit(function () {
                    studentBypassUnsavedGuard = true;
                    window.location.href = href;
                });
            });
        }
    </script>
<?php endif; ?>

<?php if ($studentDataScope === 'current'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const bulkRowsBody = document.getElementById('bulkStudentsRows');
            const addBulkRowBtn = document.getElementById('addBulkStudentRow');
            const bulkForm = document.getElementById('bulkAddStudentsForm');
            const defaultClassSelect = document.getElementById('bulkDefaultClass');
            const bulkClasses = <?php echo json_encode(array_map(static function ($classItem) {
                return [
                    'id' => (int) $classItem['id'],
                    'label' => ($classItem['grade_name'] ?? '') . ' — ' . $classItem['name'],
                ];
            }, $classes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            if (!bulkRowsBody || !addBulkRowBtn || !bulkForm) {
                return;
            }

            function escapeBulkHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, function (char) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
                });
            }

            function bulkClassOptions() {
                return '<option value="">الفصل الافتراضي</option>' + bulkClasses.map(function (item) {
                    return '<option value="' + item.id + '">' + escapeBulkHtml(item.label) + '</option>';
                }).join('');
            }

            function renumberBulkRows() {
                const rows = Array.from(bulkRowsBody.querySelectorAll('.bulk-student-row'));
                rows.forEach(function (row, index) {
                    row.dataset.index = String(index);
                    const numberCell = row.querySelector('.bulk-row-number');
                    if (numberCell) numberCell.textContent = String(index + 1);
                    row.querySelectorAll('[name^="bulk_students["]').forEach(function (field) {
                        field.name = field.name.replace(/^bulk_students\[\d+\]/, 'bulk_students[' + index + ']');
                    });
                });
                addBulkRowBtn.disabled = rows.length >= 20;
            }

            function addBulkRow() {
                const currentCount = bulkRowsBody.querySelectorAll('.bulk-student-row').length;
                if (currentCount >= 20) return;
                const today = new Date().toISOString().slice(0, 10);
                const row = document.createElement('tr');
                row.className = 'bulk-student-row';
                row.dataset.index = String(currentCount);
                row.innerHTML =
                    '<td class="bulk-row-number">' + (currentCount + 1) + '</td>' +
                    '<td><input type="text" class="form-control form-control-sm" name="bulk_students[' + currentCount + '][name]" placeholder="الاسم الكامل"></td>' +
                    '<td><select class="form-select form-select-sm" name="bulk_students[' + currentCount + '][class_id]">' + bulkClassOptions() + '</select></td>' +
                    '<td><input type="text" class="form-control form-control-sm" inputmode="numeric" pattern="[0-9]{14}" maxlength="14" name="bulk_students[' + currentCount + '][national_id]"></td>' +
                    '<td><select class="form-select form-select-sm" name="bulk_students[' + currentCount + '][gender]"><option value="">-- اختر --</option><option value="male">ذكر</option><option value="female">أنثى</option></select></td>' +
                    '<td><input type="text" class="form-control form-control-sm" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" name="bulk_students[' + currentCount + '][phone_mobile]"></td>' +
                    '<td><input type="text" class="form-control form-control-sm flatpickr-date" name="bulk_students[' + currentCount + '][enrollment_date]" placeholder="اختر التاريخ..." value="' + today + '"></td>' +
                    '<td><button type="button" class="btn btn-action-pills btn-delete remove-bulk-student" data-bs-toggle="tooltip" title="حذف الصف"><i class="fas fa-trash"></i></button></td>';
                bulkRowsBody.appendChild(row);
                // تهيئة Air Datepicker على حقول التاريخ المُحقنة ديناميكياً
                if (typeof initAirDatepickers === 'function') {
                    initAirDatepickers(row);
                }
                const nameInput = row.querySelector('[name$="[name]"]');
                if (nameInput) nameInput.focus();
                row.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
                    bootstrap.Tooltip.getOrCreateInstance(element);
                });
                renumberBulkRows();
            }

            addBulkRowBtn.addEventListener('click', addBulkRow);

            bulkRowsBody.addEventListener('input', function (event) {
                if (event.target.matches('input[inputmode="numeric"]')) {
                    event.target.value = event.target.value
                        .replace(/[٠-٩]/g, function (digit) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(digit); })
                        .replace(/[۰-۹]/g, function (digit) { return digit.charCodeAt(0) - 1776; })
                        .replace(/[^0-9]/g, '');
                }
                event.target.setCustomValidity('');
            });

            bulkRowsBody.addEventListener('click', function (event) {
                const removeButton = event.target.closest('.remove-bulk-student');
                if (!removeButton) return;
                const rows = bulkRowsBody.querySelectorAll('.bulk-student-row');
                if (rows.length <= 2) {
                    window.adminConfirm('الإضافة الجماعية تتطلب طالبين على الأقل. يمكنك مسح بيانات الصف بدلاً من حذفه.', { operation: 'warning' });
                    return;
                }
                window.adminConfirm('هل تريد حذف صف الطالب من الدفعة؟', { operation: 'delete' }).then(function (approved) {
                    if (!approved) return;
                    removeButton.closest('.bulk-student-row')?.remove();
                    renumberBulkRows();
                });
            });

            bulkForm.addEventListener('submit', function (event) {
                const rows = Array.from(bulkRowsBody.querySelectorAll('.bulk-student-row'));
                const meaningfulRows = rows.filter(function (row) {
                    return Array.from(row.querySelectorAll('input:not([type="date"]):not(.flatpickr-date), select')).some(function (field) {
                        return String(field.value || '').trim() !== '';
                    });
                });
                const seenNationalIds = new Map();
                let invalidField = null;

                if (meaningfulRows.length < 2) {
                    invalidField = rows[0]?.querySelector('[name$="[name]"]') || null;
                    if (invalidField) invalidField.setCustomValidity('أدخل بيانات طالبين على الأقل للإضافة الجماعية.');
                }

                rows.forEach(function (row, index) {
                    const nameInput = row.querySelector('[name$="[name]"]');
                    const classSelect = row.querySelector('[name$="[class_id]"]');
                    const nationalIdInput = row.querySelector('[name$="[national_id]"]');
                    if (!meaningfulRows.includes(row)) return;
                    if (nameInput && !nameInput.value.trim() && !invalidField) {
                        nameInput.setCustomValidity('اسم الطالب مطلوب في الصف ' + (index + 1));
                        invalidField = nameInput;
                    }
                    if (classSelect && !classSelect.value && !defaultClassSelect?.value && !invalidField) {
                        classSelect.setCustomValidity('اختر فصلاً للطالب أو فصلاً افتراضياً للدفعة.');
                        invalidField = classSelect;
                    }
                    const nationalId = nationalIdInput?.value.trim() || '';
                    if (nationalId) {
                        if (seenNationalIds.has(nationalId) && !invalidField) {
                            nationalIdInput.setCustomValidity('الرقم القومي مكرر مع الصف ' + seenNationalIds.get(nationalId));
                            invalidField = nationalIdInput;
                        } else {
                            seenNationalIds.set(nationalId, index + 1);
                        }
                    }
                });

                if (invalidField) {
                    event.preventDefault();
                    invalidField.reportValidity();
                    invalidField.focus();
                }
            });

            renumberBulkRows();

            if (new URLSearchParams(window.location.search).get('bulk_add') === '1') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkAddStudentsModal')).show();
            }
        });
    </script>
<?php endif; ?>
