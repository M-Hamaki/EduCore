<?php

declare(strict_types=1);

/** Isolated FR-136/Q26 proof; no school database is opened. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Operations/Audit/AuditEventWriter.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffOrganizationCorrectionRepository.php';
require_once $root . '/src/Modules/Staff/Contracts/StaffOrganizationCorrectionImpactGateway.php';
require_once $root . '/src/Modules/Staff/Application/Organization/StaffOrganizationCorrectionService.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Organization\StaffOrganizationCorrectionService;
use EduCore\Modules\Staff\Contracts\StaffOrganizationCorrectionImpactGateway;
use EduCore\Modules\Staff\Contracts\StaffOrganizationCorrectionRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo $message . ':' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        ++$failures;
    }
};
$expectCode = static function (callable $work, string $code, string $message) use ($assert): void {
    try {
        $work();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $code, $message);
    }
};

final class CorrectionMemoryStore implements StaffOrganizationCorrectionRepository, StaffOrganizationCorrectionImpactGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $corrections = [];
    /** @var array<int,array<string,mixed>> */
    public array $decisions = [];
    /** @var list<array<string,mixed>> */
    public array $published = [];
    /** @var array<int,string> */
    public array $requesters = [10 => 'admin', 11 => 'admin', 12 => 'admin'];
    /** @var array<int,string> */
    public array $approvers = [20 => 'super_admin', 21 => 'hr_manager'];
    public bool $failPublish = false;

    public function transactional(callable $work): mixed
    {
        $before = serialize([$this->corrections, $this->decisions, $this->published]);
        try {
            return $work();
        } catch (Throwable $exception) {
            [$this->corrections, $this->decisions, $this->published] = unserialize($before, ['allowed_classes' => false]);
            throw $exception;
        }
    }

    public function actorCanRequestCorrection(int $actorId): bool
    {
        return isset($this->requesters[$actorId]) || isset($this->approvers[$actorId]);
    }

    public function actorCanApproveCorrection(int $actorId): bool
    {
        return isset($this->approvers[$actorId]);
    }

    public function correctionByIdempotencyForUpdate(string $key): ?array
    {
        foreach ($this->corrections as $correction) {
            if ($correction['idempotency_key'] === $key) {
                return $correction;
            }
        }
        return null;
    }

    public function correctionByIdForUpdate(int $correctionId): ?array
    {
        return $this->corrections[$correctionId] ?? null;
    }

    public function finalDecisionForCorrectionForUpdate(int $correctionId): ?array
    {
        foreach ($this->decisions as $decision) {
            if ($decision['correction_id'] === $correctionId) {
                return $decision;
            }
        }
        return null;
    }

    public function decisionByIdempotencyForUpdate(string $key): ?array
    {
        foreach ($this->decisions as $decision) {
            if ($decision['idempotency_key'] === $key) {
                return $decision;
            }
        }
        return null;
    }

    public function insertCorrection(array $correction): int
    {
        $id = count($this->corrections) + 1;
        $this->corrections[$id] = ['id' => $id, 'lock_version' => 1] + $correction;
        return $id;
    }

    public function insertDecision(array $decision): int
    {
        $id = count($this->decisions) + 1;
        $this->decisions[$id] = ['id' => $id] + $decision;
        return $id;
    }

    public function recentCorrections(int $limit): array
    {
        return array_slice(array_reverse(array_values($this->corrections)), 0, $limit);
    }

    public function previewImpact(array $candidate, int $limit): array
    {
        if (($candidate['scope_type'] ?? '') === 'global') {
            return [
                'affected_staff_ids' => [1001, 1002],
                'affected_work_dates' => ['2026-07-01', '2026-07-02'],
                'affected_requests' => [
                    ['resource_type' => 'permission_request', 'resource_id' => 71],
                    ['resource_type' => 'leave_request', 'resource_id' => 81],
                ],
                'affected_report_periods' => ['2026-07'],
                'warnings' => [],
            ];
        }

        return [
            'affected_staff_ids' => [(int) $candidate['scope_id']],
            'affected_work_dates' => ['2026-07-15'],
            'affected_requests' => [['resource_type' => 'permission_request', 'resource_id' => 72]],
            'affected_report_periods' => ['2026-07'],
            'warnings' => [],
        ];
    }

    public function publishImpact(array $event): array
    {
        if ($this->failPublish) {
            throw new RuntimeException('downstream unavailable');
        }
        $this->published[] = $event;
        return ['accepted' => true, 'intent_count' => 4];
    }
}

