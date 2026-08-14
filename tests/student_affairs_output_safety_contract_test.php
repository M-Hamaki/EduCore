<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$export = (string) file_get_contents($root . '/admin/export_students.php');
$studentFile = (string) file_get_contents($root . '/admin/student_file.php');
$cards = (string) file_get_contents($root . '/admin/student_id_cards.php');
$students = (string) file_get_contents($root . '/admin/students.php');
$siblings = (string) file_get_contents($root . '/admin/siblings.php');
$pending = (string) file_get_contents($root . '/admin/pending_operations.php');

$checks = [
    'export_fields_formats_and_ids_are_whitelisted' => strpos($export, 'StudentExportFieldCatalog::canonicalize') !== false
        && strpos($export, "['preview', 'excel', 'pdf']") !== false
        && strpos($export, '$normalizeExportIds') !== false,
    'spreadsheet_formula_injection_is_neutralized' => strpos($export, 'exportStudentSpreadsheetValue') !== false
        && strpos($export, "preg_match('/^\\s*[=+\\-@]/u'") !== false,
    'print_forms_reject_malformed_arrays' => substr_count($studentFile . $cards, "is_array(\$_POST['student_ids'] ?? null)") >= 2
        && strpos($studentFile, 'is_string($field) && in_array($field, $allowedFieldKeys, true)') !== false,
    'id_card_style_options_are_whitelisted' => strpos($cards, "['portrait', 'landscape']") !== false
        && strpos($cards, "['school', 'blue', 'green', 'purple', 'red']") !== false
        && strpos($cards, "['solid', 'double', 'rounded']") !== false,
    'database_failures_are_not_disclosed' => strpos($students, 'student_public_error_message') !== false
        && strpos($siblings, 'siblings_public_error_message') !== false
        && strpos($pending, 'pending_operations_public_error') !== false,
    'archived_students_are_hidden_from_relationship_workflows' => substr_count($siblings, 'deleted_at IS NULL') >= 4,
    'siblings_bootstrap_initialization_waits_for_shared_footer' => strpos($siblings, "if (typeof bootstrap === 'undefined')") !== false
        && strpos($siblings, 'tooltipTriggerList.forEach') !== false
        && strpos($siblings, 'tooltipTriggerList.map') === false,
    'attachment_validation_survives_redirect' => strpos($students, "\$_SESSION['error_message'] = \$e->getMessage();") !== false
        && strpos($students, '$error_message = $e->getMessage();') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
