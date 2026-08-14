<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Presentation;

/**
 * المصدر الموحد لأعمدة قائمة الطلاب وإعداداتها.
 *
 * يعتمد على نفس كتالوج التصدير حتى لا تنفصل إعدادات الجدول عن نموذج الطالب
 * أو عن الحقول المتاحة في Excel/PDF والطباعة.
 */
final class StudentListColumnCatalog
{
    private const DEFAULT_FIELDS = [
        'student_code',
        'national_id',
        'full_name_ar',
        'class_name',
        'enrollment_status',
        'academic_status',
    ];

    private const LEADING_FIELDS = [
        'student_code',
        'national_id',
        'full_name_ar',
        'class_name',
        'enrollment_status',
        'academic_status',
    ];

    /* الحقول التي كانت موجودة بالفعل في جدول الطلاب قبل توسيع الإعدادات. */
    private const LEGACY_FIELDS = [
        'full_name_ar', 'full_name_en', 'birth_date', 'age_current', 'age_october',
        'gender', 'religion', 'nationality', 'national_id', 'passport_number', 'birth_place',
        'educational_guardianship', 'notes', 'student_code', 'ministry_code', 'class_name',
        'enrollment_status', 'academic_status', 'enrollment_date', 'previous_school',
        'city_area', 'address_current', 'phone_emergency', 'phone_mobile', 'phone_home',
        'blood_type', 'insurance_number', 'insurance_start_date', 'insurance_end_date',
        'health_status', 'chronic_diseases', 'allergies', 'disabilities', 'medications',
        'treatment_plan', 'previous_medical_reports', 'emergency_medical_notes',
        'psychological_notes', 'siblings', 'profile_image',
        'father_name', 'father_mobile', 'father_landline', 'father_email', 'father_address',
        'father_national_id', 'father_qualification', 'father_job', 'father_employer',
        'father_work_phone', 'father_birth_date', 'father_religion', 'father_nationality',
        'father_passport', 'mother_name', 'mother_mobile', 'mother_landline', 'mother_email',
        'mother_address', 'mother_national_id', 'mother_qualification', 'mother_job',
        'mother_employer', 'mother_work_phone', 'mother_birth_date', 'mother_religion',
        'mother_nationality', 'mother_passport',
    ];

    private const CLASS_ALIASES = [
        'full_name_ar' => 'col-name',
        'full_name_en' => 'col-name-en',
        'passport_number' => 'col-passport',
        'age_current' => 'col-current-age',
        'age_october' => 'col-age-october',
        'educational_guardianship' => 'col-guardianship',
        'address_current' => 'col-address',
        'insurance_start_date' => 'col-insurance-start',
        'insurance_end_date' => 'col-insurance-end',
        'chronic_diseases' => 'col-chronic',
        'treatment_plan' => 'col-treatment',
        'previous_medical_reports' => 'col-medical-reports',
        'emergency_medical_notes' => 'col-emergency-notes',
        'psychological_notes' => 'col-psychological',
        'siblings' => 'col-siblings',
        'profile_image' => 'col-profile-image',
    ];

    private const QUERY_FIELD_OVERRIDES = [
        'full_name_ar' => ['name', 'first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'],
        'full_name_en' => ['first_name_en', 'second_name_en', 'third_name_en', 'fourth_name_en', 'family_name_en'],
        'age_current' => ['birth_date'],
        'age_october' => ['birth_date', 'age_years', 'age_months', 'age_days', 'age_reference_date'],
        'educational_guardianship' => ['extra_data'],
        'additional_data' => ['extra_data'],
        'account_status' => ['status'],
        'profile_image' => ['profile_image_id'],
        'siblings' => ['siblings_count', 'siblings_info'],
    ];

    private const DETAIL_FIELDS = [
        'notes', 'additional_data', 'previous_school', 'address_current', 'extra_phones',
        'external_transfer_reason', 'external_transfer_notes', 'other_guardians',
        'health_status', 'chronic_diseases', 'allergies', 'disabilities', 'medications',
        'treatment_plan', 'previous_medical_reports', 'emergency_medical_notes', 'psychological_notes',
        'kinships', 'academic_history', 'attachments',
        'father_address', 'father_extra_phones', 'father_extra_data',
        'mother_address', 'mother_extra_phones', 'mother_extra_data',
    ];

