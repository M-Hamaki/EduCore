<?php

$root = dirname(__DIR__);

$evaluation = (string) file_get_contents($root . '/src/Modules/BehaviorEvaluation/Evaluation.php');
$evaluationType = (string) file_get_contents($root . '/src/Modules/BehaviorEvaluation/EvaluationType.php');
$evaluationShim = (string) file_get_contents($root . '/classes/evaluation.php');
$evaluationTypeShim = (string) file_get_contents($root . '/classes/evaluation_type.php');
$handler = (string) file_get_contents($root . '/src/Modules/BehaviorEvaluation/Ajax/evaluations.php');
$handlerShim = (string) file_get_contents($root . '/classes/Ajax/Handlers/evaluations.php');
$dispatcher = (string) file_get_contents($root . '/includes/ajax_handlers.php');
$readme = (string) file_get_contents($root . '/src/Modules/BehaviorEvaluation/README.md');

$checks = [
    'models_are_namespaced' => strpos($evaluation, 'namespace EduCore\\Modules\\BehaviorEvaluation;') !== false
        && strpos($evaluationType, 'namespace EduCore\\Modules\\BehaviorEvaluation;') !== false,
    'legacy_model_names_are_aliased' => strpos($evaluationShim, "class_alias(\\EduCore\\Modules\\BehaviorEvaluation\\Evaluation::class, 'Evaluation')") !== false
        && strpos($evaluationTypeShim, "class_alias(\\EduCore\\Modules\\BehaviorEvaluation\\EvaluationType::class, 'EvaluationType')") !== false,
    'legacy_models_load_module_bootstrap' => substr_count($evaluationShim . $evaluationTypeShim, '/src/Modules/BehaviorEvaluation/bootstrap.php') === 2,
    'handler_implementation_is_module_owned' => strpos($handler, "case 'add_evaluation':") !== false
        && strpos($handler, "case 'delete_all_student_evaluations':") !== false,
    'handler_adapter_targets_module' => strpos($handlerShim, '/src/Modules/BehaviorEvaluation/Ajax/evaluations.php') !== false,
    'handler_uses_dispatch_context' => preg_match('/\\$_(?:POST|GET|FILES|SESSION)/', $handler) === 0,
    'dispatcher_contract_is_stable' => strpos($dispatcher, "'evaluations' => [") !== false
        && strpos($dispatcher, "classes/Ajax/Handlers/' . \$group . '.php") !== false
        && strpos($dispatcher, "'add_evaluation'") !== false
        && strpos($dispatcher, 'hash_equals($_SESSION[\'csrf_token\']') !== false,
    'rollback_is_documented' => stripos($readme, 'rollback') !== false
        && strpos($readme, 'No database rollback is required') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
