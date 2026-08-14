<?php

declare(strict_types=1);

function systemUndoContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../admin/activity_logs.php');
$script = file_get_contents(__DIR__ . '/../assets/js/activity-logs-undo.js');
$query = file_get_contents(__DIR__ . '/../src/Modules/Operations/Audit/SystemActivityLogQuery.php');

systemUndoContractAssert(is_string($page) && is_string($script) && is_string($query), 'System activity undo files must be readable.');

$authPosition = strpos($page, "Utilities::validateSession('admin')");
$postPosition = strpos($page, "REQUEST_METHOD'] === 'POST'");
systemUndoContractAssert($authPosition !== false && $postPosition !== false && $authPosition < $postPosition, 'Authentication must run before POST handling.');
systemUndoContractAssert(strpos($page, 'requireCsrfPost();') !== false, 'Undo POST must use shared CSRF validation.');
systemUndoContractAssert(strpos($page, "\$activeRole === 'super_admin'") !== false, 'Global undo must be restricted to the active super-admin role.');
systemUndoContractAssert(strpos($page, 'findUndoableOperation($activityId, $undoId)') !== false, 'POST must validate the exact activity/undo pair.');
systemUndoContractAssert(strpos($page, 'findRedoableOperation($activityId, $undoId)') !== false, 'Redo POST must validate the exact completed activity/undo pair.');
systemUndoContractAssert(strpos($page, 'UndoManager::undo') !== false && strpos($page, 'UndoManager::redo') !== false, 'The page must delegate reversal and redo to shared UndoManager.');
systemUndoContractAssert(strpos($page, '$systemActivityLogQuery->load($filters, $activeLogTab') !== false, 'Unified log tabs must use the unscoped system read model.');
systemUndoContractAssert(strpos($page, 'StudentOperationLogQuery') === false, 'Unified log must not inherit the Student Affairs exclusions.');
systemUndoContractAssert(strpos($page, 'csrfField()') !== false, 'Undo modal must carry a CSRF token.');
systemUndoContractAssert(strpos($page, 'js-system-undo') !== false && strpos($page, 'disabled') !== false, 'Every row must expose an active or disabled undo control.');
systemUndoContractAssert(strpos($page, 'systemUndoModal') !== false, 'Undo must use a Bootstrap modal.');
systemUndoContractAssert(strpos($page, 'systemRedoModal') !== false && strpos($page, 'js-system-redo') !== false, 'Undone tab must provide redo through a Bootstrap modal.');
systemUndoContractAssert(strpos($page, 'العمليات المتراجع عنها') !== false && strpos($page, "['log_tab'] = 'undone'") !== false, 'System log must expose a dedicated undone-operations tab.');
systemUndoContractAssert(stripos($page . $script, 'confirm(') === false, 'Browser confirm is forbidden.');
systemUndoContractAssert(stripos($page . $script, 'Swal') === false, 'SweetAlert is forbidden.');
systemUndoContractAssert(strpos($script, 'bootstrap.Modal.getOrCreateInstance') !== false, 'External script must open the Bootstrap modal.');
systemUndoContractAssert(strpos($query, 'INNER JOIN undo_log ul ON ul.id = al.undo_log_id') !== false, 'Read model must require the activity-linked undo entry.');
systemUndoContractAssert(strpos($query, "ul.undo_status = 'pending'") !== false, 'Only pending undo entries may be selected.');
systemUndoContractAssert(strpos($query, "ul.undo_status = 'completed'") !== false, 'Only completed undo entries may be selected for redo.');
systemUndoContractAssert(strpos($query, "NOT IN ('undo', 'redo')") !== false, 'Undo and redo audit events must not become mutable operation anchors.');

fwrite(STDOUT, "system_activity_log_undo_page_contract_test: OK\n");
