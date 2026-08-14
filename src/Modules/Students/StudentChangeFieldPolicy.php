<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use InvalidArgumentException;
use ProfileInputValidator;

final class StudentChangeFieldPolicy
{
    /**
     * Complete edit payload accepted from the shared admin student profile form.
     * Transport/security fields and attachment/sibling actions are intentionally
     * excluded; those resources have separate write workflows.
     */
    private const PROFILE_REQUEST_FIELDS = [
        'edit_user_id', 'record_version', 'student_scope',
        'student_code', 'ministry_code', 'grade_id', 'graduate_grade_id', 'class_id',
        'first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar',
        'first_name_en', 'second_name_en', 'third_name_en', 'fourth_name_en', 'family_name_en',
        'birth_date', 'birth_place', 'national_id', 'nationality', 'nationality_other',
        'passport_number', 'religion', 'religion_other', 'gender',
        'city_area', 'address_current', 'phone_mobile', 'phone_home', 'phone_emergency',
        'enrollment_date', 'previous_school', 'notes', 'enrollment_status', 'academic_status', 'status',
        'transfer_reason', 'transfer_destination', 'external_transfer_date',
        'external_transfer_reason', 'external_transfer_notes',
        'health_status', 'chronic_diseases', 'allergies', 'blood_type', 'disabilities',
        'medications', 'psychological_notes', 'previous_medical_reports',
        'insurance_number', 'insurance_start_date', 'insurance_end_date',
        'treatment_plan', 'emergency_medical_notes',
        'educational_guardianship', 'educational_guardianship_other',
        'student_mobile_numbers', 'student_mobile_notes',
        'student_landline_numbers', 'student_landline_notes',
        'additional_data_labels', 'additional_data_values', 'guardians',
        'student_extra_phones_present', 'student_extra_data_present',
        'student_guardians_present', 'student_external_transfer_present',
        'student_extra_phones_touched', 'student_extra_data_touched',
        'student_guardians_touched', 'student_external_transfer_touched',
    ];

    private const LABELS = [
        'student_code' => 'كود الطالب',
        'ministry_code' => 'الكود الوزاري',
        'grade_id' => 'الصف الدراسي',
        'class_id' => 'الفصل',
        'first_name_ar' => 'الاسم الأول بالعربية',
        'second_name_ar' => 'اسم الأب بالعربية',
        'third_name_ar' => 'اسم الجد بالعربية',
        'fourth_name_ar' => 'الاسم الرابع بالعربية',
        'family_name_ar' => 'اسم العائلة بالعربية',
        'first_name_en' => 'الاسم الأول بالإنجليزية',
        'second_name_en' => 'اسم الأب بالإنجليزية',
        'third_name_en' => 'اسم الجد بالإنجليزية',
        'fourth_name_en' => 'الاسم الرابع بالإنجليزية',
        'family_name_en' => 'اسم العائلة بالإنجليزية',
        'birth_date' => 'تاريخ الميلاد',
        'birth_place' => 'مكان الميلاد',
        'nationality' => 'الجنسية',
        'religion' => 'الديانة',
        'city_area' => 'المدينة / المنطقة',
        'address_current' => 'العنوان الحالي',
        'phone_mobile' => 'رقم الموبايل',
        'phone_home' => 'الهاتف الأرضي',
        'phone_emergency' => 'هاتف الطوارئ',
        'national_id' => 'الرقم القومي',
        'passport_number' => 'رقم جواز السفر',
        'gender' => 'النوع',
        'enrollment_date' => 'تاريخ القيد',
        'previous_school' => 'المدرسة السابقة',
        'enrollment_status' => 'حالة القيد',
        'academic_status' => 'الحالة الدراسية',
        'health_status' => 'الحالة الصحية',
        'chronic_diseases' => 'الأمراض المزمنة',
        'allergies' => 'الحساسية',
        'blood_type' => 'فصيلة الدم',
        'disabilities' => 'الإعاقات',
        'medications' => 'الأدوية',
        'psychological_notes' => 'ملاحظات نفسية وسلوكية',
        'previous_medical_reports' => 'تقارير طبية سابقة',
        'insurance_number' => 'رقم التأمين الصحي',
        'insurance_start_date' => 'بداية التأمين',
        'insurance_end_date' => 'نهاية التأمين',
        'treatment_plan' => 'الخطة العلاجية',
        'emergency_medical_notes' => 'ملاحظات طبية طارئة',
        'extra_phones' => 'أرقام الاتصال الإضافية',
        'extra_data' => 'البيانات الإضافية والوصاية التعليمية',
        'guardians' => 'بيانات أولياء الأمور',
        'external_transfer' => 'بيانات النقل الخارجي',
        'notes' => 'ملاحظات عامة',
    ];

