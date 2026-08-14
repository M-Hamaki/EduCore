(function (window, document) {
    'use strict';

    var storagePrefix = 'educore_school_budget_print_settings_';
    var activeTab = 'detailed';
    var initialized = false;
    var settingsSchemaVersion = 5;
    var defaultCache = {};
    var savedRange = null;
    var savedRangeTab = null;
    var livePreviewActive = false;
    var livePreviewTab = null;

    function getPaper(tabId) {
        return document.querySelector('[data-budget-paper="' + tabId + '"]');
    }

    function activeTabId() {
        var pane = document.querySelector('#budgetTabsContent > .tab-pane.show.active, #budgetTabsContent > .tab-pane.active');
        return pane ? (pane.id || '').replace(/-pane$/, '') : activeTab;
    }

    function readStorage(tabId) {
        try {
            var raw = window.localStorage.getItem(storagePrefix + tabId);
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function writeStorage(tabId, settings) {
        try {
            window.localStorage.setItem(storagePrefix + tabId, JSON.stringify(settings));
        } catch (error) {
            // تظل الإعدادات فعالة خلال الجلسة حتى لو منع المتصفح التخزين المحلي.
        }
    }

    function removeStorage(tabId) {
        try {
            window.localStorage.removeItem(storagePrefix + tabId);
        } catch (error) {
            // لا نوقف تجربة الطباعة إذا لم يتوفر localStorage.
        }
    }

    function paperText(paper, field) {
        var element = paper ? paper.querySelector('[data-budget-field="' + field + '"]') : null;
        return element ? element.textContent.trim() : '';
    }

    function paperValue(paper, field) {
        var element = paper ? paper.querySelector('[data-budget-field="' + field + '"]') : null;
        return element ? (element.getAttribute('data-budget-value') || element.textContent.trim()) : '';
    }

    function normalizePrintDate(value) {
        var raw = String(value || '').trim().replace(/\//g, '-');
        var match = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (!match) {
            return '';
        }
        return match[1] + '-' + String(match[2]).padStart(2, '0') + '-' + String(match[3]).padStart(2, '0');
    }

    function displayPrintDate(value) {
        var normalized = normalizePrintDate(value);
        if (!normalized) {
            return String(value || '').trim();
        }
        return normalized.replace(/-/g, '/');
    }

    function defaultsFor(tabId) {
        if (defaultCache[tabId]) {
            return Object.assign({}, defaultCache[tabId]);
        }
        var paper = getPaper(tabId);
        defaultCache[tabId] = {
            schemaVersion: settingsSchemaVersion,
            orientation: paper && paper.dataset.printOrientation === 'landscape' ? 'landscape' : 'portrait',
            margin: paper && paper.dataset.printMargin ? paper.dataset.printMargin : 'normal',
            font: 'Tajawal',
            density: paper && paper.dataset.tableDensity ? paper.dataset.tableDensity : 'normal',
            lineHeight: paper && paper.style.lineHeight ? paper.style.lineHeight : '',
            showHeader: true,
            showMeta: true,
            showLogo: true,
            showPhoto: false,
            showBorder: false,
            showSignatures: true,
            showDate: true,
            showAcademicYear: true,
            showTable: true,
            printLanguage: 'ar',
            showNote: false,
            signatureMode: 'titles_names',
            sigStudentAffairs: true,
            sigSchoolDirector: true,
            sigAdminDirector: false,
            sigKgDirector: false,
            sigPrimaryDirector: false,
            sigPrepSecDirector: false,
            titleUnderline: true,
            title: paperText(paper, 'title'),
            subtitle: paperText(paper, 'subtitle'),
            printDate: paperValue(paper, 'printDate'),
            academicYear: paperText(paper, 'academicYear'),
            note: ''
        };
        return Object.assign({}, defaultCache[tabId]);
    }

    function settingsFor(tabId) {
        var defaults = defaultsFor(tabId);
        var stored = readStorage(tabId);
        if (!stored || typeof stored !== 'object') {
            return defaults;
        }
        // الإصدارات السابقة خزّنت كثافة مضغوطة للتبويبين المختصر والتاريخي؛
        // يُرحّل ذلك مرة واحدة إلى كثافة مستقلة ومناسبة لورقة كل تبويب.
        if (Number(stored.schemaVersion || 0) < settingsSchemaVersion) {
            if ((tabId === 'buffer' || tabId === 'historical') && String(stored.density || '') === 'compact') {
                stored.density = defaults.density;
            }
        }
        var hasIndependentMeta = Object.prototype.hasOwnProperty.call(stored, 'showDate') || Object.prototype.hasOwnProperty.call(stored, 'showAcademicYear');
        var merged = Object.assign(defaults, stored);
        merged.schemaVersion = settingsSchemaVersion;
        if (!hasIndependentMeta) {
            merged.showDate = merged.showMeta !== false;
            merged.showAcademicYear = merged.showMeta !== false;
        }
        merged.showMeta = merged.showDate !== false || merged.showAcademicYear !== false;
        // تنظيف قيمة الوصف القديمة حتى لا تعود الجملة المحذوفة من التخزين المحلي.
        if (/^مستخرج\s+رسمي\s+من\s+نظام\s+شؤون\s+الطلاب$/.test(String(merged.subtitle || '').trim())) {
            merged.subtitle = '';
        }
        return merged;
    }

    function effectiveOrientation(tabId, value) {
        if (value === 'landscape' || value === 'portrait') {
            return value;
        }
        return tabId === 'historical' ? 'landscape' : 'portrait';
    }

    function normalizedSignatureMode(value) {
        return value === 'titles' || value === 'titles_only' ? 'titles' : 'titles_names';
    }

    function applyBudgetSignatureVisibility(paper, settings) {
        if (!paper) {
            return;
        }
        var visibility = {
            student_affairs: settings.sigStudentAffairs !== false,
            school_director: settings.sigSchoolDirector !== false,
            admin_director: settings.sigAdminDirector === true,
            stage_kg: settings.sigKgDirector === true,
            stage_primary: settings.sigPrimaryDirector === true,
            stage_prep_sec: settings.sigPrepSecDirector === true
        };
        var visibleCount = 0;
        paper.querySelectorAll('[data-budget-signature-col]').forEach(function (column) {
            var key = column.getAttribute('data-budget-signature-col');
            var isVisible = visibility[key] === true;
            column.dataset.budgetSignatureVisible = isVisible ? 'true' : 'false';
            if (isVisible) {
                visibleCount += 1;
            }
        });
        var footer = paper.querySelector('.budget-official-footer');
        if (footer) {
            footer.dataset.visibleSignatures = String(visibleCount);
        }
    }

    function applyBudgetLanguage(paper, language) {
        if (!paper) {
            return;
        }
        var isEnglish = language === 'en';
        paper.dataset.budgetLanguage = isEnglish ? 'en' : 'ar';
        paper.querySelectorAll('[data-budget-ar][data-budget-en]').forEach(function (element) {
            element.textContent = isEnglish ? element.dataset.budgetEn : element.dataset.budgetAr;
        });
    }

    function applySettings(tabId, settings) {
        var paper = getPaper(tabId);
        if (!paper) {
            return;
        }

        paper.dataset.printOrientation = effectiveOrientation(tabId, settings.orientation);
        paper.dataset.printMargin = settings.margin || 'normal';
        paper.dataset.tableDensity = settings.density || 'normal';
        paper.dataset.showHeader = settings.showHeader === false ? 'false' : 'true';
        paper.dataset.showMeta = settings.showMeta === false ? 'false' : 'true';
        paper.dataset.showLogo = settings.showLogo === false ? 'false' : 'true';
        paper.dataset.showPhoto = settings.showPhoto === true ? 'true' : 'false';
        paper.dataset.showBorder = settings.showBorder === true ? 'true' : 'false';
        paper.dataset.showSignatures = settings.showSignatures === false ? 'false' : 'true';
        paper.dataset.showDate = settings.showDate === false ? 'false' : 'true';
        paper.dataset.showAcademicYear = settings.showAcademicYear === false ? 'false' : 'true';
        paper.dataset.showTable = settings.showTable === false ? 'false' : 'true';
        paper.dataset.showNote = settings.showNote && String(settings.note || '').trim() ? 'true' : 'false';
        var signatureMode = settings.signatureMode === 'titles' ? 'titles' : normalizedSignatureMode(settings.signatureMode);
        paper.dataset.signatureMode = signatureMode;
        applyBudgetSignatureVisibility(paper, settings);
        applyBudgetLanguage(paper, settings.printLanguage === 'en' ? 'en' : 'ar');
        paper.style.setProperty('--budget-font-family', "'" + String(settings.font || 'Tajawal').replace(/['"\\]/g, '') + "'");
        paper.style.lineHeight = settings.lineHeight || '';

        var titleElement = paper.querySelector('[data-budget-field="title"]');
        if (titleElement) {
            titleElement.dataset.budgetTitleUnderline = settings.titleUnderline === false ? 'false' : 'true';
        }

        ['title', 'subtitle', 'note', 'academicYear'].forEach(function (field) {
            var element = paper.querySelector('[data-budget-field="' + field + '"]');
            if (element && settings[field] !== undefined) {
                element.textContent = settings[field];
            }
        });
        var printDateElement = paper.querySelector('[data-budget-field="printDate"]');
        var normalizedPrintDate = normalizePrintDate(settings.printDate || paperValue(paper, 'printDate'));
        if (printDateElement && normalizedPrintDate) {
            printDateElement.setAttribute('data-budget-value', normalizedPrintDate);
            printDateElement.textContent = displayPrintDate(normalizedPrintDate);
        }
    }

    function fillForm(settings) {
        var setValue = function (id, value) {
            var element = document.getElementById(id);
            if (element) {
                element.value = value;
            }
        };
        var setChecked = function (id, value) {
            var element = document.getElementById(id);
            if (element) {
                element.checked = value;
            }
        };
        setValue('budgetPrintOrientation', settings.orientation || 'auto');
        setValue('budgetPrintMargin', settings.margin || 'normal');
        setValue('budgetPrintDate', normalizePrintDate(settings.printDate || ''));
        var academicYearSelect = document.getElementById('budgetAcademicYear');
        var academicYearValue = settings.academicYear || '';
        if (academicYearSelect && academicYearValue && !Array.prototype.some.call(academicYearSelect.options, function (option) {
            return option.value === academicYearValue;
        })) {
            academicYearSelect.appendChild(new Option(academicYearValue, academicYearValue));
        }
        if (academicYearSelect) {
            academicYearSelect.value = academicYearValue;
        }
        setValue('budgetPrintFont', settings.font || 'Tajawal');
        setValue('budgetTableDensity', settings.density || 'normal');
        setValue('budgetPrintTitle', settings.title || '');
        setValue('budgetPrintSubtitle', settings.subtitle || '');
        setValue('budgetPrintNote', settings.note || '');
        setChecked('budgetShowHeader', settings.showHeader !== false);
        setChecked('budgetShowMeta', settings.showMeta !== false);
        setChecked('budgetShowLogo', settings.showLogo !== false);
        setChecked('budgetShowPhoto', settings.showPhoto === true);
        setChecked('budgetShowBorder', settings.showBorder === true);
        setChecked('budgetShowSignatures', settings.showSignatures !== false);
        setChecked('budgetShowDate', settings.showDate !== false);
        setChecked('budgetShowAcademicYear', settings.showAcademicYear !== false);
        setChecked('budgetShowTable', settings.showTable !== false);
        setChecked('budgetShowNote', settings.showNote === true);
        setValue('budgetPrintLanguage', settings.printLanguage === 'en' ? 'en' : 'ar');
        setValue('budgetSignatureMode', normalizedSignatureMode(settings.signatureMode) === 'titles' ? 'titles_only' : 'titles_and_names');
        setChecked('budgetShowStudentAffairs', settings.sigStudentAffairs !== false);
        setChecked('budgetShowSchoolDirector', settings.sigSchoolDirector !== false);
        setChecked('budgetShowAdminDirector', settings.sigAdminDirector === true);
        setChecked('budgetShowKgDirector', settings.sigKgDirector === true);
        setChecked('budgetShowPrimaryDirector', settings.sigPrimaryDirector === true);
        setChecked('budgetShowPrepSecDirector', settings.sigPrepSecDirector === true);
    }

    function settingsFromForm() {
        var currentSettings = settingsFor(activeTab);
        var paper = getPaper(activeTab);
        var readValue = function (id, fallback) {
            var element = document.getElementById(id);
            return element ? element.value : fallback;
        };
        var readChecked = function (id, fallback) {
            var element = document.getElementById(id);
            return element ? element.checked : fallback;
        };
        var showDate = readChecked('budgetShowDate', currentSettings.showDate !== false);
        var showAcademicYear = readChecked('budgetShowAcademicYear', currentSettings.showAcademicYear !== false);
        var readEditableField = function (field, fallback) {
            var element = paper ? paper.querySelector('[data-budget-field="' + field + '"]') : null;
            return element ? element.textContent.trim() : fallback;
        };
        var printDateElement = paper ? paper.querySelector('[data-budget-field="printDate"]') : null;
        var editablePrintDate = printDateElement
            ? normalizePrintDate(printDateElement.textContent.trim())
            : '';
        if (printDateElement && editablePrintDate) {
            printDateElement.setAttribute('data-budget-value', editablePrintDate);
        }
        return {
            schemaVersion: settingsSchemaVersion,
            orientation: readValue('budgetPrintOrientation', currentSettings.orientation || 'auto'),
            margin: readValue('budgetPrintMargin', currentSettings.margin || 'normal'),
            printDate: editablePrintDate || currentSettings.printDate || '',
            academicYear: readEditableField('academicYear', currentSettings.academicYear || ''),
            font: readValue('budgetPrintFont', currentSettings.font || 'Tajawal'),
            density: readValue('budgetTableDensity', currentSettings.density || 'normal'),
            title: readEditableField('title', currentSettings.title || ''),
            subtitle: String(readValue('budgetPrintSubtitle', readEditableField('subtitle', currentSettings.subtitle || ''))).trim(),
            note: String(readValue('budgetPrintNote', currentSettings.note || '')).trim(),
            showHeader: readChecked('budgetShowHeader', currentSettings.showHeader !== false),
            showMeta: showDate || showAcademicYear,
            showLogo: readChecked('budgetShowLogo', currentSettings.showLogo !== false),
            showPhoto: readChecked('budgetShowPhoto', currentSettings.showPhoto === true),
            showBorder: readChecked('budgetShowBorder', currentSettings.showBorder === true),
            showSignatures: readChecked('budgetShowSignatures', currentSettings.showSignatures !== false),
            showDate: showDate,
            showAcademicYear: showAcademicYear,
            showTable: readChecked('budgetShowTable', currentSettings.showTable !== false),
            printLanguage: readValue('budgetPrintLanguage', currentSettings.printLanguage || 'ar') === 'en' ? 'en' : 'ar',
            showNote: readChecked('budgetShowNote', currentSettings.showNote === true),
            signatureMode: normalizedSignatureMode(readValue('budgetSignatureMode', currentSettings.signatureMode || 'titles_names')),
            sigStudentAffairs: readChecked('budgetShowStudentAffairs', currentSettings.sigStudentAffairs !== false),
            sigSchoolDirector: readChecked('budgetShowSchoolDirector', currentSettings.sigSchoolDirector !== false),
            sigAdminDirector: readChecked('budgetShowAdminDirector', currentSettings.sigAdminDirector === true),
            sigKgDirector: readChecked('budgetShowKgDirector', currentSettings.sigKgDirector === true),
            sigPrimaryDirector: readChecked('budgetShowPrimaryDirector', currentSettings.sigPrimaryDirector === true),
            sigPrepSecDirector: readChecked('budgetShowPrepSecDirector', currentSettings.sigPrepSecDirector === true),
            titleUnderline: currentSettings.titleUnderline !== false
        };
    }

    function activePaper() {
        return getPaper(activeTabId());
    }

    function elementFromBudgetNode(node) {
        if (!node) {
            return null;
        }
        return node.nodeType === 1 ? node : node.parentElement;
    }

    function closestBudgetField(element, field, paper) {
        var current = element;
        while (current && current !== paper) {
            if (current.nodeType === 1 && current.getAttribute('data-budget-field') === field) {
                return current;
            }
            current = current.parentElement;
        }
        return current && current.getAttribute && current.getAttribute('data-budget-field') === field ? current : null;
    }

    function budgetSelectionContext() {
        var paper = activePaper();
        if (!paper) {
            return { paper: null, range: null, element: null };
        }

        var selection = window.getSelection ? window.getSelection() : null;
        var range = null;
        var anchorNode = null;
        if (selection && selection.rangeCount) {
            var currentRange = selection.getRangeAt(0);
            if (paper.contains(currentRange.commonAncestorContainer)) {
                range = currentRange;
                anchorNode = selection.anchorNode;
            }
        }
        if (!range && savedRange && savedRangeTab === activeTabId() && paper.contains(savedRange.commonAncestorContainer)) {
            range = savedRange;
            anchorNode = savedRange.startContainer;
        }

        var element = range ? elementFromBudgetNode(anchorNode || range.startContainer) : null;
        if (element && !paper.contains(element)) {
            element = null;
        }
        return { paper: paper, range: range, element: element };
    }

    function titleFieldForSelection(context) {
        if (!context || !context.element || !context.range) {
            return null;
        }
        var title = closestBudgetField(context.element, 'title', context.paper);
        if (!title || !title.contains(context.range.endContainer)) {
            return null;
        }
        return title;
    }

    function getBudgetSelectedBlocks(context) {
        if (!context || !context.paper || !context.range) {
            return [];
        }
        var selector = '.budget-report-title, .budget-report-subtitle, .budget-header-school, .budget-header-meta, .budget-print-note, .budget-official-footer, td, th, p, li';
        if (context.range.collapsed) {
            var anchor = context.element;
            var collapsedBlock = anchor && anchor.closest ? anchor.closest(selector) : null;
            return collapsedBlock && context.paper.contains(collapsedBlock) ? [collapsedBlock] : [];
        }
        return Array.prototype.filter.call(context.paper.querySelectorAll(selector), function (block) {
            try {
                return context.range.intersectsNode(block);
            } catch (error) {
                return false;
            }
        });
    }

    function rememberBudgetSelection() {
        var selection = window.getSelection ? window.getSelection() : null;
        if (!selection || !selection.rangeCount) {
            return;
        }
        var paper = activePaper();
        var range = selection.getRangeAt(0);
        if (paper && paper.contains(range.commonAncestorContainer)) {
            savedRange = range.cloneRange();
            savedRangeTab = activeTabId();
        }
    }

    function restoreBudgetSelection(paper) {
        if (!paper) {
            return;
        }
        paper.focus();
        if (!savedRange || savedRangeTab !== activeTabId()) {
            return;
        }
        var selection = window.getSelection ? window.getSelection() : null;
        if (!selection) {
            return;
        }
        try {
            selection.removeAllRanges();
            selection.addRange(savedRange);
        } catch (error) {
            // تجاهل النطاق المنتهي إذا تغيّر محتوى الورقة بعد الحفظ.
        }
    }

    function executeBudgetCommand(command, value) {
        var paper = activePaper();
        if (!paper) {
            return;
        }
        restoreBudgetSelection(paper);

        if (['justifyRight', 'justifyCenter', 'justifyLeft', 'justifyFull'].indexOf(command) !== -1) {
            applyBudgetAlignment(command);
            return;
        }

        var selectionContext = budgetSelectionContext();
        var titleElement = titleFieldForSelection(selectionContext);
        if (command === 'underline' && titleElement) {
            var titleSettings = settingsFor(activeTabId());
            var underlineEnabled = titleElement.dataset.budgetTitleUnderline !== 'true';
            titleElement.dataset.budgetTitleUnderline = underlineEnabled ? 'true' : 'false';
            titleSettings.titleUnderline = underlineEnabled;
            writeStorage(activeTabId(), titleSettings);
            syncBudgetEditorToolbar();
            return;
        }
        if (command === 'removeFormat' && titleElement) {
            var formatSettings = settingsFor(activeTabId());
            titleElement.dataset.budgetTitleUnderline = 'false';
            formatSettings.titleUnderline = false;
            writeStorage(activeTabId(), formatSettings);
        }

        try {
            document.execCommand('styleWithCSS', false, true);
        } catch (error) {}
        try {
            document.execCommand(command, false, value === undefined ? null : value);
        } catch (error) {
            return;
        }
        rememberBudgetSelection();
        syncBudgetEditorToolbar();
    }

    function applyBudgetFont(font) {
        var tabId = activeTabId();
        var settings = settingsFor(tabId);
        settings.font = font || 'Tajawal';
        writeStorage(tabId, settings);
        applySettings(tabId, settings);
        syncBudgetEditorToolbar();
    }

    function applyBudgetFontSize(size) {
        if (!size) {
            return;
        }
        var paper = activePaper();
        if (!paper) {
            return;
        }
        restoreBudgetSelection(paper);
        var selection = window.getSelection ? window.getSelection() : null;
        if (!selection || !selection.rangeCount || selection.isCollapsed) {
            return;
        }
        var range = selection.getRangeAt(0);
        if (!paper.contains(range.commonAncestorContainer)) {
            return;
        }

        var startElement = elementFromBudgetNode(range.startContainer);
        var endElement = elementFromBudgetNode(range.endContainer);
        var startCell = startElement && startElement.closest ? startElement.closest('td, th') : null;
        var endCell = endElement && endElement.closest ? endElement.closest('td, th') : null;
        if ((startCell || endCell) && startCell !== endCell) {
            return;
        }

        var span = document.createElement('span');
        span.style.fontSize = size;
        try {
            span.appendChild(range.extractContents());
            range.insertNode(span);
            range.selectNodeContents(span);
            selection.removeAllRanges();
            selection.addRange(range);
        } catch (error) {
            return;
        }
        rememberBudgetSelection();
        syncBudgetEditorToolbar();
    }

    function stepBudgetFont(direction) {
        var sizeSelect = document.getElementById('budgetEditorFontSize');
        if (!sizeSelect) {
            return;
        }
        var sizes = Array.prototype.map.call(sizeSelect.options, function (option) {
            return option.value;
        }).filter(Boolean);
        var currentIndex = sizes.indexOf(sizeSelect.value);
        if (currentIndex === -1) {
            currentIndex = sizes.indexOf('16px');
        }
        var nextIndex = Math.max(0, Math.min(sizes.length - 1, currentIndex + direction));
        sizeSelect.value = sizes[nextIndex];
        applyBudgetFontSize(sizeSelect.value);
    }

    function applyBudgetAlignment(command) {
        var alignmentByCommand = {
            justifyRight: 'right',
            justifyCenter: 'center',
            justifyLeft: 'left',
            justifyFull: 'justify'
        };
        var alignment = alignmentByCommand[command];
        var paper = activePaper();
        if (!alignment || !paper) {
            return;
        }
        restoreBudgetSelection(paper);
        var context = budgetSelectionContext();
        var blocks = getBudgetSelectedBlocks(context);
        if (!blocks.length) {
            return;
        }
        blocks.forEach(function (block) {
            block.style.textAlign = alignment;
        });
        rememberBudgetSelection();
        syncBudgetEditorToolbar();
    }

    function applyBudgetLineHeight(value) {
        if (!value) {
            return;
        }
        var tabId = activeTabId();
        var paper = activePaper();
        if (!paper) {
            return;
        }
        restoreBudgetSelection(paper);
        var selectionContext = budgetSelectionContext();
        var selectedBlocks = getBudgetSelectedBlocks(selectionContext);
        if (selectedBlocks.length) {
            selectedBlocks.forEach(function (block) {
                block.style.lineHeight = value;
            });
            rememberBudgetSelection();
            syncBudgetEditorToolbar();
            return;
        }
        paper.style.lineHeight = value;
        var settings = settingsFor(tabId);
        settings.lineHeight = value;
        writeStorage(tabId, settings);
        syncBudgetEditorToolbar();
    }

    function placeBudgetEditorToolbar(tabId) {
        var toolbar = document.getElementById('budgetEditorToolbar');
        var paper = getPaper(tabId || activeTabId());
        if (!toolbar || !paper || !paper.parentNode) {
            return;
        }
        paper.parentNode.insertBefore(toolbar, paper);
        toolbar.setAttribute('data-budget-orientation', paper.dataset.printOrientation || 'portrait');
    }

    function cssColorToHex(color) {
        var value = String(color || '').trim();
        if (!value || value === 'transparent' || value === 'rgba(0, 0, 0, 0)') {
            return '';
        }
        var match = value.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s]+([\d.]+))?\s*\)$/i);
        if (!match || (match[4] !== undefined && parseFloat(match[4]) === 0)) {
            return '';
        }
        return '#' + [match[1], match[2], match[3]].map(function (channel) {
            return Math.max(0, Math.min(255, Math.round(parseFloat(channel)))).toString(16).padStart(2, '0');
        }).join('');
    }

    function normalizedPixelSize(value) {
        var number = parseFloat(value);
        if (!isFinite(number) || number <= 0) {
            return '';
        }
        var rounded = Math.round(number * 100) / 100;
        return String(rounded).replace(/\.0+$/, '') + 'px';
    }

    function syncBudgetFontSizeSelect(sizeSelect, computedStyle) {
        if (!sizeSelect) {
            return;
        }
        var customOption = sizeSelect.querySelector('option[data-budget-custom-size="true"]');
        var actualSize = normalizedPixelSize(computedStyle && computedStyle.fontSize);
        if (!actualSize) {
            if (customOption) {
                customOption.remove();
            }
            sizeSelect.value = '';
            return;
        }

        var hasExactOption = Array.prototype.some.call(sizeSelect.options, function (option) {
            return option.value === actualSize && option !== customOption;
        });
        if (hasExactOption) {
            if (customOption) {
                customOption.remove();
            }
            sizeSelect.value = actualSize;
            return;
        }

        if (!customOption) {
            customOption = document.createElement('option');
            customOption.dataset.budgetCustomSize = 'true';
            sizeSelect.appendChild(customOption);
        }
        customOption.value = actualSize;
        customOption.textContent = actualSize + ' (فعلي)';
        sizeSelect.value = actualSize;
    }

    function syncBudgetLineHeightSelect(lineHeightSelect, computedStyle, fallbackValue) {
        if (!lineHeightSelect) {
            return;
        }
        var customOption = lineHeightSelect.querySelector('option[data-budget-custom-line-height="true"]');
        var fontSize = computedStyle ? parseFloat(computedStyle.fontSize) : NaN;
        var lineHeight = computedStyle ? parseFloat(computedStyle.lineHeight) : NaN;
        var ratio = Number.isFinite(fontSize) && fontSize > 0 && Number.isFinite(lineHeight)
            ? Math.round((lineHeight / fontSize) * 100) / 100
            : NaN;
        var actualValue = Number.isFinite(ratio) ? String(ratio) : String(fallbackValue || '');
        if (!actualValue) {
            if (customOption) {
                customOption.remove();
            }
            lineHeightSelect.value = '';
            return;
        }

        var hasExactOption = Array.prototype.some.call(lineHeightSelect.options, function (option) {
            return option.value === actualValue && option !== customOption;
        });
        if (hasExactOption) {
            if (customOption) {
                customOption.remove();
            }
            lineHeightSelect.value = actualValue;
            return;
        }

        if (!customOption) {
            customOption = document.createElement('option');
            customOption.dataset.budgetCustomLineHeight = 'true';
            lineHeightSelect.appendChild(customOption);
        }
        customOption.value = actualValue;
        customOption.textContent = actualValue + ' (فعلي)';
        lineHeightSelect.value = actualValue;
    }

    function setBudgetCommandState(button, active) {
        button.classList.toggle('active', !!active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function queryBudgetCommandState(command) {
        try {
            return document.queryCommandState(command) === true;
        } catch (error) {
            return false;
        }
    }

    function syncBudgetCommandStates(toolbar, selectionContext) {
        if (!toolbar) {
            return;
        }
        var computedStyle = selectionContext && selectionContext.element && window.getComputedStyle
            ? window.getComputedStyle(selectionContext.element)
            : null;
        var titleElement = titleFieldForSelection(selectionContext);
        var decoration = computedStyle ? String(computedStyle.textDecorationLine || computedStyle.textDecoration || '') : '';
        var textAlign = computedStyle ? String(computedStyle.textAlign || '').toLowerCase() : '';
        var states = {
            bold: queryBudgetCommandState('bold') || (computedStyle ? (computedStyle.fontWeight === 'bold' || parseInt(computedStyle.fontWeight, 10) >= 600) : false),
            italic: queryBudgetCommandState('italic') || (computedStyle ? computedStyle.fontStyle === 'italic' : false),
            underline: titleElement
                ? titleElement.dataset.budgetTitleUnderline !== 'false'
                : queryBudgetCommandState('underline') || decoration.indexOf('underline') !== -1,
            strikeThrough: queryBudgetCommandState('strikeThrough') || decoration.indexOf('line-through') !== -1,
            justifyRight: queryBudgetCommandState('justifyRight') || textAlign === 'right',
            justifyCenter: queryBudgetCommandState('justifyCenter') || textAlign === 'center',
            justifyLeft: queryBudgetCommandState('justifyLeft') || textAlign === 'left',
            justifyFull: queryBudgetCommandState('justifyFull') || textAlign === 'justify',
            insertUnorderedList: queryBudgetCommandState('insertUnorderedList'),
            insertOrderedList: queryBudgetCommandState('insertOrderedList')
        };
        toolbar.querySelectorAll('[data-budget-command]').forEach(function (button) {
            var command = button.getAttribute('data-budget-command');
            if (Object.prototype.hasOwnProperty.call(states, command)) {
                setBudgetCommandState(button, states[command]);
            } else {
                button.removeAttribute('aria-pressed');
                button.classList.remove('active');
            }
        });
    }

    function syncBudgetEditorToolbar() {
        var tabId = activeTabId();
        var settings = settingsFor(tabId);
        var fontSelect = document.getElementById('budgetEditorFont');
        var sizeSelect = document.getElementById('budgetEditorFontSize');
        var lineHeightSelect = document.getElementById('budgetEditorLineHeight');
        var paper = activePaper();
        var toolbar = document.getElementById('budgetEditorToolbar');
        var selectionContext = budgetSelectionContext();
        var computedStyle = selectionContext.element && window.getComputedStyle
            ? window.getComputedStyle(selectionContext.element)
            : null;
        if (fontSelect) {
            fontSelect.value = settings.font || 'Tajawal';
        }
        if (lineHeightSelect && paper) {
            syncBudgetLineHeightSelect(lineHeightSelect, computedStyle, settings.lineHeight || paper.style.lineHeight || '');
        }
        var textColor = document.getElementById('budgetEditorTextColor');
        var highlightColor = document.getElementById('budgetEditorHighlightColor');
        if (computedStyle && textColor) {
            var selectedTextColor = cssColorToHex(computedStyle.color);
            if (selectedTextColor) {
                textColor.value = selectedTextColor;
            }
        }
        if (computedStyle && highlightColor) {
            var selectedHighlightColor = cssColorToHex(computedStyle.backgroundColor);
            if (selectedHighlightColor) {
                highlightColor.value = selectedHighlightColor;
            }
        }
        syncBudgetFontSizeSelect(sizeSelect, computedStyle);
        syncBudgetCommandStates(toolbar, selectionContext);
    }

    // حفظ الحقول التي يحررها المستخدم داخل الورقة حتى لا تعيد نافذة الإعدادات أو الطباعة النص القديم.
    function persistEditablePaperFields(paper) {
        if (!paper) {
            return;
        }
        var tabId = paper.getAttribute('data-budget-paper');
        if (!tabId) {
            return;
        }
        var settings = settingsFor(tabId);
        ['title', 'subtitle', 'academicYear', 'note'].forEach(function (field) {
            var element = paper.querySelector('[data-budget-field="' + field + '"]');
            if (element) {
                settings[field] = element.textContent.trim();
            }
        });
        var printDateElement = paper.querySelector('[data-budget-field="printDate"]');
        if (printDateElement) {
            var normalizedDate = normalizePrintDate(printDateElement.textContent.trim());
            if (normalizedDate) {
                printDateElement.setAttribute('data-budget-value', normalizedDate);
                settings.printDate = normalizedDate;
            }
        }
        writeStorage(tabId, settings);
    }

    function initializeBudgetEditorToolbar() {
        var toolbar = document.getElementById('budgetEditorToolbar');
        if (!toolbar) {
            return;
        }
        toolbar.querySelectorAll('[data-budget-command]').forEach(function (button) {
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                rememberBudgetSelection();
            });
            button.addEventListener('click', function () {
                executeBudgetCommand(button.getAttribute('data-budget-command'));
            });
        });
        toolbar.querySelectorAll('[data-budget-font-step]').forEach(function (button) {
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                rememberBudgetSelection();
            });
            button.addEventListener('click', function () {
                stepBudgetFont(parseInt(button.getAttribute('data-budget-font-step'), 10) || 0);
            });
        });
        var fontSelect = document.getElementById('budgetEditorFont');
        if (fontSelect) {
            fontSelect.addEventListener('change', function () { applyBudgetFont(this.value); });
        }
        var sizeSelect = document.getElementById('budgetEditorFontSize');
        if (sizeSelect) {
            sizeSelect.addEventListener('mousedown', rememberBudgetSelection);
            sizeSelect.addEventListener('change', function () { applyBudgetFontSize(this.value); });
        }
        var textColor = document.getElementById('budgetEditorTextColor');
        if (textColor) {
            textColor.addEventListener('mousedown', rememberBudgetSelection);
            textColor.addEventListener('input', function () {
                executeBudgetCommand('foreColor', this.value);
            });
        }
        var highlightColor = document.getElementById('budgetEditorHighlightColor');
        if (highlightColor) {
            highlightColor.addEventListener('mousedown', rememberBudgetSelection);
            highlightColor.addEventListener('input', function () {
                executeBudgetCommand('hiliteColor', this.value);
            });
        }
        var lineHeightSelect = document.getElementById('budgetEditorLineHeight');
        if (lineHeightSelect) {
            lineHeightSelect.addEventListener('change', function () { applyBudgetLineHeight(this.value); });
        }
        document.querySelectorAll('.budget-editable-paper').forEach(function (paper) {
            paper.addEventListener('input', function () {
                persistEditablePaperFields(paper);
                syncBudgetEditorToolbar();
            });
            paper.addEventListener('mouseup', function () {
                rememberBudgetSelection();
                syncBudgetEditorToolbar();
            });
            paper.addEventListener('keyup', function () {
                rememberBudgetSelection();
                syncBudgetEditorToolbar();
            });
        });
        document.addEventListener('selectionchange', function () {
            rememberBudgetSelection();
            syncBudgetEditorToolbar();
        });
        syncBudgetEditorToolbar();
    }

    function openSettings(tabId) {
        activeTab = tabId || activeTabId();
        var settings = settingsFor(activeTab);
        livePreviewActive = true;
        livePreviewTab = activeTab;
        applySettings(activeTab, settings);
        fillForm(settings);
        updateLivePreviewStatus('المعاينة الحية مفعّلة — التغييرات ظاهرة الآن ولم تُحفظ بعد.');
        var modalElement = document.getElementById('budgetPrintSettingsModal');
        if (window.bootstrap && modalElement) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        }
    }

    function resetSettings() {
        removeStorage(activeTab);
        if (typeof window.resetBudgetFilters === 'function') {
            window.resetBudgetFilters(activeTab);
        }
        var defaults = defaultsFor(activeTab);
        applySettings(activeTab, defaults);
        placeBudgetEditorToolbar(activeTab);
        fillForm(defaults);
        updateLivePreviewStatus('تمت إعادة إعدادات التبويب للمعاينة؛ اضغط «تطبيق وحفظ» للاحتفاظ بها.');
    }

    function updateLivePreviewStatus(message) {
        var status = document.getElementById('budgetLivePreviewStatus');
        if (status && message) {
            status.textContent = message;
        }
    }

    function isLivePreviewControl(element) {
        if (!element || !element.id) {
            return false;
        }
        return [
            'budgetPrintOrientation',
            'budgetPrintMargin',
            'budgetTableDensity',
            'budgetShowHeader',
            'budgetShowLogo',
            'budgetShowBorder',
            'budgetShowSignatures',
            'budgetShowStudentAffairs',
            'budgetShowSchoolDirector',
            'budgetShowAdminDirector',
            'budgetShowKgDirector',
            'budgetShowPrimaryDirector',
            'budgetShowPrepSecDirector',
            'budgetPrintLanguage',
            'budgetSignatureMode',
            'budgetPrintSubtitle',
            'budgetShowNote',
            'budgetPrintNote'
        ].indexOf(element.id) !== -1;
    }

    function previewSettingsFromForm() {
        if (!livePreviewActive || !livePreviewTab) {
            return;
        }
        var settings = settingsFromForm();
        applySettings(livePreviewTab, settings);
        placeBudgetEditorToolbar(livePreviewTab);
        updateLivePreviewStatus('المعاينة الحية مفعّلة — التغييرات ظاهرة الآن ولم تُحفظ بعد.');
    }

    function syncPrintPageRule(paper) {
        if (!paper) {
            return;
        }
        var orientation = paper.dataset.printOrientation === 'landscape' ? 'landscape' : 'portrait';
        var style = document.getElementById('schoolBudgetActivePageRule');
        if (!style) {
            style = document.createElement('style');
            style.id = 'schoolBudgetActivePageRule';
            document.head.appendChild(style);
        }
        style.textContent = '@media print { @page { size: A4 ' + orientation + '; margin: 4mm; } }';
    }

    function preparePrint(exportMode) {
        activeTab = activeTabId();
        var settings = settingsFor(activeTab);
        applySettings(activeTab, settings);
        if (typeof window.applyBudgetFilterState === 'function') {
            window.applyBudgetFilterState(activeTab);
        }
        placeBudgetEditorToolbar(activeTab);
        var paper = getPaper(activeTab);
        if (exportMode === 'pdf' && paper) {
            var previousTitle = document.title;
            var titleElement = paper.querySelector('.budget-report-title');
            var exportTitle = titleElement && titleElement.textContent.trim()
                ? titleElement.textContent.trim()
                : 'تقرير أعداد الطلاب';
            document.title = exportTitle.replace(/[\\/:*?"<>|]+/g, '-') + '_' + new Date().toISOString().slice(0, 10);
            window.addEventListener('afterprint', function restoreDocumentTitle() {
                document.title = previousTitle;
            }, { once: true });
        }
        if (paper) {
            document.documentElement.dataset.budgetPrintOrientation = paper.dataset.printOrientation;
        }
        syncPrintPageRule(paper);
        if (typeof window.prepareSchoolBudgetPrintMode === 'function') {
            window.prepareSchoolBudgetPrintMode();
        }
        window.setTimeout(function () {
            window.print();
        }, 0);
    }

    function syncPrintOrientation() {
        activeTab = activeTabId();
        applySettings(activeTab, settingsFor(activeTab));
        placeBudgetEditorToolbar(activeTab);
        var paper = getPaper(activeTab);
        if (paper) {
            document.documentElement.dataset.budgetPrintOrientation = paper.dataset.printOrientation;
        }
        syncPrintPageRule(paper);
    }

    function init() {
        if (initialized) {
            return;
        }
        initialized = true;

        ['detailed', 'buffer', 'historical'].forEach(function (tabId) {
            applySettings(tabId, settingsFor(tabId));
        });
        placeBudgetEditorToolbar(activeTab);
        initializeBudgetEditorToolbar();

        document.querySelectorAll('[data-budget-print-settings]').forEach(function (button) {
            button.addEventListener('click', function () {
                openSettings(button.getAttribute('data-budget-print-settings'));
            });
        });
        var printButton = document.getElementById('budgetPrintBtn');
        if (printButton) {
            printButton.addEventListener('click', preparePrint);
        }
        var pdfButton = document.getElementById('budgetPdfBtn');
        if (pdfButton) {
            pdfButton.addEventListener('click', function () {
                preparePrint('pdf');
            });
        }
        var settingsModal = document.getElementById('budgetPrintSettingsModal');
        if (settingsModal) {
            ['input', 'change'].forEach(function (eventName) {
                settingsModal.addEventListener(eventName, function (event) {
                    if (isLivePreviewControl(event.target)) {
                        previewSettingsFromForm();
                    }
                });
            });
            settingsModal.addEventListener('hidden.bs.modal', function () {
                if (!livePreviewActive || !livePreviewTab) {
                    return;
                }
                // إغلاق النافذة دون حفظ يعيد آخر نسخة محفوظة مع إبقاء تعديلات الورقة المباشرة.
                var persistedSettings = settingsFor(livePreviewTab);
                applySettings(livePreviewTab, persistedSettings);
                placeBudgetEditorToolbar(livePreviewTab);
                livePreviewActive = false;
                livePreviewTab = null;
            });
        }
        document.getElementById('budgetApplySettings').addEventListener('click', function () {
            var settings = settingsFromForm();
            writeStorage(activeTab, settings);
            applySettings(activeTab, settings);
            placeBudgetEditorToolbar(activeTab);
            updateLivePreviewStatus('تم حفظ إعدادات التبويب بنجاح.');
            livePreviewActive = false;
            livePreviewTab = null;
            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('budgetPrintSettingsModal')).hide();
        });
        var resetSettingsButton = document.getElementById('budgetResetSettings');
        if (resetSettingsButton) {
            resetSettingsButton.addEventListener('click', resetSettings);
        }
        document.querySelectorAll('#budgetTabs [data-bs-toggle="tab"]').forEach(function (tabButton) {
            tabButton.addEventListener('shown.bs.tab', function () {
                activeTab = activeTabId();
                placeBudgetEditorToolbar(activeTab);
                savedRange = null;
                savedRangeTab = activeTab;
                syncBudgetEditorToolbar();
            });
        });
        window.addEventListener('beforeprint', syncPrintOrientation);
    }

    window.prepareBudgetPrint = preparePrint;
    window.openBudgetPrintSettings = openSettings;
    document.addEventListener('DOMContentLoaded', init);
}(window, document));
(function () {
    'use strict';
    const replaceExactText = (value) => String(value)
        .replaceAll('ميزانية المدرسة', 'إحصائيات أعداد الطلاب')
        .replaceAll('ميزانية الفصول التفصيلية', 'أعداد الطلاب حسب الفصول')
        .replaceAll('ميزانية الفصول', 'أعداد الطلاب حسب الفصول');
    const initSchoolBudgetPresentation = () => {
        return;
        document.title = replaceExactText(document.title);
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node;
        while ((node = walker.nextNode())) {
            if (!node.parentElement || node.parentElement.closest('script, style')) continue;
            nodes.push(node);
        }
        nodes.forEach((textNode) => {
            const value = replaceExactText(textNode.nodeValue);
            if (value !== textNode.nodeValue) textNode.nodeValue = value;
        });
        document.querySelectorAll('input, textarea').forEach((field) => {
            if (field.value) field.value = replaceExactText(field.value);
            if (field.placeholder) field.placeholder = replaceExactText(field.placeholder);
        });
        const selectors = [
            '.report-paper-sheet .budget-report-title',
            '.report-paper-sheet .report-title',
            '.report-paper-sheet [data-budget-title="true"]',
            '.report-paper-sheet [data-editable-field="title"]',
            '.report-paper-sheet [data-field="title"]',
            '.report-paper-sheet [data-role="report-title"]',
            '.report-paper-sheet h1[contenteditable="true"]',
            '.report-paper-sheet h2[contenteditable="true"]',
            '.report-paper-sheet h3[contenteditable="true"]'
        ].join(',');
        document.querySelectorAll(selectors).forEach((title) => {
            if (!title.isContentEditable || title.querySelector('u, ins')) return;
            if (!title.textContent.trim() || title.textContent.trim().length > 160) return;
            try {
                const range = document.createRange();
                range.selectNodeContents(title);
                const underline = document.createElement('u');
                range.surroundContents(underline);
                title.style.textDecoration = 'none';
            } catch (error) {
                // لا نعطل أدوات التحرير إذا كان العنوان يحتوي بنية HTML جزئية.
            }
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSchoolBudgetPresentation, { once: true });
    } else {
        initSchoolBudgetPresentation();
    }
})();
(function () {
    'use strict';

    const papers = () => Array.from(document.querySelectorAll('.report-paper-sheet'));

    function visiblePaper() {
        const all = papers();
        return all.find((paper) => {
            const style = window.getComputedStyle(paper);
            return style.display !== 'none' && style.visibility !== 'hidden' && paper.offsetParent !== null;
        })
            || all.find((paper) => paper.closest('.tab-pane.show.active, [role="tabpanel"].active, .budget-paper-tab.active'))
            || all[0]
            || null;
    }

    function enterPrintMode() {
        const active = visiblePaper();
        if (!active) return;
        papers().forEach((paper) => paper.classList.toggle('budget-print-active', paper === active));
        document.body.classList.add('school-budget-printing');
    }

    function leavePrintMode() {
        document.body.classList.remove('school-budget-printing');
        papers().forEach((paper) => paper.classList.remove('budget-print-active'));
    }

    window.addEventListener('beforeprint', enterPrintMode);
    window.addEventListener('afterprint', leavePrintMode);
    window.prepareSchoolBudgetPrintMode = enterPrintMode;

    if (window.matchMedia) {
        const media = window.matchMedia('print');
        const onChange = (event) => (event.matches ? enterPrintMode() : leavePrintMode());
        if (media.addEventListener) media.addEventListener('change', onChange);
        else if (media.addListener) media.addListener(onChange);
    }
})();
