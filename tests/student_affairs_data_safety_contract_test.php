<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$derivedLists = (string) file_get_contents($root . '/src/Modules/Students/DerivedStudentListDataTableQuery.php');
$export = (string) file_get_contents($root . '/admin/export_students.php');
$studentFile = (string) file_get_contents($root . '/admin/student_file.php');
$studentCards = (string) file_get_contents($root . '/admin/student_id_cards.php');
$statements = (string) file_get_contents($root . '/admin/statements.php');
$siblings = (string) file_get_contents($root . '/admin/siblings.php');
$relationships = (string) file_get_contents($root . '/src/Modules/Students/StudentRelationshipService.php');

$checks = [
    'new_students_fail_closed_without_year' => strpos($derivedLists, "private function newStudentsWhere") !== false
        && substr_count($derivedLists, "\$where[] = '1 = 0';") >= 2,
    'export_excludes_archived_students' => substr_count($export, 'u.deleted_at IS NULL') >= 3,
    'export_summary_uses_year_and_role_scope' => strpos($export, '$statsJoin') !== false
        && strpos($export, '$allowedClassIds') !== false
        && strpos($export, '$studentCountsStmt->execute($statsParams)') !== false,
    'document_post_queries_exclude_archived_students' => strpos(
        $studentFile,
        "WHERE u.id IN (\$placeholders) AND u.role = 'student' AND u.deleted_at IS NULL"
    ) !== false
        && strpos(
            $studentCards,
            "WHERE u.id IN (\$placeholders) AND u.role = 'student' AND u.deleted_at IS NULL"
        ) !== false
        && strpos($statements, "WHERE u.id = ? AND u.role = 'student' AND u.deleted_at IS NULL") !== false,
    'siblings_page_delegates_all_relationship_writes' => strpos($siblings, '$studentRelationshipService->link(') !== false
        && strpos($siblings, '$studentRelationshipService->unlinkSibling(') !== false
        && strpos($siblings, '$studentRelationshipService->linkKinshipByType(') !== false
        && strpos($siblings, '$studentRelationshipService->unlinkKinship(') !== false
        && strpos($siblings, 'DELETE FROM student_kinships') === false,
    'typed_kinship_link_is_atomic_and_audited' => strpos($relationships, 'public function linkKinshipByType(') !== false
        && strpos($relationships, "SELECT id, name FROM kinship_types WHERE id = ? AND status = 'active' FOR UPDATE") !== false
        && strpos($relationships, "'student_kinships'") !== false
        && strpos($relationships, 'UndoManager::newBatchId()') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
