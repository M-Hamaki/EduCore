<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/user.php';
require_once dirname(__DIR__) . '/classes/ProfileAttachmentStorage.php';
require_once dirname(__DIR__) . '/src/Modules/Students/Presentation/StudentExportFieldCatalog.php';
require_once dirname(__DIR__) . '/src/Modules/Students/Presentation/StudentExportValueFormatter.php';
require_once dirname(__DIR__) . '/src/Modules/Students/Presentation/StudentListColumnCatalog.php';
require_once dirname(__DIR__) . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php';

use EduCore\Modules\Students\Presentation\StudentListDataTablePresenter;
use EduCore\Modules\Students\Presentation\StudentListColumnCatalog;

$student = [
    'id' => 17,
    'name' => 'طالب اختبار',
    'student_code' => 'S-17',
    'national_id' => '12345678901234',
    'class_name' => '1/أ',
    'birth_date' => '2012-01-02',
    'enrollment_status' => 'enrolled',
    'siblings_count' => 1,
    'siblings_info' => 'شقيق اختبار||1/ب',
    'profile_image_id' => 55,
    'stage_name' => 'المرحلة الابتدائية',
];

$presenter = new StudentListDataTablePresenter();
$full = $presenter->rows([$student], 0, 'students.php', '&student_scope=current', 'current');
$projected = $presenter->rows(
    [$student],
    0,
    'students.php',
    '&student_scope=current',
    'current',
    ['col-birth-date', 'col-siblings', 'col-stage-name']
);

$additionalColumnCount = count(StudentListColumnCatalog::additionalColumns());
$statusIndex = 65 + $additionalColumnCount;
$stageOffset = array_search('col-stage-name', StudentListColumnCatalog::additionalClasses(), true);

if (count($full[0]) !== count($projected[0])) {
    throw new RuntimeException('Projection must preserve the DataTables column count.');
}
if ($projected[0][5] === '' || strpos($projected[0][5], '2012-01-02') === false) {
    throw new RuntimeException('Requested optional column was not rendered.');
}
if ($stageOffset === false || $projected[0][65 + $stageOffset] === '' || strpos($projected[0][65 + $stageOffset], 'المرحلة الابتدائية') === false) {
    throw new RuntimeException('Requested additional table column was not rendered.');
}
if ($projected[0][3] === '' || strpos($projected[0][3], 'طالب اختبار') === false) {
    throw new RuntimeException('The required student name column must never be projected out.');
}
if ($projected[0][4] === '' || strpos($projected[0][4], '1/أ') === false) {
    throw new RuntimeException('The required class column must never be projected out.');
}
if ($projected[0][$statusIndex] === '' || strpos($projected[0][$statusIndex], 'مقيد') === false) {
    throw new RuntimeException('The required annual status column must never be projected out.');
}
if ($projected[0][7] !== '') {
    throw new RuntimeException('Unrequested optional column leaked into the response.');
}
if ($projected[0][$statusIndex + 1] === '' || strpos($projected[0][$statusIndex + 1], 'data-bs-toggle="popover"') === false) {
    throw new RuntimeException('Requested sibling column was not rendered.');
}
if ($projected[0][$statusIndex + 2] !== '') {
    throw new RuntimeException('Unrequested profile image column leaked into the response.');
}
if ($projected[0][$statusIndex + 3] === '') {
    throw new RuntimeException('Required actions column must never be projected out.');
}
if (strpos($projected[0][$statusIndex + 3], 'href="students.php?action=edit&id=17&amp;student_scope=current"') === false) {
    throw new RuntimeException('Student edit action must preserve the direct edit route and list scope.');
}

$specialist = $presenter->rows(
    [$student],
    0,
    'students.php',
    '&student_scope=current',
    'current',
    null,
    false
);
if (strpos($specialist[0][$statusIndex + 3], '?action=edit&id=17') === false) {
    throw new RuntimeException('Scoped specialist list must retain the student edit action.');
}
if (strpos($specialist[0][$statusIndex + 3], 'archive-student') !== false) {
    throw new RuntimeException('Scoped specialist list must not expose the archive action.');
}

echo "Student DataTables presenter projection test passed.\n";
