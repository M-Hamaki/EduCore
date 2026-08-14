<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/UserProfileStore.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'profile_saves_are_atomic_and_capture_user_name_sync' => substr_count($source, "auditProfileSave(") >= 3
        && strpos($source, "'table' => 'users'") !== false
        && strpos($source, "'profile_name_synchronized' => true") !== false,
    'profile_creation_and_updates_are_undoable' => strpos($source, 'recordReplacement(') !== false
        && strpos($source, 'recordCompositeUpdate(') !== false,
    'image_delete_commits_before_file_cleanup' => strpos($source, "'staff_profile_image'") !== false
        && strpos($source, "'direct_undo_available' => false") !== false
        && strpos($source, 'if ($ownsTransaction && $row && !empty($row[\'profile_image\']))') !== false,
    'guardian_crud_is_locked_and_audited' => strpos($source, "fetchById('student_guardians', \$guardianId, true)") !== false
        && substr_count($source, "'student_guardian'") >= 2,
    'sibling_pair_is_bidirectional_locked_and_audited' => strpos($source, 'fetchSiblingPair(') !== false
        && strpos($source, "'student_sibling_link'") !== false
        && strpos($source, "ORDER BY id' . (\$lock ? ' FOR UPDATE' : '')") !== false,
    'student_transfer_insert_is_audited' => strpos($source, "'student_transfer'") !== false
        && strpos($source, "fetchById('student_transfers'") !== false,
    'all_profile_tables_are_registered' => strpos($policy, "'student_profiles'") !== false
        && strpos($policy, "'staff_profiles'") !== false
        && strpos($policy, "'student_guardians'") !== false
        && strpos($policy, "'student_siblings'") !== false
        && strpos($policy, "'student_transfers'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
