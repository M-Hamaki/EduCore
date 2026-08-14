<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineEvidenceStorage;
use EduCore\Modules\Staff\Contracts\DisciplineInvestigationRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Owns investigation assignment/completion and the append-only evidence chain.
 *
 * The service authorizes before any private file validation or storage, keeps
 * the database/audit write in one transaction, and cleans a newly moved file
 * if the transaction fails. It cannot issue a decision or change Finance.
 */
final class DisciplineInvestigationService
{
    /** @var list<string> */
    private const CONFIDENTIALITY_LEVELS = ['normal', 'restricted', 'highly_restricted'];

    /** @var list<string> */
    private const EVIDENCE_KINDS = [
        'statement', 'attendance_reference', 'complaint_reference',
        'document_reference', 'private_attachment', 'physical_item', 'other',
    ];

    /** @var list<string> */
    private const SOURCE_REFERENCE_KINDS = [
        'attendance_reference', 'complaint_reference', 'document_reference',
    ];

    /** @var list<string> */
    private const EVIDENCE_CASE_STATES = [
        'triage',
        'under_investigation',
        'pending_decision',
        'decided',
        'appeal_pending',
        'upheld',
        'amended',
        'revoked',
        'closed',
    ];

    public function __construct(
        private DisciplineInvestigationRepository $repository,
        private DisciplineEvidenceStorage $storage,
        private DisciplineCaseAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * Assigns an independent investigator and opens the investigation state.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function startInvestigation(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $expectedCaseLock = $this->positiveId(
            $command['expected_case_lock_version'] ?? null,
            'DISCIPLINE_CASE_LOCK_INVALID'
        );
        $investigatorId = $this->positiveId(
            $command['investigator_user_id'] ?? null,
            'DISCIPLINE_INVESTIGATOR_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_INVESTIGATION_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $command,
            $actorId,
            $caseId,
            $expectedCaseLock,
            $investigatorId,
            $idempotencyKey,
            $now
        ): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'start_investigation', $case, $now);
            $input = $this->investigationInput(
                $command,
                $actorId,
                $investigatorId,
                $idempotencyKey,
                $case,
                $now
            );
            $existing = $this->repository->investigationByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['investigation_hash'] ?? ''), $input['investigation_hash'])) {
                    return $this->investigationReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_INVESTIGATION_IDEMPOTENCY_CONFLICT');
            }
            $status = (string) ($case['status'] ?? '');
            if (!in_array($status, ['triage', 'reopened'], true)
                || (int) ($case['lock_version'] ?? 0) !== $expectedCaseLock) {
                throw new DomainException('DISCIPLINE_CASE_INVESTIGATION_START_FORBIDDEN');
            }
            $reporterId = $this->nullablePositiveId($case['incident_reported_by_user_id'] ?? null);
            $openerId = $this->nullablePositiveId($case['opened_by_user_id'] ?? null);
            if ($investigatorId === $reporterId || $investigatorId === $openerId) {
                throw new DomainException('DISCIPLINE_INVESTIGATOR_RECORDER_CONFLICT');
            }
            if (!$this->repository->lockUser($investigatorId)) {
                throw new DomainException('DISCIPLINE_INVESTIGATOR_NOT_FOUND');
            }

            $investigationId = $this->repository->insertInvestigation($input);
            if ($investigationId <= 0) {
                throw new DomainException('DISCIPLINE_INVESTIGATION_PERSIST_FAILED');
            }
            if (!$this->repository->transitionCase(
                $caseId,
                $expectedCaseLock,
                $status,
                'under_investigation'
            )) {
                throw new DomainException('DISCIPLINE_CASE_STALE');
            }
            $stored = $input + [
                'id' => $investigationId,
                'status' => 'in_progress',
                'lock_version' => 1,
            ];
            $this->audit->recordEvent(
                'staff_discipline_investigation_started',
                'staff_discipline_investigations',
                $investigationId,
                null,
                [
                    'case_id' => $caseId,
                    'investigator_user_id' => $investigatorId,
                    'confidentiality_level' => $input['confidentiality_level'],
                    'investigation_hash' => $input['investigation_hash'],
                    'allegation_provided' => $input['allegation'] !== null,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_discipline_case_investigation_started',
                'staff_discipline_cases',
                $caseId,
                (string) ($case['case_no'] ?? ''),
                ['previous_status' => $status, 'status' => 'under_investigation', 'investigation_id' => $investigationId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->investigationReceipt($stored, false);
        });
    }

    /**
     * Completes one investigation. A later decision owner determines whether
     * all required investigations are complete before moving the case forward.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function completeInvestigation(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $investigationId = $this->positiveId(
            $command['investigation_id'] ?? null,
            'DISCIPLINE_INVESTIGATION_ID_INVALID'
        );
        $expectedLock = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'DISCIPLINE_INVESTIGATION_LOCK_INVALID'
        );
        $findings = $this->requiredText($command['findings'] ?? null, 20000, 'DISCIPLINE_FINDINGS_REQUIRED');
        $recommendation = $this->requiredText(
            $command['recommendation'] ?? null,
            10000,
            'DISCIPLINE_RECOMMENDATION_REQUIRED'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $investigationId,
            $expectedLock,
            $findings,
            $recommendation,
            $now
        ): array {
            $investigation = $this->requiredInvestigation($investigationId);
            $case = $this->requiredCase(
                $this->positiveId($investigation['case_id'] ?? null, 'DISCIPLINE_INVESTIGATION_CASE_INVALID')
            );
            $this->authorization->assertCanAct($actorId, 'complete_investigation', $case, $now);
            if ((int) ($investigation['investigator_user_id'] ?? 0) !== $actorId) {
                throw new DomainException('DISCIPLINE_INVESTIGATION_OWNER_ONLY');
            }
            if ((string) ($investigation['status'] ?? '') !== 'in_progress'
                || (int) ($investigation['lock_version'] ?? 0) !== $expectedLock) {
                throw new DomainException('DISCIPLINE_INVESTIGATION_STALE');
            }
            if (!$this->repository->completeInvestigation(
                $investigationId,
                $expectedLock,
                $findings,
                $recommendation,
                $this->instant($now)
            )) {
                throw new DomainException('DISCIPLINE_INVESTIGATION_STALE');
            }
            $after = array_replace($investigation, [
                'status' => 'completed',
                'findings' => $findings,
                'recommendation' => $recommendation,
                'completed_at' => $this->instant($now),
                'lock_version' => $expectedLock + 1,
            ]);
            $this->audit->recordEvent(
                'staff_discipline_investigation_completed',
                'staff_discipline_investigations',
                $investigationId,
                null,
                [
                    'case_id' => (int) $investigation['case_id'],
                    'investigator_user_id' => $actorId,
                    'findings_provided' => true,
                    'recommendation_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->investigationReceipt($after, false);
        });
    }

    /**
     * Adds a non-file piece of evidence. Linked resources remain references;
     * this service never changes their raw data or lifecycle.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function recordReferenceEvidence(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_EVIDENCE_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use ($command, $actorId, $caseId, $idempotencyKey, $now): array {
            $case = $this->requiredCase($caseId);
            $this->authorization->assertCanAct($actorId, 'record_evidence', $case, $now);
            $context = $this->evidenceContext($command, $case, true);
            $input = $this->referenceEvidenceInput($command, $actorId, $idempotencyKey, $context, $now);
            $existing = $this->repository->evidenceByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['chain_hash'] ?? ''), $input['chain_hash'])) {
                    return $this->evidenceReceipt($existing, true);
                }
                throw new DomainException('DISCIPLINE_EVIDENCE_IDEMPOTENCY_CONFLICT');
            }
            $evidenceId = $this->repository->insertEvidence($input);
            if ($evidenceId <= 0) {
                throw new DomainException('DISCIPLINE_EVIDENCE_PERSIST_FAILED');
            }
            $stored = $input + ['id' => $evidenceId, 'status' => 'collected'];
            $this->auditEvidence($actorId, $stored, false, $now);

            return $this->evidenceReceipt($stored, false);
        });
    }

    /**
     * Stores one private attachment only after the case authorization succeeds.
     * A database or audit failure removes the just-moved file and leaves no
     * metadata/reference behind.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function uploadPrivateEvidence(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'DISCIPLINE_ACTOR_INVALID');
        $caseId = $this->positiveId($command['case_id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'DISCIPLINE_EVIDENCE_IDEMPOTENCY_INVALID'
        );
        $file = $command['file'] ?? null;
        if (!is_array($file)) {
            throw new InvalidArgumentException('DISCIPLINE_EVIDENCE_FILE_INVALID');
        }
        $now = $this->now();
        $storedFile = null;

        try {
            return $this->repository->transactional(function () use (
                $command,
                $actorId,
                $caseId,
                $idempotencyKey,
                $file,
                $now,
                &$storedFile
            ): array {
                $case = $this->requiredCase($caseId);
                $this->authorization->assertCanAct($actorId, 'upload_evidence', $case, $now);
                $context = $this->evidenceContext($command, $case, true);
                $existing = $this->repository->evidenceByIdempotencyForUpdate($idempotencyKey);
                if ($existing !== null) {
                    if ((int) ($existing['case_id'] ?? 0) === $caseId
                        && (int) ($existing['investigation_id'] ?? 0) === (int) ($context['investigation_id'] ?? 0)
                        && (string) ($existing['evidence_kind'] ?? '') === 'private_attachment') {
                        return $this->evidenceReceipt($existing, true);
                    }
                    throw new DomainException('DISCIPLINE_EVIDENCE_IDEMPOTENCY_CONFLICT');
                }

                $storedFile = $this->storage->storeUploadedFile($file);
                $this->assertStoredFile($storedFile);
                $input = $this->privateEvidenceInput(
                    $command,
                    $actorId,
                    $idempotencyKey,
                    $context,
                    $storedFile,
                    $now
                );
                $evidenceId = $this->repository->insertEvidence($input);
                if ($evidenceId <= 0) {
                    throw new DomainException('DISCIPLINE_EVIDENCE_PERSIST_FAILED');
                }
                $stored = $input + ['id' => $evidenceId, 'status' => 'collected'];
                $this->auditEvidence($actorId, $stored, true, $now);

                return $this->evidenceReceipt($stored, false);
            });
        } catch (\Throwable $exception) {
            if (is_array($storedFile) && isset($storedFile['storage_ref'])) {
                $this->cleanupNewFile((string) $storedFile['storage_ref']);
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $case @return array<string,mixed> */
    private function investigationInput(
        array $command,
        int $actorId,
        int $investigatorId,
        string $idempotencyKey,
        array $case,
        DateTimeImmutable $now
    ): array {
        $allegation = $this->nullableText($command['allegation'] ?? null, 10000, 'DISCIPLINE_ALLEGATION_INVALID');
        $confidentiality = $this->enum(
            $command['confidentiality_level'] ?? $case['confidentiality_level'] ?? 'restricted',
            self::CONFIDENTIALITY_LEVELS,
            'DISCIPLINE_CONFIDENTIALITY_INVALID'
        );
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $investigationHash = $this->hash([
            'case_id' => $caseId,
            'investigator_user_id' => $investigatorId,
            'assigned_by_user_id' => $actorId,
            'allegation' => $allegation,
            'confidentiality_level' => $confidentiality,
        ]);

        return [
            'case_id' => $caseId,
            'investigator_user_id' => $investigatorId,
            'assigned_by_user_id' => $actorId,
            'assigned_at' => $this->instant($now),
            'started_at' => $this->instant($now),
            'allegation' => $allegation,
            'confidentiality_level' => $confidentiality,
            'idempotency_key' => $idempotencyKey,
            'investigation_hash' => $investigationHash,
        ];
    }

    /**
     * @param array<string,mixed> $command
     * @param array<string,mixed> $case
     * @return array{case_id:int,investigation_id:?int,prior_evidence_id:?int,prior_chain_hash:?string}
     */
    private function evidenceContext(array $command, array $case, bool $requireOpenInvestigation): array
    {
        $caseStatus = (string) ($case['status'] ?? '');
        if (!in_array($caseStatus, self::EVIDENCE_CASE_STATES, true)) {
            throw new DomainException('DISCIPLINE_CASE_EVIDENCE_CHANGE_FORBIDDEN');
        }
        $caseId = $this->positiveId($case['id'] ?? null, 'DISCIPLINE_CASE_ID_INVALID');
        $investigationId = $this->nullablePositiveId($command['investigation_id'] ?? null);
        if ($investigationId !== null) {
            $investigation = $this->requiredInvestigation($investigationId);
            if ((int) ($investigation['case_id'] ?? 0) !== $caseId) {
                throw new DomainException('DISCIPLINE_EVIDENCE_INVESTIGATION_MISMATCH');
            }
            if ($requireOpenInvestigation
                && !in_array((string) ($investigation['status'] ?? ''), ['in_progress', 'completed'], true)) {
                throw new DomainException('DISCIPLINE_EVIDENCE_INVESTIGATION_NOT_ACTIVE');
            }
        }
        $priorEvidenceId = $this->nullablePositiveId($command['prior_evidence_id'] ?? null);
        $priorChainHash = null;
        if ($priorEvidenceId !== null) {
            $prior = $this->requiredEvidence($priorEvidenceId);
            if ((int) ($prior['case_id'] ?? 0) !== $caseId) {
                throw new DomainException('DISCIPLINE_EVIDENCE_PREDECESSOR_MISMATCH');
            }
            $priorChainHash = $this->hashValue($prior['chain_hash'] ?? null, 'DISCIPLINE_EVIDENCE_CHAIN_INVALID');
        }

        return [
            'case_id' => $caseId,
            'investigation_id' => $investigationId,
            'prior_evidence_id' => $priorEvidenceId,
            'prior_chain_hash' => $priorChainHash,
        ];
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $context @return array<string,mixed> */
    private function referenceEvidenceInput(
        array $command,
        int $actorId,
        string $idempotencyKey,
        array $context,
        DateTimeImmutable $now
    ): array {
        $kind = $this->enum($command['evidence_kind'] ?? null, self::EVIDENCE_KINDS, 'DISCIPLINE_EVIDENCE_KIND_INVALID');
        if ($kind === 'private_attachment') {
            throw new InvalidArgumentException('DISCIPLINE_EVIDENCE_PRIVATE_UPLOAD_REQUIRED');
        }
        $summary = $this->requiredText(
            $command['evidence_summary'] ?? null,
            10000,
            'DISCIPLINE_EVIDENCE_SUMMARY_REQUIRED'
        );
        [$sourceType, $sourceId] = $this->sourceReference($command, $kind);
        $chainHash = $this->chainHash($context, $kind, $sourceType, $sourceId, $summary, null);

        return [
            'case_id' => $context['case_id'],
            'investigation_id' => $context['investigation_id'],
            'prior_evidence_id' => $context['prior_evidence_id'],
            'evidence_kind' => $kind,
            'source_resource_type' => $sourceType,
            'source_resource_id' => $sourceId,
            'storage_ref' => null,
            'original_name' => null,
            'mime_type' => null,
            'byte_size' => null,
            'content_sha256' => null,
            'chain_hash' => $chainHash,
            'evidence_summary' => $summary,
            'collected_by_user_id' => $actorId,
            'collected_at' => $this->instant($now),
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $context @param array<string,mixed> $storedFile @return array<string,mixed> */
    private function privateEvidenceInput(
        array $command,
        int $actorId,
        string $idempotencyKey,
        array $context,
        array $storedFile,
        DateTimeImmutable $now
    ): array {
        $summary = $this->nullableText(
            $command['evidence_summary'] ?? null,
            10000,
            'DISCIPLINE_EVIDENCE_SUMMARY_INVALID'
        );
        $chainHash = $this->chainHash(
            $context,
            'private_attachment',
            null,
            null,
            $summary,
            (string) $storedFile['sha256']
        );

        return [
            'case_id' => $context['case_id'],
            'investigation_id' => $context['investigation_id'],
            'prior_evidence_id' => $context['prior_evidence_id'],
            'evidence_kind' => 'private_attachment',
            'source_resource_type' => null,
            'source_resource_id' => null,
            'storage_ref' => (string) $storedFile['storage_ref'],
            'original_name' => (string) $storedFile['original_name'],
            'mime_type' => (string) $storedFile['mime'],
            'byte_size' => (int) $storedFile['size'],
            'content_sha256' => (string) $storedFile['sha256'],
            'chain_hash' => $chainHash,
            'evidence_summary' => $summary,
            'collected_by_user_id' => $actorId,
            'collected_at' => $this->instant($now),
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /** @param array<string,mixed> $command @return array{0:?string,1:?int} */
    private function sourceReference(array $command, string $kind): array
    {
        $sourceType = $this->nullableText(
            $command['source_resource_type'] ?? null,
            100,
            'DISCIPLINE_EVIDENCE_SOURCE_TYPE_INVALID'
        );
        $sourceId = $this->nullablePositiveId($command['source_resource_id'] ?? null);
        if (in_array($kind, self::SOURCE_REFERENCE_KINDS, true)) {
            if ($sourceType === null || $sourceId === null) {
                throw new InvalidArgumentException('DISCIPLINE_EVIDENCE_SOURCE_REQUIRED');
            }
        } elseif ($sourceType !== null || $sourceId !== null) {
            throw new InvalidArgumentException('DISCIPLINE_EVIDENCE_SOURCE_NOT_ALLOWED');
        }

        return [$sourceType, $sourceId];
    }

    /** @param array<string,mixed> $stored */
    private function assertStoredFile(array $stored): void
    {
        $storageRef = (string) ($stored['storage_ref'] ?? '');
        if (preg_match(
            '#^private:discipline_evidence/[A-Za-z0-9_-]+\\.(pdf|jpg|jpeg|png)$#',
            $storageRef
        ) !== 1) {
            throw new DomainException('DISCIPLINE_EVIDENCE_STORAGE_REFERENCE_INVALID');
        }
        $originalName = trim((string) ($stored['original_name'] ?? ''));
        if ($originalName === ''
            || basename(str_replace('\\', '/', $originalName)) !== $originalName
            || mb_strlen($originalName, 'UTF-8') > 255) {
            throw new DomainException('DISCIPLINE_EVIDENCE_ORIGINAL_NAME_INVALID');
        }
        if (!in_array($stored['mime'] ?? null, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new DomainException('DISCIPLINE_EVIDENCE_MIME_INVALID');
        }
        $size = filter_var($stored['size'] ?? null, FILTER_VALIDATE_INT);
        if ($size === false || $size <= 0 || $size > 10485760) {
            throw new DomainException('DISCIPLINE_EVIDENCE_SIZE_INVALID');
        }
        if (preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($stored['sha256'] ?? ''))) !== 1) {
            throw new DomainException('DISCIPLINE_EVIDENCE_HASH_INVALID');
        }
    }

    /** @param array<string,mixed> $context */
    private function chainHash(
        array $context,
        string $kind,
        ?string $sourceType,
        ?int $sourceId,
        ?string $summary,
        ?string $contentSha256
    ): string {
        return $this->hash([
            'case_id' => $context['case_id'],
            'investigation_id' => $context['investigation_id'],
            'prior_evidence_id' => $context['prior_evidence_id'],
            'prior_chain_hash' => $context['prior_chain_hash'],
            'evidence_kind' => $kind,
            'source_resource_type' => $sourceType,
            'source_resource_id' => $sourceId,
            'summary_hash' => $summary === null ? null : hash('sha256', $summary),
            'content_sha256' => $contentSha256,
        ]);
    }

    /** @param array<string,mixed> $evidence */
    private function auditEvidence(int $actorId, array $evidence, bool $privateFile, DateTimeImmutable $now): void
    {
        $this->audit->recordEvent(
            $privateFile ? 'staff_discipline_private_evidence_uploaded' : 'staff_discipline_reference_evidence_recorded',
            'staff_discipline_evidence',
            $this->positiveId($evidence['id'] ?? null, 'DISCIPLINE_EVIDENCE_PERSIST_FAILED'),
            null,
            [
                'case_id' => (int) $evidence['case_id'],
                'investigation_id' => $evidence['investigation_id'],
                'evidence_kind' => (string) $evidence['evidence_kind'],
                'source_resource_type' => $evidence['source_resource_type'],
                'source_resource_id' => $evidence['source_resource_id'],
                'private_file' => $privateFile,
                'mime_type' => $evidence['mime_type'],
                'byte_size' => $evidence['byte_size'],
                'chain_hash' => (string) $evidence['chain_hash'],
                'summary_provided' => $evidence['evidence_summary'] !== null,
            ],
            ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
        );
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
    private function requiredInvestigation(int $investigationId): array
    {
        $investigation = $this->repository->investigationForUpdate($investigationId);
        if ($investigation === null) {
            throw new DomainException('DISCIPLINE_INVESTIGATION_NOT_FOUND');
        }

        return $investigation;
    }

    /** @return array<string,mixed> */
    private function requiredEvidence(int $evidenceId): array
    {
        $evidence = $this->repository->evidenceForUpdate($evidenceId);
        if ($evidence === null) {
            throw new DomainException('DISCIPLINE_EVIDENCE_NOT_FOUND');
        }

        return $evidence;
    }

    /** @param array<string,mixed> $investigation @return array<string,mixed> */
    private function investigationReceipt(array $investigation, bool $replayed): array
    {
        return [
            'investigation_id' => $this->positiveId(
                $investigation['id'] ?? null,
                'DISCIPLINE_INVESTIGATION_PERSIST_FAILED'
            ),
            'case_id' => (int) ($investigation['case_id'] ?? 0),
            'investigator_user_id' => (int) ($investigation['investigator_user_id'] ?? 0),
            'status' => (string) ($investigation['status'] ?? ''),
            'lock_version' => (int) ($investigation['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function evidenceReceipt(array $evidence, bool $replayed): array
    {
        return [
            'evidence_id' => $this->positiveId($evidence['id'] ?? null, 'DISCIPLINE_EVIDENCE_PERSIST_FAILED'),
            'case_id' => (int) ($evidence['case_id'] ?? 0),
            'investigation_id' => $this->nullablePositiveId($evidence['investigation_id'] ?? null),
            'evidence_kind' => (string) ($evidence['evidence_kind'] ?? ''),
            'mime_type' => $evidence['mime_type'] ?? null,
            'byte_size' => $evidence['byte_size'] ?? null,
            'chain_hash' => (string) ($evidence['chain_hash'] ?? ''),
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
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException($error);
        }

        return $value;
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

    private function hashValue(mixed $value, string $error): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', strtolower($value)) !== 1) {
            throw new DomainException($error);
        }

        return strtolower($value);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function instant(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
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

    private function cleanupNewFile(string $storageRef): void
    {
        try {
            if (!$this->storage->delete($storageRef)) {
                error_log('Discipline evidence rollback cleanup failed: ' . hash('sha256', $storageRef));
            }
        } catch (\Throwable) {
            error_log('Discipline evidence rollback cleanup threw: ' . hash('sha256', $storageRef));
        }
    }
}
