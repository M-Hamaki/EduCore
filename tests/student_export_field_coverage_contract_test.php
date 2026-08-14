<?php

declare(strict_types=1);

use EduCore\Modules\Students\Presentation\StudentExportFieldCatalog;
use EduCore\Modules\Students\Presentation\StudentExportValueFormatter;
use EduCore\Modules\Students\StudentProfileRequestMapper;

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Students/Presentation/StudentExportFieldCatalog.php';
require_once $root . '/src/Modules/Students/Presentation/StudentExportValueFormatter.php';
require_once $root . '/src/Modules/Students/StudentProfileRequestMapper.php';

$source = (string) file_get_contents($root . '/admin/export_students.php');
$labels = StudentExportFieldCatalog::labels();
$reflection = new ReflectionClass(StudentProfileRequestMapper::class);
$profileFields = $reflection->getReflectionConstant('PROFILE_FIELDS')->getValue();
$missingProfileFields = [];
foreach ($profileFields as $profileField) {
    $exportField = $profileField === 'grade_id' ? 'grade_name' : $profileField;
    if (!array_key_exists($exportField, $labels)) {
        $missingProfileFields[] = $profileField;
    }
}

$latestCompositeFields = [
    'extra_phones', 'additional_data', 'educational_guardianship',
    'stage_name', 'grade_name', 'class_name', 'enrollment_status', 'academic_status',
    'transfer_destination', 'external_transfer_date', 'external_transfer_reason', 'external_transfer_notes',
    'father_extra_phones', 'father_extra_data', 'mother_extra_phones', 'mother_extra_data',
    'other_guardians', 'siblings', 'kinships', 'academic_history',
    'profile_image', 'attachments',
];

$missingCompositeFields = array_values(array_filter(
    $latestCompositeFields,
    static fn(string $field): bool => !array_key_exists($field, $labels)
));

$fixture = [
    'name' => 'طالب اختبار',
    'first_name_ar' => 'طالب',
    'second_name_ar' => 'اختبار',
    'birth_date' => (new DateTimeImmutable('today'))->modify('-10 years -2 months -3 days')->format('Y-m-d'),
    'extra_phones' => json_encode([
        ['type' => 'mobile', 'number' => '01000000000', 'note' => 'واتساب'],
        ['type' => 'landline', 'number' => '0233333333', 'note' => 'المنزل'],
    ], JSON_UNESCAPED_UNICODE),
    'extra_data' => json_encode([
        ['label' => '__educational_guardianship', 'value' => 'mother'],
        ['label' => 'الهواية', 'value' => 'القراءة'],
    ], JSON_UNESCAPED_UNICODE),
    'enrollment_status' => 'enrolled',
    'academic_status' => 'promoted',
    'status' => 'active',
];
$guardians = [
    'father' => [
        'guardian_name' => 'الأب',
        'relationship' => 'father',
        'religion' => 'ديانة مخصصة',
        'extra_phones' => json_encode([['type' => 'mobile', 'number' => '01111111111', 'note' => 'عمل']], JSON_UNESCAPED_UNICODE),
        'extra_data' => json_encode([['label' => 'فرع الشركة', 'value' => 'القاهرة']], JSON_UNESCAPED_UNICODE),
    ],
    'mother' => [],
    'others' => [[
        'guardian_name' => 'الوصي',
        'relationship' => 'other',
        'relationship_other' => 'وصي مفوض',
        'phone_primary' => '01222222222',
    ]],
];

$allFieldsFormat = true;
foreach (array_keys($labels) as $field) {
    if (!is_string(StudentExportValueFormatter::format($field, $fixture, $guardians, date('Y') . '-10-01'))) {
        $allFieldsFormat = false;
        break;
    }
}

