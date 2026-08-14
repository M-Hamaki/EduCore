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
use EduCore\Modules\Operations\Audit\AuditService;

/**
 * كلاس إدارة تسجيلات الطلاب السنوية
 *
 * المصدر الموحّد لعلاقة "الطالب ↔ المرحلة/الصف/الفصل" داخل عام دراسي معيّن.
 * يحلّ محل الاعتماد المباشر على users.class_id مع الحفاظ على التوافق (fallback).
 */
class StudentEnrollment
{
    /**
     * معرّف فصل الطالب في عام معيّن.
     * @return int|null معرّف الفصل، أو null إذا لم يوجد تسجيل
     */
    public static function getStudentClass(PDO $db, int $studentId, int $academicYearId): ?int
    {
        if (!self::tableExists($db, 'student_enrollments')) {
            // طبقة توافق: استخدم users.class_id قبل تطبيق الميزة
            $stmt = $db->prepare("SELECT class_id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (int) $val : null;
        }
        $stmt = $db->prepare("SELECT class_id FROM student_enrollments
            WHERE student_id = ? AND academic_year_id = ? AND enrollment_status = 'enrolled' LIMIT 1");
        $stmt->execute([$studentId, $academicYearId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : null;
    }

    /**
     * تسجيل/تحديث تسجيل طالب في عام معيّن (upsert).
     */
    public static function upsert(
        PDO $db,
        int $studentId,
        int $academicYearId,
        ?int $stageId = null,
        ?int $gradeId = null,
        ?int $classId = null,
        string $status = 'enrolled',
        string $academicStatus = 'new',
        ?string $batchId = null
    ): int {
        if (!self::tableExists($db, 'student_enrollments')) {
            return 0;
        }
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM student_enrollments WHERE student_id = ? AND academic_year_id = ? FOR UPDATE');
            $beforeStmt->execute([$studentId, $academicYearId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $sql = "INSERT INTO student_enrollments
                (student_id, academic_year_id, stage_id, grade_id, class_id,
                 enrollment_status, academic_status, enrollment_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
                ON DUPLICATE KEY UPDATE
                    stage_id = VALUES(stage_id), grade_id = VALUES(grade_id),
                    class_id = VALUES(class_id),
                    enrollment_status = VALUES(enrollment_status),
                    academic_status = VALUES(academic_status)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $studentId,
                $academicYearId,
                $stageId,
                $gradeId,
                $classId,
                $status,
                $academicStatus,
            ]);

            $afterStmt = $db->prepare('SELECT * FROM student_enrollments WHERE student_id = ? AND academic_year_id = ?');
            $afterStmt->execute([$studentId, $academicYearId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('Student enrollment could not be reloaded.');
            $id = (int) $after['id'];
            $audit = new AuditService($db);
            if ($before) {
                $audit->recordUpdate('student_enrollment', 'student_enrollments', $id, 'قيد طالب #' . $studentId, $before, $after, 'تحديث قيد الطالب السنوي', $batchId);
            } else {
                $audit->recordInsert('student_enrollment', 'student_enrollments', $id, 'قيد طالب #' . $studentId, $after, 'إنشاء قيد الطالب السنوي', $batchId);
            }
            if ($ownsTransaction) $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * مصفوفة كاملة ببيانات تسجيل طالب في عام معيّن.
     */
    public static function getStudentEnrollment(PDO $db, int $studentId, int $academicYearId): ?array
    {
        if (!self::tableExists($db, 'student_enrollments')) {
            return null;
        }
        $stmt = $db->prepare("SELECT se.*, g.stage_id, c.grade_id AS class_grade_id
            FROM student_enrollments se
                LEFT JOIN classes c ON c.id = se.class_id
                LEFT JOIN grades g ON g.id = c.grade_id
            WHERE se.student_id = ? AND se.academic_year_id = ? LIMIT 1");
        $stmt->execute([$studentId, $academicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * كل الطلاب المسجّلين (enrolled) في فصل معيّن خلال عام معيّن.
     * @return array<int,array>
     */
    public static function getStudentsByClass(PDO $db, int $classId, int $academicYearId, bool $activeOnly = true): array
    {
        if (!self::tableExists($db, 'student_enrollments')) {
            // طبقة توافق
            $sql = "SELECT u.* FROM users u WHERE u.role = 'student' AND u.class_id = ?";
            if ($activeOnly) {
                $sql .= " AND u.status = 'active'";
            }
            $sql .= " ORDER BY u.name";
            $stmt = $db->prepare($sql);
            $stmt->execute([$classId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $sql = "SELECT u.* FROM student_enrollments se
                JOIN users u ON u.id = se.student_id
                WHERE se.class_id = ? AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'";
        if ($activeOnly) {
            $sql .= " AND u.status = 'active'";
        }
        $sql .= " ORDER BY u.name";
        $stmt = $db->prepare($sql);
        $stmt->execute([$classId, $academicYearId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * كل معرّفات الطلاب المسجّلين في عام معيّن.
     * @return int[]
     */
    public static function getStudentIdsForYear(PDO $db, int $academicYearId): array
    {
        if (!self::tableExists($db, 'student_enrollments')) {
            $stmt = $db->query("SELECT id FROM users WHERE role = 'student'");
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $stmt = $db->prepare("SELECT student_id FROM student_enrollments WHERE academic_year_id = ? AND enrollment_status = 'enrolled'");
        $stmt->execute([$academicYearId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
