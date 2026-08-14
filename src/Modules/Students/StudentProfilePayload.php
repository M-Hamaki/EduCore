<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use ActivityLog;
use DateTime;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use UndoManager;
use User;

final class StudentProfilePayload
{
    private const RELATIONSHIPS = [
        'father', 'mother', 'grandfather', 'grandmother', 'uncle_paternal', 'aunt_paternal',
        'uncle_maternal', 'aunt_maternal', 'brother', 'sister', 'legal_guardian', 'other',
    ];

    public static function fullName(array $data): string
    {
        $parts = [];
        foreach (['first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'] as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return preg_replace('/\s+/u', ' ', implode(' ', $parts));
    }

    public static function activityDetails(array $before, array $after): ?array
    {
        $tracked = [
            'name', 'student_code', 'ministry_code', 'class', 'grade', 'first_name_ar', 'second_name_ar',
            'third_name_ar', 'fourth_name_ar', 'family_name_ar', 'first_name_en', 'second_name_en',
            'third_name_en', 'fourth_name_en', 'family_name_en', 'birth_date', 'birth_place',
            'national_id', 'nationality', 'passport_number', 'religion', 'gender', 'city_area',
            'address_current', 'phone_mobile', 'phone_home', 'phone_emergency', 'enrollment_date',
            'enrollment_status', 'academic_status', 'previous_school', 'transfer_destination', 'external_transfer_date',
            'external_transfer_reason', 'external_transfer_notes', 'blood_type', 'health_status',
            'chronic_diseases', 'allergies', 'disabilities', 'medications', 'insurance_number',
            'insurance_start_date', 'insurance_end_date', 'psychological_notes',
            'emergency_medical_notes', 'treatment_plan', 'previous_medical_reports', 'notes',
        ];
        $changes = [];
        foreach ($tracked as $field) {
            if ((string)($before[$field] ?? null) !== (string)($after[$field] ?? null)) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }
        $related = [];
        if ((int)($before['guardian_count'] ?? 0) !== (int)($after['guardian_count'] ?? 0)) {
            $related['guardian_count'] = [
                'from' => (int)($before['guardian_count'] ?? 0),
                'to' => (int)($after['guardian_count'] ?? 0),
            ];
        }
        if (!$changes && !$related) {
            return null;
        }
        $details = ['summary' => 'تم تعديل ' . count($changes) . ' حقل', 'changes' => $changes];
        if ($related) {
            $details['related_changes'] = $related;
        }
        return $details;
    }

    public static function fatherName(array $student): string
    {
        $parts = [];
        foreach (['second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'] as $key) {
            $value = trim((string)($student[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return preg_replace('/\s+/u', ' ', trim(implode(' ', $parts)));
    }

    public static function normalizeFixedParents(array $guardians, array $student): array
    {
        $father = null;
        $mother = null;
        $others = [];
        foreach ($guardians as $guardian) {
            if (!is_array($guardian)) {
                continue;
            }
            $relationship = trim((string)($guardian['relationship'] ?? ''));
            if ($father === null && $relationship === 'father') {
                $father = $guardian;
            } elseif ($mother === null && $relationship === 'mother') {
                $mother = $guardian;
            } else {
                $others[] = $guardian;
            }
        }
        $father = $father ?? [];
        $mother = $mother ?? [];
        $father['relationship'] = 'father';
        $father['is_primary'] = 1;
        $fatherName = self::fatherName($student);
        if ($fatherName !== '') {
            $father['guardian_name'] = $fatherName;
        }
        $mother['relationship'] = 'mother';
        $mother['is_primary'] = 0;
        foreach ($others as &$other) {
            $other['is_primary'] = 0;
        }
        unset($other);
        return array_values(array_merge([$father, $mother], $others));
    }

    public static function normalizeRelationships(array $guardians): array
    {
        foreach ($guardians as &$guardian) {
            $relationship = trim((string)($guardian['relationship'] ?? ''));
            $other = trim((string)($guardian['relationship_other'] ?? ''));
            if (($relationship === 'other' || $relationship === 'أخرى') && $other !== '') {
                $guardian['relationship'] = 'other';
                $guardian['relationship_other'] = $other;
                continue;
            }
            $guardian['relationship_other'] = '';
            if ($relationship !== '' && !in_array($relationship, self::RELATIONSHIPS, true)) {
                $guardian['relationship'] = 'other';
                $guardian['relationship_other'] = $relationship;
            }
        }
        unset($guardian);
        return $guardians;
    }

    public static function sanitizeEducationalGuardianship(?string $value): string
    {
        $value = trim((string)$value);
        return in_array($value, self::RELATIONSHIPS, true) ? $value : ($value !== '' ? $value : '');
    }

    public static function extractEducationalGuardianship(?string $json, array &$filtered = []): string
    {
        $items = self::decodeExtraDataForForm($json);
        $value = '';
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string)($item['label'] ?? ''));
            if ($label === '__educational_guardianship' || $label === 'الوصاية التعليمية') {
                $value = self::sanitizeEducationalGuardianship((string)($item['value'] ?? ''));
            } else {
                $filtered[] = ['label' => $label, 'value' => trim((string)($item['value'] ?? ''))];
            }
        }
        return $value;
    }

    /**
     * Normalizes current and legacy phone JSON into the structure consumed by
     * the shared profile form, preventing untouched legacy values from vanishing.
     *
     * @return array<int,array{type:string,number:string,note:string}>
     */
    public static function decodePhonesForForm(?string $json): array
    {
        $items = json_decode((string) $json, true);
        if (!is_array($items)) return [];
        $phones = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $number = trim((string) $item);
                if ($number === '') continue;
                $phones[] = [
                    'type' => preg_match('/^01\d{9}$/', $number) ? 'mobile' : 'landline',
                    'number' => $number,
                    'note' => '',
                ];
                continue;
            }
            $number = trim((string) ($item['number'] ?? $item['phone'] ?? ''));
            if ($number === '') continue;
            $type = trim((string) ($item['type'] ?? ''));
            if (!in_array($type, ['mobile', 'landline'], true)) {
                $type = preg_match('/^01\d{9}$/', $number) ? 'mobile' : 'landline';
            }
            $phones[] = [
                'type' => $type,
                'number' => $number,
                'note' => trim((string) ($item['note'] ?? '')),
            ];
        }
        return $phones;
    }

