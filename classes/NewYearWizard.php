<?php

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/NewYearRolloverService.php';

/**
 * معالج تهيئة عام دراسي جديد.
 *
 * يعتمد على تسجيلات student_enrollments لكل عام:
 * - الطالب الراسب يظل في نفس الصف.
 * - طالب الصف النهائي غير الراسب يتحول إلى خريج.
 * - باقي الطلاب ينتقلون إلى الصف التالي.
 */
class NewYearWizard
{
    public static function preview(PDO $db, int $sourceYearId, int $targetYearId, array $retainedStudentIds = []): array
    {
        $report = (new NewYearRolloverService($db))->preflight(
            $sourceYearId,
            $targetYearId,
            $retainedStudentIds
        );
        return self::formatPreview($report);
    }

    public static function prepareAndPreview(
        PDO $db,
        int $sourceYearId,
        int $targetYearId,
        array $decisionOverrides = [],
        array $retainedStudentIds = [],
        ?int $actorId = null
    ): array {
        $report = (new NewYearRolloverService($db))->prepareDecisions(
            $sourceYearId,
            $targetYearId,
            $decisionOverrides,
            $retainedStudentIds,
            $actorId
        );
        return self::formatPreview($report);
    }

    private static function formatPreview(array $report): array
    {
        return [
            'classes_to_copy' => (int) ($report['classes'] ?? 0),
            'students_auto_placed' => (int) ($report['class_mappings']['auto_placed_students'] ?? 0),
            'students_unassigned_promoted' => (int) ($report['class_mappings']['unassigned_promoted_students'] ?? 0),
            'students_promoted' => (int) ($report['students']['promoted'] ?? 0),
            'students_retained' => (int) ($report['students']['retained'] ?? 0),
            'students_graduating' => (int) ($report['students']['graduating'] ?? 0),
            'students_pending' => (int) ($report['students']['pending'] ?? 0),
            'students_transferred_out' => (int) ($report['students']['transferred_out'] ?? 0),
            'students_withdrawn' => (int) ($report['students']['withdrawn'] ?? 0),
            'students_excluded_test' => (int) ($report['students']['excluded_test'] ?? 0),
            'students_skipped' => (int) ($report['students']['students_skipped'] ?? 0),
            'calendar' => $report['calendar'] ?? [],
            'subject_assignments' => (int) ($report['subject_assignments'] ?? 0),
            'assessment_schemes' => (int) ($report['assessment_schemes'] ?? 0),
            'blockers' => $report['blockers'] ?? [],
            'blocker_groups' => $report['blocker_groups'] ?? [],
            'warnings' => $report['warnings'] ?? [],
            'ready' => (bool) ($report['ready'] ?? false),
            'source_fingerprint' => (string) ($report['source_fingerprint'] ?? ''),
            'decision_fingerprint' => (string) ($report['decision_fingerprint'] ?? ''),
        ];
    }

    public static function execute(PDO $db, int $sourceYearId, int $targetYearId, array $options = [], array $retainedStudentIds = []): array
    {
        $backupKey = trim((string) ($options['backup_key'] ?? ''));
        if ($backupKey === '') {
            throw new RuntimeException('يلزم اختيار نسخة تعافٍ مجرّبة الاستعادة.');
        }
        $report = (new NewYearRolloverService($db))->execute(
            $sourceYearId,
            $targetYearId,
            $backupKey,
            $retainedStudentIds,
            isset($options['actor_id']) ? (int) $options['actor_id'] : null
        );
        $report['buses_copied'] = 0;
        $report['balances_carried'] = 0;
        return $report;
    }

