<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/class_lists.php');
$scripts = (string) file_get_contents($root . '/classes/Presentation/ClassLists/page_scripts.php');
$css = (string) file_get_contents($root . '/assets/css/class-lists.css');
$buttonsCss = (string) file_get_contents($root . '/assets/css/buttons.css');
$exportSupport = (string) file_get_contents($root . '/classes/Presentation/ClassLists/ClassListExportSupport.php');
$studentQuery = (string) file_get_contents($root . '/classes/Presentation/ClassLists/ClassListStudentQuery.php');
$combined = $page . "\n" . $scripts;

$authPosition = strpos($page, "Utilities::validateSession('admin')");
$databasePosition = strpos($page, '$database = new Database()');
$postPosition = strpos($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'");

$checks = [
    'auth_precedes_database_and_post' => $authPosition !== false
        && $databasePosition > $authPosition
        && $postPosition > $databasePosition,
    'transfer_contract_is_preserved' => strpos($page, "\$_POST['ajax_change_class']") !== false
        && strpos($page, "hash_equals(\$_SESSION['csrf_token']") !== false
        && strpos($page, 'beginTransaction()') !== false
        && strpos($page, 'StudentProfileCommandService::fromDatabase') !== false
        && strpos($page, 'applyClassTransfer(') !== false
        && strpos($page, 'UndoManager::newBatchId()') !== false,
    'tabs_and_export_contracts_are_preserved' => strpos($combined, "'lists'") !== false
        && strpos($combined, "'log'") !== false
        && strpos($combined, "'custom'") !== false
        && strpos($page, 'export_excel') !== false
        && strpos($page, 'print_all') !== false,
    'entrypoint_loads_owned_assets' => strpos($page, '../assets/css/class-lists.css') !== false
        && strpos($page, "classes/Presentation/ClassLists/page_scripts.php") !== false,
    'script_fragment_keeps_dynamic_context' => strpos($scripts, "<?php echo \$activeTab; ?>") !== false
        && strpos($scripts, "<?php echo htmlspecialchars(\$_SESSION['csrf_token'] ?? ''); ?>") !== false,
    'settings_apply_uses_declared_sort_control' => strpos($scripts, 'if (listSortOrder)') !== false
        && strpos($scripts, 'listSortOrder.value') !== false
        && strpos($scripts, 'listSortOrderSelect') === false
        && strpos($scripts, "document.getElementById('filterForm')") !== false,
    'css_keeps_page_contracts' => strpos($css, '.custom-list-card') !== false
        && strpos($css, '.selection-bar') !== false
        && strpos($css, '#classSummaryTable') !== false,
    'student_lists_are_loaded_in_one_batch' => strpos($page, 'fetchByClassIds(') !== false
        && strpos($studentQuery, 'WHERE se.class_id IN (') !== false
        && strpos($page, 'classLists_fetchClassStudents(') === false,
    'exports_are_safe_for_headers_sheets_and_cells' => strpos($exportSupport, 'safeFileBase') !== false
        && strpos($exportSupport, 'safeWorksheetTitle') !== false
        && strpos($exportSupport, 'safeCsvValue') !== false
        && strpos($exportSupport, 'setCellValueExplicit') !== false
        && strpos($exportSupport, 'filename*=UTF-8') !== false
        && strpos($page, 'ClassListExportSupport::safeCsvValue') !== false,
    'ajax_transfer_refreshes_summary_cards' => strpos($page, 'ajax_get_summary') !== false
        && strpos($page, 'classListsTotalStudents') !== false
        && strpos($scripts, 'refreshClassListSummary') !== false
        && strpos($scripts, 'classListSummaryRefreshTimer') !== false,
    'dynamic_ajax_errors_are_escaped' => strpos($scripts, "escapeHtml(data.message || 'خطأ')") !== false,
    'button_styles_are_centralized' => strpos($combined, 'onmouseover=') === false
        && strpos($combined, 'onmouseout=') === false
        && preg_match('/class=["\'][^"\']*btn[^"\']*["\'][^>]*style=/i', $combined) !== 1
        && strpos($buttonsCss, '.bulk-transfer-btn') !== false
        && strpos($buttonsCss, '.custom-list-action-btn') !== false
        && strpos($css, '.bulk-transfer-btn') === false
        && strpos($css, '.custom-list-action-btn') === false,
    'script_helpers_are_declared_once' => substr_count($scripts, 'function escapeHtml(') === 1
        && substr_count($scripts, 'function toArabicNumerals(') === 1,
    'entrypoint_below_large_file_limit' => substr_count($page, "\n") + 1 < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
