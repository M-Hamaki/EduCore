/**
 * User Services Customization - Shared JS
 * Used in admin/students.php and admin/teachers.php
 * Allows per-user service overrides via SweetAlert2 dialog
 */
(function() {
    // Service definitions
    const studentServices = {
        'rewards': 'نظام المكافآت',
        'reports': 'التقارير الشهرية',
        'materials': 'المواد التعليمية',
        'ebooks': 'الكتب الإلكترونية',
        'results': 'النتائج',
        'timetable': 'الجدول الدراسي'
    };

    const teacherServices = {
        'rewards': 'نظام المكافآت',
        'lesson_prep': 'تحضير الدروس بالذكاء الاصطناعي',
        'grade_system': 'نظام رصد الدرجات',
        'attendance': 'نظام الحضور والغياب',
        'timetable': 'الجدول المدرسي',
        'training': 'التطوير المهني والتدريبات'
    };

    const serviceIcons = {
        'rewards': 'fas fa-star',
        'reports': 'fas fa-file-alt',
        'materials': 'fas fa-book',
        'ebooks': 'fas fa-tablet-alt',
        'results': 'fas fa-chart-bar',
        'timetable': 'fas fa-calendar-alt',
        'lesson_prep': 'fas fa-robot',
        'grade_system': 'fas fa-clipboard-list',
        'attendance': 'fas fa-user-check',
        'training': 'fas fa-chalkboard-teacher'
    };

    // Inject CSS for the dialog (matches admin Bootstrap theme)
    const style = document.createElement('style');
    style.textContent = `
        .swal-services-popup {
            font-family: 'Tajawal', 'Segoe UI', sans-serif !important;
            border-radius: 0.5rem !important;
        }
        .swal-services-popup .swal2-title {
            font-family: 'Tajawal', 'Segoe UI', sans-serif !important;
            font-size: 1.15rem !important;
            font-weight: 700 !important;
            color: #212529 !important;
            padding: 1rem 1.5rem 0.5rem !important;
        }
        .swal-services-popup .swal2-html-container {
            font-family: 'Tajawal', 'Segoe UI', sans-serif !important;
            padding: 0 1.25rem !important;
            margin: 0 !important;
        }
        .swal-services-popup .swal2-actions {
            gap: 0.5rem;
        }
        .swal-services-popup .swal2-confirm {
            border-radius: 0.375rem !important;
            font-family: 'Tajawal', sans-serif !important;
            font-weight: 600 !important;
            padding: 0.4rem 1.2rem !important;
            font-size: 0.9rem !important;
        }
        .swal-services-popup .swal2-deny {
            border-radius: 0.375rem !important;
            font-family: 'Tajawal', sans-serif !important;
            font-weight: 600 !important;
            padding: 0.4rem 1.2rem !important;
            font-size: 0.9rem !important;
        }
        .swal-services-popup .swal2-cancel {
            border-radius: 0.375rem !important;
            font-family: 'Tajawal', sans-serif !important;
            font-weight: 600 !important;
            padding: 0.4rem 1.2rem !important;
            font-size: 0.9rem !important;
        }
        .svc-user-badge {
            display: inline-block;
            background: #0d6efd;
            color: #fff;
            padding: 4px 16px;
            border-radius: 0.375rem;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 12px;
        }
        .svc-status-alert {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 0.375rem;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .svc-status-alert.override {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #664d03;
        }
        .svc-status-alert.default {
            background: #cff4fc;
            border: 1px solid #0dcaf0;
            color: #055160;
        }
        .svc-stage-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 8px 14px;
            font-size: 0.82rem;
            color: #6c757d;
            margin-bottom: 14px;
        }
        .svc-stage-info.empty {
            background: #fff3cd;
            border-color: #ffc107;
            color: #664d03;
        }
        .svc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .svc-check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 0.375rem;
            border: 1px solid #dee2e6;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.15s, background-color 0.15s;
            user-select: none;
        }
        .svc-check-item:hover {
            border-color: #0d6efd;
            background: #f0f6ff;
        }
        .svc-check-item.checked {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        .svc-check-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #0d6efd;
            cursor: pointer;
            flex-shrink: 0;
        }
        .svc-check-item i {
            color: #0d6efd;
            font-size: 1rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        .svc-check-item span {
            font-weight: 600;
            font-size: 0.85rem;
            color: #212529;
        }
    `;
    document.head.appendChild(style);

    function buildServiceCheckboxes(role, selectedServices, stageServices) {
        const allServices = role === 'student' ? studentServices : teacherServices;
        const hasOverride = selectedServices !== null;
        const activeServices = hasOverride ? selectedServices : stageServices;

        let html = '';

        // Status indicator
        if (hasOverride) {
            html += `<div class="svc-status-alert override">
                <i class="fas fa-user-cog"></i>
                <span>تخصيص فردي مُفعّل — يتجاوز إعدادات المرحلة</span>
            </div>`;
        } else {
            html += `<div class="svc-status-alert default">
                <i class="fas fa-layer-group"></i>
                <span>يستخدم إعدادات المرحلة الافتراضية</span>
            </div>`;
        }

        // Stage services info
        if (stageServices && stageServices.length > 0) {
            html += `<div class="svc-stage-info">
                <i class="fas fa-info-circle me-1"></i>
                خدمات المرحلة الحالية: <strong>${stageServices.map(s => allServices[s] || s).join('، ')}</strong>
            </div>`;
        } else {
            html += `<div class="svc-stage-info empty">
                <i class="fas fa-exclamation-triangle me-1"></i>
                لا توجد خدمات محددة في المرحلة
            </div>`;
        }

        // Checkboxes
        html += '<div class="svc-grid">';
        for (const [key, label] of Object.entries(allServices)) {
            const checked = activeServices && activeServices.includes(key);
            const icon = serviceIcons[key] || 'fas fa-cog';
            html += `
            <label class="svc-check-item${checked ? ' checked' : ''}">
                <input type="checkbox" name="svc_${key}" value="${key}" ${checked ? 'checked' : ''}
                       onchange="this.closest('.svc-check-item').classList.toggle('checked', this.checked)">
                <i class="${icon}"></i>
                <span>${label}</span>
            </label>`;
        }
        html += '</div>';

        return html;
    }

    function openServiceCustomization(userId, userName, role) {
        // Fetch current settings
        fetch(`../includes/ajax_handlers.php?action=get_user_services&user_id=${userId}&role=${role}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire('خطأ', data.message || 'حدث خطأ', 'error');
                    return;
                }

                const roleLabel = role === 'student' ? 'الطالب' : 'المعلم';
                const checkboxesHtml = buildServiceCheckboxes(role, data.user_services, data.stage_services);

                Swal.fire({
                    title: `<i class="fas fa-cogs me-2" style="color: #0d6efd;"></i> تخصيص خدمات ${roleLabel}`,
                    html: `
                        <div style="text-align: center; margin-bottom: 12px;">
                            <span class="svc-user-badge">
                                <i class="fas fa-user me-1"></i>${userName}
                            </span>
                        </div>
                        <div style="text-align: right; direction: rtl;">
                            ${checkboxesHtml}
                        </div>
                    `,
                    width: 560,
                    showCancelButton: true,
                    showDenyButton: data.user_services !== null,
                    confirmButtonText: '<i class="fas fa-save me-1"></i> حفظ التخصيص',
                    cancelButtonText: 'إلغاء',
                    denyButtonText: '<i class="fas fa-undo me-1"></i> إعادة للافتراضي',
                    confirmButtonColor: '#0d6efd',
                    denyButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d',
                    reverseButtons: true,
                    customClass: {
                        popup: 'swal-services-popup swal2-rtl'
                    },
                    didOpen: () => {
                        const popup = Swal.getPopup();
                        popup.style.direction = 'rtl';
                    },
                    preConfirm: () => {
                        const checkboxes = Swal.getPopup().querySelectorAll('input[type="checkbox"]:checked');
                        const selected = Array.from(checkboxes).map(cb => cb.value);
                        return selected;
                    }
                }).then(result => {
                    if (result.isConfirmed) {
                        // Save custom services
                        const formData = new FormData();
                        formData.append('action', 'save_user_services');
                        formData.append('user_id', userId);
                        formData.append('role', role);
                        result.value.forEach(s => formData.append('services[]', s));

                        fetch('../includes/ajax_handlers.php', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(res => {
                                if (res.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'تم الحفظ',
                                        text: res.message,
                                        timer: 2000,
                                        showConfirmButton: false
                                    });
                                    updateButtonState(userId, role, true);
                                } else {
                                    Swal.fire('خطأ', res.message, 'error');
                                }
                            })
                            .catch(() => Swal.fire('خطأ', 'فشل الاتصال بالسيرفر', 'error'));

                    } else if (result.isDenied) {
                        // Reset to default
                        Swal.fire({
                            title: 'تأكيد إعادة التعيين',
                            text: `هل تريد إعادة خدمات ${userName} للإعدادات الافتراضية (إعدادات المرحلة)؟`,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'نعم، إعادة تعيين',
                            cancelButtonText: 'إلغاء',
                            confirmButtonColor: '#ffc107',
                            reverseButtons: true,
                            customClass: { popup: 'swal-services-popup swal2-rtl' },
                            didOpen: () => { Swal.getPopup().style.direction = 'rtl'; }
                        }).then(r2 => {
                            if (r2.isConfirmed) {
                                const formData = new FormData();
                                formData.append('action', 'reset_user_services');
                                formData.append('user_id', userId);
                                formData.append('role', role);

                                fetch('../includes/ajax_handlers.php', { method: 'POST', body: formData })
                                    .then(r => r.json())
                                    .then(res => {
                                        if (res.success) {
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'تمت إعادة التعيين',
                                                text: res.message,
                                                timer: 2000,
                                                showConfirmButton: false
                                            });
                                            updateButtonState(userId, role, false);
                                        } else {
                                            Swal.fire('خطأ', res.message, 'error');
                                        }
                                    })
                                    .catch(() => Swal.fire('خطأ', 'فشل الاتصال بالسيرفر', 'error'));
                            }
                        });
                    }
                });
            })
            .catch(() => {
                Swal.fire('خطأ', 'فشل تحميل بيانات الخدمات', 'error');
            });
    }

    function updateButtonState(userId, role, hasOverride) {
        const btn = document.querySelector(`.customize-services[data-id="${userId}"][data-role="${role}"]`);
        if (!btn) return;
        if (hasOverride) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-outline-info');
            btn.title = 'تخصيص الخدمات (مُخصّص)';
        } else {
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-outline-secondary');
            btn.title = 'تخصيص الخدمات';
        }
        // Re-init tooltip
        if (window.bootstrap) {
            const existing = bootstrap.Tooltip.getInstance(btn);
            if (existing) existing.dispose();
            new bootstrap.Tooltip(btn);
        }
    }

    // Event delegation
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.customize-services');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();

        const userId = btn.getAttribute('data-id');
        const userName = btn.getAttribute('data-name');
        const role = btn.getAttribute('data-role');

        openServiceCustomization(userId, userName, role);
    });

    // On page load, mark buttons that have overrides
    document.addEventListener('DOMContentLoaded', function() {
        const buttons = document.querySelectorAll('.customize-services');
        if (buttons.length === 0) return;

        const role = buttons[0].getAttribute('data-role');

        // Only check visible page users (DataTables may paginate)
        const visibleButtons = Array.from(buttons).slice(0, 50);
        visibleButtons.forEach(btn => {
            const userId = btn.getAttribute('data-id');
            fetch(`../includes/ajax_handlers.php?action=get_user_services&user_id=${userId}&role=${role}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.user_services !== null) {
                        updateButtonState(userId, role, true);
                    }
                })
                .catch(() => {});
        });
    });
})();
