<?php

declare(strict_types=1);

$pageSource = (string) file_get_contents(dirname(__DIR__) . '/admin/statements.php');
$styleSource = (string) file_get_contents(dirname(__DIR__) . '/assets/css/statements.css');
$source = $pageSource . "\n" . $styleSource;
$editorSource = (string) file_get_contents(dirname(__DIR__) . '/assets/js/statements-editor.js');

$checks = [
    'page_loads_shared_document_editor' => strpos($source, '../assets/js/statements-editor.js') !== false,
    'page_loads_extracted_document_styles' => strpos($pageSource, '../assets/css/statements.css?v=') !== false,
    'student_photo_uses_authorized_private_controller' => strpos(
        $source,
        "ProfileAttachmentStorage::adminDownloadUrl('student'"
    ) !== false
        && strpos($source, '../uploads/<?php echo htmlspecialchars($student[\'profile_image\'])') === false,
    'toolbar_preserves_document_selection' => strpos($editorSource, 'function rememberDocumentSelection()') !== false
        && strpos($editorSource, 'function restoreDocumentSelection()') !== false
        && strpos($editorSource, "toolbar.addEventListener('pointerdown'") !== false,
    'commands_restore_selection_before_formatting' => strpos(
        $editorSource,
        'function executeDocumentCommand(command, value)'
    ) !== false
        && strpos($editorSource, 'if (!restoreDocumentSelection()) return;') !== false,
    'font_size_wraps_dom_without_html_reinjection' => strpos($editorSource, 'span.appendChild(range.extractContents());') !== false
        && strpos($editorSource, "document.execCommand('insertHTML'") === false,
    'toolbar_exposes_live_pressed_state' => strpos($source, 'data-doc-command="bold"') !== false
        && strpos($editorSource, 'function updateFormattingToolbarState()') !== false
        && strpos($editorSource, "button.setAttribute('aria-pressed'") !== false,
    'font_size_selector_reads_current_selection' => strpos($editorSource, "getComputedStyle(anchorElement).fontSize") !== false
        && strpos($editorSource, "option[data-current-value]") !== false
        && strpos($editorSource, 'setDynamicSelectValue(') !== false,
    'document_uses_real_editable_blank_line' => strpos($source, 'doc-editor-blank-line') !== false
        && strpos($source, '$editorBlankLine') !== false
        && strpos($editorSource, 'function normalizeDocumentBlocks()') !== false
        && strpos($editorSource, 'isCaretAtDocumentBodyStart') === false
        && strpos($editorSource, 'is-leading-line-removed') === false,
    'editor_history_covers_custom_and_keyboard_actions' => strpos($editorSource, 'function commitDocumentHistory()') !== false
        && strpos($editorSource, 'function undoDocumentChange()') !== false
        && strpos($editorSource, 'function redoDocumentChange()') !== false
        && strpos($editorSource, "if (command === 'undo')") !== false
        && strpos($editorSource, "if (command === 'redo')") !== false
        && strpos($editorSource, "key === 'z'") !== false
        && strpos($editorSource, "key === 'y'") !== false,
    'professional_paragraph_tools_are_available' => strpos($source, 'data-doc-command="insertUnorderedList"') !== false
        && strpos($source, 'data-doc-command="insertOrderedList"') !== false
        && strpos($source, 'id="docLineHeightSelect"') !== false
        && strpos($editorSource, 'function applyDocumentLineHeight(value)') !== false
        && strpos($editorSource, "event.key === 'Tab'") !== false,
    'paragraph_alignment_is_explicit_and_selection_aware' => strpos($source, '.doc-text-line {') !== false
        && strpos($source, 'text-align: start;') !== false
        && strpos($editorSource, 'function applyDocumentAlignment(command)') !== false
        && strpos($editorSource, "block.style.textAlign = alignment") !== false
        && strpos($editorSource, "currentAlignment === 'start'") !== false,
    'official_title_uses_standard_text_formatting' => strpos($source, '<u><?php echo htmlspecialchars($documentTitleDisplay); ?></u>') !== false
        && strpos($source, 'data-doc-title') === false
        && strpos($source, 'doc-document-title fw-bold d-block w-100') !== false
        && strpos($editorSource, 'getSelectedDocumentTitle') === false,
    'arabic_underline_has_readable_spacing' => strpos($source, 'text-underline-position: under;') !== false
        && strpos($source, 'text-underline-offset: 0.28em;') !== false
        && strpos($source, 'text-decoration-thickness: max(1px, 0.06em);') !== false
        && strpos($source, '.official-doc-paper [style*="text-decoration"]') !== false,
    'selected_text_keeps_live_underline_visible' => strpos($source, '.official-doc-paper ::selection') !== false
        && strpos($source, 'background: rgba(37, 99, 235, 0.22);') !== false
        && strpos($source, 'color: inherit;') !== false
        && strpos($source, '.official-doc-paper u::selection') === false,
    'paragraph_indent_is_uniform_and_reversible' => strpos($editorSource, 'function applyDocumentIndent(delta)') !== false
        && strpos($editorSource, "block.style.paddingInlineStart = nextIndent + 'px'") !== false
        && strpos($editorSource, "command === 'indent' || command === 'outdent'") !== false,
    'word_style_keyboard_shortcuts_are_supported' => strpos($editorSource, "b: 'bold'") !== false
        && strpos($editorSource, "i: 'italic'") !== false
        && strpos($editorSource, "u: 'underline'") !== false
        && strpos($editorSource, "event.code === 'Digit7'") !== false
        && strpos($editorSource, "event.code === 'Digit8'") !== false,
    'editor_paste_stays_safe_and_clean' => strpos($source, 'spellcheck="true"') !== false
        && strpos($editorSource, "getData('text/plain')") !== false
        && strpos($editorSource, "document.execCommand('insertText'") !== false,
    'editor_status_and_a4_overflow_are_live' => strpos($source, 'id="docWordCount"') !== false
        && strpos($source, 'id="docCharacterCount"') !== false
        && strpos($source, 'id="docPageOverflowWarning"') !== false
        && strpos($editorSource, 'function updateDocumentStatus()') !== false
        && strpos($editorSource, "paper.classList.toggle('has-page-overflow'") !== false,
    'preview_zoom_is_persistent_and_print_safe' => strpos($source, 'id="docZoomSelect"') !== false
        && strpos($editorSource, 'function initializeZoomControl()') !== false
        && strpos($editorSource, 'ZOOM_STORAGE_KEY') !== false
        && strpos($source, 'zoom: 1 !important;') !== false,
    'paper_uses_exact_a4_screen_box' => strpos($source, 'width: 210mm;') !== false
        && strpos($source, 'height: 297mm;') !== false
        && strpos($source, '--document-padding-block: 12.7mm;') !== false
        && strpos($source, 'class="official-doc-paper shadow') !== false,
    'print_reuses_preview_padding' => strpos(
        $source,
        'padding: var(--document-padding-block) var(--document-padding-inline) !important;'
    ) !== false
        && strpos($source, 'padding: 20mm 18mm !important;') === false,
    'print_reuses_preview_border_offsets' => substr_count($source, 'top: 7mm') >= 2
        && substr_count($source, 'right: 7mm') >= 2,
    'print_resets_responsive_zoom' => strpos($source, '.document-workspace-scaler {') !== false
        && strpos($source, 'zoom: 1 !important;') !== false,
    'print_finishes_active_inline_edit' => strpos($editorSource, 'window.printOfficialDocument = function ()') !== false
        && strpos($editorSource, 'document.activeElement.blur()') !== false
        && strpos($source, 'onclick="printOfficialDocument()"') !== false,
    'pdf_export_is_available_for_every_statement_tab_and_reuses_a4_print' => strpos($source, 'onclick="exportOfficialDocumentPdf()"') !== false
        && strpos($source, 'btn-pdf-soft') !== false
        && strpos($source, 'تصدير PDF') !== false
        && strpos($editorSource, 'window.exportOfficialDocumentPdf = function ()') !== false
        && strpos($editorSource, "printDocument('pdf')") !== false
        && strpos($editorSource, 'window.print();') !== false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "Statements editor and print contract test passed.\n";
