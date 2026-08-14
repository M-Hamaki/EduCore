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

final class StudentProfilePageQuery
{
    private PDO $db;
    private User $users;

    public function __construct(PDO $db, User $users)
    {
        $this->db = $db;
        $this->users = $users;
    }

    public function editData(int $studentId): array
    {
        $student = new User($this->db);
        $student->id = $studentId;
        $student->readOneWithoutCredentials();
        $profile = $this->users->getStudentProfile($studentId);
        $guardians = $this->users->getStudentGuardians($studentId);

        $filteredExtraData = [];
        $educationalGuardianship = StudentProfilePayload::extractEducationalGuardianship(
            $profile['extra_data'] ?? null,
            $filteredExtraData
        );
        $guardianExtraPhones = [];
        $guardianExtraData = [];
        foreach ($guardians as $index => $guardian) {
            $guardianExtraPhones[$index] = StudentProfilePayload::decodePhonesForForm($guardian['extra_phones'] ?? null);
            $guardianExtraData[$index] = StudentProfilePayload::decodeExtraDataForForm($guardian['extra_data'] ?? null);
        }

        $externalStmt = $this->db->prepare('SELECT setr.*, u.name AS transferred_by_name
            FROM student_external_transfers setr
            LEFT JOIN users u ON u.id = setr.transferred_by
            WHERE setr.student_id = ? LIMIT 1');
        $externalStmt->execute([$studentId]);

        return [
            'student' => $student,
            'profile' => $profile,
            'guardians' => $guardians,
            'siblings' => $this->users->getStudentSiblings($studentId),
            'transfers' => $this->users->getStudentTransfers($studentId),
            'academic_history' => $this->users->getStudentAcademicHistory($studentId),
            'current_enrollment' => $this->currentEnrollment($studentId),
            'external_transfer' => $externalStmt->fetch(PDO::FETCH_ASSOC) ?: [],
            'attachments' => $this->attachments($studentId),
            'extra_phones' => StudentProfilePayload::decodePhonesForForm($profile['extra_phones'] ?? null),
            'extra_data' => $filteredExtraData,
            'educational_guardianship' => $educationalGuardianship,
            'guardian_extra_phones' => $guardianExtraPhones,
            'guardian_extra_data' => $guardianExtraData,
        ];
    }

    public function viewData(int $studentId): ?array
    {
        $student = new User($this->db);
        $student->id = $studentId;
        $student->readOneWithoutCredentials();
        if ($student->role !== 'student') {
            return null;
        }

        $kinshipStmt = $this->db->prepare('SELECT sk.relative_id, kt.name AS kinship_name,
                u.name AS relative_name, sp2.student_code AS relative_code, c.name AS relative_class
            FROM student_kinships sk
            JOIN kinship_types kt ON sk.kinship_type_id = kt.id
            JOIN users u ON sk.relative_id = u.id
            LEFT JOIN student_profiles sp2 ON u.id = sp2.user_id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE sk.student_id = ?
            ORDER BY u.name');
        $kinshipStmt->execute([$studentId]);

        $className = '';
        if (!empty($student->class_id)) {
            $classStmt = $this->db->prepare('SELECT name FROM classes WHERE id = ?');
            $classStmt->execute([$student->class_id]);
            $className = $classStmt->fetchColumn() ?: '';
        }

        return [
            'student' => $student,
            'profile' => $this->users->getStudentProfile($studentId),
            'guardians' => $this->users->getStudentGuardians($studentId),
            'siblings' => $this->users->getStudentSiblings($studentId),
            'transfers' => $this->users->getStudentTransfers($studentId),
            'academic_history' => $this->users->getStudentAcademicHistory($studentId),
            'current_enrollment' => $this->currentEnrollment($studentId),
            'kinships' => $kinshipStmt->fetchAll(PDO::FETCH_ASSOC),
            'attachments' => $this->attachments($studentId),
            'class_name' => $className,
        ];
    }

    private function attachments(int $studentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM student_attachments WHERE user_id = ? ORDER BY uploaded_at DESC');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function currentEnrollment(int $studentId): array
    {
        $academicYearId = AcademicYear::currentId($this->db);
        if ($academicYearId <= 0) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT se.*, ay.name AS academic_year, ay.locked AS academic_year_locked,
                    s.stage_name, g.grade_name, c.name AS class_name
             FROM student_enrollments se
             JOIN academic_years ay ON ay.id = se.academic_year_id
             LEFT JOIN stages s ON s.id = se.stage_id
             LEFT JOIN grades g ON g.id = se.grade_id
             LEFT JOIN classes c ON c.id = se.class_id
             WHERE se.student_id = ? AND se.academic_year_id = ?
             LIMIT 1'
        );
        $stmt->execute([$studentId, $academicYearId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
