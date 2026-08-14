<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Presentation;

use DateTimeImmutable;
use Exception;

final class StudentExportValueFormatter
{
    private const GENDER_LABELS = ['male' => 'ذكر', 'female' => 'أنثى'];
    private const RELIGION_LABELS = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
    private const RELATIONSHIP_LABELS = [
        'father' => 'الأب', 'mother' => 'الأم', 'grandfather' => 'الجد', 'grandmother' => 'الجدة',
        'uncle_paternal' => 'العم', 'aunt_paternal' => 'العمة', 'uncle_maternal' => 'الخال',
        'aunt_maternal' => 'الخالة', 'brother' => 'الأخ', 'sister' => 'الأخت',
        'legal_guardian' => 'وصي قانوني', 'other' => 'صلة قرابة أخرى',
    ];
    private const ENROLLMENT_LABELS = [
        'enrolled' => 'مقيد', 'transferred' => 'منقول خارج المدرسة',
        'discontinued' => 'منقطع', 'withdrawn' => 'منسحب', 'graduated' => 'خريج',
    ];
    private const ACADEMIC_LABELS = [
        'new' => 'مستجد', 'promoted' => 'ناجح ومنقول', 'retained' => 'راسب',
        'graduated' => 'خريج', 'pending' => 'قيد المراجعة',
    ];
    private const ACCOUNT_LABELS = [
        'active' => 'نشط', 'inactive' => 'معطل', 'blocked' => 'محظور',
        'graduated' => 'خريج', 'transferred' => 'منقول',
    ];