final class CorrectionAuditSpy implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $fail = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new RuntimeException('audit unavailable');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$store = new CorrectionMemoryStore();
$audit = new CorrectionAuditSpy();
$service = new StaffOrganizationCorrectionService($store, $store, $audit);
$base = [
    'correction_kind' => 'organization_unit',
    'scope_type' => 'staff',
    'scope_id' => 1001,
    'effective_from' => '2026-07-15',
    'effective_to' => '2026-07-15',
    'proposed_reference_id' => 55,
    'reason' => 'تصحيح القوة بعد مراجعة قرار النقل',
    'idempotency_key' => hash('sha256', 'org-correction-preview-1'),
];

$preview = $service->previewCorrection($base, 10);
$assert(
    $preview['correction_id'] === 1
    && $preview['status'] === 'previewed'
    && $preview['impact']['affected_staff_ids'] === [1001]
    && $preview['impact']['affected_requests'][0]['resource_id'] === 72,
    'Q26_preview_freezes_exact_staff_dates_requests_and_report_periods'
);
$assert(
    !str_contains(json_encode($audit->events, JSON_UNESCAPED_UNICODE), $base['reason'])
    && strlen((string) ($store->corrections[1]['reason_hash'] ?? '')) === 64,
    'Q26_audit_redacts_reason_and_keeps_only_its_hash'
);
$replay = $service->previewCorrection($base, 10);
$assert($replay['correction_id'] === 1 && $replay['replayed'] === true, 'Q26_preview_is_idempotent');
$expectCode(
    static fn () => $service->previewCorrection(array_replace($base, ['proposed_reference_id' => 99]), 10),
    'STAFF_ORG_CORRECTION_IDEMPOTENCY_CONFLICT',
    'Q26_altered_preview_replay_fails_closed'
);
$expectCode(
    static fn () => $service->decideCorrection([
        'correction_id' => 1,
        'expected_lock_version' => 1,
        'decision' => 'approved',
        'comment' => 'اعتماد',
        'idempotency_key' => hash('sha256', 'self-approval'),
    ], 10),
    'STAFF_ORG_CORRECTION_SELF_APPROVAL_FORBIDDEN',
    'Q26_requester_cannot_approve_own_correction'
);
$expectCode(
    static fn () => $service->decideCorrection([
        'correction_id' => 1,
        'expected_lock_version' => 1,
        'decision' => 'approved',
        'idempotency_key' => hash('sha256', 'ordinary-admin-approval'),
    ], 11),
    'STAFF_ORG_CORRECTION_APPROVER_FORBIDDEN',
    'Q26_ordinary_admin_cannot_approve_correction'
);
$approved = $service->decideCorrection([
    'correction_id' => 1,
    'expected_lock_version' => 1,
    'decision' => 'approved',
    'comment' => 'تمت مراجعة نطاق التأثير',
    'idempotency_key' => hash('sha256', 'super-admin-approval'),
], 20);
$assert(
    $approved['decision'] === 'approved'
    && count($store->published) === 1
    && $store->published[0]['direction'] === 'apply'
    && $store->published[0]['impact']['affected_report_periods'] === ['2026-07'],
    'Q26_independent_approval_publishes_only_the_frozen_scope'
);
$approvedReplay = $service->decideCorrection([
    'correction_id' => 1,
    'expected_lock_version' => 1,
    'decision' => 'approved',
    'comment' => 'تمت مراجعة نطاق التأثير',
    'idempotency_key' => hash('sha256', 'super-admin-approval'),
], 20);
$assert($approvedReplay['replayed'] === true && count($store->published) === 1, 'Q26_decision_replay_does_not_duplicate_impacts');

