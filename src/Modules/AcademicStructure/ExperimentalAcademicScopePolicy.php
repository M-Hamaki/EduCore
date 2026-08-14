<?php

declare(strict_types=1);

namespace EduCore\Modules\AcademicStructure;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Central policy for the effective experimental state of the academic hierarchy.
 *
 * Effective state is inherited downwards: stage -> grade -> class. A student's
 * account classification remains an independent override owned by users.
 */
final class ExperimentalAcademicScopePolicy
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function studentExperimentalReason(array $student): ?string
    {
        if ((int) ($student['is_test_account'] ?? 0) === 1) {
            return 'test_account';
        }
        if ((int) ($student['class_is_experimental'] ?? 0) === 1) {
            return 'experimental_class';
        }
        if ((int) ($student['grade_is_experimental'] ?? 0) === 1) {
            return 'experimental_grade';
        }
        if ((int) ($student['stage_is_experimental'] ?? 0) === 1) {
            return 'experimental_stage';
        }
        return null;
    }

    public function assertSchemaReady(): void
    {
        foreach ([['stages', 'is_experimental'], ['grades', 'is_experimental'], ['classes', 'is_experimental'], ['users', 'is_test_account']]
            as [$table, $column]) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('تصنيف الهيكل الأكاديمي التجريبي غير مثبت بالكامل. شغّل migration المعتمد أولاً.');
            }
        }
    }

    public function assertStageTransition(int $stageId, bool $targetDirectExperimental): void
    {
        $stage = $this->row('stages', $stageId);
        $before = (int) ($stage['is_experimental'] ?? 0) === 1;
        $this->assertTransition(
            'المرحلة',
            $before,
            $targetDirectExperimental,
            $this->affectedStudentCounts('stage', $stageId)
        );
    }

    public function assertGradeTransition(
        int $gradeId,
        ?int $targetStageId,
        bool $targetDirectExperimental
    ): void {
        $grade = $this->row('grades', $gradeId);
        $before = (int) ($grade['is_experimental'] ?? 0) === 1
            || $this->stageDirectExperimental($grade['stage_id'] !== null ? (int) $grade['stage_id'] : null);
        $after = $targetDirectExperimental || $this->stageDirectExperimental($targetStageId);
        $this->assertTransition('الصف', $before, $after, $this->affectedStudentCounts('grade', $gradeId));
    }

    public function assertClassTransition(
        int $classId,
        ?int $targetGradeId,
        bool $targetDirectExperimental
    ): void {
        $class = $this->row('classes', $classId);
        $before = (int) ($class['is_experimental'] ?? 0) === 1
            || $this->gradeEffectiveExperimental($class['grade_id'] !== null ? (int) $class['grade_id'] : null);
        $after = $targetDirectExperimental || $this->gradeEffectiveExperimental($targetGradeId);
        $this->assertTransition('الفصل', $before, $after, $this->affectedStudentCounts('class', $classId));
    }

    public function gradeEffectiveExperimental(?int $gradeId): bool
    {
        if (!$gradeId) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT COALESCE(g.is_experimental, 0) AS grade_experimental,
                    COALESCE(s.is_experimental, 0) AS stage_experimental
             FROM grades g
             LEFT JOIN stages s ON s.id = g.stage_id
             WHERE g.id = ? LIMIT 1'
        );
        $stmt->execute([$gradeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('الصف الدراسي المحدد غير موجود.');
        }
        return (int) $row['grade_experimental'] === 1 || (int) $row['stage_experimental'] === 1;
    }

    public function classEffectiveExperimental(?int $classId): bool
    {
        if (!$classId) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT COALESCE(c.is_experimental, 0) AS class_experimental,
                    COALESCE(g.is_experimental, 0) AS grade_experimental,
                    COALESCE(s.is_experimental, 0) AS stage_experimental
             FROM classes c
             LEFT JOIN grades g ON g.id = c.grade_id
             LEFT JOIN stages s ON s.id = g.stage_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('الفصل المحدد غير موجود.');
        }
        return (int) $row['class_experimental'] === 1
            || (int) $row['grade_experimental'] === 1
            || (int) $row['stage_experimental'] === 1;
    }

    /** @return array{official:int,test:int,total:int} */
    private function affectedStudentCounts(string $scope, int $id): array
    {
        if ($scope === 'stage') {
            $enrollmentWhere = '(se.stage_id = ? OR g.stage_id = ?)
                AND COALESCE(g.is_experimental, 0) = 0
                AND COALESCE(c.is_experimental, 0) = 0';
            $legacyWhere = 'g.stage_id = ? AND COALESCE(g.is_experimental, 0) = 0
                AND COALESCE(c.is_experimental, 0) = 0';
            $params = [$id, $id, $id];
        } elseif ($scope === 'grade') {
            $enrollmentWhere = 'se.grade_id = ? AND COALESCE(c.is_experimental, 0) = 0';
            $legacyWhere = 'c.grade_id = ? AND COALESCE(c.is_experimental, 0) = 0';
            $params = [$id, $id];
        } elseif ($scope === 'class') {
            $enrollmentWhere = 'se.class_id = ?';
            $legacyWhere = 'c.id = ?';
            $params = [$id, $id];
        } else {
            throw new InvalidArgumentException('نطاق التصنيف التجريبي غير صالح.');
        }

        $sql = "SELECT
                SUM(CASE WHEN COALESCE(u.is_test_account, 0) = 0 THEN 1 ELSE 0 END) AS official_count,
                SUM(CASE WHEN COALESCE(u.is_test_account, 0) = 1 THEN 1 ELSE 0 END) AS test_count,
                COUNT(*) AS total_count
            FROM (
                SELECT se.student_id
                FROM student_enrollments se
                LEFT JOIN grades g ON g.id = se.grade_id
                LEFT JOIN classes c ON c.id = se.class_id
                WHERE {$enrollmentWhere}
                UNION
                SELECT u0.id AS student_id
                FROM users u0
                JOIN classes c ON c.id = u0.class_id
                LEFT JOIN grades g ON g.id = c.grade_id
                WHERE u0.role = 'student' AND {$legacyWhere}
            ) affected
            JOIN users u ON u.id = affected.student_id AND u.role = 'student'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'official' => (int) ($row['official_count'] ?? 0),
            'test' => (int) ($row['test_count'] ?? 0),
            'total' => (int) ($row['total_count'] ?? 0),
        ];
    }

    /** @param array{official:int,test:int,total:int} $counts */
    private function assertTransition(string $label, bool $before, bool $after, array $counts): void
    {
        if ($before === $after) {
            return;
        }
        if (!$before && $after && $counts['official'] > 0) {
            throw new InvalidArgumentException(
                "لا يمكن تحويل {$label} إلى تجريبية لأنها ستؤثر في {$counts['official']} طالب رسمي. "
                . 'أنشئ عنصراً تجريبياً جديداً أو انقل الطلاب أولاً.'
            );
        }
        if ($before && !$after && $counts['test'] > 0) {
            throw new InvalidArgumentException(
                "لا يمكن تحويل {$label} إلى رسمية لأنها ستضم {$counts['test']} حساب طالب تجريبي. "
                . 'انقل الحسابات التجريبية أو حوّل تصنيفها أولاً.'
            );
        }
    }

    private function stageDirectExperimental(?int $stageId): bool
    {
        if (!$stageId) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT COALESCE(is_experimental, 0) FROM stages WHERE id = ?');
        $stmt->execute([$stageId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new InvalidArgumentException('المرحلة الدراسية المحددة غير موجودة.');
        }
        return (int) $value === 1;
    }

    private function row(string $table, int $id): array
    {
        if (!in_array($table, ['stages', 'grades', 'classes'], true) || $id <= 0) {
            throw new InvalidArgumentException('معرّف الهيكل الأكاديمي غير صالح.');
        }
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('عنصر الهيكل الأكاديمي غير موجود.');
        }
        return $row;
    }
}