    public static function format(
        string $field,
        array $student,
        array $guardians = [],
        ?string $octoberReferenceDate = null
    ): string {
        $direct = [
            'student_code' => 'student_code',
            'first_name_ar' => 'first_name_ar',
            'second_name_ar' => 'second_name_ar',
            'third_name_ar' => 'third_name_ar',
            'fourth_name_ar' => 'fourth_name_ar',
            'family_name_ar' => 'family_name_ar',
            'first_name_en' => 'first_name_en',
            'second_name_en' => 'second_name_en',
            'third_name_en' => 'third_name_en',
            'fourth_name_en' => 'fourth_name_en',
            'family_name_en' => 'family_name_en',
            'class_name' => 'class_name',
            'grade_name' => 'grade_name',
            'stage_name' => 'stage_name',
            'enrollment_date' => 'enrollment_date',
            'previous_school' => 'previous_school',
            'national_id' => 'national_id',
            'birth_date' => 'birth_date',
            'birth_place' => 'birth_place',
            'blood_type' => 'blood_type',
            'passport_number' => 'passport_number',
            'nationality' => 'nationality',
            'ministry_code' => 'ministry_code',
            'notes' => 'notes',
            'city_area' => 'city_area',
            'address_current' => 'address_current',
            'phone_home' => 'phone_home',
            'phone_emergency' => 'phone_emergency',
            'phone_mobile' => 'phone_mobile',
            'transfer_destination' => 'transfer_destination',
            'external_transfer_date' => 'external_transfer_date',
            'external_transfer_reason' => 'external_transfer_reason',
            'external_transfer_notes' => 'external_transfer_notes',
            'health_status' => 'health_status',
            'chronic_diseases' => 'chronic_diseases',
            'allergies' => 'allergies',
            'disabilities' => 'disabilities',
            'medications' => 'medications',
            'insurance_number' => 'insurance_number',
            'insurance_start_date' => 'insurance_start_date',
            'insurance_end_date' => 'insurance_end_date',
            'treatment_plan' => 'treatment_plan',
            'previous_medical_reports' => 'previous_medical_reports',
            'emergency_medical_notes' => 'emergency_medical_notes',
            'psychological_notes' => 'psychological_notes',
            'siblings' => 'siblings',
            'kinships' => 'kinships',
            'academic_history' => 'academic_history',
            'attachments' => 'attachments',
        ];
        if (isset($direct[$field])) {
            return self::value($student[$direct[$field]] ?? null);
        }

        switch ($field) {
            case 'full_name_ar':
                return self::name($student, ['first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'], $student['name'] ?? null);
            case 'full_name_en':
                return self::name($student, ['first_name_en', 'second_name_en', 'third_name_en', 'fourth_name_en', 'family_name_en']);
            case 'gender':
                return self::label(self::GENDER_LABELS, $student['gender'] ?? null);
            case 'religion':
                return self::label(self::RELIGION_LABELS, $student['religion'] ?? null);
            case 'enrollment_status':
                return self::label(self::ENROLLMENT_LABELS, $student['enrollment_status'] ?? null);
            case 'academic_status':
                return self::label(self::ACADEMIC_LABELS, $student['academic_status'] ?? null);
            case 'account_status':
                return self::label(self::ACCOUNT_LABELS, $student['status'] ?? null);
            case 'age_current':
                return self::age($student['birth_date'] ?? null, new DateTimeImmutable('today'));
            case 'age_october':
                try {
                    $reference = new DateTimeImmutable($octoberReferenceDate ?: date('Y') . '-10-01');
                } catch (Exception $e) {
                    return '-';
                }
                return self::age($student['birth_date'] ?? null, $reference);
            case 'educational_guardianship':
                $value = self::jsonLabelValue(
                    $student['extra_data'] ?? null,
                    ['__educational_guardianship', 'الوصاية التعليمية']
                );
                return self::label(self::RELATIONSHIP_LABELS, $value);
            case 'extra_phones':
                return self::phones($student['extra_phones'] ?? null);
            case 'additional_data':
                return self::extraData(
                    $student['extra_data'] ?? null,
                    ['__educational_guardianship', 'الوصاية التعليمية']
                );
            case 'other_guardians':
                $summaries = [];
                foreach (($guardians['others'] ?? []) as $guardian) {
                    if (is_array($guardian)) {
                        $summaries[] = self::guardianSummary($guardian);
                    }
                }
                return $summaries ? implode(' | ', $summaries) : '-';
            case 'profile_image':
                return !empty($student['profile_image_id']) ? 'صورة مرفقة' : '-';
        }

        if (preg_match('/^(father|mother)_(.+)$/', $field, $matches) === 1) {
            return self::guardianField(
                is_array($guardians[$matches[1]] ?? null) ? $guardians[$matches[1]] : [],
                $matches[2]
            );
        }

        return '-';
    }

    private static function guardianField(array $guardian, string $field): string
    {
        if ($guardian === []) {
            return '-';
        }
        $map = [
            'name' => 'guardian_name', 'birth_date' => 'birth_date', 'birth_place' => 'birth_place',
            'nationality' => 'nationality', 'national_id' => 'national_id', 'passport' => 'passport_number',
            'mobile' => 'phone_primary', 'landline' => 'phone_landline', 'email' => 'email',
            'address' => 'address', 'qualification' => 'qualification', 'job' => 'job_title',
            'employer' => 'employer', 'work_phone' => 'work_phone',
        ];
        if (isset($map[$field])) {
            return self::value($guardian[$map[$field]] ?? null);
        }
        if ($field === 'relationship') {
            $relationship = (string) ($guardian['relationship'] ?? '');
            if ($relationship === 'other' && trim((string) ($guardian['relationship_other'] ?? '')) !== '') {
                return trim((string) $guardian['relationship_other']);
            }
            return self::label(self::RELATIONSHIP_LABELS, $relationship);
        }
        if ($field === 'religion') {
            return self::label(self::RELIGION_LABELS, $guardian['religion'] ?? null);
        }
        if ($field === 'extra_phones') {
            return self::phones($guardian['extra_phones'] ?? null);
        }
        if ($field === 'extra_data') {
            return self::extraData($guardian['extra_data'] ?? null);
        }
        return '-';
    }

