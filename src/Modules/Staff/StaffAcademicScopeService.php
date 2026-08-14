<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';
require_once __DIR__ . '/StaffRoleCapabilityResolver.php';

class StaffAcademicScopeService
{
    public const SCOPED_ROLES = ['specialist', 'doctor', 'librarian'];

    private bool $schemaReady = false;

    public function __construct(protected PDO $db)
    {
    }

    public static function roleRequiresScope(?string $role): bool
    {
        return in_array((string)$role, self::SCOPED_ROLES, true);
    }

    public function assertSchemaReady(): void
    {
        if ($this->schemaReady) return;
        foreach (['staff_grade_assignments', 'staff_class_assignments'] as $table) {
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Staff academic scope schema is not ready; run database migrations.');
            }
        }
        $columnStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (\'staff_grade_assignments\', \'staff_class_assignments\')
               AND COLUMN_NAME = \'role_key\''
        );
        $columnStmt->execute();
        if ((int)$columnStmt->fetchColumn() !== 2) {
            throw new RuntimeException('Role-aware staff academic scope schema is not ready; run database migrations.');
        }
        $this->schemaReady = true;
    }

    /** @return array{grade_ids:array<int,int>,explicit_class_ids:array<int,int>,class_ids:array<int,int>} */
    public function scope(int $staffId, int $academicYearId, ?string $roleKey = null): array
    {
        $this->assertSchemaReady();
        $roleKey = $this->resolveRoleKey($staffId, $roleKey);
        $gradeIds = $this->assignedIds('staff_grade_assignments', 'grade_id', $staffId, $roleKey, $academicYearId);
        $explicitClassIds = $this->assignedIds('staff_class_assignments', 'class_id', $staffId, $roleKey, $academicYearId);
        return [
            'grade_ids' => $gradeIds,
            'explicit_class_ids' => $explicitClassIds,
            'class_ids' => $this->allowedClassIds($gradeIds, $explicitClassIds),
        ];
    }

    /** @return array<int,int> */
    public function allowedClassIdsForStaff(int $staffId, int $academicYearId, ?string $roleKey = null): array
    {
        return $this->scope($staffId, $academicYearId, $roleKey)['class_ids'];
    }

    /** @return array<int,int> Compatibility for existing specialist callers. */
    public function allowedClassIdsForSpecialist(int $staffId, int $academicYearId): array
    {
        return $this->allowedClassIdsForStaff($staffId, $academicYearId);
    }

    public function assertClassAllowed(int $staffId, int $academicYearId, int $classId, ?string $roleKey = null): void
    {
        if ($classId <= 0 || !in_array($classId, $this->allowedClassIdsForStaff($staffId, $academicYearId, $roleKey), true)) {
            throw new RuntimeException('الفصل المطلوب خارج نطاق المستخدم الحالي.');
        }
    }

    public function assertStudentAllowed(int $staffId, int $academicYearId, int $studentId, ?string $roleKey = null): void
    {
        $classIds = $this->allowedClassIdsForStaff($staffId, $academicYearId, $roleKey);
        if ($studentId <= 0 || $classIds === []) {
            throw new RuntimeException('الطالب المطلوب خارج النطاق الأكاديمي للمستخدم الحالي.');
        }
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT 1 FROM users u
            JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            WHERE u.id = ? AND u.role = 'student' AND u.deleted_at IS NULL
              AND se.class_id IN ({$placeholders}) LIMIT 1");
        $stmt->execute(array_merge([$academicYearId, $studentId], $classIds));
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('الطالب المطلوب خارج النطاق الأكاديمي للمستخدم الحالي.');
        }
    }

    /** @return array<int,int> */
    public function allowedTeacherIds(int $staffId, int $academicYearId, ?string $roleKey = null): array
    {
        $classIds = $this->allowedClassIdsForStaff($staffId, $academicYearId, $roleKey);
        if ($classIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT DISTINCT tsa.teacher_id
            FROM teacher_subject_assignments tsa
            JOIN users u ON u.id = tsa.teacher_id
            JOIN user_role_assignments ura ON ura.user_id = u.id
                AND ura.role_key = 'teacher' AND ura.status = 'active'
            WHERE tsa.academic_year_id = ? AND tsa.is_active = 1
              AND tsa.class_id IN ({$placeholders}) ORDER BY tsa.teacher_id");
        $stmt->execute(array_merge([$academicYearId], $classIds));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<int,mixed> $gradeIds @param array<int,mixed> $classIds */
    public function replaceAssignments(
        int $staffId,
        int $academicYearId,
        array $gradeIds,
        array $classIds,
        int $actorId,
        ?string $roleKey = null,
        ?string $batchId = null
    ): array
    {
        $this->assertSchemaReady();
        $gradeIds = $this->normalizeIds($gradeIds);
        $classIds = $this->normalizeIds($classIds);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();

        try {
            $userStmt = $this->db->prepare('SELECT id, name, role FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $userStmt->execute([$staffId]);
            $staff = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$staff) {
                throw new InvalidArgumentException('حساب العامل غير موجود.');
            }
            $roleKey = $this->resolveRoleKey($staffId, $roleKey);
            if (!(new StaffRoleCapabilityResolver($this->db))->requiresAcademicScope($roleKey)) {
                throw new InvalidArgumentException('لا يمكن حفظ نطاق أكاديمي لهذا الدور.');
            }
            $membershipStmt = $this->db->prepare(
                "SELECT 1 FROM user_role_assignments
                 WHERE user_id = ? AND role_key = ? AND status = 'active' LIMIT 1"
            );
            $membershipStmt->execute([$staffId, $roleKey]);
            if (!$membershipStmt->fetchColumn()) {
                throw new InvalidArgumentException('الدور المحدد غير مخصص لحساب العامل.');
            }
            $this->assertAcademicYear($academicYearId);
            $this->assertExistingIds('grades', $gradeIds);
            $classGrades = $this->classGrades($classIds);
            foreach ($classGrades as $classId => $gradeId) {
                if (in_array($gradeId, $gradeIds, true)) {
                    $classIds = array_values(array_diff($classIds, [$classId]));
                }
            }

            $beforeGrades = $this->assignmentRows('staff_grade_assignments', $staffId, $roleKey, $academicYearId, true);
            $beforeClasses = $this->assignmentRows('staff_class_assignments', $staffId, $roleKey, $academicYearId, true);
            $beforeGradeIds = array_map('intval', array_column($beforeGrades, 'grade_id'));
            $beforeClassIds = array_map('intval', array_column($beforeClasses, 'class_id'));
            sort($beforeGradeIds); sort($beforeClassIds); sort($gradeIds); sort($classIds);
            if ($beforeGradeIds === $gradeIds && $beforeClassIds === $classIds) {
                if ($ownsTransaction) $this->db->commit();
                return $this->scope($staffId, $academicYearId, $roleKey);
            }

            $this->deleteAssignments($staffId, $roleKey, $academicYearId);
            $this->insertAssignments('staff_grade_assignments', 'grade_id', $staffId, $roleKey, $academicYearId, $gradeIds, $actorId);
            $this->insertAssignments('staff_class_assignments', 'class_id', $staffId, $roleKey, $academicYearId, $classIds, $actorId);
            $afterGrades = $this->assignmentRows('staff_grade_assignments', $staffId, $roleKey, $academicYearId, false);
            $afterClasses = $this->assignmentRows('staff_class_assignments', $staffId, $roleKey, $academicYearId, false);

            (new AuditService($this->db))->recordReplacement(
                'staff_academic_scope',
                $staffId,
                (string)$staff['name'],
                $this->auditItems(array_merge($beforeGrades, $beforeClasses), true),
                $this->auditItems(array_merge($afterGrades, $afterClasses), false),
                ['academic_year_id' => $academicYearId, 'role' => $roleKey, 'grade_ids' => $gradeIds, 'class_ids' => $classIds],
                $batchId
            );

            if ($ownsTransaction) $this->db->commit();
            return $this->scope($staffId, $academicYearId, $roleKey);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function removeAllAssignments(int $staffId, int $actorId, string $reason = 'إلغاء النطاق الأكاديمي'): void
    {
        $this->assertSchemaReady();
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $nameStmt = $this->db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $nameStmt->execute([$staffId]);
            $name = (string)($nameStmt->fetchColumn() ?: ('عامل #' . $staffId));
            $rows = [];
            foreach (['staff_grade_assignments', 'staff_class_assignments'] as $table) {
                $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE staff_id = ? ORDER BY id FOR UPDATE");
                $stmt->execute([$staffId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $rows[] = ['table' => $table, 'record_id' => (int)$row['id'], 'snapshot' => $row, 'description' => $reason];
                }
                $this->db->prepare("DELETE FROM {$table} WHERE staff_id = ?")->execute([$staffId]);
            }
            if ($rows !== []) {
                (new AuditService($this->db))->recordReplacement(
                    'staff_academic_scope', $staffId, $name, $rows, [],
                    ['reason' => $reason, 'actor_id' => $actorId]
                );
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function removeRoleAssignments(
        int $staffId,
        string $roleKey,
        int $actorId,
        string $reason = 'إلغاء نطاق الدور الأكاديمي',
        ?string $batchId = null
    ): void {
        $this->assertSchemaReady();
        $roleKey = $this->resolveRoleKey($staffId, $roleKey);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $nameStmt = $this->db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $nameStmt->execute([$staffId]);
            $name = (string)($nameStmt->fetchColumn() ?: ('عامل #' . $staffId));
            $rows = [];
            foreach (['staff_grade_assignments', 'staff_class_assignments'] as $table) {
                $stmt = $this->db->prepare(
                    "SELECT * FROM {$table} WHERE staff_id = ? AND role_key = ? ORDER BY id FOR UPDATE"
                );
                $stmt->execute([$staffId, $roleKey]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $rows[] = ['table' => $table, 'record_id' => (int)$row['id'], 'snapshot' => $row, 'description' => $reason];
                }
                $this->db->prepare("DELETE FROM {$table} WHERE staff_id = ? AND role_key = ?")
                    ->execute([$staffId, $roleKey]);
            }
            if ($rows !== []) {
                (new AuditService($this->db))->recordReplacement(
                    'staff_academic_scope', $staffId, $name, $rows, [],
                    ['reason' => $reason, 'actor_id' => $actorId, 'role' => $roleKey],
                    $batchId
                );
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<int,int> $gradeIds @param array<int,int> $explicitClassIds @return array<int,int> */
    private function allowedClassIds(array $gradeIds, array $explicitClassIds): array
    {
        $ids = $explicitClassIds;
        if ($gradeIds !== []) {
            $placeholders = implode(',', array_fill(0, count($gradeIds), '?'));
            $stmt = $this->db->prepare("SELECT id FROM classes WHERE status = 'active' AND grade_id IN ({$placeholders})");
            $stmt->execute($gradeIds);
            $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }

    /** @return array<int,int> */
    private function assignedIds(string $table, string $column, int $staffId, string $roleKey, int $academicYearId): array
    {
        $stmt = $this->db->prepare(
            "SELECT {$column} FROM {$table}
             WHERE staff_id = ? AND role_key = ? AND academic_year_id = ? ORDER BY {$column}"
        );
        $stmt->execute([$staffId, $roleKey, $academicYearId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    private function assertAcademicYear(int $academicYearId): void
    {
        $stmt = $this->db->prepare("SELECT 1 FROM academic_years WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$academicYearId]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('العام الدراسي المحدد غير صالح.');
    }

    /** @param array<int,int> $ids */
    private function assertExistingIds(string $table, array $ids): void
    {
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND id IN ({$placeholders})");
        $stmt->execute($ids);
        if ((int)$stmt->fetchColumn() !== count($ids)) throw new InvalidArgumentException('تتضمن التعيينات عناصر غير صالحة أو غير نشطة.');
    }

    /** @param array<int,int> $classIds @return array<int,int> */
    private function classGrades(array $classIds): array
    {
        if ($classIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT id, grade_id FROM classes WHERE status = 'active' AND id IN ({$placeholders})");
        $stmt->execute($classIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $map[(int)$row['id']] = (int)$row['grade_id'];
        if (count($map) !== count($classIds)) throw new InvalidArgumentException('تتضمن التعيينات فصولاً غير صالحة أو غير نشطة.');
        return $map;
    }

    /** @return array<int,array<string,mixed>> */
    private function assignmentRows(string $table, int $staffId, string $roleKey, int $academicYearId, bool $lock): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$table} WHERE staff_id = ? AND role_key = ? AND academic_year_id = ? ORDER BY id"
            . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$staffId, $roleKey, $academicYearId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) $row['_audit_table'] = $table;
        unset($row);
        return $rows;
    }

    private function deleteAssignments(int $staffId, string $roleKey, int $academicYearId): void
    {
        foreach (['staff_grade_assignments', 'staff_class_assignments'] as $table) {
            $this->db->prepare(
                "DELETE FROM {$table} WHERE staff_id = ? AND role_key = ? AND academic_year_id = ?"
            )->execute([$staffId, $roleKey, $academicYearId]);
        }
    }

    /** @param array<int,int> $ids */
    private function insertAssignments(
        string $table,
        string $column,
        int $staffId,
        string $roleKey,
        int $academicYearId,
        array $ids,
        int $actorId
    ): void
    {
        if ($ids === []) return;
        $stmt = $this->db->prepare(
            "INSERT INTO {$table} (staff_id, role_key, academic_year_id, {$column}, assigned_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($ids as $id) {
            $stmt->execute([$staffId, $roleKey, $academicYearId, $id, $actorId > 0 ? $actorId : null]);
        }
    }

    private function resolveRoleKey(int $staffId, ?string $roleKey): string
    {
        $roleKey = trim((string)$roleKey);
        if ($roleKey !== '') {
            return $roleKey;
        }

        $stmt = $this->db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $legacyRole = trim((string)($stmt->fetchColumn() ?: ''));
        if ($legacyRole === '') {
            throw new InvalidArgumentException('تعذر تحديد الدور المرتبط بالنطاق الأكاديمي.');
        }
        return $legacyRole;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function auditItems(array $rows, bool $deleting): array
    {
        $items = [];
        foreach ($rows as $row) {
            $table = (string)($row['_audit_table'] ?? '');
            unset($row['_audit_table']);
            $items[] = [
                'table' => $table,
                'record_id' => (int)$row['id'],
                'snapshot' => $row,
                'description' => $deleting ? 'استبدال النطاق الأكاديمي للعامل' : 'إضافة النطاق الأكاديمي للعامل',
            ];
        }
        return $items;
    }
}