    public static function getStudentsGroupedByClass(PDO $db, int $yearId, int $targetYearId = 0): array
    {
        if ($yearId <= 0) {
            return [];
        }

        $stmt = $db->prepare("SELECT
                COALESCE(se.class_id, 0) AS class_id,
                COALESCE(c.name, 'بدون فصل') AS class_name,
                g.grade_name,
                s.stage_name,
                se.student_id,
                se.stage_id,
                se.grade_id,
                se.enrollment_status AS current_enrollment_status,
                se.academic_status AS current_academic_status,
                u.name AS student_name,
                sp.student_code,
                       COALESCE(u.is_test_account, 0) AS is_test_account,
                COALESCE(s.is_experimental, 0) AS stage_is_experimental,
                COALESCE(g.is_experimental, 0) AS grade_is_experimental,
                COALESCE(c.is_experimental, 0) AS class_is_experimental,
                d.decision AS saved_decision,
                d.enrollment_status AS saved_enrollment_status,
                d.academic_status AS saved_academic_status,
                d.status AS decision_status,
                d.decision_source,
                d.reason_code
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id AND u.role = 'student'
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN classes c ON c.id = se.class_id
            LEFT JOIN grades g ON g.id = se.grade_id
            LEFT JOIN stages s ON s.id = se.stage_id
            LEFT JOIN student_promotion_decisions d
              ON d.source_enrollment_id = se.id AND d.target_year_id = ? AND d.status <> 'cancelled'
            WHERE se.academic_year_id = ?
              AND se.enrollment_status = 'enrolled'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
            ORDER BY s.stage_order, g.grade_order, c.display_order, c.name, u.name");
        $stmt->execute([$targetYearId, $yearId]);

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $classId = (int) $row['class_id'];
            if (!isset($groups[$classId])) {
                $groups[$classId] = [
                    'class_id' => $classId,
                    'class_name' => $row['class_name'],
                    'grade_name' => $row['grade_name'],
                    'stage_name' => $row['stage_name'],
                    'students' => [],
                ];
            }
            $groups[$classId]['students'][] = [
                'student_id' => (int) $row['student_id'],
                'stage_id' => isset($row['stage_id']) ? (int) $row['stage_id'] : null,
                'grade_id' => isset($row['grade_id']) ? (int) $row['grade_id'] : null,
                'current_enrollment_status' => $row['current_enrollment_status'] ?? 'enrolled',
                'current_academic_status' => $row['current_academic_status'] ?? 'new',
                'student_name' => $row['student_name'],
                'student_code' => $row['student_code'],
                'is_test_account' => (int) ($row['is_test_account'] ?? 0) === 1,
                'stage_is_experimental' => (int) ($row['stage_is_experimental'] ?? 0) === 1,
                'grade_is_experimental' => (int) ($row['grade_is_experimental'] ?? 0) === 1,
                'class_is_experimental' => (int) ($row['class_is_experimental'] ?? 0) === 1,
                'saved_decision' => $row['saved_decision'] ?? null,
                'saved_enrollment_status' => $row['saved_enrollment_status'] ?? null,
                'saved_academic_status' => $row['saved_academic_status'] ?? null,
                'decision_status' => $row['decision_status'] ?? null,
                'decision_source' => $row['decision_source'] ?? null,
                'decision_reason_code' => $row['reason_code'] ?? null,
            ];
        }

        return array_values($groups);
    }

    private static function validateYears(int $sourceYearId, int $targetYearId): void
    {
        if ($sourceYearId <= 0 || $targetYearId <= 0 || $sourceYearId === $targetYearId) {
            throw new InvalidArgumentException('يرجى اختيار عام مصدر وهدف صحيحين ومختلفين.');
        }
    }

