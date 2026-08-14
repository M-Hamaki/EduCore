<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Organization;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffOrganizationCorrectionImpactGateway;
use EduCore\Modules\Staff\Contracts\StaffOrganizationCorrectionRepository;
use InvalidArgumentException;
use Throwable;

/** Immutable preview, two-person decision, scoped-impact, and reversal workflow. */
final class StaffOrganizationCorrectionService
{
    private const KINDS = ['organization_unit', 'job_title', 'manager', 'calendar'];
    private const SCOPES = ['staff', 'org_unit', 'policy_group', 'global'];
    private const MAX_IMPACT_ITEMS = 5000;

    public function __construct(
        private StaffOrganizationCorrectionRepository $repository,
        private StaffOrganizationCorrectionImpactGateway $impacts,
        private AuditEventWriter $audit,
        private ?DateTimeZone $timezone = null
    ) {
        $this->timezone ??= new DateTimeZone('Africa/Cairo');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function previewCorrection(array $input, int $actorId): array
    {
        $candidate = $this->normalizeCandidate($input, $actorId);

        return $this->repository->transactional(function () use ($candidate): array {
            $this->assertRequester($candidate['requested_by']);
            $existing = $this->repository->correctionByIdempotencyForUpdate($candidate['idempotency_key']);
            if ($existing !== null) {
                $this->assertSameHash($existing, $candidate['payload_hash'], 'STAFF_ORG_CORRECTION_IDEMPOTENCY_CONFLICT');
                return $this->correctionReceipt($existing, true);
            }

            $impact = $this->normalizeImpact($this->impacts->previewImpact($candidate, self::MAX_IMPACT_ITEMS));
            $candidate['impact_snapshot_json'] = $this->json($impact);
            $candidate['impact_snapshot_hash'] = hash('sha256', $candidate['impact_snapshot_json']);
            $correctionId = $this->repository->insertCorrection($candidate);
            if ($correctionId <= 0) {
                throw new DomainException('STAFF_ORG_CORRECTION_PERSIST_FAILED');
            }

            $this->audit->recordEvent(
                'staff_organization_correction_previewed',
                'staff_organization_corrections',
                $correctionId,
                null,
                $this->auditDetails($candidate),
                ['user_id' => $candidate['requested_by']]
            );

            return $this->correctionReceipt(['id' => $correctionId, 'lock_version' => 1] + $candidate, false);
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function previewReversal(array $input, int $actorId): array
    {
        $originalId = $this->positiveId($input['correction_id'] ?? null, 'STAFF_ORG_CORRECTION_ID_INVALID');
        $reason = $this->reason($input['reason'] ?? null);
        $idempotencyKey = $this->hashKey($input['idempotency_key'] ?? null, 'STAFF_ORG_CORRECTION_IDEMPOTENCY_INVALID');
        $actorId = $this->positiveId($actorId, 'STAFF_ORG_CORRECTION_ACTOR_INVALID');

        return $this->repository->transactional(function () use ($originalId, $reason, $idempotencyKey, $actorId): array {
            $this->assertRequester($actorId);
            $original = $this->repository->correctionByIdForUpdate($originalId);
            $decision = $this->repository->finalDecisionForCorrectionForUpdate($originalId);
            if ($original === null || ($decision['decision'] ?? null) !== 'approved') {
                throw new DomainException('STAFF_ORG_CORRECTION_REVERSAL_NOT_AVAILABLE');
            }
            if (($original['reverses_correction_id'] ?? null) !== null) {
                throw new DomainException('STAFF_ORG_CORRECTION_REVERSAL_CHAIN_INVALID');
            }

            $candidate = [
                'correction_kind' => (string) $original['correction_kind'],
                'scope_type' => (string) $original['scope_type'],
                'scope_id' => $original['scope_id'] === null ? null : (int) $original['scope_id'],
                'effective_from' => (string) $original['effective_from'],
                'effective_to' => (string) $original['effective_to'],
                'proposed_reference_id' => (int) $original['proposed_reference_id'],
                'reason_text' => $reason,
                'reason_hash' => hash('sha256', $reason),
                'reverses_correction_id' => $originalId,
                'direction' => 'reverse',
                'requested_by' => $actorId,
                'idempotency_key' => $idempotencyKey,
            ];
            $candidate['payload_hash'] = $this->payloadHash($candidate);
            $existing = $this->repository->correctionByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                $this->assertSameHash($existing, $candidate['payload_hash'], 'STAFF_ORG_CORRECTION_IDEMPOTENCY_CONFLICT');
                return $this->correctionReceipt($existing, true);
            }
            $candidate['impact_snapshot_json'] = (string) $original['impact_snapshot_json'];
            $candidate['impact_snapshot_hash'] = (string) $original['impact_snapshot_hash'];
            $correctionId = $this->repository->insertCorrection($candidate);
            if ($correctionId <= 0) {
                throw new DomainException('STAFF_ORG_CORRECTION_PERSIST_FAILED');
            }

            $this->audit->recordEvent(
                'staff_organization_correction_reversal_previewed',
                'staff_organization_corrections',
                $correctionId,
                null,
                $this->auditDetails($candidate),
                ['user_id' => $actorId]
            );

            return $this->correctionReceipt(['id' => $correctionId, 'lock_version' => 1] + $candidate, false);
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function decideCorrection(array $input, int $actorId): array
    {
        $command = [
            'correction_id' => $this->positiveId($input['correction_id'] ?? null, 'STAFF_ORG_CORRECTION_ID_INVALID'),
            'expected_lock_version' => $this->positiveId($input['expected_lock_version'] ?? null, 'STAFF_ORG_CORRECTION_VERSION_INVALID'),
            'decision' => $this->choice($input['decision'] ?? null, ['approved', 'rejected'], 'STAFF_ORG_CORRECTION_DECISION_INVALID'),
            'comment_hash' => $this->commentHash($input['comment'] ?? null),
            'idempotency_key' => $this->hashKey($input['idempotency_key'] ?? null, 'STAFF_ORG_CORRECTION_DECISION_IDEMPOTENCY_INVALID'),
            'decided_by' => $this->positiveId($actorId, 'STAFF_ORG_CORRECTION_ACTOR_INVALID'),
        ];
        $command['decision_hash'] = hash('sha256', $this->json($command));

        return $this->repository->transactional(function () use ($command): array {
            $correction = $this->repository->correctionByIdForUpdate($command['correction_id']);
            if ($correction === null) {
                throw new DomainException('STAFF_ORG_CORRECTION_NOT_FOUND');
            }
            if ((int) $correction['requested_by'] === $command['decided_by']) {
                throw new DomainException('STAFF_ORG_CORRECTION_SELF_APPROVAL_FORBIDDEN');
            }
            if (!$this->repository->actorCanApproveCorrection($command['decided_by'])) {
                throw new DomainException('STAFF_ORG_CORRECTION_APPROVER_FORBIDDEN');
            }
            if ((int) ($correction['lock_version'] ?? 0) !== $command['expected_lock_version']) {
                throw new DomainException('STAFF_ORG_CORRECTION_VERSION_CONFLICT');
            }

            $replay = $this->repository->decisionByIdempotencyForUpdate($command['idempotency_key']);
            if ($replay !== null) {
                $this->assertSameHash($replay, $command['decision_hash'], 'STAFF_ORG_CORRECTION_DECISION_IDEMPOTENCY_CONFLICT', 'decision_hash');
                return $this->decisionReceipt($replay, true);
            }
            if ($this->repository->finalDecisionForCorrectionForUpdate($command['correction_id']) !== null) {
                throw new DomainException('STAFF_ORG_CORRECTION_ALREADY_DECIDED');
            }

            $decisionId = $this->repository->insertDecision($command);
            if ($decisionId <= 0) {
                throw new DomainException('STAFF_ORG_CORRECTION_DECISION_PERSIST_FAILED');
            }
            $published = ['accepted' => true, 'intent_count' => 0];
            if ($command['decision'] === 'approved') {
                try {
                    $published = $this->impacts->publishImpact([
                        'correction_id' => $command['correction_id'],
                        'decision_id' => $decisionId,
                        'direction' => (string) ($correction['direction'] ?? 'apply'),
                        'correction_kind' => (string) $correction['correction_kind'],
                        'scope_type' => (string) $correction['scope_type'],
                        'scope_id' => $correction['scope_id'] === null ? null : (int) $correction['scope_id'],
                        'proposed_reference_id' => (int) $correction['proposed_reference_id'],
                        'impact_snapshot_hash' => (string) $correction['impact_snapshot_hash'],
                        'impact' => $this->decodeImpact((string) $correction['impact_snapshot_json']),
                        'actor_id' => $command['decided_by'],
                        'idempotency_key' => $command['idempotency_key'],
                    ]);
                } catch (Throwable $exception) {
                    throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_PUBLISH_FAILED', 0, $exception);
                }
                if (($published['accepted'] ?? false) !== true) {
                    throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_PUBLISH_FAILED');
                }
            }

            $this->audit->recordEvent(
                'staff_organization_correction_' . $command['decision'],
                'staff_organization_correction_decisions',
                $decisionId,
                null,
                [
                    'correction_id' => $command['correction_id'],
                    'decision' => $command['decision'],
                    'direction' => (string) ($correction['direction'] ?? 'apply'),
                    'impact_snapshot_hash' => (string) $correction['impact_snapshot_hash'],
                    'comment_hash' => $command['comment_hash'],
                    'intent_count' => (int) ($published['intent_count'] ?? 0),
                ],
                ['user_id' => $command['decided_by']]
            );

            return $this->decisionReceipt(['id' => $decisionId] + $command, false, (int) ($published['intent_count'] ?? 0));
        });
    }

    /** @return list<array<string,mixed>> */
    public function recentForAdministrator(int $actorId, int $limit = 50): array
    {
        $actorId = $this->positiveId($actorId, 'STAFF_ORG_CORRECTION_ACTOR_INVALID');
        if (!$this->repository->actorCanRequestCorrection($actorId)) {
            throw new DomainException('STAFF_ORG_CORRECTION_REQUESTER_FORBIDDEN');
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('STAFF_ORG_CORRECTION_LIMIT_INVALID');
        }

        return array_map(fn (array $row): array => $this->correctionReceipt($row, false), $this->repository->recentCorrections($limit));
    }

    public function canApprove(int $actorId): bool
    {
        return $actorId > 0 && $this->repository->actorCanApproveCorrection($actorId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeCandidate(array $input, int $actorId): array
    {
        $kind = $this->choice($input['correction_kind'] ?? null, self::KINDS, 'STAFF_ORG_CORRECTION_KIND_INVALID');
        $scopeType = $this->choice($input['scope_type'] ?? null, self::SCOPES, 'STAFF_ORG_CORRECTION_SCOPE_INVALID');
        $scopeId = $scopeType === 'global'
            ? null
            : $this->positiveId($input['scope_id'] ?? null, 'STAFF_ORG_CORRECTION_SCOPE_INVALID');
        [$from, $to] = $this->dateRange($input['effective_from'] ?? null, $input['effective_to'] ?? null);
        $candidate = [
            'correction_kind' => $kind,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'effective_from' => $from,
            'effective_to' => $to,
            'proposed_reference_id' => $this->positiveId($input['proposed_reference_id'] ?? null, 'STAFF_ORG_CORRECTION_REFERENCE_INVALID'),
            'reason_text' => $this->reason($input['reason'] ?? null),
            'reverses_correction_id' => null,
            'direction' => 'apply',
            'requested_by' => $this->positiveId($actorId, 'STAFF_ORG_CORRECTION_ACTOR_INVALID'),
            'idempotency_key' => $this->hashKey($input['idempotency_key'] ?? null, 'STAFF_ORG_CORRECTION_IDEMPOTENCY_INVALID'),
        ];
        $candidate['reason_hash'] = hash('sha256', $candidate['reason_text']);
        $candidate['payload_hash'] = $this->payloadHash($candidate);

        return $candidate;
    }

    /** @param array<string,mixed> $impact @return array<string,mixed> */
    private function normalizeImpact(array $impact): array
    {
        $staff = $this->positiveIntegerList($impact['affected_staff_ids'] ?? [], 500, 'STAFF_ORG_CORRECTION_STAFF_IMPACT_INVALID');
        $dates = $this->dateList($impact['affected_work_dates'] ?? [], 366);
        $requests = $this->requestList($impact['affected_requests'] ?? [], 1000);
        $periods = $this->periodList($impact['affected_report_periods'] ?? [], 60);
        $warnings = $this->warningList($impact['warnings'] ?? []);
        $intentCount = count($staff) * max(1, count($dates)) + count($requests) + count($staff) * count($periods);
        if ($staff === [] || $intentCount > self::MAX_IMPACT_ITEMS) {
            throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_TOO_LARGE');
        }

        return [
            'affected_staff_ids' => $staff,
            'affected_work_dates' => $dates,
            'affected_requests' => $requests,
            'affected_report_periods' => $periods,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeImpact(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_SNAPSHOT_INVALID');
        }
        return $this->normalizeImpact($decoded);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function correctionReceipt(array $row, bool $replayed): array
    {
        $impact = $this->decodeImpact((string) ($row['impact_snapshot_json'] ?? '{}'));
        $decision = isset($row['decision']) ? (string) $row['decision'] : null;
        return [
            'correction_id' => (int) $row['id'],
            'correction_kind' => (string) $row['correction_kind'],
            'scope_type' => (string) $row['scope_type'],
            'scope_id' => $row['scope_id'] === null ? null : (int) $row['scope_id'],
            'effective_from' => (string) $row['effective_from'],
            'effective_to' => (string) $row['effective_to'],
            'proposed_reference_id' => (int) $row['proposed_reference_id'],
            'reverses_correction_id' => $row['reverses_correction_id'] === null ? null : (int) $row['reverses_correction_id'],
            'direction' => (string) ($row['direction'] ?? 'apply'),
            'requested_by' => (int) $row['requested_by'],
            'lock_version' => (int) ($row['lock_version'] ?? 1),
            'status' => $decision ?? 'previewed',
            'decided_by' => isset($row['decided_by']) ? (int) $row['decided_by'] : null,
            'impact' => $impact,
            'impact_snapshot_hash' => (string) $row['impact_snapshot_hash'],
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decisionReceipt(array $row, bool $replayed, int $intentCount = 0): array
    {
        return [
            'decision_id' => (int) $row['id'],
            'correction_id' => (int) $row['correction_id'],
            'decision' => (string) $row['decision'],
            'decided_by' => (int) $row['decided_by'],
            'intent_count' => $intentCount,
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function auditDetails(array $candidate): array
    {
        return [
            'correction_kind' => $candidate['correction_kind'],
            'scope_type' => $candidate['scope_type'],
            'scope_id' => $candidate['scope_id'],
            'effective_from' => $candidate['effective_from'],
            'effective_to' => $candidate['effective_to'],
            'proposed_reference_id' => $candidate['proposed_reference_id'],
            'reverses_correction_id' => $candidate['reverses_correction_id'],
            'direction' => $candidate['direction'],
            'reason_hash' => $candidate['reason_hash'],
            'impact_snapshot_hash' => $candidate['impact_snapshot_hash'],
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function payloadHash(array $candidate): string
    {
        $copy = $candidate;
        unset($copy['reason_text'], $copy['payload_hash'], $copy['impact_snapshot_json'], $copy['impact_snapshot_hash']);
        ksort($copy);
        return hash('sha256', $this->json($copy));
    }

    private function assertRequester(int $actorId): void
    {
        if (!$this->repository->actorCanRequestCorrection($actorId)) {
            throw new DomainException('STAFF_ORG_CORRECTION_REQUESTER_FORBIDDEN');
        }
    }

    /** @param array<string,mixed> $row */
    private function assertSameHash(array $row, string $expected, string $error, string $field = 'payload_hash'): void
    {
        if (!isset($row[$field]) || !hash_equals((string) $row[$field], $expected)) {
            throw new DomainException($error);
        }
    }

    /** @return array{0:string,1:string} */
    private function dateRange(mixed $from, mixed $to): array
    {
        $fromDate = $this->date($from, 'STAFF_ORG_CORRECTION_DATE_INVALID');
        $toDate = $this->date($to, 'STAFF_ORG_CORRECTION_DATE_INVALID');
        if ($toDate < $fromDate || (new DateTimeImmutable($fromDate, $this->timezone))->diff(new DateTimeImmutable($toDate, $this->timezone))->days > 365) {
            throw new InvalidArgumentException('STAFF_ORG_CORRECTION_DATE_RANGE_INVALID');
        }
        return [$fromDate, $toDate];
    }

    private function date(mixed $value, string $error): string
    {
        $text = is_string($value) ? trim($value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($error);
        }
        return $text;
    }

    private function reason(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || mb_strlen($text, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('STAFF_ORG_CORRECTION_REASON_INVALID');
        }
        return $text;
    }

    private function commentHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || mb_strlen($text, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('STAFF_ORG_CORRECTION_COMMENT_INVALID');
        }
        return hash('sha256', $text);
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException($error);
        }
        return (int) $id;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $error): string
    {
        $choice = is_string($value) ? strtolower(trim($value)) : '';
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }
        return $choice;
    }

    private function hashKey(mixed $value, string $error): string
    {
        $key = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new InvalidArgumentException($error);
        }
        return $key;
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $values, int $limit, string $error): array
    {
        if (!is_array($values) || count($values) > $limit) {
            throw new DomainException($error);
        }
        $result = [];
        foreach ($values as $value) {
            $result[] = $this->positiveId($value, $error);
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return list<string> */
    private function dateList(mixed $values, int $limit): array
    {
        if (!is_array($values) || count($values) > $limit) {
            throw new DomainException('STAFF_ORG_CORRECTION_DATE_IMPACT_INVALID');
        }
        $result = array_map(fn (mixed $value): string => $this->date($value, 'STAFF_ORG_CORRECTION_DATE_IMPACT_INVALID'), $values);
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<array{resource_type:string,resource_id:int}> */
    private function requestList(mixed $values, int $limit): array
    {
        if (!is_array($values) || count($values) > $limit) {
            throw new DomainException('STAFF_ORG_CORRECTION_REQUEST_IMPACT_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new DomainException('STAFF_ORG_CORRECTION_REQUEST_IMPACT_INVALID');
            }
            $type = $this->choice($value['resource_type'] ?? null, ['permission_request', 'leave_request', 'approval_instance'], 'STAFF_ORG_CORRECTION_REQUEST_IMPACT_INVALID');
            $id = $this->positiveId($value['resource_id'] ?? null, 'STAFF_ORG_CORRECTION_REQUEST_IMPACT_INVALID');
            $result[$type . ':' . $id] = ['resource_type' => $type, 'resource_id' => $id];
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    /** @return list<string> */
    private function periodList(mixed $values, int $limit): array
    {
        if (!is_array($values) || count($values) > $limit) {
            throw new DomainException('STAFF_ORG_CORRECTION_REPORT_IMPACT_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            $period = is_string($value) ? trim($value) : '';
            if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $period) !== 1) {
                throw new DomainException('STAFF_ORG_CORRECTION_REPORT_IMPACT_INVALID');
            }
            $result[] = $period;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private function warningList(mixed $values): array
    {
        if (!is_array($values) || count($values) > 20) {
            throw new DomainException('STAFF_ORG_CORRECTION_WARNING_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            $warning = is_string($value) ? strtolower(trim($value)) : '';
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $warning) !== 1) {
                throw new DomainException('STAFF_ORG_CORRECTION_WARNING_INVALID');
            }
            $result[] = $warning;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
