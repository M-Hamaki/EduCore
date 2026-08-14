<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Presentation;

final class StudentExportFieldCatalog
{
    /**
     * @return array<string,array<int,array<int,mixed>>>
     */
    public static function sections(): array
    {
        return [
            'البيانات الأساسية' => [
                ['__header__', 'الاسم باللغة العربية'],
                ['full_name_ar', 'chk_full_name_ar', 'الاسم الكامل باللغة العربية', true],
                ['first_name_ar', 'chk_first_name_ar', 'الاسم الأول بالعربية', false],
                ['second_name_ar', 'chk_second_name_ar', 'اسم الأب بالعربية', false],
                ['third_name_ar', 'chk_third_name_ar', 'اسم الجد بالعربية', false],
                ['fourth_name_ar', 'chk_fourth_name_ar', 'الاسم الرابع بالعربية', false],
                ['family_name_ar', 'chk_family_name_ar', 'اسم العائلة بالعربية', false],

                ['__header__', 'الاسم باللغة الإنجليزية'],
                ['full_name_en', 'chk_full_name_en', 'الاسم الكامل باللغة الإنجليزية', false],
                ['first_name_en', 'chk_first_name_en', 'الاسم الأول بالإنجليزية', false],
                ['second_name_en', 'chk_second_name_en', 'اسم الأب بالإنجليزية', false],
                ['third_name_en', 'chk_third_name_en', 'اسم الجد بالإنجليزية', false],
                ['fourth_name_en', 'chk_fourth_name_en', 'الاسم الرابع بالإنجليزية', false],
                ['family_name_en', 'chk_family_name_en', 'اسم العائلة بالإنجليزية', false],

                ['__header__', 'البيانات الشخصية'],
                ['religion', 'chk_religion', 'الديانة', false],
                ['gender', 'chk_gender', 'النوع', true],
                ['nationality', 'chk_nationality', 'الجنسية', false],
                ['national_id', 'chk_national_id', 'الرقم القومي للمصريين', false],
                ['passport_number', 'chk_passport_number', 'رقم جواز السفر', false],
                ['birth_date', 'chk_birth_date', 'تاريخ الميلاد', true],
                ['age_current', 'chk_age_current', 'العمر الحالي', false],
                ['age_october', 'chk_age_october', 'العمر في 1 أكتوبر للعام الدراسي الحالي', false],
                ['birth_place', 'chk_birth_place', 'محل الميلاد', false],
                ['educational_guardianship', 'chk_educational_guardianship', 'الوصاية التعليمية', false],
                ['notes', 'chk_notes', 'ملاحظات عامة', false],
                ['additional_data', 'chk_additional_data', 'البيانات الإضافية', false],

                ['__header__', 'القيد والمسار الدراسي الحالي'],
                ['student_code', 'chk_student_code', 'كود الطالب لدى المدرسة', true],
                ['ministry_code', 'chk_ministry_code', 'كود الطالب بوزارة التربية والتعليم', true],
                ['stage_name', 'chk_stage_name', 'المرحلة', false],
                ['grade_name', 'chk_grade_name', 'الصف', true],
                ['class_name', 'chk_class_name', 'الفصل', true],
                ['enrollment_status', 'chk_enrollment_status', 'حالة القيد', true],
                ['academic_status', 'chk_academic_status', 'الحالة الدراسية', false],
                ['account_status', 'chk_account_status', 'حالة الحساب', false],
                ['enrollment_date', 'chk_enrollment_date', 'تاريخ القيد بالمدرسة', false],
                ['previous_school', 'chk_previous_school', 'المدرسة القادم منها الطالب', false],

                ['__header__', 'النقل إلى خارج المدرسة'],
                ['transfer_destination', 'chk_transfer_destination', 'الجهة المنقول إليها', false],
                ['external_transfer_date', 'chk_external_transfer_date', 'تاريخ النقل الخارجي', false],
                ['external_transfer_reason', 'chk_external_transfer_reason', 'سبب النقل الخارجي', false],
                ['external_transfer_notes', 'chk_external_transfer_notes', 'ملاحظات النقل الخارجي', false],

                ['__header__', 'العناوين وبيانات التواصل'],
                ['city_area', 'chk_city_area', 'المدينة / المنطقة', false],
                ['address_current', 'chk_address_current', 'العنوان التفصيلي', false],
                ['phone_emergency', 'chk_phone_emergency', 'رقم الطوارئ', true],
                ['phone_mobile', 'chk_phone_mobile', 'رقم موبايل الطالب الأساسي', false],
                ['phone_home', 'chk_phone_home', 'رقم الهاتف الأرضي الأساسي', false],
                ['extra_phones', 'chk_extra_phones', 'أرقام الطالب الإضافية وملاحظاتها', false],
            ],
            'بيانات الأب' => self::guardianSection('father', 'الأب'),
            'بيانات الأم' => self::guardianSection('mother', 'الأم'),
            'أولياء الأمور الآخرون' => [
                ['other_guardians', 'chk_other_guardians', 'جميع بيانات أولياء الأمور الآخرين', false],
            ],
            'البيانات الصحية والنفسية' => [
                ['__header__', 'الحالة الصحية'],
                ['blood_type', 'chk_blood_type', 'فصيلة الدم', false],
                ['insurance_number', 'chk_insurance_number', 'رقم التأمين الطبي', false],
                ['insurance_start_date', 'chk_insurance_start_date', 'تاريخ بداية التأمين', false],
                ['insurance_end_date', 'chk_insurance_end_date', 'تاريخ نهاية التأمين', false],
                ['health_status', 'chk_health_status', 'الحالة الصحية العامة', false],
                ['chronic_diseases', 'chk_chronic_diseases', 'الأمراض المزمنة', false],
                ['allergies', 'chk_allergies', 'الحساسية', false],
                ['disabilities', 'chk_disabilities', 'الإعاقات', false],
                ['medications', 'chk_medications', 'العلاج / الأدوية', false],
                ['treatment_plan', 'chk_treatment_plan', 'الخطط العلاجية المتبعة', false],
                ['previous_medical_reports', 'chk_previous_medical_reports', 'التقارير الطبية السابقة', false],
                ['emergency_medical_notes', 'chk_emergency_medical_notes', 'الملاحظات الطبية الطارئة', false],
                ['__header__', 'الحالة النفسية والسلوكية'],
                ['psychological_notes', 'chk_psychological_notes', 'الحالة النفسية والسلوكية', false],
            ],
            'الأسرة وصلات القرابة' => [
                ['siblings', 'chk_siblings', 'الإخوة والأشقاء', false],
                ['kinships', 'chk_kinships', 'صلات القرابة الأخرى', false],
            ],
            'المسار الدراسي' => [
                ['academic_history', 'chk_academic_history', 'المسار الدراسي السنوي الكامل', false],
            ],
            'الصورة الشخصية والمرفقات' => [
                ['profile_image', 'chk_profile_image', 'الصورة الشخصية', false],
                ['attachments', 'chk_attachments', 'أسماء المرفقات', false],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::sections() as $columns) {
            foreach ($columns as $column) {
                if (($column[0] ?? '') !== '__header__') {
                    $labels[(string) $column[0]] = (string) $column[2];
                }
            }
        }
        return $labels;
    }

    /**
     * @return list<string>
     */
    public static function defaultFields(): array
    {
        $fields = [];
        foreach (self::sections() as $columns) {
            foreach ($columns as $column) {
                if (($column[0] ?? '') !== '__header__' && !empty($column[3])) {
                    $fields[] = (string) $column[0];
                }
            }
        }
        return $fields;
    }

    /**
     * @param mixed $fields
     * @return list<string>
     */
    public static function canonicalize($fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $aliases = [
            'passport' => 'passport_number',
            'age_display' => 'age_current',
            'status' => 'enrollment_status',
            'transfer_reason' => 'external_transfer_reason',
            'transfer_date' => 'external_transfer_date',
            'insurance_start' => 'insurance_start_date',
            'insurance_end' => 'insurance_end_date',
            'treatment' => 'treatment_plan',
            'medical_reports' => 'previous_medical_reports',
            'emergency_notes' => 'emergency_medical_notes',
        ];
        $known = self::labels();
        $selected = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = $aliases[$field] ?? $field;
            if (array_key_exists($field, $known)) {
                $selected[$field] = $field;
            }
        }
        return array_values($selected);
    }