    /** @return array<string,string> */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    public static function filter(array $input): array
    {
        $result = [];
        foreach (self::LABELS as $field => $_label) {
            if (array_key_exists($field, $input) && !is_array($input[$field])) {
                $result[$field] = trim((string)$input[$field]);
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function profileRequest(array $input): array
    {
        $result = [];
        foreach (self::PROFILE_REQUEST_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $result[$field] = self::sanitizeValue($input[$field], 0);
        }
        return $result;
    }

    /**
     * The shared profile form renders every composite section, but an untouched
     * section must not be interpreted as a request to replace or clear it.
     * Composite replacement is fail-closed: without an explicit touched marker
     * the current stored value must be preserved.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function omitUntouchedCompositeGroups(array $input): array
    {
        $groups = [
            'student_extra_phones_touched' => [
                'student_extra_phones_present', 'student_mobile_numbers', 'student_mobile_notes',
                'student_landline_numbers', 'student_landline_notes',
            ],
            'student_extra_data_touched' => [
                'student_extra_data_present', 'additional_data_labels', 'additional_data_values',
                'educational_guardianship', 'educational_guardianship_other',
            ],
            'student_guardians_touched' => ['student_guardians_present', 'guardians'],
            'student_external_transfer_touched' => [
                'student_external_transfer_present', 'transfer_destination', 'external_transfer_date',
                'external_transfer_reason', 'external_transfer_notes',
            ],
        ];

        foreach ($groups as $touchedField => $groupFields) {
            if (array_key_exists($touchedField, $input)
                && trim((string) $input[$touchedField]) === '1') {
                continue;
            }
            foreach ($groupFields as $field) {
                unset($input[$field]);
            }
        }

        return $input;
    }

    /** @param array<string,mixed> $display @param array<string,mixed> $request @return array<string,mixed> */
    public static function filterUntouchedCompositeDisplay(array $display, array $request): array
    {
        $touchFields = [
            'extra_phones' => 'student_extra_phones_touched',
            'extra_data' => 'student_extra_data_touched',
            'guardians' => 'student_guardians_touched',
            'external_transfer' => 'student_external_transfer_touched',
        ];
        foreach ($touchFields as $displayField => $touchedField) {
            if (trim((string) ($request[$touchedField] ?? '')) !== '1') {
                unset($display[$displayField]);
            }
        }
        return $display;
    }

    /** @param array<string,mixed> $profile @return array<string,string> */
    public static function snapshot(array $profile): array
    {
        $snapshot = [];
        foreach (self::LABELS as $field => $_label) {
            $snapshot[$field] = trim((string)($profile[$field] ?? ''));
        }
        return $snapshot;
    }

    /** @param array<string,string> $merged */
    public static function validate(array $merged): void
    {
        ProfileInputValidator::birthDate($merged['birth_date'] ?? '', 'الطالب');
        ProfileInputValidator::mobile($merged['phone_mobile'] ?? '', 'رقم موبايل الطالب الأساسي');
        ProfileInputValidator::mobile($merged['phone_emergency'] ?? '', 'رقم موبايل الطوارئ');
        ProfileInputValidator::landline($merged['phone_home'] ?? '', 'رقم الهاتف الأرضي للطالب');
        $name = StudentProfilePayload::fullName($merged);
        if ($name === '') {
            throw new InvalidArgumentException('اسم الطالب باللغة العربية إلزامي.');
        }
    }

    private static function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 4) {
            return '';
        }
        if (!is_array($value)) {
            return mb_substr(trim((string) $value), 0, 10000);
        }

        $result = [];
        foreach (array_slice($value, 0, 100, true) as $key => $item) {
            $safeKey = is_int($key) ? $key : mb_substr((string) $key, 0, 80);
            $result[$safeKey] = self::sanitizeValue($item, $depth + 1);
        }
        return $result;
    }
}
