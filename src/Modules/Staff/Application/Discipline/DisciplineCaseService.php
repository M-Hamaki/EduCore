<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineCaseRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the opening half of a discipline case.
 *
 * It records an incident, opens exactly one case without changing a linked
 * Attendance/Ertaq/document source, and keeps parties as separately auditable
 * records. It deliberately cannot investigate, decide, execute a sanction,
 * or send a Finance fact; those responsibilities have later owners.
 */
final class DisciplineCaseService
{
    /** @var list<string> */
    private const CONFIDENTIALITY_LEVELS = ['normal', 'restricted', 'highly_restricted'];

    /** @var list<string> */
    private const PARTY_ROLES = [
        'subject', 'reporter', 'complainant', 'respondent', 'witness',
        'representative', 'observer', 'other',
    ];

    /** @var list<string> */
    private const VISIBILITY_SCOPES = ['case_team', 'decision_team', 'restricted', 'subject_only'];

    /** @var list<string> */
    private const CANCELLABLE_CASE_STATES = ['reported', 'triage', 'under_investigation', 'pending_decision'];

    public function __construct(
        private DisciplineCaseRepository $repository,
        private DisciplineCaseAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function recordIncident(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['create_idempotency_key'] ?? null,
            64,
            'DISCIPLINE_INCIDENT_IDEMPOTENCY_INVALID'
        );
        $input = $this->incidentInput($command, $actorId, $idempotencyKey);
        $now = $this->now();
        $this->authorization->assertCanAct($actorId, 'record_incident', null, $now);

        return $this->repository->transactional(function () use ($actorId, $idempotencyKey, $input, $now): array {
            if ($input['subject_staff_user_id'] !== null
                && !$this->repository->lockStaff($input['subject_staff_user_id'])) {
                throw new DomainException('DISCIPLINE_SUBJECT_NOT_FOUND');
            }

            $existing = $this->repository->incidentByCreateIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['incident_hash'] ?? ''), $input['incident_hash'])) {
                    return $this->incidentReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_INCIDENT_IDEMPOTENCY_CONFLICT');
            }

