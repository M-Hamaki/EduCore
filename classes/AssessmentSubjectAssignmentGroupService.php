<?php

require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/AssessmentTeacherAssignmentActivationService.php';
require_once __DIR__ . '/AssessmentSchemeReadinessService.php';
require_once __DIR__ . '/AssessmentSchemeScopeResolver.php';

final class AssessmentSubjectAssignmentGroupService
{
    private PDO $db;
    private AssessmentSchemeScopeResolver $scopeResolver;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->scopeResolver = new AssessmentSchemeScopeResolver($db);
        ActivityLog::setDb($db);
    }

    public function add(array $input, int $currentAcademicYearId, int $actorId): array
    {
        $academicYearId = $currentAcademicYearId > 0
            ? $currentAcademicYearId
            : (int) ($input['academic_year_id'] ?? 0);
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $termId = !empty($input['term_id']) ? (int) $input['term_id'] : null;
        $allGradeIds = $this->intList($input['all_grade_ids'] ?? $input['grade_ids'] ?? []);
        $classIds = $this->intList($input['class_ids'] ?? []);
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($academicYearId <= 0 || $subjectId <= 0) {
            throw new InvalidArgumentException('اختر العام الدراسي والمادة.');
        }
        if (empty($allGradeIds) && empty($classIds)) {
            throw new InvalidArgumentException('اختر صفاً كاملاً أو فصلاً واحداً على الأقل.');
        }

        $subjectName = $this->loadSubjectName($subjectId);
        $this->assertTermBelongsToYear($termId, $academicYearId);

        $classRows = $this->loadClasses($classIds);
        $classGradeMap = [];
        $classNameMap = [];
        foreach ($classRows as $classRow) {
            $classId = (int) $classRow['id'];
            $classGradeMap[$classId] = (int) $classRow['grade_id'];
            $classNameMap[$classId] = (string) $classRow['name'];
        }

        $desiredGradeIds = $allGradeIds;
        foreach ($classGradeMap as $classGradeId) {
            $desiredGradeIds[] = $classGradeId;
        }
        $desiredGradeIds = array_values(array_unique($desiredGradeIds));
        $gradeMap = $this->loadGrades($desiredGradeIds);
        $desiredScopes = $this->buildDesiredScopes(
            $allGradeIds,
            $classIds,
            $classGradeMap,
            $classNameMap,
            $gradeMap
        );

        try {
            $this->db->beginTransaction();
            $existingAssignments = $this->loadTargetGroupForUpdate($academicYearId, $subjectId, $termId);
            $existingByGrade = [];
            foreach ($existingAssignments as $assignment) {
                $existingByGrade[(int) $assignment['grade_id']][] = $assignment;
            }

            $matches = [];
            foreach ($desiredScopes as $scope) {
                $gradeId = (int) $scope['grade_id'];
                $classId = $scope['class_id'] !== null ? (int) $scope['class_id'] : null;
                $exactAssignment = null;

                foreach ($existingByGrade[$gradeId] ?? [] as $existingAssignment) {
                    $existingClassId = $existingAssignment['class_id'] !== null
                        ? (int) $existingAssignment['class_id']
                        : null;
                    $overlaps = $classId === null || $existingClassId === null || $existingClassId === $classId;
                    if (!$overlaps) {
                        continue;
                    }
                    if ($existingClassId !== $classId) {
                        throw new InvalidArgumentException(
                            'يوجد ربط سابق يغطي ' . $scope['grade_name']
                            . ' بنطاق مختلف. عدّل المجموعة الموجودة بدلاً من إنشاء نطاق متداخل.'
                        );
                    }
                    $exactAssignment = $existingAssignment;
                }

                $matches[] = ['scope' => $scope, 'old' => $exactAssignment];
            }

            $result = $this->persistChanges(
                $matches,
                [],
                $academicYearId,
                $termId,
                $subjectId,
                $subjectName,
                'active',
                'replace',
                $notes,
                $actorId
            );
            $result['teacher_activation'] = $this->synchronizeTeacherAssignments(
                $academicYearId,
                $subjectId,
                ['batch_id' => (string) $result['batch_id']]
            );
            $result['scheme_readiness'] = $this->synchronizeSchemeReadiness(
                $academicYearId,
                $subjectId,
                ['batch_id' => (string) $result['batch_id']]
            );
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function setStatus(array $input, int $currentAcademicYearId): int
    {
        [$academicYearId, $subjectId, $termId] = $this->groupIdentity($input, $currentAcademicYearId);
        $targetStatus = (string) ($input['target_status'] ?? '');
        if (!in_array($targetStatus, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('الحالة المطلوبة للمجموعة غير صحيحة.');
        }
        $targetActive = $targetStatus === 'active' ? 1 : 0;
        $subjectName = $this->loadSubjectName($subjectId, $targetActive === 1);

        try {
            $this->db->beginTransaction();
            $assignments = $this->loadGroupForUpdate($academicYearId, $subjectId, $termId);
            if (empty($assignments)) {
                throw new InvalidArgumentException('مجموعة روابط المادة المحددة لم تعد موجودة.');
            }

            $batchId = bin2hex(random_bytes(16));
            $context = ['batch_id' => $batchId];
            $updateStmt = $this->db->prepare('UPDATE subject_grade_assignments SET is_active = ? WHERE id = ?');
            $updated = 0;
            foreach ($assignments as $assignment) {
                if ((!empty($assignment['is_active']) ? 1 : 0) === $targetActive) {
                    continue;
                }
                $before = $this->normalizedRow($assignment);
                $after = $before;
                $after['is_active'] = $targetActive;
                $id = (int) $assignment['id'];
                $updateStmt->execute([$targetActive, $id]);
                $this->mustAudit(ActivityLog::logChange(
                    'update',
                    'subject_grade_assignment',
                    $id,
                    $subjectName . ' - ' . $assignment['grade_name'],
                    $before,
                    $after,
                    $context
                ));
                $updated++;
            }

            $this->synchronizeTeacherAssignments($academicYearId, $subjectId, $context);
            $this->synchronizeSchemeReadiness($academicYearId, $subjectId, $context);
            $this->db->commit();
            return $updated;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteGroup(array $input, int $currentAcademicYearId, int $actorId): int
    {
        [$academicYearId, $subjectId, $termId] = $this->groupIdentity($input, $currentAcademicYearId);
        $subjectName = $this->loadSubjectName($subjectId, false);

        try {
            $this->db->beginTransaction();
            $assignments = $this->loadGroupForUpdate($academicYearId, $subjectId, $termId);
            if (empty($assignments)) {
                throw new InvalidArgumentException('مجموعة روابط المادة المحددة لم تعد موجودة.');
            }
            $this->assertRemovalsAndMovesAreSafe($assignments, [], false);
            $result = $this->persistChanges(
                [],
                $assignments,
                $academicYearId,
                $termId,
                $subjectId,
                $subjectName,
                'preserve',
                'preserve',
                '',
                $actorId
            );
            $this->synchronizeSchemeReadiness(
                $academicYearId,
                $subjectId,
                ['batch_id' => (string) $result['batch_id']]
            );
            $this->db->commit();
            return (int) $result['deleted'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function sync(array $input, int $currentAcademicYearId, int $actorId): array
    {
        $academicYearId = $currentAcademicYearId > 0
            ? $currentAcademicYearId
            : (int) ($input['academic_year_id'] ?? 0);
        $originalAcademicYearId = (int) ($input['original_academic_year_id'] ?? 0);
        $originalSubjectId = (int) ($input['original_subject_id'] ?? 0);
        $originalTermId = !empty($input['original_term_id']) ? (int) $input['original_term_id'] : null;
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $termId = !empty($input['term_id']) ? (int) $input['term_id'] : null;
        $allGradeIds = $this->intList($input['all_grade_ids'] ?? []);
        $classIds = $this->intList($input['class_ids'] ?? []);
        $statusMode = (string) ($input['status_mode'] ?? 'preserve');
        $notesMode = (string) ($input['notes_mode'] ?? 'preserve');
        $notes = trim((string) ($input['notes'] ?? ''));

        if (!in_array($statusMode, ['preserve', 'active', 'inactive'], true)) {
            throw new InvalidArgumentException('خيار حالة الروابط غير صحيح.');
        }
        if (!in_array($notesMode, ['preserve', 'replace'], true)) {
            throw new InvalidArgumentException('خيار تحديث الملاحظات غير صحيح.');
        }
        if (
            $academicYearId <= 0
            || $originalAcademicYearId <= 0
            || $originalSubjectId <= 0
            || $subjectId <= 0
        ) {
            throw new InvalidArgumentException('بيانات مجموعة روابط المادة غير مكتملة.');
        }
        if ($academicYearId !== $originalAcademicYearId) {
            throw new InvalidArgumentException('لا يمكن نقل مجموعة الروابط إلى عام دراسي آخر.');
        }
        if (empty($allGradeIds) && empty($classIds)) {
            throw new InvalidArgumentException('اختر صفاً كاملاً أو فصلاً واحداً على الأقل.');
        }

        $subjectName = $this->loadSubjectName($subjectId);
        $this->assertTermBelongsToYear($termId, $academicYearId);

        $classRows = $this->loadClasses($classIds);
        $classGradeMap = [];
        $classNameMap = [];
        foreach ($classRows as $classRow) {
            $classId = (int) $classRow['id'];
            $classGradeMap[$classId] = (int) $classRow['grade_id'];
            $classNameMap[$classId] = (string) $classRow['name'];
        }

        $desiredGradeIds = $allGradeIds;
        foreach ($classGradeMap as $classGradeId) {
            $desiredGradeIds[] = $classGradeId;
        }
        $desiredGradeIds = array_values(array_unique($desiredGradeIds));
        $gradeMap = $this->loadGrades($desiredGradeIds);
        $desiredScopes = $this->buildDesiredScopes(
            $allGradeIds,
            $classIds,
            $classGradeMap,
            $classNameMap,
            $gradeMap
        );

        try {
            $this->db->beginTransaction();
            $oldAssignments = $this->loadGroupForUpdate(
                $originalAcademicYearId,
                $originalSubjectId,
                $originalTermId
            );
            if (empty($oldAssignments)) {
                throw new InvalidArgumentException('مجموعة روابط المادة المحددة لم تعد موجودة.');
            }

            $oldAssignmentIds = array_map('intval', array_column($oldAssignments, 'id'));
            $oldAssignmentLookup = array_fill_keys($oldAssignmentIds, true);
            $targetAssignments = $this->loadTargetGroupForUpdate($academicYearId, $subjectId, $termId);
            $externalTargetAssignments = array_values(array_filter(
                $targetAssignments,
                static fn(array $row): bool => !isset($oldAssignmentLookup[(int) $row['id']])
            ));
            if (
                ($subjectId !== $originalSubjectId || $termId !== $originalTermId)
                && !empty($externalTargetAssignments)
            ) {
                throw new InvalidArgumentException(
                    'توجد مجموعة روابط أخرى للمادة والترم المختارين. عدّل المجموعة الموجودة بدلاً من دمج مجموعتين تلقائياً.'
                );
            }

            [$matches, $removedAssignments] = $this->matchScopes($oldAssignments, $desiredScopes);
            $this->assertRemovalsAndMovesAreSafe(
                $removedAssignments,
                $matches,
                $subjectId !== $originalSubjectId || $termId !== $originalTermId
            );

            $result = $this->persistChanges(
                $matches,
                $removedAssignments,
                $academicYearId,
                $termId,
                $subjectId,
                $subjectName,
                $statusMode,
                $notesMode,
                $notes,
                $actorId
            );
            $result['teacher_activation'] = $this->synchronizeTeacherAssignments(
                $academicYearId,
                $subjectId,
                ['batch_id' => (string) $result['batch_id']]
            );
            $result['scheme_readiness'] = $this->synchronizeSchemeReadiness(
                $academicYearId,
                $subjectId,
                ['batch_id' => (string) $result['batch_id']]
            );
            if ($originalSubjectId !== $subjectId) {
                $result['previous_subject_teacher_activation'] = $this->synchronizeTeacherAssignments(
                    $academicYearId,
                    $originalSubjectId,
                    ['batch_id' => (string) $result['batch_id']]
                );
                $result['previous_subject_scheme_readiness'] = $this->synchronizeSchemeReadiness(
                    $originalAcademicYearId,
                    $originalSubjectId,
                    ['batch_id' => (string) $result['batch_id']]
                );
            }
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{activated:int,pending:int,suspended:int,unchanged:int,transitions:list<array<string,mixed>>} */
    private function synchronizeTeacherAssignments(
        int $academicYearId,
        int $subjectId,
        array $auditContext = []
    ): array
    {
        if (!$this->tableExists('teacher_subject_assignments')) {
            return ['activated' => 0, 'pending' => 0, 'suspended' => 0, 'unchanged' => 0, 'transitions' => []];
        }

        $columnStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME IN (?, ?)'
        );
        $columnStmt->execute(['teacher_subject_assignments', 'requested_active', 'pending_reason']);
        if ((int) $columnStmt->fetchColumn() !== 2) {
            return ['activated' => 0, 'pending' => 0, 'suspended' => 0, 'unchanged' => 0, 'transitions' => []];
        }

        $auditContext['batch_id'] = !empty($auditContext['batch_id'])
            ? (string) $auditContext['batch_id']
            : bin2hex(random_bytes(16));
        $auditContext['source'] = 'subject_assignment_group';

        return (new AssessmentTeacherAssignmentActivationService($this->db))->synchronize(
            $academicYearId,
            $subjectId,
            $auditContext
        );
    }

    /** @return list<array{scheme_id:int,status:string,reason:?string,changed:bool}> */
    private function synchronizeSchemeReadiness(
        int $academicYearId,
        int $subjectId,
        array $auditContext = []
    ): array {
        return (new AssessmentSchemeReadinessService($this->db))->refreshForSubject(
            $academicYearId,
            $subjectId,
            !empty($auditContext['batch_id']) ? (string) $auditContext['batch_id'] : null,
            true
        );
    }

    private function intList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function loadClasses(array $classIds): array
    {
        if (empty($classIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT c.id, c.name, c.grade_id
            FROM classes c
            JOIN grades g ON g.id = c.grade_id
            WHERE c.id IN ($placeholders)
              AND c.status = 'active'
              AND g.status = 'active'");
        $stmt->execute($classIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== count($classIds)) {
            throw new InvalidArgumentException('يوجد فصل مختار غير صحيح أو غير نشط.');
        }
        return $rows;
    }

    private function loadGrades(array $gradeIds): array
    {
        $placeholders = implode(',', array_fill(0, count($gradeIds), '?'));
        $stmt = $this->db->prepare("SELECT id, grade_name, stage_id
            FROM grades
            WHERE id IN ($placeholders) AND status = 'active'");
        $stmt->execute($gradeIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== count($gradeIds)) {
            throw new InvalidArgumentException('يوجد صف مختار غير صحيح أو غير نشط.');
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    private function buildDesiredScopes(
        array $allGradeIds,
        array $classIds,
        array $classGradeMap,
        array $classNameMap,
        array $gradeMap
    ): array {
        $allGradeLookup = array_fill_keys($allGradeIds, true);
        $scopes = [];
        foreach ($allGradeIds as $gradeId) {
            $scopes[$this->scopeKey($gradeId, null)] = [
                'grade_id' => $gradeId,
                'class_id' => null,
                'stage_id' => (int) $gradeMap[$gradeId]['stage_id'],
                'grade_name' => (string) $gradeMap[$gradeId]['grade_name'],
                'class_name' => 'الصف بالكامل',
            ];
        }
        foreach ($classIds as $classId) {
            $gradeId = $classGradeMap[$classId] ?? 0;
            if ($gradeId <= 0) {
                throw new InvalidArgumentException('أحد الفصول المختارة لا يتبع صفاً صحيحاً.');
            }
            if (isset($allGradeLookup[$gradeId])) {
                throw new InvalidArgumentException('لا يمكن اختيار الصف بالكامل وفصول محددة منه في الوقت نفسه.');
            }
            $scopes[$this->scopeKey($gradeId, $classId)] = [
                'grade_id' => $gradeId,
                'class_id' => $classId,
                'stage_id' => (int) $gradeMap[$gradeId]['stage_id'],
                'grade_name' => (string) $gradeMap[$gradeId]['grade_name'],
                'class_name' => (string) ($classNameMap[$classId] ?? $classId),
            ];
        }
        return $scopes;
    }

    private function loadGroupForUpdate(int $yearId, int $subjectId, ?int $termId): array
    {
        $stmt = $this->db->prepare("SELECT sga.*, g.grade_name, c.name AS class_name
            FROM subject_grade_assignments sga
            JOIN grades g ON g.id = sga.grade_id
            LEFT JOIN classes c ON c.id = sga.class_id
            WHERE sga.academic_year_id = ?
              AND sga.subject_id = ?
              AND sga.term_id <=> ?
            ORDER BY sga.id
            FOR UPDATE");
        $stmt->execute([$yearId, $subjectId, $termId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadTargetGroupForUpdate(int $yearId, int $subjectId, ?int $termId): array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM subject_grade_assignments
            WHERE academic_year_id = ?
              AND subject_id = ?
              AND term_id <=> ?
            FOR UPDATE");
        $stmt->execute([$yearId, $subjectId, $termId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function matchScopes(array $oldAssignments, array $desiredScopes): array
    {
        $oldByScope = [];
        foreach ($oldAssignments as $oldAssignment) {
            $classId = $oldAssignment['class_id'] !== null ? (int) $oldAssignment['class_id'] : null;
            $oldByScope[$this->scopeKey((int) $oldAssignment['grade_id'], $classId)][] = $oldAssignment;
        }

        $matches = [];
        $consumedIds = [];
        foreach ($desiredScopes as $scopeKey => $scope) {
            $oldAssignment = null;
            if (!empty($oldByScope[$scopeKey])) {
                $oldAssignment = array_shift($oldByScope[$scopeKey]);
                $consumedIds[(int) $oldAssignment['id']] = true;
            }
            $matches[] = ['scope' => $scope, 'old' => $oldAssignment];
        }
        $removed = array_values(array_filter(
            $oldAssignments,
            static fn(array $row): bool => !isset($consumedIds[(int) $row['id']])
        ));
        return [$matches, $removed];
    }

    private function assertRemovalsAndMovesAreSafe(array $removed, array $matches, bool $movesGroup): void
    {
        $schemeStmt = $this->tableExists('assessment_schemes')
            ? $this->db->prepare('SELECT COUNT(*) FROM assessment_schemes WHERE subject_assignment_id = ?')
            : null;
        $teacherStmt = $this->tableExists('teacher_subject_assignments')
            ? $this->db->prepare("SELECT COUNT(*)
                FROM teacher_subject_assignments
                WHERE academic_year_id = ?
                  AND subject_id = ?
                  AND (term_id <=> ? OR term_id IS NULL OR ? IS NULL)
                  AND (grade_id <=> ? OR grade_id IS NULL)
                  AND (class_id <=> ? OR class_id IS NULL)
                  AND is_active = 1")
            : null;

        $blocked = [];
        foreach ($removed as $assignment) {
            if ($this->dependencyCount($assignment, $schemeStmt, $teacherStmt) > 0) {
                $blocked[] = $this->scopeLabel($assignment);
            }
        }
        if ($movesGroup) {
            foreach ($matches as $match) {
                if ($match['old'] && $this->dependencyCount($match['old'], $schemeStmt, $teacherStmt) > 0) {
                    $blocked[] = $this->scopeLabel($match['old']);
                }
            }
        }
        $blocked = array_values(array_unique($blocked));
        if (!empty($blocked)) {
            throw new RuntimeException(
                'لا يمكن إزالة أو نقل الروابط التالية لأنها مستخدمة في خطط درجات أو تعيينات معلمين: '
                . implode('، ', $blocked)
                . '. اتركها محددة أو عطّل المجموعة بدلاً من حذفها.'
            );
        }
    }

    private function groupIdentity(array $input, int $currentAcademicYearId): array
    {
        $academicYearId = $currentAcademicYearId > 0
            ? $currentAcademicYearId
            : (int) ($input['academic_year_id'] ?? 0);
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $termId = !empty($input['term_id']) ? (int) $input['term_id'] : null;
        if ($academicYearId <= 0 || $subjectId <= 0) {
            throw new InvalidArgumentException('بيانات مجموعة روابط المادة غير مكتملة.');
        }
        $this->assertTermBelongsToYear($termId, $academicYearId);
        return [$academicYearId, $subjectId, $termId];
    }

    private function loadSubjectName(int $subjectId, bool $requireActive = true): string
    {
        $sql = 'SELECT name FROM subjects WHERE id = ?';
        if ($requireActive) {
            $sql .= ' AND COALESCE(is_active, 1) = 1';
        }
        $subjectStmt = $this->db->prepare($sql . ' LIMIT 1');
        $subjectStmt->execute([$subjectId]);
        $subjectName = (string) $subjectStmt->fetchColumn();
        if ($subjectName === '') {
            throw new InvalidArgumentException('المادة المختارة غير صحيحة أو غير نشطة.');
        }
        return $subjectName;
    }

    private function assertTermBelongsToYear(?int $termId, int $academicYearId): void
    {
        if ($termId === null) {
            return;
        }
        $termStmt = $this->db->prepare('SELECT academic_year_id FROM academic_terms WHERE id = ? LIMIT 1');
        $termStmt->execute([$termId]);
        if ((int) $termStmt->fetchColumn() !== $academicYearId) {
            throw new InvalidArgumentException('الترم المختار لا يتبع العام الدراسي المحدد.');
        }
    }

    private function dependencyCount(array $assignment, ?PDOStatement $schemeStmt, ?PDOStatement $teacherStmt): int
    {
        $count = 0;
        if ($schemeStmt) {
            $schemeStmt->execute([(int) $assignment['id']]);
            $count += (int) $schemeStmt->fetchColumn();
        }
        $count += $this->scopeResolver->countSchemesDependentOnSubjectAssignment($assignment);
        if ($teacherStmt) {
            $teacherStmt->execute([
                (int) $assignment['academic_year_id'],
                (int) $assignment['subject_id'],
                $assignment['term_id'],
                $assignment['term_id'],
                $assignment['grade_id'],
                $assignment['class_id'],
            ]);
            $count += (int) $teacherStmt->fetchColumn();
        }
        return $count;
    }

    private function persistChanges(
        array $matches,
        array $removed,
        int $yearId,
        ?int $termId,
        int $subjectId,
        string $subjectName,
        string $statusMode,
        string $notesMode,
        string $notes,
        int $actorId
    ): array {
        $batchId = bin2hex(random_bytes(16));
        $context = ['batch_id' => $batchId];
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'batch_id' => $batchId];

        $deleteStmt = $this->db->prepare('DELETE FROM subject_grade_assignments WHERE id = ?');
        foreach ($removed as $assignment) {
            $id = (int) $assignment['id'];
            $deleteStmt->execute([$id]);
            $this->mustAudit(ActivityLog::log(
                'delete',
                'subject_grade_assignment',
                $id,
                $subjectName . ' - ' . $assignment['grade_name'],
                $this->normalizedRow($assignment),
                $context
            ));
            $counts['deleted']++;
        }

        $updateStmt = $this->db->prepare("UPDATE subject_grade_assignments
            SET academic_year_id = ?, term_id = ?, subject_id = ?, stage_id = ?,
                grade_id = ?, class_id = ?, is_active = ?, notes = ?
            WHERE id = ?");
        $insertStmt = $this->db->prepare("INSERT INTO subject_grade_assignments
            (academic_year_id, term_id, subject_id, stage_id, grade_id, class_id, is_active, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($matches as $match) {
            $scope = $match['scope'];
            $old = $match['old'];
            $isActive = $statusMode === 'inactive' ? 0 : 1;
            if ($statusMode === 'preserve' && $old) {
                $isActive = !empty($old['is_active']) ? 1 : 0;
            }
            $storedNotes = $notesMode === 'replace' ? ($notes !== '' ? $notes : null) : null;
            if ($notesMode === 'preserve' && $old) {
                $storedNotes = $old['notes'];
            }
            $after = [
                'academic_year_id' => $yearId,
                'term_id' => $termId,
                'subject_id' => $subjectId,
                'stage_id' => (int) $scope['stage_id'],
                'grade_id' => (int) $scope['grade_id'],
                'class_id' => $scope['class_id'],
                'is_active' => $isActive,
                'notes' => $storedNotes,
            ];

            if ($old) {
                $id = (int) $old['id'];
                $before = $this->normalizedRow($old);
                if ($before !== $after) {
                    $updateStmt->execute([
                        $after['academic_year_id'],
                        $after['term_id'],
                        $after['subject_id'],
                        $after['stage_id'],
                        $after['grade_id'],
                        $after['class_id'],
                        $after['is_active'],
                        $after['notes'],
                        $id,
                    ]);
                    $this->mustAudit(ActivityLog::logChange(
                        'update',
                        'subject_grade_assignment',
                        $id,
                        $subjectName . ' - ' . $scope['grade_name'],
                        $before,
                        $after,
                        $context
                    ));
                    $counts['updated']++;
                }
                continue;
            }

            $insertStmt->execute([
                $after['academic_year_id'],
                $after['term_id'],
                $after['subject_id'],
                $after['stage_id'],
                $after['grade_id'],
                $after['class_id'],
                $after['is_active'],
                $after['notes'],
                $actorId > 0 ? $actorId : null,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->mustAudit(ActivityLog::log(
                'create',
                'subject_grade_assignment',
                $id,
                $subjectName . ' - ' . $scope['grade_name'],
                $after,
                $context
            ));
            $counts['created']++;
        }
        return $counts;
    }

    private function normalizedRow(array $row): array
    {
        return [
            'academic_year_id' => (int) $row['academic_year_id'],
            'term_id' => $row['term_id'] !== null ? (int) $row['term_id'] : null,
            'subject_id' => (int) $row['subject_id'],
            'stage_id' => $row['stage_id'] !== null ? (int) $row['stage_id'] : null,
            'grade_id' => (int) $row['grade_id'],
            'class_id' => $row['class_id'] !== null ? (int) $row['class_id'] : null,
            'is_active' => !empty($row['is_active']) ? 1 : 0,
            'notes' => $row['notes'],
        ];
    }

    private function scopeLabel(array $assignment): string
    {
        return (string) $assignment['grade_name']
            . ($assignment['class_name'] !== null ? ' - ' . $assignment['class_name'] : ' - الصف بالكامل');
    }

    private function scopeKey(int $gradeId, ?int $classId): string
    {
        return $gradeId . ':' . ($classId ?? 0);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function mustAudit(bool $logged): void
    {
        if (!$logged) {
            throw new RuntimeException('تعذر تسجيل العملية في سجل التدقيق.');
        }
    }
}
