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

final class StudentProfileRequestMapper
{
    private const PROFILE_FIELDS = [
        'student_code', 'ministry_code', 'grade_id',
        'first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar',
        'first_name_en', 'second_name_en', 'third_name_en', 'fourth_name_en', 'family_name_en',
        'birth_date', 'birth_place', 'national_id', 'nationality', 'passport_number', 'religion', 'gender',
        'city_area', 'address_current', 'phone_mobile', 'phone_home', 'phone_emergency', 'enrollment_date',
        'previous_school', 'notes', 'health_status', 'chronic_diseases', 'allergies', 'blood_type',
        'disabilities', 'medications', 'psychological_notes', 'previous_medical_reports',
        'insurance_number', 'insurance_start_date', 'insurance_end_date', 'treatment_plan',
        'emergency_medical_notes',
    ];

    public function normalizeAndValidate(array $post): array
    {
        ProfileInputValidator::nationalId($post['national_id'] ?? '', 'الرقم القومي للطالب');
        ProfileInputValidator::birthDate($post['birth_date'] ?? '', 'الطالب');
        ProfileInputValidator::mobile($post['phone_mobile'] ?? '', 'رقم موبايل الطالب الأساسي');
        ProfileInputValidator::mobile($post['phone_emergency'] ?? '', 'رقم موبايل الطالب');
        ProfileInputValidator::landline($post['phone_home'] ?? '', 'رقم الهاتف الأرضي للطالب');

        $studentMobileNumbers = is_array($post['student_mobile_numbers'] ?? null) ? $post['student_mobile_numbers'] : [];
        foreach ($studentMobileNumbers as $index => $number) {
            ProfileInputValidator::landline($number, 'الهاتف الإضافي رقم ' . ($index + 1) . ' للطالب');
        }
        $studentLandlineNumbers = is_array($post['student_landline_numbers'] ?? null) ? $post['student_landline_numbers'] : [];
        foreach ($studentLandlineNumbers as $index => $number) {
            ProfileInputValidator::landline($number, 'الهاتف الأرضي الإضافي رقم ' . ($index + 1) . ' للطالب');
        }

        if (!empty($post['guardians']) && is_array($post['guardians'])) {
            $post['guardians'] = StudentProfilePayload::normalizeFixedParents($post['guardians'], $post);
            $post['guardians'] = StudentProfilePayload::normalizeRelationships($post['guardians']);
            foreach ($post['guardians'] as $guardianIndex => $guardian) {
                $number = $guardianIndex + 1;
                ProfileInputValidator::nationalId($guardian['national_id'] ?? '', "الرقم القومي لولي الأمر {$number}");
                ProfileInputValidator::mobile($guardian['phone_primary'] ?? '', "موبايل ولي الأمر {$number}");
                ProfileInputValidator::landline($guardian['phone_landline'] ?? '', "الهاتف الأرضي لولي الأمر {$number}");
                ProfileInputValidator::landline($guardian['work_phone'] ?? '', "هاتف العمل لولي الأمر {$number}");
                $mobileNumbers = is_array($guardian['extra_mobile_numbers'] ?? null) ? $guardian['extra_mobile_numbers'] : [];
                foreach ($mobileNumbers as $index => $phone) {
                    ProfileInputValidator::landline($phone, 'الهاتف الإضافي رقم ' . ($index + 1) . " لولي الأمر {$number}");
                }
                $landlineNumbers = is_array($guardian['extra_landline_numbers'] ?? null) ? $guardian['extra_landline_numbers'] : [];
                foreach ($landlineNumbers as $index => $phone) {
                    ProfileInputValidator::landline($phone, 'الهاتف الأرضي الإضافي رقم ' . ($index + 1) . " لولي الأمر {$number}");
                }
            }
        }

        return $post;
    }

    public function profileData(array $post, bool $cleanLegacyNotes = false): array
    {
        $profile = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (isset($post[$field])) {
                $profile[$field] = trim((string) $post[$field]);
            }
        }
        if (empty($profile['grade_id']) && !empty($post['graduate_grade_id'])) {
            $profile['grade_id'] = trim((string) $post['graduate_grade_id']);
        }
        if (isset($profile['nationality']) && in_array($profile['nationality'], ['أخرى', 'other'], true) && !empty($post['nationality_other'])) {
            $profile['nationality'] = trim((string) $post['nationality_other']);
        }
        if (($profile['religion'] ?? '') === 'other' && !empty(trim((string) ($post['religion_other'] ?? '')))) {
            $profile['notes'] = trim(($profile['notes'] ?? '') . "\nالديانة (أخرى): " . trim((string) $post['religion_other']));
        }

        $profile['extra_phones'] = StudentProfilePayload::studentExtraPhones($post);
        $profile['extra_data'] = StudentProfilePayload::studentExtraData($post);
        $guardianship = $post['educational_guardianship'] ?? '';
        if (in_array($guardianship, ['other', 'أخرى'], true) && !empty($post['educational_guardianship_other'])) {
            $guardianship = trim((string) $post['educational_guardianship_other']);
        }
        $profile['extra_data'] = StudentProfilePayload::mergeEducationalGuardianship($profile['extra_data'], (string) $guardianship);

        if ($cleanLegacyNotes) {
            $profile['notes'] = trim(preg_replace(
                '/\[CONTACT_META_START\].*?\[CONTACT_META_END\]\n*/s',
                '',
                preg_replace('/\[ADDITIONAL_DATA_START\].*?\[ADDITIONAL_DATA_END\]\n*/s', '', (string) ($profile['notes'] ?? ''))
            ));
        }

        $profile['search_key_ar'] = User::buildSearchKey($post['first_name_ar'] ?? '', $post['second_name_ar'] ?? '', $post['third_name_ar'] ?? '', $post['fourth_name_ar'] ?? '', $post['family_name_ar'] ?? '');
        $profile['search_key_en'] = User::buildSearchKey($post['first_name_en'] ?? '', $post['second_name_en'] ?? '', $post['third_name_en'] ?? '', $post['fourth_name_en'] ?? '', $post['family_name_en'] ?? '');
        if (!empty($post['birth_date'])) {
            $age = User::calculateAgeFromOctober($post['birth_date']);
            if ($age) {
                $profile['age_years'] = $age['years'];
                $profile['age_months'] = $age['months'];
                $profile['age_days'] = $age['days'];
                $profile['age_reference_date'] = $age['ref'];
            }
        }
        return $profile;
    }
}
