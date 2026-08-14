<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentArchiveService.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/StudentArchiveQuery.php');
$list = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$archivePage = (string) file_get_contents($root . '/admin/student_archive.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260716_student_archiving.php');

$checks = [
    'hard_delete_removed_from_live_page' => strpos($page, "isset(\$_POST['delete_student'])") === false
        && strpos($list, 'id="deleteStudentModal"') === false,
    'archive_action_added' => strpos($page, "isset(\$_POST['archive_student'])") !== false
        && strpos($list, 'id="archiveStudentModal"') !== false,
    'id_input_retained' => strpos($page, "\$_POST['user_id']") !== false,
    'delegates_to_archive_service' => strpos($page, '$studentArchiveService->archive(') !== false,
    'archive_preserves_history' => strpos($service, "status = 'inactive', deleted_at = NOW()") !== false
        && strpos($service, 'DELETE FROM users') !== false,
    'role_guard_retained' => strpos($service, "role = 'student'") !== false,
    'transaction_retained' => strpos($service, '$this->db->beginTransaction();') !== false
        && strpos($service, '$this->db->commit();') !== false
        && strpos($service, '$this->db->rollBack();') !== false,
    'archive_and_restore_are_audited' => substr_count($service, '->recordUpdate(') >= 2,
    'permanent_delete_is_guarded' => strpos($service, 'PERMANENT_DELETE_DELAY_HOURS = 24') !== false
        && strpos($service, 'protectedRecordCounts(') !== false
        && strpos($service, "'irreversible_student_purge_after_archive'") !== false,
    'official_student_references_fail_closed' => strpos($service, "'student_change_requests'") !== false
        && strpos($service, "'student_promotion_decisions'") !== false
        && strpos($service, "'student_external_transfers'") !== false
        && strpos($service, 'unclassifiedStudentReferences()') !== false
        && strpos($service, 'مرجع بيانات طالب غير مصنف') !== false,
    'purge_dependents_are_explicit' => strpos($service, 'PURGE_DEPENDENT_REFERENCES') !== false
        && strpos($service, "'student_profiles' => ['user_id']") !== false
        && strpos($service, "'student_kinships' => ['student_id', 'relative_id']") !== false,
    'archive_page_hides_database_errors' => strpos($archivePage, '$e instanceof PDOException') !== false
        && strpos($archivePage, 'لم تُحفظ أي تغييرات جزئية') !== false,
    'archive_query_only_returns_archived' => strpos($query, "u.deleted_at IS NOT NULL") !== false,
    'archive_page_has_restore_and_delete_modals' => strpos($archivePage, 'id="restoreStudentModal"') !== false
        && strpos($archivePage, 'id="permanentDeleteStudentModal"') !== false
        && strpos($archivePage, 'verifyPassword(') !== false,
    'migration_owns_schema' => strpos($migration, "ADD COLUMN archived_by") !== false
        && strpos($migration, "ADD COLUMN archive_reason") !== false
        && strpos($migration, "ADD COLUMN status_before_archive") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
