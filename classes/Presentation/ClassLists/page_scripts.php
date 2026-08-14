<script>

function toArabicNumerals(num) {
    var indianNumerals = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    return num.toString().replace(/[0-9]/g, function(d) {
        return indianNumerals[d];
    });
}

// ===== Dynamic Class Student Lists =====
var loadedClasses = {
    <?php foreach ($filteredClasses as $cl) echo $cl['id'] . ': true, '; ?>
};

var colMapping = {
    toggleSerialAr: 1,
    toggleCode: 2,
    toggleNameAr: 3,
    toggleGender: 4,
    toggleGenderEn: 5,
    toggleNameEn: 6,
    toggleCodeEn: 7,
    toggleSerialEn: 8,
    toggleClassAr: null,
    toggleClassEn: null
};

function escapeHtml(text) {
    if (!text) return '';
    var d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function loadClassStudents(classId) {
    if (loadedClasses[classId]) return;
    loadedClasses[classId] = true;

    var placeholder = document.getElementById('class-card-' + classId);
    var isNew = false;
    if (!placeholder) {
        placeholder = document.createElement('div');
        placeholder.id = 'class-card-' + classId;
        placeholder.className = 'card shadow mb-4 admin-card-surface';
        isNew = true;
    }
    placeholder.innerHTML = '<div class="card-body text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل بيانات الفصل...</div>';

    if (isNew) {
        var container = document.getElementById('classListsContainer');
        if (container) {
            container.appendChild(placeholder);
        }
    }

    var sortOrder = document.getElementById('sortOrderInput') ? document.getElementById('sortOrderInput').value : 'ar_alpha';
    fetch('class_lists.php?ajax_get_class_students=1&class_id=' + classId + '&sort_order=' + sortOrder)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                placeholder.innerHTML = '<div class="card-body text-center text-danger py-3"><i class="fas fa-exclamation-circle me-1"></i>' + escapeHtml(data.message || 'خطأ') + '</div>';
                return;
            }
            var cd = data.classData;
            var students = data.students;
            var html = '';
            html += '<div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">';
            html += '<h6 class="mb-0"><i class="fas fa-users me-2"></i>' + escapeHtml(cd.name) + ' — ' + escapeHtml(cd.grade_name) + ' (' + escapeHtml(cd.stage_name) + ')</h6>';
            html += '<div class="no-print d-flex align-items-center gap-2">';
            html += '<button type="button" class="btn btn-sm bulk-transfer-btn d-none" data-class-id="' + cd.id + '" data-grade-id="' + cd.grade_id + '" data-class-name="' + escapeHtml(cd.name) + '"><i class="fas fa-random me-1"></i>نقل جماعي (<span class="bulk-selected-count">0</span>)</button>';
            html += '<span class="badge d-inline-flex align-items-center justify-content-center text-primary-emphasis" style="height: 30px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; padding: 0 12px; margin: 0; background-color: rgba(59, 130, 246, 0.1); color: #1d4ed8; border: none;"><i class="fas fa-male text-primary me-1"></i>' + cd.male_count + '</span>';
            html += '<span class="badge d-inline-flex align-items-center justify-content-center text-danger-emphasis" style="height: 30px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; padding: 0 12px; margin: 0; background-color: rgba(239, 68, 68, 0.1); color: #b91c1c; border: none;"><i class="fas fa-female text-danger me-1"></i>' + cd.female_count + '</span>';
            html += '<span class="badge d-inline-flex align-items-center justify-content-center" style="height: 30px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; padding: 0 12px; margin: 0; background-color: rgba(100, 116, 139, 0.12); color: #475569; border: none;"><i class="fas fa-users text-secondary me-1"></i>' + cd.student_count + '</span>';
            html += '</div></div>';

            html += '<div class="card-body p-3">';
            if (students.length === 0) {
                html += '<div class="text-center text-muted py-3"><i class="fas fa-info-circle me-1"></i>لا يوجد طلاب في هذا الفصل</div>';
            } else {
                html += '<div class="table-responsive admin-table-wrap"><table class="table table-hover table-striped mb-0 admin-data-table" id="classStudentsTable_' + classId + '">';
                var showSerialAr = document.getElementById('toggleSerialAr').checked;
                var showCode = document.getElementById('toggleCode').checked;
                var showNameAr = document.getElementById('toggleNameAr').checked;
                var showGender = document.getElementById('toggleGender').checked;
                var showGenderEn = document.getElementById('toggleGenderEn').checked;
                var showNameEn = document.getElementById('toggleNameEn').checked;
                var showCodeEn = document.getElementById('toggleCodeEn').checked;
                var showSerialEn = document.getElementById('toggleSerialEn').checked;
                html += '<thead class="table-light"><tr>';
                html += '<th class="no-print text-center select-all-col" width="35"><input type="checkbox" class="form-check-input select-all-class-students" style="cursor: pointer;"></th>';
                html += '<th class="col-serial-ar" width="50"' + (!showSerialAr ? ' style="display:none"' : '') + '>#</th>';
                html += '<th class="col-code"' + (!showCode ? ' style="display:none"' : '') + '>كود الطالب</th>';
                html += '<th class="col-name-ar"' + (!showNameAr ? ' style="display:none"' : '') + '>اسم الطالب</th>';
                html += '<th class="text-center col-gender"' + (!showGender ? ' style="display:none"' : '') + '>النوع</th>';
                html += '<th class="text-center col-gender-en" style="text-align: left !important;' + (!showGenderEn ? 'display:none;' : '') + '">Gender</th>';
                html += '<th class="col-name-en" style="text-align: left !important;' + (!showNameEn ? 'display:none;' : '') + '">Student Name</th>';
                html += '<th class="col-code-en" style="text-align: left !important;' + (!showCodeEn ? 'display:none;' : '') + '">Code</th>';
                html += '<th class="text-center col-serial-en" style="text-align: left !important;' + (!showSerialEn ? 'display:none;' : '') + '">#</th>';
                html += '<th class="text-center no-print" width="60">نقل</th></tr></thead><tbody>';
                var transferredIds = [];
                try {
                    transferredIds = JSON.parse(sessionStorage.getItem('transferred_student_ids') || '[]').map(function(s) { return String(s).trim(); });
                } catch(e) {
                    transferredIds = [];
                }
                for (var i = 0; i < students.length; i++) {
                    var st = students[i];
                    var genderBadge = st.gender === 'male' ? '<span class="badge bg-primary-subtle text-primary px-2 py-1">ذكر</span>' : (st.gender === 'female' ? '<span class="badge bg-danger-subtle text-danger px-2 py-1">أنثى</span>' : '<span class="badge bg-secondary-subtle text-secondary px-2 py-1">-</span>');
                    var genderBadgeEn = st.gender === 'male' ? '<span class="badge bg-primary-subtle text-primary px-2 py-1">Male</span>' : (st.gender === 'female' ? '<span class="badge bg-danger-subtle text-danger px-2 py-1">Female</span>' : '<span class="badge bg-secondary-subtle text-secondary px-2 py-1">-</span>');
                    var isTransferred = transferredIds.includes(String(st.id).trim());
                    var rowClass = isTransferred ? 'class="highlight-transferred-row"' : '';

                    html += '<tr id="student-row-' + st.id + '" ' + rowClass + '>';
                    html += '<td class="no-print text-center select-student-col"><input type="checkbox" class="form-check-input select-student-chk" value="' + st.id + '" data-name="' + escapeHtml(st.name) + '" data-grade-id="' + cd.grade_id + '" data-class-id="' + classId + '" data-class-name="' + escapeHtml(cd.name) + '" style="cursor: pointer;"></td>';
                    html += '<td class="col-serial-ar"' + (!showSerialAr ? ' style="display:none"' : '') + '>' + toArabicNumerals(i + 1) + '</td>';
                    html += '<td class="col-code"' + (!showCode ? ' style="display:none"' : '') + '><span class="text-muted">' + escapeHtml(st.student_code || '-') + '</span></td>';
                    html += '<td class="col-name-ar"' + (!showNameAr ? ' style="display:none"' : '') + '><strong>' + escapeHtml(st.name) + '</strong></td>';
                    html += '<td class="text-center col-gender"' + (!showGender ? ' style="display:none"' : '') + '>' + genderBadge + '</td>';
                    html += '<td class="text-center col-gender-en" style="text-align: left !important;' + (!showGenderEn ? 'display:none;' : '') + '">' + genderBadgeEn + '</td>';
                    html += '<td class="col-name-en" dir="ltr" style="text-align: left !important;' + (!showNameEn ? 'display:none;' : '') + '">' + escapeHtml(st.name_en || '-') + '</td>';
                    html += '<td class="col-code-en" dir="ltr" style="text-align: left !important;' + (!showCodeEn ? 'display:none;' : '') + '><span class="text-muted">' + escapeHtml(st.student_code || '-') + '</span></td>';
                    html += '<td class="text-center col-serial-en" style="text-align: left !important;' + (!showSerialEn ? 'display:none;' : '') + '">' + (i + 1) + '</td>';
                    html += '<td class="text-center no-print"><button class="btn btn-action-pills btn-edit transfer-btn" data-id="' + st.id + '" data-name="' + escapeHtml(st.name) + '" data-grade-id="' + cd.grade_id + '" data-current-class="' + classId + '" data-bs-toggle="tooltip" title="نقل"><i class="fas fa-exchange-alt"></i></button></td>';
                    html += '</tr>';
                }
                html += '</tbody></table></div>';
            }
            html += '</div>';

            placeholder.innerHTML = html;

            placeholder.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });

            if (typeof initializeTableColumnSettings === 'function') {
                initializeTableColumnSettings('classStudentsTable_' + classId, colMapping, 'class_lists_columns');
            }
        })
        .catch(function() {
            placeholder.innerHTML = '<div class="card-body text-center text-danger py-3"><i class="fas fa-exclamation-circle me-1"></i>خطأ في الاتصال</div>';
        });
}

