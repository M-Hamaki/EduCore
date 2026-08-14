(function () {
    'use strict';

    let savedDocumentRange = null;
    let historyEntries = [];
    let historyIndex = -1;
    let historyCommitTimer = null;
    let isRestoringHistory = false;
    const HISTORY_LIMIT = 60;
    const ZOOM_STORAGE_KEY = 'educore_statements_editor_zoom';

    function getDocumentPaper() {
        return document.getElementById('officialDocPaper');
    }

    function isRangeInsideDocument(range) {
        const paper = getDocumentPaper();
        return Boolean(paper && range && paper.contains(range.commonAncestorContainer));
    }

    function rememberDocumentSelection() {
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount) return false;

        const range = selection.getRangeAt(0);
        if (!isRangeInsideDocument(range)) return false;

        savedDocumentRange = range.cloneRange();
        return true;
    }

    function restoreDocumentSelection() {
        if (!savedDocumentRange || !isRangeInsideDocument(savedDocumentRange)) return false;

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedDocumentRange);
        return true;
    }

    function getSelectionAnchorElement() {
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || !isRangeInsideDocument(selection.getRangeAt(0))) {
            return null;
        }
        return selection.anchorNode && selection.anchorNode.nodeType === Node.ELEMENT_NODE
            ? selection.anchorNode
            : selection.anchorNode?.parentElement;
    }

    function colorToHex(color) {
        if (!color || color === 'transparent' || color === 'rgba(0, 0, 0, 0)') return null;
        if (/^#[0-9a-f]{6}$/i.test(color)) return color.toLowerCase();

        const channels = color.match(/\d+(?:\.\d+)?/g);
        if (!channels || channels.length < 3) return null;
        return '#' + channels.slice(0, 3).map(function (channel) {
            return Math.max(0, Math.min(255, Math.round(Number(channel)))).toString(16).padStart(2, '0');
        }).join('');
    }

    function setDynamicSelectValue(select, value, label) {
        if (!select || !value) return;
        select.querySelectorAll('option[data-current-value]').forEach(function (option) {
            option.remove();
        });
        let matchingOption = Array.from(select.options).find(function (option) {
            return option.value === value;
        });
        if (!matchingOption) {
            matchingOption = new Option(label, value);
            matchingOption.dataset.currentValue = 'true';
            select.add(matchingOption);
        }
        select.value = value;
    }

    function createDocumentSnapshot() {
        const paper = getDocumentPaper();
        if (!paper) return '';

        const clone = paper.cloneNode(true);
        clone.querySelectorAll('.entity-inline-input').forEach(function (input) {
            input.remove();
        });
        clone.querySelectorAll('.entity-name-display[data-edit-init]').forEach(function (display) {
            delete display.dataset.editInit;
            display.style.removeProperty('display');
        });
        return clone.innerHTML;
    }

    function updateHistoryButtons() {
        const undoButton = document.getElementById('docUndoButton');
        const redoButton = document.getElementById('docRedoButton');
        if (undoButton) undoButton.disabled = historyIndex <= 0;
        if (redoButton) redoButton.disabled = historyIndex < 0 || historyIndex >= historyEntries.length - 1;
    }

    function setEditorStatus(message, iconClass, colorClass) {
        const status = document.getElementById('docEditStatus');
        if (!status) return;
        status.innerHTML = '<i class="' + iconClass + ' ' + colorClass + ' me-1"></i>' + message;
    }

    function updateDocumentStatus() {
        const paper = getDocumentPaper();
        if (!paper) return;

        const visibleText = (paper.innerText || '').replace(/\s+/g, ' ').trim();
        const words = visibleText ? visibleText.split(' ').length : 0;
        const characters = visibleText.replace(/\s/g, '').length;
        const wordCount = document.getElementById('docWordCount');
        const characterCount = document.getElementById('docCharacterCount');
        if (wordCount) wordCount.textContent = String(words);
        if (characterCount) characterCount.textContent = String(characters);

        const selection = window.getSelection();
        const selectionCount = document.getElementById('docSelectionCount');
        if (selectionCount) {
            const selectedCharacters = selection && selection.rangeCount && isRangeInsideDocument(selection.getRangeAt(0))
                ? selection.toString().length
                : 0;
            selectionCount.textContent = selectedCharacters > 0 ? 'المحدد: ' + selectedCharacters : '';
        }

        const paperRect = paper.getBoundingClientRect();
        let contentBottom = 0;
        Array.from(paper.children).forEach(function (child) {
            const style = window.getComputedStyle(child);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            const rect = child.getBoundingClientRect();
            contentBottom = Math.max(contentBottom, rect.bottom - paperRect.top);
        });
        const pageUsage = Math.max(0, Math.round((contentBottom / Math.max(1, paperRect.height)) * 100));
        const hasOverflow = paper.scrollHeight > paper.clientHeight + 2 || pageUsage > 100;
        const usageDisplay = document.getElementById('docPageUsage');
        const overflowWarning = document.getElementById('docPageOverflowWarning');
        if (usageDisplay) usageDisplay.textContent = 'الصفحة: ' + pageUsage + '%';
        if (overflowWarning) overflowWarning.classList.toggle('d-none', !hasOverflow);
        paper.classList.toggle('has-page-overflow', hasOverflow);
    }

    function normalizeDocumentBlocks() {
        const paper = getDocumentPaper();
        if (!paper) return;
        paper.querySelectorAll('.doc-editor-blank-line').forEach(function (line) {
            if (line.textContent.replace(/\u200b/g, '').trim() !== '') {
                line.classList.remove('doc-editor-blank-line');
            }
        });
    }

    function commitDocumentHistory() {
        if (isRestoringHistory) return;

        const snapshot = createDocumentSnapshot();
        if (!snapshot || historyEntries[historyIndex] === snapshot) {
            updateHistoryButtons();
            return;
        }

        historyEntries = historyEntries.slice(0, historyIndex + 1);
        historyEntries.push(snapshot);
        if (historyEntries.length > HISTORY_LIMIT) historyEntries.shift();
        historyIndex = historyEntries.length - 1;
        updateHistoryButtons();
        if (historyIndex > 0) {
            setEditorStatus('تم التعديل', 'fas fa-pen', 'text-primary');
        }
        updateDocumentStatus();
    }

    function scheduleDocumentHistoryCommit() {
        window.clearTimeout(historyCommitTimer);
        historyCommitTimer = window.setTimeout(commitDocumentHistory, 350);
    }

    function flushDocumentHistoryCommit() {
        window.clearTimeout(historyCommitTimer);
        historyCommitTimer = null;
        commitDocumentHistory();
    }

    function restoreDocumentSnapshot(snapshot) {
        const paper = getDocumentPaper();
        if (!paper || !snapshot) return;

        isRestoringHistory = true;
        paper.innerHTML = snapshot;
        savedDocumentRange = null;
        paper.focus();
        window.setTimeout(function () {
            isRestoringHistory = false;
            updateFormattingToolbarState();
            updateDocumentStatus();
        }, 0);
    }

    function undoDocumentChange() {
        flushDocumentHistoryCommit();
        if (historyIndex <= 0) return;

        historyIndex -= 1;
        restoreDocumentSnapshot(historyEntries[historyIndex]);
        updateHistoryButtons();
        setEditorStatus('تم التراجع', 'fas fa-rotate-left', 'text-secondary');
    }

    function redoDocumentChange() {
        flushDocumentHistoryCommit();
        if (historyIndex >= historyEntries.length - 1) return;

        historyIndex += 1;
        restoreDocumentSnapshot(historyEntries[historyIndex]);
        updateHistoryButtons();
        setEditorStatus('تمت الإعادة', 'fas fa-rotate-right', 'text-secondary');
    }

    function updateFormattingToolbarState() {
        document.querySelectorAll('.doc-editor-toolbar [data-doc-command]').forEach(function (button) {
            let active = false;
            try {
                active = document.queryCommandState(button.dataset.docCommand);
            } catch (error) {
                active = false;
            }
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        const selection = window.getSelection();
        const anchorElement = getSelectionAnchorElement();
        if (!selection || !anchorElement) {
            return;
        }

        const size = Number.parseFloat(window.getComputedStyle(anchorElement).fontSize);
        if (Number.isFinite(size)) {
            const normalizedSize = Math.round(size * 10) / 10;
            setDynamicSelectValue(
                document.getElementById('docFontSizeSelect'),
                normalizedSize + 'px',
                String(normalizedSize)
            );
        }

        const computedStyle = window.getComputedStyle(anchorElement);
        const paper = getDocumentPaper();
        let currentAlignment = computedStyle.textAlign;
        if (currentAlignment === 'start') currentAlignment = paper?.dir === 'ltr' ? 'left' : 'right';
        if (currentAlignment === 'end') currentAlignment = paper?.dir === 'ltr' ? 'right' : 'left';
        const alignmentByCommand = {
            justifyRight: 'right',
            justifyCenter: 'center',
            justifyLeft: 'left',
            justifyFull: 'justify'
        };
        Object.entries(alignmentByCommand).forEach(function (entry) {
            const button = document.querySelector('[data-doc-command="' + entry[0] + '"]');
            if (!button) return;
            const active = currentAlignment === entry[1];
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        const textColor = colorToHex(document.queryCommandValue('foreColor'))
            || colorToHex(computedStyle.color);
        const highlightColor = colorToHex(document.queryCommandValue('hiliteColor'))
            || colorToHex(document.queryCommandValue('backColor'))
            || colorToHex(computedStyle.backgroundColor);
        const textColorInput = document.getElementById('docTextColorInput');
        const highlightColorInput = document.getElementById('docHighlightColorInput');
        if (textColorInput && textColor) textColorInput.value = textColor;
        if (highlightColorInput) highlightColorInput.value = highlightColor || '#ffffff';

        const lineHeight = Number.parseFloat(computedStyle.lineHeight);
        const fontSize = Number.parseFloat(computedStyle.fontSize);
        if (Number.isFinite(lineHeight) && Number.isFinite(fontSize) && fontSize > 0) {
            const ratio = Math.round((lineHeight / fontSize) * 100) / 100;
            setDynamicSelectValue(document.getElementById('docLineHeightSelect'), String(ratio), String(ratio));
        }
        updateDocumentStatus();
    }

    function executeDocumentCommand(command, value) {
        if (command === 'undo') {
            undoDocumentChange();
            return;
        }
        if (command === 'redo') {
            redoDocumentChange();
            return;
        }
        if (['justifyRight', 'justifyCenter', 'justifyLeft', 'justifyFull'].includes(command)) {
            applyDocumentAlignment(command);
            return;
        }
        if (command === 'indent' || command === 'outdent') {
            applyDocumentIndent(command === 'indent' ? 24 : -24);
            return;
        }
        if (!restoreDocumentSelection()) return;

        if (command === 'hiliteColor' && !document.queryCommandSupported('hiliteColor')) {
            command = 'backColor';
        }

        document.execCommand('styleWithCSS', false, true);
        document.execCommand(command, false, value ?? null);
        if (command === 'removeFormat') {
            getSelectedBlocks().forEach(function (block) {
                block.style.removeProperty('text-align');
                block.style.removeProperty('line-height');
                block.style.removeProperty('padding-inline-start');
            });
        }
        rememberDocumentSelection();
        updateFormattingToolbarState();
        scheduleDocumentHistoryCommit();
    }

    function applyDocumentAlignment(command) {
        if (!restoreDocumentSelection()) return;

        const alignmentByCommand = {
            justifyRight: 'right',
            justifyCenter: 'center',
            justifyLeft: 'left',
            justifyFull: 'justify'
        };
        const alignment = alignmentByCommand[command];
        if (!alignment) return;

        let blocks = getSelectedBlocks();
        if (blocks.length === 0) {
            const anchorElement = getSelectionAnchorElement();
            const fallbackBlock = anchorElement?.closest('.doc-text-line, p, li, div');
            if (fallbackBlock && getDocumentPaper()?.contains(fallbackBlock)) blocks = [fallbackBlock];
        }
        blocks.forEach(function (block) {
            block.style.textAlign = alignment;
        });
        rememberDocumentSelection();
        updateFormattingToolbarState();
        commitDocumentHistory();
    }

    function applyDocumentIndent(delta) {
        if (!restoreDocumentSelection()) return;

        let blocks = getSelectedBlocks();
        if (blocks.length === 0) {
            const anchorElement = getSelectionAnchorElement();
            const fallbackBlock = anchorElement?.closest('.doc-text-line, p, li, div');
            if (fallbackBlock && getDocumentPaper()?.contains(fallbackBlock)) blocks = [fallbackBlock];
        }
        blocks.forEach(function (block) {
            const currentIndent = Number.parseFloat(window.getComputedStyle(block).paddingInlineStart) || 0;
            const nextIndent = Math.max(0, Math.min(240, currentIndent + delta));
            if (nextIndent === 0) block.style.removeProperty('padding-inline-start');
            else block.style.paddingInlineStart = nextIndent + 'px';
        });
        rememberDocumentSelection();
        updateFormattingToolbarState();
        commitDocumentHistory();
    }

    function getSelectedBlocks() {
        const paper = getDocumentPaper();
        const selection = window.getSelection();
        if (!paper || !selection || !selection.rangeCount) return [];

        const range = selection.getRangeAt(0);
        const selector = '.doc-text-line, p, li, h1, h2, h3, h4, h5, h6, blockquote';
        if (selection.isCollapsed) {
            const anchorElement = getSelectionAnchorElement();
            const block = anchorElement?.closest(selector);
            return block && paper.contains(block) ? [block] : [];
        }

        return Array.from(paper.querySelectorAll(selector)).filter(function (block) {
            try {
                return range.intersectsNode(block);
            } catch (error) {
                return false;
            }
        });
    }

    function applyDocumentLineHeight(value) {
        if (!value || !restoreDocumentSelection()) return;

        let blocks = getSelectedBlocks();
        if (blocks.length === 0) {
            const body = document.querySelector('[data-editor-boundary="document-body"]');
            if (body) blocks = [body];
        }
        blocks.forEach(function (block) {
            block.style.lineHeight = value;
        });
        rememberDocumentSelection();
        updateFormattingToolbarState();
        commitDocumentHistory();
    }

    function applyDocumentFontSize(pixelSize) {
        if (!pixelSize || !restoreDocumentSelection()) return;

        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || selection.isCollapsed) return;

        const range = selection.getRangeAt(0);
        const span = document.createElement('span');
        span.style.fontSize = pixelSize;
        span.appendChild(range.extractContents());
        range.insertNode(span);
        range.selectNodeContents(span);
        selection.removeAllRanges();
        selection.addRange(range);
        rememberDocumentSelection();
        updateFormattingToolbarState();
        commitDocumentHistory();
    }

    function changeDocumentFontSize(step) {
        if (!restoreDocumentSelection()) return;

        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || selection.isCollapsed) return;

        const anchorElement = selection.anchorNode.nodeType === Node.TEXT_NODE
            ? selection.anchorNode.parentElement
            : selection.anchorNode;
        const currentSize = anchorElement
            ? Number.parseFloat(window.getComputedStyle(anchorElement).fontSize)
            : 16;
        const nextSize = Math.min(48, Math.max(10, (Number.isFinite(currentSize) ? currentSize : 16) + (step * 2)));
        applyDocumentFontSize(nextSize + 'px');
    }

    function handleEditorKeyboardShortcut(event) {
        if (event.target.closest('input, textarea, select')) return;

        if (event.key === 'Tab') {
            event.preventDefault();
            executeDocumentCommand(event.shiftKey ? 'outdent' : 'indent');
            return;
        }

        if (!(event.ctrlKey || event.metaKey)) return;
        const key = event.key.toLowerCase();
        const commandByKey = {
            b: 'bold',
            i: 'italic',
            u: 'underline',
            l: 'justifyLeft',
            e: 'justifyCenter',
            r: 'justifyRight',
            j: 'justifyFull'
        };

        if (commandByKey[key]) {
            event.preventDefault();
            executeDocumentCommand(commandByKey[key]);
        } else if (event.shiftKey && event.code === 'Digit7') {
            event.preventDefault();
            executeDocumentCommand('insertOrderedList');
        } else if (event.shiftKey && event.code === 'Digit8') {
            event.preventDefault();
            executeDocumentCommand('insertUnorderedList');
        } else if (event.code === 'Space') {
            event.preventDefault();
            executeDocumentCommand('removeFormat');
        }
    }

    function initializeZoomControl() {
        const zoomSelect = document.getElementById('docZoomSelect');
        const workspace = document.querySelector('.document-workspace-scaler');
        if (!zoomSelect || !workspace) return;

        let storedZoom = 'auto';
        try {
            storedZoom = localStorage.getItem(ZOOM_STORAGE_KEY) || 'auto';
        } catch (error) {
            storedZoom = 'auto';
        }
        if (!Array.from(zoomSelect.options).some(function (option) { return option.value === storedZoom; })) {
            storedZoom = 'auto';
        }

        function applyZoom(value) {
            workspace.style.zoom = value === 'auto' ? '' : value;
            window.setTimeout(updateDocumentStatus, 0);
        }

        zoomSelect.value = storedZoom;
        applyZoom(storedZoom);
        zoomSelect.addEventListener('change', function () {
            applyZoom(this.value);
            try {
                localStorage.setItem(ZOOM_STORAGE_KEY, this.value);
            } catch (error) {}
        });
    }

    function initializeDocumentEditor() {
        const paper = getDocumentPaper();
        if (!paper) return;

        const toolbar = document.querySelector('.doc-editor-toolbar');
        ['keyup', 'mouseup', 'input', 'focus'].forEach(function (eventName) {
            paper.addEventListener(eventName, function () {
                if (eventName === 'input') normalizeDocumentBlocks();
                rememberDocumentSelection();
                updateFormattingToolbarState();
                updateDocumentStatus();
                if (eventName === 'input') scheduleDocumentHistoryCommit();
            });
        });
        document.addEventListener('selectionchange', function () {
            if (rememberDocumentSelection()) updateFormattingToolbarState();
        });
        if (toolbar) {
            toolbar.addEventListener('pointerdown', function (event) {
                if (event.target.closest('button')) event.preventDefault();
                restoreDocumentSelection();
            });
        }
        paper.addEventListener('paste', function (event) {
            const plainText = event.clipboardData?.getData('text/plain');
            if (plainText === undefined) return;
            event.preventDefault();
            document.execCommand('insertText', false, plainText);
            scheduleDocumentHistoryCommit();
            updateDocumentStatus();
        });
        paper.addEventListener('keydown', function (event) {
            const key = event.key.toLowerCase();
            if ((event.ctrlKey || event.metaKey) && key === 'z') {
                event.preventDefault();
                if (event.shiftKey) redoDocumentChange();
                else undoDocumentChange();
            } else if ((event.ctrlKey || event.metaKey) && key === 'y') {
                event.preventDefault();
                redoDocumentChange();
            } else {
                handleEditorKeyboardShortcut(event);
            }
        });

        commitDocumentHistory();
        initializeZoomControl();
        updateDocumentStatus();
        document.addEventListener('input', function (event) {
            if (!paper.contains(event.target)) window.setTimeout(updateDocumentStatus, 0);
        });
        document.addEventListener('change', function () {
            window.setTimeout(updateDocumentStatus, 0);
        });
        if ('ResizeObserver' in window) {
            new ResizeObserver(updateDocumentStatus).observe(paper);
        }
        window.addEventListener('beforeprint', updateDocumentStatus);
    }

    window.execDocCmd = executeDocumentCommand;
    window.applyCustomFontSize = applyDocumentFontSize;
    window.changeFontSize = changeDocumentFontSize;
    window.applyDocLineHeight = applyDocumentLineHeight;
    function printDocument(exportMode) {
        if (!getDocumentPaper()) return;
        if (exportMode === 'pdf') {
            var previousTitle = document.title;
            var activeTab = document.querySelector('.nav-tabs .nav-link.active');
            var exportTitle = activeTab && activeTab.textContent.trim()
                ? activeTab.textContent.trim()
                : 'إفادة رسمية';
            document.title = exportTitle.replace(/[\\/:*?"<>|]+/g, '-') + '_' + new Date().toISOString().slice(0, 10);
            window.addEventListener('afterprint', function restoreDocumentTitle() {
                document.title = previousTitle;
            }, { once: true });
        }
        if (document.activeElement && typeof document.activeElement.blur === 'function') {
            document.activeElement.blur();
        }
        requestAnimationFrame(function () {
            window.print();
        });
    }

    window.printOfficialDocument = function () {
        printDocument('print');
    };

    window.exportOfficialDocumentPdf = function () {
        printDocument('pdf');
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeDocumentEditor);
    } else {
        initializeDocumentEditor();
    }
})();
