<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;

require_once __DIR__ . '/AcademicYearWriteGuard.php';
require_once __DIR__ . '/AssessmentEngine.php';
require_once __DIR__ . '/AssessmentSchemeScopeResolver.php';
require_once __DIR__ . '/SystemAdministratorRoleService.php';
require_once __DIR__ . '/UndoManager.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

/**
 * Audited administration commands for canonical assessment marks.
 */
final class AssessmentMarkConflictException extends RuntimeException
{
}

final class AssessmentMarkAdministrationService
{
    private const MAX_SELECTION = 200;

    private PDO $db;
    private array $tableCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function normalizeIds($raw): array
    {
        $values = is_array($raw) ? $raw : preg_split('/\s*,\s*/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_values(array_unique(array_filter(array_map('intval', $values ?: []), static fn(int $id): bool => $id > 0)));
        if (count($ids) > self::MAX_SELECTION) {
            throw new InvalidArgumentException('الحد الأقصى للعملية الجماعية الواحدة هو 200 درجة.');
        }
        return $ids;
    }

    public function updateMark(
        int $markId,
        array $payload,
        int $selectedAcademicYearId,
        int $actorId,
        string $actorRole,
        ?string $sharedBatchId = null
    ): array {
        $reason = $this->assertReason((string) ($payload['reason'] ?? ''));
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $before = $this->fetchMarkForUpdate($markId);
            $this->assertSelectedYear($before, $selectedAcademicYearId);
            (new AcademicYearWriteGuard($this->db))->assertWritable((int) $before['academic_year_id']);

            $isLocked = !empty($before['locked_at'])
                || (int) ($before['student_locked'] ?? 0) === 1
                || (int) ($before['locked_window_count'] ?? 0) > 0;
            if ($isLocked) {
                if ($actorRole !== 'super_admin') {
                    throw new RuntimeException('هذه الدرجة مقفلة، ولا يستطيع تعديلها إلا مدير النظام الأعلى.');
                }
                (new SystemAdministratorRoleService($this->db))->assertActorCanManage($actorId, $actorRole);
            }

            $normalized = $this->normalizeMarkPayload($payload, $before);
            if ($this->hasExpectedState($payload) && !$this->matchesExpectedState($before, $payload)) {
                if ($this->matchesNormalizedState($before, $normalized)) {
                    if ($ownsTransaction) {
                        $this->db->commit();
                    }
                    return [
                        'mark_id' => $markId,
                        'batch_id' => '',
                        'published_count' => (int) ($before['published_count'] ?? 0),
                        'no_change' => true,
                    ];
                }
                throw new AssessmentMarkConflictException('عدّل مستخدم آخر هذه الدرجة بعد تحميل الشيت. أعد تحميل الخلية قبل الكتابة فوق التغيير.');
            }
            if ($this->matchesNormalizedState($before, $normalized)) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return [
                    'mark_id' => $markId,
                    'batch_id' => '',
                    'published_count' => (int) ($before['published_count'] ?? 0),
                    'no_change' => true,
                ];
            }
            $reviewStatus = (int) ($before['review_required'] ?? 0) > 0 ? 'pending' : 'not_required';
            $update = $this->db->prepare('UPDATE student_marks
                SET value = ?, mark_status = ?, note = ?, recorded_by = ?, review_status = ?,
                    reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
                WHERE id = ?');
            $update->execute([
                $normalized['value'],
                $normalized['status'],
                $normalized['note'],
                $actorId > 0 ? $actorId : null,
                $reviewStatus,
                $markId,
            ]);

            $after = $this->fetchMarkRow($markId);
            $this->insertDomainAudit($before, $after, 'update', $reason, $actorId);
            $batchId = $sharedBatchId ?: UndoManager::newBatchId();
            (new AuditService($this->db))->recordUpdate(
                'student_mark',
                'student_marks',
                $markId,
                (string) ($before['student_name'] ?? ('درجة #' . $markId)),
                $this->markSnapshot($before),
                $this->markSnapshot($after),
                'تصحيح إداري لدرجة طالب: ' . $reason,
                $batchId
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return [
                'mark_id' => $markId,
                'batch_id' => $batchId,
                'published_count' => (int) ($before['published_count'] ?? 0),
                'no_change' => false,
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Create a missing sheet cell only through one unambiguous live recording window.
     */
    public function createMark(
        int $studentId,
        int $windowId,
        int $schemeId,
        int $componentId,
        ?int $weekId,
        array $payload,
        int $selectedAcademicYearId,
        int $actorId,
        string $actorRole,
        ?string $sharedBatchId = null
    ): array {
        if ($studentId <= 0 || $windowId <= 0 || $schemeId <= 0 || $componentId <= 0) {
            throw new InvalidArgumentException('بيانات خلية الدرجة الجديدة غير مكتملة.');
        }
        $reason = $this->assertReason((string) ($payload['reason'] ?? ''));
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            (new AcademicYearWriteGuard($this->db))->assertWritable($selectedAcademicYearId);
            $context = $this->fetchCreateContext(
                $studentId,
                $windowId,
                $schemeId,
                $componentId,
                $weekId,
                $selectedAcademicYearId
            );

            if (!empty($context['student_locked'])) {
                if ($actorRole !== 'super_admin') {
                    throw new RuntimeException('هذا الطالب مقفل، ولا يستطيع إنشاء درجة له إلا مدير النظام الأعلى.');
                }
                (new SystemAdministratorRoleService($this->db))->assertActorCanManage($actorId, $actorRole);
            }

            $normalized = $this->normalizeMarkPayload($payload, $context);
            $existing = $context['existing_mark'] ?? null;
            if (is_array($existing)) {
                if ($this->matchesNormalizedState($existing, $normalized)) {
                    if ($ownsTransaction) {
                        $this->db->commit();
                    }
                    return [
                        'mark_id' => (int) $existing['id'],
                        'batch_id' => '',
                        'published_count' => $this->publishedCountForMark($existing),
                        'no_change' => true,
                    ];
                }
                throw new AssessmentMarkConflictException('أُنشئت هذه الدرجة من جلسة أخرى. أعد تحميل الشيت قبل تعديلها.');
            }

            if ($normalized['status'] === AssessmentEngine::STATUS_EMPTY && $normalized['note'] === null) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return ['mark_id' => 0, 'batch_id' => '', 'published_count' => 0, 'no_change' => true];
            }

            $reviewStatus = !empty($context['requires_review']) ? 'pending' : 'not_required';
            $insert = $this->db->prepare('INSERT INTO student_marks
                (student_id, scheme_id, component_id, week_id, week_slot, academic_year_id, term_id, subject_id,
                 grade_id, class_id_at_entry, value, mark_status, note, recorded_by, review_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            try {
                $insert->execute([
                    $studentId,
                    $schemeId,
                    $componentId,
                    $weekId,
                    $weekId ?? 0,
                    $selectedAcademicYearId,
                    (int) $context['term_id'],
                    (int) $context['subject_id'],
                    (int) $context['scheme_grade_id'],
                    (int) $context['student_class_id'],
                    $normalized['value'],
                    $normalized['status'],
                    $normalized['note'],
                    $actorId > 0 ? $actorId : null,
                    $reviewStatus,
                ]);
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    throw new AssessmentMarkConflictException('أُنشئت هذه الدرجة بالتزامن من جلسة أخرى. أعد تحميل الشيت.');
                }
                throw $error;
            }

            $markId = (int) $this->db->lastInsertId();
            $after = $this->fetchMarkRow($markId);
            $this->insertDomainAudit([], $after, 'create', $reason, $actorId);
            $batchId = $sharedBatchId ?: UndoManager::newBatchId();
            (new AuditService($this->db))->recordInsert(
                'student_mark',
                'student_marks',
                $markId,
                (string) ($context['student_name'] ?? ('درجة #' . $markId)),
                $this->markSnapshot($after),
                'إنشاء إداري لدرجة طالب من الشيت: ' . $reason,
                $batchId
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return ['mark_id' => $markId, 'batch_id' => $batchId, 'published_count' => 0, 'no_change' => false];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Apply one normalized change to several marks as one audited, undoable transaction.
     */
    public function bulkUpdateMarks(
        array $markIds,
        array $payload,
        int $selectedAcademicYearId,
        int $actorId,
        string $actorRole
    ): array {
        $markIds = self::normalizeIds($markIds);
        if ($markIds === []) {
            throw new InvalidArgumentException('اختر درجة واحدة على الأقل للتعديل.');
        }
        sort($markIds, SORT_NUMERIC);
        $reason = $this->assertReason((string) ($payload['reason'] ?? ''));
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $marks = [];
            $hasLockedMark = false;
            foreach ($markIds as $markId) {
                $mark = $this->fetchMarkForUpdate($markId);
                $this->assertSelectedYear($mark, $selectedAcademicYearId);
                $hasLockedMark = $hasLockedMark
                    || !empty($mark['locked_at'])
                    || (int) ($mark['student_locked'] ?? 0) === 1
                    || (int) ($mark['locked_window_count'] ?? 0) > 0;
                $marks[] = $mark;
            }
            (new AcademicYearWriteGuard($this->db))->assertWritable($selectedAcademicYearId);
            if ($hasLockedMark) {
                if ($actorRole !== 'super_admin') {
                    throw new RuntimeException('تتضمن المجموعة درجات مقفلة، ولا يستطيع تعديلها إلا مدير النظام الأعلى.');
                }
                (new SystemAdministratorRoleService($this->db))->assertActorCanManage($actorId, $actorRole);
            }

            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $publishedAffected = 0;
            $update = $this->db->prepare('UPDATE student_marks
                SET value = ?, mark_status = ?, note = ?, recorded_by = ?, review_status = ?,
                    reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
                WHERE id = ?');
            foreach ($marks as $before) {
                $markPayload = $payload;
                if (!array_key_exists('note', $markPayload)) {
                    $markPayload['note'] = (string) ($before['note'] ?? '');
                }
                $normalized = $this->normalizeMarkPayload($markPayload, $before);
                $reviewStatus = (int) ($before['review_required'] ?? 0) > 0 ? 'pending' : 'not_required';
                $update->execute([
                    $normalized['value'],
                    $normalized['status'],
                    $normalized['note'],
                    $actorId > 0 ? $actorId : null,
                    $reviewStatus,
                    (int) $before['id'],
                ]);
                if ($update->rowCount() > 1) {
                    throw new RuntimeException('تعذر ضمان سلامة التعديل الجماعي؛ أُلغيت العملية بالكامل.');
                }

                $after = $this->fetchMarkRow((int) $before['id']);
                $this->insertDomainAudit($before, $after, 'update', $reason, $actorId);
                $audit->recordUpdate(
                    'student_mark',
                    'student_marks',
                    (int) $before['id'],
                    (string) ($before['student_name'] ?? ('درجة #' . $before['id'])),
                    $this->markSnapshot($before),
                    $this->markSnapshot($after),
                    'تعديل جماعي لدرجات الطلاب: ' . $reason,
                    $batchId
                );
                $publishedAffected += (int) ($before['published_count'] ?? 0);
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return ['affected' => count($marks), 'batch_id' => $batchId, 'published_count' => $publishedAffected];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Apply a rectangular spreadsheet edit/paste as one transaction and one undo batch.
     */
    public function bulkApplyCells(
        array $changes,
        int $selectedAcademicYearId,
        int $actorId,
        string $actorRole,
        string $reason
    ): array {
        if ($changes === []) {
            throw new InvalidArgumentException('لا توجد خلايا قابلة للحفظ في النطاق المحدد.');
        }
        if (count($changes) > self::MAX_SELECTION) {
            throw new InvalidArgumentException('الحد الأقصى للعملية الجماعية الواحدة هو 200 خلية.');
        }
        $reason = $this->assertReason($reason);

        $normalizedChanges = [];
        $uniqueKeys = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new InvalidArgumentException('تنسيق إحدى خلايا العملية الجماعية غير صحيح.');
            }
            $markId = max(0, (int) ($change['mark_id'] ?? 0));
            $weekId = array_key_exists('week_id', $change) && $change['week_id'] !== '' && $change['week_id'] !== null
                ? (int) $change['week_id']
                : null;
            $key = $markId > 0
                ? 'mark:' . $markId
                : 'cell:' . (int) ($change['student_id'] ?? 0) . ':' . (int) ($change['component_id'] ?? 0) . ':' . ($weekId ?? 0);
            if (isset($uniqueKeys[$key])) {
                throw new InvalidArgumentException('تتكرر الخلية نفسها داخل العملية الجماعية.');
            }
            $uniqueKeys[$key] = true;
            $change['mark_id'] = $markId;
            $change['week_id'] = $weekId;
            $normalizedChanges[] = $change;
        }

        usort($normalizedChanges, static function (array $left, array $right): int {
            $leftKey = !empty($left['mark_id'])
                ? '0:' . str_pad((string) $left['mark_id'], 20, '0', STR_PAD_LEFT)
                : '1:' . str_pad((string) ($left['student_id'] ?? 0), 12, '0', STR_PAD_LEFT)
                    . ':' . str_pad((string) ($left['component_id'] ?? 0), 12, '0', STR_PAD_LEFT)
                    . ':' . str_pad((string) ($left['week_id'] ?? 0), 12, '0', STR_PAD_LEFT);
            $rightKey = !empty($right['mark_id'])
                ? '0:' . str_pad((string) $right['mark_id'], 20, '0', STR_PAD_LEFT)
                : '1:' . str_pad((string) ($right['student_id'] ?? 0), 12, '0', STR_PAD_LEFT)
                    . ':' . str_pad((string) ($right['component_id'] ?? 0), 12, '0', STR_PAD_LEFT)
                    . ':' . str_pad((string) ($right['week_id'] ?? 0), 12, '0', STR_PAD_LEFT);
            return $leftKey <=> $rightKey;
        });

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $batchId = UndoManager::newBatchId();
            $affected = 0;
            $publishedAffected = 0;
            $markIds = [];
            foreach ($normalizedChanges as $change) {
                $change['reason'] = $reason;
                if ((int) $change['mark_id'] > 0) {
                    $result = $this->updateMark(
                        (int) $change['mark_id'],
                        $change,
                        $selectedAcademicYearId,
                        $actorId,
                        $actorRole,
                        $batchId
                    );
                } else {
                    $result = $this->createMark(
                        (int) ($change['student_id'] ?? 0),
                        (int) ($change['window_id'] ?? 0),
                        (int) ($change['scheme_id'] ?? 0),
                        (int) ($change['component_id'] ?? 0),
                        $change['week_id'],
                        $change,
                        $selectedAcademicYearId,
                        $actorId,
                        $actorRole,
                        $batchId
                    );
                }

                if (empty($result['no_change'])) {
                    $affected++;
                }
                if ((int) ($result['mark_id'] ?? 0) > 0) {
                    $markIds[] = (int) $result['mark_id'];
                }
                $publishedAffected += (int) ($result['published_count'] ?? 0);
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return [
                'affected' => $affected,
                'batch_id' => $affected > 0 ? $batchId : '',
                'published_count' => $publishedAffected,
                'mark_ids' => array_values(array_unique($markIds)),
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function deleteMarks(
        array $markIds,
        int $selectedAcademicYearId,
        int $actorId,
        string $actorRole,
        string $reason
    ): array {
        $markIds = self::normalizeIds($markIds);
        if ($markIds === []) {
            throw new InvalidArgumentException('اختر درجة واحدة على الأقل للحذف.');
        }
        $reason = $this->assertReason($reason);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            (new SystemAdministratorRoleService($this->db))->assertActorCanManage($actorId, $actorRole);
            $placeholders = implode(',', array_fill(0, count($markIds), '?'));
            $stmt = $this->db->prepare("SELECT sm.*, student.name AS student_name
                FROM student_marks sm JOIN users student ON student.id = sm.student_id
                WHERE sm.id IN ({$placeholders}) ORDER BY sm.id{$this->lockClause()}");
            $stmt->execute($markIds);
            $marks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($marks) !== count($markIds)) {
                throw new RuntimeException('تغيرت مجموعة الدرجات قبل الحذف. أعد تحميل الصفحة وحاول مرة أخرى.');
            }
            foreach ($marks as $mark) {
                $this->assertSelectedYear($mark, $selectedAcademicYearId);
            }
            (new AcademicYearWriteGuard($this->db))->assertWritable($selectedAcademicYearId);

            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $publishedAffected = 0;
            foreach ($marks as $mark) {
                $publishedAffected += $this->publishedCountForMark($mark);
                $this->insertDomainAudit($mark, [], 'delete', $reason, $actorId);
                $audit->recordDelete(
                    'student_mark',
                    'student_marks',
                    (int) $mark['id'],
                    (string) ($mark['student_name'] ?? ('درجة #' . $mark['id'])),
                    $this->markSnapshot($mark),
                    'حذف إداري لدرجة طالب بواسطة مدير النظام الأعلى: ' . $reason,
                    $batchId
                );
            }

            $delete = $this->db->prepare("DELETE FROM student_marks WHERE id IN ({$placeholders})");
            $delete->execute($markIds);
            if ($delete->rowCount() !== count($markIds)) {
                throw new RuntimeException('لم تُحذف كل الدرجات المحددة؛ أُلغي الحذف بالكامل.');
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return ['affected' => count($markIds), 'batch_id' => $batchId, 'published_count' => $publishedAffected];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Super-admin exception: remove the access window but preserve canonical marks.
     */
    public function deleteWindowPreservingMarks(
        int $windowId,
        int $selectedAcademicYearId,
        int $actorId,
        string $actorRole,
        string $reason,
        string $confirmationName
    ): array {
        $reason = $this->assertReason($reason);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            (new SystemAdministratorRoleService($this->db))->assertActorCanManage($actorId, $actorRole);
            $stmt = $this->db->prepare('SELECT aw.*, scheme.academic_year_id
                FROM assessment_windows aw JOIN assessment_schemes scheme ON scheme.id = aw.scheme_id
                WHERE aw.id = ? LIMIT 1' . $this->lockClause());
            $stmt->execute([$windowId]);
            $window = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$window) {
                throw new InvalidArgumentException('نافذة الرصد غير موجودة.');
            }
            $this->assertSelectedYear($window, $selectedAcademicYearId);
            (new AcademicYearWriteGuard($this->db))->assertWritable((int) $window['academic_year_id']);
            if ((string) ($window['status'] ?? '') === 'open') {
                throw new RuntimeException('أغلق نافذة الرصد أولًا قبل الحذف الاستثنائي.');
            }
            if (!hash_equals(trim((string) $window['window_name']), trim($confirmationName))) {
                throw new InvalidArgumentException('اكتب اسم نافذة الرصد كاملًا لتأكيد الحذف الاستثنائي.');
            }

            $marksCount = $this->countWindowMarksByRow($window);
            $batchId = UndoManager::newBatchId();
            (new AuditService($this->db))->recordDelete(
                'assessment_window',
                'assessment_windows',
                $windowId,
                (string) $window['window_name'],
                $this->windowSnapshot($window),
                "حذف استثنائي لنافذة رصد مع الاحتفاظ بـ {$marksCount} درجة مرتبطة: {$reason}",
                $batchId
            );
            $delete = $this->db->prepare('DELETE FROM assessment_windows WHERE id = ?');
            $delete->execute([$windowId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('تعذر حذف نافذة الرصد. أُلغي الإجراء بالكامل.');
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return ['window_id' => $windowId, 'preserved_marks' => $marksCount, 'batch_id' => $batchId];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function fetchCreateContext(
        int $studentId,
        int $windowId,
        int $schemeId,
        int $componentId,
        ?int $weekId,
        int $academicYearId
    ): array {
        $sql = "SELECT window_row.*, scheme.academic_year_id, scheme.term_id, scheme.subject_id,
                    scheme.grade_id AS scheme_grade_id, scheme.status AS scheme_status,
                    scheme.enable_excused_absence, component.accepts_absence,
                    component.accepts_excused_absence, component.is_active AS component_is_active,
                    COALESCE(week_rule.max_grade_override, component.max_grade) AS max_grade,
                    week_rule.is_included AS week_rule_included,
                    (window_row.opens_at IS NULL OR window_row.opens_at <= NOW()) AS has_started,
                    (window_row.closes_at IS NULL OR window_row.closes_at >= NOW()) AS has_not_expired
                FROM assessment_windows window_row
                JOIN assessment_schemes scheme ON scheme.id = window_row.scheme_id
                JOIN assessment_components component ON component.id = window_row.component_id
                LEFT JOIN assessment_component_week_rules week_rule
                  ON week_rule.component_id = window_row.component_id AND week_rule.week_id = window_row.week_id
                WHERE window_row.id = ? LIMIT 1{$this->lockClause()}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$windowId]);
        $context = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$context) {
            throw new InvalidArgumentException('نافذة إنشاء الدرجة غير موجودة.');
        }

        $windowWeekId = $context['week_id'] !== null ? (int) $context['week_id'] : null;
        if ((int) $context['academic_year_id'] !== $academicYearId
            || (int) $context['scheme_id'] !== $schemeId
            || (int) $context['component_id'] !== $componentId
            || $windowWeekId !== $weekId) {
            throw new AssessmentMarkConflictException('تغير نطاق نافذة الرصد أو العمود. أعد تحميل الشيت.');
        }
        if ((string) $context['status'] !== 'open'
            || (int) $context['has_started'] !== 1
            || (int) $context['has_not_expired'] !== 1
            || (string) $context['scheme_status'] !== 'active'
            || (int) $context['component_is_active'] !== 1
            || ($windowWeekId !== null && $context['week_rule_included'] !== null && (int) $context['week_rule_included'] !== 1)) {
            throw new AssessmentMarkConflictException('أُغلقت نافذة الرصد أو لم تعد صالحة لهذه الخلية. أعد تحميل الشيت.');
        }

        $studentLockSelect = $this->tableExists('assessment_student_locks')
            ? 'EXISTS(SELECT 1 FROM assessment_student_locks student_lock WHERE student_lock.student_id = student.id AND student_lock.academic_year_id = ?)'
            : '0';
        $enrollmentParams = $this->tableExists('assessment_student_locks')
            ? [$academicYearId, $academicYearId, $studentId]
            : [$academicYearId, $studentId];
        $enrollmentStmt = $this->db->prepare("SELECT enrollment.grade_id, enrollment.class_id,
                student.name AS student_name, student.role, {$studentLockSelect} AS student_locked
            FROM users student
            JOIN student_enrollments enrollment ON enrollment.student_id = student.id
             AND enrollment.academic_year_id = ? AND enrollment.enrollment_status = 'enrolled'
            WHERE student.id = ? LIMIT 1{$this->lockClause()}");
        $enrollmentStmt->execute($enrollmentParams);
        $student = $enrollmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student || (string) ($student['role'] ?? '') !== 'student') {
            throw new AssessmentMarkConflictException('الطالب لم يعد مقيدًا في العام المختار. أعد تحميل الشيت.');
        }
        $studentClassId = (int) ($student['class_id'] ?? 0);
        if ((int) ($student['grade_id'] ?? 0) !== (int) $context['scheme_grade_id'] || $studentClassId <= 0) {
            throw new AssessmentMarkConflictException('تغير صف الطالب أو فصله، لذلك لا يمكن إنشاء الدرجة قبل إعادة تحميل الشيت.');
        }
        if ($context['class_id'] !== null && (int) $context['class_id'] !== $studentClassId) {
            throw new AssessmentMarkConflictException('نافذة الرصد لا تشمل فصل الطالب الحالي.');
        }
        if (!(new AssessmentSchemeScopeResolver($this->db))->schemeCoversClass(
            $schemeId,
            (int) $context['scheme_grade_id'],
            $studentClassId
        )) {
            throw new AssessmentMarkConflictException('فصل الطالب الحالي خارج نطاق خطة الدرجات. أعد تحميل الشيت.');
        }

        $matchingStmt = $this->db->prepare("SELECT candidate.id
            FROM assessment_windows candidate
            JOIN assessment_schemes candidate_scheme ON candidate_scheme.id = candidate.scheme_id
            JOIN assessment_components candidate_component ON candidate_component.id = candidate.component_id
            LEFT JOIN assessment_component_week_rules candidate_rule
              ON candidate_rule.component_id = candidate.component_id AND candidate_rule.week_id = candidate.week_id
            WHERE candidate.scheme_id = ? AND candidate.component_id = ?
              AND ((candidate.week_id IS NULL AND ? IS NULL) OR candidate.week_id = ?)
              AND (candidate.class_id IS NULL OR candidate.class_id = ?)
              AND candidate.status = 'open'
              AND (candidate.opens_at IS NULL OR candidate.opens_at <= NOW())
              AND (candidate.closes_at IS NULL OR candidate.closes_at >= NOW())
              AND candidate_scheme.academic_year_id = ? AND candidate_scheme.status = 'active'
              AND candidate_component.is_active = 1
              AND (candidate.week_id IS NULL OR candidate_rule.is_included IS NULL OR candidate_rule.is_included = 1)
            ORDER BY candidate.id{$this->lockClause()}");
        $matchingStmt->execute([$schemeId, $componentId, $weekId, $weekId, $studentClassId, $academicYearId]);
        $matchingWindowIds = array_map('intval', $matchingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($matchingWindowIds) !== 1 || $matchingWindowIds[0] !== $windowId) {
            throw new AssessmentMarkConflictException('توجد أكثر من نافذة صالحة لهذه الخلية أو لا توجد نافذة واحدة محددة. عالج تداخل النوافذ ثم أعد تحميل الشيت.');
        }

        $existingStmt = $this->db->prepare("SELECT * FROM student_marks
            WHERE student_id = ? AND component_id = ? AND week_slot = ?
              AND academic_year_id = ? AND term_id = ? LIMIT 1{$this->lockClause()}");
        $existingStmt->execute([$studentId, $componentId, $weekId ?? 0, $academicYearId, (int) $context['term_id']]);

        $context['student_name'] = (string) $student['student_name'];
        $context['student_class_id'] = $studentClassId;
        $context['student_locked'] = (int) ($student['student_locked'] ?? 0);
        $context['existing_mark'] = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $context;
    }

    private function fetchMarkForUpdate(int $markId): array
    {
        $weekRuleJoin = $this->tableExists('assessment_component_week_rules')
            ? 'LEFT JOIN assessment_component_week_rules cwr ON cwr.component_id = sm.component_id AND cwr.week_id = sm.week_id'
            : '';
        $maxGradeSelect = $this->tableExists('assessment_component_week_rules')
            ? 'COALESCE(cwr.max_grade_override, component.max_grade)'
            : 'component.max_grade';
        $studentLockSelect = $this->tableExists('assessment_student_locks')
            ? "EXISTS(SELECT 1 FROM assessment_student_locks asl WHERE asl.student_id = sm.student_id AND asl.academic_year_id = sm.academic_year_id)"
            : '0';
        $sql = "SELECT sm.*, student.name AS student_name, component.name AS component_name,
                component.accepts_absence, component.accepts_excused_absence, scheme.enable_excused_absence,
                {$maxGradeSelect} AS max_grade,
                {$studentLockSelect} AS student_locked,
                (SELECT COUNT(*) FROM assessment_windows locked_window
                    WHERE locked_window.scheme_id = sm.scheme_id AND locked_window.component_id = sm.component_id
                      AND (locked_window.week_id IS NULL OR locked_window.week_id = sm.week_id)
                      AND (locked_window.class_id IS NULL OR locked_window.class_id = sm.class_id_at_entry)
                      AND locked_window.status = 'locked') AS locked_window_count,
                (SELECT COUNT(*) FROM assessment_windows review_window
                    WHERE review_window.scheme_id = sm.scheme_id AND review_window.component_id = sm.component_id
                      AND (review_window.week_id IS NULL OR review_window.week_id = sm.week_id)
                      AND (review_window.class_id IS NULL OR review_window.class_id = sm.class_id_at_entry)
                      AND review_window.requires_review = 1) AS review_required
            FROM student_marks sm
            JOIN users student ON student.id = sm.student_id
            JOIN assessment_components component ON component.id = sm.component_id
            JOIN assessment_schemes scheme ON scheme.id = sm.scheme_id
            {$weekRuleJoin}
            WHERE sm.id = ? LIMIT 1{$this->lockClause()}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$markId]);
        $mark = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mark) {
            throw new InvalidArgumentException('الدرجة المطلوبة غير موجودة.');
        }
        $mark['published_count'] = $this->publishedCountForMark($mark);
        return $mark;
    }

    private function fetchMarkRow(int $markId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM student_marks WHERE id = ? LIMIT 1');
        $stmt->execute([$markId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('تعذر إعادة تحميل الدرجة بعد التعديل.');
        }
        return $row;
    }

    private function normalizeMarkPayload(array $payload, array $mark): array
    {
        $status = (string) ($payload['mark_status'] ?? 'present');
        if (!in_array($status, ['present', 'absent', 'excused_absent', 'exempt', 'empty'], true)) {
            throw new InvalidArgumentException('حالة الدرجة غير صحيحة.');
        }
        $note = trim((string) ($payload['note'] ?? ''));
        if (mb_strlen($note, 'UTF-8') > 500) {
            throw new InvalidArgumentException('ملاحظة الدرجة يجب ألا تتجاوز 500 حرف.');
        }

        $value = null;
        if ($status === 'present') {
            $normalized = AssessmentEngine::normalizeMarkInput(
                (string) ($payload['value'] ?? ''),
                (float) $mark['max_grade'],
                false,
                false
            );
            $value = $normalized['value'];
        } elseif ($status === 'absent' && empty($mark['accepts_absence'])) {
            throw new InvalidArgumentException('هذا البند لا يسمح بتسجيل الغياب بدل الدرجة.');
        } elseif ($status === 'excused_absent'
            && (empty($mark['enable_excused_absence']) || empty($mark['accepts_excused_absence']))) {
            throw new InvalidArgumentException('هذا البند لا يسمح بتسجيل الغياب بعذر.');
        }

        return ['value' => $value, 'status' => $status, 'note' => $note !== '' ? $note : null];
    }

    private function hasExpectedState(array $payload): bool
    {
        return array_key_exists('expected_status', $payload)
            || array_key_exists('expected_value', $payload)
            || array_key_exists('expected_note', $payload)
            || array_key_exists('expected_updated_at', $payload);
    }

    private function matchesExpectedState(array $mark, array $payload): bool
    {
        if (array_key_exists('expected_status', $payload)
            && (string) ($mark['mark_status'] ?? 'empty') !== (string) $payload['expected_status']) {
            return false;
        }
        if (array_key_exists('expected_note', $payload)
            && trim((string) ($mark['note'] ?? '')) !== trim((string) $payload['expected_note'])) {
            return false;
        }
        if (array_key_exists('expected_updated_at', $payload)
            && (string) ($mark['updated_at'] ?? '') !== (string) $payload['expected_updated_at']) {
            return false;
        }
        if (array_key_exists('expected_value', $payload)) {
            $expected = $payload['expected_value'] === '' || $payload['expected_value'] === null
                ? null
                : (float) $payload['expected_value'];
            $current = $mark['value'] !== null ? (float) $mark['value'] : null;
            if (($expected === null) !== ($current === null)) {
                return false;
            }
            if ($expected !== null && $current !== null && abs($expected - $current) > 0.001) {
                return false;
            }
        }
        return true;
    }

    private function matchesNormalizedState(array $mark, array $normalized): bool
    {
        $currentValue = $mark['value'] !== null ? (float) $mark['value'] : null;
        $nextValue = $normalized['value'] !== null ? (float) $normalized['value'] : null;
        $sameValue = ($currentValue === null && $nextValue === null)
            || ($currentValue !== null && $nextValue !== null && abs($currentValue - $nextValue) <= 0.001);
        return $sameValue
            && (string) ($mark['mark_status'] ?? AssessmentEngine::STATUS_EMPTY) === (string) $normalized['status']
            && trim((string) ($mark['note'] ?? '')) === trim((string) ($normalized['note'] ?? ''));
    }

    private function insertDomainAudit(array $before, array $after, string $action, string $reason, int $actorId): void
    {
        if (!$this->tableExists('student_mark_audit')) {
            throw new RuntimeException('جدول سجل تعديل الدرجات غير متاح؛ أُلغي التغيير.');
        }
        $stmt = $this->db->prepare('INSERT INTO student_mark_audit
            (mark_id, student_id, action, old_value, new_value, old_status, new_status, reason, changed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            (int) ($before['id'] ?? $after['id'] ?? 0) ?: null,
            (int) ($before['student_id'] ?? $after['student_id'] ?? 0),
            $action,
            array_key_exists('value', $before) && $before['value'] !== null ? (string) $before['value'] : null,
            array_key_exists('value', $after) && $after['value'] !== null ? (string) $after['value'] : null,
            $before['mark_status'] ?? null,
            $after['mark_status'] ?? null,
            $reason,
            $actorId > 0 ? $actorId : null,
        ]);
    }

    private function publishedCountForMark(array $mark): int
    {
        if (!$this->tableExists('published_report_details') || !$this->tableExists('published_reports')) {
            return 0;
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM published_report_details prd
            JOIN published_reports pr ON pr.id = prd.published_report_id
            WHERE pr.student_id = ? AND pr.academic_year_id = ? AND prd.component_id = ?
              AND ((prd.week_id IS NULL AND ? IS NULL) OR prd.week_id = ?)');
        $weekId = $mark['week_id'] !== null ? (int) $mark['week_id'] : null;
        $stmt->execute([(int) $mark['student_id'], (int) $mark['academic_year_id'], (int) $mark['component_id'], $weekId, $weekId]);
        return (int) $stmt->fetchColumn();
    }

    private function countWindowMarksByRow(array $window): int
    {
        $where = ['scheme_id = ?', 'component_id = ?'];
        $params = [(int) $window['scheme_id'], (int) $window['component_id']];
        if ($window['week_id'] !== null) {
            $where[] = 'week_id = ?';
            $params[] = (int) $window['week_id'];
        }
        if ($window['class_id'] !== null) {
            $where[] = 'class_id_at_entry = ?';
            $params[] = (int) $window['class_id'];
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM student_marks WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function assertSelectedYear(array $row, int $selectedAcademicYearId): void
    {
        if ($selectedAcademicYearId <= 0 || (int) ($row['academic_year_id'] ?? 0) !== $selectedAcademicYearId) {
            throw new RuntimeException('لا يمكن تعديل بيانات درجات خارج العام الدراسي المختار.');
        }
    }

    private function assertReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new InvalidArgumentException('اكتب سببًا واضحًا من خمسة أحرف على الأقل.');
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('سبب العملية يجب ألا يتجاوز 500 حرف.');
        }
        return $reason;
    }

    private function markSnapshot(array $row): array
    {
        $columns = ['id', 'student_id', 'scheme_id', 'component_id', 'week_id', 'week_slot', 'academic_year_id', 'term_id',
            'subject_id', 'grade_id', 'class_id_at_entry', 'value', 'mark_status', 'note', 'recorded_by', 'reviewed_by',
            'review_status', 'reviewed_at', 'review_note', 'locked_at', 'created_at', 'updated_at'];
        return array_intersect_key($row, array_flip($columns));
    }

    private function windowSnapshot(array $row): array
    {
        $columns = ['id', 'scheme_id', 'component_id', 'week_id', 'grade_id', 'class_id', 'teacher_id', 'window_name',
            'opens_at', 'closes_at', 'status', 'allow_edit_after_save', 'requires_review', 'opened_by', 'created_at', 'updated_at'];
        return array_intersect_key($row, array_flip($columns));
    }

    private function lockClause(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        }
        $stmt->execute([$table]);
        return $this->tableCache[$table] = (bool) $stmt->fetchColumn();
    }
}
