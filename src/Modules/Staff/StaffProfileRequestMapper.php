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

final class StaffProfileRequestMapper
{
    private const PROFILE_FIELDS = [
        'employee_code', 'biometric_id', 'ministry_code', 'full_name_ar', 'full_name_en',
        'national_id', 'passport_number', 'birth_date', 'birth_place', 'gender', 'religion',
        'nationality', 'address_detail', 'city_area', 'phone_mobile', 'phone_home',
        'phone_emergency', 'emergency_contact_name', 'email_personal', 'marital_status',
        'military_status', 'public_service_status', 'number_of_children', 'blood_type',
        'job_title', 'job_grade', 'contract_type', 'contract_start', 'contract_end',
        'admin_notes', 'qualification', 'qualification_year', 'qualification_university',
        'specialization', 'other_qualifications', 'training_courses', 'years_of_experience',
        'work_history', 'promotions', 'status_history', 'insurance_number',
        'insurance_start_date', 'insurance_end_date', 'treatment_plan', 'health_issues',
        'health_status', 'chronic_diseases', 'allergies', 'disabilities', 'medications',
        'previous_medical_reports', 'emergency_medical_notes', 'psychological_notes', 'notes',
    ];

    private StaffEmploymentLifecycleService $employmentLifecycle;

    public function __construct(StaffEmploymentLifecycleService $employmentLifecycle)
    {
        $this->employmentLifecycle = $employmentLifecycle;
    }

    public function map(array $input, array $allowedDepartments): array
    {
        $this->validate($input);

        $name = !empty($input['full_name_ar'])
            ? trim((string) $input['full_name_ar'])
            : trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('اسم الموظف باللغة العربية إلزامي.');
        }

        $profile = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (isset($input[$field])) {
                $profile[$field] = trim((string) $input[$field]);
            }
        }
        if (array_key_exists('biometric_id', $input)) {
            $profile['biometric_id'] = StaffBiometricIdentityService::normalize(
                $input['biometric_id']
            );
        }

        if (isset($input['departments']) && is_array($input['departments'])) {
            $selected = array_values(array_filter(array_map('trim', $input['departments'])));
            $valid = array_values(array_intersect($selected, $allowedDepartments));
            $custom = [];
            if (in_array('أخرى', $selected, true) && !empty($input['departments_other'])) {
                $custom = array_values(array_filter(array_map(
                    'trim',
                    preg_split('/[،,]+/u', (string) $input['departments_other']) ?: []
                )));
            }
            $profile['department'] = implode(',', array_unique(array_merge($valid, $custom)));
        }

        foreach ([
            'religion' => ['other', 'religion_other'],
            'marital_status' => ['other', 'marital_status_other'],
            'job_title' => ['أخرى', 'job_title_other'],
            'nationality' => ['أخرى', 'nationality_other'],
            'contract_type' => ['other', 'contract_type_other'],
        ] as $field => [$sentinel, $otherField]) {
            if (($profile[$field] ?? '') === $sentinel && !empty($input[$otherField])) {
                $profile[$field] = trim((string) $input[$otherField]);
            }
        }

        $profile['extra_phones'] = StaffProfilePayload::extraPhones($input);
        $profile['extra_data'] = StaffProfilePayload::extraData($input);
        $profile['extra_employment_data'] = StaffProfilePayload::extraEmploymentData($input);
        $statusEvents = $this->employmentLifecycle->normalizeStatusHistory($input, $profile);
        $jobMovements = $this->employmentLifecycle->normalizeJobMovements($input);
        $this->employmentLifecycle->applyStatusSummary($profile, $statusEvents);
        $profile['status_history'] = json_encode($statusEvents, JSON_UNESCAPED_UNICODE);
        $profile['promotions'] = json_encode($jobMovements, JSON_UNESCAPED_UNICODE);
        $profile['admin_notes'] = trim(preg_replace(
            '/\[CONTACT_META_START\].*?\[CONTACT_META_END\]\n*/s',
            '',
            preg_replace(
                '/\[ADDITIONAL_DATA_START\].*?\[ADDITIONAL_DATA_END\]\n*/s',
                '',
                (string) ($profile['admin_notes'] ?? '')
            )
        ));

        return [
            'name' => $name,
            'profile' => $profile,
            'status_events' => $statusEvents,
            'job_movements' => $jobMovements,
        ];
    }

    private function validate(array $input): void
    {
        ProfileInputValidator::nationalId($input['national_id'] ?? '', 'الرقم القومي للموظف');
        ProfileInputValidator::birthDate($input['birth_date'] ?? '', 'الموظف');
        ProfileInputValidator::mobile($input['phone_mobile'] ?? '', 'رقم موبايل الموظف');
        ProfileInputValidator::mobile($input['phone_emergency'] ?? '', 'رقم طوارئ الموظف');
        ProfileInputValidator::landline($input['phone_home'] ?? '', 'رقم الهاتف الأرضي للموظف');

        $mobileNumbers = isset($input['staff_mobile_numbers']) && is_array($input['staff_mobile_numbers'])
            ? $input['staff_mobile_numbers']
            : [];
        foreach ($mobileNumbers as $index => $number) {
            ProfileInputValidator::landline($number, 'الهاتف الإضافي رقم ' . ($index + 1) . ' للموظف');
        }
        $landlineNumbers = isset($input['staff_landline_numbers']) && is_array($input['staff_landline_numbers'])
            ? $input['staff_landline_numbers']
            : [];
        foreach ($landlineNumbers as $index => $number) {
            ProfileInputValidator::landline($number, 'الهاتف الأرضي الإضافي رقم ' . ($index + 1) . ' للموظف');
        }
    }
}
