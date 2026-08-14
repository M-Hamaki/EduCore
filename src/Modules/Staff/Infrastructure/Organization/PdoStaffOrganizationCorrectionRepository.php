<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Organization;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Staff\Contracts\StaffOrganizationCorrectionImpactGateway;
use EduCore\Modules\Staff\Contracts\StaffOrganizationCorrectionRepository;
use PDO;
use Throwable;

/** PDO adapter for immutable correction evidence and Staff-owned impact discovery. */
final class PdoStaffOrganizationCorrectionRepository implements StaffOrganizationCorrectionRepository, StaffOrganizationCorrectionImpactGateway
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }
        try {
            $result = $work();
            if ($owns) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function actorCanRequestCorrection(int $actorId): bool
    {
        $statement = $this->db->prepare(
            "SELECT 1
             FROM users u
             WHERE u.id = ? AND u.status = 'active'
               AND (
                    u.role = 'admin'
                    OR EXISTS (
                        SELECT 1 FROM user_role_assignments ura
                        WHERE ura.user_id = u.id
                          AND ura.role_key IN ('admin','super_admin')
                          AND ura.status = 'active'
                    )
               )
             LIMIT 1"
        );
        $statement->execute([$actorId]);
        return $statement->fetchColumn() !== false;
    }

    public function actorCanApproveCorrection(int $actorId): bool
    {
        $statement = $this->db->prepare(
            "SELECT 1
             FROM users u
             WHERE u.id = ? AND u.status = 'active'
               AND (
                    EXISTS (
                        SELECT 1 FROM user_role_assignments ura
                        WHERE ura.user_id = u.id
                          AND ura.role_key = 'super_admin'
                          AND ura.status = 'active'
                    )
                    OR EXISTS (
                        SELECT 1 FROM staff_manager_assignments sma
                        WHERE sma.manager_user_id = u.id
                          AND sma.manager_kind = 'hr'
                          AND sma.status = 'active'
                          AND sma.valid_from <= CURRENT_DATE
                          AND (sma.valid_to IS NULL OR sma.valid_to >= CURRENT_DATE)
                    )
               )
             LIMIT 1"
        );
        $statement->execute([$actorId]);
        return $statement->fetchColumn() !== false;
    }

    public function correctionByIdempotencyForUpdate(string $key): ?array
    {
        return $this->one(
            'SELECT * FROM staff_organization_corrections WHERE idempotency_key = ?' . $this->forUpdate(),
            [$key]
        );
    }

    public function correctionByIdForUpdate(int $correctionId): ?array
    {
        return $this->one(
            'SELECT * FROM staff_organization_corrections WHERE id = ?' . $this->forUpdate(),
            [$correctionId]
        );
    }

    public function finalDecisionForCorrectionForUpdate(int $correctionId): ?array
    {
        return $this->one(
            'SELECT * FROM staff_organization_correction_decisions WHERE correction_id = ?' . $this->forUpdate(),
            [$correctionId]
        );
    }

    public function decisionByIdempotencyForUpdate(string $key): ?array
    {
        return $this->one(
            'SELECT * FROM staff_organization_correction_decisions WHERE idempotency_key = ?' . $this->forUpdate(),
            [$key]
        );
    }

    public function insertCorrection(array $correction): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_organization_corrections
             (correction_kind, scope_type, scope_id, effective_from, effective_to,
              proposed_reference_id, reason_text, reason_hash, impact_snapshot_json,
              impact_snapshot_hash, reverses_correction_id, direction, requested_by,
              payload_hash, idempotency_key, lock_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $statement->execute([
            $correction['correction_kind'],
            $correction['scope_type'],
            $correction['scope_id'],
            $correction['effective_from'],
            $correction['effective_to'],
            $correction['proposed_reference_id'],
            $correction['reason_text'],
            $correction['reason_hash'],
            $correction['impact_snapshot_json'],
            $correction['impact_snapshot_hash'],
            $correction['reverses_correction_id'],
            $correction['direction'],
            $correction['requested_by'],
            $correction['payload_hash'],
            $correction['idempotency_key'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function insertDecision(array $decision): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_organization_correction_decisions
             (correction_id, decision, comment_hash, decided_by, decision_hash, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $decision['correction_id'],
            $decision['decision'],
            $decision['comment_hash'],
            $decision['decided_by'],
            $decision['decision_hash'],
            $decision['idempotency_key'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function recentCorrections(int $limit): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, d.decision, d.decided_by, d.created_at AS decided_at
             FROM staff_organization_corrections c
             LEFT JOIN staff_organization_correction_decisions d ON d.correction_id = c.id
             ORDER BY c.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function previewImpact(array $candidate, int $limit): array
    {
        $this->assertReferenceAvailable($candidate);
        $staffIds = $this->affectedStaffIds($candidate, $limit);
        if ($staffIds === []) {
            throw new DomainException('STAFF_ORG_CORRECTION_NO_AFFECTED_STAFF');
        }

        $dates = $this->dates((string) $candidate['effective_from'], (string) $candidate['effective_to']);
        $requests = [];
        $warnings = [];
        foreach ([
            'staff_permission_requests' => 'permission_request',
            'staff_leave_requests' => 'leave_request',
        ] as $table => $resourceType) {
            if (!$this->tableExists($table)) {
                $warnings[] = $resourceType . '_source_unavailable';
                continue;
            }
            $requests = array_merge($requests, $this->affectedRequests($table, $resourceType, $staffIds, $candidate));
        }

        $periods = [];
        foreach ($dates as $date) {
            $periods[substr($date, 0, 7)] = true;
        }

        return [
            'affected_staff_ids' => $staffIds,
            'affected_work_dates' => $dates,
            'affected_requests' => $requests,
            'affected_report_periods' => array_keys($periods),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function publishImpact(array $event): array
    {
        $impact = $event['impact'] ?? null;
        if (!is_array($impact)) {
            throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_SNAPSHOT_INVALID');
        }
        $correctionId = (int) ($event['correction_id'] ?? 0);
        $decisionId = (int) ($event['decision_id'] ?? 0);
        $direction = (string) ($event['direction'] ?? '');
        $kind = (string) ($event['correction_kind'] ?? '');
        $snapshotHash = (string) ($event['impact_snapshot_hash'] ?? '');
        $baseKey = (string) ($event['idempotency_key'] ?? '');
        if ($correctionId <= 0 || $decisionId <= 0 || !in_array($direction, ['apply', 'reverse'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
        ) {
            throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_EVENT_INVALID');
        }

        $count = 0;
        $staffIds = $impact['affected_staff_ids'] ?? [];
        if ($kind !== 'manager') {
            foreach ($staffIds as $staffId) {
                foreach ($impact['affected_work_dates'] ?? [] as $workDate) {
                    $this->insertImpact([
                        $correctionId, $decisionId, $direction, 'attendance_day', 'staff_attendance_day',
                        null, $staffId, $workDate, null, $snapshotHash,
                    ], $baseKey);
                    ++$count;
                }
                foreach ($impact['affected_report_periods'] ?? [] as $period) {
                    $this->insertImpact([
                        $correctionId, $decisionId, $direction, 'report_period', 'staff_attendance_report_period',
                        null, $staffId, null, $period, $snapshotHash,
                    ], $baseKey);
                    ++$count;
                }
            }
        }
        foreach ($impact['affected_requests'] ?? [] as $request) {
            $this->insertImpact([
                $correctionId, $decisionId, $direction, 'request_route', (string) $request['resource_type'],
                (int) $request['resource_id'], null, null, null, $snapshotHash,
            ], $baseKey);
            ++$count;
        }

        return ['accepted' => true, 'intent_count' => $count];
    }

    /** @param array<string,mixed> $candidate */
    private function assertReferenceAvailable(array $candidate): void
    {
        $kind = (string) $candidate['correction_kind'];
        $referenceId = (int) $candidate['proposed_reference_id'];
        $table = match ($kind) {
            'organization_unit' => 'staff_org_units',
            'job_title' => 'staff_job_titles',
            'manager' => 'users',
            default => null,
        };
        if ($table === null) {
            return; // Calendar owner revalidates the reference when consuming the exact intent.
        }
        $status = $kind === 'manager' ? " AND status = 'active'" : '';
        $statement = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ?{$status} LIMIT 1");
        $statement->execute([$referenceId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('STAFF_ORG_CORRECTION_REFERENCE_UNAVAILABLE');
        }
    }

    /** @param array<string,mixed> $candidate @return list<int> */
    private function affectedStaffIds(array $candidate, int $limit): array
    {
        $scopeType = (string) $candidate['scope_type'];
        $params = [(string) $candidate['effective_to'], (string) $candidate['effective_from']];
        if ($scopeType === 'staff') {
            $sql = 'SELECT sp.user_id FROM staff_profiles sp WHERE sp.user_id = ?';
            $params = [(int) $candidate['scope_id']];
        } elseif ($scopeType === 'org_unit') {
            $sql = 'SELECT DISTINCT sa.staff_user_id FROM staff_assignments sa
                    WHERE sa.valid_from <= ? AND (sa.valid_to IS NULL OR sa.valid_to >= ?)
                      AND sa.org_unit_id = ?';
            $params[] = (int) $candidate['scope_id'];
        } elseif ($scopeType === 'policy_group') {
            $sql = 'SELECT DISTINCT gm.staff_user_id FROM staff_policy_group_memberships gm
                    WHERE gm.valid_from <= ? AND (gm.valid_to IS NULL OR gm.valid_to >= ?)
                      AND gm.status = \'active\' AND gm.group_id = ?';
            $params[] = (int) $candidate['scope_id'];
        } else {
            $sql = 'SELECT DISTINCT sa.staff_user_id FROM staff_assignments sa
                    WHERE sa.valid_from <= ? AND (sa.valid_to IS NULL OR sa.valid_to >= ?)
                      AND sa.assignment_kind = \'primary\'';
        }
        $sql .= ' ORDER BY 1 LIMIT ' . (int) ($limit + 1);
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > $limit) {
            throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_TOO_LARGE');
        }
        return $ids;
    }

    /** @param list<int> $staffIds @param array<string,mixed> $candidate @return list<array{resource_type:string,resource_id:int}> */
    private function affectedRequests(string $table, string $resourceType, array $staffIds, array $candidate): array
    {
        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id FROM {$table}
             WHERE staff_user_id IN ({$placeholders})
               AND from_at < ? AND to_at >= ?
               AND status IN ('pending_approval','cancellation_requested')
             ORDER BY id LIMIT 1001"
        );
        $statement->execute(array_merge($staffIds, [
            (string) $candidate['effective_to'] . ' 23:59:59',
            (string) $candidate['effective_from'] . ' 00:00:00',
        ]));
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > 1000) {
            throw new DomainException('STAFF_ORG_CORRECTION_IMPACT_TOO_LARGE');
        }
        return array_map(
            static fn (int $id): array => ['resource_type' => $resourceType, 'resource_id' => $id],
            $ids
        );
    }

    /** @return list<string> */
    private function dates(string $from, string $to): array
    {
        $timezone = new DateTimeZone('Africa/Cairo');
        $start = new DateTimeImmutable($from . ' 00:00:00', $timezone);
        $end = (new DateTimeImmutable($to . ' 00:00:00', $timezone))->modify('+1 day');
        $dates = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $date) {
            $dates[] = $date->format('Y-m-d');
        }
        return $dates;
    }

    /** @param list<mixed> $values */
    private function insertImpact(array $values, string $baseKey): void
    {
        $impactKey = hash('sha256', $baseKey . '|' . json_encode($values, JSON_UNESCAPED_SLASHES));
        $statement = $this->db->prepare(
            'INSERT INTO staff_organization_correction_impacts
             (correction_id, decision_id, direction, impact_type, resource_type,
              resource_id, staff_user_id, work_date, report_period, source_snapshot_hash, impact_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute(array_merge($values, [$impactKey]));
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function forUpdate(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
