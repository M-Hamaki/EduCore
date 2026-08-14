<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$handler = (string) file_get_contents($root . '/classes/Ajax/Handlers/user_services.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'user_services_is_registered_for_undo' => strpos($policy, "'user_services'") !== false,
    'save_and_reset_are_atomic' => substr_count($handler, 'beginTransaction()') === 2
        && substr_count($handler, 'rollBack()') === 2
        && substr_count($handler, 'commit()') === 2,
    'rows_are_locked_by_composite_business_key' => substr_count($handler, 'user_id = ? AND role = ? FOR UPDATE') === 2,
    'create_update_and_reset_are_audited' => strpos($handler, "recordInsert('user_service'") !== false
        && strpos($handler, "recordUpdate('user_service'") !== false
        && strpos($handler, 'recordDelete(') !== false,
    'response_contract_is_preserved' => strpos($handler, "'user_services' => \$userServices") !== false
        && strpos($handler, "'override_stage' => \$overrideStage") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