function removeClassCard(classId) {
    delete loadedClasses[classId];
    var card = document.getElementById('class-card-' + classId);
    if (card) {
        card.style.transition = 'opacity 0.3s, max-height 0.3s';
        card.style.opacity = '0';
        setTimeout(function() { card.remove(); }, 300);
    }
}

var classListSummaryRefreshTimer = null;

function refreshClassListSummary() {
    var url = new URL(window.location.href);
    url.searchParams.set('ajax_get_summary', '1');
    url.searchParams.delete('export_excel');
    url.searchParams.delete('print_all');

    return fetch(url.toString(), { credentials: 'same-origin' })
        .then(function(response) {
            if (!response.ok) throw new Error('تعذر تحديث إحصائيات قوائم الفصول.');
            return response.json();
        })
        .then(function(data) {
            if (!data || data.success !== true) throw new Error('استجابة الإحصائيات غير صالحة.');
            var values = {
                classListsTotalStudents: data.total_students,
                classListsTotalClasses: data.total_classes,
                classListsTotalMale: data.total_male,
                classListsTotalFemale: data.total_female
            };
            Object.keys(values).forEach(function(id) {
                var element = document.getElementById(id);
                if (!element) return;
                var value = Number.parseInt(values[id], 10);
                if (!Number.isFinite(value)) value = 0;
                element.dataset.target = String(value);
                element.textContent = value.toLocaleString('ar-EG');
            });
        })
        .catch(function(error) {
            console.error('Class list summary refresh failed:', error);
        });
}

function updateSummaryRowCount(classId, delta, studentId) {
    window.clearTimeout(classListSummaryRefreshTimer);
    classListSummaryRefreshTimer = window.setTimeout(refreshClassListSummary, 120);
}

