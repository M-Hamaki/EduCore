<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyRepository;
use EduCore\Modules\Staff\Contracts\StaffGroupOverlapQuery;
use JsonException;
use PDO;
use PDOException;
use DomainException;
use RuntimeException;

/** MySQL persistence adapter for effective-dated staff schedules. */
final class PdoSchedulePolicyRepository implements SchedulePolicyRepository
{
    private PDO $db;
    private StaffGroupOverlapQuery $groupOverlap;

    public function __construct(PDO $db, StaffGroupOverlapQuery $groupOverlap)
    {
        $this->db = $db;
        $this->groupOverlap = $groupOverlap;
    }

    public function listPolicies(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            $where[] = 'p.status = ?';
            $params[] = trim((string) $filters['status']);
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $where[] = '(p.code LIKE ? OR p.name LIKE ?)';
            $search = '%' . trim((string) $filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }
        if (isset($filters['state']) && trim((string) $filters['state']) !== '') {
            $where[] = 'v.state = ?';
            $params[] = trim((string) $filters['state']);
        }
        if (isset($filters['scope_type']) && trim((string) $filters['scope_type']) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM staff_schedule_scopes filter_scope
                         WHERE filter_scope.policy_version_id = v.id
                           AND filter_scope.status = \'active\'
                           AND filter_scope.scope_type = ?)';
            $params[] = trim((string) $filters['scope_type']);
        }
        $sql = 'SELECT p.*, v.id AS version_id, v.version_no, v.state, v.valid_from, v.valid_to,
                       v.timezone, v.rounding_rule, v.season_start_mmdd, v.season_end_mmdd,
                       v.supersedes_id, v.lock_version, v.published_by, v.published_at,
                       s.scope_type, s.scope_id, s.priority AS scope_priority,
                       (SELECT COUNT(*) FROM staff_schedule_policy_versions vc WHERE vc.policy_id = p.id) AS version_count
                FROM staff_schedule_policies p
                LEFT JOIN staff_schedule_policy_versions v
                  ON v.policy_id = p.id
                 AND v.version_no = (SELECT MAX(vm.version_no) FROM staff_schedule_policy_versions vm WHERE vm.policy_id = p.id)
                LEFT JOIN staff_schedule_scopes s
                  ON s.id = (SELECT sm.id FROM staff_schedule_scopes sm
                             WHERE sm.policy_version_id = v.id AND sm.status = \'active\'
                             ORDER BY sm.priority DESC, sm.id LIMIT 1)';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.name, p.id';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPolicy(int $policyId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_schedule_policies WHERE id = ?');
        $statement->execute([$policyId]);
        $policy = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            return null;
        }
        $versions = $this->db->prepare(
            'SELECT id AS version_id, policy_id, version_no, state, valid_from, valid_to,
                    timezone, rounding_rule, season_start_mmdd, season_end_mmdd, supersedes_id,
                    lock_version, published_by, published_at, created_by, created_at
             FROM staff_schedule_policy_versions WHERE policy_id = ? ORDER BY version_no DESC'
        );
        $versions->execute([$policyId]);
        $policy['versions'] = $versions->fetchAll(PDO::FETCH_ASSOC);

