<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

/** Transaction-owning, audited command owner for schedule policies/calendar. */
final class SchedulePolicyCommandService
{
    private const SCOPE_TYPES = ['global', 'org_unit', 'job_title', 'group', 'staff'];
    private const EXCEPTION_TYPES = ['holiday', 'closure', 'partial_day', 'makeup_day', 'override'];

    private AttendanceTransactionManager $transactions;
    private SchedulePolicyRepository $repository;
    private AuditEventWriter $audit;

    public function __construct(
        AttendanceTransactionManager $transactions,
        SchedulePolicyRepository $repository,
        AuditEventWriter $audit
    ) {
        $this->transactions = $transactions;
        $this->repository = $repository;
        $this->audit = $audit;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createDraft(int $actorId, array $payload, string $idempotencyKey): array
    {
        $this->assertActor($actorId);
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use ($actorId, $payload, $idempotencyKey): array {
            $normalized = $this->normalizePolicyPayload($payload, $actorId);
            $existingPolicyId = (int) ($payload['policy_id'] ?? $payload['policy']['id'] ?? 0);
            $supersedesId = (int) ($normalized['version']['supersedes_id'] ?? 0);
            $payloadHash = $this->payloadHash([
                'actor_id' => $actorId,
                'policy_id' => $existingPolicyId,
                'supersedes_id' => $supersedesId,
                'policy' => $normalized['policy'],
                'version' => $normalized['version'],
                'days' => $normalized['days'],
                'scopes' => $normalized['scopes'],
            ]);
            $replay = $this->replayReceipt('create_draft', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $existing = $this->repository->findVersionByCreateKey($idempotencyKey);
            if ($existing !== null) {
                if (!hash_equals((string) ($existing['create_payload_hash'] ?? ''), $payloadHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT');
                }
                return $this->versionReceipt($existing);
            }

            if ($existingPolicyId > 0) {
                $policy = $this->repository->policyForUpdate($existingPolicyId);
                if ($policy === null) {
                    throw new DomainException('SCHEDULE_POLICY_NOT_FOUND');
                }
                if ($supersedesId <= 0) {
                    throw new DomainException('SCHEDULE_SUPERSEDES_REQUIRED');
                }
                $predecessor = $this->repository->versionForUpdate($supersedesId);
                if ($predecessor === null
                    || (int) ($predecessor['policy_id'] ?? 0) !== $existingPolicyId
                    || ($predecessor['state'] ?? '') !== 'published') {
                    throw new DomainException('SCHEDULE_SUPERSEDES_INVALID');
                }
                $newFrom = new DateTimeImmutable((string) $normalized['version']['valid_from']);
                $oldFrom = new DateTimeImmutable((string) $predecessor['valid_from']);
                if ($newFrom <= $oldFrom) {
                    throw new DomainException('SCHEDULE_SUCCESSOR_RANGE_INVALID');
                }
                $policyId = $existingPolicyId;
                $this->repository->updatePolicy($policyId, $normalized['policy']);
            } else {
                if ($supersedesId > 0) {
                    throw new DomainException('SCHEDULE_SUPERSEDES_INVALID');
                }
                $policyId = $this->repository->insertPolicy($normalized['policy']);
            }
            $normalized['version']['version_no'] = $this->repository->nextVersionNumber($policyId);
            $normalized['version']['create_idempotency_key'] = $idempotencyKey;
            $normalized['version']['create_payload_hash'] = $payloadHash;
            $normalized['version']['created_by'] = $actorId;
            $versionId = $this->repository->insertDraftVersion($policyId, $normalized['version']);
            $this->repository->replaceDraftDays($versionId, $normalized['days']);
            $this->repository->replaceDraftScopes($versionId, $normalized['scopes']);

            $this->audit->recordEvent(
                'staff_schedule_draft_created',
                'staff_schedule_policy_version',
                $versionId,
                $normalized['policy']['name'],
                [
                    'policy_id' => $policyId,
                    'version_no' => $normalized['version']['version_no'],
                    'scope_count' => count($normalized['scopes']),
                    'working_day_count' => count(array_filter(
                        $normalized['days'],
                        static fn (array $day): bool => $day['is_working_day'] === true
                    )),
                    'idempotency_hash' => hash('sha256', $idempotencyKey),
                ],
                ['user_id' => $actorId]
            );

            $result = [
                'policy_id' => $policyId,
                'version_id' => $versionId,
                'version_no' => $normalized['version']['version_no'],
                'state' => 'draft',
                'lock_version' => 1,
            ];
            $this->recordReceipt('create_draft', 'staff_schedule_policy_version', $versionId, $idempotencyKey, $payloadHash, $result, $actorId);

            return $result;
        });
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updateDraft(
        int $versionId,
        int $actorId,
        array $payload,
        int $expectedLockVersion,
        string $idempotencyKey
    ): array {
        $this->assertActor($actorId);
        $this->assertPositiveId($versionId, 'SCHEDULE_VERSION_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'SCHEDULE_LOCK_VERSION_INVALID');
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use (
            $versionId,
            $actorId,
            $payload,
            $expectedLockVersion,
            $idempotencyKey
        ): array {
            $normalized = $this->normalizePolicyPayload($payload, $actorId);
            $payloadHash = $this->payloadHash([
                'version_id' => $versionId,
                'actor_id' => $actorId,
                'expected_lock_version' => $expectedLockVersion,
                'policy' => $normalized['policy'],
                'version' => $normalized['version'],
                'days' => $normalized['days'],
                'scopes' => $normalized['scopes'],
            ]);
            $replay = $this->replayReceipt('update_draft', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->repository->versionForUpdate($versionId);
            if ($current === null) {
                throw new DomainException('SCHEDULE_VERSION_NOT_FOUND');
            }
            if (($current['state'] ?? '') !== 'draft') {
                throw new DomainException('SCHEDULE_VERSION_IMMUTABLE');
            }
            if ((int) ($current['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('SCHEDULE_VERSION_STALE');
            }
            $currentSupersedes = (int) ($current['supersedes_id'] ?? 0) ?: null;
            $versionInput = (array) ($payload['version'] ?? []);
            if (array_key_exists('supersedes_id', $versionInput)) {
                $requestedSupersedes = (int) ($versionInput['supersedes_id'] ?? 0) ?: null;
                if ($requestedSupersedes !== $currentSupersedes) {
                    throw new DomainException('SCHEDULE_SUPERSEDES_IMMUTABLE');
                }
            }
            $normalized['version']['supersedes_id'] = $currentSupersedes;
            $normalized['version']['last_command_key'] = $idempotencyKey;
            $normalized['version']['last_command_payload_hash'] = $payloadHash;
            $updated = $this->repository->updateDraftVersion(
                $versionId,
                $expectedLockVersion,
                $normalized['version']
            );
            if (!$updated) {
                throw new DomainException('SCHEDULE_VERSION_STALE');
            }
            $this->repository->updatePolicy((int) $current['policy_id'], $normalized['policy']);
            $this->repository->replaceDraftDays($versionId, $normalized['days']);
            $this->repository->replaceDraftScopes($versionId, $normalized['scopes']);

            $this->audit->recordEvent(
                'staff_schedule_draft_updated',
                'staff_schedule_policy_version',
                $versionId,
                $normalized['policy']['name'],
                [
                    'policy_id' => (int) $current['policy_id'],
                    'from_lock_version' => $expectedLockVersion,
                    'to_lock_version' => $expectedLockVersion + 1,
                    'scope_count' => count($normalized['scopes']),
                    'idempotency_hash' => hash('sha256', $idempotencyKey),
                ],
                ['user_id' => $actorId]
            );

            $result = [
                'policy_id' => (int) $current['policy_id'],
                'version_id' => $versionId,
                'version_no' => (int) ($current['version_no'] ?? 1),
                'state' => 'draft',
                'lock_version' => $expectedLockVersion + 1,
            ];
            $this->recordReceipt('update_draft', 'staff_schedule_policy_version', $versionId, $idempotencyKey, $payloadHash, $result, $actorId);

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function publish(
        int $versionId,
        int $actorId,
        DateTimeImmutable $publishedAt,
        string $idempotencyKey
    ): array {
        $this->assertActor($actorId);
        $this->assertPositiveId($versionId, 'SCHEDULE_VERSION_ID_INVALID');
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use (
            $versionId,
            $actorId,
            $publishedAt,
            $idempotencyKey
        ): array {
            $payloadHash = $this->payloadHash([
                'version_id' => $versionId,
                'actor_id' => $actorId,
            ]);
            $replay = $this->replayReceipt('publish', $idempotencyKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->repository->versionForUpdate($versionId);
            if ($current === null) {
                throw new DomainException('SCHEDULE_VERSION_NOT_FOUND');
            }
            if (($current['state'] ?? '') !== 'draft') {
                throw new DomainException('SCHEDULE_VERSION_IMMUTABLE');
            }

            $conflicts = $this->repository->publicationConflicts($versionId);
            if ($conflicts !== []) {
                $ids = array_values(array_unique(array_map(
                    static fn (array $conflict): int => (int) ($conflict['version_id'] ?? 0),
                    $conflicts
                )));
                sort($ids, SORT_NUMERIC);
                throw new DomainException('SCHEDULE_PUBLICATION_CONFLICT:' . implode(',', $ids));
            }

            $lockVersion = (int) ($current['lock_version'] ?? 0);
            if (!$this->repository->markPublished(
                $versionId,
                $lockVersion,
                $actorId,
                $publishedAt,
                $idempotencyKey,
                $payloadHash
            )) {
                throw new DomainException('SCHEDULE_VERSION_STALE');
            }

            $this->audit->recordEvent(
                'staff_schedule_published',
                'staff_schedule_policy_version',
                $versionId,
                (string) ($current['policy_name'] ?? $current['policy_code'] ?? ''),
                [
                    'policy_id' => (int) $current['policy_id'],
                    'version_no' => (int) ($current['version_no'] ?? 0),
                    'published_at' => $publishedAt->format(DateTimeImmutable::ATOM),
                    'idempotency_hash' => hash('sha256', $idempotencyKey),
                ],
                ['user_id' => $actorId]
            );

            $result = [
                'policy_id' => (int) $current['policy_id'],
                'version_id' => $versionId,
                'version_no' => (int) ($current['version_no'] ?? 0),
                'state' => 'published',
                'lock_version' => $lockVersion + 1,
                'published_by' => $actorId,
                'published_at' => $publishedAt->format(DateTimeImmutable::ATOM),
            ];
            $this->recordReceipt('publish', 'staff_schedule_policy_version', $versionId, $idempotencyKey, $payloadHash, $result, $actorId);

            return $result;
        });
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveCalendarException(int $actorId, array $payload, string $idempotencyKey): array
    {
        $this->assertActor($actorId);
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use ($actorId, $payload, $idempotencyKey): array {
            $payloadHash = $this->payloadHash(['actor_id' => $actorId, 'payload' => $payload]);
            $receiptReplay = $this->replayReceipt('save_calendar_exception', $idempotencyKey, $payloadHash);
            if ($receiptReplay !== null) {
                return $receiptReplay;
            }
            $replay = $this->repository->findCalendarExceptionByIdempotency($idempotencyKey);
            if ($replay !== null) {
                if (!hash_equals((string) ($replay['payload_hash'] ?? ''), $payloadHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT');
                }
                return $replay;
            }

            $normalized = $this->normalizeCalendarException($payload, $actorId, $idempotencyKey);
            $normalized['payload_hash'] = $payloadHash;
            $exceptionId = isset($payload['id']) ? (int) $payload['id'] : 0;
            $current = $exceptionId > 0
                ? $this->repository->calendarExceptionForUpdate($exceptionId)
                : null;
            if ($exceptionId > 0 && $current === null) {
                throw new DomainException('CALENDAR_EXCEPTION_NOT_FOUND');
            }

            if ($current !== null && (string) ($current['status'] ?? '') !== 'draft'
                && ($normalized['calendar_date'] !== (string) $current['calendar_date']
                    || $normalized['scope_type'] !== (string) $current['scope_type']
                    || (int) $normalized['scope_id'] !== (int) $current['scope_id'])) {
                throw new DomainException('CALENDAR_SUPERSESSION_SCOPE_MISMATCH');
            }

            $requestedPredecessorId = (int) ($normalized['supersedes_id'] ?? 0);
            $terminal = $this->repository->terminalCalendarExceptionForDateScopeForUpdate(
                (string) $normalized['calendar_date'],
                (string) $normalized['scope_type'],
                (int) $normalized['scope_id']
            );

            if ($current === null) {
                if ((string) $normalized['status'] === 'draft') {
                    if ($requestedPredecessorId > 0
                        && ($terminal === null || (int) ($terminal['id'] ?? 0) !== $requestedPredecessorId)) {
                        throw new DomainException('CALENDAR_SUPERSESSION_INVALID');
                    }
                } else {
                    if ($requestedPredecessorId > 0
                        && ($terminal === null || (int) ($terminal['id'] ?? 0) !== $requestedPredecessorId)) {
                        throw new DomainException('CALENDAR_SUPERSESSION_INVALID');
                    }
                    $current = $terminal;
                }
            } elseif ((string) ($current['status'] ?? '') === 'draft') {
                $storedPredecessorId = (int) ($current['supersedes_id'] ?? 0);
                if ($storedPredecessorId > 0) {
                    if ($requestedPredecessorId > 0 && $requestedPredecessorId !== $storedPredecessorId) {
                        throw new DomainException('CALENDAR_SUPERSESSION_INVALID');
                    }
                    $normalized['supersedes_id'] = $storedPredecessorId;
                }
                if ((string) $normalized['status'] !== 'draft' && $terminal !== null
                    && $storedPredecessorId !== (int) ($terminal['id'] ?? 0)) {
                    throw new DomainException('CALENDAR_EXCEPTION_REQUIRES_SUPERSESSION');
                }
            } elseif ($terminal === null || (int) ($terminal['id'] ?? 0) !== (int) ($current['id'] ?? 0)) {
                throw new DomainException('CALENDAR_EXCEPTION_STALE');
            }

            $referencedSchedule = null;
            if ((int) ($normalized['schedule_policy_version_id'] ?? 0) > 0) {
                $referencedSchedule = $this->repository->findVersion((int) $normalized['schedule_policy_version_id']);
                if ($referencedSchedule === null || ($referencedSchedule['state'] ?? '') !== 'published') {
                    throw new DomainException('CALENDAR_SCHEDULE_VERSION_NOT_PUBLISHED');
                }
            }
            $this->validateCalendarOverride($normalized, $referencedSchedule);
            $action = 'staff_calendar_exception_created';
            if ($current !== null && ($current['status'] ?? '') === 'draft') {
                $expected = (int) ($payload['expected_lock_version'] ?? 0);
                if ($expected <= 0 || $expected !== (int) ($current['lock_version'] ?? 0)) {
                    throw new DomainException('CALENDAR_EXCEPTION_STALE');
                }
                if (!$this->repository->updateDraftCalendarException($exceptionId, $expected, $normalized)) {
                    throw new DomainException('CALENDAR_EXCEPTION_STALE');
                }
                $id = $exceptionId;
                $normalized['lock_version'] = $expected + 1;
                $action = 'staff_calendar_exception_draft_updated';
            } else {
                if ($current !== null) {
                    if ($normalized['calendar_date'] !== (string) $current['calendar_date']
                        || $normalized['scope_type'] !== (string) $current['scope_type']
                        || (int) $normalized['scope_id'] !== (int) $current['scope_id']) {
                        throw new DomainException('CALENDAR_SUPERSESSION_SCOPE_MISMATCH');
                    }
                    $normalized['supersedes_id'] = (int) $current['id'];
                    $action = 'staff_calendar_exception_superseded';
                }
                $id = $this->repository->insertCalendarException($normalized);
                $normalized['lock_version'] = 1;
            }

            $this->audit->recordEvent(
                $action,
                'staff_calendar_exception',
                $id,
                null,
                [
                    'calendar_date' => $normalized['calendar_date'],
                    'scope_type' => $normalized['scope_type'],
                    'scope_id' => $normalized['scope_id'],
                    'exception_type' => $normalized['exception_type'],
                    'status' => $normalized['status'],
                    'supersedes_id' => $normalized['supersedes_id'] ?? null,
                    'idempotency_hash' => hash('sha256', $idempotencyKey),
                ],
                ['user_id' => $actorId]
            );

            $result = $normalized + ['id' => $id];
            $this->recordReceipt(
                'save_calendar_exception',
                'staff_calendar_exception',
                $id,
                $idempotencyKey,
                $payloadHash,
                $result,
                $actorId
            );

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function retireCalendarException(
        int $actorId,
        int $exceptionId,
        int $expectedLockVersion,
        string $idempotencyKey
    ): array {
        $this->assertActor($actorId);
        $this->assertPositiveId($exceptionId, 'CALENDAR_EXCEPTION_ID_INVALID');
        $this->assertPositiveId($expectedLockVersion, 'CALENDAR_EXCEPTION_LOCK_VERSION_INVALID');
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->transactions->transactional(function () use (
            $actorId,
            $exceptionId,
            $expectedLockVersion,
            $idempotencyKey
        ): array {
            $current = $this->repository->calendarExceptionForUpdate($exceptionId);
            if ($current === null) {
                throw new DomainException('CALENDAR_EXCEPTION_NOT_FOUND');
            }
            if ((int) ($current['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('CALENDAR_EXCEPTION_STALE');
            }
            if (($current['status'] ?? '') !== 'active') {
                throw new DomainException('CALENDAR_EXCEPTION_NOT_ACTIVE');
            }
            $payload = $current;
            $payload['id'] = $exceptionId;
            $payload['expected_lock_version'] = $expectedLockVersion;
            $payload['status'] = 'retired';
            unset($payload['idempotency_key'], $payload['payload_hash'], $payload['lock_version']);

            return $this->saveCalendarException($actorId, $payload, $idempotencyKey);
        });
    }

    /** @return array{policy:array<string,mixed>,version:array<string,mixed>,days:list<array<string,mixed>>,scopes:list<array<string,mixed>>} */
    private function normalizePolicyPayload(array $payload, int $actorId): array
    {
        $policyInput = (array) ($payload['policy'] ?? []);
        $code = strtoupper(trim((string) ($policyInput['code'] ?? '')));
        $name = trim((string) ($policyInput['name'] ?? ''));
        if ($code === '' || preg_match('/^[A-Z0-9][A-Z0-9_-]{1,79}$/', $code) !== 1) {
            throw new InvalidArgumentException('SCHEDULE_POLICY_CODE_INVALID');
        }
        if ($name === '' || mb_strlen($name) > 200) {
            throw new InvalidArgumentException('SCHEDULE_POLICY_NAME_INVALID');
        }
        $description = trim((string) ($policyInput['description'] ?? ''));
        if (mb_strlen($description) > 1000) {
            throw new InvalidArgumentException('SCHEDULE_POLICY_DESCRIPTION_INVALID');
        }

        $versionInput = (array) ($payload['version'] ?? []);
        try {
            $scheduleTimezone = new DateTimeZone(trim((string) ($versionInput['timezone'] ?? 'Africa/Cairo')));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('SCHEDULE_TIMEZONE_INVALID', 0, $exception);
        }
        $validFrom = $this->normalizeInstant(
            $versionInput['valid_from'] ?? null,
            'SCHEDULE_VALID_FROM_INVALID',
            $scheduleTimezone
        );
        $validTo = isset($versionInput['valid_to']) && trim((string) $versionInput['valid_to']) !== ''
            ? $this->normalizeInstant($versionInput['valid_to'], 'SCHEDULE_VALID_TO_INVALID', $scheduleTimezone)
            : null;
        if ($validTo !== null && $validTo <= $validFrom) {
            throw new InvalidArgumentException('SCHEDULE_EFFECTIVE_RANGE_INVALID');
        }

        $schedule = WorkSchedule::fromArray([
            'timezone' => $versionInput['timezone'] ?? 'Africa/Cairo',
            'season_start_mmdd' => $versionInput['season_start_mmdd'] ?? $versionInput['season_start'] ?? null,
            'season_end_mmdd' => $versionInput['season_end_mmdd'] ?? $versionInput['season_end'] ?? null,
            'days' => $payload['days'] ?? [],
        ]);
        $days = $schedule->toArray()['days'];
        if ($days === []) {
            throw new DomainException('SCHEDULE_DAYS_REQUIRED');
        }

        $scopes = $this->normalizeScopes((array) ($payload['scopes'] ?? []), $actorId, $scheduleTimezone);
        if ($scopes === []) {
            throw new DomainException('SCHEDULE_SCOPES_REQUIRED');
        }

        return [
            'policy' => [
                'code' => $code,
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'status' => 'active',
                'created_by' => $actorId,
            ],
            'version' => [
                'state' => 'draft',
                'valid_from' => $validFrom->format('Y-m-d H:i:s.u'),
                'valid_to' => $validTo?->format('Y-m-d H:i:s.u'),
                'timezone' => $schedule->timezone(),
                'rounding_rule' => $this->nullableString($versionInput['rounding_rule'] ?? null, 80, 'SCHEDULE_ROUNDING_RULE_INVALID'),
                'season_start_mmdd' => $schedule->toArray()['season_start_mmdd'],
                'season_end_mmdd' => $schedule->toArray()['season_end_mmdd'],
                'supersedes_id' => isset($versionInput['supersedes_id']) ? (int) $versionInput['supersedes_id'] : null,
            ],
            'days' => $days,
            'scopes' => $scopes,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeScopes(array $scopes, int $actorId, DateTimeZone $timezone): array
    {
        $normalized = [];
        $identities = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                throw new InvalidArgumentException('SCHEDULE_SCOPE_INVALID');
            }
            $type = trim((string) ($scope['scope_type'] ?? ''));
            if (!in_array($type, self::SCOPE_TYPES, true)) {
                throw new InvalidArgumentException('SCHEDULE_SCOPE_TYPE_INVALID');
            }
            $scopeId = (int) ($scope['scope_id'] ?? 0);
            if (($type === 'global' && $scopeId !== 0) || ($type !== 'global' && $scopeId <= 0)) {
                throw new InvalidArgumentException('SCHEDULE_SCOPE_ID_INVALID');
            }
            $priority = filter_var($scope['priority'] ?? 0, FILTER_VALIDATE_INT);
            if ($priority === false || $priority < 0 || $priority > 65535) {
                throw new InvalidArgumentException('SCHEDULE_SCOPE_PRIORITY_INVALID');
            }
            $validFrom = $this->normalizeInstant($scope['valid_from'] ?? null, 'SCHEDULE_SCOPE_FROM_INVALID', $timezone);
            $validTo = isset($scope['valid_to']) && trim((string) $scope['valid_to']) !== ''
                ? $this->normalizeInstant($scope['valid_to'], 'SCHEDULE_SCOPE_TO_INVALID', $timezone)
                : null;
            if ($validTo !== null && $validTo <= $validFrom) {
                throw new InvalidArgumentException('SCHEDULE_SCOPE_RANGE_INVALID');
            }
            $identity = implode(':', [$type, $scopeId, $priority, $validFrom->format(DateTimeImmutable::ATOM)]);
            if (isset($identities[$identity])) {
                throw new DomainException('SCHEDULE_SCOPE_TIE');
            }
            $identities[$identity] = true;
            $normalized[] = [
                'scope_type' => $type,
                'scope_id' => $scopeId,
                'priority' => $priority,
                'valid_from' => $validFrom->format('Y-m-d H:i:s.u'),
                'valid_to' => $validTo?->format('Y-m-d H:i:s.u'),
                'status' => 'active',
                'created_by' => $actorId,
            ];
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function normalizeCalendarException(array $payload, int $actorId, string $idempotencyKey): array
    {
        $date = trim((string) ($payload['calendar_date'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_DATE_INVALID');
        }
        $scopeType = trim((string) ($payload['scope_type'] ?? ''));
        $scopeId = (int) ($payload['scope_id'] ?? 0);
        if (!in_array($scopeType, self::SCOPE_TYPES, true)
            || ($scopeType === 'global' && $scopeId !== 0)
            || ($scopeType !== 'global' && $scopeId <= 0)) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_SCOPE_INVALID');
        }
        $priority = filter_var($payload['priority'] ?? 0, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_PRIORITY_INVALID');
        }
        $type = trim((string) ($payload['exception_type'] ?? ''));
        if (!in_array($type, self::EXCEPTION_TYPES, true)) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_TYPE_INVALID');
        }
        $status = trim((string) ($payload['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'active', 'retired'], true)) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_STATUS_INVALID');
        }
        $scheduleVersionId = isset($payload['schedule_policy_version_id'])
            && (int) $payload['schedule_policy_version_id'] > 0
            ? (int) $payload['schedule_policy_version_id']
            : null;
        $override = $payload['override_json'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            $override = json_decode($override, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($override !== null && !is_array($override)) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_OVERRIDE_INVALID');
        }
        if (!in_array($type, ['holiday', 'closure'], true) && $scheduleVersionId === null && $override === null) {
            throw new DomainException('CALENDAR_EXCEPTION_OVERRIDE_REQUIRED');
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('CALENDAR_EXCEPTION_REASON_INVALID');
        }

        return [
            'calendar_date' => $date,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'priority' => $priority,
            'exception_type' => $type,
            'schedule_policy_version_id' => $scheduleVersionId,
            'override_json' => $override,
            'reason' => $reason,
            'status' => $status,
            'supersedes_id' => isset($payload['supersedes_id']) ? (int) $payload['supersedes_id'] : null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actorId,
        ];
    }

    /** @param array<string,mixed>|null $referencedVersion */
    private function validateCalendarOverride(array $exception, ?array $referencedVersion): void
    {
        $override = $exception['override_json'] ?? null;
        if ($override === null) {
            return;
        }
        try {
            if (isset($override['days'])) {
                WorkSchedule::fromArray($override);
                return;
            }
            $date = new DateTimeImmutable((string) $exception['calendar_date']);
            if ($referencedVersion !== null && is_array($referencedVersion['schedule'] ?? null)) {
                WorkSchedule::fromArray($referencedVersion['schedule'])->withDayOverride($date, $override);
                return;
            }
            WorkSchedule::fromArray([
                'timezone' => 'Africa/Cairo',
                'days' => [array_merge([
                    'weekday' => (int) $date->format('N'),
                    'is_working_day' => true,
                ], $override)],
            ]);
        } catch (\Throwable $exception) {
            throw new DomainException('CALENDAR_OVERRIDE_INVALID', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function versionReceipt(array $version): array
    {
        return [
            'policy_id' => (int) ($version['policy_id'] ?? 0),
            'version_id' => (int) ($version['version_id'] ?? $version['id'] ?? 0),
            'version_no' => (int) ($version['version_no'] ?? 0),
            'state' => (string) ($version['state'] ?? 'draft'),
            'lock_version' => (int) ($version['lock_version'] ?? 1),
            'published_by' => isset($version['published_by']) ? (int) $version['published_by'] : null,
            'published_at' => $version['published_at'] ?? null,
        ];
    }

    private function normalizeInstant(mixed $value, string $error, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($error);
        }
        try {
            $instant = new DateTimeImmutable($text, $timezone);
            return $timezone === null ? $instant : $instant->setTimezone($timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($error, 0, $exception);
        }
    }

    private function nullableString(mixed $value, int $maxLength, string $error): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function assertActor(int $actorId): void
    {
        $this->assertPositiveId($actorId, 'SCHEDULE_ACTOR_INVALID');
    }

    private function assertPositiveId(int $value, string $error): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($error);
        }
    }

    /** @return array<string,mixed>|null */
    private function replayReceipt(string $commandType, string $idempotencyKey, string $payloadHash): ?array
    {
        $receipt = $this->repository->findCommandReceipt($idempotencyKey);
        if ($receipt === null) {
            return null;
        }
        if (($receipt['command_type'] ?? '') !== $commandType
            || !hash_equals((string) ($receipt['payload_hash'] ?? ''), $payloadHash)) {
            throw new DomainException('IDEMPOTENCY_CONFLICT');
        }
        $result = $receipt['result_json'] ?? null;
        if (is_string($result)) {
            $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($result)) {
            throw new RuntimeException('SCHEDULE_COMMAND_RECEIPT_INVALID');
        }

        return $result;
    }

    private function recordReceipt(
        string $commandType,
        string $resourceType,
        int $resourceId,
        string $idempotencyKey,
        string $payloadHash,
        array $result,
        int $actorId
    ): void {
        $this->repository->recordCommandReceipt([
            'command_type' => $commandType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'result_json' => $result,
            'actor_user_id' => $actorId,
        ]);
    }

    private function payloadHash(array $payload): string
    {
        $canonical = $this->canonicalize($payload);
        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        $isList = $keys === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 190) {
            throw new InvalidArgumentException('SCHEDULE_IDEMPOTENCY_KEY_INVALID');
        }

        return $key;
    }
}
