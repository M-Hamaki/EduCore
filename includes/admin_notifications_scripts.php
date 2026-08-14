<?php

declare(strict_types=1);

if (!defined('EDUCORE_NOTIFICATIONS_PAGE')) {
    http_response_code(404);
    exit;
}

?><script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ===== Toggle notification type sections =====
    const typeSelect = document.getElementById('notificationType');
    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            document.getElementById('studentTargets').style.display = this.value === 'student' ? 'block' : 'none';
            document.getElementById('teacherTargets').style.display = this.value === 'teacher' ? 'block' : 'none';
            document.getElementById('specialistTargets').style.display = this.value === 'specialist' ? 'block' : 'none';
            document.getElementById('publicNotice').style.display = this.value === 'public' ? 'block' : 'none';
        });
    }
    const typeSelectModal = document.getElementById('notificationTypeModal');
    if (typeSelectModal) {
        typeSelectModal.addEventListener('change', function() {
            document.getElementById('studentTargetsModal').style.display = this.value === 'student' ? 'block' : 'none';
            document.getElementById('teacherTargetsModal').style.display = this.value === 'teacher' ? 'block' : 'none';
            document.getElementById('specialistTargetsModal').style.display = this.value === 'specialist' ? 'block' : 'none';
            document.getElementById('publicNoticeModal').style.display = this.value === 'public' ? 'block' : 'none';
        });
    }
    
    // ===== Load students by class =====
    const loadBtn = document.getElementById('loadStudentsBtn');
    if (loadBtn) {
        loadBtn.addEventListener('click', function() {
            const classId = document.getElementById('studentClassFilter').value;
            if (!classId) return;
            
            fetch('notifications.php?ajax=students_by_class&class_id=' + classId)
                .then(r => r.json())
                .then(students => {
                    const select = document.getElementById('targetStudents');
                    // Keep already selected
                    const existing = new Set();
                    for (let opt of select.options) {
                        if (opt.selected) existing.add(opt.value);
                    }
                    // Add new students (avoid duplicates)
                    students.forEach(s => {
                        if (!existing.has(String(s.id))) {
                            let existsInList = false;
                            for (let opt of select.options) {
                                if (opt.value == s.id) { existsInList = true; break; }
                            }
                            if (!existsInList) {
                                const opt = new Option(s.name, s.id);
                                select.add(opt);
                            }
                        }
                    });
                });
        });
    }
    
    // ===== Delete notification =====
    document.querySelectorAll('.delete-notif').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('deleteId').value = this.dataset.id;
            document.getElementById('deleteTitle').textContent = this.dataset.title;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        });
    });
    
    // ===== Toggle status (opens confirmation modal) =====
    document.querySelectorAll('.toggle-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const newStatus = this.dataset.newStatus;
            const actionText = newStatus === '1' ? 'تفعيل' : 'تعطيل';
            
            document.getElementById('toggleNotifId').value = id;
            document.getElementById('toggleNotifStatus').value = newStatus;
            document.getElementById('toggleNotifTitle').innerHTML = '<i class="fas fa-bell me-2"></i>' + actionText + ' التنبيه';
            document.getElementById('toggleNotifText').innerHTML = 'هل أنت متأكد من <span class="fw-bold">' + actionText + '</span> هذا التنبيه؟';
            
            const modalContent = document.getElementById('toggleNotifModalContent');
            const btn2 = document.getElementById('toggleNotifBtn');
            const icon = document.getElementById('toggleNotifIcon');
            
            if (newStatus === '1') {
                modalContent.classList.remove('admin-modal-warning');
                modalContent.classList.add('admin-modal-create');
                btn2.className = 'btn btn-success';
                btn2.textContent = 'تفعيل';
                icon.className = 'fas fa-check-circle text-success';
                document.getElementById('toggleNotifDesc').textContent = 'سيتم تفعيل التنبيه وسيظهر للمستخدمين المستهدفين.';
            } else {
                modalContent.classList.remove('admin-modal-create');
                modalContent.classList.add('admin-modal-warning');
                btn2.className = 'btn btn-danger';
                btn2.textContent = 'تعطيل';
                icon.className = 'fas fa-pause-circle text-danger';
                document.getElementById('toggleNotifDesc').textContent = 'سيتم تعطيل التنبيه ولن يظهر للمستخدمين حتى يتم تفعيله مرة أخرى.';
            }
            
            new bootstrap.Modal(document.getElementById('toggleNotifModal')).show();
        });
    });
    
    // ===== Edit notification AJAX loader =====
    document.querySelectorAll('.edit-notif-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch('notifications.php?ajax=get_notification&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (!data || data.error) return;
                    document.getElementById('editNotifId').value = data.id;
                    document.getElementById('editNotificationType').value = data.type;
                    document.getElementById('editPriority').value = data.priority || 'normal';
                    document.getElementById('editTitle').value = data.title;
                    document.getElementById('editMessage').value = data.message;
                    document.getElementById('editStartDate').value = data.start_date || '';
                    document.getElementById('editEndDate').value = data.end_date || '';
                    document.getElementById('editStartTime').value = data.start_time || '';
                    document.getElementById('editEndTime').value = data.end_time || '';
                    
                    const editType = data.type;
                    document.getElementById('editStudentTargets').style.display = editType === 'student' ? 'block' : 'none';
                    document.getElementById('editTeacherTargets').style.display = editType === 'teacher' ? 'block' : 'none';
                    document.getElementById('editSpecialistTargets').style.display = editType === 'specialist' ? 'block' : 'none';
                    document.getElementById('editPublicNotice').style.display = editType === 'public' ? 'block' : 'none';

                    const days = data.show_days_arr || [];
                    document.querySelectorAll('.edit-day-checkbox').forEach(cb => {
                        cb.checked = days.includes(parseInt(cb.value));
                    });
                    
                    const targets = data.targets || {};
                    const targetStages = targets.stage || [];
                    document.querySelectorAll('.edit-stage-checkbox').forEach(cb => {
                        cb.checked = targetStages.includes(parseInt(cb.value));
                    });
                    
                    const selectSelected = (selectId, valuesArr) => {
                        const select = document.getElementById(selectId);
                        if (!select) return;
                        const valSet = new Set((valuesArr || []).map(v => String(v)));
                        for (let opt of select.options) {
                            opt.selected = valSet.has(opt.value);
                        }
                    };
                    
                    selectSelected('editTargetGrades', targets.grade);
                    selectSelected('editTargetClasses', targets.class);
                    selectSelected('editTargetTeachers', targets.teacher);
                    selectSelected('editTargetSpecialists', targets.specialist);
                    
                    document.getElementById('editSendPushCheck').checked = parseInt(data.send_push) === 1;

                    new bootstrap.Modal(document.getElementById('editNotificationModal')).show();
                });
        });
    });

    const typeEditModal = document.getElementById('editNotificationType');
    if (typeEditModal) {
        typeEditModal.addEventListener('change', function() {
            document.getElementById('editStudentTargets').style.display = this.value === 'student' ? 'block' : 'none';
            document.getElementById('editTeacherTargets').style.display = this.value === 'teacher' ? 'block' : 'none';
            document.getElementById('editSpecialistTargets').style.display = this.value === 'specialist' ? 'block' : 'none';
            document.getElementById('editPublicNotice').style.display = this.value === 'public' ? 'block' : 'none';
        });
    }

    // ===== Filters, reset, and column toggling =====
    const typeFilter = document.getElementById('typeFilter');
    const priorityFilter = document.getElementById('priorityFilter');
    const resetNotifFilters = document.getElementById('resetNotifFilters');
    
    const setupTableFilters = () => {
        if (typeof $ === 'undefined' || !$.fn.DataTable) return;
        
        let table;
        if ($.fn.dataTable.isDataTable('#notificationsTable')) {
            table = $('#notificationsTable').DataTable();
        } else if ($('#notificationsTable').length) {
            table = $('#notificationsTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json'
                },
                pageLength: 25,
                responsive: true
            });
        }
        
        if (!table) {
            setTimeout(setupTableFilters, 150);
            return;
        }

        if (typeFilter) {
            typeFilter.addEventListener('change', function() {
                const map = {'student': 'طلاب', 'teacher': 'معلمين', 'specialist': 'أخصائيين', 'public': 'عام'};
                if (this.value === 'all') {
                    table.column(2).search('').draw();
                } else {
                    table.column(2).search(map[this.value] || '').draw();
                }
            });
        }

        if (priorityFilter) {
            priorityFilter.addEventListener('change', function() {
                const map = {'normal': 'عادي', 'important': 'مهم', 'urgent': 'عاجل'};
                if (this.value === 'all') {
                    table.column(3).search('').draw();
                } else {
                    table.column(3).search(map[this.value] || '').draw();
                }
            });
        }

        if (resetNotifFilters) {
            resetNotifFilters.addEventListener('click', function() {
                if (typeFilter) typeFilter.value = 'all';
                if (priorityFilter) priorityFilter.value = 'all';
                table.search('').columns().search('').draw();
            });
        }

        document.querySelectorAll('.col-toggle-check').forEach(chk => {
            chk.addEventListener('change', function() {
                const colIdx = parseInt(this.dataset.column);
                table.column(colIdx).visible(this.checked);
            });
        });
    };

    setTimeout(setupTableFilters, 200);
    
    // ===== Occasion Toggle (opens confirmation modal) =====
    document.querySelectorAll('.occasion-toggle').forEach(toggle => {
        toggle.addEventListener('change', function(e) {
            e.preventDefault();
            // Revert checkbox - actual change happens via form submit
            this.checked = !this.checked;
            
            const id = this.dataset.id;
            const title = this.dataset.title;
            const currentStatus = parseInt(this.dataset.status);
            const newStatus = currentStatus ? 0 : 1;
            const actionText = newStatus ? 'تفعيل' : 'تعطيل';
            
            document.getElementById('toggleOccasionId').value = id;
            document.getElementById('toggleOccasionStatus').value = newStatus;
            document.getElementById('toggleOccasionTitle').innerHTML = '<i class="fas fa-star me-2"></i>' + actionText + ' مناسبة';
            document.getElementById('toggleOccasionText').innerHTML = 'هل أنت متأكد من <span class="fw-bold">' + actionText + '</span> المناسبة <span class="fw-bold text-primary">' + title + '</span>؟';
            
            const modalContent = document.getElementById('toggleOccasionModalContent');
            const btn = document.getElementById('toggleOccasionBtn');
            const icon = document.getElementById('toggleOccasionIcon');
            
            if (newStatus) {
                modalContent.classList.remove('admin-modal-warning');
                modalContent.classList.add('admin-modal-create');
                btn.className = 'btn btn-success';
                btn.textContent = 'تفعيل';
                icon.className = 'fas fa-check-circle text-success';
                document.getElementById('toggleOccasionDesc').textContent = 'سيتم تفعيل المناسبة وستظهر للمستخدمين في بواباتهم.';
            } else {
                modalContent.classList.remove('admin-modal-create');
                modalContent.classList.add('admin-modal-warning');
                btn.className = 'btn btn-danger';
                btn.textContent = 'تعطيل';
                icon.className = 'fas fa-pause-circle text-danger';
                document.getElementById('toggleOccasionDesc').textContent = 'سيتم تعطيل المناسبة ولن تظهر للمستخدمين حتى يتم تفعيلها مرة أخرى.';
            }
            
            new bootstrap.Modal(document.getElementById('toggleOccasionModal')).show();
        });
    });
    
    // ===== Edit Occasion (load data via AJAX, show modal) =====
    document.querySelectorAll('.edit-occasion-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch('notifications.php?ajax=get_occasion&id=' + id)
            .then(r => r.json())
            .then(occ => {
                document.getElementById('editOccId').value = occ.id;
                document.getElementById('editOccTitle').value = occ.title;
                document.getElementById('editOccMessage').value = occ.message;
                document.getElementById('editOccTarget').value = (occ.target_type === 'both' ? 'all' : occ.target_type);
                document.getElementById('editOccStartDate').value = occ.start_date || '';
                document.getElementById('editOccEndDate').value = occ.end_date || '';
                new bootstrap.Modal(document.getElementById('editOccasionModal')).show();
            });
        });
    });
    
    // ===== Delete Occasion (opens confirmation modal) =====
    document.querySelectorAll('.delete-occasion-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('deleteOccasionId').value = this.dataset.id;
            document.getElementById('deleteOccasionName').textContent = this.dataset.title;
            new bootstrap.Modal(document.getElementById('deleteOccasionModal')).show();
        });
    });
    
    // ===== Send Push for Occasion =====
    document.querySelectorAll('.send-push-occasion-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('sendPushOccasionId').value = this.dataset.id;
            document.getElementById('sendPushOccasionName').textContent = this.dataset.title;
            new bootstrap.Modal(document.getElementById('sendPushOccasionModal')).show();
        });
    });
    
    // ===== Theme Selector for Add Occasion =====
    document.querySelectorAll('.theme-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.theme-btn').forEach(b => {
                b.style.borderColor = 'transparent';
                b.classList.remove('active');
            });
            this.style.borderColor = '#333';
            this.classList.add('active');
            
            const theme = this.dataset.theme;
            let gs, ge, tc;
            
            if (theme === 'custom') {
                document.getElementById('customColorsSection').style.display = 'block';
                gs = document.getElementById('addOccGradStart').value;
                ge = document.getElementById('addOccGradEnd').value;
                tc = document.getElementById('addOccTextColor').value;
            } else {
                document.getElementById('customColorsSection').style.display = 'none';
                gs = this.dataset.gs;
                ge = this.dataset.ge;
                tc = this.dataset.tc;
            }
            
            // Update hidden form fields
            document.getElementById('addOccThemeHidden').value = theme === 'custom' ? 'default' : theme;
            document.getElementById('addOccGradStartHidden').value = gs;
            document.getElementById('addOccGradEndHidden').value = ge;
            document.getElementById('addOccTextColorHidden').value = tc;
            
            updateAddPreview(gs, ge, tc);
        });
    });
    
    // Custom color pickers
    ['addOccGradStart', 'addOccGradEnd', 'addOccTextColor'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', function() {
            const gs = document.getElementById('addOccGradStart').value;
            const ge = document.getElementById('addOccGradEnd').value;
            const tc = document.getElementById('addOccTextColor').value;
            document.getElementById('addOccGradStartHidden').value = gs;
            document.getElementById('addOccGradEndHidden').value = ge;
            document.getElementById('addOccTextColorHidden').value = tc;
            updateAddPreview(gs, ge, tc);
        });
    });
    
    // Live preview updates
    ['addOccTitle', 'addOccMessage', 'addOccEmoji'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', function() {
            updateAddPreview(
                document.getElementById('addOccGradStartHidden').value,
                document.getElementById('addOccGradEndHidden').value,
                document.getElementById('addOccTextColorHidden').value
            );
        });
    });
    const iconSelect = document.getElementById('addOccIcon');
    if (iconSelect) iconSelect.addEventListener('change', function() {
        updateAddPreview(
            document.getElementById('addOccGradStartHidden').value,
            document.getElementById('addOccGradEndHidden').value,
            document.getElementById('addOccTextColorHidden').value
        );
    });
    
    function updateAddPreview(gs, ge, tc) {
        const title = document.getElementById('addOccTitle').value || 'عنوان المناسبة';
        const emoji = document.getElementById('addOccEmoji').value || '';
        const msg = document.getElementById('addOccMessage').value || 'رسالة المناسبة ستظهر هنا بكامل الوضوح والألوان المحددة...';
        const icon = document.getElementById('addOccIcon').value;
        
        const preview = document.getElementById('addOccPreview');
        if (preview) {
            preview.style.background = 'linear-gradient(135deg, ' + gs + ' 0%, ' + ge + ' 100%)';
            preview.style.color = tc;
        }
        const tEl = document.getElementById('addOccPreviewTitle');
        if (tEl) tEl.textContent = (emoji ? emoji + ' ' : '') + title;
        const iEl = document.getElementById('addOccPreviewIcon');
        if (iEl) iEl.className = icon;
        const mEl = document.getElementById('addOccPreviewMsg');
        if (mEl) mEl.textContent = msg;
    }
    
    // ===== Standardized Tab Persistence (sessionStorage + replaceState) =====
    const tabLinks = document.querySelectorAll('button[data-bs-toggle="tab"]');
    const storageKey = 'notifications_active_tab';
    const activeTabInputs = document.querySelectorAll('.active-tab-input');
    const urlParams = new URLSearchParams(window.location.search);

    const syncTabUI = (target) => {
        const tabName = (target === '#tab-occasions' || target === 'occasions') ? 'occasions' : 'notifications';
        activeTabInputs.forEach(input => input.value = tabName);
        
        const statsNotif = document.getElementById('stats-tab-notifications');
        const statsOcc = document.getElementById('stats-tab-occasions');
        const addNotifBtn = document.getElementById('addNotifTopBtn');
        const addOccasionBtn = document.getElementById('addOccasionTopBtn');
        
        if (tabName === 'notifications') {
            if (statsNotif) statsNotif.classList.remove('d-none');
            if (statsOcc) statsOcc.classList.add('d-none');
            if (addNotifBtn) addNotifBtn.classList.remove('d-none');
            if (addOccasionBtn) addOccasionBtn.classList.add('d-none');
        } else {
            if (statsOcc) statsOcc.classList.remove('d-none');
            if (statsNotif) statsNotif.classList.add('d-none');
            if (addOccasionBtn) addOccasionBtn.classList.remove('d-none');
            if (addNotifBtn) addNotifBtn.classList.add('d-none');
        }
    };

    // 1. Restore from sessionStorage if no URL param exists
    if (!urlParams.has('tab')) {
        const savedTab = sessionStorage.getItem(storageKey);
        if (savedTab) {
            const tabEl = document.querySelector(`button[data-bs-target="${savedTab}"]`);
            if (tabEl) { new bootstrap.Tab(tabEl).show(); }
            syncTabUI(savedTab);
        } else {
            syncTabUI('#tab-notifications');
        }
    } else {
        // Correct tab if URL param exists
        const urlTab = urlParams.get('tab');
        const targetId = urlTab === 'occasions' ? '#tab-occasions' : '#tab-notifications';
        const tabEl = document.querySelector(`button[data-bs-target="${targetId}"]`);
        if (tabEl && !tabEl.classList.contains('active')) {
            new bootstrap.Tab(tabEl).show();
        }
        syncTabUI(targetId);
    }

    // 2. Save to sessionStorage and update URL on click
    tabLinks.forEach(link => {
        link.addEventListener('shown.bs.tab', function (e) {
            const target = e.target.getAttribute('data-bs-target');
            sessionStorage.setItem(storageKey, target);
            
            syncTabUI(target);

            // Sync with URL without reload
            const tabName = target === '#tab-occasions' ? 'occasions' : 'notifications';
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', newUrl);
        });
    });

    // ===== Occasions Filtering =====
    const filterOccasions = () => {
        const targetFilter = document.getElementById('occTargetFilter')?.value || 'all';
        const statusFilter = document.getElementById('occStatusFilter')?.value || 'all';
        
        document.querySelectorAll('.occasion-card-col').forEach(col => {
            let tType = col.dataset.targetType;
            if (tType === 'both') tType = 'all';
            const status = col.dataset.status;
            
            let matchTarget = (targetFilter === 'all' || tType === targetFilter);
            let matchStatus = (statusFilter === 'all' || (statusFilter === 'active' && status === '1') || (statusFilter === 'disabled' && status === '0'));
            
            col.style.display = (matchTarget && matchStatus) ? '' : 'none';
        });
    };

    document.getElementById('occTargetFilter')?.addEventListener('change', filterOccasions);
    document.getElementById('occStatusFilter')?.addEventListener('change', filterOccasions);
    document.getElementById('resetOccasionFilters')?.addEventListener('click', function() {
        if (document.getElementById('occTargetFilter')) document.getElementById('occTargetFilter').value = 'all';
        filterOccasions();
    });

    // ===== Preview Occasion Portal Banner =====
    document.querySelectorAll('.preview-occasion-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch('notifications.php?ajax=get_occasion&id=' + id)
            .then(r => r.json())
            .then(occ => {
                const roleBadge = document.getElementById('previewOccasionRoleBadge');
                const targetMap = {'all':'معاينة العرض للطلاب والمعلمين', 'student':'معاينة العرض لبوابة الطلاب فقط', 'teacher':'معاينة العرض لبوابة المعلمين فقط', 'both':'معاينة العرض للطلاب والمعلمين'};
                if (roleBadge) roleBadge.textContent = targetMap[occ.target_type] || 'معاينة العرض للبوابة';

                const theme = occ.theme || 'default';
                const gs = occ.gradient_start || '#3b82f6';
                const ge = occ.gradient_end || '#1d4ed8';
                const tc = occ.text_color || '#ffffff';
                const icon = occ.icon || 'fas fa-star';
                const emojiRaw = (occ.emoji || '').trim();
                let displayTitle = occ.title || '';
                if (emojiRaw && !displayTitle.includes(emojiRaw)) {
                    displayTitle = emojiRaw + ' ' + displayTitle;
                }
                const message = occ.message || '';
                const animType = occ.animation_type || 'fadeIn';
                const confetti = occ.show_confetti || 0;
                const key = occ.occasion_key || 'preview';

                const html = `
                <div class="occasion-banner occasion-${theme}" data-occasion="${key}" data-animation="${animType}" data-confetti="${confetti}"
                     style="background: linear-gradient(135deg, ${gs} 0%, ${ge} 100%); color: ${tc}; margin: 0 auto; width: 100%;">
                    <div class="occasion-decoration occasion-deco-${theme}"></div>
                    <button class="occasion-close" title="إغلاق" onclick="return false;">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="occasion-content">
                        <div class="occasion-icon">
                            <i class="${icon}"></i>
                        </div>
                        <div class="occasion-text">
                            <h4 class="occasion-title">${displayTitle}</h4>
                            <p class="occasion-message">${message}</p>
                        </div>
                    </div>
                </div>`;

                const container = document.getElementById('previewOccasionPortalContainer');
                if (container) container.innerHTML = html;
                
                new bootstrap.Modal(document.getElementById('previewOccasionModal')).show();
            });
        });
    });
    
    // ===== Send Push Notification =====
    document.querySelectorAll('.send-push-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('sendPushNotifId').value = this.dataset.id;
            document.getElementById('sendPushNotifName').textContent = this.dataset.title;
            new bootstrap.Modal(document.getElementById('sendPushNotifModal')).show();
        });
    });
    
    // ===== Push Notification card toggle with type selector =====
    const pushCard = document.getElementById('pushNotifCard');
    if (typeSelect && pushCard) {
        typeSelect.addEventListener('change', function() {
            pushCard.style.display = (this.value === 'public') ? 'none' : 'block';
        });
    }
    
    // ===== Load push subscription count =====
    const pushSubBadge = document.getElementById('pushSubCount');
    if (pushSubBadge) {
        fetch(window.location.origin + '/api/push_subscribe.php?count=1')
            .then(r => r.json())
            .then(data => {
                if (data.count !== undefined) {
                    pushSubBadge.textContent = data.count + ' جهاز مسجل';
                }
            }).catch(() => {});
    }
});
</script>

</div><!-- end .notifications-page -->
