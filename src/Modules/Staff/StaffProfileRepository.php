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

final class StaffProfileRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function activitySnapshot(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT u.name,
                sp.employee_code, sp.biometric_id, sp.ministry_code, sp.full_name_ar, sp.full_name_en,
                sp.national_id, sp.passport_number, sp.birth_date, sp.birth_place,
                sp.gender, sp.religion, sp.nationality,
                sp.address_detail, sp.city_area, sp.phone_mobile, sp.phone_home, sp.phone_emergency,
                sp.emergency_contact_name, sp.email_personal,
                sp.marital_status, sp.military_status, sp.public_service_status,
                sp.number_of_children, sp.blood_type, sp.job_title, sp.department, sp.job_grade,
                sp.contract_type, sp.contract_start, sp.contract_end, sp.admin_notes,
                sp.qualification, sp.qualification_year, sp.qualification_university,
                sp.specialization, sp.other_qualifications, sp.training_courses,
                sp.years_of_experience, sp.work_history, sp.promotions, sp.status_history,
                sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date,
                sp.current_work_status, sp.current_status_reason, sp.current_status_effective_date,
                sp.latest_hire_date, sp.last_working_day, sp.can_rehire,
                sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
                sp.medications, sp.previous_medical_reports, sp.emergency_medical_notes,
                sp.treatment_plan, sp.health_issues, sp.psychological_notes, sp.notes
            FROM users u LEFT JOIN staff_profiles sp ON sp.user_id = u.id
            WHERE u.id=?
              AND NOT EXISTS (
                  SELECT 1 FROM user_role_assignments ura
                  WHERE ura.user_id = u.id AND ura.status = 'active'
                    AND ura.role_key IN ('admin', 'super_admin', 'student')
              )
              AND sp.user_id IS NOT NULL");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function assertManageableStaff(int $userId, string $verb = 'إدارته'): void
    {
        $stmt = $this->db->prepare("SELECT u.id
            FROM users u
            INNER JOIN staff_profiles sp ON sp.user_id = u.id
            WHERE u.id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM user_role_assignments ura
                  WHERE ura.user_id = u.id AND ura.status = 'active'
                    AND ura.role_key IN ('admin', 'super_admin', 'student')
              )
            LIMIT 1");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'ملف العامل المطلوب غير موجود أو لا يمكن ' . $verb . ' من هذه الصفحة.'
            );
        }
    }

    public function displayName(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: ('موظف #' . $userId));
    }
}
