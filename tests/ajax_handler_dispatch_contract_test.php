<?php

$root = dirname(__DIR__);
$entrypoint = (string) file_get_contents($root . '/includes/ajax_handlers.php');
$handlerPaths = [
    'reports' => $root . '/classes/Ajax/Handlers/reports.php',
    'lookups' => $root . '/classes/Ajax/Handlers/lookups.php',
    'evaluations' => $root . '/src/Modules/BehaviorEvaluation/Ajax/evaluations.php',
    'user_services' => $root . '/classes/Ajax/Handlers/user_services.php',
];
$handlers = [];
foreach ($handlerPaths as $group => $path) {
    $handlers[$group] = (string) file_get_contents($path);
}
$combinedHandlers = implode("\n", $handlers);
$evaluationAdapter = (string) file_get_contents($root . '/classes/Ajax/Handlers/evaluations.php');

$actions = [
    'admin_reports_datatable',
    'specialist_reports_datatable',
    'teacher_evaluations_datatable',
    'delete_teacher_evaluation',
    'get_students_by_class',
    'get_teachers_by_class',
    'get_all_students',
    'get_all_teachers',
    'get_specialist_students',
    'get_evaluation_types',
    'get_classrooms',
    'get_student',
    'get_teacher',
    'get_student_class',
    'find_siblings',
    'search_students_for_sibling',
    'find_kinship',
    'link_kinship',
    'global_deep_search',
    'add_evaluation',
    'get_student_evaluations',
    'delete_evaluation',
    'get_teacher_evaluations_for_admin',
    'update_teacher_evaluation',
    'delete_all_evaluations',
    'delete_evaluation_from_report',
    'bulk_delete_evaluations_specialist',
    'bulk_delete_evaluations_admin',
    'adjust_total_points',
    'delete_all_student_evaluations',
    'export_student_evaluations',
    'get_user_services',
    'save_user_services',
    'reset_user_services',
];

$allActionsRoutedAndHandledOnce = true;
foreach ($actions as $action) {
    $allActionsRoutedAndHandledOnce = $allActionsRoutedAndHandledOnce
        && substr_count($entrypoint, "'" . $action . "'") >= 1
        && substr_count($combinedHandlers, "case '" . $action . "':") === 1;
}

$authPosition = strpos($entrypoint, "if (!isset(\$_SESSION['user_id']");
$csrfPosition = strpos($entrypoint, "hash_equals(\$_SESSION['csrf_token']");
$permissionPosition = strpos($entrypoint, '$permissions = [');
$requestContextPosition = strpos($entrypoint, '$requestPost = $_POST;');
$dispatchPosition = strpos($entrypoint, 'require $handlerFile;');

$checks = [
    'all_actions_routed_and_handled_once' => $allActionsRoutedAndHandledOnce,
    'security_gates_precede_dispatch' => $authPosition !== false
        && $csrfPosition > $authPosition
        && $permissionPosition > $csrfPosition
        && $dispatchPosition > $permissionPosition,
    'request_context_precedes_dispatch' => $requestContextPosition > $csrfPosition
        && $dispatchPosition > $requestContextPosition,
    'handlers_do_not_read_superglobals' => preg_match(
        '/\\$_(?:POST|GET|FILES|SESSION)/',
        $combinedHandlers
    ) === 0,
    'entrypoint_owns_response_helper' => strpos(
        $entrypoint,
        'function sendJsonResponse('
    ) !== false,
    'handlers_reuse_response_helper' => strpos(
        $combinedHandlers,
        'function sendJsonResponse('
    ) === false && strpos($combinedHandlers, 'sendJsonResponse(') !== false,
    'reports_group_is_focused' => strpos($handlers['reports'], "case 'admin_reports_datatable':") !== false
        && strpos($handlers['reports'], "case 'add_evaluation':") === false,
    'lookups_group_is_focused' => strpos($handlers['lookups'], "case 'get_student':") !== false
        && strpos($handlers['lookups'], "case 'save_user_services':") === false,
    'evaluations_group_is_focused' => strpos($handlers['evaluations'], "case 'add_evaluation':") !== false
        && strpos($handlers['evaluations'], "case 'get_user_services':") === false,
    'evaluation_adapter_targets_module' => strpos(
        $evaluationAdapter,
        "src/Modules/BehaviorEvaluation/Ajax/evaluations.php"
    ) !== false,
    'services_group_is_focused' => strpos($handlers['user_services'], "case 'get_user_services':") !== false
        && strpos($handlers['user_services'], "case 'add_evaluation':") === false,
    'invalid_action_contract_preserved' => strpos(
        $entrypoint,
        "'message' => 'Invalid action: ' . \$action"
    ) !== false,
    'no_handler_exceeds_large_file_limit' => max(array_map(
        static fn(string $source): int => substr_count($source, "\n") + 1,
        $handlers
    )) < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
