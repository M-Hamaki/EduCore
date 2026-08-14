(function () {
    'use strict';

    const paperSelector = '.report-paper-sheet';
    const titleSelector = [
        '.budget-report-title',
        '.budget-document-title',
        '.budget-paper-title',
        '.report-title',
        '.report-heading',
        '[data-budget-title="true"]',
        '[data-editable-field="title"]',
        '[data-editor-field="title"]',
        '[data-field="title"]',
        '[data-role="report-title"]'
    ].join(',');
    const titleTextPattern = /(تقرير|بيان\s+تدرج|أعداد\s+الطلاب|القدرة\s+الاستيعابية)/;
    let savedRange = null;

    function renameStatisticsLabels() {
        return;
        const replaceText = (value) => String(value)
            .replaceAll('تقرير ميزانية الفصول وتوزيع أعداد الطلاب بالتفصيل', 'تقرير أعداد الطلاب وتوزيعهم على الفصول بالتفصيل')
            .replaceAll('ميزانية الفصول التفصيلية', 'إحصاء أعداد الطلاب حسب الفصول')
            .replaceAll('ميزانية المدرسة', 'إحصائيات أعداد الطلاب')
            .replaceAll('ميزانية الفصول', 'أعداد الطلاب حسب الفصول');

        document.title = replaceText(document.title);
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (!node.parentElement || node.parentElement.closest('script, style')) continue;
            const updated = replaceText(node.nodeValue);
            if (updated !== node.nodeValue) node.nodeValue = updated;
        }
        document.querySelectorAll('input, textarea').forEach((field) => {
            if (field.value) field.value = replaceText(field.value);
            if (field.placeholder) field.placeholder = replaceText(field.placeholder);
        });
    }

    function editableTitleCandidates() {
        const titles = new Set();
        document.querySelectorAll(paperSelector).forEach((paper) => {
            paper.querySelectorAll(titleSelector).forEach((element) => titles.add(element));
            paper.querySelectorAll('h1, h2, h3, h4').forEach((element) => {
                if (titleTextPattern.test(element.textContent.trim())) titles.add(element);
            });
            paper.querySelectorAll('[contenteditable="true"]').forEach((element) => {
                if (!element.closest('td, th') && titleTextPattern.test(element.textContent.trim())) titles.add(element);
            });
        });
        return Array.from(titles);
    }

    function underlineStorageKey(title) {
        const paper = title.closest(paperSelector);
        return 'studentStatistics:titleUnderline:' + (paper && paper.dataset.budgetPaper ? paper.dataset.budgetPaper : 'active');
    }

    function unwrapUnderlineElements(title) {
        title.querySelectorAll('u, ins').forEach((element) => {
            const parent = element.parentNode;
            while (element.firstChild) parent.insertBefore(element.firstChild, element);
            element.remove();
        });
    }

    function applyTitleUnderline(title, underlined, persist) {
        unwrapUnderlineElements(title);
        title.querySelectorAll('*').forEach((element) => {
            element.style.setProperty('text-decoration', 'none', 'important');
            element.style.setProperty('border-bottom', '0', 'important');
        });
        title.dataset.budgetTitleUnderlined = underlined ? 'true' : 'false';
        title.style.setProperty('text-decoration-line', underlined ? 'underline' : 'none', 'important');
        title.style.setProperty('text-decoration-style', 'solid', 'important');
        title.style.setProperty('text-decoration-thickness', underlined ? '1.5px' : 'auto', 'important');
        title.style.setProperty('text-underline-offset', underlined ? '3px' : 'auto', 'important');
        title.style.setProperty('border-bottom', '0', 'important');
        if (persist) localStorage.setItem(underlineStorageKey(title), underlined ? '1' : '0');
    }

    function initializeTitle(title) {
        if (!(title instanceof HTMLElement)) return;
        title.contentEditable = 'true';
        title.classList.add('budget-title-toolbar-ready');
        if (title.dataset.budgetUnderlineInitialized === 'true') {
            applyTitleUnderline(title, title.dataset.budgetTitleUnderlined === 'true', false);
            return;
        }
        title.dataset.budgetUnderlineInitialized = 'true';
        const saved = localStorage.getItem(underlineStorageKey(title));
        applyTitleUnderline(title, saved === null ? true : saved === '1', false);
    }

    function initializeTitles() {
        editableTitleCandidates().forEach(initializeTitle);
    }

    function rangeInsidePaper(range) {
        const container = range && range.commonAncestorContainer;
        const element = container && (container.nodeType === Node.ELEMENT_NODE ? container : container.parentElement);
        return Boolean(element && element.closest(paperSelector));
    }

    function rememberSelection() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;
        const range = selection.getRangeAt(0);
        if (!rangeInsidePaper(range)) return;
        savedRange = range.cloneRange();
        updateUnderlineButtons();
    }

    function restoreSelection() {
        if (!savedRange || !rangeInsidePaper(savedRange)) return false;
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedRange);
        return true;
    }

    function isUnderlineButton(element) {
        const button = element && element.closest('button, [role="button"]');
        if (!button) return null;
        const command = [
            button.dataset.command,
            button.dataset.cmd,
            button.dataset.editorCommand,
            button.dataset.formatCommand,
            button.dataset.action
        ].filter(Boolean).join(' ').toLowerCase();
        const label = [
            button.title,
            button.getAttribute('aria-label'),
            button.textContent
        ].filter(Boolean).join(' ').trim().toLowerCase();
        return command.includes('underline')
            || Boolean(button.querySelector('.fa-underline'))
            || /(^|\s)u($|\s)|underline|تسطير/.test(label)
            ? button
            : null;
    }

    function selectedTitle() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return null;
        const range = selection.getRangeAt(0);
        if (!rangeInsidePaper(range)) return null;
        const node = range.commonAncestorContainer;
        const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
        return element ? element.closest('.budget-title-toolbar-ready') : null;
    }

    function selectionIsUnderlined() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !rangeInsidePaper(selection.getRangeAt(0))) return false;
        const title = selectedTitle();
        if (title) return title.dataset.budgetTitleUnderlined === 'true';
        try {
            if (document.queryCommandState('underline')) return true;
        } catch (error) {
            // يعتمد الاحتياط أدناه على بنية DOM.
        }
        const node = selection.anchorNode;
        const element = node && (node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement);
        return Boolean(element && element.closest('u, ins, [style*="text-decoration: underline"]'));
    }

    function updateUnderlineButtons() {
        document.querySelectorAll('button, [role="button"]').forEach((element) => {
            const button = isUnderlineButton(element);
            if (!button) return;
            const active = selectionIsUnderlined();
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    document.addEventListener('selectionchange', rememberSelection);
    document.addEventListener('mouseup', (event) => {
        if (event.target.closest(paperSelector)) rememberSelection();
    });
    document.addEventListener('keyup', (event) => {
        if (event.target.closest(paperSelector)) rememberSelection();
    });

    document.addEventListener('mousedown', (event) => {
        if (isUnderlineButton(event.target)) event.preventDefault();
    }, true);

    document.addEventListener('click', (event) => {
        const button = isUnderlineButton(event.target);
        if (!button || !savedRange) return;
        if (!restoreSelection()) return;
        const title = selectedTitle();
        if (!title) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        applyTitleUnderline(title, title.dataset.budgetTitleUnderlined !== 'true', true);
        rememberSelection();
        updateUnderlineButtons();
    }, true);

    function start() {
        renameStatisticsLabels();
        initializeTitles();
        updateUnderlineButtons();
        window.setTimeout(() => {
            renameStatisticsLabels();
            initializeTitles();
            updateUnderlineButtons();
        }, 0);
        document.addEventListener('shown.bs.tab', () => {
            window.setTimeout(() => {
                renameStatisticsLabels();
                initializeTitles();
                updateUnderlineButtons();
            }, 0);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
