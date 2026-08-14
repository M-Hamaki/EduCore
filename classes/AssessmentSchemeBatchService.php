<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;

require_once __DIR__ . '/AcademicYearWriteGuard.php';
require_once __DIR__ . '/AssessmentAnnualPolicyService.php';
require_once __DIR__ . '/AssessmentEngine.php';
require_once __DIR__ . '/AssessmentSchemeReadinessService.php';
require_once __DIR__ . '/AssessmentSchemeScopeResolver.php';
require_once __DIR__ . '/UndoManager.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

/**
 * Creates a coherent set of term schemes from one administrator request.
 *
 * Each grade receives its own family, each selected term receives its own scheme,
 * and every scheme keeps an explicit grade/class scope.  That keeps the old
 * one-term scheme contract intact while giving the administrator one safe batch
 * operation to manage.
 */
final class AssessmentSchemeBatchService
{
    private PDO $db;
    private AssessmentSchemeScopeResolver $scopeResolver;
    private AssessmentSchemeReadinessService $readiness;
    private AssessmentAnnualPolicyService $annualPolicy;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->scopeResolver = new AssessmentSchemeScopeResolver($db);
        $this->readiness = new AssessmentSchemeReadinessService($db, $this->scopeResolver);
        $this->annualPolicy = new AssessmentAnnualPolicyService($db);
    }

    /**
     * Produces a read-only, server-validated preview suitable for confirmation.
     *
     * @return array<string,mixed>
     */
    public function preview(int $academicYearId, array $input): array
    {
        return $this->buildPlan($academicYearId, $input);
    }

    /**
     * @return array{batch_id:string,scheme_ids:list<int>,family_ids:list<int>,idempotent:bool,readiness:array<int,array{status:string,reason:?string}>}
     */
    public function create(int $academicYearId, array $input, ?int $actorId = null): array
    {
        $this->assertSchemaReady();
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $plan = $this->buildPlan($academicYearId, $input);
        $requestKey = (string) $plan['request_key'];
        $legacyExisting = $this->existingRequestFamilies(
            $academicYearId,
            (array) ($plan['legacy_family_request_keys'] ?? [])
        );
        if ($legacyExisting !== []) {
            throw new RuntimeException('هذا الطلب أُنشئ بإصدار أقدم من ميزة منع التكرار. افتح نموذج إنشاء جديد حتى لا تُنشأ مجموعة مكررة.');
        }
        $existing = $this->existingRequestFamilies($academicYearId, $plan['family_request_keys']);
        if ($existing !== []) {
            if (count($existing) !== count($plan['family_request_keys'])) {
                throw new RuntimeException('تعذر استكمال طلب قديم بصورة آمنة. أنشئ طلبًا جديدًا بدلًا من إعادة استخدام نفس الطلب.');
            }
            $familyIds = array_map('intval', array_column($existing, 'id'));
            $schemeIds = $this->schemesForFamilies($familyIds);
            return [
                'batch_id' => (string) ($existing[0]['batch_id'] ?? ''),
                'scheme_ids' => $schemeIds,
                'family_ids' => $familyIds,
                'idempotent' => true,
                'readiness' => $this->readinessSummary($schemeIds),
            ];
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->lockCandidateSchemes($academicYearId, (int) $plan['subject']['id'], array_keys($plan['terms']));
            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $engine = new AssessmentEngine($this->db);
            $actorId = $actorId && $actorId > 0 ? $actorId : null;
            $familyIds = [];
            $schemeIds = [];
            $readiness = [];

            foreach ($plan['grades'] as $gradeId => $gradePlan) {
                $familyRequestKey = (string) $plan['family_request_keys'][$gradeId];
                $familyId = $this->insertFamily(
                    $academicYearId,
                    (int) $plan['subject']['id'],
                    (string) $plan['settings']['name'],
                    $familyRequestKey,
                    $batchId,
                    $actorId
                );
                $familyIds[] = $familyId;
                $audit->recordInsert(
                    'assessment_scheme_family',
                    'assessment_scheme_families',
                    $familyId,
                    (string) $plan['settings']['name'],
                    $this->fetchById('assessment_scheme_families', $familyId),
                    'إنشاء عائلة خطط درجات جماعية',
                    $batchId,
                    ['request_key' => $requestKey, 'grade_id' => (int) $gradeId]
                );

                foreach ($plan['terms'] as $termId => $term) {
                    $schemeId = $this->insertScheme(
                        $familyId,
                        $academicYearId,
                        (int) $termId,
                        $plan,
                        $gradePlan,
                        $term,
                        $batchId,
                        $actorId
                    );
                    $schemeIds[] = $schemeId;
                    $audit->recordInsert(
                        'assessment_scheme',
                        'assessment_schemes',
                        $schemeId,
                        $this->schemeName((string) $plan['settings']['name'], (string) $term['name'], count($plan['terms'])),
                        $this->fetchById('assessment_schemes', $schemeId),
                        'إنشاء خطة درجات ضمن عملية جماعية',
                        $batchId,
                        ['family_id' => $familyId, 'grade_id' => (int) $gradeId, 'term_id' => (int) $termId]
                    );

                    foreach ($gradePlan['scopes'] as $scope) {
                        $scopeId = $this->insertScope($schemeId, $scope);
                        $audit->recordInsert(
                            'assessment_scheme_scope',
                            'assessment_scheme_scopes',
                            $scopeId,
                            'نطاق خطة #' . $schemeId,
                            $this->fetchById('assessment_scheme_scopes', $scopeId),
                            'تحديد نطاق صف أو فصل لخطة الدرجات',
                            $batchId,
                            ['scheme_id' => $schemeId]
                        );
                    }

                    if ($plan['template'] !== null) {
                        $sourceTermId = (int) $plan['template']['term_id'];
                        $sourceTotal = (float) $plan['template']['total_grade'];
                        $scale = !empty($plan['settings']['scale_template_components']) && $sourceTotal > 0
                            ? (float) $plan['settings']['total_grade'] / $sourceTotal
                            : 1.0;
                        $engine->copySchemeComponents(
                            (int) $plan['template']['id'],
                            $schemeId,
                            $scale,
                            $batchId,
                            $sourceTermId === (int) $termId
                        );
                    }

                    $ready = $this->readiness->refresh($schemeId, $batchId, true);
                    $readiness[$schemeId] = ['status' => $ready['status'], 'reason' => $ready['reason']];
                }

                if (!empty($plan['annual']['enabled'])) {
                    $policyId = $this->insertAnnualPolicy($familyId, $actorId);
                    $audit->recordInsert(
                        'assessment_annual_policy',
                        'assessment_annual_policies',
                        $policyId,
                        'سياسة نهاية العام',
                        $this->fetchById('assessment_annual_policies', $policyId),
                        'إنشاء سياسة أوزان نهاية العام',
                        $batchId,
                        ['family_id' => $familyId]
                    );
                    foreach ($plan['annual']['weights'] as $termId => $weight) {
                        $weightId = $this->insertAnnualPolicyWeight($policyId, (int) $termId, (float) $weight);
                        $audit->recordInsert(
                            'assessment_annual_policy_term',
                            'assessment_annual_policy_terms',
                            $weightId,
                            'وزن ترم في نهاية العام',
                            $this->fetchById('assessment_annual_policy_terms', $weightId),
                            'تحديد وزن ترم في سياسة نهاية العام',
                            $batchId,
                            ['family_id' => $familyId]
                        );
                    }
                }
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
            return [
                'batch_id' => $batchId,
                'scheme_ids' => $schemeIds,
                'family_ids' => $familyIds,
                'idempotent' => false,
                'readiness' => $readiness,
            ];
        } catch (Throwable $error) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // The unique family request key is also the idempotency lock.  A
            // concurrent double-click may lose that race after both requests
            // passed the initial lookup; return the already-completed batch
            // instead of exposing a duplicate-key database error.
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
                $existing = $this->existingRequestFamilies($academicYearId, $plan['family_request_keys']);
                if (count($existing) === count($plan['family_request_keys'])) {
                    $familyIds = array_map('intval', array_column($existing, 'id'));
                    $schemeIds = $this->schemesForFamilies($familyIds);
                    return [
                        'batch_id' => (string) ($existing[0]['batch_id'] ?? ''),
                        'scheme_ids' => $schemeIds,
                        'family_ids' => $familyIds,
                        'idempotent' => true,
                        'readiness' => $this->readinessSummary($schemeIds),
                    ];
                }
            }
            throw $error;
        }
    }

    /**
     * Activates a set of ready draft schemes atomically.
     *
     * @param list<int>|array<int,mixed> $schemeIds
     * @return list<int>
     */
    public function activate(int $academicYearId, array $schemeIds, ?string $batchId = null): array
    {
        $schemeIds = array_values(array_unique(array_filter(array_map('intval', $schemeIds), static fn(int $id): bool => $id > 0)));
        if ($schemeIds === []) {
            throw new InvalidArgumentException('حدد خطة واحدة على الأقل للتفعيل.');
        }
        $this->assertSchemaReady();
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $rows = $this->fetchSchemesForActivation($academicYearId, $schemeIds, true);
            if (count($rows) !== count($schemeIds)) {
                throw new RuntimeException('تتضمن القائمة خطة خارج العام الدراسي الحالي أو غير موجودة.');
            }
            $batchId = $batchId ?: UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $selectedScopes = [];
            foreach ($rows as $rowIndex => $row) {
                $ready = $this->readiness->refresh((int) $row['id'], $batchId, true);
                if ($ready['status'] !== 'ready') {
                    throw new RuntimeException('الخطة «' . $row['name'] . '» غير جاهزة: ' . ($ready['reason'] ?? 'راجع ربط المادة والبنود.'));
                }
                // Readiness may have been refreshed just above; use the current
                // persisted row as the before snapshot for the lifecycle audit.
                $row = $this->fetchById('assessment_schemes', (int) $row['id']);
                $rows[$rowIndex] = $row;
                foreach ($this->scopeResolver->scopesForScheme((int) $row['id'], true) as $scope) {
                    foreach ($selectedScopes as $selectedScope) {
                        if ((int) $selectedScope['term_id'] === (int) $row['term_id']
                            && (int) $selectedScope['subject_id'] === (int) $row['subject_id']
                            && $this->scopeResolver->scopesOverlap($scope, $selectedScope['scope'])) {
                            throw new RuntimeException('تحتوي الخطط المحددة على تداخل داخلي لنفس المادة والترم والنطاق.');
                        }
                    }
                    $selectedScopes[] = [
                        'term_id' => (int) $row['term_id'],
                        'subject_id' => (int) $row['subject_id'],
                        'scope' => $scope,
                    ];
                    $this->scopeResolver->assertNoActiveOverlap(
                        $academicYearId,
                        (int) $row['term_id'],
                        (int) $row['subject_id'],
                        $scope,
                        $schemeIds
                    );
                }
            }

            $activate = $this->db->prepare("UPDATE assessment_schemes SET status = 'active' WHERE id = ? AND status <> 'active'");
            foreach ($rows as $row) {
                if ((string) $row['status'] === 'active') {
                    continue;
                }
                $activate->execute([(int) $row['id']]);
                $after = $row;
                $after['status'] = 'active';
                $audit->recordUpdate(
                    'assessment_scheme',
                    'assessment_schemes',
                    (int) $row['id'],
                    (string) $row['name'],
                    $row,
                    $after,
                    'تفعيل خطة درجات جاهزة',
                    $batchId
                );
            }
            if ($startedTransaction) {
                $this->db->commit();
            }
            return $schemeIds;
        } catch (Throwable $error) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Activates every term plan in one family as a single atomic action.
     *
     * A family is deliberately the only lifecycle owner for grouped plans: a
     * user must never end up with one term active and its sibling term silently
     * left as a draft because they used a row action in the legacy list.
     *
     * @return list<int>
     */
    public function activateFamily(int $academicYearId, int $familyId): array
    {
        $this->assertSchemaReady();
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $family = $this->family($academicYearId, $familyId, true);
            $familySchemes = $this->fetchSchemesForFamily((int) $family['id'], true);
            if ($familySchemes === []) {
                throw new RuntimeException('لا تحتوي مجموعة الخطة على أي ترم قابل للتفعيل.');
            }
            $firstScheme = $familySchemes[0];
            $annual = $this->annualPolicy->policyForScheme(
                (int) $firstScheme['id'],
                !empty($firstScheme['annual_result_enabled']),
                (float) ($firstScheme['first_term_weight'] ?? 50),
                (float) ($firstScheme['second_term_weight'] ?? 50)
            );
            if (!empty($annual['enabled'])) {
                $positiveWeights = array_filter(
                    (array) ($annual['weights_by_term_id'] ?? []),
                    static fn($weight): bool => (float) $weight > 0
                );
                $familyTermIds = array_fill_keys(array_map(
                    static fn(array $scheme): int => (int) $scheme['term_id'],
                    $familySchemes
                ), true);
                $hasForeignTerm = count(array_diff(array_map('intval', array_keys($positiveWeights)), array_keys($familyTermIds))) > 0;
                if (empty($annual['valid']) || count($positiveWeights) < 2 || $hasForeignTerm) {
                    throw new RuntimeException('سياسة نهاية العام للمجموعة غير صالحة. راجع أوزان الترمات قبل التفعيل.');
                }
            }
            $batchId = UndoManager::newBatchId();
            $changed = $this->activate(
                $academicYearId,
                array_map(static fn(array $scheme): int => (int) $scheme['id'], $familySchemes),
                $batchId
            );

            $beforeFamily = $this->fetchById('assessment_scheme_families', (int) $family['id']);
            if ($beforeFamily['archived_at'] !== null) {
                $restoreFamily = $this->db->prepare('UPDATE assessment_scheme_families SET archived_at = NULL WHERE id = ?');
                $restoreFamily->execute([(int) $family['id']]);
                $afterFamily = $beforeFamily;
                $afterFamily['archived_at'] = null;
                (new AuditService($this->db))->recordUpdate(
                    'assessment_scheme_family',
                    'assessment_scheme_families',
                    (int) $family['id'],
                    (string) $family['name'],
                    $beforeFamily,
                    $afterFamily,
                    'إعادة تفعيل مجموعة خطط درجات مترابطة',
                    $batchId
                );
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
            return $changed;
        } catch (Throwable $error) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Archives every term plan in a family atomically.  This is a lifecycle
     * operation, so records with marks/reports remain intact and are not
     * deleted.
     *
     * @return list<int>
     */
    public function archiveFamily(int $academicYearId, int $familyId): array
    {
        $this->assertSchemaReady();
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $family = $this->family($academicYearId, $familyId, true);
            $rows = $this->fetchSchemesForFamily((int) $family['id'], true);
            if ($rows === []) {
                throw new RuntimeException('لا تحتوي مجموعة الخطة على أي ترم قابل للإدارة.');
            }

            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $archive = $this->db->prepare("UPDATE assessment_schemes SET status = 'archived' WHERE id = ? AND status <> 'archived'");
            $changed = [];
            foreach ($rows as $row) {
                if ((string) $row['status'] === 'archived') {
                    continue;
                }
                // fetchSchemesForFamily() includes display-only joins such as
                // term_name and components_total.  Audit/undo snapshots must
                // contain only real assessment_schemes columns.
                $before = $this->fetchById('assessment_schemes', (int) $row['id']);
                $archive->execute([(int) $row['id']]);
                $after = $before;
                $after['status'] = 'archived';
                $audit->recordUpdate(
                    'assessment_scheme',
                    'assessment_schemes',
                    (int) $row['id'],
                    (string) $row['name'],
                    $before,
                    $after,
                    'تعطيل خطة درجات ضمن مجموعة مترابطة',
                    $batchId
                );
                $changed[] = (int) $row['id'];
            }
            if ($changed === []) {
                throw new RuntimeException('كل خطط هذه المجموعة معطلة بالفعل.');
            }

            // Keep the aggregate record in the same state as its term plans.
            // Fetching it by id intentionally excludes the joined subject label
            // returned by family(), so the undo snapshot contains table fields
            // only.
            $beforeFamily = $this->fetchById('assessment_scheme_families', (int) $family['id']);
            $archivedAt = date('Y-m-d H:i:s');
            $archiveFamily = $this->db->prepare('UPDATE assessment_scheme_families SET archived_at = ? WHERE id = ?');
            $archiveFamily->execute([$archivedAt, (int) $family['id']]);
            $afterFamily = $beforeFamily;
            $afterFamily['archived_at'] = $archivedAt;
            $audit->recordUpdate(
                'assessment_scheme_family',
                'assessment_scheme_families',
                (int) $family['id'],
                (string) $family['name'],
                $beforeFamily,
                $afterFamily,
                'أرشفة مجموعة خطط درجات مترابطة',
                $batchId
            );
            if ($startedTransaction) {
                $this->db->commit();
            }
            return $changed;
        } catch (Throwable $error) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Read model for the dedicated family-management view.
     *
     * @return array{family:array<string,mixed>,schemes:list<array<string,mixed>>,scopes:array<int,list<array<string,mixed>>>,annual:array<string,mixed>}
     */
    public function familyDetails(int $academicYearId, int $familyId): array
    {
        $this->assertSchemaReady();
        $family = $this->family($academicYearId, $familyId, false);
        $schemes = $this->fetchSchemesForFamily((int) $family['id'], false);
        if ($schemes === []) {
            throw new RuntimeException('لا تحتوي هذه المجموعة على أي خطط درجات.');
        }

        $schemeIds = array_map(static fn(array $scheme): int => (int) $scheme['id'], $schemes);
        $placeholders = implode(',', array_fill(0, count($schemeIds), '?'));
        $scopeStmt = $this->db->prepare("SELECT scope.scheme_id, scope.scope_kind, scope.class_id,
                grade.grade_name, class.name AS class_name
            FROM assessment_scheme_scopes scope
            JOIN grades grade ON grade.id = scope.grade_id
            LEFT JOIN classes class ON class.id = scope.class_id
            WHERE scope.scheme_id IN ({$placeholders})
            ORDER BY scope.scheme_id, scope.scope_kind, class.name");
        $scopeStmt->execute($schemeIds);
        $scopes = [];
        foreach ($scopeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $scope) {
            $scopes[(int) $scope['scheme_id']][] = $scope;
        }

        $firstScheme = $schemes[0];
        $annual = $this->annualPolicy->policyForScheme(
            (int) $firstScheme['id'],
            !empty($firstScheme['annual_result_enabled']),
            (float) ($firstScheme['first_term_weight'] ?? 50),
            (float) ($firstScheme['second_term_weight'] ?? 50)
        );
        return ['family' => $family, 'schemes' => $schemes, 'scopes' => $scopes, 'annual' => $annual];
    }

    /**
     * Updates the shared scope and annual policy while a family is still a
     * draft.  Once any term becomes operational, a replacement family is the
     * safe path instead of silently rewriting its history.
     *
     * @return array{scope_rows:int,annual_enabled:bool}
     */
    public function updateFamilyConfiguration(int $academicYearId, int $familyId, array $input): array
    {
        $this->assertSchemaReady();
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $family = $this->family($academicYearId, $familyId, true);
            $schemes = $this->fetchSchemesForFamily((int) $family['id'], true);
            if ($schemes === []) {
                throw new RuntimeException('لا تحتوي هذه المجموعة على خطط درجات.');
            }
            foreach ($schemes as $scheme) {
                if ((string) $scheme['status'] !== 'draft') {
                    throw new RuntimeException('يمكن تعديل نطاق المجموعة وسياسة نهاية العام قبل التفعيل فقط. أنشئ مجموعة بديلة بدل تعديل خطة تشغيلية.');
                }
            }
            $gradeIds = array_values(array_unique(array_map(static fn(array $scheme): int => (int) $scheme['grade_id'], $schemes)));
            if (count($gradeIds) !== 1) {
                throw new RuntimeException('تعذر تعديل نطاق مجموعة تحتوي أكثر من صف.');
            }
            $desiredScopes = $this->normalizeFamilyScopes((int) $gradeIds[0], $input);
            $termIds = array_values(array_unique(array_map(static fn(array $scheme): int => (int) $scheme['term_id'], $schemes)));
            $annual = $this->normalizeFamilyAnnualPolicy($input, $termIds);

            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $schemeIds = array_map(static fn(array $scheme): int => (int) $scheme['id'], $schemes);
            $scopeRows = $this->scopeRowsForSchemes($schemeIds, true);
            $deleteScope = $this->db->prepare('DELETE FROM assessment_scheme_scopes WHERE id = ?');
            foreach ($scopeRows as $scopeRow) {
                $deleteScope->execute([(int) $scopeRow['id']]);
                $audit->recordDelete(
                    'assessment_scheme_scope',
                    'assessment_scheme_scopes',
                    (int) $scopeRow['id'],
                    'نطاق خطة #' . (int) $scopeRow['scheme_id'],
                    $scopeRow,
                    'استبدال نطاق خطة درجات جماعية',
                    $batchId
                );
            }
            foreach ($schemeIds as $schemeId) {
                foreach ($desiredScopes as $scope) {
                    $scopeId = $this->insertScope($schemeId, $scope);
                    $audit->recordInsert(
                        'assessment_scheme_scope',
                        'assessment_scheme_scopes',
                        $scopeId,
                        'نطاق خطة #' . $schemeId,
                        $this->fetchById('assessment_scheme_scopes', $scopeId),
                        'تحديد نطاق صف أو فصل لخطة درجات جماعية',
                        $batchId
                    );
                }
            }

            // Keep the legacy foreign-key column coherent for callers that
            // still display it, while the explicit scope rows remain the
            // authoritative access boundary. Multiple per-class links cannot
            // be represented by one legacy id and are intentionally stored as
            // NULL there.
            $assignmentUpdate = $this->db->prepare('UPDATE assessment_schemes SET subject_assignment_id = ? WHERE id = ?');
            foreach ($schemes as $scheme) {
                $schemeId = (int) $scheme['id'];
                $assignmentId = $this->supportingAssignmentId(
                    $academicYearId,
                    (int) $scheme['term_id'],
                    (int) $family['subject_id'],
                    $desiredScopes
                );
                $before = $this->fetchById('assessment_schemes', $schemeId);
                $beforeAssignmentId = $before['subject_assignment_id'] !== null ? (int) $before['subject_assignment_id'] : null;
                if ($beforeAssignmentId !== $assignmentId) {
                    $assignmentUpdate->execute([$assignmentId, $schemeId]);
                    $after = $before;
                    $after['subject_assignment_id'] = $assignmentId;
                    $audit->recordUpdate(
                        'assessment_scheme',
                        'assessment_schemes',
                        $schemeId,
                        (string) $before['name'],
                        $before,
                        $after,
                        'مزامنة رابط المادة مع نطاق خطة درجات جماعية',
                        $batchId
                    );
                }
            }

            $this->updateFamilyAnnualPolicy((int) $family['id'], $annual, $audit, $batchId);
            foreach ($schemeIds as $schemeId) {
                $this->readiness->refresh($schemeId, $batchId, true);
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
            return ['scope_rows' => count($desiredScopes), 'annual_enabled' => $annual['enabled']];
        } catch (Throwable $error) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function buildPlan(int $academicYearId, array $input): array
    {
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('اختر عامًا دراسيًا نشطًا أولًا.');
        }
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        if ($subjectId <= 0 || $name === '') {
            throw new InvalidArgumentException('اختر المادة واكتب اسمًا للخطة.');
        }
        if (mb_strlen($name, 'UTF-8') > 190) {
            throw new InvalidArgumentException('اسم الخطة طويل جدًا.');
        }
        $subjectStmt = $this->db->prepare('SELECT id, name FROM subjects WHERE id = ? AND COALESCE(is_active, 1) = 1 LIMIT 1');
        $subjectStmt->execute([$subjectId]);
        $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$subject) {
            throw new InvalidArgumentException('المادة المختارة غير موجودة أو غير نشطة.');
        }

        $termIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['term_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        if ($termIds === []) {
            throw new InvalidArgumentException('حدد ترمًا واحدًا على الأقل.');
        }
        $termPlaceholders = implode(',', array_fill(0, count($termIds), '?'));
        $termStmt = $this->db->prepare("SELECT id, name, term_order FROM academic_terms
            WHERE academic_year_id = ? AND status = 'active' AND id IN ({$termPlaceholders})
            ORDER BY term_order, id");
        $termStmt->execute(array_merge([$academicYearId], $termIds));
        $terms = [];
        foreach ($termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $term) {
            $terms[(int) $term['id']] = $term;
        }
        if (count($terms) !== count($termIds)) {
            throw new InvalidArgumentException('أحد الترمات المختارة غير نشط أو لا يتبع العام الدراسي الحالي.');
        }

        $grades = $this->normalizeScopes((array) ($input['scopes'] ?? []));
        if ($grades === []) {
            throw new InvalidArgumentException('حدد صفًا واحدًا أو أكثر، أو اختر فصولًا محددة من الصف.');
        }

        $settings = $this->normalizeSettings($input, $name);
        $template = $this->template((int) ($input['template_scheme_id'] ?? 0), $subjectId, $settings);
        $annual = $this->normalizeAnnualPolicy($input, array_keys($terms));
        $requestKey = strtolower(trim((string) ($input['request_key'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $requestKey)) {
            $requestKey = hash('sha256', random_bytes(32));
        }

        $familyRequestKeys = [];
        $legacyFamilyRequestKeys = [];
        $missingLinks = [];
        foreach ($grades as $gradeId => $gradePlan) {
            $legacyFamilyRequestKeys[$gradeId] = hash('sha256', $requestKey . ':grade:' . $gradeId);
            $familyRequestKeys[$gradeId] = $this->familyRequestKey(
                $requestKey,
                $subjectId,
                (int) $gradeId,
                $terms,
                $gradePlan,
                $settings,
                $template,
                $annual
            );
            foreach ($terms as $termId => $term) {
                $missing = $this->scopeResolver->firstMissingSubjectLink($academicYearId, (int) $termId, $subjectId, $gradePlan['scopes']);
                if ($missing !== null) {
                    $missingLinks[] = ['term_id' => (int) $termId, 'grade_id' => (int) $gradeId, 'class_id' => $missing['class_id']];
                }
            }
        }

        return [
            'request_key' => $requestKey,
            'subject' => $subject,
            'terms' => $terms,
            'grades' => $grades,
            'settings' => $settings,
            'template' => $template,
            'annual' => $annual,
            'missing_links' => $missingLinks,
            'family_request_keys' => $familyRequestKeys,
            'legacy_family_request_keys' => $legacyFamilyRequestKeys,
        ];
    }

    /** @return array<int,array{grade:array<string,mixed>,scopes:list<array{grade_id:int,class_id:?int,scope_kind:string}>}> */
    private function normalizeScopes(array $rawScopes): array
    {
        $result = [];
        foreach ($rawScopes as $rawGradeId => $rawScope) {
            $gradeId = (int) $rawGradeId;
            if ($gradeId <= 0 || !is_array($rawScope)) {
                continue;
            }
            $gradeStmt = $this->db->prepare("SELECT id, stage_id, grade_name FROM grades WHERE id = ? AND status = 'active' LIMIT 1");
            $gradeStmt->execute([$gradeId]);
            $grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$grade) {
                throw new InvalidArgumentException('أحد الصفوف المختارة غير نشط أو غير موجود.');
            }
            $allClasses = !empty($rawScope['all_classes']);
            $classIds = array_values(array_unique(array_filter(array_map('intval', (array) ($rawScope['class_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            $scopes = [];
            if ($allClasses) {
                $scopes[] = ['grade_id' => $gradeId, 'class_id' => null, 'scope_kind' => 'grade'];
            } else {
                if ($classIds === []) {
                    throw new InvalidArgumentException('اختر الصف بالكامل أو فصلًا واحدًا على الأقل من داخله.');
                }
                $placeholders = implode(',', array_fill(0, count($classIds), '?'));
                $classStmt = $this->db->prepare("SELECT id FROM classes WHERE grade_id = ? AND status = 'active' AND id IN ({$placeholders})");
                $classStmt->execute(array_merge([$gradeId], $classIds));
                $activeClassIds = array_map('intval', $classStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if (count($activeClassIds) !== count($classIds)) {
                    throw new InvalidArgumentException('أحد الفصول المختارة لا يتبع الصف أو غير نشط.');
                }
                foreach ($classIds as $classId) {
                    $scopes[] = ['grade_id' => $gradeId, 'class_id' => $classId, 'scope_kind' => 'class'];
                }
            }
            $result[$gradeId] = ['grade' => $grade, 'scopes' => $scopes];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function normalizeSettings(array $input, string $name): array
    {
        $totalRaw = trim((string) ($input['total_grade'] ?? '100'));
        if ($totalRaw === '' || !is_numeric($totalRaw)) {
            throw new InvalidArgumentException('مجموع الخطة يجب أن يكون رقمًا صالحًا.');
        }
        $total = (float) $totalRaw;
        $passRaw = trim((string) ($input['pass_grade'] ?? ''));
        if ($passRaw !== '' && !is_numeric($passRaw)) {
            throw new InvalidArgumentException('درجة النجاح يجب أن تكون رقمًا صالحًا.');
        }
        $pass = $passRaw === '' ? null : (float) $passRaw;
        if (!is_finite($total) || $total <= 0 || $total > 100000) {
            throw new InvalidArgumentException('مجموع الخطة يجب أن يكون رقمًا موجبًا صحيحًا.');
        }
        if ($pass !== null && (!is_finite($pass) || $pass < 0 || $pass > $total)) {
            throw new InvalidArgumentException('درجة النجاح يجب أن تكون بين صفر ومجموع الخطة.');
        }
        $normalRaw = (string) ($input['normal_absence_policy'] ?? 'zero');
        $normal = in_array($normalRaw, ['zero', 'exclude', 'note'], true) ? $normalRaw : 'zero';
        $excusedRaw = (string) ($input['excused_absence_policy'] ?? 'exclude');
        $excused = in_array($excusedRaw, ['zero', 'exclude', 'note'], true) ? $excusedRaw : 'exclude';
        $roundingModeRaw = (string) ($input['rounding_mode'] ?? 'none');
        $roundingMode = in_array($roundingModeRaw, ['none', 'nearest_half', 'integer', 'two_decimals'], true)
            ? $roundingModeRaw : 'none';
        $roundingScopeRaw = (string) ($input['rounding_scope'] ?? 'total');
        $roundingScope = in_array($roundingScopeRaw, ['total', 'components', 'both'], true)
            ? $roundingScopeRaw : 'total';
        return [
            'name' => $name,
            'total_grade' => round($total, 2),
            'pass_grade' => $pass === null ? null : round($pass, 2),
            'counts_in_total' => !empty($input['counts_in_total']) ? 1 : 0,
            'enable_excused_absence' => !empty($input['enable_excused_absence']) ? 1 : 0,
            'normal_absence_policy' => $normal,
            'excused_absence_policy' => $excused,
            'rounding_enabled' => !empty($input['rounding_enabled']) ? 1 : 0,
            'rounding_mode' => $roundingMode,
            'rounding_scope' => $roundingScope,
            'scale_template_components' => !empty($input['scale_template_components']) ? 1 : 0,
        ];
    }

    /** @return array<string,mixed>|null */
    private function template(int $schemeId, int $subjectId, array &$settings): ?array
    {
        if ($schemeId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1');
        $stmt->execute([$schemeId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template || (int) $template['subject_id'] !== $subjectId) {
            throw new InvalidArgumentException('قالب الخطة يجب أن ينتمي إلى نفس المادة.');
        }
        return $template;
    }

    /** @return array{enabled:bool,weights:array<int,float>} */
    private function normalizeAnnualPolicy(array $input, array $termIds): array
    {
        $enabled = !empty($input['annual_enabled']);
        if (!$enabled) {
            return ['enabled' => false, 'weights' => []];
        }
        if (count($termIds) < 2) {
            throw new InvalidArgumentException('أوزان نهاية العام تحتاج ترمين مؤهلين على الأقل.');
        }
        $weights = [];
        $rawWeights = (array) ($input['annual_weights'] ?? []);
        foreach ($termIds as $termId) {
            $weights[(int) $termId] = $rawWeights[$termId] ?? $rawWeights[(string) $termId] ?? null;
        }
        $weights = $this->annualPolicy->assertWeightsAreValid($weights);
        if (count(array_filter($weights, static fn(float $weight): bool => $weight > 0)) < 2) {
            throw new InvalidArgumentException('نتيجة نهاية العام تحتاج ترمين مساهمين على الأقل بوزن أكبر من صفر.');
        }
        return ['enabled' => true, 'weights' => $weights];
    }

    /**
     * @return list<array{grade_id:int,class_id:?int,scope_kind:string}>
     */
    private function normalizeFamilyScopes(int $gradeId, array $input): array
    {
        if ($gradeId <= 0) {
            throw new InvalidArgumentException('تعذر تحديد الصف الخاص بمجموعة الخطة.');
        }

        $allClasses = !empty($input['scope_all_classes']) || !empty($input['all_classes']);
        if ($allClasses) {
            return [['grade_id' => $gradeId, 'class_id' => null, 'scope_kind' => 'grade']];
        }

        $classIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($input['scope_class_ids'] ?? $input['class_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        if ($classIds === []) {
            throw new InvalidArgumentException('اختر الصف بالكامل أو فصلًا واحدًا على الأقل من داخله.');
        }

        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM classes WHERE grade_id = ? AND status = 'active' AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$gradeId], $classIds));
        $activeClassIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($activeClassIds) !== count($classIds)) {
            throw new InvalidArgumentException('أحد الفصول المختارة لا يتبع الصف أو غير نشط.');
        }

        $scopes = [];
        foreach ($classIds as $classId) {
            $scopes[] = ['grade_id' => $gradeId, 'class_id' => $classId, 'scope_kind' => 'class'];
        }
        return $scopes;
    }

    /**
     * The public creation and edit paths deliberately share the same annual
     * policy validation: every positive weighted policy needs at least two
     * contributing terms and all weights must total one hundred.
     *
     * @param list<int> $termIds
     * @return array{enabled:bool,weights:array<int,float>}
     */
    private function normalizeFamilyAnnualPolicy(array $input, array $termIds): array
    {
        return $this->normalizeAnnualPolicy($input, $termIds);
    }

    /** @param list<int> $schemeIds @return list<array<string,mixed>> */
    private function scopeRowsForSchemes(array $schemeIds, bool $lock): array
    {
        if ($schemeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($schemeIds), '?'));
        $sql = "SELECT * FROM assessment_scheme_scopes WHERE scheme_id IN ({$placeholders}) ORDER BY scheme_id, id";
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($schemeIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Stores an explicit family policy even when it is disabled. That prevents
     * a later legacy-field fallback from accidentally re-enabling a policy
     * that an administrator deliberately turned off.
     *
     * @param array{enabled:bool,weights:array<int,float>} $annual
     */
    private function updateFamilyAnnualPolicy(int $familyId, array $annual, AuditService $audit, string $batchId): void
    {
        $sql = 'SELECT * FROM assessment_annual_policies WHERE family_id = ? LIMIT 1';
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$familyId]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$policy) {
            $insert = $this->db->prepare('INSERT INTO assessment_annual_policies (family_id, is_enabled, created_by) VALUES (?, ?, NULL)');
            $insert->execute([$familyId, $annual['enabled'] ? 1 : 0]);
            $policyId = (int) $this->db->lastInsertId();
            $policy = $this->fetchById('assessment_annual_policies', $policyId);
            $audit->recordInsert(
                'assessment_annual_policy',
                'assessment_annual_policies',
                $policyId,
                'سياسة نهاية العام',
                $policy,
                'تحديد سياسة نهاية العام لمجموعة خطط درجات',
                $batchId,
                ['family_id' => $familyId]
            );
        } else {
            $policyId = (int) $policy['id'];
            $enabled = $annual['enabled'] ? 1 : 0;
            if ((int) $policy['is_enabled'] !== $enabled) {
                $before = $policy;
                $update = $this->db->prepare('UPDATE assessment_annual_policies SET is_enabled = ? WHERE id = ?');
                $update->execute([$enabled, $policyId]);
                $after = $before;
                $after['is_enabled'] = $enabled;
                $audit->recordUpdate(
                    'assessment_annual_policy',
                    'assessment_annual_policies',
                    $policyId,
                    'سياسة نهاية العام',
                    $before,
                    $after,
                    'تغيير حالة سياسة نهاية العام لمجموعة خطط درجات',
                    $batchId
                );
            }
        }

        $termSql = 'SELECT * FROM assessment_annual_policy_terms WHERE policy_id = ? ORDER BY id';
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $termSql .= ' FOR UPDATE';
        }
        $termStmt = $this->db->prepare($termSql);
        $termStmt->execute([$policyId]);
        $existingTerms = $termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $delete = $this->db->prepare('DELETE FROM assessment_annual_policy_terms WHERE id = ?');
        foreach ($existingTerms as $term) {
            $delete->execute([(int) $term['id']]);
            $audit->recordDelete(
                'assessment_annual_policy_term',
                'assessment_annual_policy_terms',
                (int) $term['id'],
                'وزن ترم في نهاية العام',
                $term,
                'استبدال أوزان سياسة نهاية العام',
                $batchId
            );
        }

        if (!$annual['enabled']) {
            return;
        }
        foreach ($annual['weights'] as $termId => $weight) {
            $weightId = $this->insertAnnualPolicyWeight($policyId, (int) $termId, (float) $weight);
            $audit->recordInsert(
                'assessment_annual_policy_term',
                'assessment_annual_policy_terms',
                $weightId,
                'وزن ترم في نهاية العام',
                $this->fetchById('assessment_annual_policy_terms', $weightId),
                'تحديد وزن ترم في سياسة نهاية العام',
                $batchId,
                ['family_id' => $familyId]
            );
        }
    }

    /**
     * The idempotency identity includes the normalized business payload. A
     * browser back/resubmit with changed terms or scopes must never return an
     * older family as if it represented the new request.
     *
     * @param array<int,array<string,mixed>> $terms
     * @param array<string,mixed> $gradePlan
     * @param array<string,mixed> $settings
     * @param array<string,mixed>|null $template
     * @param array<string,mixed> $annual
     */
    private function familyRequestKey(
        string $requestKey,
        int $subjectId,
        int $gradeId,
        array $terms,
        array $gradePlan,
        array $settings,
        ?array $template,
        array $annual
    ): string {
        $termIds = array_map('intval', array_keys($terms));
        sort($termIds, SORT_NUMERIC);
        $scopes = array_map(static fn(array $scope): array => [
            'grade_id' => (int) $scope['grade_id'],
            'class_id' => $scope['class_id'] !== null ? (int) $scope['class_id'] : null,
            'scope_kind' => (string) $scope['scope_kind'],
        ], (array) ($gradePlan['scopes'] ?? []));
        usort($scopes, static function (array $left, array $right): int {
            return [$left['grade_id'], $left['class_id'] ?? 0, $left['scope_kind']]
                <=> [$right['grade_id'], $right['class_id'] ?? 0, $right['scope_kind']];
        });
        $payload = [
            'subject_id' => $subjectId,
            'grade_id' => $gradeId,
            'term_ids' => $termIds,
            'scopes' => $scopes,
            'settings' => $settings,
            'template_scheme_id' => (int) ($template['id'] ?? 0),
            'annual' => $annual,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return hash('sha256', $requestKey . ':plan:' . $encoded);
    }

    private function insertFamily(int $yearId, int $subjectId, string $name, string $requestKey, string $batchId, ?int $actorId): int
    {
        $stmt = $this->db->prepare('INSERT INTO assessment_scheme_families
            (academic_year_id, subject_id, name, request_key, batch_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$yearId, $subjectId, $name, $requestKey, $batchId, $actorId]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $gradePlan @param array<string,mixed> $term */
    private function insertScheme(
        int $familyId,
        int $academicYearId,
        int $termId,
        array $plan,
        array $gradePlan,
        array $term,
        string $batchId,
        ?int $actorId
    ): int {
        $assignmentId = $this->supportingAssignmentId(
            $academicYearId,
            $termId,
            (int) $plan['subject']['id'],
            $gradePlan['scopes']
        );
        $settings = $plan['settings'];
        $stmt = $this->db->prepare('INSERT INTO assessment_schemes
            (family_id, academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name,
             total_grade, pass_grade, counts_in_total, enable_excused_absence, normal_absence_policy,
             excused_absence_policy, rounding_enabled, rounding_mode, rounding_scope,
             annual_result_enabled, first_term_weight, second_term_weight, status, readiness_status,
             readiness_reason, copied_from_scheme_id, batch_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 50, 50, \'draft\', \'needs_components\', ?, ?, ?, ?)');
        $stmt->execute([
            $familyId,
            $academicYearId,
            $termId,
            $assignmentId,
            (int) $plan['subject']['id'],
            (int) $gradePlan['grade']['stage_id'],
            (int) $gradePlan['grade']['id'],
            $this->schemeName((string) $settings['name'], (string) $term['name'], count($plan['terms'])),
            $settings['total_grade'],
            $settings['pass_grade'],
            $settings['counts_in_total'],
            $settings['enable_excused_absence'],
            $settings['normal_absence_policy'],
            $settings['excused_absence_policy'],
            $settings['rounding_enabled'],
            $settings['rounding_mode'],
            $settings['rounding_scope'],
            'تحتاج الخطة إلى بنود تقييم أو ربط مادة.',
            $plan['template']['id'] ?? null,
            $batchId,
            $actorId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{grade_id:int,class_id:?int,scope_kind:string} $scope */
    private function insertScope(int $schemeId, array $scope): int
    {
        $stmt = $this->db->prepare('INSERT INTO assessment_scheme_scopes (scheme_id, grade_id, class_id, scope_identity, scope_kind)
            VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $schemeId,
            $scope['grade_id'],
            $scope['class_id'],
            $scope['class_id'] ?? 0,
            $scope['scope_kind'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertAnnualPolicy(int $familyId, ?int $actorId): int
    {
        $stmt = $this->db->prepare('INSERT INTO assessment_annual_policies (family_id, is_enabled, created_by) VALUES (?, 1, ?)');
        $stmt->execute([$familyId, $actorId]);
        return (int) $this->db->lastInsertId();
    }

    private function insertAnnualPolicyWeight(int $policyId, int $termId, float $weight): int
    {
        $stmt = $this->db->prepare('INSERT INTO assessment_annual_policy_terms (policy_id, term_id, weight) VALUES (?, ?, ?)');
        $stmt->execute([$policyId, $termId, $weight]);
        return (int) $this->db->lastInsertId();
    }

    /** @param list<array{grade_id:int,class_id:?int,scope_kind:string}> $scopes */
    private function supportingAssignmentId(int $yearId, int $termId, int $subjectId, array $scopes): ?int
    {
        $ids = [];
        foreach ($scopes as $scope) {
            $link = $this->scopeResolver->findActiveSubjectLink($yearId, $termId, $subjectId, (int) $scope['grade_id'], $scope['class_id']);
            if ($link === null) {
                return null;
            }
            $ids[] = (int) $link['id'];
        }
        $ids = array_values(array_unique($ids));
        return count($ids) === 1 ? $ids[0] : null;
    }

    private function schemeName(string $name, string $termName, int $termCount): string
    {
        $label = $termCount > 1 ? $name . ' — ' . $termName : $name;
        return mb_substr($label, 0, 190, 'UTF-8');
    }

    /** @param array<int,string> $familyRequestKeys @return list<array<string,mixed>> */
    private function existingRequestFamilies(int $yearId, array $familyRequestKeys): array
    {
        if ($familyRequestKeys === []) {
            return [];
        }
        $keys = array_values($familyRequestKeys);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT id, batch_id FROM assessment_scheme_families WHERE academic_year_id = ? AND request_key IN ({$placeholders})";
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && $this->db->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$yearId], $keys));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<int> $familyIds @return list<int> */
    private function schemesForFamilies(array $familyIds): array
    {
        if ($familyIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM assessment_schemes WHERE family_id IN ({$placeholders}) ORDER BY id");
        $stmt->execute($familyIds);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param list<int> $schemeIds @return array<int,array{status:string,reason:?string}> */
    private function readinessSummary(array $schemeIds): array
    {
        if ($schemeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($schemeIds), '?'));
        $stmt = $this->db->prepare("SELECT id, readiness_status, readiness_reason FROM assessment_schemes WHERE id IN ({$placeholders})");
        $stmt->execute($schemeIds);
        $summary = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $summary[(int) $row['id']] = ['status' => (string) $row['readiness_status'], 'reason' => $row['readiness_reason'] !== null ? (string) $row['readiness_reason'] : null];
        }
        return $summary;
    }

    /** @param list<int> $termIds */
    private function lockCandidateSchemes(int $yearId, int $subjectId, array $termIds): void
    {
        if ($termIds === [] || $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($termIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM assessment_schemes
            WHERE academic_year_id = ? AND subject_id = ? AND term_id IN ({$placeholders}) FOR UPDATE");
        $stmt->execute(array_merge([$yearId, $subjectId], $termIds));
        $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string,mixed> */
    private function family(int $academicYearId, int $familyId, bool $lock): array
    {
        if ($familyId <= 0) {
            throw new InvalidArgumentException('معرّف مجموعة الخطة غير صالح.');
        }
        $sql = 'SELECT family.*, subject.name AS subject_name
            FROM assessment_scheme_families family
            JOIN subjects subject ON subject.id = family.subject_id
            WHERE family.id = ? AND family.academic_year_id = ? LIMIT 1';
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$familyId, $academicYearId]);
        $family = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$family) {
            throw new RuntimeException('مجموعة خطة الدرجات غير موجودة أو لا تتبع العام الدراسي الحالي.');
        }
        return $family;
    }

    /** @return list<array<string,mixed>> */
    private function fetchSchemesForFamily(int $familyId, bool $lock): array
    {
        $componentsReady = $this->tableExists('assessment_components');
        $componentsSelect = $componentsReady
            ? 'COALESCE(component_totals.components_total, 0) AS components_total,
                COALESCE(component_totals.components_count, 0) AS components_count'
            : '0 AS components_total, 0 AS components_count';
        $componentsJoin = $componentsReady
            ? 'LEFT JOIN (
                SELECT scheme_id, SUM(max_grade) AS components_total, COUNT(*) AS components_count
                FROM assessment_components
                WHERE is_active = 1 AND counts_in_total = 1
                GROUP BY scheme_id
            ) component_totals ON component_totals.scheme_id = scheme.id'
            : '';
        $sql = 'SELECT scheme.*, term.name AS term_name, grade.grade_name, ' . $componentsSelect . '
            FROM assessment_schemes scheme
            JOIN academic_terms term ON term.id = scheme.term_id
            JOIN grades grade ON grade.id = scheme.grade_id
            ' . $componentsJoin . '
            WHERE scheme.family_id = ?
            ORDER BY term.term_order, scheme.id';
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<int> $schemeIds @return list<array<string,mixed>> */
    private function fetchSchemesForActivation(int $yearId, array $schemeIds, bool $lock): array
    {
        $placeholders = implode(',', array_fill(0, count($schemeIds), '?'));
        $sql = "SELECT * FROM assessment_schemes WHERE academic_year_id = ? AND id IN ({$placeholders}) ORDER BY id";
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$yearId], $schemeIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function fetchById(string $table, int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id];
    }

    private function assertSchemaReady(): void
    {
        foreach (['assessment_scheme_families', 'assessment_scheme_scopes', 'assessment_annual_policies', 'assessment_annual_policy_terms'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('ميزة الخطط الجماعية تحتاج تطبيق ترقية قاعدة البيانات أولًا.');
            }
        }
        foreach (['family_id', 'readiness_status', 'readiness_reason', 'batch_id'] as $column) {
            if (!$this->columnExists('assessment_schemes', $column)) {
                throw new RuntimeException('ترقية خطط الدرجات غير مكتملة. طبّق ترقية قاعدة البيانات أولًا.');
            }
        }
        if (!$this->columnExists('assessment_scheme_scopes', 'scope_identity')) {
            throw new RuntimeException('ترقية هوية نطاق خطط الدرجات غير مكتملة. طبّق جميع ترقيات قاعدة البيانات أولًا.');
        }
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