    private static function normalizeStudentIdSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $set[$id] = true;
            }
        }
        return $set;
    }

    private static function getFinalGradeId(PDO $db): int
    {
        $stmt = $db->query("SELECT g.id
            FROM grades g
            JOIN stages s ON s.id = g.stage_id
            WHERE g.status = 'active' AND s.status = 'active'
            ORDER BY s.stage_order DESC, g.grade_order DESC, g.id DESC
            LIMIT 1");
        return (int) $stmt->fetchColumn();
    }

    private static function getActiveStudentsForSource(PDO $db, int $yearId): array
    {
        $stmt = $db->prepare("SELECT se.student_id, se.stage_id, se.grade_id, se.class_id
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            WHERE se.academic_year_id = ?
              AND se.enrollment_status = 'enrolled'
              AND u.role = 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL");
        $stmt->execute([$yearId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function getNextGradeId(PDO $db, int $gradeId): int
    {
        if ($gradeId <= 0) {
            return 0;
        }

        $stmt = $db->prepare("SELECT g.grade_order, s.stage_order
            FROM grades g
            JOIN stages s ON s.id = g.stage_id
            WHERE g.id = ?
            LIMIT 1");
        $stmt->execute([$gradeId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return 0;
        }

        $next = $db->prepare("SELECT g.id
            FROM grades g
            JOIN stages s ON s.id = g.stage_id
            WHERE g.status = 'active'
              AND s.status = 'active'
              AND (
                    s.stage_order > :stage_order
                    OR (s.stage_order = :stage_order AND g.grade_order > :grade_order)
                  )
            ORDER BY s.stage_order ASC, g.grade_order ASC, g.id ASC
            LIMIT 1");
        $next->execute([
            ':stage_order' => (int) $current['stage_order'],
            ':grade_order' => (int) $current['grade_order'],
        ]);
        return (int) $next->fetchColumn();
    }

    private static function getStageForGrade(PDO $db, int $gradeId): ?int
    {
        $stmt = $db->prepare("SELECT stage_id FROM grades WHERE id = ? LIMIT 1");
        $stmt->execute([$gradeId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int) $value : null;
    }

    private static function findMatchingClass(PDO $db, int $targetYearId, int $nextGradeId, int $oldClassId, int $sourceYearId): ?int
    {
        if ($oldClassId <= 0 || $nextGradeId <= 0) {
            return null;
        }

        $rankStmt = $db->prepare("SELECT COUNT(*)
            FROM classes
            WHERE grade_id = (SELECT grade_id FROM classes WHERE id = ?)
              AND academic_year_id = ?
              AND id <= ?");
        $rankStmt->execute([$oldClassId, $sourceYearId, $oldClassId]);
        $rank = max(1, (int) $rankStmt->fetchColumn());

        $match = $db->prepare("SELECT id
            FROM classes
            WHERE grade_id = ? AND academic_year_id = ?
            ORDER BY display_order, id
            LIMIT 1 OFFSET " . ($rank - 1));
        $match->execute([$nextGradeId, $targetYearId]);
        $value = $match->fetchColumn();
        return $value !== false ? (int) $value : null;
    }

    private static function getYearName(PDO $db, int $yearId): string
    {
        $stmt = $db->prepare("SELECT name FROM academic_years WHERE id = ? LIMIT 1");
        $stmt->execute([$yearId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private static function carryForwardBalances(
        PDO $db,
        int $sourceYearId,
        int $targetYearId,
        array &$insertedAuditItems,
        array &$updatedAuditItems
    ): int
    {
        $count = 0;
        $yearName = self::getYearName($db, $sourceYearId);

        $rows = $db->prepare("SELECT student_id, final_amount, total_paid, balance
            FROM student_fees
            WHERE academic_year = ?
              AND balance > 0");
        $rows->execute([$yearName]);

        $insert = $db->prepare("INSERT INTO student_fee_balances_history
            (student_id, academic_year_id, total_due, total_paid, balance, carried_forward)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                total_due = VALUES(total_due),
                total_paid = VALUES(total_paid),
                balance = VALUES(balance),
                carried_forward = 1");

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $beforeStmt = $db->prepare('SELECT * FROM student_fee_balances_history WHERE student_id = ? AND academic_year_id = ? FOR UPDATE');
            $beforeStmt->execute([(int)$row['student_id'], $sourceYearId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $insert->execute([
                $row['student_id'],
                $sourceYearId,
                $row['final_amount'] ?? 0,
                $row['total_paid'] ?? 0,
                $row['balance'] ?? 0,
            ]);
            $afterStmt = $db->prepare('SELECT * FROM student_fee_balances_history WHERE student_id = ? AND academic_year_id = ?');
            $afterStmt->execute([(int)$row['student_id'], $sourceYearId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('Carried fee balance could not be reloaded.');
            if ($before === null) {
                $insertedAuditItems[] = self::replacementItem('student_fee_balances_history', $after, 'ترحيل رصيد مالي إلى سجل العام');
            } elseif ($before != $after) {
                $updatedAuditItems[] = self::updateItem(
                    'student_fee_balances_history', $after['id'] ?? $row['student_id'], $before, $after, 'تحديث رصيد مالي مرحل'
                );
            }
            $count++;
        }

        return $count;
    }

    private static function enrollmentAuditItem(PDO $db, int $studentId, int $yearId): array
    {
        $stmt = $db->prepare('SELECT * FROM student_enrollments WHERE student_id = ? AND academic_year_id = ?');
        $stmt->execute([$studentId, $yearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('New-year enrollment could not be reloaded.');
        return self::replacementItem('student_enrollments', $row, 'إنشاء تسجيل طالب للعام الجديد');
    }

    private static function replacementItem(string $table, array $row, string $description): array
    {
        if (!$row || !isset($row['id'])) throw new RuntimeException('Audited inserted row has no primary identifier.');
        return ['table' => $table, 'record_id' => $row['id'], 'snapshot' => $row, 'description' => $description];
    }

    private static function updateItem(string $table, $recordId, array $before, array $after, string $description): array
    {
        return ['table' => $table, 'record_id' => $recordId, 'before' => $before, 'after' => $after, 'description' => $description];
    }

    private static function fetchById(PDO $db, string $table, int $id): array
    {
        $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private static function fetchByIdForUpdate(PDO $db, string $table, int $id): array
    {
        $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