    private const LTR_FIELDS = [
        'student_code', 'ministry_code', 'national_id', 'passport_number',
        'phone_emergency', 'phone_mobile', 'phone_home', 'insurance_number',
        'father_national_id', 'father_passport', 'father_mobile', 'father_landline',
        'father_email', 'father_work_phone', 'mother_national_id', 'mother_passport',
        'mother_mobile', 'mother_landline', 'mother_email', 'mother_work_phone',
    ];

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    public static function sections(): array
    {
        $sections = [];
        foreach (StudentExportFieldCatalog::sections() as $sectionTitle => $items) {
            $sections[$sectionTitle] = [];
            foreach ($items as $item) {
                if (($item[0] ?? '') === '__header__') {
                    $sections[$sectionTitle][] = ['type' => 'header', 'label' => (string) ($item[1] ?? '')];
                    continue;
                }

                $field = (string) ($item[0] ?? '');
                if ($field === '') {
                    continue;
                }
                $definition = self::definition(
                    $field,
                    (string) ($item[2] ?? $field),
                    in_array($field, self::DEFAULT_FIELDS, true)
                );
                $definition['section'] = $sectionTitle;
                $sections[$sectionTitle][] = $definition;
            }
        }
        return $sections;
    }

    /**
     * ترتيب الأعمدة الفعلي في DataTables، مع إبقاء الأعمدة الأساسية أولاً.
     *
     * @return list<array<string,mixed>>
     */
    public static function columns(): array
    {
        $byField = [];
        foreach (self::sections() as $items) {
            foreach ($items as $item) {
                if (($item['type'] ?? '') === 'column') {
                    $byField[(string) $item['field']] = $item;
                }
            }
        }

        $ordered = [];
        foreach (self::LEADING_FIELDS as $field) {
            if (isset($byField[$field])) {
                $ordered[] = $byField[$field];
                unset($byField[$field]);
            }
        }
        array_push($ordered, ...array_values($byField));
        return $ordered;
    }

    /**
     * الشكل المتوافق مع قالب مودال إعدادات الجدول القديم.
     *
     * @return array<string,list<array<int,mixed>>>
     */
    public static function settingsSections(): array
    {
        $sections = [];
        foreach (self::sections() as $sectionTitle => $items) {
            foreach ($items as $item) {
                if (($item['type'] ?? '') === 'header') {
                    $sections[$sectionTitle][] = ['__header__', (string) $item['label']];
                    continue;
                }
                $sections[$sectionTitle][] = [
                    (string) $item['class'],
                    (string) $item['id'],
                    (string) $item['label'],
                    (bool) $item['default'],
                ];
            }
        }
        return $sections;
    }

    /** @return list<string> */
    public static function classes(): array
    {
        return array_values(array_map(
            static fn(array $column): string => (string) $column['class'],
            self::columns()
        ));
    }

    /**
     * الحقول التي لم تكن متاحة سابقاً في إعدادات جدول الطلاب.
     * يبقى ترتيب الجدول القديم كما هو، وتلحق هذه الحقول به فقط.
     *
     * @return list<array<string,mixed>>
     */
    public static function additionalColumns(): array
    {
        return array_values(array_filter(
            self::columns(),
            static fn(array $column): bool => !in_array((string) $column['field'], self::LEGACY_FIELDS, true)
        ));
    }

    /** @return list<string> */
    public static function additionalClasses(): array
    {
        return array_values(array_map(
            static fn(array $column): string => (string) $column['class'],
            self::additionalColumns()
        ));
    }

    /** @return list<string> */
    public static function legacyFields(): array
    {
        return self::LEGACY_FIELDS;
    }

    /** @return list<string> */
    public static function tableFields(): array
    {
        $additional = array_map(
            static fn(array $column): string => (string) $column['field'],
            self::additionalColumns()
        );
        return array_values(array_unique(array_merge(self::LEGACY_FIELDS, $additional)));
    }

    /** @return list<string> */
    public static function queryFieldsForClasses(array $classes): array
    {
        $requested = array_fill_keys(array_values(array_filter(array_map('strval', $classes))), true);
        $fields = [];
        foreach (self::columns() as $column) {
            if (!isset($requested[(string) $column['class']])) {
                continue;
            }
            array_push($fields, ...$column['query_fields']);
        }
        return array_values(array_unique($fields));
    }

    public static function fieldAtDataTableIndex(int $index): ?string
    {
        if ($index <= 0) {
            return null;
        }
        $columns = self::columns();
        return isset($columns[$index - 1]) ? (string) $columns[$index - 1]['field'] : null;
    }

    public static function isDetail(string $field): bool
    {
        return in_array($field, self::DETAIL_FIELDS, true);
    }

    public static function direction(string $field): ?string
    {
        return in_array($field, self::LTR_FIELDS, true) ? 'ltr' : null;
    }

    /** @return array<string,mixed> */
    private static function definition(string $field, string $label, bool $default): array
    {
        return [
            'type' => 'column',
            'field' => $field,
            'class' => self::CLASS_ALIASES[$field] ?? ('col-' . str_replace('_', '-', $field)),
            'id' => 'tbl_' . preg_replace('/[^a-z0-9_]+/i', '_', $field),
            'label' => $label,
            'default' => $default,
            'query_fields' => self::QUERY_FIELD_OVERRIDES[$field] ?? [$field],
        ];
    }
}
