<?php if ($staffServerSide): ?>
<script src="../assets/js/admin-server-side-table.js"></script>
<?php endif; ?>
<script>
function openDeleteStaffModal(button) {
    if (!button || !window.bootstrap) {
        return;
    }
    document.getElementById('delete_staff_id').value = button.dataset.id || '';
    document.getElementById('delete_staff_name').textContent = button.dataset.name || '';
    new bootstrap.Modal(document.getElementById('deleteStaffModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);

    // Delegated handlers for DataTables-managed rows
    document.addEventListener('click', function(e) {
        var deleteBtn = e.target.closest('.delete-staff');
        if (deleteBtn) {
            openDeleteStaffModal(deleteBtn);
            return;
        }

    });

    if (<?php echo $staffServerSide ? 'true' : 'false'; ?> && window.AdminServerSideTable) {
        var staffJobTitleFilter = document.getElementById('staffJobTitleFilter');
        var staffForceFilter = document.getElementById('staffForceFilter');
        var staffWorkStatusFilter = document.getElementById('staffWorkStatusFilter');
        var totalCountBadge = document.getElementById('staffTotalCountBadge');
        var staffTable = window.AdminServerSideTable.init({
            selector: '#staffTable',
            url: 'ajax_staff_datatable.php',
            order: [[3, 'asc']],
            dtOptions: { pageLength: 50 },
            requestData: function () {
                return {
                    job_title: !staffJobTitleFilter || staffJobTitleFilter.value === 'all' ? '' : staffJobTitleFilter.value,
                    force: !staffForceFilter || staffForceFilter.value === 'all' ? '' : staffForceFilter.value,
                    work_status: !staffWorkStatusFilter || staffWorkStatusFilter.value === 'all' ? '' : staffWorkStatusFilter.value
                };
            },
            language: { processing: '<div class="admin-list-loading"><i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل العاملين…</div>' },
            decorateRow: function (row) { row.lastElementChild.classList.add('actions-column', 'admin-table-actions'); },
            onDraw: function (api) {
                document.querySelectorAll('#tableSettingsModal .col-toggle-checkbox').forEach(function (cb) { applyColumnVisibility(cb.getAttribute('data-column'), cb.checked); });
                if (totalCountBadge) totalCountBadge.textContent = api.page.info().recordsDisplay;
            }
        });

        function applyStaffFilters() { if (staffTable) staffTable.ajax.reload(null, true); }
        document.getElementById('resetStaffFilters')?.addEventListener('click', function() {
            if (staffJobTitleFilter) staffJobTitleFilter.value = 'all';
            if (staffForceFilter) staffForceFilter.value = 'all';
            if (staffWorkStatusFilter) staffWorkStatusFilter.value = 'all';
            if (staffTable) { staffTable.search('').draw(); }
        });
        staffJobTitleFilter?.addEventListener('change', applyStaffFilters);
        staffForceFilter?.addEventListener('change', applyStaffFilters);
        staffWorkStatusFilter?.addEventListener('change', applyStaffFilters);
    }

    // ===== ثبات التبويب عبر عنوان URL فقط =====
    // لا نستعيد آخر تبويب من الجلسة عند فتح مودال جديد؛ يبدأ دائماً من البيانات الأساسية.
    const tabLinks = document.querySelectorAll('#staffTabs button[data-bs-toggle="tab"]');
    const activeTabInput = document.getElementById('active_tab_input');
    const urlTab = urlParams.get('tab');
    if (urlTab) {
        const targetId = '#pane-' + urlTab;
        const tabEl = document.querySelector(`button[data-bs-target="${targetId}"]`);
        if (tabEl && !tabEl.classList.contains('active')) {
            bootstrap.Tab.getInstance(tabEl)?.show() || new bootstrap.Tab(tabEl).show();
        }
    } else if (activeTabInput) {
        activeTabInput.value = 'basic';
    }

    // تحديث التبويب أثناء التحرير فقط؛ يبقى عنوان URL مرجعاً وحيداً بعد إعادة التحميل.
    tabLinks.forEach(link => {
        link.addEventListener('shown.bs.tab', function (e) {
            const target = e.target.getAttribute('data-bs-target');
            if (activeTabInput) { activeTabInput.value = target.replace('#pane-', ''); }

            const newUrl = new URL(window.location);
            newUrl.searchParams.set('tab', target.replace('#pane-', ''));
            window.history.replaceState({}, '', newUrl);
        });
    });
});
</script>

<!-- Print Styles -->
<style type="text/css" media="print">
    @page { size: A4; margin: 1cm; }
    .navbar, .btn-toolbar, .modal, .card-header .d-flex, .btn, .alert, footer, .pagination, .border-bottom { display: none !important; }
    .table { font-size: 12px; border-collapse: collapse; }
    .table th, .table td { border: 1px solid #000 !important; padding: 8px; text-align: center; }
    .table thead th { background-color: #f8f9fa !important; font-weight: bold; }
    .table th:last-child, .table td:last-child { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .badge { background-color: transparent !important; color: #000 !important; border: 1px solid #000 !important; }
</style>



<!-- Table Settings Modal -->
<div class="modal fade staff-table-settings" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="tableSettingsModalLabel"><i class="fas fa-cog me-2"></i>تخصيص أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom no-print">
                    <p class="text-muted small mb-0">اختر الأعمدة التي ترغب في عرضها في جدول العاملين. التغييرات تُحفظ تلقائياً.</p>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm px-3" id="selectAllColumns"><i class="fas fa-check-double me-1"></i>تحديد الكل</button>
                        <button type="button" class="btn btn-secondary btn-sm px-3" id="deselectAllColumns"><i class="fas fa-times me-1"></i>إلغاء الكل</button>
                    </div>
                </div>

                <?php
                // تعريف الأعمدة حسب تبويبات نموذج العامل؛ العناصر العادية:
                // [colClass, id, label, defaultChecked] والعناوين الفرعية: ['__header__', 'العنوان']
                $staffColumnSections = [
                    'البيانات الأساسية' => [
                        ['__header__', 'البيانات الشخصية'],
                        ['col-name', 'chk_name', 'الاسم باللغة العربية', true],
                        ['col-name-en', 'chk_name_en', 'الاسم باللغة الإنجليزية', false],
                        ['col-religion', 'chk_religion', 'الديانة', false],
                        ['col-gender', 'chk_gender', 'النوع', false],
                        ['col-nationality', 'chk_nationality', 'الجنسية', false],
                        ['col-national-id', 'chk_national_id', 'الرقم القومي', false],
                        ['col-passport', 'chk_passport', 'رقم جواز السفر', false],
                        ['col-birth-date', 'chk_birth_date', 'تاريخ الميلاد', false],
                        ['col-current-age', 'chk_current_age', 'العمر الحالي', false],
                        ['col-birth-place', 'chk_birth_place', 'محل الميلاد', false],
                        ['col-code', 'chk_code', 'كود الموظف', true],
                        ['col-ministry-code', 'chk_ministry_code', 'كود الوزارة', false],
                        ['col-biometric', 'chk_biometric', 'رقم البصمة', false],
                        ['col-military', 'chk_military', 'موقف التجنيد', false],
                        ['col-public-service', 'chk_public_service', 'موقف الخدمة العامة', false],

                        ['__header__', 'البيانات الاجتماعية'],
                        ['col-marital', 'chk_marital', 'الحالة الاجتماعية', false],
                        ['col-children', 'chk_children', 'عدد الأبناء', false],
                        ['col-social-notes', 'chk_social_notes', 'ملاحظات اجتماعية', false],

                        ['__header__', 'العناوين وبيانات التواصل'],
                        ['col-city-area', 'chk_city_area', 'المدينة / المنطقة', false],
                        ['col-address', 'chk_address', 'العنوان التفصيلي', false],
                        ['col-mobile', 'chk_mobile', 'رقم المحمول', true],
                        ['col-phone-home', 'chk_phone_home', 'الهاتف الأرضي', false],
                        ['col-email', 'chk_email', 'البريد الإلكتروني الشخصي', false],
                        ['col-phone-emergency', 'chk_phone_emergency', 'هاتف الطوارئ', false],
                        ['col-emergency-contact', 'chk_emergency_contact', 'اسم شخص الطوارئ', false],
                        ['col-extra-phones', 'chk_extra_phones', 'أرقام اتصال إضافية', false],

                        ['__header__', 'إضافة بيانات أخرى'],
                        ['col-extra-data', 'chk_extra_data', 'بيانات أساسية إضافية', false],

                        ['__header__', 'ملاحظة إدارية'],
                        ['col-admin-notes', 'chk_admin_notes', 'الملاحظة الإدارية', false],
                    ],
                    'البيانات الوظيفية' => [
                        ['__header__', 'بيانات التعاقد والسجل الوظيفي'],
                        ['col-job-title', 'chk_job_title', 'المسمى الوظيفي', true],
                        ['col-department', 'chk_department', 'القسم / القوة التابعة', false],
                        ['col-job-grade', 'chk_job_grade', 'الدرجة الوظيفية', false],
                        ['col-contract-type', 'chk_contract_type', 'نوع العقد', false],
                        ['col-hire-date', 'chk_hire_date', 'تاريخ التعيين', false],
                        ['col-contract-start', 'chk_contract_start', 'تاريخ بداية التعاقد', false],
                        ['col-contract-end', 'chk_contract_end', 'تاريخ نهاية التعاقد', false],
                        ['col-status', 'chk_status', 'الحالة الوظيفية الحالية', true],
                        ['col-status-reason', 'chk_status_reason', 'سبب الحالة الوظيفية', false],
                        ['col-status-effective', 'chk_status_effective', 'تاريخ سريان الحالة', false],
                        ['col-first-hire', 'chk_first_hire', 'أول تاريخ تعيين', false],
                        ['col-latest-hire', 'chk_latest_hire', 'آخر تاريخ تعيين', false],
                        ['col-last-working-day', 'chk_last_working_day', 'آخر يوم عمل', false],
                        ['col-can-rehire', 'chk_can_rehire', 'إمكانية إعادة التعيين', false],
                        ['col-status-history', 'chk_status_history', 'سجل الحالات الوظيفية', false],

                        ['__header__', 'الترقيات والتدرج الوظيفي'],
                        ['col-last-job-movement', 'chk_last_job_movement', 'تاريخ آخر حركة وظيفية', false],
                        ['col-job-movements', 'chk_job_movements', 'سجل الحركات الوظيفية', false],

                        ['__header__', 'إضافة بيانات أخرى'],
                        ['col-extra-employment', 'chk_extra_employment', 'بيانات وظيفية إضافية', false],
                    ],
                    'المؤهلات والخبرات' => [
                        ['__header__', 'المؤهلات العلمية'],
                        ['col-qualification', 'chk_qualification', 'المؤهل العلمي', false],
                        ['col-qual-year', 'chk_qual_year', 'سنة التخرج', false],
                        ['col-qual-uni', 'chk_qual_uni', 'الجامعة / المعهد', false],
                        ['col-specialization', 'chk_specialization', 'التخصص', false],

                        ['__header__', 'المؤهلات الدراسية والشهادات العلمية الأخرى'],
                        ['col-other-qualifications', 'chk_other_qualifications', 'المؤهلات الأخرى', false],

                        ['__header__', 'الدورات التدريبية والشهادات العلمية'],
                        ['col-training-courses', 'chk_training_courses', 'الدورات والشهادات', false],

                        ['__header__', 'الخبرات وأماكن العمل السابقة'],
                        ['col-experience', 'chk_experience', 'سنوات الخبرة', false],
                        ['col-work-history', 'chk_work_history', 'أماكن العمل السابقة', false],
                    ],
                    'البيانات الصحية والنفسية' => [
                        ['__header__', 'الحالة الصحية'],
                        ['col-blood-type', 'chk_blood_type', 'فصيلة الدم', false],
                        ['col-insurance-number', 'chk_insurance_number', 'رقم التأمين الصحي', false],
                        ['col-insurance-start', 'chk_insurance_start', 'بداية التأمين', false],
                        ['col-insurance-end', 'chk_insurance_end', 'نهاية التأمين', false],
                        ['col-health-status', 'chk_health_status', 'الحالة الصحية العامة', false],
                        ['col-chronic', 'chk_chronic', 'الأمراض المزمنة', false],
                        ['col-allergies', 'chk_allergies', 'الحساسية', false],
                        ['col-disabilities', 'chk_disabilities', 'الإعاقات', false],
                        ['col-medications', 'chk_medications', 'الأدوية المستخدمة', false],
                        ['col-treatment', 'chk_treatment', 'خطة العلاج', false],
                        ['col-medical-reports', 'chk_medical_reports', 'التقارير الطبية السابقة', false],
                        ['col-emergency-notes', 'chk_emergency_notes', 'ملاحظات طبية طارئة', false],

                        ['__header__', 'الحالة النفسية والسلوكية'],
                        ['col-psychological', 'chk_psychological', 'ملاحظات نفسية وسلوكية', false],
                    ],
                    'المرفقات' => [
                        ['__header__', 'مرفقات الموظف'],
                        ['col-profile-image', 'chk_profile_image', 'الصورة الشخصية', false],
                        ['col-attachments', 'chk_attachments', 'عدد المرفقات', false],
                    ],
                ];
                // أيقونات مطابقة لتبويبات نموذج العامل الفعلية
                $staffSectionIcons = [
                    'البيانات الأساسية' => 'fas fa-id-card',
                    'البيانات الوظيفية' => 'fas fa-file-contract',
                    'المؤهلات والخبرات' => 'fas fa-graduation-cap',
                    'البيانات الصحية والنفسية' => 'fas fa-heartbeat',
                    'المرفقات' => 'fas fa-paperclip',
                ];
                ?>
                <div class="staff-table-settings-sections">
                    <?php $settingsSectionIndex = 0; foreach ($staffColumnSections as $sectionTitle => $cols):
                        $staffSectionIcon = $staffSectionIcons[$sectionTitle] ?? 'fas fa-folder-open';
                    ?>
                        <section class="staff-table-settings-stack-item" id="staff-settings-section-<?php echo $settingsSectionIndex; ?>">
                            <div class="card mb-4 border shadow-sm staff-table-settings-section">
                                <div class="card-header border-0 d-flex justify-content-between align-items-center py-3 staff-table-settings-section-header">
                                    <h6 class="mb-0 fw-bold text-primary d-flex align-items-center staff-table-settings-section-title">
                                        <span class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-circle shadow-sm me-2 staff-table-settings-section-icon">
                                            <i class="<?php echo htmlspecialchars($staffSectionIcon); ?>"></i>
                                        </span>
                                        <span><?php echo htmlspecialchars($sectionTitle); ?></span>
                                    </h6>
                                    <div class="d-flex gap-1" role="group">
                                        <button type="button" class="btn btn-outline-success btn-sm select-section px-2 py-1"
                                            data-target-section="<?php echo htmlspecialchars($sectionTitle); ?>" title="تحديد القسم">
                                            <i class="fas fa-check"></i> تحديد
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm deselect-section px-2 py-1"
                                            data-target-section="<?php echo htmlspecialchars($sectionTitle); ?>" title="إلغاء القسم">
                                            <i class="fas fa-times"></i> إلغاء
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body py-3">
                                    <div class="row g-3" data-section="<?php echo htmlspecialchars($sectionTitle); ?>">
                                        <?php foreach ($cols as $c): ?>
                                            <?php if ($c[0] === '__header__'): ?>
                                                <div class="col-12">
                                                    <div class="d-flex align-items-center mb-1 mt-2 staff-table-settings-subheading">
                                                        <span class="badge rounded-pill text-bg-light border me-2">
                                                            <i class="fas fa-layer-group me-1 text-primary"></i>
                                                            <?php echo htmlspecialchars($c[1]); ?>
                                                        </span>
                                                        <hr class="flex-grow-1 my-0">
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="col-lg-3 col-md-4 col-sm-6 col-6">
                                                    <div class="form-check form-switch staff-table-settings-switch">
                                                        <input class="form-check-input col-toggle-checkbox" type="checkbox" role="switch"
                                                            id="<?php echo $c[1]; ?>" data-column="<?php echo $c[0]; ?>" <?php echo $c[3] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label text-secondary fw-medium" for="<?php echo $c[1]; ?>"><?php echo htmlspecialchars($c[2]); ?></label>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php $settingsSectionIndex++; endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- نافذة عرض محتوى الخلية (للنصوص الطويلة) -->
<div class="modal fade" id="cellContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="cellContentTitle"><i class="fas fa-info-circle me-2"></i></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="cellContentBody" class="text-dark" style="white-space: pre-wrap; word-wrap: break-word;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
// ===== إعدادات أعمدة الجدول (تطبيق مباشر عبر class) =====
function applyColumnVisibility(colClass, isVisible) {
    document.querySelectorAll('.' + colClass).forEach(function (el) {
        if (isVisible) { el.classList.remove('d-none'); }
        else { el.classList.add('d-none'); }
    });
}
document.addEventListener('DOMContentLoaded', function () {
    var checkboxes = document.querySelectorAll('#tableSettingsModal .col-toggle-checkbox');
    var storageKey = 'staff_table_columns';
    var prefs = {};
    try { prefs = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { prefs = {}; }

    checkboxes.forEach(function (cb) {
        var colClass = cb.getAttribute('data-column');
        if (!colClass) return;
        var isVisible = prefs.hasOwnProperty(colClass) ? prefs[colClass] : cb.checked;
        cb.checked = isVisible;
        applyColumnVisibility(colClass, isVisible);
        cb.addEventListener('change', function () {
            applyColumnVisibility(colClass, this.checked);
            prefs[colClass] = this.checked;
            localStorage.setItem(storageKey, JSON.stringify(prefs));
        });
    });

    // ===== أزرار تحديد الكل / إلغاء الكل / تحديد القسم =====
    // نُطلق حدث 'change' على كل checkbox ليُطبّق الإخفاء/الإظهار ويُحفظ في localStorage تلقائياً.
    function toggleAll(checked) {
        document.querySelectorAll('#tableSettingsModal .col-toggle-checkbox').forEach(function (cb) {
            if (cb.checked !== checked) {
                cb.checked = checked;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }
    var btnAll = document.getElementById('selectAllColumns');
    var btnNone = document.getElementById('deselectAllColumns');
    if (btnAll) btnAll.addEventListener('click', function () { toggleAll(true); });
    if (btnNone) btnNone.addEventListener('click', function () { toggleAll(false); });

    document.querySelectorAll('#tableSettingsModal .select-section').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var section = this.getAttribute('data-target-section');
            document.querySelectorAll('#tableSettingsModal .row[data-section="' + section + '"] .col-toggle-checkbox').forEach(function (cb) {
                if (!cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); }
            });
        });
    });
    document.querySelectorAll('#tableSettingsModal .deselect-section').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var section = this.getAttribute('data-target-section');
            document.querySelectorAll('#tableSettingsModal .row[data-section="' + section + '"] .col-toggle-checkbox').forEach(function (cb) {
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
            });
        });
    });

    // ===== معالج النقر على أيقونات النصوص الطويلة لفتح النافذة المنبثقة =====
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.view-cell-content');
        if (!btn) return;
        e.preventDefault();
        var title = btn.getAttribute('data-title') || 'التفاصيل';
        var content = btn.getAttribute('data-content') || '';
        var titleEl = document.getElementById('cellContentTitle');
        var bodyEl = document.getElementById('cellContentBody');
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-info-circle me-2"></i>' + title;
        if (bodyEl) bodyEl.textContent = content;
        var modalEl = document.getElementById('cellContentModal');
        if (modalEl) { bootstrap.Modal.getOrCreateInstance(modalEl).show(); }
    });
});

// وظيفة تصدير جدول الموظفين لملف CSV


function exportStaffTableToCSV() {
    const table = document.getElementById("staffTable");
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll("tr");

    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll("td, th");
        // تخطي عمود الإجراءات (الأخير)
        for (let j = 0; j < cols.length - 1; j++) {
            let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        csv.push(row.join(","));
    }

    const csvContent = "\uFEFF" + csv.join("\n");
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);

    link.setAttribute("href", url);
    link.setAttribute("download", "staff_list_" + new Date().toISOString().slice(0,10) + ".csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