    private static function guardianSummary(array $guardian): string
    {
        $parts = [
            'الاسم: ' . self::value($guardian['guardian_name'] ?? null),
            'الصلة: ' . self::guardianField($guardian, 'relationship'),
        ];
        $details = [
            'تاريخ الميلاد' => $guardian['birth_date'] ?? null,
            'محل الميلاد' => $guardian['birth_place'] ?? null,
            'الديانة' => self::guardianField($guardian, 'religion'),
            'الجنسية' => $guardian['nationality'] ?? null,
            'الرقم القومي' => $guardian['national_id'] ?? null,
            'جواز السفر' => $guardian['passport_number'] ?? null,
            'الموبايل' => $guardian['phone_primary'] ?? null,
            'الأرضي' => $guardian['phone_landline'] ?? null,
            'البريد' => $guardian['email'] ?? null,
            'العنوان' => $guardian['address'] ?? null,
            'أرقام إضافية' => self::phones($guardian['extra_phones'] ?? null),
            'المؤهل' => $guardian['qualification'] ?? null,
            'الوظيفة' => $guardian['job_title'] ?? null,
            'جهة العمل' => $guardian['employer'] ?? null,
            'هاتف العمل' => $guardian['work_phone'] ?? null,
            'بيانات إضافية' => self::extraData($guardian['extra_data'] ?? null),
        ];
        foreach ($details as $label => $value) {
            $value = self::value($value);
            if ($value !== '-') {
                $parts[] = $label . ': ' . $value;
            }
        }
        return implode('؛ ', $parts);
    }

    private static function phones($json): string
    {
        $items = self::decodeList($json);
        $values = [];
        foreach ($items as $item) {
            $number = trim((string) ($item['number'] ?? $item['phone'] ?? ''));
            if ($number === '') {
                continue;
            }
            $type = ($item['type'] ?? '') === 'landline' ? 'أرضي' : 'موبايل';
            $note = trim((string) ($item['note'] ?? ''));
            $values[] = $type . ': ' . $number . ($note !== '' ? ' (' . $note . ')' : '');
        }
        return $values ? implode('، ', $values) : '-';
    }

    private static function extraData($json, array $excludedLabels = []): string
    {
        $values = [];
        foreach (self::decodeList($json) as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if (in_array($label, $excludedLabels, true)) {
                continue;
            }
            $value = trim((string) ($item['value'] ?? ''));
            if ($label !== '' || $value !== '') {
                $values[] = ($label !== '' ? $label : 'بيان إضافي') . ': ' . ($value !== '' ? $value : '-');
            }
        }
        return $values ? implode('، ', $values) : '-';
    }

    private static function jsonLabelValue($json, array $labels): string
    {
        foreach (self::decodeList($json) as $item) {
            if (in_array((string) ($item['label'] ?? ''), $labels, true)) {
                return trim((string) ($item['value'] ?? ''));
            }
        }
        return '';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function decodeList($json): array
    {
        if (is_array($json)) {
            return array_values(array_filter($json, 'is_array'));
        }
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private static function age($birthDate, DateTimeImmutable $reference): string
    {
        if (!is_string($birthDate) || trim($birthDate) === '') {
            return '-';
        }
        try {
            $birth = (new DateTimeImmutable($birthDate))->setTime(0, 0);
        } catch (Exception $e) {
            return '-';
        }
        $reference = $reference->setTime(0, 0);
        if ($birth > $reference) {
            return 'لم يولد بعد';
        }
        $diff = $birth->diff($reference);
        return $diff->y . ' سنة ' . $diff->m . ' شهر ' . $diff->d . ' يوم';
    }

    private static function name(array $student, array $fields, $fallback = null): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = trim((string) ($student[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return $parts ? implode(' ', $parts) : self::value($fallback);
    }

    private static function label(array $labels, $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '-' : ($labels[$value] ?? $value);
    }

    private static function value($value): string
    {
        if ($value === null) {
            return '-';
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : '-';
    }
}
