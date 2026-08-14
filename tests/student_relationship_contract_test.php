<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentRelationshipService.php');
$lookups = (string) file_get_contents($root . '/classes/Ajax/Handlers/lookups.php');
$dispatcher = (string) file_get_contents($root . '/includes/ajax_handlers.php');
$checks = [
    'actions_retained' => strpos($page, "\$_POST['link_sibling']") !== false
        && strpos($page, "\$_POST['unlink_sibling']") !== false
        && strpos($page, "\$_POST['unlink_kinship']") !== false,
    'delegates_all_actions' => strpos($page, '$studentRelationshipService->link(') !== false
        && strpos($page, '$studentRelationshipService->unlinkSibling(') !== false
        && strpos($page, '$studentRelationshipService->unlinkKinship(') !== false,
    'pair_guard_retained' => strpos($service, "throw new InvalidArgumentException('لا يمكن ربط الطالب بنفسه.')") !== false
        && strpos($page, 'catch (StudentRelationshipGuardException $e)') !== false,
    'sibling_keys_retained' => strpos($service, "'half_brother'") !== false && strpos($service, "'step_sister'") !== false,
    'bidirectional_kinship_retained' => strpos($service, '$link->execute([$studentId, $relativeId, $kinshipTypeId]);') !== false
        && strpos($service, '$link->execute([$relativeId, $studentId, $kinshipTypeId]);') !== false,
    'all_relationship_mutations_are_atomic' => substr_count($service, 'beginTransaction()') >= 3
        && substr_count($service, '->rollBack()') >= 3,
    'relationship_rows_are_locked_and_batch_audited' => strpos($service, "\$suffix = \$lock ? ' FOR UPDATE' : ''") !== false
        && substr_count($service, ', true);') >= 3
        && strpos($service, 'UndoManager::newBatchId()') !== false
        && strpos($service, 'recordInsert(') !== false
        && strpos($service, 'recordDelete(') !== false,
    'legacy_lookup_delegates_without_direct_writes' => strpos($lookups, '$service->link($studentId, $relativeId, $kinshipName)') !== false
        && strpos($lookups, 'INSERT INTO kinship_types') === false
        && strpos($lookups, 'INSERT IGNORE INTO student_kinships') === false,
    'kinship_link_is_post_only_and_csrf_protected' => strpos($dispatcher, "\$postOnlyActions = ['link_kinship']") !== false
        && strpos($lookups, "\$requestPost['student_id']") !== false,
];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
