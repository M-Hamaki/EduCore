<?php
declare(strict_types=1);

require_once __DIR__ . '/ActivityLog.php';

/**
 * Keeps teacher assignment activation safe when subject/class links are
 * created, changed, disabled, or removed. A pending assignment keeps the
 * requested permissions, but never grants access until its exact scope has
 * an active subject link.
 */
final class AssessmentTeacherAssignmentActivationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{activated: int, pending: int, suspended: int, unchanged: int, transitions: list<array<string, mixed>>}
     */
    public function synchronize(int $academicYearId, ?int $subjectId = null, array $auditContext = []): array
    {
        $sql = 'SELECT id, academic_year_id, term_id, teacher_id, subject_id, grade_id, class_id,
                       is_active, requested_active, pending_reason
                FROM teacher_subject_assignments
                WHERE academic_year_id = :academic_year_id';
        $params = ['academic_year_id' => $academicYearId];
        if ($subjectId !== null) {
            $sql .= ' AND subject_id = :subject_id';
            $params['subject_id'] = $subjectId;
        }

        $statement = $this->pdo->prepare($sql . ' FOR UPDATE');
        $statement->execute($params);

        $result = [
            'activated' => 0,
            'pending' => 0,
            'suspended' => 0,
            'unchanged' => 0,
            'transitions' => [],
        ];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $requestedActive = (int) $assignment['requested_active'] === 1;
            $hasLink = $requestedActive && $this->hasActiveSubjectLink($assignment);
            $effectiveActive = $requestedActive && $hasLink;
            $pendingReason = $requestedActive && !$hasLink ? 'missing_subject_link' : null;

            if ((int) $assignment['is_active'] === (int) $effectiveActive
                && (string) ($assignment['pending_reason'] ?? '') === (string) ($pendingReason ?? '')) {
                ++$result['unchanged'];
                continue;
            }

            $update = $this->pdo->prepare(
                'UPDATE teacher_subject_assignments
                 SET is_active = :is_active, pending_reason = :pending_reason
                 WHERE id = :id'
            );
            $update->execute([
                'is_active' => $effectiveActive ? 1 : 0,
                'pending_reason' => $pendingReason,
                'id' => (int) $assignment['id'],
            ]);

            $transition = [
                'assignment_id' => (int) $assignment['id'],
                'teacher_id' => (int) $assignment['teacher_id'],
                'subject_id' => (int) $assignment['subject_id'],
                'grade_id' => $assignment['grade_id'] === null ? null : (int) $assignment['grade_id'],
                'class_id' => $assignment['class_id'] === null ? null : (int) $assignment['class_id'],
                'from_active' => (int) $assignment['is_active'] === 1,
                'to_active' => $effectiveActive,
                'reason' => $pendingReason,
            ];
            $result['transitions'][] = $transition;

            $before = [
                'requested_active' => $requestedActive ? 1 : 0,
                'is_active' => (int) $assignment['is_active'] === 1 ? 1 : 0,
                'pending_reason' => $assignment['pending_reason'] ?: null,
            ];
            $after = [
                'requested_active' => $requestedActive ? 1 : 0,
                'is_active' => $effectiveActive ? 1 : 0,
                'pending_reason' => $pendingReason,
            ];
            if (!ActivityLog::logChange(
                'update',
                'teacher_subject_assignment',
                (int) $assignment['id'],
                'مزامنة تعيين معلم بالمادة',
                $before,
                $after,
                $auditContext
            )) {
                throw new RuntimeException('تعذر تسجيل مزامنة تعيين المعلم في سجل التدقيق.');
            }

            if ($effectiveActive) {
                ++$result['activated'];
            } elseif ($requestedActive) {
                ++$result['pending'];
            } else {
                ++$result['suspended'];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $assignment */
    private function hasActiveSubjectLink(array $assignment): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM subject_grade_assignments sga
             JOIN subjects s ON s.id = sga.subject_id
             WHERE sga.academic_year_id = :academic_year_id
               AND sga.subject_id = :subject_id
               AND sga.is_active = 1
               AND COALESCE(s.is_active, 1) = 1
               AND (sga.grade_id IS NULL OR sga.grade_id = :grade_id)
               AND (sga.class_id IS NULL OR sga.class_id = :class_id)
               AND (
                    sga.term_id IS NULL
                    OR (
                        :assignment_term_id IS NOT NULL
                        AND sga.term_id = :assignment_term_id
                    )
               )
             LIMIT 1'
        );
        $statement->execute([
            'academic_year_id' => (int) $assignment['academic_year_id'],
            'subject_id' => (int) $assignment['subject_id'],
            'grade_id' => $assignment['grade_id'] === null ? null : (int) $assignment['grade_id'],
            'class_id' => $assignment['class_id'] === null ? null : (int) $assignment['class_id'],
            'assignment_term_id' => $assignment['term_id'] === null ? null : (int) $assignment['term_id'],
        ]);

        return $statement->fetchColumn() !== false;
    }
}
