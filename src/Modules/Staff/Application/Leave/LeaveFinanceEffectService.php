<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;
use EduCore\Modules\Staff\Domain\Leave\LeaveUnits;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Queues and dispatches immutable leave facts to Finance.
 *
 * Staff never calculates money or writes Finance-owned payroll tables. An
 * approved policy snapshot supplies a stable fact code and exact leave units;
 * Finance interprets that fact under its own payroll-period, maker-checker,
 * and reversal rules. The Staff outbox is deliberately durable before a
 * gateway call, so a process crash is retried with the same effect key.
 */
final class LeaveFinanceEffectService implements LeaveFinanceEffectQueue
{
    private const RESOURCE_TYPE = 'leave_request';
    private const TARGET_MODULE = 'finance';
    private const OUTBOX_SCHEMA_VERSION = 1;
    private const LEASE_SECONDS = 300;
    private const RETRY_DELAY_SECONDS = 60;
    private const MAX_DISPATCH_BATCH_SIZE = 200;

    public function __construct(
        private LeaveFinanceEffectRepository $repository,
        private ?PayrollImpactGateway $finance,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * Store one immutable Finance fact per affected payroll month.
     *
     * The method is safe to call from a final-approval transaction: it only
     * writes the Staff outbox and audit evidence. A worker must call
     * dispatchEffect() after that transaction commits.
     *
     * @return array{status:string,request_id:int,effect_ids:list<int>,replayed_effect_ids:list<int>}
     */
    public function queueForApprovedRequest(
        int $requestId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($requestId <= 0) {
            throw new InvalidArgumentException('LEAVE_FINANCE_REQUEST_ID_INVALID');
        }
        if ($actorId <= 0) {
            throw new InvalidArgumentException('LEAVE_FINANCE_ACTOR_ID_INVALID');
        }
        $occurredAt ??= $this->now();

        return $this->repository->transactional(function () use ($requestId, $actorId, $occurredAt): array {
            $request = $this->repository->requestForUpdate($requestId);
            if ($request === null) {
                throw new DomainException('LEAVE_FINANCE_REQUEST_NOT_FOUND');
            }
            $facts = $this->factsForApprovedRequest($request, $this->repository->requestDaysForUpdate($requestId));
            if ($facts === []) {
                return [
                    'status' => 'not_applicable',
                    'request_id' => $requestId,
                    'effect_ids' => [],
                    'replayed_effect_ids' => [],
                ];
            }

            $effectIds = [];
            $replayedEffectIds = [];
            foreach ($facts as $fact) {
                $existing = $this->repository->effectByIdentityForUpdate(
                    (string) $fact['effect_key'],
                    (string) $fact['idempotency_key']
                );
                if ($existing !== null) {
                    $this->assertExistingEffectMatches($existing, $fact);
                    $replayedEffectIds[] = $this->positiveInt($existing['id'] ?? null, 'LEAVE_FINANCE_EFFECT_ID_INVALID');
                    continue;
                }

                $effectId = $this->repository->insertEffect($fact);
                if ($effectId <= 0) {
                    throw new DomainException('LEAVE_FINANCE_EFFECT_PERSIST_FAILED');
                }
                $effectIds[] = $effectId;
                $this->audit->recordEvent(
                    'staff_leave_finance_effect_queued',
                    'staff_external_effects',
                    $effectId,
                    null,
                    [
                        'effect_key' => $fact['effect_key'],
                        'resource_type' => self::RESOURCE_TYPE,
                        'resource_id' => $requestId,
                        'target_module' => self::TARGET_MODULE,
                        'fact_type' => $fact['fact_type'],
                        'units' => $fact['units'],
                        'effective_period' => $fact['effective_period'],
                        'direction' => $fact['direction'],
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($occurredAt)]
                );
            }

            return [
                'status' => $effectIds === [] ? 'idempotent_replay' : 'queued',
                'request_id' => $requestId,
                'effect_ids' => $effectIds,
                'replayed_effect_ids' => $replayedEffectIds,
            ];
        });
    }

    /**
     * Claims one outbox fact, sends it through the Finance contract, and
     * records only a safe outcome. Gateway exceptions and rejections become
     * retryable Staff outbox states; no raw Finance error is stored or shown.
     *
     * @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string}
     */
    public function dispatchEffect(
        int $effectId,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($effectId <= 0) {
            throw new InvalidArgumentException('LEAVE_FINANCE_EFFECT_ID_INVALID');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvalidArgumentException('LEAVE_FINANCE_ACTOR_ID_INVALID');
        }
        $occurredAt ??= $this->now();
        $claimed = $this->claimForDispatch($effectId, $actorId, $occurredAt);
        if (($claimed['claimed'] ?? false) !== true) {
            return [
                'effect_id' => $effectId,
                'accepted' => (string) ($claimed['status'] ?? '') === 'accepted',
                'status' => (string) ($claimed['status'] ?? 'unavailable'),
                'finance_reference' => $this->nullableSafeText($claimed['result_ref'] ?? null, 255),
            ];
        }

        $effect = $claimed['effect'];
        if ($this->finance === null) {
            return $this->markRetry($effectId, $actorId, $occurredAt, 'FINANCE_GATEWAY_UNAVAILABLE');
        }
        try {
            $payload = $this->gatewayPayload($effect);
            $result = $this->finance->submitFacts(
                (string) $effect['effect_key'],
                $payload['staff_user_id'],
                (string) $effect['fact_type'],
                (string) $effect['units'],
                (string) $effect['effective_period'],
                $payload['source_ref'],
                $payload['metadata']
            );
        } catch (Throwable) {
            return $this->markRetry($effectId, $actorId, $occurredAt, 'FINANCE_GATEWAY_UNAVAILABLE');
        }

        if (!is_array($result) || !array_key_exists('accepted', $result) || !is_bool($result['accepted'])) {
            return $this->markRetry($effectId, $actorId, $occurredAt, 'FINANCE_GATEWAY_RESPONSE_INVALID');
        }
        if ($result['accepted'] !== true) {
            return $this->markRetry($effectId, $actorId, $occurredAt, 'FINANCE_GATEWAY_REJECTED');
        }

        return $this->repository->transactional(function () use ($effectId, $actorId, $occurredAt, $result): array {
            $current = $this->repository->effectForUpdate($effectId);
            if ($current === null) {
                throw new DomainException('LEAVE_FINANCE_EFFECT_NOT_FOUND');
            }
            if ((string) ($current['status'] ?? '') === 'accepted') {
                return $this->dispatchReceipt($current, true);
            }

            $reference = $this->nullableSafeText($result['finance_reference'] ?? null, 255);
            if (!$this->repository->markEffectAccepted(
                $effectId,
                $reference,
                $this->databaseInstant($occurredAt)
            )) {
                $after = $this->repository->effectForUpdate($effectId);
                if ($after === null) {
                    throw new DomainException('LEAVE_FINANCE_EFFECT_NOT_FOUND');
                }

                return $this->dispatchReceipt($after, (string) ($after['status'] ?? '') === 'accepted');
            }

            $this->audit->recordEvent(
                'staff_leave_finance_effect_accepted',
                'staff_external_effects',
                $effectId,
                null,
                [
                    'effect_key' => (string) $current['effect_key'],
                    'target_module' => self::TARGET_MODULE,
                    'fact_type' => (string) $current['fact_type'],
                    'units' => (string) $current['units'],
                    'effective_period' => (string) $current['effective_period'],
                    'finance_reference_present' => $reference !== null,
                ],
                $this->auditContext($actorId, $occurredAt)
            );

            $accepted = array_replace($current, [
                'status' => 'accepted',
                'result_ref' => $reference,
            ]);

            return $this->dispatchReceipt($accepted, true);
        });
    }

    /**
     * Selects the next due leave facts and delegates their conditional claim
     * one by one. The read is intentionally not the claim: another worker may
     * win between them, and then dispatchEffect() returns a safe skipped
     * receipt without calling Finance. Finance still receives the deterministic
     * effect key, so recovery after an expired lease remains idempotent there.
     *
     * @return array{
     *     selected_effect_ids:list<int>,
     *     accepted_count:int,
     *     retry_count:int,
     *     skipped_count:int,
     *     receipts:list<array{effect_id:int,accepted:bool,status:string,finance_reference:?string}>
     * }
     */
    public function dispatchDueEffects(
        int $limit = 50,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($limit <= 0 || $limit > self::MAX_DISPATCH_BATCH_SIZE) {
            throw new InvalidArgumentException('LEAVE_FINANCE_DISPATCH_LIMIT_INVALID');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvalidArgumentException('LEAVE_FINANCE_ACTOR_ID_INVALID');
        }

        $occurredAt ??= $this->now();
        $effectIds = $this->repository->dueEffectIdsForDispatch(
            $limit,
            $this->databaseInstant($occurredAt)
        );
        $receipts = [];
        $acceptedCount = 0;
        $retryCount = 0;
        $skippedCount = 0;

        foreach ($effectIds as $effectId) {
            $effectId = (int) $effectId;
            if ($effectId <= 0) {
                throw new DomainException('LEAVE_FINANCE_EFFECT_ID_INVALID');
            }
            $receipt = $this->dispatchEffect($effectId, $actorId, $occurredAt);
            $receipts[] = $receipt;

            if ($receipt['accepted']) {
                ++$acceptedCount;
            } elseif ($receipt['status'] === 'retry') {
                ++$retryCount;
            } else {
                ++$skippedCount;
            }
        }

        return [
            'selected_effect_ids' => array_values(array_map('intval', $effectIds)),
            'accepted_count' => $acceptedCount,
            'retry_count' => $retryCount,
            'skipped_count' => $skippedCount,
            'receipts' => $receipts,
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @param list<array<string,mixed>> $days
     * @return list<array<string,mixed>>
     */
    private function factsForApprovedRequest(array $request, array $days): array
    {
        if ((string) ($request['status'] ?? '') !== 'approved') {
            throw new DomainException('LEAVE_FINANCE_REQUEST_NOT_APPROVED');
        }
        $requestId = $this->positiveInt($request['id'] ?? null, 'LEAVE_FINANCE_REQUEST_ID_INVALID');
        $staffUserId = $this->positiveInt($request['staff_user_id'] ?? null, 'LEAVE_FINANCE_STAFF_ID_INVALID');
        $leaveTypeId = $this->positiveInt($request['leave_type_id'] ?? null, 'LEAVE_FINANCE_LEAVE_TYPE_INVALID');
        $policyVersionId = $this->positiveInt(
            $request['policy_version_id'] ?? null,
            'LEAVE_FINANCE_POLICY_VERSION_INVALID'
        );
        $requestHash = trim((string) ($request['request_hash'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1) {
            throw new DomainException('LEAVE_FINANCE_REQUEST_HASH_INVALID');
        }

        $snapshot = $this->policySnapshot($request['policy_snapshot'] ?? null);
        $factType = $this->nullableFactType($snapshot['payroll_effect_code'] ?? null);
        if ($factType === null) {
            return [];
        }

        $leaveType = $snapshot['leave_type'] ?? null;
        if (!is_array($leaveType)
            || (int) ($leaveType['id'] ?? 0) !== $leaveTypeId
            || !in_array((string) ($leaveType['unit'] ?? ''), ['day', 'hour'], true)) {
            throw new DomainException('LEAVE_FINANCE_POLICY_SNAPSHOT_INVALID');
        }
        $leaveTypeCode = $this->requiredFactType(
            $leaveType['code'] ?? null,
            'LEAVE_FINANCE_POLICY_SNAPSHOT_INVALID'
        );
        $kind = (string) ($request['request_kind'] ?? '');
        [$direction, $sign] = match ($kind) {
            'leave', 'extension' => ['apply', 1],
            'early_return', 'cancellation' => ['reverse', -1],
            default => throw new DomainException('LEAVE_FINANCE_REQUEST_KIND_INVALID'),
        };

        $expectedUnits = LeaveUnits::fromDecimal(
            $request['requested_units'] ?? null,
            false,
            'LEAVE_FINANCE_REQUEST_UNITS_INVALID'
        );
        $expectedMinutes = $this->positiveInt(
            $request['requested_minutes'] ?? null,
            'LEAVE_FINANCE_REQUEST_MINUTES_INVALID'
        );
        if ($days === []) {
            throw new DomainException('LEAVE_FINANCE_REQUEST_DAYS_EMPTY');
        }

        /** @var array<string,array{units_milli:int,minutes:int,dates:list<string>}> $periods */
        $periods = [];
        $totalUnits = 0;
        $totalMinutes = 0;
        foreach ($days as $day) {
            $kindOfDay = (string) ($day['day_kind'] ?? '');
            if (!in_array($kindOfDay, ['workday', 'partial', 'non_working'], true)) {
                throw new DomainException('LEAVE_FINANCE_REQUEST_DAY_INVALID');
            }
            $units = LeaveUnits::fromDecimal(
                $day['requested_units'] ?? null,
                false,
                'LEAVE_FINANCE_REQUEST_DAY_UNITS_INVALID'
            );
            $minutes = $this->nonNegativeInt(
                $day['requested_minutes'] ?? null,
                'LEAVE_FINANCE_REQUEST_DAY_MINUTES_INVALID'
            );
            if ($kindOfDay === 'non_working') {
                if ($units !== 0 || $minutes !== 0) {
                    throw new DomainException('LEAVE_FINANCE_NON_WORKING_DAY_INVALID');
                }
                continue;
            }
            if ($units <= 0 || $minutes <= 0) {
                throw new DomainException('LEAVE_FINANCE_REQUEST_DAY_INVALID');
            }
            $date = $this->dateKey($day['work_date'] ?? null, 'LEAVE_FINANCE_REQUEST_DAY_DATE_INVALID');
            $period = substr($date, 0, 7);
            if (!isset($periods[$period])) {
                $periods[$period] = ['units_milli' => 0, 'minutes' => 0, 'dates' => []];
            }
            $periods[$period]['units_milli'] += $units;
            $periods[$period]['minutes'] += $minutes;
            $periods[$period]['dates'][] = $date;
            $totalUnits += $units;
            $totalMinutes += $minutes;
        }
        if ($periods === [] || $totalUnits !== $expectedUnits || $totalMinutes !== $expectedMinutes) {
            throw new DomainException('LEAVE_FINANCE_REQUEST_ALLOCATION_MISMATCH');
        }
        ksort($periods, SORT_STRING);

        $facts = [];
        foreach ($periods as $period => $allocation) {
            $units = LeaveUnits::format($sign * $allocation['units_milli']);
            $effectKey = 'staff-leave-finance:v1:' . $requestId . ':' . $period . ':' . $direction . ':'
                . substr(hash('sha256', $requestHash . '|' . $factType . '|' . $units), 0, 24);
            $idempotencyKey = 'staff-leave-finance:'
                . hash('sha256', $effectKey . '|' . $requestHash);
            $payload = [
                'schema_version' => self::OUTBOX_SCHEMA_VERSION,
                'source_ref' => 'staff_leave_request:' . $requestId,
                'staff_user_id' => $staffUserId,
                'metadata' => [
                    'direction' => $direction,
                    'leave_request_id' => $requestId,
                    'leave_type_code' => $leaveTypeCode,
                    'leave_unit' => (string) $leaveType['unit'],
                    'policy_version_id' => $policyVersionId,
                    'request_kind' => $kind,
                    'request_hash' => $requestHash,
                    'requested_minutes' => $sign * $allocation['minutes'],
                    'work_date_from' => min($allocation['dates']),
                    'work_date_to' => max($allocation['dates']),
                ],
            ];
            $facts[] = [
                'effect_key' => $effectKey,
                'idempotency_key' => $idempotencyKey,
                'resource_type' => self::RESOURCE_TYPE,
                'resource_id' => $requestId,
                'target_module' => self::TARGET_MODULE,
                'fact_type' => $factType,
                'units' => $units,
                'effective_period' => $period,
                'payload' => $this->canonicalJson($payload),
                'status' => 'pending',
                'direction' => $direction,
            ];
        }

        return $facts;
    }

    /** @return array{claimed:bool,status:string,result_ref:?string,effect?:array<string,mixed>} */
    private function claimForDispatch(int $effectId, ?int $actorId, DateTimeImmutable $occurredAt): array
    {
        return $this->repository->transactional(function () use ($effectId, $actorId, $occurredAt): array {
            $effect = $this->repository->effectForUpdate($effectId);
            if ($effect === null) {
                throw new DomainException('LEAVE_FINANCE_EFFECT_NOT_FOUND');
            }
            $status = (string) ($effect['status'] ?? '');
            if ($status === 'accepted') {
                return ['claimed' => false, 'status' => 'accepted', 'result_ref' => $effect['result_ref'] ?? null];
            }
            if (in_array($status, ['failed', 'reversed', 'cancelled'], true)) {
                return ['claimed' => false, 'status' => $status, 'result_ref' => $effect['result_ref'] ?? null];
            }

            $claimedAt = $this->databaseInstant($occurredAt);
            $leaseUntil = $this->databaseInstant($occurredAt->modify('+' . self::LEASE_SECONDS . ' seconds'));
            if (!$this->repository->claimEffect($effectId, $claimedAt, $leaseUntil)) {
                $after = $this->repository->effectForUpdate($effectId);
                if ($after === null) {
                    throw new DomainException('LEAVE_FINANCE_EFFECT_NOT_FOUND');
                }

                return [
                    'claimed' => false,
                    'status' => (string) ($after['status'] ?? 'unavailable'),
                    'result_ref' => $after['result_ref'] ?? null,
                ];
            }

            $this->audit->recordEvent(
                'staff_leave_finance_effect_dispatch_claimed',
                'staff_external_effects',
                $effectId,
                null,
                [
                    'effect_key' => (string) $effect['effect_key'],
                    'target_module' => self::TARGET_MODULE,
                    'attempt' => (int) ($effect['attempts'] ?? 0) + 1,
                    'lease_until' => $leaseUntil,
                ],
                $this->auditContext($actorId, $occurredAt)
            );

            return [
                'claimed' => true,
                'status' => 'processing',
                'result_ref' => null,
                'effect' => array_replace($effect, ['status' => 'processing']),
            ];
        });
    }

    /** @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string} */
    private function markRetry(
        int $effectId,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
        string $reasonCode
    ): array {
        return $this->repository->transactional(function () use ($effectId, $actorId, $occurredAt, $reasonCode): array {
            $current = $this->repository->effectForUpdate($effectId);
            if ($current === null) {
                throw new DomainException('LEAVE_FINANCE_EFFECT_NOT_FOUND');
            }
            if ((string) ($current['status'] ?? '') === 'accepted') {
                return $this->dispatchReceipt($current, true);
            }
            $nextAttempt = $this->databaseInstant($occurredAt->modify('+' . self::RETRY_DELAY_SECONDS . ' seconds'));
            if (!$this->repository->markEffectForRetry($effectId, $reasonCode, $nextAttempt)) {
                $after = $this->repository->effectForUpdate($effectId);
                if ($after === null) {
                    throw new DomainException('LEAVE_FINANCE_EFFECT_NOT_FOUND');
                }

                return $this->dispatchReceipt($after, (string) ($after['status'] ?? '') === 'accepted');
            }

            $this->audit->recordEvent(
                'staff_leave_finance_effect_retry_scheduled',
                'staff_external_effects',
                $effectId,
                null,
                [
                    'effect_key' => (string) $current['effect_key'],
                    'target_module' => self::TARGET_MODULE,
                    'reason_code' => $reasonCode,
                    'next_attempt_at' => $nextAttempt,
                ],
                $this->auditContext($actorId, $occurredAt)
            );

            return [
                'effect_id' => $effectId,
                'accepted' => false,
                'status' => 'retry',
                'finance_reference' => null,
            ];
        });
    }

    /** @param array<string,mixed> $effect @return array{staff_user_id:int,source_ref:string,metadata:array<string,mixed>} */
    private function gatewayPayload(array $effect): array
    {
        if ((string) ($effect['resource_type'] ?? '') !== self::RESOURCE_TYPE
            || (string) ($effect['target_module'] ?? '') !== self::TARGET_MODULE) {
            throw new DomainException('LEAVE_FINANCE_EFFECT_PAYLOAD_INVALID');
        }
        $payload = $this->decodeJsonObject($effect['payload'] ?? null, 'LEAVE_FINANCE_EFFECT_PAYLOAD_INVALID');
        $staffUserId = $this->positiveInt($payload['staff_user_id'] ?? null, 'LEAVE_FINANCE_EFFECT_PAYLOAD_INVALID');
        $sourceRef = $this->requiredSafeText($payload['source_ref'] ?? null, 190, 'LEAVE_FINANCE_EFFECT_PAYLOAD_INVALID');
        $metadata = $payload['metadata'] ?? null;
        if (!is_array($metadata)
            || array_key_exists('reason', $metadata)
            || array_key_exists('supporting_document_ref', $metadata)
            || array_key_exists('medical_document_ref', $metadata)
            || array_key_exists('amount', $metadata)) {
            throw new DomainException('LEAVE_FINANCE_EFFECT_PAYLOAD_INVALID');
        }

        return [
            'staff_user_id' => $staffUserId,
            'source_ref' => $sourceRef,
            'metadata' => $this->normalizeJsonValue($metadata),
        ];
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $expected */
    private function assertExistingEffectMatches(array $existing, array $expected): void
    {
        if (
            (string) ($existing['effect_key'] ?? '') !== (string) $expected['effect_key']
            || (string) ($existing['idempotency_key'] ?? '') !== (string) $expected['idempotency_key']
            || (string) ($existing['resource_type'] ?? '') !== self::RESOURCE_TYPE
            || (int) ($existing['resource_id'] ?? 0) !== (int) $expected['resource_id']
            || (string) ($existing['target_module'] ?? '') !== self::TARGET_MODULE
            || (string) ($existing['fact_type'] ?? '') !== (string) $expected['fact_type']
            || (string) ($existing['units'] ?? '') !== (string) $expected['units']
            || (string) ($existing['effective_period'] ?? '') !== (string) $expected['effective_period']
        ) {
            throw new DomainException('LEAVE_FINANCE_EFFECT_IDEMPOTENCY_CONFLICT');
        }
        $actualPayload = $this->decodeJsonObject($existing['payload'] ?? null, 'LEAVE_FINANCE_EFFECT_IDEMPOTENCY_CONFLICT');
        $expectedPayload = $this->decodeJsonObject($expected['payload'] ?? null, 'LEAVE_FINANCE_EFFECT_IDEMPOTENCY_CONFLICT');
        if ($this->canonicalJson($actualPayload) !== $this->canonicalJson($expectedPayload)) {
            throw new DomainException('LEAVE_FINANCE_EFFECT_IDEMPOTENCY_CONFLICT');
        }
    }

    /** @param array<string,mixed> $effect @return array{effect_id:int,accepted:bool,status:string,finance_reference:?string} */
    private function dispatchReceipt(array $effect, bool $accepted): array
    {
        return [
            'effect_id' => $this->positiveInt($effect['id'] ?? null, 'LEAVE_FINANCE_EFFECT_ID_INVALID'),
            'accepted' => $accepted,
            'status' => (string) ($effect['status'] ?? 'unavailable'),
            'finance_reference' => $this->nullableSafeText($effect['result_ref'] ?? null, 255),
        ];
    }

    /** @return array<string,mixed> */
    private function policySnapshot(mixed $snapshot): array
    {
        return $this->decodeJsonObject($snapshot, 'LEAVE_FINANCE_POLICY_SNAPSHOT_INVALID');
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value, string $error): array
    {
        if (is_array($value)) {
            return $this->normalizeJsonValue($value);
        }
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException($error);
        }
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException($error, 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new DomainException($error);
        }

        return $this->normalizeJsonValue($decoded);
    }

    private function nullableFactType(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->requiredFactType($value, 'LEAVE_FINANCE_FACT_TYPE_INVALID');
    }

    private function requiredFactType(mixed $value, string $error): string
    {
        $text = trim((string) $value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/', $text) !== 1) {
            throw new DomainException($error);
        }

        return $text;
    }

    private function requiredSafeText(mixed $value, int $maxLength, string $error): string
    {
        $text = $this->nullableSafeText($value, $maxLength);
        if ($text === null) {
            throw new DomainException($error);
        }

        return $text;
    }

    private function nullableSafeText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1) {
            return null;
        }

        return $text;
    }

    private function positiveInt(mixed $value, string $error): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new DomainException($error);
        }
        if ($number <= 0) {
            throw new DomainException($error);
        }

        return $number;
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new DomainException($error);
        }
        if ($number < 0) {
            throw new DomainException($error);
        }

        return $number;
    }

    private function dateKey(mixed $value, string $error): string
    {
        $date = trim((string) $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException($error);
        }

        return $date;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->normalizeJsonValue($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new DomainException('LEAVE_FINANCE_JSON_INVALID', 0, $exception);
        }
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeJsonValue($item), $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeJsonValue($item);
            }

            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            throw new DomainException('LEAVE_FINANCE_JSON_INVALID');
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new DomainException('LEAVE_FINANCE_JSON_INVALID');
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function auditContext(?int $actorId, DateTimeImmutable $occurredAt): array
    {
        $context = ['occurred_at' => $this->databaseInstant($occurredAt)];
        if ($actorId !== null) {
            $context['user_id'] = $actorId;
        } else {
            $context['actor_scope'] = 'system';
        }

        return $context;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