document.addEventListener('DOMContentLoaded', function() {
    function showWarningAlert(message) {
        var msgEl = document.getElementById('genericAlertMessage');
        if (msgEl) {
            msgEl.textContent = message;
        }
        var modalEl = document.getElementById('genericAlertModal');
        if (modalEl) {
            new bootstrap.Modal(modalEl).show();
        }
    }
    window.showWarningAlert = showWarningAlert;

    // Clear highlights if we navigated from a different page (ignore refreshes/reloads)
    var referrer = document.referrer;
    var isReload = false;
    if (window.performance && window.performance.getEntriesByType) {
        var navs = window.performance.getEntriesByType("navigation");
        if (navs.length > 0 && navs[0].type === "reload") {
            isReload = true;
        }
    }
    if (!isReload && referrer && !referrer.includes('class_lists.php')) {
        sessionStorage.removeItem('transferred_student_ids');
    }

    // Initialize tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });

    // ===== Column Toggle Mapping & Initialization via admin_table_actions.js =====
    // Ensure column checkboxes and options show saved localStorage states on page load
    var urlParams = new URLSearchParams(window.location.search);
    var activeTab = '<?php echo $activeTab; ?>';

    function loadModalSettings() {
        var colKey = activeTab === 'custom' ? 'class_lists_columns_custom' : 'class_lists_columns';
        var savedCols = {};
        try {
            savedCols = JSON.parse(localStorage.getItem(colKey) || '{}');
        } catch(e) {}

        Object.keys(colMapping).forEach(function(checkboxId) {
            var cb = document.getElementById(checkboxId);
            if (cb) {
                if (Object.prototype.hasOwnProperty.call(savedCols, checkboxId)) {
                    cb.checked = !!savedCols[checkboxId];
                }
            }
        });

        var prefix = activeTab === 'custom' ? '_custom' : '';

        if (!urlParams.has('sort_order')) {
            var sortVal = localStorage.getItem('class_lists_sort_order' + prefix);
            if (sortVal && document.getElementById('listSortOrder')) {
                document.getElementById('listSortOrder').value = sortVal;
                if (document.getElementById('sortOrderInput')) {
                    document.getElementById('sortOrderInput').value = sortVal;
                }
            }
        } else {
            localStorage.setItem('class_lists_sort_order' + prefix, urlParams.get('sort_order'));
        }

        if (!urlParams.has('print_layout_lang')) {
            var langVal = localStorage.getItem('class_lists_print_layout_lang' + prefix);
            if (langVal && document.getElementById('printLayoutLang')) {
                document.getElementById('printLayoutLang').value = langVal;
                if (document.getElementById('printLayoutLangInput')) {
                    document.getElementById('printLayoutLangInput').value = langVal;
                }
            }
        } else {
            localStorage.setItem('class_lists_print_layout_lang' + prefix, urlParams.get('print_layout_lang'));
        }

        if (!urlParams.has('show_print_stats')) {
            var statsVal = localStorage.getItem('class_lists_show_print_stats' + prefix);
            if (statsVal && document.getElementById('togglePrintStats')) {
                document.getElementById('togglePrintStats').checked = (statsVal === '1');
                if (document.getElementById('showPrintStatsInput')) {
                    document.getElementById('showPrintStatsInput').value = statsVal;
                }
            }
        } else {
            localStorage.setItem('class_lists_show_print_stats' + prefix, urlParams.get('show_print_stats'));
        }

        if (!urlParams.has('show_print_date')) {
            var dateVal = localStorage.getItem('class_lists_show_print_date' + prefix);
            if (dateVal && document.getElementById('togglePrintDate')) {
                document.getElementById('togglePrintDate').checked = (dateVal === '1');
                if (document.getElementById('showPrintDateInput')) {
                    document.getElementById('showPrintDateInput').value = dateVal;
                }
            }
        } else {
            localStorage.setItem('class_lists_show_print_date' + prefix, urlParams.get('show_print_date'));
        }

        if (activeTab === 'custom') {
            if (!urlParams.has('print_stage_id')) {
                var stageVal = localStorage.getItem('class_lists_print_stage_id_custom');
                if (stageVal && document.getElementById('printStageSelect')) {
                    document.getElementById('printStageSelect').value = stageVal;
                }
            } else {
                localStorage.setItem('class_lists_print_stage_id_custom', urlParams.get('print_stage_id'));
            }
            var classArCont = document.getElementById('toggleClassArContainer');
            var classEnCont = document.getElementById('toggleClassEnContainer');
            if (classArCont) classArCont.style.display = 'block';
            if (classEnCont) classEnCont.style.display = 'block';
        } else {
            var classArCont = document.getElementById('toggleClassArContainer');
            var classEnCont = document.getElementById('toggleClassEnContainer');
            if (classArCont) classArCont.style.display = 'none';
            if (classEnCont) classEnCont.style.display = 'none';
        }
    }

    loadModalSettings();

    // Highlight session transferred students on page load
    var sessionTransferredIds = [];
    try {
        sessionTransferredIds = JSON.parse(sessionStorage.getItem('transferred_student_ids') || '[]').map(function(s) { return String(s).trim(); });
    } catch(e) {
        sessionTransferredIds = [];
    }
    sessionTransferredIds.forEach(function(studentId) {
        var cleanId = String(studentId).trim();
        var row = document.getElementById('student-row-' + cleanId);
        if (row) {
            row.classList.add('highlight-transferred-row');
        }
    });

    var classIds = <?php echo json_encode(array_column($filteredClasses, 'id')); ?>;
    classIds.forEach(function(classId) {
        if (document.getElementById('classStudentsTable_' + classId)) {
            initializeTableColumnSettings('classStudentsTable_' + classId, colMapping, 'class_lists_columns');
        }
    });

    // ===== Bind print button parameters dynamically based on column toggle states =====
    var printBtn = document.getElementById('printAllBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var showSerialAr = document.getElementById('toggleSerialAr').checked ? '1' : '0';
            var showCode = document.getElementById('toggleCode').checked ? '1' : '0';
            var showNameAr = document.getElementById('toggleNameAr').checked ? '1' : '0';
            var showGender = document.getElementById('toggleGender').checked ? '1' : '0';
            var showGenderEn = document.getElementById('toggleGenderEn').checked ? '1' : '0';
            var showNameEn = document.getElementById('toggleNameEn').checked ? '1' : '0';
            var showCodeEn = document.getElementById('toggleCodeEn').checked ? '1' : '0';
            var showSerialEn = document.getElementById('toggleSerialEn').checked ? '1' : '0';
            var sortOrder = document.getElementById('listSortOrder') ? document.getElementById('listSortOrder').value : 'ar_alpha';
            var printLayoutLang = document.getElementById('printLayoutLang') ? document.getElementById('printLayoutLang').value : 'ar';
            var showPrintStats = document.getElementById('togglePrintStats').checked ? '1' : '0';
            var showPrintDate = document.getElementById('togglePrintDate').checked ? '1' : '0';

            var url = this.getAttribute('href');
            // Clean up any existing parameters
            url = url.replace(/&show_serial_ar=[0-9]/g, '')
                     .replace(/&show_code=[0-9]/g, '')
                     .replace(/&show_name_ar=[0-9]/g, '')
                     .replace(/&show_gender=[0-9]/g, '')
                     .replace(/&show_gender_en=[0-9]/g, '')
                     .replace(/&show_name_en=[0-9]/g, '')
                     .replace(/&show_code_en=[0-9]/g, '')
                     .replace(/&show_serial_en=[0-9]/g, '')
                     .replace(/&sort_order=[a-zA-Z_]+/g, '')
                     .replace(/&print_layout_lang=[a-z]+/g, '')
                     .replace(/&show_print_stats=[0-9]/g, '')
                     .replace(/&show_print_date=[0-9]/g, '');
            // Append current states
            url += '&show_serial_ar=' + showSerialAr
                + '&show_code=' + showCode
                + '&show_name_ar=' + showNameAr
                + '&show_gender=' + showGender
                + '&show_gender_en=' + showGenderEn
                + '&show_name_en=' + showNameEn
                + '&show_code_en=' + showCodeEn
                + '&show_serial_en=' + showSerialEn
                + '&sort_order=' + sortOrder
                + '&print_layout_lang=' + printLayoutLang
                + '&show_print_stats=' + showPrintStats
                + '&show_print_date=' + showPrintDate;

            window.open(url, '_blank');
        });
    }

    // ===== Bind list sort order change listener =====
    var listSortOrder = document.getElementById('listSortOrder');
    if (listSortOrder) {
        listSortOrder.addEventListener('change', function() {
            var sortInput = document.getElementById('sortOrderInput');
            if (sortInput) {
                sortInput.value = this.value;
            }
        });
    }

    // ===== Bind print layout language change listener =====
    var printLayoutLangSelect = document.getElementById('printLayoutLang');
    if (printLayoutLangSelect) {
        printLayoutLangSelect.addEventListener('change', function() {
            var langInput = document.getElementById('printLayoutLangInput');
            if (langInput) {
                langInput.value = this.value;
            }
        });
    }

    // ===== Bind print stats toggle change listener =====
    var togglePrintStatsCheckbox = document.getElementById('togglePrintStats');
    if (togglePrintStatsCheckbox) {
        togglePrintStatsCheckbox.addEventListener('change', function() {
            var statsInput = document.getElementById('showPrintStatsInput');
            if (statsInput) {
                statsInput.value = this.checked ? '1' : '0';
            }
        });
    }

    // ===== Bind print date toggle change listener =====
    var togglePrintDateCheckbox = document.getElementById('togglePrintDate');
    if (togglePrintDateCheckbox) {
        togglePrintDateCheckbox.addEventListener('change', function() {
            var dateInput = document.getElementById('showPrintDateInput');
            if (dateInput) {
                dateInput.value = this.checked ? '1' : '0';
            }
        });
    }

    // (Duplicate event listeners for sort order and print language removed — already bound at L2373-2392)

    // ===== Bind Apply Settings button click listener =====
    var btnApplySettings = document.getElementById('btnApplySettings');
    if (btnApplySettings) {
        btnApplySettings.addEventListener('click', function() {
            var prefix = activeTab === 'custom' ? '_custom' : '';
            if (listSortOrder) {
                localStorage.setItem('class_lists_sort_order' + prefix, listSortOrder.value);
            }
            if (printLayoutLangSelect) {
                localStorage.setItem('class_lists_print_layout_lang' + prefix, printLayoutLangSelect.value);
            }
            if (togglePrintStatsCheckbox) {
                localStorage.setItem('class_lists_show_print_stats' + prefix, togglePrintStatsCheckbox.checked ? '1' : '0');
            }
            if (togglePrintDateCheckbox) {
                localStorage.setItem('class_lists_show_print_date' + prefix, togglePrintDateCheckbox.checked ? '1' : '0');
            }
            if (activeTab === 'custom' && document.getElementById('printStageSelect')) {
                localStorage.setItem('class_lists_print_stage_id_custom', document.getElementById('printStageSelect').value);
            }

            if (activeTab === 'custom') {
                var modalEl = document.getElementById('tableSettingsModal');
                var modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) {
                    modalInstance.hide();
                }
                if (typeof renderCustomLists === 'function') {
                    renderCustomLists();
                }
                return;
            }

            // Copy all current settings from modal to form hidden fields
            if (listSortOrder && document.getElementById('sortOrderInput')) {
                document.getElementById('sortOrderInput').value = listSortOrder.value;
            }
            if (printLayoutLangSelect && document.getElementById('printLayoutLangInput')) {
                document.getElementById('printLayoutLangInput').value = printLayoutLangSelect.value;
            }
            if (togglePrintStatsCheckbox && document.getElementById('showPrintStatsInput')) {
                document.getElementById('showPrintStatsInput').value = togglePrintStatsCheckbox.checked ? '1' : '0';
            }
            if (togglePrintDateCheckbox && document.getElementById('showPrintDateInput')) {
                document.getElementById('showPrintDateInput').value = togglePrintDateCheckbox.checked ? '1' : '0';
            }

            var form = document.getElementById('filterForm');
            if (form) {
                form.submit();
            }
        });
    }

    // ===== Bind export Excel button parameters dynamically based on column toggle states =====
    var excelBtn = document.getElementById('exportExcelBtn');
    if (excelBtn) {
        excelBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var showSerialAr = document.getElementById('toggleSerialAr').checked ? '1' : '0';
            var showCode = document.getElementById('toggleCode').checked ? '1' : '0';
            var showNameAr = document.getElementById('toggleNameAr').checked ? '1' : '0';
            var showGender = document.getElementById('toggleGender').checked ? '1' : '0';
            var showGenderEn = document.getElementById('toggleGenderEn').checked ? '1' : '0';
            var showNameEn = document.getElementById('toggleNameEn').checked ? '1' : '0';
            var showCodeEn = document.getElementById('toggleCodeEn').checked ? '1' : '0';
            var showSerialEn = document.getElementById('toggleSerialEn').checked ? '1' : '0';
            var sortOrder = document.getElementById('listSortOrder') ? document.getElementById('listSortOrder').value : 'ar_alpha';
            var printLayoutLang = document.getElementById('printLayoutLang') ? document.getElementById('printLayoutLang').value : 'ar';
            var showPrintStats = document.getElementById('togglePrintStats').checked ? '1' : '0';
            var showPrintDate = document.getElementById('togglePrintDate').checked ? '1' : '0';

            var url = this.getAttribute('href');
            // Clean up any existing parameters
            url = url.replace(/&show_serial_ar=[0-9]/g, '')
                     .replace(/&show_code=[0-9]/g, '')
                     .replace(/&show_name_ar=[0-9]/g, '')
                     .replace(/&show_gender=[0-9]/g, '')
                     .replace(/&show_gender_en=[0-9]/g, '')
                     .replace(/&show_name_en=[0-9]/g, '')
                     .replace(/&show_code_en=[0-9]/g, '')
                     .replace(/&show_serial_en=[0-9]/g, '')
                     .replace(/&sort_order=[a-zA-Z_]+/g, '')
                     .replace(/&print_layout_lang=[a-z]+/g, '')
                     .replace(/&show_print_stats=[0-9]/g, '')
                     .replace(/&show_print_date=[0-9]/g, '');
            // Append current states
            url += '&show_serial_ar=' + showSerialAr
                + '&show_code=' + showCode
                + '&show_name_ar=' + showNameAr
                + '&show_gender=' + showGender
                + '&show_gender_en=' + showGenderEn
                + '&show_name_en=' + showNameEn
                + '&show_code_en=' + showCodeEn
                + '&show_serial_en=' + showSerialEn
                + '&sort_order=' + sortOrder
                + '&print_layout_lang=' + printLayoutLang
                + '&show_print_stats=' + showPrintStats
                + '&show_print_date=' + showPrintDate;

            window.location.href = url;
        });
    }

    // ===== Multiple Selection Filter Cascading =====
    function updateDropdownLabels() {
        // 1. Stages
        var checkedStages = document.querySelectorAll('.stage-checkbox:checked');
        var stageLabel = document.getElementById('selectedStagesLabel');
        var stageBtn = document.getElementById('stageDropdown');
        if (stageLabel) {
            if (checkedStages.length === 0) {
                stageLabel.textContent = 'الكل';
            } else if (checkedStages.length === document.querySelectorAll('.stage-checkbox').length) {
                stageLabel.textContent = 'الكل';
            } else if (checkedStages.length <= 2) {
                var names = [];
                checkedStages.forEach(function(cb) {
                    names.push(cb.nextElementSibling.textContent.trim());
                });
                stageLabel.textContent = names.join('، ');
            } else {
                stageLabel.textContent = checkedStages.length + ' محددة';
            }
        }
        if (stageBtn) {
            stageBtn.classList.toggle('active-filter', checkedStages.length > 0);
        }

        // 2. Grades
        var checkedGrades = document.querySelectorAll('.grade-checkbox:checked');
        var gradeLabel = document.getElementById('selectedGradesLabel');
        var gradeBtn = document.getElementById('gradeDropdown');
        if (gradeLabel) {
            var visibleGradesCount = document.querySelectorAll('.grade-item:not([style*="display: none"])').length || document.querySelectorAll('.grade-item').length;
            if (checkedGrades.length === 0) {
                gradeLabel.textContent = 'الكل';
            } else if (checkedGrades.length === visibleGradesCount) {
                gradeLabel.textContent = 'الكل';
            } else if (checkedGrades.length <= 2) {
                var names = [];
                checkedGrades.forEach(function(cb) {
                    names.push(cb.nextElementSibling.textContent.trim());
                });
                gradeLabel.textContent = names.join('، ');
            } else {
                gradeLabel.textContent = checkedGrades.length + ' محددة';
            }
        }
        if (gradeBtn) {
            gradeBtn.classList.toggle('active-filter', checkedGrades.length > 0);
        }

        // 3. Classes
        var checkedClasses = document.querySelectorAll('.class-checkbox:checked');
        var classLabel = document.getElementById('selectedClassesLabel');
        var classBtn = document.getElementById('classDropdown');
        if (classLabel) {
            var visibleClassesCount = document.querySelectorAll('.class-item:not([style*="display: none"])').length || document.querySelectorAll('.class-item').length;
            if (checkedClasses.length === 0) {
                classLabel.textContent = 'الكل';
            } else if (checkedClasses.length === visibleClassesCount) {
                classLabel.textContent = 'الكل';
            } else if (checkedClasses.length <= 2) {
                var names = [];
                checkedClasses.forEach(function(cb) {
                    names.push(cb.nextElementSibling.textContent.trim());
                });
                classLabel.textContent = names.join('، ');
            } else {
                classLabel.textContent = checkedClasses.length + ' محددة';
            }
        }
        if (classBtn) {
            classBtn.classList.toggle('active-filter', checkedClasses.length > 0);
        }
    }

    function applyCascadingFilters() {
        // Get checked stage IDs
        var checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function(cb) {
            return cb.value;
        });

        // Update grades visibility
        var gradeItems = document.querySelectorAll('.grade-item');
        gradeItems.forEach(function(item) {
            var stageId = item.getAttribute('data-stage');
            if (checkedStages.length === 0 || checkedStages.includes(stageId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                var cb = item.querySelector('.grade-checkbox');
                if (cb && cb.checked) {
                    cb.checked = false;
                }
            }
        });

        // Get checked grade IDs
        var checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function(cb) {
            return cb.value;
        });

        // Update classes visibility
        var classItems = document.querySelectorAll('.class-item');
        classItems.forEach(function(item) {
            var gradeId = item.getAttribute('data-grade');
            var cb = item.querySelector('.class-checkbox');

            // Check if this class's grade belongs to any visible grades/stages
            var gradeItem = document.querySelector('.grade-checkbox[value="' + gradeId + '"]');
            var isGradeVisible = gradeItem && gradeItem.closest('.grade-item').style.display !== 'none';

            if (isGradeVisible && (checkedGrades.length === 0 || checkedGrades.includes(gradeId))) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                if (cb && cb.checked) {
                    cb.checked = false;
                }
            }
        });

        updateDropdownLabels();
    }

    // Bind change listeners to checkboxes
    document.querySelectorAll('.stage-checkbox').forEach(function(cb) {
        cb.addEventListener('change', applyCascadingFilters);
    });
    document.querySelectorAll('.grade-checkbox').forEach(function(cb) {
        cb.addEventListener('change', applyCascadingFilters);
    });
    document.querySelectorAll('.class-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateDropdownLabels);
    });

    // Initial trigger
    applyCascadingFilters();

    // ===== Transfer Modal =====
    var transferModal = document.getElementById('transferModal');
    var transferClassSelect = document.getElementById('transferClassSelect');
    var confirmTransferBtn = document.getElementById('confirmTransferBtn');
    var transferAlert = document.getElementById('transferAlert');

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.transfer-btn');
        if (!btn) return;

        var studentId = btn.getAttribute('data-id');
        var studentName = btn.getAttribute('data-name');
        var gradeId = btn.getAttribute('data-grade-id');
        var currentClass = btn.getAttribute('data-current-class');

        document.getElementById('transferStudentId').value = studentId;
        document.getElementById('transferStudentName').textContent = studentName;
        document.getElementById('transferGradeId').value = gradeId;
        document.getElementById('transferCurrentClass').value = currentClass;
        if (document.getElementById('transferReasonInput')) {
            document.getElementById('transferReasonInput').value = '';
        }
        transferAlert.className = 'd-none';
        transferAlert.innerHTML = '';

        transferClassSelect.innerHTML = '<option value="">جاري التحميل...</option>';
        transferClassSelect.disabled = true;

        fetch('class_lists.php?ajax_get_grade_classes=1&grade_id=' + gradeId)
            .then(function(r) { return r.json(); })
            .then(function(classes) {
                transferClassSelect.innerHTML = '<option value="">-- اختر الفصل --</option>';
                classes.forEach(function(cl) {
                    if (cl.id != currentClass) {
                        var label = cl.name;
                        var opt = new Option(label, cl.id);
                        transferClassSelect.appendChild(opt);
                    }
                });
                transferClassSelect.disabled = false;
            })
            .catch(function() {
                transferClassSelect.innerHTML = '<option value="">خطأ في تحميل الفصول</option>';
            });

        new bootstrap.Modal(transferModal).show();
    });

    confirmTransferBtn.addEventListener('click', function() {
        var studentId = document.getElementById('transferStudentId').value;
        var newClassId = transferClassSelect.value;

        if (!newClassId) {
            transferAlert.className = 'alert alert-warning';
            transferAlert.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>يرجى اختيار الفصل الجديد';
            return;
        }

        confirmTransferBtn.disabled = true;
        confirmTransferBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري النقل...';
        transferAlert.className = 'd-none';

        var formData = new FormData();
        formData.append('ajax_change_class', '1');
        formData.append('student_id', studentId);
        formData.append('new_class_id', newClassId);
        var reasonVal = document.getElementById('transferReasonInput') ? document.getElementById('transferReasonInput').value : '';
        formData.append('transfer_reason', reasonVal);
        formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>');

        fetch('class_lists.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                confirmTransferBtn.disabled = false;
                confirmTransferBtn.innerHTML = <?php echo json_encode(
                    $isSpecialistPortal
                        ? '<i class="fas fa-paper-plane me-1"></i>إرسال للمراجعة'
                        : '<i class="fas fa-check me-1"></i>تأكيد النقل',
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ); ?>;

                if (data.success) {
                    if (data.pending) {
                        transferAlert.className = 'alert alert-success';
                        transferAlert.innerHTML = '<i class="fas fa-hourglass-half me-1"></i>' + data.message;
                        document.querySelectorAll('.select-student-chk:checked').forEach(function(chk) {
                            chk.checked = false;
                            chk.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                        setTimeout(function() {
                            bootstrap.Modal.getInstance(transferModal).hide();
                        }, 1500);
                        return;
                    }
                    var studentIdArr = studentId.toString().split(',').map(function(s) { return s.trim(); });

                    // حفظ معرّفات الطلاب في sessionStorage لتظليلهم بلون مميز في هذه الجلسة
                    var transferredIds = [];
                    try {
                        transferredIds = JSON.parse(sessionStorage.getItem('transferred_student_ids') || '[]').map(function(s) { return String(s).trim(); });
                    } catch(e) {
                        transferredIds = [];
                    }
                    studentIdArr.forEach(function(sId) {
                        var strId = String(sId).trim();
                        if (!transferredIds.includes(strId)) {
                            transferredIds.push(strId);
                        }
                    });
                    sessionStorage.setItem('transferred_student_ids', JSON.stringify(transferredIds));

                    var currentClassId = document.getElementById('transferCurrentClass').value;
                    studentIdArr.forEach(function(sId) {
                        var row = document.getElementById('student-row-' + sId);
                        if (row) {
                            row.style.transition = 'opacity 0.3s';
                            row.style.opacity = '0';
                            setTimeout(function() { row.remove(); }, 300);
                        }
                    });

                    transferAlert.className = 'alert alert-success';
                    transferAlert.innerHTML = '<i class="fas fa-check-circle me-1"></i>' + data.message;

                    // تحديث بطاقة الفصل القديم والجديد بالكامل في نفس مكانهما دون إزالتهما من الترتيب
                    setTimeout(function() {
                        // إعادة تحميل الفصل القديم دائماً (لإزالة الصف منه)
                        var oldClassIds = currentClassId.toString().split(',').map(function(s) { return s.trim(); });
                        oldClassIds.forEach(function(oldId) {
                            if (oldId) {
                                delete loadedClasses[oldId];
                                loadClassStudents(oldId);
                                updateSummaryRowCount(oldId, -1, studentId);
                            }
                        });
                        // إعادة تحميل الفصل الجديد إن كان ظاهراً في الصفحة (لإضافة الصف إليه مع التظليل)
                        var newClassCard = document.getElementById('class-card-' + newClassId);
                        if (newClassCard) {
                            delete loadedClasses[newClassId];
                            loadClassStudents(newClassId);
                        }
                        // تحديث الأرقام في جدول الملخص للجديد
                        updateSummaryRowCount(newClassId, 1, studentId);

                        // تحديث حالة رسالة التراجع التلقائية وعرضها فوراً
                        if (typeof window.checkUndoState === 'function') {
                            window.checkUndoState(true);
                        }
                    }, 350);

                    setTimeout(function() {
                        bootstrap.Modal.getInstance(transferModal).hide();
                    }, 1200);
                } else {
                    transferAlert.className = 'alert alert-danger';
                    transferAlert.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>' + (data.message || 'حدث خطأ');
                }
            })
            .catch(function() {
                confirmTransferBtn.disabled = false;
                confirmTransferBtn.innerHTML = <?php echo json_encode(
                    $isSpecialistPortal
                        ? '<i class="fas fa-paper-plane me-1"></i>إرسال للمراجعة'
                        : '<i class="fas fa-check me-1"></i>تأكيد النقل',
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ); ?>;
                transferAlert.className = 'alert alert-danger';
                transferAlert.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>حدث خطأ في الاتصال';
            });
    });

    // ===== Bulk Selection & Transfer Event Delegation =====
    function updateBulkTransferBtnState(card) {
        var bulkBtn = card.querySelector('.bulk-transfer-btn');
        if (!bulkBtn) return;

        var selectedChks = card.querySelectorAll('.select-student-chk:checked');
        var count = selectedChks.length;

        var countSpan = bulkBtn.querySelector('.bulk-selected-count');
        if (countSpan) {
            countSpan.textContent = count;
        }

        if (count > 0) {
            bulkBtn.classList.remove('d-none');
            bulkBtn.classList.add('d-inline-flex');
        } else {
            bulkBtn.classList.remove('d-inline-flex');
            bulkBtn.classList.add('d-none');
        }

        // Update select all checkbox state
        var selectAllChk = card.querySelector('.select-all-class-students');
        if (selectAllChk) {
            var totalChks = card.querySelectorAll('.select-student-chk');
            selectAllChk.checked = totalChks.length > 0 && selectedChks.length === totalChks.length;
            selectAllChk.indeterminate = selectedChks.length > 0 && selectedChks.length < totalChks.length;
        }
    }

    // 1. Checkbox click handlers (student checks)
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('select-student-chk')) {
            var card = e.target.closest('.card');
            if (card) {
                updateBulkTransferBtnState(card);
            }
        }
    });

    // 2. "Select All" checkbox toggle handler
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('select-all-class-students')) {
            var card = e.target.closest('.card');
            if (card) {
                var checked = e.target.checked;
                card.querySelectorAll('.select-student-chk').forEach(function(chk) {
                    if (chk.checked !== checked) {
                        chk.checked = checked;
                        var event = new Event('change', { bubbles: true });
                        chk.dispatchEvent(event);
                    }
                });
            }
        }
    });

    // 3. Bulk Transfer button click handler
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.bulk-transfer-btn');
        if (!btn) return;

        var card = btn.closest('.card');
        if (!card) return;

        var selectedChks = card.querySelectorAll('.select-student-chk:checked');
        if (selectedChks.length === 0) return;

        var studentIds = [];
        selectedChks.forEach(function(chk) {
            studentIds.push(chk.value);
        });

        var classId = btn.getAttribute('data-class-id');
        var gradeId = btn.getAttribute('data-grade-id');
        var className = btn.getAttribute('data-class-name');

        document.getElementById('transferStudentId').value = studentIds.join(',');
        document.getElementById('transferStudentName').textContent = 'الطلاب المحددين (عدد: ' + studentIds.length + ') من فصل ' + className;
        document.getElementById('transferGradeId').value = gradeId;
        document.getElementById('transferCurrentClass').value = classId;

        if (document.getElementById('transferReasonInput')) {
            document.getElementById('transferReasonInput').value = '';
        }
        transferAlert.className = 'd-none';
        transferAlert.innerHTML = '';

        transferClassSelect.innerHTML = '<option value="">جاري التحميل...</option>';
        transferClassSelect.disabled = true;

        fetch('class_lists.php?ajax_get_grade_classes=1&grade_id=' + gradeId)
            .then(function(r) { return r.json(); })
            .then(function(classes) {
                transferClassSelect.innerHTML = '<option value="">-- اختر الفصل --</option>';
                classes.forEach(function(cl) {
                    if (cl.id != classId) {
                        var label = cl.name;
                        var opt = new Option(label, cl.id);
                        transferClassSelect.appendChild(opt);
                    }
                });
                transferClassSelect.disabled = false;
            })
            .catch(function() {
                transferClassSelect.innerHTML = '<option value="">خطأ في تحميل الفصول</option>';
            });

        new bootstrap.Modal(transferModal).show();
    });

    // ===== Bulk Transfer for all selected students from the floating bar =====
    var bulkTransferSelectedBtn = document.getElementById('bulkTransferSelectedBtn');
    if (bulkTransferSelectedBtn) {
        bulkTransferSelectedBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var checkedChks = document.querySelectorAll('.select-student-chk:checked');
            if (checkedChks.length === 0) {
                showWarningAlert('يرجى تحديد طلاب أولاً لإجراء النقل الجماعي.');
                return;
            }

            // Validate that all selected students belong to the same grade
            var gradeIds = [];
            checkedChks.forEach(function(chk) {
                var gId = chk.getAttribute('data-grade-id');
                if (gId && !gradeIds.includes(gId)) {
                    gradeIds.push(gId);
                }
            });

            if (gradeIds.length > 1) {
                showWarningAlert('عذراً، لا يمكن نقل طلاب من صفوف دراسية مختلفة دفعة واحدة. يرجى تحديد طلاب من نفس الصف الدراسي فقط (مثال: الصف الأول فقط) للتمكن من تحديد فصل مستهدف واحد.');
                return;
            }

            var studentIds = [];
            var currentClassIds = [];
            var currentClassNames = [];
            checkedChks.forEach(function(chk) {
                studentIds.push(chk.value);
                var cId = chk.getAttribute('data-class-id');
                if (cId && !currentClassIds.includes(cId)) {
                    currentClassIds.push(cId);
                }
                var cName = chk.getAttribute('data-class-name');
                if (cName && !currentClassNames.includes(cName)) {
                    currentClassNames.push(cName);
                }
            });

            var gradeId = gradeIds[0];

            // Populate modal inputs
            document.getElementById('transferStudentId').value = studentIds.join(',');
            document.getElementById('transferStudentName').textContent = 'الطلاب المحددين (عدد: ' + studentIds.length + ') من فصول: ' + currentClassNames.join('، ');
            document.getElementById('transferGradeId').value = gradeId;
            document.getElementById('transferCurrentClass').value = currentClassIds.join(',');

            if (document.getElementById('transferReasonInput')) {
                document.getElementById('transferReasonInput').value = '';
            }
            transferAlert.className = 'd-none';
            transferAlert.innerHTML = '';

            // Load target classes for this grade
            transferClassSelect.innerHTML = '<option value="">جاري التحميل...</option>';
            transferClassSelect.disabled = true;

            fetch('class_lists.php?ajax_get_grade_classes=1&grade_id=' + gradeId)
                .then(function(r) { return r.json(); })
                .then(function(classes) {
                    transferClassSelect.innerHTML = '<option value="">-- اختر الفصل --</option>';
                    classes.forEach(function(cl) {
                        // Hide classes where selected students are already enrolled
                        if (!currentClassIds.includes(String(cl.id))) {
                            var label = cl.name;
                            var opt = new Option(label, cl.id);
                            transferClassSelect.appendChild(opt);
                        }
                    });
                    transferClassSelect.disabled = false;
                })
                .catch(function() {
                    transferClassSelect.innerHTML = '<option value="">خطأ في تحميل الفصول</option>';
                });

            new bootstrap.Modal(transferModal).show();
        });
    }
});