        return $policy;
    }

    public function findVersion(int $versionId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT v.*, v.id AS version_id, p.code AS policy_code, p.name AS policy_name,
                    p.description AS policy_description, p.status AS policy_status
             FROM staff_schedule_policy_versions v
             JOIN staff_schedule_policies p ON p.id = v.policy_id
             WHERE v.id = ?'
        );
        $statement->execute([$versionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateVersion($row) : null;
    }

    public function candidateVersionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $at): array
    {
        [$scopeSql, $scopeParams] = $this->scopePredicate('s', $staffId, $assignmentSnapshot);
        $instant = $this->instant($at);
        $sql = 'SELECT v.*, v.id AS version_id, p.code AS policy_code, p.name AS policy_name,
                       p.description AS policy_description, p.status AS policy_status,
                       s.id AS scope_id_record, s.scope_type, s.scope_id,
                       s.priority AS scope_priority, s.valid_from AS scope_valid_from,
                       s.valid_to AS scope_valid_to
                FROM staff_schedule_policy_versions v
                JOIN staff_schedule_policies p ON p.id = v.policy_id AND p.status = \'active\'
                JOIN staff_schedule_scopes s ON s.policy_version_id = v.id AND s.status = \'active\'
                WHERE v.state = \'published\'
                  AND v.valid_from <= ? AND (v.valid_to IS NULL OR ? < v.valid_to)
                  AND s.valid_from <= ? AND (s.valid_to IS NULL OR ? < s.valid_to)
                  AND (' . $scopeSql . ')
                  AND NOT EXISTS (
                      SELECT 1 FROM staff_schedule_policy_versions successor
                      WHERE successor.supersedes_id = v.id
                        AND successor.state IN (\'published\',\'retired\')
                        AND successor.valid_from <= ?
                  )
                ORDER BY s.scope_type, s.priority DESC, s.valid_from DESC, v.valid_from DESC, v.id';
        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([$instant, $instant, $instant, $instant], $scopeParams, [$instant]));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['schedule'] = $this->schedulePayload((int) $row['version_id'], $row);
        }
        unset($row);

        return $rows;
    }

    public function calendarExceptionsFor(
        int $staffId,
        array $assignmentSnapshot,
        DateTimeImmutable $date
    ): array {
        [$scopeSql, $scopeParams] = $this->scopePredicate('e', $staffId, $assignmentSnapshot);
        $statement = $this->db->prepare(
            'SELECT e.* FROM staff_calendar_exceptions e
             WHERE e.calendar_date = ? AND e.status = \'active\'
               AND (' . $scopeSql . ')
               AND NOT EXISTS (
                   SELECT 1 FROM staff_calendar_exceptions successor
                   WHERE successor.supersedes_id = e.id
                     AND successor.status IN (\'active\',\'retired\')
               )
             ORDER BY e.scope_type, e.priority DESC, e.created_at DESC, e.id'
        );
        $statement->execute(array_merge([$date->format('Y-m-d')], $scopeParams));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['override_json'] = $this->decodeJson($row['override_json'] ?? null);
            if ((int) ($row['schedule_policy_version_id'] ?? 0) > 0) {
                $version = $this->findVersion((int) $row['schedule_policy_version_id']);
                if ($version !== null) {
                    $row['schedule'] = $version['schedule'];
                }
            }
        }
        unset($row);

        return $rows;
    }

    public function approvedChangesFor(
        int $staffId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_schedule_change_requests
             WHERE status = \'approved\' AND from_at < ? AND to_at > ?
               AND (staff_user_id = ? OR (change_type = \'shift_swap\' AND counterpart_staff_id = ?))
             ORDER BY from_at, id'
        );
        $statement->execute([
            $this->instant($windowEnd),
            $this->instant($windowStart),
            $staffId,
            $staffId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $snapshot = $this->decodeJson($row['approved_schedule_snapshot'] ?? null);
            if (($row['change_type'] ?? '') === 'shift_swap'
                && is_array($snapshot)
                && isset($snapshot['staff_schedules'])
                && is_array($snapshot['staff_schedules'])) {
                $staffSnapshot = $snapshot['staff_schedules'][(string) $staffId] ?? $snapshot['staff_schedules'][$staffId] ?? null;
                if (is_array($staffSnapshot)) {
                    $snapshot = $staffSnapshot;
                }
            }
            $row['approved_schedule_snapshot'] = $snapshot;
        }
        unset($row);

        return $rows;
    }

    public function listCalendarExceptions(array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'scope_type', 'exception_type'] as $field) {
            if (isset($filters[$field]) && trim((string) $filters[$field]) !== '') {
                $where[] = 'e.' . $field . ' = ?';
                $params[] = trim((string) $filters[$field]);
            }
        }
        if (isset($filters['date_from']) && trim((string) $filters['date_from']) !== '') {
            $where[] = 'e.calendar_date >= ?';
            $params[] = trim((string) $filters['date_from']);
        }
        if (isset($filters['date_to']) && trim((string) $filters['date_to']) !== '') {
            $where[] = 'e.calendar_date <= ?';
            $params[] = trim((string) $filters['date_to']);
        }
        $sql = 'SELECT e.* FROM staff_calendar_exceptions e';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.calendar_date DESC, e.scope_type, e.priority DESC, e.id DESC';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['override_json'] = $this->decodeJson($row['override_json'] ?? null);
        }
        unset($row);

        return $rows;
    }

    public function findCommandReceipt(string $idempotencyKey): ?array
    {
        return $this->fetchOne('SELECT * FROM staff_schedule_command_receipts WHERE idempotency_key = ?', [$idempotencyKey]);
    }

    public function recordCommandReceipt(array $receipt): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_schedule_command_receipts
             (command_type, resource_type, resource_id, idempotency_key, payload_hash, result_json, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $receipt['command_type'],
            $receipt['resource_type'],
            $receipt['resource_id'],
            $receipt['idempotency_key'],
            $receipt['payload_hash'],
            $this->encodeJson($receipt['result_json']),
            $receipt['actor_user_id'],
        ]);
    }

    public function nextVersionNumber(int $policyId): int
    {
        $policy = $this->policyForUpdate($policyId);
        if ($policy === null) {
            throw new RuntimeException('SCHEDULE_POLICY_NOT_FOUND');
        }
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1 FROM staff_schedule_policy_versions WHERE policy_id = ?'
        );
        $statement->execute([$policyId]);

        return (int) $statement->fetchColumn();
    }

    public function policyForUpdate(int $policyId): ?array
    {
        return $this->fetchOne('SELECT * FROM staff_schedule_policies WHERE id = ? FOR UPDATE', [$policyId]);
    }

    public function insertPolicy(array $policy): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_schedule_policies (code, name, description, status, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $policy['code'], $policy['name'], $policy['description'], $policy['status'], $policy['created_by'],
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException('SCHEDULE_POLICY_CODE_EXISTS', 0, $exception);
            }
            throw $exception;
        }

        return (int) $this->db->lastInsertId();
    }

    public function updatePolicy(int $policyId, array $policy): void
    {
        $statement = $this->db->prepare(
            'UPDATE staff_schedule_policies SET code = ?, name = ?, description = ?, status = ? WHERE id = ?'
        );
        try {
            $statement->execute([
                $policy['code'], $policy['name'], $policy['description'], $policy['status'], $policyId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException('SCHEDULE_POLICY_CODE_EXISTS', 0, $exception);
            }
            throw $exception;
        }
        if ($statement->rowCount() > 1) {
            throw new RuntimeException('SCHEDULE_POLICY_UPDATE_INVALID');
        }
    }

    public function insertDraftVersion(int $policyId, array $version): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_schedule_policy_versions
             (policy_id, version_no, state, valid_from, valid_to, timezone, rounding_rule,
              season_start_mmdd, season_end_mmdd, supersedes_id, create_idempotency_key,
              create_payload_hash, created_by)
             VALUES (?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $policyId, $version['version_no'], $version['valid_from'], $version['valid_to'],
            $version['timezone'], $version['rounding_rule'], $version['season_start_mmdd'],
            $version['season_end_mmdd'], $version['supersedes_id'], $version['create_idempotency_key'],
            $version['create_payload_hash'], $version['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findVersionByCreateKey(string $idempotencyKey): ?array
    {
        return $this->fetchOne(
            'SELECT v.*, v.id AS version_id, p.code AS policy_code, p.name AS policy_name
             FROM staff_schedule_policy_versions v JOIN staff_schedule_policies p ON p.id = v.policy_id
             WHERE v.create_idempotency_key = ?',
            [$idempotencyKey]
        );
    }

    public function versionForUpdate(int $versionId): ?array
    {
        return $this->fetchOne(
            'SELECT v.*, v.id AS version_id, p.code AS policy_code, p.name AS policy_name
             FROM staff_schedule_policy_versions v JOIN staff_schedule_policies p ON p.id = v.policy_id
             WHERE v.id = ? FOR UPDATE',
            [$versionId]
        );
    }

    public function updateDraftVersion(int $versionId, int $expectedLockVersion, array $version): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_schedule_policy_versions
             SET valid_from = ?, valid_to = ?, timezone = ?, rounding_rule = ?,
                 season_start_mmdd = ?, season_end_mmdd = ?, supersedes_id = ?,
                 last_command_key = ?, last_command_payload_hash = ?, lock_version = lock_version + 1
             WHERE id = ? AND state = \'draft\' AND lock_version = ?'
        );
        $statement->execute([
            $version['valid_from'], $version['valid_to'], $version['timezone'], $version['rounding_rule'],
            $version['season_start_mmdd'], $version['season_end_mmdd'], $version['supersedes_id'],
            $version['last_command_key'], $version['last_command_payload_hash'], $versionId, $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function replaceDraftDays(int $versionId, array $days): void
    {
        $deleteSegments = $this->db->prepare(
            'DELETE segment FROM staff_schedule_segments segment
             JOIN staff_schedule_days day ON day.id = segment.schedule_day_id
             WHERE day.policy_version_id = ?'
        );
        $deleteSegments->execute([$versionId]);
        $deleteDays = $this->db->prepare('DELETE FROM staff_schedule_days WHERE policy_version_id = ?');
        $deleteDays->execute([$versionId]);
        $insertDay = $this->db->prepare(
            'INSERT INTO staff_schedule_days
             (policy_version_id, weekday, is_working_day, start_time, end_time, end_day_offset,
              required_minutes, late_grace_minutes, early_grace_minutes, entry_window_before_minutes,
              entry_window_after_minutes, exit_window_before_minutes, exit_window_after_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertSegment = $this->db->prepare(
            'INSERT INTO staff_schedule_segments
             (schedule_day_id, sequence_no, segment_type, start_time, end_time,
              start_day_offset, end_day_offset, counts_required_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($days as $day) {
            $insertDay->execute([
                $versionId, $day['weekday'], $day['is_working_day'] ? 1 : 0,
                $day['start_time'], $day['end_time'], $day['end_day_offset'], $day['required_minutes'],
                $day['late_grace_minutes'], $day['early_grace_minutes'],
                $day['entry_window_before_minutes'], $day['entry_window_after_minutes'],
                $day['exit_window_before_minutes'], $day['exit_window_after_minutes'],
            ]);
            $dayId = (int) $this->db->lastInsertId();
            foreach ((array) ($day['segments'] ?? []) as $segment) {
                $insertSegment->execute([
                    $dayId, $segment['sequence_no'], $segment['segment_type'],
                    $segment['start_time'], $segment['end_time'], $segment['start_day_offset'],
                    $segment['end_day_offset'], $segment['counts_required_minutes'] ? 1 : 0,
                ]);
            }
        }
    }

    public function replaceDraftScopes(int $versionId, array $scopes): void
    {
        $delete = $this->db->prepare('DELETE FROM staff_schedule_scopes WHERE policy_version_id = ?');
        $delete->execute([$versionId]);
        $insert = $this->db->prepare(
            'INSERT INTO staff_schedule_scopes
             (policy_version_id, scope_type, scope_id, priority, valid_from, valid_to, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($scopes as $scope) {
            $insert->execute([
                $versionId, $scope['scope_type'], $scope['scope_id'], $scope['priority'],
                $scope['valid_from'], $scope['valid_to'], $scope['status'], $scope['created_by'],
            ]);
        }
    }

    public function publicationConflicts(int $versionId): array
    {
        $candidate = $this->versionForUpdate($versionId);
        if ($candidate === null) {
            return [['version_id' => $versionId, 'reason' => 'not_found']];
        }
        $candidateScopes = $this->scopesForVersion($versionId);
        $conflicts = [];
        $infinity = '9999-12-31 23:59:59.999999';
        foreach ($candidateScopes as $scope) {
            $statement = $this->db->prepare(
                'SELECT v.id AS version_id, v.policy_id, v.valid_from, v.valid_to, v.supersedes_id,
                        s.id AS scope_id_record, s.scope_type, s.scope_id, s.priority,
                        s.valid_from AS scope_valid_from, s.valid_to AS scope_valid_to
                 FROM staff_schedule_policy_versions v
                 JOIN staff_schedule_scopes s ON s.policy_version_id = v.id AND s.status = \'active\'
                 WHERE v.state = \'published\' AND v.id <> ? AND s.scope_type = ?
                   AND v.valid_from < ? AND ? < COALESCE(v.valid_to, ?)
                   AND s.valid_from < ? AND ? < COALESCE(s.valid_to, ?)'
            );
            $candidateEnd = $candidate['valid_to'] ?? $infinity;
            $scopeEnd = $scope['valid_to'] ?? $infinity;
            $statement->execute([
                $versionId, $scope['scope_type'], $candidateEnd, $candidate['valid_from'], $infinity,
                $scopeEnd, $scope['valid_from'], $infinity,
            ]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $other) {
                $effectiveStart = max(
                    (string) $candidate['valid_from'],
                    (string) $scope['valid_from'],
                    (string) $other['valid_from'],
                    (string) $other['scope_valid_from']
                );
                $effectiveEnd = min(
                    (string) $candidateEnd,
                    (string) $scopeEnd,
                    (string) ($other['valid_to'] ?? $infinity),
                    (string) ($other['scope_valid_to'] ?? $infinity)
                );
                if (!($effectiveStart < $effectiveEnd)) {
                    continue;
                }
                $isDirectPredecessor = (int) ($candidate['supersedes_id'] ?? 0) === (int) $other['version_id']
                    && (int) $candidate['policy_id'] === (int) $other['policy_id']
                    && (string) $candidate['valid_from'] > (string) $other['valid_from'];
                if ($isDirectPredecessor) {
                    continue;
                }
                $sameIdentity = (int) $scope['scope_id'] === (int) $other['scope_id'];
                $overlappingGroups = $scope['scope_type'] === 'group'
                    && $this->groupsShareMember(
                        (int) $scope['scope_id'],
                        (int) $other['scope_id'],
                        $effectiveStart,
                        $effectiveEnd
                    );
                if ($sameIdentity || $overlappingGroups) {
                    $conflicts[(int) $other['version_id']] = $other + ['reason' => $sameIdentity ? 'scope_overlap' : 'group_membership_overlap'];
                }
            }
        }
        ksort($conflicts, SORT_NUMERIC);

        return array_values($conflicts);
    }

    public function markPublished(
        int $versionId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $publishedAt,
        string $publicationKey,
        string $payloadHash
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE staff_schedule_policy_versions
             SET state = \'published\', published_by = ?, published_at = ?, publication_key = ?,
                 publication_payload_hash = ?, lock_version = lock_version + 1
             WHERE id = ? AND state = \'draft\' AND lock_version = ?'
        );
        $statement->execute([
            $actorId, $this->instant($publishedAt), $publicationKey, $payloadHash,
            $versionId, $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function findCalendarExceptionByIdempotency(string $idempotencyKey): ?array
    {
        $row = $this->fetchOne('SELECT * FROM staff_calendar_exceptions WHERE idempotency_key = ?', [$idempotencyKey]);
        if ($row !== null) {
            $row['override_json'] = $this->decodeJson($row['override_json'] ?? null);
        }

        return $row;
    }

    public function calendarExceptionForUpdate(int $exceptionId): ?array
    {
        $row = $this->fetchOne('SELECT * FROM staff_calendar_exceptions WHERE id = ? FOR UPDATE', [$exceptionId]);
        if ($row !== null) {
            $row['override_json'] = $this->decodeJson($row['override_json'] ?? null);
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function terminalCalendarExceptionForDateScopeForUpdate(
        string $calendarDate,
        string $scopeType,
        int $scopeId
    ): ?array {
        $row = $this->fetchOne(
            'SELECT e.*
             FROM staff_calendar_exceptions e
             WHERE e.calendar_date = ?
               AND e.scope_type = ?
               AND e.scope_id = ?
               AND e.status IN (\'active\', \'retired\')
               AND NOT EXISTS (
                   SELECT 1
                   FROM staff_calendar_exceptions successor
                   WHERE successor.supersedes_id = e.id
                     AND successor.status IN (\'active\', \'retired\')
               )
             ORDER BY e.id DESC
             LIMIT 1
             FOR UPDATE',
            [$calendarDate, $scopeType, $scopeId]
        );
        if ($row !== null) {
            $row['override_json'] = $this->decodeJson($row['override_json'] ?? null);
        }

        return $row;
    }

    public function insertCalendarException(array $exception): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_calendar_exceptions
             (calendar_date, scope_type, scope_id, priority, exception_type, schedule_policy_version_id,
              override_json, reason, status, supersedes_id, idempotency_key, payload_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $exception['calendar_date'], $exception['scope_type'], $exception['scope_id'], $exception['priority'],
            $exception['exception_type'], $exception['schedule_policy_version_id'],
            $this->nullableJson($exception['override_json']), $exception['reason'], $exception['status'],
            $exception['supersedes_id'], $exception['idempotency_key'], $exception['payload_hash'],
            $exception['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateDraftCalendarException(
        int $exceptionId,
        int $expectedLockVersion,
        array $exception
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE staff_calendar_exceptions
             SET calendar_date = ?, scope_type = ?, scope_id = ?, priority = ?, exception_type = ?,
                 schedule_policy_version_id = ?, override_json = ?, reason = ?, status = ?,
                 supersedes_id = ?, idempotency_key = ?, payload_hash = ?, lock_version = lock_version + 1
             WHERE id = ? AND status = \'draft\' AND lock_version = ?'
        );
        $statement->execute([
            $exception['calendar_date'], $exception['scope_type'], $exception['scope_id'], $exception['priority'],
            $exception['exception_type'], $exception['schedule_policy_version_id'],
            $this->nullableJson($exception['override_json']), $exception['reason'], $exception['status'],
            $exception['supersedes_id'], $exception['idempotency_key'], $exception['payload_hash'],
            $exceptionId, $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function findChangeRequestByIdempotency(string $idempotencyKey): ?array
    {
        return $this->decodeChange($this->fetchOne(
            'SELECT * FROM staff_schedule_change_requests WHERE idempotency_key = ?',
            [$idempotencyKey]
        ));
    }

    public function changeRequestForUpdate(int $requestId): ?array
    {
        return $this->decodeChange($this->fetchOne(
            'SELECT * FROM staff_schedule_change_requests WHERE id = ? FOR UPDATE',
            [$requestId]
        ));
    }

    public function insertChangeRequest(array $request): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_schedule_change_requests
             (staff_user_id, change_type, from_at, to_at, counterpart_staff_id,
              requested_schedule_version_id, reason, workflow_instance_id, status,
              approved_schedule_snapshot, idempotency_key, payload_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $request['staff_user_id'], $request['change_type'], $request['from_at'], $request['to_at'],
            $request['counterpart_staff_id'], $request['requested_schedule_version_id'], $request['reason'],
            $request['workflow_instance_id'], $request['status'],
            $this->nullableJson($request['approved_schedule_snapshot'] ?? null),
            $request['idempotency_key'], $request['payload_hash'], $request['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateChangeRequest(int $requestId, int $expectedLockVersion, array $changes): bool
    {
        $allowed = [
            'status', 'counterpart_accepted_by', 'counterpart_accepted_at', 'workflow_instance_id',
            'approved_schedule_snapshot', 'approved_by', 'approved_at', 'last_command_key',
            'last_command_payload_hash',
        ];
        $set = [];
        $params = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $set[] = '`' . $field . '` = ?';
            $value = $changes[$field];
            if ($field === 'approved_schedule_snapshot') {
                $value = $this->nullableJson($value);
            }
            $params[] = $value;
        }
        if ($set === []) {
            return false;
        }
        $set[] = 'lock_version = lock_version + 1';
        $params[] = $requestId;
        $params[] = $expectedLockVersion;
        $statement = $this->db->prepare(
            'UPDATE staff_schedule_change_requests SET ' . implode(', ', $set)
            . ' WHERE id = ? AND lock_version = ?'
        );
        $statement->execute($params);

        return $statement->rowCount() === 1;
    }

    public function overlappingChangeRequests(
        array $staffIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $statuses,
        ?int $excludeRequestId = null
    ): array {
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds), static fn (int $id): bool => $id > 0)));
        $statuses = array_values(array_unique(array_filter(array_map('strval', $statuses), static fn (string $status): bool => $status !== '')));
        if ($staffIds === [] || $statuses === []) {
            return [];
        }
        $staffMarks = implode(',', array_fill(0, count($staffIds), '?'));
        $statusMarks = implode(',', array_fill(0, count($statuses), '?'));
        $sql = 'SELECT * FROM staff_schedule_change_requests
                WHERE from_at < ? AND to_at > ?
                  AND status IN (' . $statusMarks . ')
                  AND (staff_user_id IN (' . $staffMarks . ') OR counterpart_staff_id IN (' . $staffMarks . '))';
        $params = array_merge(
            [$this->instant($to), $this->instant($from)],
            $statuses,
            $staffIds,
            $staffIds
        );
        if ($excludeRequestId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeRequestId;
        }
        $sql .= ' ORDER BY from_at, id';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map(fn (array $row): array => $this->decodeChange($row) ?? [], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function lockChangeParticipants(array $staffIds): void
    {
        $staffIds = array_values(array_unique(array_filter(
            array_map('intval', $staffIds),
            static fn (int $id): bool => $id > 0
        )));
        sort($staffIds, SORT_NUMERIC);
        if ($staffIds === []) {
            throw new RuntimeException('SCHEDULE_CHANGE_PARTICIPANTS_REQUIRED');
        }
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO staff_schedule_participant_locks (staff_user_id) VALUES (?)'
        );
        foreach ($staffIds as $staffId) {
            $insert->execute([$staffId]);
        }
        $marks = implode(',', array_fill(0, count($staffIds), '?'));
        $statement = $this->db->prepare(
            'SELECT staff_user_id FROM staff_schedule_participant_locks
             WHERE staff_user_id IN (' . $marks . ') ORDER BY staff_user_id FOR UPDATE'
        );
        $statement->execute($staffIds);
        if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== count($staffIds)) {
            throw new RuntimeException('SCHEDULE_CHANGE_PARTICIPANT_LOCK_FAILED');
        }
    }

    /** @return array<string,mixed> */
    private function hydrateVersion(array $row): array
    {
        $row['schedule'] = $this->schedulePayload((int) $row['version_id'], $row);
        $row['scopes'] = $this->scopesForVersion((int) $row['version_id']);

        return $row;
    }

    /** @return array<string,mixed> */
    private function schedulePayload(int $versionId, array $version): array
    {
        $daysStatement = $this->db->prepare(
            'SELECT * FROM staff_schedule_days WHERE policy_version_id = ? ORDER BY weekday'
        );
        $daysStatement->execute([$versionId]);
        $days = $daysStatement->fetchAll(PDO::FETCH_ASSOC);
        $segmentStatement = $this->db->prepare(
            'SELECT * FROM staff_schedule_segments WHERE schedule_day_id = ? ORDER BY sequence_no'
        );
        foreach ($days as &$day) {
            $segmentStatement->execute([(int) $day['id']]);
            $segments = $segmentStatement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($segments as &$segment) {
                $segment['sequence_no'] = (int) $segment['sequence_no'];
                $segment['start_day_offset'] = (int) $segment['start_day_offset'];
                $segment['end_day_offset'] = (int) $segment['end_day_offset'];
                $segment['counts_required_minutes'] = (bool) $segment['counts_required_minutes'];
            }
            unset($segment);
            foreach ([
                'weekday', 'end_day_offset', 'required_minutes', 'late_grace_minutes',
                'early_grace_minutes', 'entry_window_before_minutes', 'entry_window_after_minutes',
                'exit_window_before_minutes', 'exit_window_after_minutes',
            ] as $integerField) {
                $day[$integerField] = (int) $day[$integerField];
            }
            $day['is_working_day'] = (bool) $day['is_working_day'];
            $day['segments'] = $segments;
        }
        unset($day);

        return [
            'timezone' => (string) ($version['timezone'] ?? 'Africa/Cairo'),
            'season_start_mmdd' => $version['season_start_mmdd'] ?? null,
            'season_end_mmdd' => $version['season_end_mmdd'] ?? null,
            'days' => $days,
        ];
    }

    private function scopesForVersion(int $versionId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_schedule_scopes WHERE policy_version_id = ? ORDER BY scope_type, priority DESC, valid_from'
        );
        $statement->execute([$versionId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function scopePredicate(string $alias, int $staffId, array $assignment): array
    {
        $clauses = ["({$alias}.scope_type = 'global' AND {$alias}.scope_id = 0)"];
        $params = [];
        if ($staffId > 0) {
            $clauses[] = "({$alias}.scope_type = 'staff' AND {$alias}.scope_id = ?)";
            $params[] = $staffId;
        }
        foreach (['org_unit' => 'org_unit_id', 'job_title' => 'job_title_id'] as $scopeType => $field) {
            $id = (int) ($assignment[$field] ?? 0);
            if ($id > 0) {
                $clauses[] = "({$alias}.scope_type = '{$scopeType}' AND {$alias}.scope_id = ?)";
                $params[] = $id;
            }
        }
        $groups = array_values(array_unique(array_filter(
            array_map('intval', (array) ($assignment['group_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        if ($groups !== []) {
            $clauses[] = "({$alias}.scope_type = 'group' AND {$alias}.scope_id IN ("
                . implode(',', array_fill(0, count($groups), '?')) . '))';
            array_push($params, ...$groups);
        }

        return [implode(' OR ', $clauses), $params];
    }

    private function groupsShareMember(int $leftGroup, int $rightGroup, string $from, string $to): bool
    {
        return $this->groupOverlap->groupsShareActiveMember(
            $leftGroup,
            $rightGroup,
            new DateTimeImmutable($from),
            new DateTimeImmutable($to)
        );
    }

    private function decodeChange(?array $row): ?array
    {
        if ($row !== null) {
            $row['approved_schedule_snapshot'] = $this->decodeJson($row['approved_schedule_snapshot'] ?? null);
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function fetchOne(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function instant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.u');
    }

    private function nullableJson(mixed $value): ?string
    {
        return $value === null ? null : $this->encodeJson($value);
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('SCHEDULE_JSON_INVALID', 0, $exception);
        }
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }
        try {
            return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SCHEDULE_JSON_CORRUPT', 0, $exception);
        }
    }
}
