<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;

require_once __DIR__ . '/AcademicYearWriteGuard.php';
require_once __DIR__ . '/AssessmentEngine.php';
require_once __DIR__ . '/UndoManager.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

final class AssessmentWindowLifecycleService
{
    private const TRANSITIONS = [
        'draft' => ['open'],
        'open' => ['closed'],
        'closed' => ['open', 'locked'],
        'locked' => ['open'],
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array{window_id:int,old_status:string,new_status:string,batch_id:string,review:array<string,int>}
     */
    public function transition(
        int $windowId,
        string $targetStatus,
        int $actorId,
        string $actorRole,
        string $reason = '',
        ?string $reopenClosesAt = null,
        ?string $batchId = null
    ): array {
        $targetStatus = trim($targetStatus);
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('سبب تغيير الحالة يجب ألا يتجاوز 500 حرف.');
        }
        if ($windowId <= 0 || !in_array($targetStatus, ['open', 'closed', 'locked'], true)) {
            throw new InvalidArgumentException('بيانات تغيير حالة نافذة الرصد غير صحيحة.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $window = $this->fetchWindow($windowId, true);
            if (!$window) {
                throw new InvalidArgumentException('نافذة الرصد غير موجودة.');
            }
            $beforeSnapshot = $this->fetchWindowSnapshot($windowId);
            if (!$beforeSnapshot) {
                throw new RuntimeException('تعذر التقاط حالة نافذة الرصد قبل التغيير.');
            }

            (new AcademicYearWriteGuard($this->db))->assertWritable((int) $window['guarded_academic_year_id']);
            $sourceStatus = (string) ($window['status'] ?? '');
            if (!in_array($targetStatus, self::TRANSITIONS[$sourceStatus] ?? [], true)) {
                throw new RuntimeException($this->invalidTransitionMessage($sourceStatus, $targetStatus));
            }

            $review = $this->reviewSummary($window);
            if ($targetStatus === 'locked') {
                if ($reason === '') {
                    throw new InvalidArgumentException('اكتب سبب القفل النهائي.');
                }
                if (!empty($window['requires_review']) && $review['needs_review'] > 0) {
                    throw new RuntimeException(
                        'لا يمكن قفل النافذة قبل اكتمال المراجعة. توجد '
                        . $review['needs_review'] . ' درجة معلقة أو مرفوضة أو غير معتمدة.'
                    );
                }
            }

            $normalizedClose = $this->normalizeFutureClose($reopenClosesAt);
            if ($targetStatus === 'open' && in_array($sourceStatus, ['closed', 'locked'], true)) {
                if ($normalizedClose === null) {
                    throw new InvalidArgumentException('حدد موعد إغلاق جديدًا عند إعادة فتح النافذة.');
                }
                if ($reason === '') {
                    throw new InvalidArgumentException('اكتب سبب إعادة فتح النافذة.');
                }
            }

            if ($sourceStatus === 'locked' && $targetStatus === 'open'
                && !$this->canReopenLocked($actorId, $actorRole, (int) $window['scheme_id'])) {
                throw new RuntimeException('ليس لديك صلاحية إعادة فتح نافذة مقفلة نهائيًا.');
            }

            if ($targetStatus === 'open') {
                $effectiveClose = $normalizedClose;
                if ($sourceStatus === 'draft' && $effectiveClose === null && !empty($window['closes_at'])) {
                    $effectiveClose = $this->normalizeFutureClose((string) $window['closes_at']);
                }
                $stmt = $this->db->prepare(
                    'UPDATE assessment_windows SET status = ?, opened_by = ?, opens_at = NOW(), closes_at = ? WHERE id = ?'
                );
                $stmt->execute([$targetStatus, $actorId > 0 ? $actorId : null, $effectiveClose, $windowId]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE assessment_windows SET status = ?, closes_at = NOW() WHERE id = ?'
                );
                $stmt->execute([$targetStatus, $windowId]);
            }

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('لم تتغير حالة نافذة الرصد.');
            }

            $after = $this->fetchWindow($windowId, false);
            $afterSnapshot = $this->fetchWindowSnapshot($windowId);
            if (!$after || !$afterSnapshot) {
                throw new RuntimeException('تعذر إعادة تحميل نافذة الرصد بعد تغيير حالتها.');
            }

            $batchId = $batchId ?: UndoManager::newBatchId();
            (new AuditService($this->db))->recordUpdate(
                'assessment_window',
                'assessment_windows',
                $windowId,
                (string) ($window['window_name'] ?? ('نافذة #' . $windowId)),
                $beforeSnapshot,
                $afterSnapshot,
                'تغيير دورة حالة نافذة الرصد: ' . $sourceStatus . ' → ' . $targetStatus
                    . ($reason !== '' ? ' | السبب: ' . $reason : ''),
                $batchId
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'window_id' => $windowId,
                'old_status' => $sourceStatus,
                'new_status' => $targetStatus,
                'batch_id' => $batchId,
                'review' => $review,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{total:int,pending:int,rejected:int,not_required:int,approved:int,needs_review:int} */
    public function reviewSummaryForWindow(int $windowId): array
    {
        $window = $this->fetchWindow($windowId, false);
        if (!$window) {
            throw new InvalidArgumentException('نافذة الرصد غير موجودة.');
        }
        return $this->reviewSummary($window);
    }

    private function fetchWindow(int $windowId, bool $lock): ?array
    {
        $sql = 'SELECT aw.*, sch.academic_year_id AS guarded_academic_year_id
            FROM assessment_windows aw
            JOIN assessment_schemes sch ON sch.id = aw.scheme_id
            WHERE aw.id = ? LIMIT 1';
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$windowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchWindowSnapshot(int $windowId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM assessment_windows WHERE id = ? LIMIT 1');
        $stmt->execute([$windowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{total:int,pending:int,rejected:int,not_required:int,approved:int,needs_review:int} */
    private function reviewSummary(array $window): array
    {
        $sql = "SELECT COUNT(*) AS total,
                SUM(review_status = 'pending') AS pending,
                SUM(review_status = 'rejected') AS rejected,
                SUM(review_status = 'not_required') AS not_required,
                SUM(review_status = 'approved') AS approved,
                SUM(review_status <> 'approved') AS needs_review
            FROM student_marks
            WHERE scheme_id = ? AND component_id = ?
              AND ((week_id IS NULL AND ? IS NULL) OR week_id = ?)
              AND (? IS NULL OR class_id_at_entry = ?)";
        $weekId = $window['week_id'] !== null ? (int) $window['week_id'] : null;
        $classId = $window['class_id'] !== null ? (int) $window['class_id'] : null;
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (int) $window['scheme_id'],
            (int) $window['component_id'],
            $weekId,
            $weekId,
            $classId,
            $classId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'not_required' => (int) ($row['not_required'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'needs_review' => (int) ($row['needs_review'] ?? 0),
        ];
    }

    private function canReopenLocked(int $actorId, string $actorRole, int $schemeId): bool
    {
        if ($actorRole === 'super_admin') {
            return true;
        }
        if ($actorId <= 0 || $actorRole === '' || !$this->tableExists('assessment_permissions')) {
            return false;
        }
        return (new AssessmentEngine($this->db))->userHasPermission(
            $actorId,
            $actorRole,
            'reopen_window',
            'scheme',
            $schemeId
        );
    }

    private function normalizeFutureClose(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if (!$date) {
            throw new InvalidArgumentException('موعد الإغلاق الجديد غير صحيح.');
        }
        if ($date <= new DateTimeImmutable('now')) {
            throw new InvalidArgumentException('موعد الإغلاق الجديد يجب أن يكون في المستقبل.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function invalidTransitionMessage(string $source, string $target): string
    {
        $labels = ['draft' => 'مسودة', 'open' => 'مفتوحة', 'closed' => 'مغلقة', 'locked' => 'مقفلة'];
        return 'لا يمكن نقل النافذة من «' . ($labels[$source] ?? $source)
            . '» إلى «' . ($labels[$target] ?? $target) . '» مباشرة.';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
