<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;

require_once __DIR__ . '/AssessmentSchemeScopeResolver.php';
require_once __DIR__ . '/UndoManager.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

/**
 * Calculates the operational readiness of a plan without changing its lifecycle.
 *
 * A scheme can remain a draft while the material link or assessment components are
 * still being prepared.  Readiness is intentionally separate from `status`: only
 * an explicit activation command can make a ready plan active.
 */
final class AssessmentSchemeReadinessService
{
    private PDO $db;
    private AssessmentSchemeScopeResolver $scopeResolver;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(PDO $db, ?AssessmentSchemeScopeResolver $scopeResolver = null)
    {
        $this->db = $db;
        $this->scopeResolver = $scopeResolver ?: new AssessmentSchemeScopeResolver($db);
    }

    /**
     * @return array{scheme_id:int,status:string,reason:?string,changed:bool}
     */
    public function refresh(int $schemeId, ?string $batchId = null, bool $audit = true): array
    {
        if ($schemeId <= 0) {
            throw new InvalidArgumentException('خطة الدرجات غير صالحة.');
        }
        if (!$this->supportsReadiness()) {
            return [
                'scheme_id' => $schemeId,
                'status' => 'legacy',
                'reason' => 'تحتاج قاعدة البيانات إلى ترقية خطط الدرجات.',
                'changed' => false,
            ];
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1' . $this->lockClause());
            $stmt->execute([$schemeId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('خطة الدرجات غير موجودة.');
            }

            $computed = $this->compute($before);
            $changed = (string) ($before['readiness_status'] ?? '') !== $computed['status']
                || (string) ($before['readiness_reason'] ?? '') !== (string) ($computed['reason'] ?? '');
            if ($changed) {
                $update = $this->db->prepare('UPDATE assessment_schemes SET readiness_status = ?, readiness_reason = ? WHERE id = ?');
                $update->execute([$computed['status'], $computed['reason'], $schemeId]);
                $after = $before;
                $after['readiness_status'] = $computed['status'];
                $after['readiness_reason'] = $computed['reason'];

                if ($audit) {
                    (new AuditService($this->db))->recordUpdate(
                        'assessment_scheme_readiness',
                        'assessment_schemes',
                        $schemeId,
                        (string) ($before['name'] ?? ('خطة #' . $schemeId)),
                        $before,
                        $after,
                        'تحديث جاهزية خطة الدرجات',
                        $batchId
                    );
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'scheme_id' => $schemeId,
                'status' => $computed['status'],
                'reason' => $computed['reason'],
                'changed' => $changed,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Syncs plans affected by a subject-link change. The caller must be in the
     * same transaction as the link mutation when one is already open.
     *
     * @return list<array{scheme_id:int,status:string,reason:?string,changed:bool}>
     */
    public function refreshForSubject(int $academicYearId, int $subjectId, ?string $batchId = null, bool $audit = true): array
    {
        if ($academicYearId <= 0 || $subjectId <= 0 || !$this->supportsReadiness()) {
            return [];
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare("SELECT id FROM assessment_schemes
                WHERE academic_year_id = ? AND subject_id = ? AND status <> 'archived'
                ORDER BY id" . $this->lockClause());
            $stmt->execute([$academicYearId, $subjectId]);
            $results = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $schemeId) {
                $results[] = $this->refresh((int) $schemeId, $batchId, $audit);
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $results;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{status:string,reason:?string} */
    private function compute(array $scheme): array
    {
        $schemeId = (int) $scheme['id'];
        $scopes = $this->scopeResolver->scopesForScheme($schemeId, $this->db->inTransaction());
        if ($scopes === []) {
            return ['status' => 'waiting_for_subject_link', 'reason' => 'لم يُحدد نطاق صف أو فصل للخطة.'];
        }

        $missingScope = $this->scopeResolver->firstMissingSubjectLink(
            (int) $scheme['academic_year_id'],
            (int) $scheme['term_id'],
            (int) $scheme['subject_id'],
            $scopes
        );
        if ($missingScope !== null) {
            return [
                'status' => 'waiting_for_subject_link',
                'reason' => $missingScope['class_id'] === null
                    ? 'ينتظر ربط المادة بالصف قبل التفعيل.'
                    : 'ينتظر ربط المادة بالفصل المحدد قبل التفعيل.',
            ];
        }

        if (!$this->tableExists('assessment_components')) {
            return ['status' => 'needs_components', 'reason' => 'تحتاج الخطة إلى بنود تقييم قبل التفعيل.'];
        }
        $components = $this->db->prepare('SELECT COALESCE(SUM(max_grade), 0) AS total, COUNT(*) AS count
            FROM assessment_components WHERE scheme_id = ? AND is_active = 1 AND counts_in_total = 1');
        $components->execute([$schemeId]);
        $summary = $components->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($summary['count'] ?? 0) <= 0) {
            return ['status' => 'needs_components', 'reason' => 'تحتاج الخطة إلى بند تقييم فعال داخل المجموع.'];
        }
        if (abs((float) ($summary['total'] ?? 0) - (float) ($scheme['total_grade'] ?? 0)) > 0.01) {
            return ['status' => 'needs_components', 'reason' => 'مجموع بنود الخطة لا يطابق مجموعها الكلي.'];
        }

        return ['status' => 'ready', 'reason' => null];
    }

    private function supportsReadiness(): bool
    {
        return $this->tableExists('assessment_schemes')
            && $this->columnExists('assessment_schemes', 'readiness_status')
            && $this->columnExists('assessment_schemes', 'readiness_reason');
    }

    private function lockClause(): string
    {
        return $this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute([$table]);
            return $this->tableCache[$table] = (bool) $stmt->fetchColumn();
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $this->tableCache[$table] = (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db->query("PRAGMA table_info(`{$table}`)");
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (($row['name'] ?? '') === $column) {
                    return $this->columnCache[$key] = true;
                }
            }
            return $this->columnCache[$key] = false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return $this->columnCache[$key] = (bool) $stmt->fetchColumn();
    }
}
