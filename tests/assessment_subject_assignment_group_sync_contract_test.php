<?php

declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../admin/assessment_subject_assignments.php');
$service = file_get_contents(__DIR__ . '/../classes/AssessmentSubjectAssignmentGroupService.php');

if ($page === false || $service === false) {
    fwrite(STDERR, "Unable to read grouped subject-assignment sources.\n");
    exit(1);
}

$failures = [];
$expect = static function (string $source, string $needle, string $message) use (&$failures): void {
    if (strpos($source, $needle) === false) {
        $failures[] = $message;
    }
};
$reject = static function (string $source, string $needle, string $message) use (&$failures): void {
    if (strpos($source, $needle) !== false) {
        $failures[] = $message;
    }
};

$expect($page, "if (\$action === 'sync_subject_assignment_group')", 'The page must route grouped edits to one sync service.');
$expect($page, 'new AssessmentSubjectAssignmentGroupService($db)', 'The page must delegate grouped writes to the application service.');
$expect($page, 'COALESCE(sga.term_id, 0)', 'Displayed groups must be separated by academic term.');
$expect($page, 'edit-assignment-group-btn', 'The list must expose a direct group edit action.');
$expect($page, 'openEditAssignmentGroup', 'The browser workflow must preload the complete group.');
$expect($page, 'toggle-assignment-group-btn', 'Every displayed group must expose one group status action.');
$expect($page, 'delete-assignment-group-btn', 'Every displayed group must expose one group delete action.');
$expect($page, 'name="all_grade_ids[]"', 'Whole-grade scopes must be submitted as a collection.');
$expect($page, 'name="class_ids[]"', 'Class scopes must be submitted as a collection.');
$reject($page, 'edit-assignment-btn', 'Per-record edit buttons must not remain.');
$reject($page, 'view-assignment-details-btn', 'A second per-record details workflow must not remain.');
$reject($page, 'toggle-assignment-btn', 'Per-record status actions must not remain in the table.');
$reject($page, 'delete-assignment-btn', 'Per-record delete actions must not remain in the table.');

$expect($service, '$this->db->beginTransaction()', 'Grouped writes must start one database transaction.');
$expect($service, '$this->db->commit()', 'Grouped writes must commit atomically.');
$expect($service, '$this->db->rollBack()', 'Grouped writes must roll back on any failure.');
$expect($service, "['batch_id' => \$batchId]", 'Grouped audit records must share one batch identifier.');
$expect($service, 'ActivityLog::setDb($db)', 'Business and audit writes must share the same database connection.');
$expect($service, 'assertRemovalsAndMovesAreSafe', 'Removing or moving scopes must validate operational dependencies.');
$expect($service, 'توجد مجموعة روابط أخرى للمادة والترم المختارين', 'Moving a group must reject implicit merges.');
$expect($service, 'لا يمكن اختيار الصف بالكامل وفصول محددة منه في الوقت نفسه', 'Overlapping whole-grade and class scopes must be rejected.');
$expect($service, "['preserve', 'active', 'inactive']", 'Mixed and uniform status modes must be supported.');
$expect($service, "['preserve', 'replace']", 'Mixed notes must be preservable until explicitly replaced.');
$expect($service, 'public function add(', 'Creating multiple whole-grade and class scopes must use the grouped service.');
$expect($service, 'public function setStatus(', 'Group status changes must use one atomic service operation.');
$expect($service, 'public function deleteGroup(', 'Group deletion must use one atomic dependency-checked service operation.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "assessment subject assignment grouped sync contract: OK\n");
