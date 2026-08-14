<?php

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

final class StudentProfileRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function className(?int $classId): string
    {
        if (!$classId) {
            return 'بدون فصل';
        }
        $stmt = $this->db->prepare('SELECT name FROM classes WHERE id = ?');
        $stmt->execute([$classId]);
        return (string) ($stmt->fetchColumn() ?: 'فصل #' . $classId);
    }

    public function studentName(int $studentId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM users WHERE id = ? AND role = ?');
        $stmt->execute([$studentId, 'student']);
        return (string) ($stmt->fetchColumn() ?: 'طالب #' . $studentId);
    }

    public function assertManageableStudent(int $studentId): void
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('رقم الطالب غير صالح.');
        }
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $stmt->execute([$studentId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('ملف الطالب المطلوب غير موجود أو لا يمكن إدارته من هذه الصفحة.');
        }
    }

    public function activitySnapshot(int $studentId): array
    {
        $academicYearId = AcademicYear::currentId($this->db);
        $stmt = $this->db->prepare("SELECT u.name, c.name AS class,
                COALESCE(se.grade_id, sp.grade_id) AS grade_id, gr.grade_name AS grade,
                sp.student_code, sp.ministry_code,
                sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.fourth_name_ar, sp.family_name_ar,
                sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en,
                sp.birth_date, sp.birth_place, sp.national_id, sp.nationality, sp.passport_number, sp.religion,
                sp.gender, sp.city_area, sp.address_current, sp.phone_mobile, sp.phone_home, sp.phone_emergency,
                sp.enrollment_date,
                CASE
                    WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued'
                    WHEN se.enrollment_status = 'graduated' THEN 'enrolled'
                    ELSE COALESCE(se.enrollment_status, sp.enrollment_status, 'enrolled')
                END AS enrollment_status,
                CASE
                    WHEN se.enrollment_status = 'graduated' THEN 'graduated'
                    ELSE COALESCE(se.academic_status, IF(sp.enrollment_status = 'graduated', 'graduated', 'new'))
                END AS academic_status,
                sp.blood_type, sp.health_status, sp.chronic_diseases,
                sp.allergies, sp.disabilities, sp.medications, sp.insurance_number, sp.notes, sp.previous_school,
                sp.insurance_start_date, sp.insurance_end_date, sp.psychological_notes, sp.emergency_medical_notes,
                sp.treatment_plan, sp.previous_medical_reports,
                setr.destination AS transfer_destination, setr.transfer_date AS external_transfer_date,
                setr.reason AS external_transfer_reason, setr.notes AS external_transfer_notes,
                (SELECT COUNT(*) FROM student_guardians sg WHERE sg.student_id = u.id) AS guardian_count
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
            LEFT JOIN classes c ON c.id = COALESCE(se.class_id, u.class_id)
            LEFT JOIN grades gr ON gr.id = COALESCE(se.grade_id, sp.grade_id)
            LEFT JOIN student_external_transfers setr ON setr.student_id = u.id
            WHERE u.id = ? AND u.role = 'student'
            LIMIT 1");
        $stmt->execute([$academicYearId, $studentId]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty(trim((string) ($snapshot['first_name_ar'] ?? '')))
            && !empty(trim((string) ($snapshot['name'] ?? '')))) {
            $fallback = User::splitDisplayName((string) $snapshot['name']);
            foreach ($fallback as $field => $value) {
                if (empty(trim((string) ($snapshot[$field] ?? '')))) {
                    $snapshot[$field] = $value;
                }
            }
        }
        return $snapshot;
    }
}