    /**
     * @return list<string>
     */
    public static function guardianFields(): array
    {
        return array_values(array_filter(
            array_keys(self::labels()),
            static fn(string $field): bool => str_starts_with($field, 'father_')
                || str_starts_with($field, 'mother_')
                || $field === 'other_guardians'
        ));
    }

    /**
     * @return array<int,array<int,mixed>>
     */
    private static function guardianSection(string $prefix, string $label): array
    {
        return [
            ['__header__', "البيانات الشخصية لـ{$label}"],
            ["{$prefix}_name", "chk_{$prefix}_name", "الاسم الرباعي لـ{$label}", false],
            ["{$prefix}_relationship", "chk_{$prefix}_relationship", "صلة القرابة بالطالب لـ{$label}", false],
            ["{$prefix}_birth_date", "chk_{$prefix}_birth_date", "تاريخ الميلاد لـ{$label}", false],
            ["{$prefix}_birth_place", "chk_{$prefix}_birth_place", "محل الميلاد لـ{$label}", false],
            ["{$prefix}_religion", "chk_{$prefix}_religion", "الديانة لـ{$label}", false],
            ["{$prefix}_nationality", "chk_{$prefix}_nationality", "الجنسية لـ{$label}", false],
            ["{$prefix}_national_id", "chk_{$prefix}_national_id", "الرقم القومي لـ{$label}", false],
            ["{$prefix}_passport", "chk_{$prefix}_passport", "رقم جواز السفر لـ{$label}", false],
            ['__header__', "العناوين وبيانات التواصل لـ{$label}"],
            ["{$prefix}_mobile", "chk_{$prefix}_mobile", "رقم الموبايل الأساسي لـ{$label}", true],
            ["{$prefix}_landline", "chk_{$prefix}_landline", "رقم الهاتف الأرضي لـ{$label}", false],
            ["{$prefix}_email", "chk_{$prefix}_email", "البريد الإلكتروني لـ{$label}", false],
            ["{$prefix}_address", "chk_{$prefix}_address", "العنوان الحالي بالتفصيل لـ{$label}", false],
            ["{$prefix}_extra_phones", "chk_{$prefix}_extra_phones", "الأرقام الإضافية وملاحظاتها لـ{$label}", false],
            ['__header__', "المؤهل وبيانات العمل لـ{$label}"],
            ["{$prefix}_qualification", "chk_{$prefix}_qualification", "المؤهل الدراسي لـ{$label}", false],
            ["{$prefix}_job", "chk_{$prefix}_job", "الوظيفة / المسمى الوظيفي لـ{$label}", false],
            ["{$prefix}_employer", "chk_{$prefix}_employer", "جهة العمل / الشركة لـ{$label}", false],
            ["{$prefix}_work_phone", "chk_{$prefix}_work_phone", "هاتف العمل لـ{$label}", false],
            ["{$prefix}_extra_data", "chk_{$prefix}_extra_data", "البيانات الإضافية لـ{$label}", false],
        ];
    }
}
