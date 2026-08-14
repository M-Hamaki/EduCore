<?php

declare(strict_types=1);

require_once __DIR__ . '/UndoManager.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;

/**
 * Owns the durable class plan used by NewYearRolloverService.
 *
 * Grade promotion decisions remain with the rollover orchestrator; this class
 * only plans, validates, and materializes target-year class containers.
 */
final class ClassRolloverPlanService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function matrix(
        int $sourceYearId,
        int $targetYearId,
        array $rules,
        array $officialGrades
    ): array {
        $expected = $this->expectedMappings($sourceYearId, $rules, $officialGrades);
        $existing = $this->mappings($sourceYearId, $targetYearId);
        $studentCounts = $this->sourceClassStudentCounts($sourceYearId);
        $rows = [];
        $ready = true;

        foreach ($expected as $key => $plan) {
            $saved = $existing[$key] ?? null;
            $isSaved = $saved !== null
                && (string) ($saved['status'] ?? '') === 'active'
                && (int) ($saved['source_grade_id'] ?? 0) === (int) $plan['source_grade_id']
                && (int) ($saved['target_grade_id'] ?? 0) === (int) $plan['target_grade_id']
                && trim((string) ($saved['target_name'] ?? '')) !== '';
            $ready = $ready && $isSaved;
            $rows[] = array_merge($plan, [
                'target_name' => $isSaved ? (string) $saved['target_name'] : (string) $plan['source_class_name'],
                'target_capacity' => $isSaved
                    ? ($saved['target_capacity'] !== null ? (int) $saved['target_capacity'] : null)
                    : ($plan['source_capacity'] !== null ? (int) $plan['source_capacity'] : null),
                'auto_place_students' => $isSaved
                    ? (int) $saved['auto_place_students'] === 1
                    : $plan['mapping_type'] === 'cohort',
                'is_enabled' => $isSaved ? (int) $saved['is_enabled'] === 1 : true,
                'saved' => $isSaved,
                'student_count' => (int) ($studentCounts[(int) $plan['source_class_id']] ?? 0),
            ]);
        }
        $staleCount = 0;
        foreach ($existing as $key => $mapping) {
            if (!isset($expected[$key]) && (string) ($mapping['status'] ?? '') === 'active') {
                $staleCount++;
                $ready = false;
            }
        }

        return [
            'mappings' => $rows,
            'ready' => $ready,
            'expected_count' => count($rows),
            'stale_count' => $staleCount,
            'entry_template_count' => count(array_filter(
                $rows,
                static fn(array $row): bool => $row['mapping_type'] === 'entry_template'
            )),
        ];
    }

    public function save(
        int $sourceYearId,
        int $targetYearId,
        array $rules,
        array $officialGrades,
        array $submittedMappings,
        ?int $actorId
    ): array {
        $expected = $this->expectedMappings($sourceYearId, $rules, $officialGrades);
        $normalized = [];
        $targetNames = [];

        foreach ($expected as $key => $plan) {
            $input = is_array($submittedMappings[$key] ?? null) ? $submittedMappings[$key] : [];
            $targetName = trim((string) ($input['target_name'] ?? ''));
            if ($targetName === '' || mb_strlen($targetName, 'UTF-8') > 100) {
                throw new InvalidArgumentException('اسم الفصل الهدف مطلوب وأقصى طول له 100 حرف.');
            }
            $capacityInput = trim((string) ($input['target_capacity'] ?? ''));
            $capacity = $capacityInput === '' ? null : (int) $capacityInput;
            if ($capacity !== null && ($capacity <= 0 || $capacity > 65535)) {
                throw new InvalidArgumentException('سعة الفصل يجب أن تكون بين 1 و65535 أو تترك فارغة.');
            }
            $enabled = (int) ($input['is_enabled'] ?? 0) === 1;
            $autoPlace = $plan['mapping_type'] === 'cohort'
                && $enabled
                && (int) ($input['auto_place_students'] ?? 0) === 1;
            if ($enabled) {
                $nameKey = (int) $plan['target_grade_id'] . ':' . mb_strtolower($targetName, 'UTF-8');
                if (isset($targetNames[$nameKey])) {
                    throw new InvalidArgumentException('لا يمكن إنشاء فصلين بالاسم نفسه داخل الصف الهدف؛ غيّر أحد الاسمين.');
                }
                $targetNames[$nameKey] = true;
            }
            $normalized[$key] = array_merge($plan, [
                'target_name' => $targetName,
                'target_capacity' => $capacity,
                'auto_place_students' => $autoPlace ? 1 : 0,
                'is_enabled' => $enabled ? 1 : 0,
            ]);
        }

        $ownsTransaction = !$this->db->inTransaction();
        $batchId = UndoManager::newBatchId();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $this->lockYears($sourceYearId, $targetYearId);
            $select = $this->db->prepare("SELECT * FROM class_rollover_mappings
                WHERE source_year_id = ? AND target_year_id = ? FOR UPDATE");
            $select->execute([$sourceYearId, $targetYearId]);
            $existing = [];
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $existing[$this->mappingKey((string) $row['mapping_type'], (int) $row['source_class_id'])] = $row;
            }
            $insert = $this->db->prepare("INSERT INTO class_rollover_mappings
                (source_year_id, target_year_id, source_class_id, mapping_type,
                 source_grade_id, target_grade_id, target_name, target_room_location,
                 target_capacity, target_display_order, auto_place_students, is_enabled,
                 status, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
            $update = $this->db->prepare("UPDATE class_rollover_mappings
                SET source_grade_id = ?, target_grade_id = ?, target_name = ?,
                    target_room_location = ?, target_capacity = ?, target_display_order = ?,
                    auto_place_students = ?, is_enabled = ?, status = 'active', updated_by = ?
                WHERE id = ?");
            $deactivate = $this->db->prepare("UPDATE class_rollover_mappings
                SET status = 'inactive', updated_by = ? WHERE id = ?");
            $audit = new AuditService($this->db);

            foreach ($normalized as $key => $plan) {
                $before = $existing[$key] ?? null;
                if ($before) {
                    $unchanged = (int) $before['source_grade_id'] === (int) $plan['source_grade_id']
                        && (int) $before['target_grade_id'] === (int) $plan['target_grade_id']
                        && (string) $before['target_name'] === (string) $plan['target_name']
                        && (string) ($before['target_room_location'] ?? '') === (string) ($plan['source_room_location'] ?? '')
                        && ($before['target_capacity'] !== null ? (int) $before['target_capacity'] : null) === $plan['target_capacity']
                        && (int) $before['target_display_order'] === (int) $plan['source_display_order']
                        && (int) $before['auto_place_students'] === (int) $plan['auto_place_students']
                        && (int) $before['is_enabled'] === (int) $plan['is_enabled']
                        && (string) $before['status'] === 'active';
                    if (!$unchanged) {
                        $update->execute([
                            $plan['source_grade_id'], $plan['target_grade_id'], $plan['target_name'],
                            $plan['source_room_location'], $plan['target_capacity'], $plan['source_display_order'],
                            $plan['auto_place_students'], $plan['is_enabled'], $actorId, (int) $before['id'],
                        ]);
                        $audit->recordUpdate(
                            'class_rollover_mapping',
                            'class_rollover_mappings',
                            (int) $before['id'],
                            'مسار الفصل ' . $plan['source_class_name'],
                            $before,
                            $this->rowById((int) $before['id']),
                            'تحديث خريطة انتقال فصل للعام الجديد',
                            $batchId
                        );
                    }
                } else {
                    $insert->execute([
                        $sourceYearId, $targetYearId, $plan['source_class_id'], $plan['mapping_type'],
                        $plan['source_grade_id'], $plan['target_grade_id'], $plan['target_name'],
                        $plan['source_room_location'], $plan['target_capacity'], $plan['source_display_order'],
                        $plan['auto_place_students'], $plan['is_enabled'], $actorId, $actorId,
                    ]);
                    $id = (int) $this->db->lastInsertId();
                    $audit->recordInsert(
                        'class_rollover_mapping',
                        'class_rollover_mappings',
                        $id,
                        'مسار الفصل ' . $plan['source_class_name'],
                        $this->rowById($id),
                        'إضافة خريطة انتقال فصل للعام الجديد',
                        $batchId
                    );
                }
                unset($existing[$key]);
            }

            foreach ($existing as $before) {
                if ((string) ($before['status'] ?? '') === 'inactive') {
                    continue;
                }
                $deactivate->execute([$actorId, (int) $before['id']]);
                $audit->recordUpdate(
                    'class_rollover_mapping',
                    'class_rollover_mappings',
                    (int) $before['id'],
                    'مسار فصل ملغى',
                    $before,
                    $this->rowById((int) $before['id']),
                    'إلغاء خريطة فصل لم تعد توافق قواعد الانتقال',
                    $batchId
                );
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $this->matrix($sourceYearId, $targetYearId, $rules, $officialGrades);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function validate(
        int $sourceYearId,
        int $targetYearId,
        array $rules,
        array $officialGrades,
        array $students,
        array $decisionsByEnrollment
    ): array {
        $expected = $this->expectedMappings($sourceYearId, $rules, $officialGrades);
        $stored = $this->mappings($sourceYearId, $targetYearId);
        $blockers = [];
        $warnings = [];
        $enabledCount = 0;
        $enabledByGrade = [];
        $autoPlace = [];
        $targetNames = [];

        foreach ($expected as $key => $plan) {
            $mapping = $stored[$key] ?? null;
            if (!$mapping || (string) ($mapping['status'] ?? '') !== 'active') {
                $blockers[] = [
                    'code' => 'class_mapping_missing',
                    'class_id' => (int) $plan['source_class_id'],
                    'message' => 'لم تُحفظ خريطة انتقال الفصل «' . $plan['source_class_name'] . '».',
                ];
                continue;
            }
            if ((int) $mapping['source_grade_id'] !== (int) $plan['source_grade_id']
                || (int) $mapping['target_grade_id'] !== (int) $plan['target_grade_id']
                || trim((string) $mapping['target_name']) === '') {
                $blockers[] = [
                    'code' => 'class_mapping_stale',
                    'class_id' => (int) $plan['source_class_id'],
                    'message' => 'خريطة الفصل «' . $plan['source_class_name'] . '» لا توافق قاعدة الصف الحالية.',
                ];
                continue;
            }
            if ((int) $mapping['is_enabled'] !== 1) {
                continue;
            }
            $enabledCount++;
            $targetGradeId = (int) $mapping['target_grade_id'];
            $enabledByGrade[$targetGradeId] = (int) ($enabledByGrade[$targetGradeId] ?? 0) + 1;
            $nameKey = $targetGradeId . ':' . mb_strtolower(trim((string) $mapping['target_name']), 'UTF-8');
            if (isset($targetNames[$nameKey])) {
                $blockers[] = [
                    'code' => 'class_target_name_duplicate',
                    'class_id' => (int) $plan['source_class_id'],
                    'message' => 'يوجد اسم فصل هدف مكرر داخل الصف نفسه.',
                ];
            }
            $targetNames[$nameKey] = true;
            if ((string) $mapping['mapping_type'] === 'cohort'
                && (int) $mapping['auto_place_students'] === 1) {
                $autoPlace[(int) $mapping['source_class_id']] = $mapping;
            }
        }
        foreach ($stored as $key => $mapping) {
            if (!isset($expected[$key]) && (string) ($mapping['status'] ?? '') === 'active') {
                $blockers[] = [
                    'code' => 'class_mapping_stale',
                    'class_id' => (int) $mapping['source_class_id'],
                    'message' => 'توجد خريطة فصل قديمة لا توافق قواعد الصفوف الحالية؛ احفظ خريطة الفصول من جديد.',
                ];
            }
        }

        $sourceClassRanks = [];
        $rankByGrade = [];
        foreach ($this->sourceClasses($sourceYearId) as $sourceClass) {
            $gradeId = (int) $sourceClass['grade_id'];
            $rank = (int) ($rankByGrade[$gradeId] ?? 0);
            $sourceClassRanks[(int) $sourceClass['id']] = ['grade_id' => $gradeId, 'rank' => $rank];
            $rankByGrade[$gradeId] = $rank + 1;
        }
        $assignmentClasses = $this->db->prepare("SELECT DISTINCT class_id
            FROM subject_grade_assignments
            WHERE academic_year_id = ? AND class_id IS NOT NULL");
        $assignmentClasses->execute([$sourceYearId]);
        foreach ($assignmentClasses->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sourceClassId) {
            $sourceClassId = (int) $sourceClassId;
            $placement = $sourceClassRanks[$sourceClassId] ?? null;
            if ($placement
                && (int) ($enabledByGrade[(int) $placement['grade_id']] ?? 0) <= (int) $placement['rank']) {
                $blockers[] = [
                    'code' => 'class_curriculum_mapping_missing',
                    'class_id' => $sourceClassId,
                    'message' => 'تعطيل بعض الفصول ترك إسناد مادة بلا فصل مقابل في الصف الهدف.',
                ];
            }
        }

        $promotedByClass = [];
        $promotedWithoutClass = 0;
        $promotedWithoutAutoPlacement = 0;
        foreach ($students as $student) {
            $decision = $decisionsByEnrollment[(int) $student['source_enrollment_id']] ?? null;
            if (!$decision || (string) ($decision['status'] ?? '') !== 'approved'
                || (string) ($decision['decision'] ?? '') !== 'promoted') {
                continue;
            }
            $sourceClassId = (int) ($student['class_id'] ?? 0);
            if ($sourceClassId <= 0) {
                $promotedWithoutClass++;
                continue;
            }
            if (!isset($autoPlace[$sourceClassId])) {
                $promotedWithoutAutoPlacement++;
                continue;
            }
            $promotedByClass[$sourceClassId] = (int) ($promotedByClass[$sourceClassId] ?? 0) + 1;
        }
        foreach ($promotedByClass as $sourceClassId => $count) {
            $mapping = $autoPlace[$sourceClassId];
            $capacity = $mapping['target_capacity'] !== null ? (int) $mapping['target_capacity'] : null;
            if ($capacity !== null && $count > $capacity) {
                $blockers[] = [
                    'code' => 'class_capacity_exceeded',
                    'class_id' => $sourceClassId,
                    'count' => $count,
                    'capacity' => $capacity,
                    'message' => 'عدد الطلاب الناجحين أكبر من سعة الفصل الهدف «' . $mapping['target_name'] . '».',
                ];
            }
        }
        if ($promotedWithoutClass > 0) {
            $warnings[] = [
                'code' => 'promoted_without_source_class',
                'count' => $promotedWithoutClass,
                'message' => 'يوجد ' . $promotedWithoutClass . ' طالب ناجح بلا فصل مصدر؛ سيبقى بلا فصل في العام الجديد.',
            ];
        }
        if ($promotedWithoutAutoPlacement > 0) {
            $warnings[] = [
                'code' => 'promoted_manual_placement',
                'count' => $promotedWithoutAutoPlacement,
                'message' => 'يوجد ' . $promotedWithoutAutoPlacement . ' طالب ناجح اختير له تسكين يدوي بعد التهيئة.',
            ];
        }

        return [
            'expected_count' => count($expected),
            'enabled_count' => $enabledCount,
            'auto_placed_students' => array_sum($promotedByClass),
            'unassigned_promoted_students' => $promotedWithoutClass + $promotedWithoutAutoPlacement,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    public function copyClasses(
        int $runId,
        int $sourceYearId,
        int $targetYearId,
        array &$audit
    ): array {
        $stmt = $this->db->prepare("SELECT m.*
            FROM class_rollover_mappings m
            JOIN classes c ON c.id = m.source_class_id AND c.academic_year_id = m.source_year_id
            WHERE m.source_year_id = ? AND m.target_year_id = ?
              AND m.status = 'active' AND m.is_enabled = 1
            ORDER BY m.target_grade_id, m.target_display_order, m.mapping_type, m.id");
        $stmt->execute([$sourceYearId, $targetYearId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $insert = $this->db->prepare("INSERT INTO classes
            (name, grade_id, room_location, capacity, status, academic_year_id, display_order)
            VALUES (?, ?, ?, ?, 'inactive', ?, ?)");
        $all = [];
        $cohort = [];
        $targetByGrade = [];
        foreach ($rows as $row) {
            $insert->execute([
                $row['target_name'], $row['target_grade_id'], $row['target_room_location'],
                $row['target_capacity'] ?? null, $targetYearId, $row['target_display_order'],
            ]);
            $targetClassId = (int) $this->db->lastInsertId();
            $mappingId = (int) $row['id'];
            $all[$mappingId] = $targetClassId;
            $targetByGrade[(int) $row['target_grade_id']][] = $targetClassId;
            if ((string) $row['mapping_type'] === 'cohort'
                && (int) $row['auto_place_students'] === 1) {
                $cohort[(int) $row['source_class_id']] = $targetClassId;
            }
            $this->db->prepare("INSERT INTO academic_year_rollover_items
                (run_id, entity_table, source_record_id, target_record_id, dependency_order, action)
                VALUES (?, 'classes', ?, ?, 40, 'insert')")
                ->execute([$runId, 'class_mapping:' . $mappingId, $targetClassId]);
            $audit[] = ['table' => 'classes', 'record_id' => $targetClassId];
        }

        $sourceByGrade = [];
        foreach ($this->sourceClasses($sourceYearId) as $sourceRow) {
            $sourceByGrade[(int) $sourceRow['grade_id']][] = (int) $sourceRow['id'];
        }
        $curriculum = [];
        foreach ($sourceByGrade as $gradeId => $sourceClassIds) {
            $targets = $targetByGrade[$gradeId] ?? [];
            foreach ($sourceClassIds as $rank => $sourceClassId) {
                if (isset($targets[$rank])) {
                    $curriculum[$sourceClassId] = (int) $targets[$rank];
                }
            }
        }
        return ['all' => $all, 'cohort' => $cohort, 'curriculum' => $curriculum];
    }

    public function fingerprint(int $sourceYearId, int $targetYearId): string
    {
        $stmt = $this->db->prepare("SELECT source_class_id, mapping_type, source_grade_id,
                target_grade_id, target_name, target_room_location, target_capacity,
                target_display_order, auto_place_students, is_enabled, status
            FROM class_rollover_mappings
            WHERE source_year_id = ? AND target_year_id = ?
            ORDER BY source_class_id, mapping_type, id");
        $stmt->execute([$sourceYearId, $targetYearId]);
        $json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('تعذر ترميز بصمة خريطة الفصول.');
        }
        return hash('sha256', $json);
    }

    public function sourceClasses(int $sourceYearId): array
    {
        $stmt = $this->db->prepare("SELECT c.id, c.name, c.grade_id, c.room_location,
                c.capacity, c.display_order, c.status, g.grade_name, s.stage_name
            FROM classes c
            JOIN grades g ON g.id = c.grade_id
            JOIN stages s ON s.id = g.stage_id
            WHERE c.academic_year_id = ? AND c.grade_id IS NOT NULL
              AND g.status = 'active' AND s.status = 'active'
              AND g.is_experimental = 0 AND s.is_experimental = 0 AND c.is_experimental = 0
            ORDER BY s.stage_order, g.grade_order, c.display_order, c.id");
        $stmt->execute([$sourceYearId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function expectedMappings(int $sourceYearId, array $rules, array $officialGrades): array
    {
        $grades = [];
        foreach ($officialGrades as $grade) {
            $grades[(int) $grade['id']] = $grade;
        }
        $sourceClasses = $this->sourceClasses($sourceYearId);
        $classesByGrade = [];
        foreach ($sourceClasses as $class) {
            $classesByGrade[(int) $class['grade_id']][] = $class;
        }
        $plans = [];
        $targetPlanCounts = [];
        foreach ($sourceClasses as $class) {
            $sourceClassId = (int) $class['id'];
            $sourceGradeId = (int) $class['grade_id'];
            $rule = $rules[$sourceGradeId] ?? null;
            if ($rule && (string) $rule['rule_type'] === 'promote') {
                $targetGradeId = (int) ($rule['target_grade_id'] ?? 0);
                $key = $this->mappingKey('cohort', $sourceClassId);
                $plans[$key] = $this->mappingPlan($key, 'cohort', $class, $grades[$targetGradeId] ?? []);
                $targetPlanCounts[$targetGradeId] = (int) ($targetPlanCounts[$targetGradeId] ?? 0) + 1;
            }
        }
        foreach ($classesByGrade as $gradeId => $gradeClasses) {
            $plannedForGrade = (int) ($targetPlanCounts[$gradeId] ?? 0);
            for ($rank = $plannedForGrade; $rank < count($gradeClasses); $rank++) {
                $class = $gradeClasses[$rank];
                $key = $this->mappingKey('entry_template', (int) $class['id']);
                $plans[$key] = $this->mappingPlan($key, 'entry_template', $class, $grades[$gradeId] ?? []);
            }
        }
        return $plans;
    }

    private function mappingPlan(string $key, string $type, array $class, array $targetGrade): array
    {
        return [
            'mapping_key' => $key,
            'mapping_type' => $type,
            'source_class_id' => (int) $class['id'],
            'source_class_name' => (string) $class['name'],
            'source_grade_id' => (int) $class['grade_id'],
            'source_grade_name' => (string) ($class['grade_name'] ?? ''),
            'source_stage_name' => (string) ($class['stage_name'] ?? ''),
            'source_room_location' => $class['room_location'] ?? null,
            'source_capacity' => $class['capacity'] !== null ? (int) $class['capacity'] : null,
            'source_display_order' => (int) ($class['display_order'] ?? 0),
            'target_grade_id' => (int) ($targetGrade['id'] ?? 0),
            'target_grade_name' => (string) ($targetGrade['grade_name'] ?? ''),
            'target_stage_name' => (string) ($targetGrade['stage_name'] ?? ''),
        ];
    }

    private function sourceClassStudentCounts(int $sourceYearId): array
    {
        $stmt = $this->db->prepare("SELECT se.class_id, COUNT(*) AS total
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
              AND se.class_id IS NOT NULL AND u.role = 'student'
              AND u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY se.class_id");
        $stmt->execute([$sourceYearId]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(int) $row['class_id']] = (int) $row['total'];
        }
        return $counts;
    }

    private function mappings(int $sourceYearId, int $targetYearId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM class_rollover_mappings
            WHERE source_year_id = ? AND target_year_id = ?
            ORDER BY source_class_id, mapping_type, id");
        $stmt->execute([$sourceYearId, $targetYearId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[$this->mappingKey((string) $row['mapping_type'], (int) $row['source_class_id'])] = $row;
        }
        return $rows;
    }

    private function mappingKey(string $type, int $sourceClassId): string
    {
        return $type . '-' . $sourceClassId;
    }

    private function lockYears(int $sourceYearId, int $targetYearId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM academic_years WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $stmt->execute([$sourceYearId, $targetYearId]);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) !== 2) {
            throw new RuntimeException('تعذر قفل العامين أثناء حفظ خريطة الفصول.');
        }
    }

    private function rowById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM class_rollover_mappings WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
