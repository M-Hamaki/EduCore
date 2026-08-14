<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use ActivityLog;
use ClassRoom;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use StaffEmploymentLifecycleService;
use UndoManager;
use User;

final class StaffProfilePayload
{
    public static function composeNameParts($parts): string
    {
        if (!is_array($parts)) {
            return trim((string)$parts);
        }
        return trim(implode(' ', array_values(array_filter(array_map(static function ($part): string {
            return trim((string)$part);
        }, $parts), static function (string $part): bool {
            return $part !== '';
        }))));
    }

    public static function splitNameParts(?string $fullName, int $maxParts = 5): array
    {
        $fullName = trim((string)$fullName);
        if ($fullName === '') {
            return array_fill(0, $maxParts, '');
        }
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn($part): bool => $part !== ''));
        if (count($parts) >= 2 && $parts[0] === 'عبد') {
            $parts[0] .= ' ' . $parts[1];
            array_splice($parts, 1, 1);
        }
        if (count($parts) > $maxParts) {
            $overflow = array_slice($parts, $maxParts - 1);
            $parts = array_slice($parts, 0, $maxParts - 1);
            $parts[] = implode(' ', $overflow);
        }
        return array_pad($parts, $maxParts, '');
    }

    public static function normalizeForm(array $post): array
    {
        $post['full_name_ar'] = (($post['name_parts_ar_touched'] ?? '0') === '1')
            ? self::composeNameParts($post['full_name_ar_parts'] ?? ($post['full_name_ar'] ?? ''))
            : trim((string)($post['full_name_ar'] ?? ''));
        $post['full_name_en'] = (($post['name_parts_en_touched'] ?? '0') === '1')
            ? self::composeNameParts($post['full_name_en_parts'] ?? ($post['full_name_en'] ?? ''))
            : trim((string)($post['full_name_en'] ?? ''));
        if (($post['marital_status'] ?? '') === 'other' && !empty($post['marital_status_other'])) {
            $post['marital_status'] = trim((string)$post['marital_status_other']);
        }
        if (!in_array(($post['marital_status'] ?? ''), ['married', 'widowed', 'divorced'], true)) {
            $post['number_of_children'] = '0';
        }
        return $post;
    }

    public static function extraPhones(array $post): ?string
    {
        $phones = [];
        foreach ([
            ['staff_mobile_numbers', 'staff_mobile_notes', 'mobile'],
            ['staff_landline_numbers', 'staff_landline_notes', 'landline'],
        ] as [$numbersKey, $notesKey, $type]) {
            foreach (($post[$numbersKey] ?? []) as $index => $number) {
                $number = trim((string)$number);
                if ($number !== '') {
                    $phones[] = ['type' => $type, 'number' => $number, 'note' => trim((string)($post[$notesKey][$index] ?? ''))];
                }
            }
        }
        return $phones ? json_encode($phones, JSON_UNESCAPED_UNICODE) : null;
    }

    public static function extraData(array $post): ?string
    {
        return self::labelValueRows($post['additional_data_labels'] ?? [], $post['additional_data_values'] ?? []);
    }

    public static function extraEmploymentData(array $post): ?string
    {
        return self::labelValueRows($post['additional_employment_data_labels'] ?? [], $post['additional_employment_data_values'] ?? []);
    }

    public static function activityDetails(array $before, array $after, bool $passwordChanged = false): ?array
    {
        $fields = [
            'name', 'employee_code', 'biometric_id', 'ministry_code', 'full_name_ar', 'full_name_en',
            'national_id', 'passport_number', 'birth_date', 'birth_place', 'gender', 'religion', 'nationality',
            'address_detail', 'city_area', 'phone_mobile', 'phone_home', 'phone_emergency',
            'emergency_contact_name', 'email_personal', 'marital_status', 'military_status', 'public_service_status',
            'number_of_children', 'blood_type', 'job_title', 'department', 'job_grade', 'contract_type',
            'contract_start', 'contract_end', 'admin_notes', 'qualification', 'qualification_year',
            'qualification_university', 'specialization', 'other_qualifications', 'training_courses',
            'years_of_experience', 'work_history', 'promotions', 'status_history', 'insurance_number',
            'insurance_start_date', 'insurance_end_date', 'current_work_status', 'current_status_reason',
            'current_status_effective_date', 'latest_hire_date', 'last_working_day', 'can_rehire',
            'health_status', 'chronic_diseases', 'allergies', 'disabilities', 'medications',
            'previous_medical_reports', 'emergency_medical_notes', 'treatment_plan', 'health_issues',
            'psychological_notes', 'notes',
        ];
        $changes = [];
        foreach ($fields as $field) {
            if ((string)($before[$field] ?? '') !== (string)($after[$field] ?? '')) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }
        return (!$changes && !$passwordChanged)
            ? null
            : ['summary' => 'تم تعديل ' . count($changes) . ' حقل', 'changes' => $changes];
    }

    private static function labelValueRows(array $labels, array $values): ?string
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
}