    /** @return array<int,array{label:string,value:string}> */
    public static function decodeExtraDataForForm(?string $json): array
    {
        $items = json_decode((string) $json, true);
        if (!is_array($items)) return [];
        $result = [];
        foreach ($items as $key => $item) {
            if (is_array($item) && array_key_exists('label', $item)) {
                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') continue;
                $result[] = ['label' => $label, 'value' => trim((string) ($item['value'] ?? ''))];
                continue;
            }
            if (!is_string($key)) continue;
            $value = is_array($item)
                ? (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                : trim((string) $item);
            $result[] = ['label' => self::legacyExtraDataLabel($key), 'value' => $value];
        }
        return $result;
    }

    public static function mergeEducationalGuardianship(?string $json, ?string $guardianship): ?string
    {
        $filtered = [];
        self::extractEducationalGuardianship($json, $filtered);
        $value = self::sanitizeEducationalGuardianship($guardianship);
        if ($value !== '') {
            $filtered[] = ['label' => '__educational_guardianship', 'value' => $value];
        }
        return $filtered ? json_encode($filtered, JSON_UNESCAPED_UNICODE) : null;
    }

    public static function studentExtraPhones(array $post): ?string
    {
        return self::phones($post, 'student_mobile_numbers', 'student_mobile_notes', 'student_landline_numbers', 'student_landline_notes');
    }

    public static function guardianExtraPhones(array $guardian): ?string
    {
        return self::phones($guardian, 'extra_mobile_numbers', 'extra_mobile_notes', 'extra_landline_numbers', 'extra_landline_notes');
    }

    public static function guardianExtraData(array $guardian): ?string
    {
        return self::labelValues($guardian['extra_data_labels'] ?? [], $guardian['extra_data_values'] ?? []);
    }

    public static function studentExtraData(array $post): ?string
    {
        return self::labelValues($post['additional_data_labels'] ?? [], $post['additional_data_values'] ?? []);
    }

    public static function splitBulkName(string $name): array
    {
        $parts = array_values(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        return [
            'first_name_ar' => $parts[0] ?? '', 'second_name_ar' => $parts[1] ?? '',
            'third_name_ar' => $parts[2] ?? '', 'fourth_name_ar' => $parts[3] ?? '',
            'family_name_ar' => count($parts) > 4 ? implode(' ', array_slice($parts, 4)) : '',
        ];
    }

    private static function phones(array $data, string $mobileKey, string $mobileNotesKey, string $landlineKey, string $landlineNotesKey): ?string
    {
        $phones = [];
        foreach ([[$mobileKey, $mobileNotesKey, 'mobile'], [$landlineKey, $landlineNotesKey, 'landline']] as [$numbers, $notes, $type]) {
            foreach (($data[$numbers] ?? []) as $index => $number) {
                $number = trim((string)$number);
                if ($number !== '') {
                    $phones[] = ['type' => $type, 'number' => $number, 'note' => trim((string)($data[$notes][$index] ?? ''))];
                }
            }
        }
        return $phones ? json_encode($phones, JSON_UNESCAPED_UNICODE) : null;
    }

    private static function labelValues(array $labels, array $values): ?string
    {
        $items = [];
        foreach ($labels as $index => $label) {
            $label = trim((string)$label);
            $value = trim((string)($values[$index] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $items[] = ['label' => $label !== '' ? $label : 'بيان إضافي', 'value' => $value];
        }
        return $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;
    }

    private static function legacyExtraDataLabel(string $key): string
    {
        return [
            'social_media_contact' => 'وسيلة التواصل الاجتماعي',
            'social_media_work_address' => 'عنوان العمل أو الحساب',
            'educational_guardianship' => '__educational_guardianship',
        ][$key] ?? trim(str_replace('_', ' ', $key));
    }
}