$checks = [
    'catalog_is_the_single_field_source' => strpos($source, 'StudentExportFieldCatalog::sections()') !== false
        && strpos($source, 'StudentExportFieldCatalog::labels()') !== false
        && substr_count($source, '$columnSections = [') === 0,
    'all_persisted_profile_fields_are_exportable' => $missingProfileFields === [],
    'latest_composite_and_related_fields_are_exportable' => $missingCompositeFields === [],
    'legacy_field_names_are_canonicalized_without_duplicates' =>
        StudentExportFieldCatalog::canonicalize(['passport', 'passport_number', 'status', 'transfer_reason'])
        === ['passport_number', 'enrollment_status', 'external_transfer_reason'],
    'every_catalog_field_has_a_formatter' => $allFieldsFormat,
    'current_age_is_recalculated_from_today' =>
        str_starts_with(StudentExportValueFormatter::format('age_current', $fixture), '10 سنة 2 شهر'),
    'composite_student_data_is_readable' =>
        str_contains(StudentExportValueFormatter::format('extra_phones', $fixture), 'واتساب')
        && StudentExportValueFormatter::format('additional_data', $fixture) === 'الهواية: القراءة'
        && StudentExportValueFormatter::format('educational_guardianship', $fixture) === 'الأم',
    'guardian_extensions_and_custom_values_are_readable' =>
        StudentExportValueFormatter::format('father_religion', $fixture, $guardians) === 'ديانة مخصصة'
        && str_contains(StudentExportValueFormatter::format('father_extra_phones', $fixture, $guardians), '01111111111')
        && str_contains(StudentExportValueFormatter::format('other_guardians', $fixture, $guardians), 'وصي مفوض'),
    'query_covers_current_annual_and_external_transfer_fields' =>
        strpos($source, 'se.academic_status') !== false
        && strpos($source, 'sp.extra_phones') !== false
        && strpos($source, 'setr.notes AS external_transfer_notes') !== false,
    'attachments_export_display_names_not_private_paths' =>
        strpos($source, "COALESCE(NULLIF(original_name, ''), label, 'مرفق') AS display_name") !== false
        && strpos($source, "\$attachmentMap[\$studentId]['items'][] = \$displayName") !== false,
    'profile_photo_is_rendered_and_embedded_not_exported_as_a_filename' =>
        ($labels['profile_image'] ?? null) === 'الصورة الشخصية'
        && ($labels['attachments'] ?? null) === 'أسماء المرفقات'
        && !array_key_exists('total_points', $labels)
        && strpos($source, 'ProfileAttachmentStorage::adminDownloadUrl') !== false
        && strpos($source, "'path' => \$s['profile_image_path']") !== false
        && strpos($source, "exportToExcel(\$data, 'تقرير_الطلاب', \$spreadsheetImages)") !== false,
    'no_current_year_fallback_has_no_dangling_enrollment_alias' =>
        strpos($source, '$_enrollmentStatusSql = $_useYear') !== false
        && strpos($source, '$_academicStatusSql = $_useYear') !== false,
    'print_button_builds_preview_before_printing' =>
        strpos($source, 'btn-print-soft') !== false
        && substr_count($source, "document.getElementById('exportFormatInput').value='pdf'") >= 2,
    'discontinued_status_is_exportable' =>
        strpos($source, "'discontinued', 'transferred_in'") !== false
        && strpos($source, "THEN 'discontinued'") !== false
        && strpos($source, "'discontinued' => 'منقطع ⏸️'") !== false,
    'student_dropdown_reuses_scoped_list' =>
        strpos($source, 'استخدم القائمة المقيّدة مسبقاً بصلاحيات المستخدم') !== false
        && strpos($source, '$_dropEnrollClause') === false,
    'enrollment_status_filters_student_dropdown_dynamically' =>
        strpos($source, 'data-enrollment-status=') !== false
        && strpos($source, 'data-transferred-in=') !== false
        && strpos($source, 'function filterStudentOptions(') !== false
        && strpos($source, "status === 'transferred_in' ? isTransferredIn : enrollmentStatus === status") !== false
        && strpos($source, 'filterStudentOptions(true);') !== false,
    'report_filter_form_opts_out_of_data_entry_drafts' =>
        strpos($source, 'id="exportForm" data-no-form-safety="true"') !== false,
    'student_filter_checkboxes_do_not_use_hidden_card_selector' =>
        strpos($source, 'form-check-input export-student-checkbox') !== false
        && strpos($source, '.export-student-checkbox:checked') !== false
        && strpos($source, "querySelectorAll('.export-student-checkbox')") !== false
        && strpos($source, 'form-check-input student-checkbox') === false,
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
