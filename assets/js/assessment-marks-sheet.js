(function () {
    'use strict';

    const config = window.assessmentMarksSheetConfig || {};
    const viewport = document.getElementById('marksSheetViewport');
    const statusPanel = document.getElementById('marksSheetStatus');
    const gradeSelect = document.getElementById('sheetGrade');
    const termSelect = document.getElementById('sheetTerm');
    const classSelect = document.getElementById('sheetClass');
    const studentSearch = document.getElementById('sheetStudentSearch');
    const missingOnly = document.getElementById('sheetMissingOnly');
    const subjectTabs = document.getElementById('sheetSubjectTabs');
    const feedback = document.getElementById('sheetInlineFeedback');
    const nameBox = document.getElementById('sheetNameBox');
    const formulaText = document.getElementById('sheetFormulaText');
    const saveState = document.getElementById('sheetSaveState');
    const selectionToolbar = document.getElementById('sheetSelectionToolbar');
    const selectedCount = document.getElementById('sheetSelectedCount');
    const editableSelectedCount = document.getElementById('sheetEditableSelectedCount');
    const selectionStats = document.getElementById('sheetSelectionStats');
    const bulkEditor = document.getElementById('sheetBulkEditor');
    const bulkStatus = document.getElementById('sheetBulkStatus');
    const bulkValue = document.getElementById('sheetBulkValue');
    const bulkChangeNote = document.getElementById('sheetBulkChangeNote');
    const bulkNote = document.getElementById('sheetBulkNote');
    const bulkReason = document.getElementById('sheetBulkReason');

    if (!viewport || !statusPanel || !gradeSelect || !termSelect || !classSelect || !subjectTabs) {
        return;
    }

    const state = {
        data: null,
        table: null,
        loadController: null,
        fieldMeta: new Map(),
        markFields: [],
        activeCell: null,
        selectedKeys: new Set(),
        selectionAnchor: null,
        selectionBase: new Set(),
        selectionMode: 'add',
        isSelecting: false,
        editSeed: '',
        saveChains: new Map(),
        saveSequence: new Map(),
        activeSaves: 0,
        suppressCellEdited: false,
        hoverField: '',
        selectedSchemeId: Number(config.initial && config.initial.schemeId) || 0,
    };

    function englishDigits(value) {
        return String(value == null ? '' : value)
            .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
            .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
            .replace(/[٫]/g, '.')
            .replace(/[٬،]/g, ',');
    }

    function textValue(value) {
        return englishDigits(value).trim();
    }

    function numberValue(value) {
        const normalized = englishDigits(value).replace(/,/g, '').trim();
        if (normalized === '') {
            return null;
        }
        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : NaN;
    }

    function formatNumber(value) {
        const parsed = Number(value);
        if (!Number.isFinite(parsed)) {
            return '0';
        }
        return new Intl.NumberFormat('en-US', {
            useGrouping: false,
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(parsed);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
        })[char]);
    }

    function excelColumnName(index) {
        let value = Math.max(1, Number(index) || 1);
        let result = '';
        while (value > 0) {
            value--;
            result = String.fromCharCode(65 + (value % 26)) + result;
            value = Math.floor(value / 26);
        }
        return result;
    }

    function setSaveState(mode, label) {
        if (!saveState) {
            return;
        }
        const icons = {
            idle: 'fa-cloud', saving: 'fa-cloud-arrow-up', saved: 'fa-cloud-circle-check',
            error: 'fa-cloud-circle-exclamation', offline: 'fa-wifi',
        };
        saveState.dataset.state = mode;
        saveState.replaceChildren();
        const icon = document.createElement('i');
        icon.className = 'fas ' + (icons[mode] || icons.idle) + (mode === 'saving' ? ' fa-beat-fade' : '');
        const span = document.createElement('span');
        span.textContent = label;
        saveState.append(icon, span);
    }

    function showFeedback(message, type, persistent) {
        if (!feedback) {
            return;
        }
        feedback.className = 'alert alert-' + (type || 'info');
        feedback.textContent = message;
        feedback.classList.remove('d-none');
        if (!persistent) {
            window.clearTimeout(showFeedback.timer);
            showFeedback.timer = window.setTimeout(() => feedback.classList.add('d-none'), 5000);
        }
    }

    function clearFeedback() {
        if (feedback) {
            feedback.classList.add('d-none');
        }
    }

    function setLoading(message) {
        statusPanel.className = 'assessment-sheet-status';
        statusPanel.replaceChildren();
        const icon = document.createElement('i');
        icon.className = 'fas fa-spinner fa-spin me-2';
        statusPanel.append(icon, document.createTextNode(message || 'جاري إعداد الشيت…'));
        statusPanel.classList.remove('d-none');
        viewport.classList.add('d-none');
    }

    function setEmpty(message, type, retryable) {
        statusPanel.className = 'assessment-sheet-status';
        statusPanel.replaceChildren();
        const box = document.createElement('div');
        box.className = 'assessment-sheet-empty-state alert alert-' + (type || 'info');
        const icon = document.createElement('i');
        icon.className = 'fas ' + (type === 'danger' ? 'fa-triangle-exclamation' : 'fa-table-cells');
        const text = document.createElement('span');
        text.textContent = message;
        box.append(icon, text);
        if (retryable) {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-outline-primary btn-sm';
            retry.innerHTML = '<i class="fas fa-rotate me-1"></i>إعادة المحاولة';
            retry.addEventListener('click', () => loadSheet());
            box.append(retry);
        }
        statusPanel.append(box);
        statusPanel.classList.remove('d-none');
        viewport.classList.add('d-none');
    }

    async function post(endpoint, values, signal) {
        const body = new URLSearchParams();
        Object.entries(values).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(key + '[]', String(item)));
            } else if (value !== undefined && value !== null) {
                body.append(key, String(value));
            }
        });
        body.set('csrf_token', String(config.csrfToken || ''));

        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-TOKEN': String(config.csrfToken || ''),
            },
            body: body.toString(),
            signal,
        });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('أعاد الخادم استجابة غير صالحة.');
        }
        if (!response.ok || !payload.ok) {
            const failure = new Error(payload.message || 'تعذر تنفيذ الطلب.');
            failure.status = response.status;
            throw failure;
        }
        return payload;
    }

    function availableSchemes() {
        const gradeId = Number(gradeSelect.value) || 0;
        const termId = Number(termSelect.value) || 0;
        return (config.options && config.options.schemes || []).filter((scheme) => (
            Number(scheme.grade_id) === gradeId && Number(scheme.term_id) === termId
        ));
    }

    function updateClassOptions() {
        const gradeId = Number(gradeSelect.value) || 0;
        let valid = classSelect.value === '';
        Array.from(classSelect.options).forEach((option) => {
            if (option.value === '') {
                option.hidden = false;
                return;
            }
            option.hidden = Number(option.dataset.grade) !== gradeId;
            if (!option.hidden && option.selected) {
                valid = true;
            }
        });
        if (!valid) {
            classSelect.value = '';
        }
    }

    function renderSubjectTabs() {
        subjectTabs.replaceChildren();
        const schemes = availableSchemes();
        if (!schemes.some((scheme) => Number(scheme.id) === state.selectedSchemeId)) {
            state.selectedSchemeId = Number(schemes[0] && schemes[0].id) || 0;
        }
        schemes.forEach((scheme) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'assessment-subject-tab' + (Number(scheme.id) === state.selectedSchemeId ? ' active' : '');
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', Number(scheme.id) === state.selectedSchemeId ? 'true' : 'false');
            button.innerHTML = '<span>' + escapeHtml(englishDigits(scheme.subject_name || scheme.scheme_name || 'مادة')) + '</span>'
                + '<small>' + escapeHtml(englishDigits(scheme.scheme_name || '')) + '</small>';
            button.addEventListener('click', () => {
                if (state.activeSaves > 0) {
                    showFeedback('انتظر اكتمال الحفظ الجاري قبل الانتقال إلى مادة أخرى.', 'warning');
                    return;
                }
                state.selectedSchemeId = Number(scheme.id);
                renderSubjectTabs();
                loadSheet();
            });
            subjectTabs.append(button);
        });
    }

    function syncUrl() {
        const url = new URL(window.location.href);
        const values = {
            sheet_grade_id: gradeSelect.value,
            sheet_term_id: termSelect.value,
            sheet_scheme_id: state.selectedSchemeId || '',
            sheet_class_id: classSelect.value,
        };
        Object.entries(values).forEach(([key, value]) => {
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
        });
        history.replaceState(null, '', url.toString());
    }

    async function loadSheet(preserveFeedback) {
        if (!state.selectedSchemeId) {
            destroyTable();
            setEmpty('لا توجد خطة مادة مرتبطة بالصف والفصل الدراسي المختارين.', 'info', false);
            updateSummary({students: 0, columns: 0, marks: 0, missing: 0});
            return;
        }
        if (state.activeSaves > 0) {
            showFeedback('انتظر اكتمال الحفظ الجاري قبل تحديث الشيت.', 'warning');
            return;
        }
        if (!preserveFeedback) {
            clearFeedback();
        }
        if (state.loadController) {
            state.loadController.abort();
        }
        state.loadController = new AbortController();
        setLoading('جاري تحميل الطلاب وبناء الأعمدة…');
        syncUrl();

        try {
            const response = await post(config.endpoint, {
                academic_year_id: config.academicYearId,
                grade_id: gradeSelect.value,
                term_id: termSelect.value,
                scheme_id: state.selectedSchemeId,
                class_id: classSelect.value,
            }, state.loadController.signal);
            state.data = response.data || {};
            updateSummary(state.data.summary || {});
            if (!Array.isArray(state.data.students) || state.data.students.length === 0) {
                destroyTable();
                setEmpty('لا يوجد طلاب في هذا الصف أو الفصل ضمن العام المختار.', 'info', false);
                return;
            }
            if (!Array.isArray(state.data.groups) || state.data.groups.length === 0) {
                destroyTable();
                setEmpty('لا توجد أعمدة تقييم لهذه المادة. راجع البنود وقواعد الأسابيع ونوافذ الرصد.', 'warning', false);
                return;
            }
            renderSheet();
            if (state.data.truncated) {
                showFeedback('تم عرض أول 1200 طالب فقط. استخدم فلتر الفصل لتقليل النطاق.', 'warning', true);
            }
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            destroyTable();
            setEmpty(error.message || 'تعذر تحميل شيت الدرجات. حاول مرة أخرى.', 'danger', true);
        }
    }

    function destroyTable() {
        if (state.table) {
            state.table.destroy();
            state.table = null;
        }
        state.fieldMeta.clear();
        state.markFields = [];
        state.activeCell = null;
        state.selectedKeys.clear();
        state.selectionAnchor = null;
        state.selectionBase.clear();
        state.isSelecting = false;
        updateSelectionUi();
    }

    function updateSummary(summary) {
        ['students', 'columns', 'marks', 'missing'].forEach((key) => {
            document.querySelectorAll('[data-sheet-summary="' + key + '"]').forEach((element) => {
                element.textContent = formatNumber(Number(summary[key]) || 0);
            });
        });
    }

    function recalculateSummary() {
        if (!state.data || !Array.isArray(state.data.students)) {
            return;
        }
        let marks = 0;
        let missing = 0;
        state.data.students.forEach((student) => {
            state.markFields.forEach((field) => {
                const mark = student._sheetMarks && student._sheetMarks[field];
                if (mark && mark.status !== 'empty') {
                    marks++;
                } else {
                    missing++;
                }
            });
        });
        updateSummary({
            students: state.data.students.length,
            columns: state.markFields.length,
            marks,
            missing,
        });
    }

    function refreshStudentMissing(student) {
        if (!student || !student._sheetRow) {
            return;
        }
        student._sheetRow._missing = state.markFields.reduce((count, field) => {
            const mark = student._sheetMarks && student._sheetMarks[field];
            return count + (!mark || mark.status === 'empty' ? 1 : 0);
        }, 0);
    }

    function slotMatches(left, right) {
        return Number(left.component_id) === Number(right.component_id)
            && Number(left.week_id || 0) === Number(right.week_id || 0);
    }

    function writableTarget(student, column) {
        if ((student.locked && !config.isSuperAdmin) || Number(student.class_id) <= 0) {
            return null;
        }
        const candidates = (state.data.writable_windows || []).filter((windowRow) => (
            slotMatches(windowRow, column)
            && (windowRow.class_id == null || Number(windowRow.class_id) === Number(student.class_id))
        ));
        return candidates.length === 1 ? candidates[0] : null;
    }

    function markForCell(student, field, column) {
        const sourceMarks = student.marks || {};
        const source = sourceMarks[column.key] || null;
        if (source) {
            return {
                id: Number(source.id) || 0,
                value: source.value == null ? null : Number(source.value),
                status: String(source.status || 'empty'),
                note: String(source.note || ''),
                review_status: String(source.review_status || 'not_required'),
                max_grade: Number(source.max_grade != null ? source.max_grade : column.max_grade) || 0,
                published_count: Number(source.published_count) || 0,
                locked: Boolean(source.locked),
                updated_at: String(source.updated_at || ''),
                target: null,
                field,
                column,
                student,
            };
        }
        return {
            id: 0,
            value: null,
            status: 'empty',
            note: '',
            review_status: 'not_required',
            max_grade: Number(column.max_grade) || 0,
            published_count: 0,
            locked: Boolean(student.locked),
            updated_at: '',
            target: writableTarget(student, column),
            field,
            column,
            student,
        };
    }

    function displayMark(mark) {
        if (!mark || mark.status === 'empty') {
            return '';
        }
        if (mark.status === 'absent') {
            return 'غ';
        }
        if (mark.status === 'excused_absent') {
            return 'غ بعذر';
        }
        if (mark.status === 'exempt') {
            return 'معفى';
        }
        return mark.value == null ? '' : formatNumber(mark.value);
    }

    function statusLabel(status) {
        return ({
            present: 'درجة مرصودة', absent: 'غائب', excused_absent: 'غياب بعذر',
            exempt: 'معفى', empty: 'غير مرصودة',
        })[status] || 'غير مرصودة';
    }

    function canEditMark(mark) {
        if (!mark) {
            return false;
        }
        if (mark.id > 0) {
            return !mark.locked || Boolean(config.isSuperAdmin);
        }
        return Boolean(mark.target) && (!mark.locked || Boolean(config.isSuperAdmin));
    }

    function cellMark(cell) {
        const row = cell && cell.getRow ? cell.getRow().getData() : null;
        return row && row._sheetMarks ? row._sheetMarks[cell.getField()] || null : null;
    }

    function cellSelectionKey(cell) {
        if (!cell || !cell.getRow || !cell.getField) {
            return '';
        }
        return String(cell.getRow().getIndex()) + ':' + String(cell.getField());
    }

    function selectionPoint(cell) {
        return cell ? {rowId: cell.getRow().getIndex(), field: cell.getField()} : null;
    }

    function identityFormatter(cell) {
        const value = document.createElement('span');
        value.className = 'assessment-sheet-identity-value';
        value.textContent = englishDigits(cell.getValue() || '—');
        value.title = value.textContent;
        return value;
    }

    function studentFormatter(cell) {
        const link = document.createElement('a');
        link.className = 'assessment-sheet-student-link';
        link.href = 'student_profile.php?id=' + encodeURIComponent(cell.getRow().getData().student_id);
        link.textContent = englishDigits(cell.getValue() || '—');
        link.title = link.textContent;
        link.addEventListener('click', (event) => event.stopPropagation());
        return link;
    }

    function markFormatter(cell) {
        const element = cell.getElement();
        const mark = cellMark(cell);
        const statusClasses = ['is-present', 'is-absent', 'is-excused-absent', 'is-exempt', 'is-empty', 'is-missing', 'is-locked', 'is-readonly', 'needs-review', 'has-published', 'has-note', 'is-sheet-selected', 'is-sheet-active'];
        element.classList.add('assessment-sheet-mark-cell');
        element.classList.remove(...statusClasses);
        element.classList.add(mark && mark.id === 0 ? 'is-missing' : 'is-' + String(mark && mark.status || 'empty').replace('_', '-'));
        if (mark && mark.locked) {
            element.classList.add('is-locked');
        } else if (mark && !canEditMark(mark)) {
            element.classList.add('is-readonly');
        }
        if (mark && mark.review_status === 'pending') {
            element.classList.add('needs-review');
        }
        if (mark && mark.published_count > 0) {
            element.classList.add('has-published');
        }
        if (mark && mark.note) {
            element.classList.add('has-note');
        }
        const selectionKey = cellSelectionKey(cell);
        element.dataset.sheetKey = selectionKey;
        if (state.selectedKeys.has(selectionKey)) {
            element.classList.add('is-sheet-selected');
        }
        if (state.activeCell && cellSelectionKey(state.activeCell) === selectionKey) {
            element.classList.add('is-sheet-active');
        }

        const content = document.createElement('span');
        content.textContent = displayMark(mark) || '—';
        const flags = document.createElement('span');
        flags.className = 'assessment-sheet-cell-flags';
        if (mark && mark.locked) {
            flags.innerHTML += '<i class="fas fa-lock text-danger" aria-hidden="true"></i>';
        }
        if (mark && mark.published_count > 0) {
            flags.innerHTML += '<i class="fas fa-file-lines text-info" aria-hidden="true"></i>';
        }
        if (mark && mark.note) {
            flags.innerHTML += '<i class="fas fa-note-sticky text-primary" aria-hidden="true"></i>';
        }
        const wrapper = document.createDocumentFragment();
        wrapper.append(content, flags);
        return wrapper;
    }

    function cellTooltip(event, cell) {
        const mark = cellMark(cell);
        if (!mark) {
            return false;
        }
        const parts = [
            mark.student.name,
            mark.column.name + ' — الحد الأقصى ' + formatNumber(mark.max_grade),
            statusLabel(mark.status),
        ];
        if (mark.note) {
            parts.push('ملاحظة: ' + mark.note);
        }
        if (mark.published_count > 0) {
            parts.push('موجودة في ' + formatNumber(mark.published_count) + ' نسخة منشورة');
        }
        if (!canEditMark(mark)) {
            parts.push(mark.id === 0 ? 'لا توجد نافذة رصد واحدة صالحة لإنشاء الدرجة' : 'الخلية مقفلة');
        }
        return parts.join('\n');
    }

    function spreadsheetEditor(cell, onRendered, success, cancel) {
        const mark = cellMark(cell);
        const input = document.createElement('input');
        let touched = false;
        let finished = false;
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', 'درجة ' + (mark ? mark.student.name : 'الطالب'));
        input.value = mark && mark.status === 'present' && mark.value != null ? formatNumber(mark.value) : '';

        const finish = () => {
            if (finished) {
                return true;
            }
            if (!touched && mark && mark.status !== 'present') {
                finished = true;
                cancel();
                return true;
            }
            const raw = englishDigits(input.value).trim();
            if (raw === '') {
                finished = true;
                success('');
                return true;
            }
            const numeric = numberValue(raw);
            if (!Number.isFinite(numeric) || numeric < 0 || numeric > Number(mark.max_grade)) {
                showFeedback('القيمة يجب أن تكون بين 0 و' + formatNumber(mark.max_grade) + '.', 'danger');
                input.focus();
                input.select();
                return false;
            }
            finished = true;
            success(numeric);
            return true;
        };

        onRendered(() => {
            input.focus();
            if (state.editSeed !== '') {
                input.value = state.editSeed;
                state.editSeed = '';
                touched = true;
                input.setSelectionRange(input.value.length, input.value.length);
            } else {
                input.select();
            }
        });
        input.addEventListener('input', () => {
            touched = true;
            if (formulaText && mark) {
                formulaText.textContent = mark.student.name + ' · ' + mark.column.name + ' = ' + englishDigits(input.value);
            }
        });
        input.addEventListener('blur', finish);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                finished = true;
                cancel();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (finish()) {
                    window.setTimeout(() => cell.navigateDown(), 0);
                }
            } else if (event.key === 'Tab') {
                event.preventDefault();
                if (finish()) {
                    window.setTimeout(() => event.shiftKey ? cell.navigatePrev() : cell.navigateNext(), 0);
                }
            }
        });
        return input;
    }

    function columnTitle(letter, name, maximum) {
        return '<span class="assessment-sheet-column-title">'
            + '<span class="assessment-sheet-column-letter">' + escapeHtml(letter) + '</span>'
            + '<span class="assessment-sheet-column-name">' + escapeHtml(englishDigits(name)) + '</span>'
            + (maximum == null ? '' : '<small class="assessment-sheet-column-max">من ' + escapeHtml(formatNumber(maximum)) + '</small>')
            + '</span>';
    }

    function identityColumn(title, field, width, formatter, letter) {
        return {
            title: columnTitle(letter, title, null),
            field,
            width,
            minWidth: width,
            maxWidth: width,
            frozen: true,
            headerSort: false,
            resizable: 'header',
            hozAlign: 'center',
            headerHozAlign: 'center',
            editor: false,
            formatter: formatter || identityFormatter,
            accessorClipboard: (value) => englishDigits(value || ''),
        };
    }

    function renderSheet() {
        if (typeof window.Tabulator !== 'function') {
            setEmpty('تعذر تحميل محرّك الشيت المحلي. حدّث الصفحة أو تحقق من ملفات الأصول.', 'danger', true);
            return;
        }
        destroyTable();
        state.fieldMeta.clear();
        state.markFields = [];

        const columns = [
            identityColumn('كود الطالب', 'student_code', 120, identityFormatter, 'A'),
            identityColumn('اسم المستخدم', 'username', 180, identityFormatter, 'B'),
            identityColumn('اسم الطالب', 'student_name', 240, studentFormatter, 'C'),
            identityColumn('الفصل', 'class_name', 112, identityFormatter, 'D'),
        ];
        let letterIndex = 5;
        (state.data.groups || []).forEach((group) => {
            const groupColumns = [];
            (group.columns || []).forEach((column) => {
                const field = 'mark_' + Number(column.component_id) + '_' + Number(column.week_id || 0);
                const letter = excelColumnName(letterIndex++);
                state.fieldMeta.set(field, {column, letter});
                state.markFields.push(field);
                groupColumns.push({
                    title: columnTitle(letter, column.name, column.max_grade),
                    field,
                    width: 104,
                    minWidth: 96,
                    maxWidth: 180,
                    widthShrink: 0,
                    headerSort: false,
                    resizable: 'header',
                    hozAlign: 'center',
                    headerHozAlign: 'center',
                    cssClass: 'assessment-sheet-mark-column',
                    editable: (cell) => canEditMark(cellMark(cell)),
                    editor: spreadsheetEditor,
                    formatter: markFormatter,
                    tooltip: cellTooltip,
                    accessorClipboard: (value, data) => displayMark(data._sheetMarks && data._sheetMarks[field]),
                });
            });
            columns.push({
                title: '<span class="assessment-sheet-group-title"><strong>'
                    + escapeHtml(englishDigits(group.name || '')) + '</strong><small>'
                    + escapeHtml(englishDigits(group.date_label || '')) + '</small></span>',
                headerHozAlign: 'center',
                columns: groupColumns,
            });
        });

        const rows = (state.data.students || []).map((student) => {
            const row = {
                student_id: Number(student.id),
                student_code: englishDigits(student.student_code || ''),
                username: englishDigits(student.username || ''),
                student_name: englishDigits(student.name || ''),
                class_name: englishDigits(student.class_name || 'بدون فصل'),
                _sheetMarks: {},
                _missing: 0,
                _search: [student.name, student.username, student.student_code, student.class_name]
                    .map((value) => textValue(value).toLocaleLowerCase('ar')).join(' '),
            };
            state.markFields.forEach((field) => {
                const meta = state.fieldMeta.get(field);
                const mark = markForCell(student, field, meta.column);
                row._sheetMarks[field] = mark;
                row[field] = mark.status === 'present' ? mark.value : null;
                if (mark.status === 'empty') {
                    row._missing++;
                }
            });
            student._sheetMarks = row._sheetMarks;
            student._sheetRow = row;
            return row;
        });

        viewport.classList.remove('d-none');
        statusPanel.classList.add('d-none');
        state.table = new window.Tabulator(viewport, {
            data: rows,
            index: 'student_id',
            layout: 'fitData',
            renderVertical: 'virtual',
            rowHeight: 44,
            textDirection: 'rtl',
            editTriggerEvent: 'dblclick',
            columnHeaderVertAlign: 'middle',
            columnDefaults: {
                headerSort: false,
                headerHozAlign: 'center',
                resizable: 'header',
            },
            rowHeader: {
                title: '', field: '_row_number', formatter: 'rownum', accessorClipboard: 'rownum',
                width: 48, minWidth: 48, maxWidth: 48, frozen: true, resizable: false,
                headerSort: false, hozAlign: 'center', headerHozAlign: 'center', editor: false,
                cssClass: 'assessment-sheet-row-number',
            },
            columns,
            placeholder: 'لا توجد نتائج مطابقة للفلاتر.',
        });

        state.table.on('tableBuilt', () => {
            state.table.redraw(true);
            applyRowFilters();
            setSaveState(navigator.onLine ? 'idle' : 'offline', navigator.onLine ? 'جاهز' : 'غير متصل');
        });
        viewport.setAttribute('tabindex', '0');
        state.table.on('cellClick', (event, cell) => {
            if (state.fieldMeta.has(cell.getField())) {
                setActiveCell(cell);
            }
        });
        state.table.on('cellDblClick', (event, cell) => {
            if (state.fieldMeta.has(cell.getField())) {
                setActiveCell(cell);
            }
        });
        state.table.on('cellMouseDown', beginCellSelection);
        state.table.on('cellMouseMove', extendCellSelection);
        state.table.on('cellMouseUp', endCellSelection);
        state.table.on('headerClick', selectColumnFromHeader);
        state.table.on('cellEdited', (cell) => handleCellEdited(cell));
        state.table.on('cellMouseEnter', (event, cell) => highlightColumn(cell.getField()));
        state.table.on('cellMouseLeave', () => highlightColumn(''));
        state.table.on('rowMouseEnter', (event, row) => highlightRow(row));
        state.table.on('rowMouseLeave', () => highlightRow(null));
        recalculateSummary();
    }

    function setActiveCell(cell) {
        state.activeCell = cell;
        viewport.querySelectorAll('.assessment-sheet-mark-cell.is-sheet-active').forEach((element) => {
            element.classList.remove('is-sheet-active');
        });
        if (cell && state.fieldMeta.has(cell.getField())) {
            cell.getElement().classList.add('is-sheet-active');
        }
        const field = cell.getField();
        const fieldInfo = state.fieldMeta.get(field);
        const rowPosition = cell.getRow().getPosition(true) || 1;
        if (nameBox) {
            nameBox.textContent = (fieldInfo ? fieldInfo.letter : '') + formatNumber(rowPosition);
        }
        if (!formulaText) {
            return;
        }
        const mark = cellMark(cell);
        if (mark) {
            formulaText.textContent = mark.student.name + ' · ' + mark.column.name
                + ' · الحد الأقصى ' + formatNumber(mark.max_grade)
                + ' · ' + statusLabel(mark.status)
                + (mark.note ? ' · ' + mark.note : '');
        } else {
            formulaText.textContent = cell.getColumn().getDefinition().title
                ? englishDigits(cell.getValue() || '')
                : 'حدّد خلية درجة.';
        }
    }

    function highlightColumn(field) {
        if (state.hoverField === field) {
            return;
        }
        viewport.querySelectorAll('.assessment-sheet-mark-cell.is-column-hover').forEach((element) => {
            element.classList.remove('is-column-hover');
        });
        state.hoverField = field;
        if (!field || !state.fieldMeta.has(field) || !state.table) {
            return;
        }
        const column = state.table.getColumn(field);
        if (column) {
            column.getCells().forEach((cell) => cell.getElement().classList.add('is-column-hover'));
        }
    }

    function highlightRow(row) {
        viewport.querySelectorAll('.tabulator-row.is-sheet-row-hover').forEach((element) => {
            element.classList.remove('is-sheet-row-hover');
        });
        if (row) {
            row.getElement().classList.add('is-sheet-row-hover');
        }
    }

    function selectedCells() {
        if (!state.table) {
            return [];
        }
        const cells = [];
        state.table.getRows('active').forEach((row) => {
            state.markFields.forEach((field) => {
                const cell = row.getCell(field);
                if (cell && state.selectedKeys.has(cellSelectionKey(cell))) {
                    cells.push(cell);
                }
            });
        });
        return cells;
    }

    function selectedMarkCells() {
        return selectedCells().filter((cell) => state.fieldMeta.has(cell.getField()));
    }

    function updateSelectionUi() {
        const cells = selectedMarkCells();
        const editable = cells.filter((cell) => canEditMark(cellMark(cell)));
        const numeric = cells.map((cell) => cellMark(cell)).filter((mark) => mark && mark.status === 'present' && Number.isFinite(Number(mark.value)));
        const sum = numeric.reduce((total, mark) => total + Number(mark.value), 0);
        const average = numeric.length ? sum / numeric.length : 0;
        if (selectedCount) {
            selectedCount.textContent = formatNumber(cells.length);
        }
        if (editableSelectedCount) {
            editableSelectedCount.textContent = formatNumber(editable.length);
        }
        if (selectionToolbar) {
            selectionToolbar.classList.toggle('d-none', cells.length === 0);
        }
        if (selectionStats) {
            selectionStats.textContent = 'التحديد: ' + formatNumber(cells.length)
                + (numeric.length ? ' · المجموع ' + formatNumber(sum) + ' · المتوسط ' + formatNumber(average) : '');
        }
        configureBulkEditor();
    }

    function refreshSelectionClasses() {
        const activeKey = state.activeCell ? cellSelectionKey(state.activeCell) : '';
        viewport.querySelectorAll('.assessment-sheet-mark-cell[data-sheet-key]').forEach((element) => {
            const key = element.dataset.sheetKey || '';
            element.classList.toggle('is-sheet-selected', state.selectedKeys.has(key));
            element.classList.toggle('is-sheet-active', key !== '' && key === activeKey);
        });
        updateSelectionUi();
    }

    function setSelectionRectangle(anchor, focus) {
        if (!state.table || !anchor || !focus) {
            return;
        }
        const rows = state.table.getRows('active');
        const startRow = rows.findIndex((row) => String(row.getIndex()) === String(anchor.rowId));
        const endRow = rows.findIndex((row) => String(row.getIndex()) === String(focus.rowId));
        const startColumn = state.markFields.indexOf(anchor.field);
        const endColumn = state.markFields.indexOf(focus.field);
        if (startRow < 0 || endRow < 0 || startColumn < 0 || endColumn < 0) {
            return;
        }
        const next = new Set(state.selectionBase);
        const rowFrom = Math.min(startRow, endRow);
        const rowTo = Math.max(startRow, endRow);
        const columnFrom = Math.min(startColumn, endColumn);
        const columnTo = Math.max(startColumn, endColumn);
        for (let rowIndex = rowFrom; rowIndex <= rowTo; rowIndex++) {
            for (let columnIndex = columnFrom; columnIndex <= columnTo; columnIndex++) {
                const key = String(rows[rowIndex].getIndex()) + ':' + state.markFields[columnIndex];
                if (state.selectionMode === 'subtract') {
                    next.delete(key);
                } else {
                    next.add(key);
                }
            }
        }
        state.selectedKeys = next;
        refreshSelectionClasses();
    }

    function beginCellSelection(event, cell) {
        if (event.button !== 0 || event.target.closest('.tabulator-editing')) {
            return;
        }
        const field = cell.getField();
        if (field === '_row_number') {
            selectWholeRow(event, cell.getRow());
            return;
        }
        if (!state.fieldMeta.has(field)) {
            return;
        }
        viewport.focus({preventScroll: true});
        const point = selectionPoint(cell);
        const additive = event.ctrlKey || event.metaKey;
        if (!(event.shiftKey && state.selectionAnchor)) {
            state.selectionAnchor = point;
        }
        state.selectionBase = additive ? new Set(state.selectedKeys) : new Set();
        state.selectionMode = additive && state.selectedKeys.has(cellSelectionKey(cell)) ? 'subtract' : 'add';
        state.isSelecting = true;
        setActiveCell(cell);
        setSelectionRectangle(state.selectionAnchor, point);
    }

    function extendCellSelection(event, cell) {
        if (!state.isSelecting || !state.fieldMeta.has(cell.getField())) {
            return;
        }
        setActiveCell(cell);
        setSelectionRectangle(state.selectionAnchor, selectionPoint(cell));
    }

    function endCellSelection() {
        state.isSelecting = false;
    }

    function selectWholeRow(event, row) {
        const additive = event.ctrlKey || event.metaKey;
        const next = additive ? new Set(state.selectedKeys) : new Set();
        const keys = state.markFields.map((field) => String(row.getIndex()) + ':' + field);
        const remove = additive && keys.every((key) => next.has(key));
        keys.forEach((key) => remove ? next.delete(key) : next.add(key));
        state.selectedKeys = next;
        state.selectionAnchor = state.markFields.length ? {rowId: row.getIndex(), field: state.markFields[0]} : null;
        const active = state.markFields.length ? row.getCell(state.markFields[0]) : null;
        if (active) {
            setActiveCell(active);
        }
        state.isSelecting = false;
        refreshSelectionClasses();
    }

    function selectColumnFromHeader(event, column) {
        const field = column && column.getField ? column.getField() : '';
        if (!state.table || !state.fieldMeta.has(field)) {
            return;
        }
        const rows = state.table.getRows('active');
        const additive = event.ctrlKey || event.metaKey;
        const next = additive ? new Set(state.selectedKeys) : new Set();
        const keys = rows.map((row) => String(row.getIndex()) + ':' + field);
        const remove = additive && keys.length > 0 && keys.every((key) => next.has(key));
        keys.forEach((key) => remove ? next.delete(key) : next.add(key));
        state.selectedKeys = next;
        state.selectionAnchor = rows.length ? {rowId: rows[0].getIndex(), field} : null;
        const active = rows.length ? rows[0].getCell(field) : null;
        if (active) {
            setActiveCell(active);
        }
        refreshSelectionClasses();
    }

    function clearSelection() {
        state.selectedKeys.clear();
        state.selectionAnchor = null;
        state.selectionBase.clear();
        state.isSelecting = false;
        state.activeCell = null;
        viewport.querySelectorAll('.assessment-sheet-mark-cell.is-sheet-selected, .assessment-sheet-mark-cell.is-sheet-active').forEach((element) => {
            element.classList.remove('is-sheet-selected', 'is-sheet-active');
        });
        if (bulkEditor) {
            bulkEditor.classList.add('d-none');
        }
        updateSelectionUi();
    }

    function configureBulkEditor() {
        if (!bulkStatus || !bulkValue) {
            return;
        }
        const status = bulkStatus.value;
        bulkValue.disabled = status !== 'present';
        if (status !== 'present') {
            bulkValue.value = '';
        }
        if (bulkChangeNote && bulkNote) {
            bulkNote.disabled = !bulkChangeNote.checked;
        }
    }

    function markExpectedPayload(mark) {
        if (!mark || mark.id <= 0) {
            return {};
        }
        return {
            expected_value: mark.value == null ? '' : mark.value,
            expected_status: mark.status,
            expected_note: mark.note || '',
            expected_updated_at: mark.updated_at || '',
        };
    }

    function payloadForMark(mark, desired, reason) {
        const payload = {
            academic_year_id: config.academicYearId,
            mark_id: mark.id || 0,
            student_id: mark.student.id,
            scheme_id: state.data.scheme.id,
            component_id: mark.column.component_id,
            week_id: mark.column.week_id == null ? '' : mark.column.week_id,
            window_id: mark.target ? mark.target.id : '',
            mark_status: desired.status,
            value: desired.value == null ? '' : desired.value,
            note: desired.note == null ? mark.note || '' : desired.note,
            reason: reason || 'إدخال مباشر من شيت الدرجات',
            client_request_id: window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : String(Date.now()) + '-' + Math.random(),
        };
        return Object.assign(payload, markExpectedPayload(mark));
    }

    function desiredFromCell(cell) {
        const mark = cellMark(cell);
        const value = numberValue(cell.getValue());
        if (cell.getValue() === '' || cell.getValue() == null) {
            return {status: 'empty', value: null, note: mark.note || ''};
        }
        return {status: 'present', value, note: mark.note || ''};
    }

    function handleCellEdited(cell) {
        if (state.suppressCellEdited) {
            return;
        }
        const mark = cellMark(cell);
        if (!mark || !canEditMark(mark)) {
            cell.restoreOldValue();
            return;
        }
        const desired = desiredFromCell(cell);
        if (desired.status === 'present'
            && (!Number.isFinite(desired.value) || desired.value < 0 || desired.value > Number(mark.max_grade))) {
            cell.restoreOldValue();
            showFeedback('القيمة يجب أن تكون بين 0 و' + formatNumber(mark.max_grade) + '.', 'danger');
            return;
        }
        queueCellSave(cell, desired);
    }

    function queueCellSave(cell, desired) {
        const key = cell.getRow().getIndex() + ':' + cell.getField();
        const sequence = (state.saveSequence.get(key) || 0) + 1;
        state.saveSequence.set(key, sequence);
        const previous = state.saveChains.get(key) || Promise.resolve();
        const task = previous.catch(() => {}).then(() => persistCell(cell, desired, key, sequence));
        state.saveChains.set(key, task);
        task.finally(() => {
            if (state.saveChains.get(key) === task) {
                state.saveChains.delete(key);
            }
        }).catch(() => {});
    }

    async function persistCell(cell, desired, key, sequence) {
        const mark = cellMark(cell);
        const element = cell.getElement();
        element.classList.remove('assessment-sheet-cell-saved', 'assessment-sheet-cell-error');
        element.classList.add('assessment-sheet-cell-pending');
        state.activeSaves++;
        setSaveState('saving', state.activeSaves === 1 ? 'جاري الحفظ…' : 'حفظ ' + formatNumber(state.activeSaves) + ' خلايا…');
        try {
            if (!navigator.onLine) {
                throw new Error('لا يوجد اتصال. لم تُحفظ الخلية وأُعيدت إلى آخر قيمة مؤكدة.');
            }
            const response = await post(config.updateEndpoint, payloadForMark(mark, desired));
            if (response.mark) {
                mark.id = Number(response.mark.id);
                mark.value = response.mark.value == null ? null : Number(response.mark.value);
                mark.status = String(response.mark.status || 'empty');
                mark.note = String(response.mark.note || '');
                mark.review_status = String(response.mark.review_status || 'not_required');
                mark.published_count = Number(response.mark.published_count) || 0;
                mark.updated_at = String(response.mark.updated_at || '');
                mark.target = null;
            }
            refreshStudentMissing(mark.student);
            if (state.saveSequence.get(key) === sequence) {
                state.suppressCellEdited = true;
                cell.setValue(mark.status === 'present' ? mark.value : null, false);
                state.suppressCellEdited = false;
                cell.getRow().reformat();
            }
            element.classList.remove('assessment-sheet-cell-pending');
            element.classList.add('assessment-sheet-cell-saved');
            window.setTimeout(() => element.classList.remove('assessment-sheet-cell-saved'), 1600);
            recalculateSummary();
            if (missingOnly && missingOnly.checked) {
                applyRowFilters();
            }
            if (response.mark && Number(response.mark.published_count) > 0) {
                showFeedback('تم حفظ الدرجة الأصلية. توجد نسخة منشورة لا تتغير تلقائيًا؛ أعد نشر التقرير عند الحاجة.', 'warning', true);
            }
            if (response.batch_id && typeof window.checkUndoState === 'function') {
                window.checkUndoState(true);
            }
        } catch (error) {
            element.classList.remove('assessment-sheet-cell-pending');
            element.classList.add('assessment-sheet-cell-error');
            if (state.saveSequence.get(key) === sequence) {
                state.suppressCellEdited = true;
                cell.setValue(mark.status === 'present' ? mark.value : null, false);
                state.suppressCellEdited = false;
                cell.getRow().reformat();
            }
            setSaveState(navigator.onLine ? 'error' : 'offline', navigator.onLine ? 'فشل الحفظ' : 'غير متصل');
            showFeedback(error.message || 'تعذر حفظ الخلية. لم تتغير الدرجة على الخادم.', 'danger', true);
            throw error;
        } finally {
            state.activeSaves = Math.max(0, state.activeSaves - 1);
            if (state.activeSaves === 0 && saveState.dataset.state !== 'error' && saveState.dataset.state !== 'offline') {
                setSaveState('saved', 'تم الحفظ');
                window.setTimeout(() => {
                    if (state.activeSaves === 0) {
                        setSaveState('idle', 'جاهز');
                    }
                }, 1700);
            }
        }
    }

    function changeForCell(cell, desired) {
        const mark = cellMark(cell);
        if (!mark || !canEditMark(mark)) {
            throw new Error('يتضمن النطاق خلية مقفلة أو بلا نافذة رصد صالحة. لم تُنفذ العملية.');
        }
        if (mark.id === 0 && desired.status === 'empty' && !(desired.note || '').trim()) {
            return null;
        }
        return payloadForMark(mark, desired, '');
    }

    async function applyAtomicChanges(changes, reason, successMessage) {
        const normalized = changes.filter(Boolean);
        if (normalized.length === 0) {
            showFeedback('لا توجد خلايا تحتاج إلى تغيير.', 'info');
            return;
        }
        if (normalized.length > 200) {
            showFeedback('الحد الأقصى للعملية الواحدة هو 200 خلية.', 'warning');
            return;
        }
        setSaveState('saving', 'جاري حفظ النطاق…');
        state.activeSaves++;
        let reload = false;
        try {
            const response = await post(config.bulkEndpoint, {
                action: 'apply_cells',
                academic_year_id: config.academicYearId,
                reason,
                changes: JSON.stringify(normalized),
            });
            const publishedNotice = Number(response.published_count) > 0
                ? ' توجد نسخ منشورة لن تتغير تلقائيًا.'
                : '';
            showFeedback((successMessage || response.message) + publishedNotice, publishedNotice ? 'warning' : 'success', Boolean(publishedNotice));
            setSaveState('saved', 'تم الحفظ');
            if (response.batch_id && typeof window.checkUndoState === 'function') {
                window.checkUndoState(true);
            }
            reload = true;
        } catch (error) {
            setSaveState(navigator.onLine ? 'error' : 'offline', navigator.onLine ? 'فشل الحفظ' : 'غير متصل');
            showFeedback(error.message || 'تعذر حفظ النطاق. لم تتغير أي خلية.', 'danger', true);
        } finally {
            state.activeSaves = Math.max(0, state.activeSaves - 1);
        }
        if (reload) {
            await loadSheet(true);
        }
    }

    function openBulkEditor(forceStatus) {
        const cells = selectedMarkCells();
        if (cells.length === 0) {
            showFeedback('حدّد خلية درجة أو نطاقًا أولًا.', 'warning');
            return;
        }
        if (forceStatus) {
            bulkStatus.value = forceStatus;
        }
        bulkEditor.classList.remove('d-none');
        configureBulkEditor();
        (bulkStatus.value === 'present' ? bulkValue : bulkReason).focus();
    }

    async function runBulkUpdate() {
        const reason = textValue(bulkReason.value);
        if (reason.length < 5) {
            showFeedback('اكتب سببًا واضحًا من خمسة أحرف على الأقل للعملية الجماعية.', 'warning');
            bulkReason.focus();
            return;
        }
        const status = bulkStatus.value;
        const numeric = status === 'present' ? numberValue(bulkValue.value) : null;
        if (status === 'present' && !Number.isFinite(numeric)) {
            showFeedback('اكتب قيمة رقمية صحيحة للتعديل الجماعي.', 'warning');
            bulkValue.focus();
            return;
        }
        const cells = selectedMarkCells();
        const changes = [];
        try {
            cells.forEach((cell) => {
                const mark = cellMark(cell);
                if (status === 'present' && (numeric < 0 || numeric > Number(mark.max_grade))) {
                    throw new Error('القيمة ' + formatNumber(numeric) + ' تتجاوز الحد الأقصى في عمود ' + mark.column.name + '.');
                }
                changes.push(changeForCell(cell, {
                    status,
                    value: numeric,
                    note: bulkChangeNote.checked ? textValue(bulkNote.value) : mark.note,
                }));
            });
        } catch (error) {
            showFeedback(error.message, 'danger', true);
            return;
        }
        bulkEditor.classList.add('d-none');
        await applyAtomicChanges(changes, reason, 'تم حفظ النطاق المحدد كعملية ذرية واحدة.');
    }

    async function clearSelectedValues() {
        const cells = selectedMarkCells();
        const changes = [];
        try {
            cells.forEach((cell) => {
                const mark = cellMark(cell);
                if (mark.id > 0) {
                    changes.push(changeForCell(cell, {status: 'empty', value: null, note: mark.note}));
                }
            });
        } catch (error) {
            showFeedback(error.message, 'danger', true);
            return;
        }
        await applyAtomicChanges(changes, 'مسح قيم نطاق من شيت الدرجات', 'تم مسح قيم النطاق مع الاحتفاظ بسجلات الدرجات.');
    }

    function clipboardToken(token) {
        const value = textValue(token);
        const lowered = value.toLocaleLowerCase('ar');
        if (value === '') {
            return {status: 'empty', value: null};
        }
        if (['غ', 'غائب', 'absent'].includes(lowered)) {
            return {status: 'absent', value: null};
        }
        if (['غ بعذر', 'غياب بعذر', 'بعذر', 'excused_absent'].includes(lowered)) {
            return {status: 'excused_absent', value: null};
        }
        if (['معفى', 'exempt'].includes(lowered)) {
            return {status: 'exempt', value: null};
        }
        const numeric = numberValue(value);
        if (!Number.isFinite(numeric)) {
            throw new Error('تحتوي البيانات الملصقة على قيمة غير مفهومة: «' + value + '».');
        }
        return {status: 'present', value: numeric};
    }

    function structuredSelectedCells() {
        if (!state.table) {
            return [];
        }
        const rows = state.table.getRows('active');
        const selected = selectedMarkCells();
        if (selected.length === 0) {
            return [];
        }
        const rowIndexes = selected.map((cell) => rows.findIndex((row) => row.getIndex() === cell.getRow().getIndex()));
        const columnIndexes = selected.map((cell) => state.markFields.indexOf(cell.getField()));
        const rowFrom = Math.min(...rowIndexes);
        const rowTo = Math.max(...rowIndexes);
        const columnFrom = Math.min(...columnIndexes);
        const columnTo = Math.max(...columnIndexes);
        const structured = [];
        for (let rowIndex = rowFrom; rowIndex <= rowTo; rowIndex++) {
            const rowCells = [];
            for (let columnIndex = columnFrom; columnIndex <= columnTo; columnIndex++) {
                const cell = rows[rowIndex] && rows[rowIndex].getCell(state.markFields[columnIndex]);
                if (!cell || !state.selectedKeys.has(cellSelectionKey(cell))) {
                    throw new Error('النسخ واللصق يتطلبان نطاقًا مستطيلاً متصلاً واحدًا.');
                }
                rowCells.push(cell);
            }
            structured.push(rowCells);
        }
        return structured;
    }

    function pasteTargets(matrix) {
        let target = structuredSelectedCells();
        if (target.length === 0) {
            throw new Error('حدّد نطاقًا واحدًا أو خلية بداية واحدة قبل اللصق.');
        }
        if (target.length === 1 && target[0].length === 1 && (matrix.length > 1 || matrix[0].length > 1)) {
            const start = target[0][0];
            const activeRows = state.table.getRows('active');
            const rowIndex = activeRows.findIndex((row) => row.getIndex() === start.getRow().getIndex());
            const columnIndex = state.markFields.indexOf(start.getField());
            if (rowIndex < 0 || columnIndex < 0) {
                throw new Error('ابدأ اللصق من خلية درجة، وليس من بيانات الطالب.');
            }
            target = [];
            for (let rowOffset = 0; rowOffset < matrix.length; rowOffset++) {
                const row = activeRows[rowIndex + rowOffset];
                if (!row) {
                    throw new Error('البيانات الملصقة تتجاوز آخر طالب ظاهر.');
                }
                const rowCells = [];
                for (let columnOffset = 0; columnOffset < matrix[rowOffset].length; columnOffset++) {
                    const field = state.markFields[columnIndex + columnOffset];
                    const cell = field ? row.getCell(field) : null;
                    if (!cell) {
                        throw new Error('البيانات الملصقة تتجاوز آخر عمود درجات.');
                    }
                    rowCells.push(cell);
                }
                target.push(rowCells);
            }
        } else if (target.length % matrix.length !== 0 || target[0].length % matrix[0].length !== 0) {
            throw new Error('يجب أن يكون حجم النطاق المحدد مساويًا لحجم البيانات المنسوخة أو مضاعفًا صحيحًا له.');
        }
        return target;
    }

    async function handlePaste(event) {
        if (!state.table || event.target.closest('.tabulator-editing')) {
            return;
        }
        const text = event.clipboardData && event.clipboardData.getData('text/plain');
        if (!text) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const matrix = text.replace(/\r/g, '').split('\n');
        if (matrix[matrix.length - 1] === '') {
            matrix.pop();
        }
        const values = matrix.map((row) => row.split('\t'));
        if (!values.length || !values[0].length) {
            return;
        }
        if (values.some((row) => row.length !== values[0].length)) {
            showFeedback('البيانات المنسوخة ليست نطاقًا مستطيلاً صالحًا.', 'danger', true);
            return;
        }
        try {
            const targets = pasteTargets(values);
            const changes = [];
            targets.forEach((row, rowIndex) => {
                row.forEach((cell, columnIndex) => {
                    if (!state.fieldMeta.has(cell.getField())) {
                        throw new Error('لا يمكن لصق الدرجات داخل أعمدة هوية الطالب.');
                    }
                    const token = values[rowIndex % values.length][columnIndex % values[rowIndex % values.length].length];
                    const desired = clipboardToken(token);
                    const mark = cellMark(cell);
                    if (desired.status === 'present' && (desired.value < 0 || desired.value > Number(mark.max_grade))) {
                        throw new Error('القيمة ' + formatNumber(desired.value) + ' تتجاوز الحد الأقصى في عمود ' + mark.column.name + '.');
                    }
                    changes.push(changeForCell(cell, Object.assign({note: mark.note}, desired)));
                });
            });
            await applyAtomicChanges(changes, 'لصق نطاق من شيت الدرجات', 'تم لصق النطاق وحفظه ذريًا.');
        } catch (error) {
            showFeedback(error.message || 'تعذر لصق النطاق. لم تتغير أي خلية.', 'danger', true);
        }
    }

    function handleCopy(event) {
        if (!state.table || event.target.closest('.tabulator-editing')) {
            return;
        }
        if (!event.clipboardData) {
            return;
        }
        try {
            const rows = structuredSelectedCells();
            if (rows.length === 0) {
                return;
            }
            const text = rows.map((row) => row.map((cell) => {
                const mark = cellMark(cell);
                return mark ? displayMark(mark) : englishDigits(cell.getValue() || '');
            }).join('\t')).join('\r\n');
            event.preventDefault();
            event.clipboardData.setData('text/plain', text);
            showFeedback('تم نسخ ' + formatNumber(rows.flat().length) + ' خلية.', 'success');
        } catch (error) {
            event.preventDefault();
            showFeedback(error.message || 'تعذر نسخ النطاق المحدد.', 'warning');
        }
    }

    function applyRowFilters() {
        if (!state.table) {
            return;
        }
        const needle = textValue(studentSearch && studentSearch.value).toLocaleLowerCase('ar');
        const onlyMissing = Boolean(missingOnly && missingOnly.checked);
        state.table.setFilter((row) => {
            if (needle && !String(row._search || '').includes(needle)) {
                return false;
            }
            return !onlyMissing || Number(row._missing) > 0;
        });
        clearSelection();
    }

    function deleteSelectedRecords() {
        const ids = selectedMarkCells().map((cell) => cellMark(cell)).filter((mark) => mark && mark.id > 0).map((mark) => mark.id);
        const uniqueIds = Array.from(new Set(ids));
        if (uniqueIds.length === 0) {
            showFeedback('لا يحتوي التحديد على سجلات درجات محفوظة قابلة للحذف.', 'warning');
            return;
        }
        if (uniqueIds.length > 200) {
            showFeedback('الحد الأقصى للحذف في العملية الواحدة هو 200 سجل.', 'warning');
            return;
        }
        const modalElement = document.getElementById('sheetDeleteSelectedModal');
        const count = document.getElementById('sheetDeleteSelectedCount');
        if (!modalElement || typeof bootstrap === 'undefined') {
            return;
        }
        modalElement.dataset.markIds = uniqueIds.join(',');
        count.textContent = formatNumber(uniqueIds.length);
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    async function submitDelete(event) {
        event.preventDefault();
        const modalElement = document.getElementById('sheetDeleteSelectedModal');
        const reasonElement = document.getElementById('sheetDeleteReason');
        const reason = textValue(reasonElement && reasonElement.value);
        if (reason.length < 5) {
            showFeedback('اكتب سببًا واضحًا من خمسة أحرف على الأقل للحذف.', 'warning');
            reasonElement.focus();
            return;
        }
        const ids = textValue(modalElement.dataset.markIds).split(',').filter(Boolean);
        try {
            const response = await post(config.bulkEndpoint, {
                action: 'delete', academic_year_id: config.academicYearId, mark_ids: ids, reason,
            });
            bootstrap.Modal.getInstance(modalElement).hide();
            const publishedNotice = Number(response.published_count) > 0
                ? ' النسخ المنشورة بقيت كما هي وتحتاج إلى إدارة مستقلة.'
                : '';
            showFeedback(response.message + publishedNotice, publishedNotice ? 'warning' : 'success', Boolean(publishedNotice));
            if (response.batch_id && typeof window.checkUndoState === 'function') {
                window.checkUndoState(true);
            }
            await loadSheet(true);
        } catch (error) {
            showFeedback(error.message || 'تعذر حذف السجلات. لم تتغير البيانات.', 'danger', true);
        }
    }

    gradeSelect.addEventListener('change', () => {
        updateClassOptions();
        state.selectedSchemeId = 0;
        renderSubjectTabs();
        loadSheet();
    });
    termSelect.addEventListener('change', () => {
        state.selectedSchemeId = 0;
        renderSubjectTabs();
        loadSheet();
    });
    classSelect.addEventListener('change', () => loadSheet());
    document.getElementById('reloadMarksSheet').addEventListener('click', () => loadSheet());
    if (studentSearch) {
        studentSearch.addEventListener('input', applyRowFilters);
    }
    if (missingOnly) {
        missingOnly.addEventListener('change', applyRowFilters);
    }
    document.getElementById('sheetBulkEdit').addEventListener('click', () => openBulkEditor('present'));
    document.getElementById('sheetClearValues').addEventListener('click', clearSelectedValues);
    document.getElementById('sheetClearSelection').addEventListener('click', clearSelection);
    document.getElementById('sheetApplyBulkEdit').addEventListener('click', runBulkUpdate);
    document.getElementById('sheetCancelBulkEdit').addEventListener('click', () => bulkEditor.classList.add('d-none'));
    bulkStatus.addEventListener('change', configureBulkEditor);
    bulkChangeNote.addEventListener('change', configureBulkEditor);
    const deleteButton = document.getElementById('sheetBulkDelete');
    if (deleteButton) {
        deleteButton.addEventListener('click', deleteSelectedRecords);
    }
    const deleteForm = document.getElementById('sheetDeleteSelectedForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', submitDelete);
    }

    viewport.addEventListener('paste', handlePaste, true);
    viewport.addEventListener('copy', handleCopy, true);
    viewport.addEventListener('keydown', (event) => {
        if (event.target.closest('.tabulator-editing')) {
            return;
        }
        if ((event.key === 'Delete' || event.key === 'Backspace') && selectedMarkCells().length > 0) {
            event.preventDefault();
            clearSelectedValues();
            return;
        }
        if (state.activeCell && state.fieldMeta.has(state.activeCell.getField())
            && canEditMark(cellMark(state.activeCell))
            && /^[0-9٠-٩۰-۹.,٫]$/.test(event.key)) {
            event.preventDefault();
            state.editSeed = englishDigits(event.key);
            state.activeCell.edit();
        }
    }, true);
    window.addEventListener('mouseup', endCellSelection);

    window.addEventListener('beforeunload', (event) => {
        if (state.activeSaves > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('offline', () => setSaveState('offline', 'غير متصل'));
    window.addEventListener('online', () => {
        if (state.activeSaves === 0) {
            setSaveState('idle', 'عاد الاتصال');
        }
    });

    updateClassOptions();
    renderSubjectTabs();
    loadSheet();
})();
