<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\DisciplineDecisionRepository;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use InvalidArgumentException;
use Throwable;

/**
 * Applies only the final shared-approval outcome for a proposed decision.
 *
 * This handler never calls Finance. It may persist a local Staff-owned fact
 * intent through DisciplineFinanceEffectQueue after issuance; a separately
 * configured worker alone can send that immutable fact to Finance.
 */
final class DisciplineDecisionApprovalOutcomeHandler implements ApprovalWorkflowOutcomeHandler
{
    private DateTimeZone $clockZone;
    private string $subjectRoute;

    public function __construct(
        private DisciplineDecisionRepository $repository,
        private StaffNotificationPort $notifications,
        private AuditEventWriter $audit,
        ?DateTimeZone $clockZone = null,
        string $subjectRoute = 'admin/disciplinary.php',
        private ?DisciplineFinanceEffectQueue $financeEffects = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('UTC');
        $this->subjectRoute = $this->internalRoute($subjectRoute);
    }

    /** @param array<string,mixed> $instance */
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
        $instanceId = $this->positiveId(
            $instance['id'] ?? $instance['instance_id'] ?? null,
            'DISCIPLINE_APPROVAL_INSTANCE_INVALID'
        );
        $caseId = $this->positiveId(
            $instance['resource_id'] ?? null,
            'DISCIPLINE_APPROVAL_INSTANCE_INVALID'
        );
        if ((string) ($instance['resource_type'] ?? '') !== 'discipline_case') {
            throw new DomainException('DISCIPLINE_APPROVAL_RESOURCE_INVALID');
        }
        if ($actorId <= 0 || !in_array($outcome, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('DISCIPLINE_APPROVAL_OUTCOME_INVALID');
        }
        $decisionId = $this->decisionIdFromInstance($instance);
        $occurredAt = $occurredAt->setTimezone($this->clockZone);

        $this->repository->transactional(function () use (
            $instanceId,
            $caseId,
            $decisionId,
            $outcome,
            $actorId,
            $occurredAt
        ): void {
            $decision = $this->requiredDecision($decisionId);
            if ((int) ($decision['case_id'] ?? 0) !== $caseId
                || (int) ($decision['workflow_instance_id'] ?? 0) !== $instanceId) {
                throw new DomainException('DISCIPLINE_APPROVAL_DECISION_LINK_INVALID');
            }
            $case = $this->requiredCase($caseId);
            if ((string) ($case['status'] ?? '') !== 'pending_decision') {
                throw new DomainException('DISCIPLINE_APPROVAL_CASE_NOT_PENDING');
            }
            if ((string) ($decision['status'] ?? '') !== 'proposed') {
                throw new DomainException('DISCIPLINE_APPROVAL_DECISION_NOT_PENDING');
            }
            $investigation = $this->requiredInvestigation(
                $this->positiveId(
                    $decision['investigation_id'] ?? null,
                    'DISCIPLINE_APPROVAL_INVESTIGATION_INVALID'
                )
            );
            if ((int) ($investigation['case_id'] ?? 0) !== $caseId
                || (string) ($investigation['status'] ?? '') !== 'completed') {
                throw new DomainException('DISCIPLINE_APPROVAL_INVESTIGATION_INVALID');
            }
            $this->assertFinalizerSeparated($actorId, $case, $decision, $investigation);
            $decisionLock = $this->positiveId(
                $decision['lock_version'] ?? null,
                'DISCIPLINE_APPROVAL_DECISION_STALE'
            );

            if ($outcome === 'rejected') {
                if (!$this->repository->cancelProposedDecision($decisionId, $decisionLock)) {
                    throw new DomainException('DISCIPLINE_APPROVAL_DECISION_STALE');
                }
                $this->audit->recordEvent(
                    'staff_discipline_decision_rejected',
                    'staff_discipline_decisions',
                    $decisionId,
                    (string) ($decision['decision_no'] ?? ''),
                    [
                        'case_id' => $caseId,
                        'workflow_instance_id' => $instanceId,
                        'status' => 'cancelled',
                        'financial_effect_requested' => (bool) ($decision['financial_effect_requested'] ?? false),
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($occurredAt)]
                );

                return;
            }

            $instant = $this->instant($occurredAt);
            if (!$this->repository->issueDecision($decisionId, $decisionLock, $actorId, $instant, $instant)) {
                throw new DomainException('DISCIPLINE_APPROVAL_DECISION_STALE');
            }
            $caseLock = $this->positiveId(
                $case['lock_version'] ?? null,
                'DISCIPLINE_APPROVAL_CASE_STALE'
            );
            if (!$this->repository->transitionCase($caseId, $caseLock, 'pending_decision', 'decided')) {
                throw new DomainException('DISCIPLINE_APPROVAL_CASE_STALE');
            }
            $issued = array_replace($decision, [
                'status' => 'issued',
                'decided_by_user_id' => $actorId,
                'decided_at' => $instant,
                'issued_at' => $instant,
                'lock_version' => $decisionLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_decision_issued',
                'staff_discipline_decisions',
                $decisionId,
                (string) ($decision['decision_no'] ?? ''),
                [
                    'case_id' => $caseId,
                    'workflow_instance_id' => $instanceId,
                    'status' => 'issued',
                    'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
                    'financial_effect_requested' => (bool) ($decision['financial_effect_requested'] ?? false),
                    'finance_write_performed' => false,
                ],
                ['user_id' => $actorId, 'occurred_at' => $instant]
            );
            $this->audit->recordEvent(
                'staff_discipline_case_decided',
                'staff_discipline_cases',
                $caseId,
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => 'pending_decision', 'status' => 'decided', 'decision_id' => $decisionId],
                ['user_id' => $actorId, 'occurred_at' => $instant]
            );
            if ($this->financeEffects !== null
                && (bool) ($issued['financial_effect_requested'] ?? false)) {
                $this->financeEffects->queueForIssuedDecision($decisionId, $actorId, $occurredAt);
            }
            $this->queueSubjectNotification($issued, $case, $actorId, $occurredAt);
        });
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $case */
    private function queueSubjectNotification(
        array $decision,
        array $case,
        int $actorId,
        DateTimeImmutable $occurredAt
    ): void {
        $decisionId = $this->positiveId($decision['id'] ?? null, 'DISCIPLINE_NOTIFICATION_DECISION_INVALID');
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_NOTIFICATION_CASE_INVALID');
        $subjectId = $this->positiveId(
            $case['subject_staff_user_id'] ?? null,
            'DISCIPLINE_NOTIFICATION_SUBJECT_INVALID'
        );
        $decisionLock = $this->positiveId(
            $decision['lock_version'] ?? null,
            'DISCIPLINE_NOTIFICATION_STALE'
        );
        $notification = null;
        try {
            $notification = $this->notifications->notifyRecipients(
                'staff-discipline-decision:' . $decisionId . ':issued',
                [$subjectId],
                $this->subjectRoute . '?case_id=' . $caseId,
                'لديك قرار إداري جديد متاح للمراجعة.',
                [
                    'schema_version' => 1,
                    'event_type' => 'discipline_decision_issued',
                    'resource_type' => 'discipline_case',
                    'resource_id' => $caseId,
                    'discipline_decision_id' => $decisionId,
                ],
                'staff-discipline-decision-notification:' . hash(
                    'sha256',
                    (string) ($decision['idempotency_key'] ?? $decisionId)
                )
            );
        } catch (Throwable) {
            $notification = null;
        }

        if (is_array($notification) && ($notification['accepted'] ?? false) === true) {
            $reference = $this->nullableReference($notification['receipt_id'] ?? null);
            if (!$this->repository->markNotification(
                $decisionId,
                $decisionLock,
                'sent',
                $reference,
                $this->instant($occurredAt)
            )) {
                throw new DomainException('DISCIPLINE_NOTIFICATION_STALE');
            }
            $this->audit->recordEvent(
                'staff_discipline_decision_notification_queued',
                'staff_discipline_decisions',
                $decisionId,
                (string) ($decision['decision_no'] ?? ''),
                [
                    'case_id' => $caseId,
                    'notification_status' => 'sent',
                    'notification_reference_hash' => $reference === null ? null : hash('sha256', $reference),
                    'recipient_count' => (int) ($notification['inbox_count'] ?? 0),
                    'outbox_count' => (int) ($notification['outbox_count'] ?? 0),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($occurredAt)]
            );

            return;
        }

        if (!$this->repository->markNotification($decisionId, $decisionLock, 'delivery_failed', null, null)) {
            throw new DomainException('DISCIPLINE_NOTIFICATION_STALE');
        }
        $this->audit->recordEvent(
            'staff_discipline_decision_notification_failed',
            'staff_discipline_decisions',
            $decisionId,
            (string) ($decision['decision_no'] ?? ''),
            [
                'case_id' => $caseId,
                'notification_status' => 'delivery_failed',
                'failure_code' => 'NOTIFICATION_ENQUEUE_UNAVAILABLE',
            ],
            ['user_id' => $actorId, 'occurred_at' => $this->instant($occurredAt)]
        );
    }

    /** @param array<string,mixed> $instance */
    private function decisionIdFromInstance(array $instance): int
    {
        $snapshot = $instance['snapshot'] ?? null;
        if (!is_array($snapshot) || !is_array($snapshot['context'] ?? null)) {
            throw new DomainException('DISCIPLINE_APPROVAL_SNAPSHOT_INVALID');
        }

        return $this->positiveId(
            $snapshot['context']['discipline_decision_id'] ?? null,
            'DISCIPLINE_APPROVAL_SNAPSHOT_INVALID'
        );
    }

    /** @param array<string,mixed> $case @param array<string,mixed> $decision @param array<string,mixed> $investigation */
    private function assertFinalizerSeparated(
        int $actorId,
        array $case,
        array $decision,
        array $investigation
    ): void {
        $conflicts = [
            $this->nullablePositiveId($case['subject_staff_user_id'] ?? null),
            $this->nullablePositiveId($case['incident_reported_by_user_id'] ?? null),
            $this->nullablePositiveId($case['opened_by_user_id'] ?? null),
            $this->nullablePositiveId($decision['prepared_by_user_id'] ?? null),
            $this->nullablePositiveId($investigation['investigator_user_id'] ?? null),
        ];
        if (in_array($actorId, array_filter($conflicts), true)) {
            throw new DomainException('DISCIPLINE_DECISION_FINALIZER_CONFLICT');
        }
    }

    /** @return array<string,mixed> */
    private function requiredCase(int $caseId): array
    {
        $case = $this->repository->caseForUpdate($caseId);
        if ($case === null) {
            throw new DomainException('DISCIPLINE_CASE_NOT_FOUND');
        }

        return $case;
    }

    /** @return array<string,mixed> */
    private function requiredDecision(int $decisionId): array
    {
        $decision = $this->repository->decisionForUpdate($decisionId);
        if ($decision === null) {
            throw new DomainException('DISCIPLINE_DECISION_NOT_FOUND');
        }

        return $decision;
    }

    /** @return array<string,mixed> */
    private function requiredInvestigation(int $investigationId): array
    {
        $investigation = $this->repository->investigationForUpdate($investigationId);
        if ($investigation === null) {
            throw new DomainException('DISCIPLINE_INVESTIGATION_NOT_FOUND');
        }

        return $investigation;
    }

    private function nullableReference(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new DomainException('DISCIPLINE_NOTIFICATION_REFERENCE_INVALID');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new DomainException('DISCIPLINE_NOTIFICATION_REFERENCE_INVALID');
        }

        return $value;
    }

    private function internalRoute(string $route): string
    {
        $route = trim($route);
        if ($route === '' || str_starts_with($route, '//') || str_contains($route, '\\')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $route) === 1) {
            throw new InvalidArgumentException('DISCIPLINE_NOTIFICATION_ROUTE_INVALID');
        }
        $path = parse_url($route, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains(rawurldecode($path), '..')) {
            throw new InvalidArgumentException('DISCIPLINE_NOTIFICATION_ROUTE_INVALID');
        }

        return $route;
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'DISCIPLINE_IDENTIFIER_INVALID');
    }

    private function instant(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->clockZone)
            ->format('Y-m-d H:i:s.u');
    }
}