            $incidentId = $this->repository->insertIncident($input);
            if ($incidentId <= 0) {
                throw new DomainException('DISCIPLINE_INCIDENT_PERSIST_FAILED');
            }
            $stored = $input + ['id' => $incidentId, 'status' => 'reported', 'lock_version' => 1];
            $this->audit->recordEvent(
                'staff_discipline_incident_recorded',
                'staff_discipline_incidents',
                $incidentId,
                $input['incident_no'],
                [
                    'incident_no' => $input['incident_no'],
                    'subject_staff_user_id' => $input['subject_staff_user_id'],
                    'classification' => $input['classification'],
                    'confidentiality_level' => $input['confidentiality_level'],
                    'source_resource_type' => $input['source_resource_type'],
                    'source_resource_id' => $input['source_resource_id'],
                    'incident_hash' => $input['incident_hash'],
                    'description_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->incidentReceipt($stored, false);
        });
    }

    /**
     * Opens one case from one reported incident. The subject is copied only
     * from the incident so it cannot be altered by a later browser request.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function openCase(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $incidentId = $this->positiveId($command['incident_id'] ?? null, 'DISCIPLINE_INCIDENT_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['create_idempotency_key'] ?? null,
            64,
            'DISCIPLINE_CASE_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($command, $actorId, $incidentId, $idempotencyKey, $now): array {
            $incident = $this->requiredIncident($incidentId);
            $this->authorization->assertCanAct($actorId, 'open_case', $incident, $now);
            if (!in_array((string) ($incident['status'] ?? ''), ['reported', 'triage'], true)) {
                throw new DomainException('DISCIPLINE_INCIDENT_NOT_CASE_ELIGIBLE');
            }
            $subjectStaffUserId = $this->positiveId(
                $incident['subject_staff_user_id'] ?? null,
                'DISCIPLINE_CASE_SUBJECT_REQUIRED'
            );
            if (!$this->repository->lockStaff($subjectStaffUserId)) {
                throw new DomainException('DISCIPLINE_SUBJECT_NOT_FOUND');
            }
            $requestedSubject = $this->nullablePositiveId($command['subject_staff_user_id'] ?? null);
            if ($requestedSubject !== null && $requestedSubject !== $subjectStaffUserId) {
                throw new DomainException('DISCIPLINE_CASE_SUBJECT_IMMUTABLE');
            }

            $input = $this->caseInput($command, $actorId, $idempotencyKey, $incident, $now);
            $existing = $this->repository->caseByCreateIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['case_hash'] ?? ''), $input['case_hash'])) {
                    return $this->caseReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_CASE_IDEMPOTENCY_CONFLICT');
            }
            if ($this->repository->caseByIncidentForUpdate($incidentId) !== null) {
                throw new DomainException('DISCIPLINE_INCIDENT_ALREADY_CASED');
            }

            $caseId = $this->repository->insertCase($input);
            if ($caseId <= 0) {
                throw new DomainException('DISCIPLINE_CASE_PERSIST_FAILED');
            }
            $incidentLock = $this->positiveId($incident['lock_version'] ?? null, 'DISCIPLINE_INCIDENT_LOCK_INVALID');
            if ((string) ($incident['status'] ?? '') === 'reported'
                && !$this->repository->markIncidentTriaged($incidentId, $incidentLock)) {
                throw new DomainException('DISCIPLINE_INCIDENT_STALE');
            }

            $stored = $input + ['id' => $caseId, 'status' => 'reported', 'lock_version' => 1];
            $this->audit->recordEvent(
                'staff_discipline_case_opened',
                'staff_discipline_cases',
                $caseId,
                $input['case_no'],
                [
                    'case_no' => $input['case_no'],
                    'incident_id' => $incidentId,
                    'subject_staff_user_id' => $input['subject_staff_user_id'],
                    'classification' => $input['classification'],
                    'confidentiality_level' => $input['confidentiality_level'],
                    'case_hash' => $input['case_hash'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            if ((string) ($incident['status'] ?? '') === 'reported') {
                $this->audit->recordEvent(
                    'staff_discipline_incident_triaged',
                    'staff_discipline_incidents',
                    $incidentId,
                    (string) ($incident['incident_no'] ?? ''),
                    ['case_id' => $caseId, 'previous_status' => 'reported', 'status' => 'triage'],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
            }

            return $this->caseReceipt($stored, false);
        });
    }

    /**
     * Moves a newly opened case into the documented triage state. Investigation
     * start remains owned by the investigation service.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function triageCase(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($actorId, $caseId, $expectedLockVersion, $now): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'triage_case', $case, $now);
            if ((string) ($case['status'] ?? '') !== 'reported'
                || (int) ($case['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            if (!$this->repository->transitionCase($caseId, $expectedLockVersion, 'reported', 'triage', [])) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $after = array_replace($case, ['status' => 'triage', 'lock_version' => $expectedLockVersion + 1]);
            $this->audit->recordEvent(
                'staff_discipline_case_triaged',
                'staff_discipline_cases',
                $caseId,
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => 'reported', 'status' => 'triage'],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->caseReceipt($after, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function addParty(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_PARTY_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($command, $actorId, $caseId, $idempotencyKey, $now): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'add_case_party', $case, $now);
            if (!in_array((string) ($case['status'] ?? ''), ['reported', 'triage', 'under_investigation', 'pending_decision'], true)) {
                throw new DomainException('DISCIPLINE_CASE_PARTY_CHANGE_FORBIDDEN');
            }
            $input = $this->partyInput($command, $actorId, $idempotencyKey, $case);
            $existing = $this->repository->partyByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['party_hash'] ?? ''), $input['party_hash'])) {
                    return $this->partyReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_PARTY_IDEMPOTENCY_CONFLICT');
            }

            $partyId = $this->repository->insertParty($input);
            if ($partyId <= 0) {
                throw new DomainException('DISCIPLINE_PARTY_PERSIST_FAILED');
            }
            $stored = $input + ['id' => $partyId, 'status' => 'active', 'lock_version' => 1];
            $this->audit->recordEvent(
                'staff_discipline_case_party_added',
                'staff_discipline_case_parties',
                $partyId,
                null,
                [
                    'case_id' => $caseId,
                    'party_role' => $input['party_role'],
                    'visibility_scope' => $input['visibility_scope'],
                    'is_internal_party' => $input['party_user_id'] !== null,
                    'party_hash' => $input['party_hash'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->partyReceipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function declarePartyConflict(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $partyId = $this->positiveId($command['party_id'] ?? null, 'DISCIPLINE_PARTY_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_PARTY_LOCK_INVALID'
        );
        $declaration = $this->requiredText(
            $command['conflict_declaration'] ?? null,
            4000,
            'DISCIPLINE_PARTY_CONFLICT_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $partyId,
            $expectedLockVersion,
            $declaration,
            $now
        ): array {
            $party = $this->requiredParty($partyId);
            $case = $this->requiredCase($this->positiveId($party['case_id'] ?? null, 'DISCIPLINE_PARTY_CASE_INVALID'));
            $this->authorization->assertCanAct($actorId, 'declare_party_conflict', $case, $now);
            if ((string) ($party['status'] ?? '') !== 'active'
                || (int) ($party['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('DISCIPLINE_PARTY_STALE');
            }
            if (!$this->repository->declarePartyConflict(
                $partyId,
                $expectedLockVersion,
                $declaration,
                $this->instant($now)
            )) {
                throw new DomainException('DISCIPLINE_PARTY_STALE');
            }
            $after = array_replace($party, [
                'conflict_declared_at' => $this->instant($now),
                'conflict_declaration' => $declaration,
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_party_conflict_declared',
                'staff_discipline_case_parties',
                $partyId,
                null,
                [
                    'case_id' => (int) $party['case_id'],
                    'party_role' => (string) ($party['party_role'] ?? ''),
                    'declaration_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->partyReceipt($after, false);
        });
    }

    /**
     * A party is withdrawn as a case event. It is not deleted even before a
     * decision, preserving which access and conflict information existed.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function withdrawParty(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $partyId = $this->positiveId($command['party_id'] ?? null, 'DISCIPLINE_PARTY_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_PARTY_LOCK_INVALID'
        );
        $reason = $this->requiredText(
            $command['withdrawal_reason'] ?? null,
            4000,
            'DISCIPLINE_PARTY_WITHDRAWAL_REASON_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $partyId,
            $expectedLockVersion,
            $reason,
            $now
        ): array {
            $party = $this->requiredParty($partyId);
            $case = $this->requiredCase($this->positiveId($party['case_id'] ?? null, 'DISCIPLINE_PARTY_CASE_INVALID'));
            $this->authorization->assertCanAct($actorId, 'withdraw_case_party', $case, $now);
            if ((string) ($party['status'] ?? '') === 'withdrawn') {
                return $this->partyReceipt($party, true);
            }
            if ((string) ($party['status'] ?? '') !== 'active'
                || (int) ($party['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('DISCIPLINE_PARTY_STALE');
            }
            if (!$this->repository->withdrawParty(
                $partyId,
                $expectedLockVersion,
                $actorId,
                $reason,
                $this->instant($now)
            )) {
                throw new DomainException('DISCIPLINE_PARTY_STALE');
            }
            $after = array_replace($party, [
                'status' => 'withdrawn',
                'withdrawn_by_user_id' => $actorId,
                'withdrawn_at' => $this->instant($now),
                'withdrawal_reason' => $reason,
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_case_party_withdrawn',
                'staff_discipline_case_parties',
                $partyId,
                null,
                [
                    'case_id' => (int) $party['case_id'],
                    'party_role' => (string) ($party['party_role'] ?? ''),
                    'reason_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->partyReceipt($after, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function cancelIncident(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $incidentId = $this->positiveId($command['incident_id'] ?? null, 'DISCIPLINE_INCIDENT_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_INCIDENT_LOCK_INVALID'
        );
        $reason = $this->requiredText(
            $command['cancellation_reason'] ?? null,
            4000,
            'DISCIPLINE_INCIDENT_CANCELLATION_REASON_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $incidentId,
            $expectedLockVersion,
            $reason,
            $now
        ): array {
            $incident = $this->requiredIncident($incidentId);
            $this->authorization->assertCanAct($actorId, 'cancel_incident', $incident, $now);
            if ($this->repository->caseByIncidentForUpdate($incidentId) !== null) {
                throw new DomainException('DISCIPLINE_INCIDENT_CASE_EXISTS');
            }
            if ((string) ($incident['status'] ?? '') === 'cancelled') {
                return $this->incidentReceipt($incident, true);
            }
            if (!in_array((string) ($incident['status'] ?? ''), ['draft', 'reported', 'triage'], true)
                || (int) ($incident['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('DISCIPLINE_INCIDENT_STALE');
            }
            if (!$this->repository->cancelIncident(
                $incidentId,
                $expectedLockVersion,
                $actorId,
                $reason,
                $this->instant($now)
            )) {
                throw new DomainException('DISCIPLINE_INCIDENT_STALE');
            }
            $after = array_replace($incident, [
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $actorId,
                'cancelled_at' => $this->instant($now),
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_incident_cancelled',
                'staff_discipline_incidents',
                $incidentId,
                (string) ($incident['incident_no'] ?? ''),
                ['reason_provided' => true, 'previous_status' => (string) ($incident['status'] ?? '')],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->incidentReceipt($after, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function cancelCase(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $reason = $this->requiredText(
            $command['cancellation_reason'] ?? null,
            4000,
            'DISCIPLINE_CASE_CANCELLATION_REASON_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $caseId,
            $expectedLockVersion,
            $reason,
            $now
        ): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'cancel_case', $case, $now);
            $status = (string) ($case['status'] ?? '');
            if ($status === 'cancelled') {
                return $this->caseReceipt($case, true);
            }
            if (!in_array($status, self::CANCELLABLE_CASE_STATES, true)
                || (int) ($case['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('DISCIPLINE_CASE_CANCELLATION_FORBIDDEN');
            }
            if (!$this->repository->cancelCase(
                $caseId,
                $expectedLockVersion,
                $status,
                $actorId,
                $reason,
                $this->instant($now)
            )) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $after = array_replace($case, [
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $actorId,
                'cancelled_at' => $this->instant($now),
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_case_cancelled',
                'staff_discipline_cases',
                $caseId,
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => $status, 'reason_provided' => true],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->caseReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function incidentInput(array $command, int $actorId, string $idempotencyKey): array
    {
        $subjectStaffUserId = $this->nullablePositiveId($command['subject_staff_user_id'] ?? null);
        $sourceType = $this->nullableText($command['source_resource_type'] ?? null, 100, 'DISCIPLINE_SOURCE_TYPE_INVALID');
        $sourceId = $this->nullablePositiveId($command['source_resource_id'] ?? null);
        if (($sourceType === null) !== ($sourceId === null)) {
            throw new InvalidArgumentException('DISCIPLINE_SOURCE_REFERENCE_INCOMPLETE');
        }
        $description = $this->requiredText($command['description'] ?? null, 10000, 'DISCIPLINE_INCIDENT_DESCRIPTION_REQUIRED');
        $classification = $this->requiredText($command['classification'] ?? 'general', 100, 'DISCIPLINE_CLASSIFICATION_INVALID');
        $confidentiality = $this->enum(
            $command['confidentiality_level'] ?? 'restricted',
            self::CONFIDENTIALITY_LEVELS,
            'DISCIPLINE_CONFIDENTIALITY_INVALID'
        );
        $occurredAt = $this->nullableInstant($command['occurred_at'] ?? null, 'DISCIPLINE_OCCURRED_AT_INVALID');
        $incidentNo = $this->nullableText($command['incident_no'] ?? null, 80, 'DISCIPLINE_INCIDENT_NO_INVALID')
            ?? $this->number('INC', $idempotencyKey);
        $snapshot = $command['source_reference_snapshot'] ?? null;
        if ($snapshot !== null && !is_array($snapshot)) {
            throw new InvalidArgumentException('DISCIPLINE_SOURCE_SNAPSHOT_INVALID');
        }
        $incidentHash = $this->hash([
            'incident_no' => $incidentNo,
            'subject_staff_user_id' => $subjectStaffUserId,
            'source_resource_type' => $sourceType,
            'source_resource_id' => $sourceId,
            'source_reference_snapshot' => $snapshot,
            'classification' => $classification,
            'confidentiality_level' => $confidentiality,
            'description' => $description,
            'occurred_at' => $occurredAt,
            'reported_by_user_id' => $actorId,
        ]);

        return [
            'incident_no' => $incidentNo,
            'subject_staff_user_id' => $subjectStaffUserId,
            'reported_by_user_id' => $actorId,
            'occurred_at' => $occurredAt,
            'source_resource_type' => $sourceType,
            'source_resource_id' => $sourceId,
            'source_reference_snapshot' => $snapshot,
            'classification' => $classification,
            'confidentiality_level' => $confidentiality,
            'description' => $description,
            'create_idempotency_key' => $idempotencyKey,
            'incident_hash' => $incidentHash,
        ];
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $incident @return array<string,mixed> */
    private function caseInput(
        array $command,
        int $actorId,
        string $idempotencyKey,
        array $incident,
        DateTimeImmutable $now
    ): array {
        $incidentId = $this->positiveId($incident['id'] ?? null, 'DISCIPLINE_INCIDENT_ID_INVALID');
        $subjectStaffUserId = $this->positiveId(
            $incident['subject_staff_user_id'] ?? null,
            'DISCIPLINE_CASE_SUBJECT_REQUIRED'
        );
        $classification = $this->requiredText(
            $command['classification'] ?? $incident['classification'] ?? 'general',
            100,
            'DISCIPLINE_CLASSIFICATION_INVALID'
        );
        $confidentiality = $this->enum(
            $command['confidentiality_level'] ?? $incident['confidentiality_level'] ?? 'restricted',
            self::CONFIDENTIALITY_LEVELS,
            'DISCIPLINE_CONFIDENTIALITY_INVALID'
        );
        $caseNo = $this->nullableText($command['case_no'] ?? null, 80, 'DISCIPLINE_CASE_NO_INVALID')
            ?? $this->number('DISC', $idempotencyKey);
        $caseHash = $this->hash([
            'case_no' => $caseNo,
            'incident_id' => $incidentId,
            'subject_staff_user_id' => $subjectStaffUserId,
            'classification' => $classification,
            'confidentiality_level' => $confidentiality,
            'opened_by_user_id' => $actorId,
        ]);

        return [
            'case_no' => $caseNo,
            'incident_id' => $incidentId,
            'subject_staff_user_id' => $subjectStaffUserId,
            'classification' => $classification,
            'confidentiality_level' => $confidentiality,
            'opened_by_user_id' => $actorId,
            'opened_at' => $this->instant($now),
            'create_idempotency_key' => $idempotencyKey,
            'case_hash' => $caseHash,
        ];
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $case @return array<string,mixed> */
    private function partyInput(array $command, int $actorId, string $idempotencyKey, array $case): array
    {
        $partyUserId = $this->nullablePositiveId($command['party_user_id'] ?? null);
        $externalLabel = $this->nullableText(
            $command['external_party_label'] ?? null,
            255,
            'DISCIPLINE_PARTY_EXTERNAL_LABEL_INVALID'
        );
        if (($partyUserId === null) === ($externalLabel === null)) {
            throw new InvalidArgumentException('DISCIPLINE_PARTY_IDENTITY_REQUIRED');
        }
        $partyRole = $this->enum($command['party_role'] ?? null, self::PARTY_ROLES, 'DISCIPLINE_PARTY_ROLE_INVALID');
        $visibility = $this->enum(
            $command['visibility_scope'] ?? 'case_team',
            self::VISIBILITY_SCOPES,
            'DISCIPLINE_PARTY_VISIBILITY_INVALID'
        );
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $subjectStaffUserId = $this->positiveId(
            $case['subject_staff_user_id'] ?? null,
            'DISCIPLINE_CASE_SUBJECT_REQUIRED'
        );
        if ($partyRole === 'subject' && $partyUserId !== $subjectStaffUserId) {
            throw new DomainException('DISCIPLINE_PARTY_SUBJECT_MISMATCH');
        }
        $partyHash = $this->hash([
            'case_id' => $caseId,
            'party_user_id' => $partyUserId,
            'external_party_label' => $externalLabel,
            'party_role' => $partyRole,
            'visibility_scope' => $visibility,
            'added_by_user_id' => $actorId,
        ]);

        return [
            'case_id' => $caseId,
            'party_user_id' => $partyUserId,
            'external_party_label' => $externalLabel,
            'party_role' => $partyRole,
            'visibility_scope' => $visibility,
            'added_by_user_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'party_hash' => $partyHash,
        ];
    }

    /** @return array<string,mixed> */
    private function requiredIncident(int $incidentId): array
    {
        $incident = $this->repository->incidentForUpdate($incidentId);
        if ($incident === null) {
            throw new DomainException('DISCIPLINE_INCIDENT_NOT_FOUND');
        }

        return $incident;
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
    private function requiredParty(int $partyId): array
    {
        $party = $this->repository->partyForUpdate($partyId);
        if ($party === null) {
            throw new DomainException('DISCIPLINE_PARTY_NOT_FOUND');
        }

        return $party;
    }

    /** @param array<string,mixed> $incident @return array<string,mixed> */
    private function incidentReceipt(array $incident, bool $replayed): array
    {
        return [
            'incident_id' => $this->positiveId($incident['id'] ?? null, 'DISCIPLINE_INCIDENT_PERSIST_FAILED'),
            'incident_no' => (string) ($incident['incident_no'] ?? ''),
            'status' => (string) ($incident['status'] ?? ''),
            'classification' => (string) ($incident['classification'] ?? ''),
            'confidentiality_level' => (string) ($incident['confidentiality_level'] ?? ''),
            'lock_version' => (int) ($incident['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $case @return array<string,mixed> */
    private function caseReceipt(array $case, bool $replayed): array
    {
        return [
            'case_id' => $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_PERSIST_FAILED'),
            'case_no' => (string) ($case['case_no'] ?? ''),
            'incident_id' => (int) ($case['incident_id'] ?? 0),
            'subject_staff_user_id' => (int) ($case['subject_staff_user_id'] ?? 0),
            'status' => (string) ($case['status'] ?? ''),
            'classification' => (string) ($case['classification'] ?? ''),
            'confidentiality_level' => (string) ($case['confidentiality_level'] ?? ''),
            'lock_version' => (int) ($case['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $party @return array<string,mixed> */
    private function partyReceipt(array $party, bool $replayed): array
    {
        return [
            'party_id' => $this->positiveId($party['id'] ?? null, 'DISCIPLINE_PARTY_PERSIST_FAILED'),
            'case_id' => (int) ($party['case_id'] ?? 0),
            'party_role' => (string) ($party['party_role'] ?? ''),
            'visibility_scope' => (string) ($party['visibility_scope'] ?? ''),
            'status' => (string) ($party['status'] ?? ''),
            'conflict_declared' => ($party['conflict_declared_at'] ?? null) !== null,
            'lock_version' => (int) ($party['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'DISCIPLINE_IDENTIFIER_INVALID');
    }

    private function requiredText(mixed $value, int $maxBytes, string $error): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $text = trim($value);
        if ($text === '' || strlen($text) > $maxBytes) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxBytes, string $error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredText($value, $maxBytes, $error);
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $error): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function nullableInstant(mixed $value, string $error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        try {
            return $this->instant(new DateTimeImmutable($value));
        } catch (\Throwable) {
            throw new InvalidArgumentException($error);
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function instant(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function number(string $prefix, string $idempotencyKey): string
    {
        return $prefix . '-' . strtoupper(substr(hash('sha256', $idempotencyKey), 0, 16));
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException) {
            throw new InvalidArgumentException('DISCIPLINE_COMMAND_SERIALIZATION_INVALID');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
