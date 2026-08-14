<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;

require_once __DIR__ . '/AssessmentEngine.php';
require_once __DIR__ . '/AssessmentSchemeReadinessService.php';
require_once __DIR__ . '/AssessmentWindowLifecycleService.php';
require_once __DIR__ . '/UndoManager.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

final class AssessmentBulkActionService
{
    private const MAX_BATCH_SIZE = 200;

    public function __construct(private PDO $db)
    {
    }

    /** @return list<int> */
    public static function normalizeIds($value): array
    {
        $values = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        $ids = [];
        foreach ($values ?: [] as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    /**
     * @param list<int> $ids
     * @return array{affected:int,batch_id:string,message:string}
     */
    public function execute(string $entity, string $operation, array $ids, int $academicYearId): array
    {
        $entity = trim($entity);
        $operation = trim($operation);
        $ids = self::normalizeIds($ids);
        $this->assertRequest($entity, $operation, $ids, $academicYearId);

        $batchId = UndoManager::newBatchId();
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $rows = $this->fetchRows($entity, $ids, true);
            $this->assertCompleteSelection($rows, $ids, $academicYearId);

            if ($operation === 'deactivate' && $entity === 'window') {
                $this->assertAllWindowsOpen($rows);
            }

            if ($operation === 'deactivate' && $entity === 'scheme') {
                $this->assertSchemesAreNotGrouped($rows);
            }

            if ($entity === 'component') {
                $this->assertComponentsAreWritable($rows);
            }

            if ($operation === 'delete') {
                $this->assertRowsDeletable($entity, $rows);
            }

            $audit = new AuditService($this->db);
            $affected = $operation === 'deactivate'
                ? $this->deactivateRows($entity, $rows, $audit, $batchId)
                : $this->deleteRows($entity, $rows, $audit, $batchId);

            if ($affected <= 0) {
                throw new RuntimeException($operation === 'deactivate'
                    ? 'السجلات المحددة معطلة أو مغلقة بالفعل.'
                    : 'لم يتم حذف أي سجل.');
            }

            if ($entity === 'component') {
                $schemeIds = array_values(array_unique(array_map(
                    static fn(array $row): int => (int) $row['scheme_id'],
                    $rows
                )));
                $readiness = new AssessmentSchemeReadinessService($this->db);
                foreach ($schemeIds as $schemeId) {
                    $readiness->refresh($schemeId, $batchId, true);
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'affected' => $affected,
                'batch_id' => $batchId,
                'message' => $operation === 'deactivate'
                    ? "تم تعطيل/إغلاق {$affected} سجل بنجاح."
                    : "تم حذف {$affected} سجل بنجاح.",
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Copy selected schemes to one validated assignment/term as draft schemes.
     * Names are generated deterministically and made unique in the target scope.
     *
     * @param list<int> $schemeIds
     * @return array{affected:int,components:int,batch_id:string,message:string}
     */
    public function copySchemes(
        array $schemeIds,
        int $targetAssignmentId,
        int $targetTermId,
        int $academicYearId,
        ?int $actorId
    ): array {
        $schemeIds = self::normalizeIds($schemeIds);
        if ($schemeIds === [] || count($schemeIds) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('حدد من خطة واحدة إلى 200 خطة للنسخ.');
        }
        if ($targetAssignmentId <= 0 || $targetTermId <= 0 || $academicYearId <= 0) {
            throw new InvalidArgumentException('اختر ربط المادة/الصف والترم الهدف.');
        }

        $batchId = UndoManager::newBatchId();
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $sources = $this->fetchRows('scheme', $schemeIds, true);
            $this->assertCompleteSelection($sources, $schemeIds, $academicYearId);
            $assignment = $this->fetchTargetAssignment($targetAssignmentId, $targetTermId, $academicYearId);
            foreach ($sources as $source) {
                if (!empty($source['family_id'])) {
                    throw new InvalidArgumentException('لا يمكن نسخ خطة تابعة لمجموعة من عملية النسخ الفردية؛ أنشئ مجموعة جديدة من صفحة الخطط الجماعية.');
                }
                if ((int) $source['subject_assignment_id'] === $targetAssignmentId
                    && (int) $source['term_id'] === $targetTermId) {
                    throw new InvalidArgumentException('إحدى الخطط المحددة موجودة بالفعل في نفس ربط المادة والترم الهدف.');
                }
                if ((int) $source['term_id'] !== $targetTermId && $this->schemeHasWeekRules((int) $source['id'])) {
                    throw new InvalidArgumentException('لا يمكن نسخ خطة تحتوي قواعد أسابيع إلى ترم مختلف؛ اختر ترم الهدف نفسه حتى لا ترتبط القواعد بأسابيع تاريخية خاطئة.');
                }
            }

            $audit = new AuditService($this->db);
            $engine = new AssessmentEngine($this->db);
            $created = 0;
            $componentsCreated = 0;
            $reservedNames = [];

            foreach ($sources as $source) {
                $targetName = $this->uniqueSchemeName(
                    $targetAssignmentId,
                    $targetTermId,
                    'نسخة من ' . (string) $source['name'],
                    $reservedNames
                );
                $reservedNames[] = $targetName;

                $stmt = $this->db->prepare("INSERT INTO assessment_schemes
                    (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name,
                     total_grade, pass_grade, counts_in_total, enable_excused_absence,
                     normal_absence_policy, excused_absence_policy, rounding_enabled, rounding_mode,
                     rounding_scope, annual_result_enabled, first_term_weight, second_term_weight,
                     status, copied_from_scheme_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)");
                $stmt->execute([
                    $academicYearId,
                    $targetTermId,
                    $targetAssignmentId,
                    (int) $assignment['subject_id'],
                    $assignment['stage_id'] !== null ? (int) $assignment['stage_id'] : null,
                    (int) $assignment['grade_id'],
                    $targetName,
                    (float) $source['total_grade'],
                    $source['pass_grade'] !== null ? (float) $source['pass_grade'] : null,
                    (int) $source['counts_in_total'],
                    (int) $source['enable_excused_absence'],
                    (string) $source['normal_absence_policy'],
                    (string) $source['excused_absence_policy'],
                    (int) $source['rounding_enabled'],
                    (string) $source['rounding_mode'],
                    (string) $source['rounding_scope'],
                    (int) $source['annual_result_enabled'],
                    (float) $source['first_term_weight'],
                    (float) $source['second_term_weight'],
                    (int) $source['id'],
                    $actorId && $actorId > 0 ? $actorId : null,
                ]);
                $targetSchemeId = (int) $this->db->lastInsertId();
                $targetScheme = $this->fetchOne('SELECT * FROM assessment_schemes WHERE id = ?', [$targetSchemeId]);
                $audit->recordInsert(
                    'assessment_scheme',
                    'assessment_schemes',
                    $targetSchemeId,
                    $targetName,
                    $targetScheme,
                    'نسخ جماعي لخطة درجات',
                    $batchId,
                    ['source_scheme_id' => (int) $source['id']]
                );

                $componentsCreated += $engine->copySchemeComponents((int) $source['id'], $targetSchemeId, 1.0, $batchId);
                (new AssessmentSchemeReadinessService($this->db))->refresh($targetSchemeId, $batchId, true);
                $created++;
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'affected' => $created,
                'components' => $componentsCreated,
                'batch_id' => $batchId,
                'message' => "تم نسخ {$created} خطة وإنشاء {$componentsCreated} بندًا كمسودات.",
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<int> $ids */
    private function assertRequest(string $entity, string $operation, array $ids, int $academicYearId): void
    {
        if (!in_array($entity, ['scheme', 'component', 'week_rule', 'window'], true)) {
            throw new InvalidArgumentException('نوع سجلات التقييم غير معروف.');
        }
        if (!in_array($operation, ['deactivate', 'delete'], true)) {
            throw new InvalidArgumentException('العملية الجماعية غير معروفة.');
        }
        if ($ids === [] || count($ids) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('حدد من سجل واحد إلى 200 سجل.');
        }
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('لا يوجد عام دراسي صالح لتنفيذ العملية.');
        }
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    private function fetchRows(string $entity, array $ids, bool $lock): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = match ($entity) {
            'scheme' => "SELECT sch.*, sch.academic_year_id AS guarded_year_id FROM assessment_schemes sch WHERE sch.id IN ({$placeholders})",
            'component' => "SELECT ac.*, sch.academic_year_id AS guarded_year_id, sch.name AS scheme_name,
                    sch.status AS scheme_status
                FROM assessment_components ac JOIN assessment_schemes sch ON sch.id = ac.scheme_id
                WHERE ac.id IN ({$placeholders})",
            'week_rule' => "SELECT cwr.*, ac.name AS component_name, sch.academic_year_id AS guarded_year_id
                FROM assessment_component_week_rules cwr
                JOIN assessment_components ac ON ac.id = cwr.component_id
                JOIN assessment_schemes sch ON sch.id = ac.scheme_id
                WHERE cwr.id IN ({$placeholders})",
            'window' => "SELECT aw.*, sch.academic_year_id AS guarded_year_id
                FROM assessment_windows aw JOIN assessment_schemes sch ON sch.id = aw.scheme_id
                WHERE aw.id IN ({$placeholders})",
        };
        if ($lock && $this->driver() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @param list<int> $ids */
    private function assertCompleteSelection(array $rows, array $ids, int $academicYearId): void
    {
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('تتضمن القائمة سجلات غير موجودة أو حُذفت قبل تنفيذ العملية.');
        }
        foreach ($rows as $row) {
            if ((int) ($row['guarded_year_id'] ?? $row['academic_year_id'] ?? 0) !== $academicYearId) {
                throw new InvalidArgumentException('لا يمكن تنفيذ عملية جماعية على سجلات خارج العام الدراسي المختار.');
            }
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function assertRowsDeletable(string $entity, array $rows): void
    {
        $blocked = [];
        foreach ($rows as $row) {
            $reason = $this->deleteBlockReason($entity, $row);
            if ($reason !== null) {
                $blocked[] = '«' . $this->rowName($entity, $row) . '»: ' . $reason;
            }
        }
        if ($blocked !== []) {
            $preview = array_slice($blocked, 0, 5);
            $suffix = count($blocked) > 5 ? '، وسجلات أخرى.' : '';
            throw new RuntimeException('لم تُنفذ العملية لأن الحذف ذري، وهذه السجلات مرتبطة ببيانات تشغيلية: ' . implode('؛ ', $preview) . $suffix);
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function assertSchemesAreNotGrouped(array $rows): void
    {
        foreach ($rows as $row) {
            if (!empty($row['family_id'])) {
                throw new RuntimeException('لا يمكن تعطيل خطة تابعة لمجموعة بصورة منفردة؛ استخدم إدارة المجموعة للحفاظ على اتساق كل الترمات.');
            }
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function assertComponentsAreWritable(array $rows): void
    {
        foreach ($rows as $row) {
            if ((string) ($row['scheme_status'] ?? '') === 'active') {
                throw new RuntimeException(
                    'لا يمكن تعديل بنود خطة نشطة. عطّل الخطة «'
                    . (string) ($row['scheme_name'] ?? ('#' . (int) $row['scheme_id']))
                    . '» أولا.'
                );
            }
            $dependency = $this->deleteBlockReason('component', $row);
            if ($dependency !== null) {
                throw new RuntimeException(
                    'لا يمكن تعديل البند «' . $this->rowName('component', $row)
                    . '» لارتباطه ببيانات تشغيلية: ' . $dependency . '.'
                );
            }
        }
    }

    private function deleteBlockReason(string $entity, array $row): ?string
    {
        if ($entity === 'scheme') {
            if (!empty($row['family_id'])) {
                return 'خطة تابعة لمجموعة مترابطة؛ لا تحذف ترمًا منها منفردًا';
            }
            $id = (int) $row['id'];
            $checks = [
                ['assessment_windows', 'scheme_id', 'نوافذ رصد'],
                ['student_marks', 'scheme_id', 'درجات'],
                ['report_window_items', 'scheme_id', 'عناصر تقارير'],
                ['published_report_details', 'scheme_id', 'تقارير منشورة'],
                ['assessment_schemes', 'copied_from_scheme_id', 'خطط منسوخة منها'],
            ];
            return $this->firstDependencyReason($id, $checks);
        }

        if ($entity === 'component') {
            $closure = $this->componentClosure([(int) $row['id']]);
            $ids = array_map(static fn(array $item): int => (int) $item['id'], $closure);
            $checks = [
                ['assessment_windows', 'component_id', 'نوافذ رصد'],
                ['student_marks', 'component_id', 'درجات'],
                ['report_window_items', 'component_id', 'عناصر تقارير'],
                ['published_report_details', 'component_id', 'تقارير منشورة'],
            ];
            foreach ($ids as $id) {
                $reason = $this->firstDependencyReason($id, $checks);
                if ($reason !== null) {
                    return $reason;
                }
            }
            return null;
        }

        if ($entity === 'week_rule') {
            return $this->weekRuleHasMarks((int) $row['component_id'], (int) $row['week_id'])
                ? 'درجات مرصودة لنفس البند والأسبوع'
                : null;
        }

        if ((string) ($row['status'] ?? '') === 'locked') {
            return 'النافذة مقفلة نهائيًا وتحتاج إعادة فتح بصلاحية خاصة قبل الحذف';
        }
        return $this->windowHasMarks($row) ? 'درجات مرصودة داخل نطاق النافذة' : null;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function deactivateRows(string $entity, array $rows, AuditService $audit, string $batchId): int
    {
        if ($entity === 'window') {
            $lifecycle = new AssessmentWindowLifecycleService($this->db);
            foreach ($rows as $row) {
                $lifecycle->transition(
                    (int) $row['id'],
                    'closed',
                    (int) ($_SESSION['user_id'] ?? 0),
                    (string) ($_SESSION['role'] ?? ''),
                    'إغلاق جماعي لنوافذ الرصد',
                    null,
                    $batchId
                );
            }
            return count($rows);
        }

        $affected = 0;
        foreach ($rows as $row) {
            $before = $this->fetchEntityById($entity, (int) $row['id']);
            $changed = false;
            if ($entity === 'scheme' && (string) $row['status'] === 'active') {
                $this->db->prepare("UPDATE assessment_schemes SET status = 'archived' WHERE id = ?")->execute([(int) $row['id']]);
                $changed = true;
            } elseif ($entity === 'component' && (int) $row['is_active'] === 1) {
                $this->db->prepare('UPDATE assessment_components SET is_active = 0 WHERE id = ?')->execute([(int) $row['id']]);
                $changed = true;
            } elseif ($entity === 'week_rule' && (int) $row['is_included'] === 1) {
                $this->db->prepare('UPDATE assessment_component_week_rules SET is_included = 0 WHERE id = ?')->execute([(int) $row['id']]);
                $changed = true;
            }

            if (!$changed) {
                continue;
            }
            $after = $this->fetchEntityById($entity, (int) $row['id']);
            $audit->recordUpdate(
                $this->entityType($entity),
                $this->tableName($entity),
                (int) $row['id'],
                $this->rowName($entity, $row),
                $before,
                $after,
                'تعطيل/إغلاق جماعي لسجل تقييم',
                $batchId
            );
            $affected++;
        }
        return $affected;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function deleteRows(string $entity, array $rows, AuditService $audit, string $batchId): int
    {
        $originalSnapshots = [];
        foreach ($rows as $row) {
            $originalSnapshots[(int) $row['id']] = $this->fetchEntityById($entity, (int) $row['id']);
        }
        $this->prepareRowsForDelete($entity, $rows);
        $rows = $this->fetchRows(
            $entity,
            array_map(static fn(array $row): int => (int) $row['id'], $rows),
            true
        );

        if ($entity === 'scheme') {
            foreach ($rows as $row) {
                $this->auditSchemeCascade((int) $row['id'], $audit, $batchId);
                $audit->recordDelete('assessment_scheme', 'assessment_schemes', (int) $row['id'], $this->rowName($entity, $row), $originalSnapshots[(int) $row['id']], 'تعطيل وحذف خطة درجات', $batchId);
            }
        } elseif ($entity === 'component') {
            $rootIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $closure = $this->componentClosure($rootIds);
            $this->auditComponentCascade($closure, $audit, $batchId, $originalSnapshots);
        } else {
            foreach ($rows as $row) {
                $audit->recordDelete(
                    $this->entityType($entity),
                    $this->tableName($entity),
                    (int) $row['id'],
                    $this->rowName($entity, $row),
                    $originalSnapshots[(int) $row['id']],
                    'تعطيل/إغلاق وحذف سجل تقييم',
                    $batchId
                );
            }
        }

        $rootIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($rootIds), '?'));
        $stmt = $this->db->prepare('DELETE FROM ' . $this->tableName($entity) . " WHERE id IN ({$placeholders})");
        $stmt->execute($rootIds);
        return count($rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function prepareRowsForDelete(string $entity, array $rows): void
    {
        foreach ($rows as $row) {
            if ($entity === 'scheme' && (string) $row['status'] === 'active') {
                $this->db->prepare("UPDATE assessment_schemes SET status = 'archived' WHERE id = ?")->execute([(int) $row['id']]);
            } elseif ($entity === 'component' && (int) $row['is_active'] === 1) {
                $this->db->prepare('UPDATE assessment_components SET is_active = 0 WHERE id = ?')->execute([(int) $row['id']]);
            } elseif ($entity === 'week_rule' && (int) $row['is_included'] === 1) {
                $this->db->prepare('UPDATE assessment_component_week_rules SET is_included = 0 WHERE id = ?')->execute([(int) $row['id']]);
            } elseif ($entity === 'window' && (string) $row['status'] === 'open') {
                $this->db->prepare("UPDATE assessment_windows SET status = 'closed', closes_at = NOW() WHERE id = ?")->execute([(int) $row['id']]);
            }
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function assertAllWindowsOpen(array $rows): void
    {
        $invalid = [];
        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? '') !== 'open') {
                $invalid[] = '«' . $this->rowName('window', $row) . '»';
            }
        }
        if ($invalid !== []) {
            $preview = implode('، ', array_slice($invalid, 0, 5));
            $suffix = count($invalid) > 5 ? ' وسجلات أخرى' : '';
            throw new RuntimeException('لم تُغلق أي نافذة لأن الدفعة تحتوي حالات غير مفتوحة: ' . $preview . $suffix . '.');
        }
    }

    private function auditSchemeCascade(int $schemeId, AuditService $audit, string $batchId): void
    {
        $components = $this->fetchAll('SELECT * FROM assessment_components WHERE scheme_id = ?', [$schemeId]);
        $this->auditComponentCascade($this->sortComponentsDeepestFirst($components), $audit, $batchId);
    }

    /** @param array<int,array<string,mixed>> $components */
    private function auditComponentCascade(array $components, AuditService $audit, string $batchId, array $snapshotOverrides = []): void
    {
        if ($components === []) {
            return;
        }
        $components = $this->sortComponentsDeepestFirst($components);
        $componentIds = array_map(static fn(array $row): int => (int) $row['id'], $components);
        $placeholders = implode(',', array_fill(0, count($componentIds), '?'));
        if ($this->tableExists('assessment_component_week_rules')) {
            $rules = $this->fetchAll("SELECT * FROM assessment_component_week_rules WHERE component_id IN ({$placeholders}) ORDER BY id", $componentIds);
            foreach ($rules as $rule) {
                $audit->recordDelete(
                    'assessment_component_week_rule',
                    'assessment_component_week_rules',
                    (int) $rule['id'],
                    'قاعدة أسبوع #' . (int) $rule['id'],
                    $rule,
                    'حذف تابع لعنصر تقييم محذوف',
                    $batchId
                );
            }
        }
        foreach ($components as $component) {
            $snapshot = $snapshotOverrides[(int) $component['id']] ?? $component;
            $audit->recordDelete(
                'assessment_component',
                'assessment_components',
                (int) $component['id'],
                (string) $component['name'],
                $snapshot,
                'حذف بند تابع ضمن عملية تقييم',
                $batchId
            );
        }
    }

    /** @param list<int> $rootIds @return array<int,array<string,mixed>> */
    private function componentClosure(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }
        $rootRows = $this->fetchAll(
            'SELECT * FROM assessment_components WHERE id IN (' . implode(',', array_fill(0, count($rootIds), '?')) . ')',
            $rootIds
        );
        $schemeIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['scheme_id'], $rootRows)));
        if ($schemeIds === []) {
            return [];
        }
        $all = $this->fetchAll(
            'SELECT * FROM assessment_components WHERE scheme_id IN (' . implode(',', array_fill(0, count($schemeIds), '?')) . ')',
            $schemeIds
        );
        $children = [];
        foreach ($all as $row) {
            $parentId = (int) ($row['parent_component_id'] ?? 0);
            $children[$parentId][] = $row;
        }
        $selected = [];
        $visit = function (int $id) use (&$visit, &$selected, $children): void {
            if (isset($selected[$id])) {
                return;
            }
            foreach ($children[$id] ?? [] as $child) {
                $visit((int) $child['id']);
            }
            foreach ($children[0] ?? [] as $root) {
                if ((int) $root['id'] === $id) {
                    $selected[$id] = $root;
                    return;
                }
            }
            foreach ($children as $rows) {
                foreach ($rows as $row) {
                    if ((int) $row['id'] === $id) {
                        $selected[$id] = $row;
                        return;
                    }
                }
            }
        };
        foreach ($rootIds as $rootId) {
            $visit($rootId);
        }
        return array_values($selected);
    }

    /** @param array<int,array<string,mixed>> $components @return array<int,array<string,mixed>> */
    private function sortComponentsDeepestFirst(array $components): array
    {
        $byId = [];
        foreach ($components as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $depth = static function (array $row) use (&$depth, $byId): int {
            $parentId = (int) ($row['parent_component_id'] ?? 0);
            return $parentId > 0 && isset($byId[$parentId]) ? 1 + $depth($byId[$parentId]) : 0;
        };
        usort($components, static function (array $left, array $right) use ($depth): int {
            return $depth($right) <=> $depth($left) ?: ((int) $left['id'] <=> (int) $right['id']);
        });
        return $components;
    }

    /** @param array<int,array{0:string,1:string,2:string}> $checks */
    private function firstDependencyReason(int $id, array $checks): ?string
    {
        foreach ($checks as [$table, $column, $label]) {
            if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
                continue;
            }
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                return $label;
            }
        }
        return null;
    }

    private function weekRuleHasMarks(int $componentId, int $weekId): bool
    {
        if (!$this->tableExists('student_marks')) {
            return false;
        }
        $where = 'component_id = ?';
        $params = [$componentId];
        if ($this->columnExists('student_marks', 'week_id')) {
            $where .= ' AND week_id = ?';
            $params[] = $weekId;
        } elseif ($this->columnExists('student_marks', 'week_slot')) {
            $where .= ' AND week_slot = ?';
            $params[] = $weekId;
        } else {
            return false;
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM student_marks WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function windowHasMarks(array $window): bool
    {
        if (!$this->tableExists('student_marks')) {
            return false;
        }
        $where = 'scheme_id = ? AND component_id = ?';
        $params = [(int) $window['scheme_id'], (int) $window['component_id']];
        if ($window['week_id'] !== null && $this->columnExists('student_marks', 'week_id')) {
            $where .= ' AND week_id = ?';
            $params[] = (int) $window['week_id'];
        }
        if ($window['class_id'] !== null && $this->columnExists('student_marks', 'class_id_at_entry')) {
            $where .= ' AND class_id_at_entry = ?';
            $params[] = (int) $window['class_id'];
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM student_marks WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function fetchTargetAssignment(int $assignmentId, int $termId, int $academicYearId): array
    {
        $assignment = $this->fetchOne(
            'SELECT sga.* FROM subject_grade_assignments sga WHERE sga.id = ? AND sga.is_active = 1 LIMIT 1',
            [$assignmentId]
        );
        if ($assignment === [] || (int) $assignment['academic_year_id'] !== $academicYearId) {
            throw new InvalidArgumentException('ربط المادة/الصف الهدف غير موجود أو خارج العام المختار.');
        }
        $term = $this->fetchOne('SELECT id, academic_year_id FROM academic_terms WHERE id = ? LIMIT 1', [$termId]);
        if ($term === [] || (int) $term['academic_year_id'] !== $academicYearId) {
            throw new InvalidArgumentException('الترم الهدف لا يتبع العام الدراسي المختار.');
        }
        if (!empty($assignment['term_id']) && (int) $assignment['term_id'] !== $termId) {
            throw new InvalidArgumentException('ربط المادة محدد لترم مختلف عن الترم الهدف.');
        }
        return $assignment;
    }

    /** @param list<string> $reserved */
    private function uniqueSchemeName(int $assignmentId, int $termId, string $base, array $reserved): string
    {
        $base = trim($base) !== '' ? trim($base) : 'نسخة من خطة درجات';
        $base = function_exists('mb_substr') ? mb_substr($base, 0, 170, 'UTF-8') : substr($base, 0, 170);
        $candidate = $base;
        $suffix = 2;
        while (in_array($candidate, $reserved, true) || $this->schemeNameExists($assignmentId, $termId, $candidate)) {
            $candidate = $base . ' (' . $suffix . ')';
            $suffix++;
        }
        return $candidate;
    }

    private function schemeNameExists(int $assignmentId, int $termId, string $name): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM assessment_schemes WHERE subject_assignment_id = ? AND term_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$assignmentId, $termId, $name]);
        return (bool) $stmt->fetchColumn();
    }

    private function schemeHasWeekRules(int $schemeId): bool
    {
        if (!$this->tableExists('assessment_component_week_rules')) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM assessment_component_week_rules cwr JOIN assessment_components ac ON ac.id = cwr.component_id WHERE ac.scheme_id = ? LIMIT 1');
        $stmt->execute([$schemeId]);
        return (bool) $stmt->fetchColumn();
    }

    private function fetchEntityById(string $entity, int $id): array
    {
        $table = $this->tableName($entity);
        return $this->fetchOne("SELECT * FROM {$table} WHERE id = ?", [$id]);
    }

    private function tableName(string $entity): string
    {
        return match ($entity) {
            'scheme' => 'assessment_schemes',
            'component' => 'assessment_components',
            'week_rule' => 'assessment_component_week_rules',
            'window' => 'assessment_windows',
        };
    }

    private function entityType(string $entity): string
    {
        return match ($entity) {
            'scheme' => 'assessment_scheme',
            'component' => 'assessment_component',
            'week_rule' => 'assessment_component_week_rule',
            'window' => 'assessment_window',
        };
    }

    private function rowName(string $entity, array $row): string
    {
        return match ($entity) {
            'scheme', 'component' => (string) ($row['name'] ?? ('#' . (int) $row['id'])),
            'week_rule' => (string) ($row['component_name'] ?? 'قاعدة أسبوع') . ' #' . (int) $row['week_id'],
            'window' => (string) ($row['window_name'] ?? ('#' . (int) $row['id'])),
        };
    }

    /** @return array<string,mixed> */
    private function fetchOne(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function tableExists(string $table): bool
    {
        if ($this->driver() === 'sqlite') {
            $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->driver() === 'sqlite') {
            $stmt = $this->db->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