// DataTables for transfer log
if (document.getElementById('transferLogTable')) {
    $(document).ready(function() {
        $('#transferLogTable').DataTable({
            language: {
                search: "بحث:",
                lengthMenu: "عرض _MENU_ سجلات",
                info: "عرض _START_ إلى _END_ من أصل _TOTAL_ سجل",
                infoEmpty: "عرض 0 إلى 0 من أصل 0 سجل",
                infoFiltered: "(مصفاة من إجمالي _MAX_ سجل)",
                infoPostFix: "",
                loadingRecords: "جاري التحميل...",
                zeroRecords: "لم يتم العثور على أية سجلات مطابقة",
                emptyTable: "لا توجد سجلات متاحة في الجدول",
                paginate: {
                    first: "الأول",
                    previous: "السابق",
                    next: "التالي",
                    last: "الأخير"
                }
            },
            order: [[1, 'desc']],
            pageLength: 25,
            dom: '<"row align-items-center mb-3"<"col-md-6"l><"col-md-6"f>>rt<"row align-items-center mt-3"<"col-md-6"i><"col-md-6"p>>'
        });
    });
}

// ===== Custom Student List Selection and Processing =====
(function() {
    var customSelectionBar = document.getElementById('customListSelectionBar');
    var customSelectedCountSpan = document.getElementById('customSelectedCount');

    function updateCustomSelectionBar() {
        var checkedChks = document.querySelectorAll('.select-student-chk:checked');
        var count = checkedChks.length;

        if (customSelectedCountSpan) {
            customSelectedCountSpan.textContent = count;
        }

        if (count > 0 && '<?php echo $activeTab; ?>' === 'lists') {
            if (customSelectionBar) {
                customSelectionBar.classList.add('visible');
            }
        } else {
            if (customSelectionBar) {
                customSelectionBar.classList.remove('visible');
            }
        }
    }

    // Bind to checkbox changes globally
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('select-student-chk') || e.target.classList.contains('select-all-class-students')) {
            updateCustomSelectionBar();
        }
    });

    // Clear selection
    var clearCustomSelectionBtn = document.getElementById('clearCustomSelectionBtn');
    if (clearCustomSelectionBtn) {
        clearCustomSelectionBtn.addEventListener('click', function() {
            document.querySelectorAll('.select-student-chk:checked').forEach(function(chk) {
                chk.checked = false;
                var event = new Event('change', { bubbles: true });
                chk.dispatchEvent(event);
            });
            document.querySelectorAll('.select-all-class-students:checked').forEach(function(chk) {
                chk.checked = false;
                var event = new Event('change', { bubbles: true });
                chk.dispatchEvent(event);
            });
            updateCustomSelectionBar();
        });
    }

    // Open Create Custom List Modal
    var createCustomListBtn = document.getElementById('createCustomListBtn');
    if (createCustomListBtn) {
        createCustomListBtn.addEventListener('click', function() {
            var checkedChks = document.querySelectorAll('.select-student-chk:checked');
            var count = checkedChks.length;
            if (count === 0) return;

            var modalSelectedCount = document.getElementById('modalSelectedCount');
            if (modalSelectedCount) {
                modalSelectedCount.textContent = count;
            }

            var modal = new bootstrap.Modal(document.getElementById('createCustomListModal'));
            modal.show();
        });
    }

    // Confirm Create Custom List
    var confirmCreateCustomListBtn = document.getElementById('confirmCreateCustomListBtn');
    if (confirmCreateCustomListBtn) {
        confirmCreateCustomListBtn.addEventListener('click', function() {
            var titleInput = document.getElementById('customListTitleInput');
            var title = titleInput ? titleInput.value.trim() : '';
            if (!title) {
                window.showWarningAlert('الرجاء إدخال عنوان القائمة المخصصة');
                return;
            }

            var checkedChks = document.querySelectorAll('.select-student-chk:checked');
            var ids = Array.from(checkedChks).map(function(chk) { return chk.value; }).join(',');

            var lists = [];
            try {
                lists = JSON.parse(localStorage.getItem('custom_student_lists') || '[]');
            } catch(e) {}

            var urlParams = new URLSearchParams(window.location.search);
            var editListId = urlParams.get('edit_list_id');

            if (editListId) {
                // Update existing list
                var found = false;
                lists.forEach(function(lst) {
                    if (lst.id === editListId) {
                        lst.title = title;
                        lst.ids = ids;
                        found = true;
                    }
                });
                if (!found) {
                    lists.push({
                        id: editListId,
                        title: title,
                        ids: ids,
                        created_at: new Date().toLocaleString('ar-EG')
                    });
                }
            } else {
                // Create new list
                lists.push({
                    id: Date.now().toString(),
                    title: title,
                    ids: ids,
                    created_at: new Date().toLocaleString('ar-EG')
                });
            }

            localStorage.setItem('custom_student_lists', JSON.stringify(lists));

            // Backup/legacy compatibility
            localStorage.setItem('custom_student_list_ids', ids);
            localStorage.setItem('custom_student_list_title', title);

            // Close modal
            var modalEl = document.getElementById('createCustomListModal');
            var modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
            }

            // Redirect
            window.location.href = 'class_lists.php?tab=custom';
        });
    }

    // List selection variable for deletion
    var listIdToDelete = null;

    // Handle dynamic list clicks
    document.addEventListener('click', function(e) {
        // Edit custom list btn click
        var editBtn = e.target.closest('.edit-custom-list-btn');
        if (editBtn) {
            var listId = editBtn.getAttribute('data-list-id');
            var listIds = editBtn.getAttribute('data-list-ids');
            var listTitle = editBtn.getAttribute('data-list-title');

            localStorage.setItem('custom_student_list_ids', listIds);
            localStorage.setItem('custom_student_list_title', listTitle);

            window.location.href = 'class_lists.php?tab=lists&edit_custom=1&edit_list_id=' + listId + '&ids=' + listIds + '&title=' + encodeURIComponent(listTitle);
            return;
        }

        // Delete custom list btn click
        var deleteBtn = e.target.closest('.delete-custom-list-btn');
        if (deleteBtn) {
            listIdToDelete = deleteBtn.getAttribute('data-list-id');
            var listTitle = deleteBtn.getAttribute('data-list-title');

            var nameSpan = document.getElementById('deleteListNameSpan');
            if (nameSpan) {
                nameSpan.textContent = listTitle;
            }

            var modalEl = document.getElementById('deleteCustomListModal');
            if (modalEl) {
                var modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
            return;
        }
    });

    // Confirm Delete Custom List
    var confirmDeleteCustomListBtn = document.getElementById('confirmDeleteCustomListBtn');
    if (confirmDeleteCustomListBtn) {
        confirmDeleteCustomListBtn.addEventListener('click', function() {
            if (!listIdToDelete) return;

            var lists = [];
            try {
                lists = JSON.parse(localStorage.getItem('custom_student_lists') || '[]');
            } catch(e) {}

            lists = lists.filter(function(lst) {
                return lst.id !== listIdToDelete;
            });

            localStorage.setItem('custom_student_lists', JSON.stringify(lists));

            // Clean up legacy item if matches
            localStorage.removeItem('custom_student_list_ids');
            localStorage.removeItem('custom_student_list_title');

            var modalEl = document.getElementById('deleteCustomListModal');
            var modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
            }

            renderCustomLists();
            updateCustomListsCountBadge();
        });
    }

    function updateCustomListsCountBadge() {
        var lists = [];
        try {
            lists = JSON.parse(localStorage.getItem('custom_student_lists') || '[]');
        } catch(e) {}
        var badge = document.getElementById('customListsCountBadge');
        if (badge) {
            badge.textContent = lists.length;
        }
    }

    // Render multiple custom lists inside tab=custom
    window.renderCustomLists = function() {
        var container = document.getElementById('customListsContainer');
        if (!container) return;

        var lists = [];
        try {
            lists = JSON.parse(localStorage.getItem('custom_student_lists') || '[]');
        } catch(e) {}

        if (lists.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5 border rounded bg-white mb-4 no-print">
                    <i class="fas fa-user-tag fa-3x mb-3 text-muted"></i>
                    <h5>لا توجد قوائم مخصصة حالياً</h5>
                    <p class="text-muted mb-0">لإنشاء قائمة مخصصة جديدة، قم بالذهاب إلى تبويب "قوائم الفصول"، وحدد الطلاب المطلوبين من الفصول المختلفة، ثم اضغط على زر "إنشاء قائمة مخصصة" في الشريط العائم أسفل الصفحة.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = '';

        var sortOrder = localStorage.getItem('class_lists_sort_order_custom') || 'ar_alpha';
        var printLayoutLang = localStorage.getItem('class_lists_print_layout_lang_custom') || 'ar';
        var showPrintStats = (localStorage.getItem('class_lists_show_print_stats_custom') !== '0');
        var showPrintDate = (localStorage.getItem('class_lists_show_print_date_custom') !== '0');
        var printStageId = localStorage.getItem('class_lists_print_stage_id_custom') || 'auto';

        var cols = {};
        try {
            cols = JSON.parse(localStorage.getItem('class_lists_columns_custom') || '{}');
        } catch(e) {}
        var showSerialAr = (cols.toggleSerialAr !== false) ? '1' : '0';
        var showCode = (cols.toggleCode === true) ? '1' : '0';
        var showNameAr = (cols.toggleNameAr !== false) ? '1' : '0';
        var showClassAr = (cols.toggleClassAr !== false) ? '1' : '0';
        var showGender = (cols.toggleGender !== false) ? '1' : '0';
        var showGenderEn = (cols.toggleGenderEn === true) ? '1' : '0';
        var showClassEn = (cols.toggleClassEn === true) ? '1' : '0';
        var showNameEn = (cols.toggleNameEn !== false) ? '1' : '0';
        var showCodeEn = (cols.toggleCodeEn === true) ? '1' : '0';
        var showSerialEn = (cols.toggleSerialEn !== false) ? '1' : '0';

        lists.forEach(function(list) {
            var cardId = 'custom-list-card-' + list.id;
            var collapseId = 'collapseList_' + list.id;

            var cardHtml = `
                <div class="card shadow mb-4 admin-card-surface custom-list-card" id="${cardId}">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 custom-list-toggle" data-bs-toggle="collapse" data-bs-target="#${collapseId}">
                            <i class="fas fa-chevron-down toggle-icon text-muted"></i>
                            <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-user-tag me-2 text-primary"></i>${escapeHtml(list.title)}</h6>
                            <span class="badge bg-secondary-subtle text-secondary px-2 rounded-pill" id="badge-count-${list.id}">الطلاب: ...</span>
                        </div>
                        <div class="no-print d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary custom-list-action-btn" data-bs-toggle="modal" data-bs-target="#tableSettingsModal">
                                <i class="fas fa-cog me-1"></i>إعدادات القائمة
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning custom-list-action-btn edit-custom-list-btn" data-list-id="${list.id}" data-list-ids="${list.ids}" data-list-title="${escapeHtml(list.title)}">
                                <i class="fas fa-edit me-1"></i>تعديل القائمة
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger custom-list-action-btn delete-custom-list-btn" data-list-id="${list.id}" data-list-title="${escapeHtml(list.title)}">
                                <i class="fas fa-trash-alt me-1"></i>حذف القائمة
                            </button>
                            <a href="class_lists.php?print_all=1&tab=custom&ids=${list.ids}&title=${encodeURIComponent(list.title)}&sort_order=${sortOrder}&print_layout_lang=${printLayoutLang}&show_print_stats=${showPrintStats?'1':'0'}&show_print_date=${showPrintDate?'1':'0'}&print_stage_id=${printStageId}" class="btn btn-sm btn-outline-primary custom-list-action-btn" target="_blank">
                                <i class="fas fa-print me-1"></i>طباعة القائمة
                            </a>
                            <a href="class_lists.php?export_excel=1&tab=custom&ids=${list.ids}&title=${encodeURIComponent(list.title)}&sort_order=${sortOrder}&show_serial_ar=${showSerialAr}&show_code=${showCode}&show_name_ar=${showNameAr}&show_class_ar=${showClassAr}&show_gender=${showGender}&show_gender_en=${showGenderEn}&show_class_en=${showClassEn}&show_name_en=${showNameEn}&show_code_en=${showCodeEn}&show_serial_en=${showSerialEn}" class="btn btn-sm btn-outline-success custom-list-action-btn">
                                <i class="fas fa-file-excel me-1"></i>تصدير Excel
                            </a>
                        </div>
                    </div>
                    <div id="${collapseId}" class="collapse show">
                        <div class="card-body p-3">
                            <div class="table-responsive admin-table-wrap">
                                <table class="table table-hover table-striped mb-0 admin-data-table" id="customStudentsTable_${list.id}">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="col-serial-ar" width="50">#</th>
                                            <th class="col-code">كود الطالب</th>
                                            <th class="col-name-ar">اسم الطالب</th>
                                            <th class="col-class-ar">اسم الفصل</th>
                                            <th class="text-center col-gender">النوع</th>
                                            <th class="text-center col-gender-en" style="text-align: left !important;">Gender</th>
                                            <th class="col-class-en" style="text-align: left !important;">Class</th>
                                            <th class="col-name-en" style="text-align: left !important;">Student Name</th>
                                            <th class="col-code-en" style="text-align: left !important;">Code</th>
                                            <th class="text-center col-serial-en" width="50">#</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-${list.id}">
                                        <tr>
                                            <td colspan="10" class="text-center py-4">
                                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                                <span>جاري تحميل الطلاب...</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            container.insertAdjacentHTML('beforeend', cardHtml);

            // Fetch students via AJAX
            fetch('class_lists.php?ajax_get_students_by_ids=1&ids=' + list.ids + '&sort_order=' + sortOrder)
                .then(function(r) { return r.json(); })
                .then(function(students) {
                    var tbody = document.getElementById('tbody-' + list.id);
                    var countBadge = document.getElementById('badge-count-' + list.id);
                    if (countBadge) {
                        countBadge.textContent = 'الطلاب: ' + students.length;
                    }

                    if (!tbody) return;

                    if (students.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">لا يوجد طلاب في هذه القائمة</td></tr>';
                        return;
                    }

                    var tbodyHtml = '';
                    students.forEach(function(st, idx) {
                        var sidx = idx + 1;
                        var genderAr = st.gender === 'male' ? '<span class="badge bg-primary-subtle text-primary px-2 py-1">ذكر</span>' : (st.gender === 'female' ? '<span class="badge bg-danger-subtle text-danger px-2 py-1">أنثى</span>' : '<span class="badge bg-secondary-subtle text-secondary px-2 py-1">-</span>');
                        var genderEn = st.gender === 'male' ? '<span class="badge bg-primary-subtle text-primary px-2 py-1">Male</span>' : (st.gender === 'female' ? '<span class="badge bg-danger-subtle text-danger px-2 py-1">Female</span>' : '<span class="badge bg-secondary-subtle text-secondary px-2 py-1">-</span>');

                        tbodyHtml += `
                            <tr id="custom-student-row-${list.id}-${st.id}">
                                <td class="col-serial-ar">${toArabicNumerals(sidx)}</td>
                                <td class="col-code"><span class="text-muted">${escapeHtml(st.student_code || '-')}</span></td>
                                <td class="col-name-ar"><strong>${escapeHtml(st.name)}</strong></td>
                                <td class="col-class-ar"><strong>${escapeHtml(st.class_name_ar || '')}</strong></td>
                                <td class="text-center col-gender">${genderAr}</td>
                                <td class="text-center col-gender-en" style="text-align: left !important;">${genderEn}</td>
                                <td class="col-class-en" style="text-align: left !important;"><strong>${escapeHtml(st.class_name_en || '')}</strong></td>
                                <td class="col-name-en" dir="ltr" style="text-align: left !important;">${escapeHtml(st.name_en || '-')}</td>
                                <td class="col-code-en" dir="ltr" style="text-align: left !important;"><span class="text-muted">${escapeHtml(st.student_code || '-')}</span></td>
                                <td class="text-center col-serial-en" style="text-align: left !important;">${sidx}</td>
                            </tr>
                        `;
                    });

                    tbody.innerHTML = tbodyHtml;

                    // Initialize column settings
                    var customColMapping = {
                        toggleSerialAr: 0,
                        toggleCode: 1,
                        toggleNameAr: 2,
                        toggleClassAr: 3,
                        toggleGender: 4,
                        toggleGenderEn: 5,
                        toggleClassEn: 6,
                        toggleNameEn: 7,
                        toggleCodeEn: 8,
                        toggleSerialEn: 9
                    };
                    initializeTableColumnSettings('customStudentsTable_' + list.id, customColMapping, 'class_lists_columns_custom');
                })
                .catch(function() {
                    var tbody = document.getElementById('tbody-' + list.id);
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger">حدث خطأ أثناء تحميل الطلاب</td></tr>';
                    }
                });
        });
    }

    // Run Migration on Page Load
    var oldIds = localStorage.getItem('custom_student_list_ids');
    var oldTitle = localStorage.getItem('custom_student_list_title');
    var hasCustomLists = localStorage.getItem('custom_student_lists');

    if (oldIds && !hasCustomLists) {
        var migrated = [{
            id: Date.now().toString(),
            title: oldTitle || 'قائمة مخصصة',
            ids: oldIds,
            created_at: new Date().toLocaleString('ar-EG')
        }];
        localStorage.setItem('custom_student_lists', JSON.stringify(migrated));
        localStorage.removeItem('custom_student_list_ids');
        localStorage.removeItem('custom_student_list_title');
    }

    // Update badge count
    updateCustomListsCountBadge();

    // Render custom lists if currently on custom tab
    if ('<?php echo $activeTab; ?>' === 'custom') {
        renderCustomLists();
    }

    // Show or hide print stage selection dropdown based on current tab
    var printStageSelectContainer = document.getElementById('printStageSelectContainer');
    if (printStageSelectContainer) {
        if ('<?php echo $activeTab; ?>' === 'custom') {
            printStageSelectContainer.style.display = 'block';
        } else {
            printStageSelectContainer.style.display = 'none';
        }
    }

    // Auto-check students if edit_custom is present in URL
    if ('<?php echo $activeTab; ?>' === 'lists') {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('edit_custom') === '1') {
            var editListId = urlParams.get('edit_list_id') || '';

            // Prefill title input and customize button texts in Edit Mode
            var createCustomListBtn = document.getElementById('createCustomListBtn');
            if (createCustomListBtn) {
                createCustomListBtn.innerHTML = '<i class="fas fa-save me-1"></i>تعديل القائمة';
            }

            var modalTitle = document.querySelector('#createCustomListModal .modal-title');
            if (modalTitle) {
                modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>تعديل القائمة المخصصة';
            }

            var confirmCreateCustomListBtn = document.getElementById('confirmCreateCustomListBtn');
            if (confirmCreateCustomListBtn) {
                confirmCreateCustomListBtn.textContent = 'حفظ التعديلات';
            }

            var titleInput = document.getElementById('customListTitleInput');
            if (titleInput) {
                var titleVal = urlParams.get('title') || '';
                titleInput.value = titleVal;
            }

            var savedIdsStr = urlParams.get('ids') || '';
            if (savedIdsStr) {
                var savedIds = savedIdsStr.split(',');
                // Wait for document to be ready
                $(document).ready(function() {
                    savedIds.forEach(function(id) {
                        var chk = document.querySelector('.select-student-chk[value="' + id + '"]');
                        if (chk) {
                            chk.checked = true;
                            // Trigger change event to sync the card header bulk actions
                            var changeEvent = new Event('change', { bubbles: true });
                            chk.dispatchEvent(changeEvent);
                        }
                    });
                    updateCustomSelectionBar();
                });
            }
        }
    }

})();
</script>