$reversal = $service->previewReversal([
    'correction_id' => 1,
    'reason' => 'إلغاء التصحيح والعودة إلى الإسقاط السابق',
    'idempotency_key' => hash('sha256', 'org-correction-reversal'),
], 12);
$assert(
    $reversal['correction_id'] === 2
    && ($store->corrections[2]['reverses_correction_id'] ?? null) === 1
    && $reversal['impact'] === $preview['impact'],
    'Q26_reversal_reuses_frozen_scope_without_deleting_history'
);
$reversalApproved = $service->decideCorrection([
    'correction_id' => 2,
    'expected_lock_version' => 1,
    'decision' => 'approved',
    'idempotency_key' => hash('sha256', 'hr-manager-reversal-approval'),
], 21);
$assert(
    $reversalApproved['decision'] === 'approved'
    && count($store->published) === 2
    && $store->published[1]['direction'] === 'reverse',
    'Q26_HR_manager_can_approve_a_separate_scoped_reversal'
);

$rollbackStore = new CorrectionMemoryStore();
$rollbackStore->failPublish = true;
$rollbackAudit = new CorrectionAuditSpy();
$rollbackService = new StaffOrganizationCorrectionService($rollbackStore, $rollbackStore, $rollbackAudit);
$rollbackPreview = $rollbackService->previewCorrection(array_replace($base, [
    'idempotency_key' => hash('sha256', 'rollback-preview'),
]), 10);
$expectCode(
    static fn () => $rollbackService->decideCorrection([
        'correction_id' => $rollbackPreview['correction_id'],
        'expected_lock_version' => 1,
        'decision' => 'approved',
        'idempotency_key' => hash('sha256', 'rollback-approval'),
    ], 20),
    'STAFF_ORG_CORRECTION_IMPACT_PUBLISH_FAILED',
    'Q26_publish_failure_rolls_back_decision_and_audit'
);
$assert($rollbackStore->decisions === [] && count($rollbackAudit->events) === 1, 'Q26_failed_approval_leaves_only_the_prior_preview_audit');

$surface = (string) file_get_contents($root . '/admin/hr_organization.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260809_staff_hr_organization_corrections.php');
$auditPolicy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$factory = (string) file_get_contents($root . '/src/Modules/Staff/Infrastructure/StaffModuleFactory.php');
$assert(
    str_contains($surface, 'name="action" value="preview_correction"')
    && str_contains($surface, 'id="correctionDecisionModal"')
    && str_contains($surface, 'id="correctionReversalModal"')
    && !str_contains($surface, 'confirm(')
    && !str_contains($surface, 'Swal.'),
    'Q26_admin_surface_uses_reviewed_forms_and_Bootstrap_modals'
);
$assert(
    str_contains($migration, 'staff_organization_corrections')
    && str_contains($migration, 'staff_organization_correction_decisions')
    && str_contains($migration, 'staff_organization_correction_impacts')
    && str_contains($migration, 'no_update')
    && str_contains($migration, 'no_delete'),
    'Q26_schema_is_append_only_and_has_no_hard_delete_path'
);
$assert(
    str_contains($auditPolicy, "'staff_organization_correction_' => [")
    && str_contains($auditPolicy, "'staff_organization_corrections',")
    && str_contains($auditPolicy, "'staff_organization_correction_decisions',")
    && str_contains($auditPolicy, "'staff_organization_correction_impacts',")
    && str_contains($auditPolicy, "'reason_text'")
    && str_contains($auditPolicy, "'impact_snapshot_json'"),
    'Q26_audit_policy_redacts_sensitive_preview_fields_and_disables_direct_undo'
);
$assert(
    str_contains($factory, 'public function organizationCorrections(): StaffOrganizationCorrectionService')
    && str_contains($factory, 'new PdoStaffOrganizationCorrectionRepository($this->db)')
    && str_contains($surface, '$factory->organizationCorrections()'),
    'Q26_module_factory_bootstraps_the_owned_service_and_page_uses_it'
);

exit($failures > 0 ? 1 : 0);
