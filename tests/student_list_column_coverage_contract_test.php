<?php

declare(strict_types=1);

use EduCore\Modules\Students\Presentation\StudentExportFieldCatalog;
use EduCore\Modules\Students\Presentation\StudentListColumnCatalog;
use EduCore\Modules\Students\Presentation\StudentListDataTablePresenter;
use EduCore\Modules\Students\StudentProfileRequestMapper;

$root = dirname(__DIR__);
require_once $root . '/classes/user.php';
require_once $root . '/classes/ProfileAttachmentStorage.php';
require_once $root . '/src/Modules/Students/Presentation/StudentExportFieldCatalog.php';
require_once $root . '/src/Modules/Students/Presentation/StudentExportValueFormatter.php';
require_once $root . '/src/Modules/Students/Presentation/StudentListColumnCatalog.php';
require_once $root . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php';
require_once $root . '/src/Modules/Students/StudentProfileRequestMapper.php';

$view = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/StudentListDataTableQuery.php');
$repository = (string) file_get_contents($root . '/src/Modules/Students/StudentListReadRepository.php');
$profileFields = (new ReflectionClass(StudentProfileRequestMapper::class))
    ->getReflectionConstant('PROFILE_FIELDS')
    ->getValue();
$tableFields = StudentListColumnCatalog::tableFields();
$missingProfileFields = [];
foreach ($profileFields as $profileField) {
    $tableField = $profileField === 'grade_id' ? 'grade_name' : $profileField;
    if (!in_array($tableField, $tableFields, true)) {
        $missingProfileFields[] = $profileField;
    }
}

$latestCompositeFields = [
    'extra_phones', 'additional_data', 'educational_guardianship', 'stage_name', 'grade_name',
    'class_name', 'enrollment_status', 'academic_status', 'account_status', 'transfer_destination',
    'external_transfer_date', 'external_transfer_reason', 'external_transfer_notes',
    'father_relationship', 'father_birth_place', 'father_extra_phones', 'father_extra_data',
    'mother_relationship', 'mother_birth_place', 'mother_extra_phones', 'mother_extra_data',
    'other_guardians', 'siblings', 'kinships', 'academic_history', 'profile_image', 'attachments',
];
$missingCompositeFields = array_values(array_filter(
    $latestCompositeFields,
    static fn(string $field): bool => !in_array($field, $tableFields, true)
));

$additionalColumns = StudentListColumnCatalog::additionalColumns();
$additionalClasses = StudentListColumnCatalog::additionalClasses();
$fixture = [
    'id' => 91,
    'name' => 'طالب اختبار',
    'student_code' => 'S-91',
    'national_id' => '12345678901234',
    'class_name' => '1/أ',
    'enrollment_status' => 'enrolled',
    'academic_status' => 'new',
    'stage_name' => 'المرحلة الابتدائية',
    'grade_name' => 'الصف الأول',
    'first_name_ar' => 'طالب',
    'extra_data' => json_encode([['label' => 'بيان', 'value' => 'اختبار']], JSON_UNESCAPED_UNICODE),
];
$row = (new StudentListDataTablePresenter())->rows(
    [$fixture],
    0,
    'students.php',
    '&student_scope=current',
    'current',
    $additionalClasses
)[0];
$additionalStart = 65;
$renderedAdditionalColumns = true;
foreach ($additionalColumns as $offset => $column) {
    if (($row[$additionalStart + $offset] ?? '') === '') {
        $renderedAdditionalColumns = false;
        break;
    }
}

$checks = [
    'all_persisted_profile_fields_are_available_in_table_settings' => $missingProfileFields === [],
    'all_latest_composite_and_related_fields_are_available_in_table_settings' => $missingCompositeFields === [],
    'additional_columns_are_unique_and_renderable' => count($additionalClasses) === count(array_unique($additionalClasses))
        && $renderedAdditionalColumns,
    'view_renders_headers_and_settings_from_the_additional_catalog' =>
        substr_count($view, 'StudentListColumnCatalog::additionalColumns()') >= 3,
    'datatable_query_selects_only_requested_additional_fields' =>
        strpos($query, 'StudentListColumnCatalog::queryFieldsForClasses') !== false
        && strpos($query, 'StudentListColumnCatalog::additionalClasses()') !== false,
    'read_repository_hydrates_related_table_fields_in_batches' =>
        strpos($repository, 'hydrateRelatedFields') !== false
        && strpos($repository, "student_guardians") !== false
        && strpos($repository, "student_kinships") !== false
        && strpos($repository, "student_attachments") !== false
        && strpos($repository, "student_enrollments") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
if ($missingProfileFields !== []) {
    echo 'missing_profile_fields:' . implode(',', $missingProfileFields) . PHP_EOL;
}
if ($missingCompositeFields !== []) {
    echo 'missing_composite_fields:' . implode(',', $missingCompositeFields) . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
