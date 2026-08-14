<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\BusFeeScheduleRepository;
use PDO;
use RuntimeException;

final class PdoBusFeeScheduleRepository implements BusFeeScheduleRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findActiveByLegacyKey(int $academicYearId, string $legacyZoneKey): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM finance_bus_fee_schedules
             WHERE academic_year_id = ? AND legacy_zone_key = ? AND status = 'active'
             ORDER BY version_number DESC LIMIT 1"
        );
        $stmt->execute([$academicYearId, $legacyZoneKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findActiveBySubscriptionKey(int $academicYearId, string $subscriptionKey, string $atDate): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM finance_bus_fee_schedules
             WHERE academic_year_id = ? AND transport_subscription_key = ?
               AND status = 'active' AND effective_from <= ?
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY version_number DESC LIMIT 1"
        );
        $stmt->execute([$academicYearId, $subscriptionKey, $atDate, $atDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createVersion(array $fields): int
    {
        $lock = $this->db->prepare(
            'SELECT version_number FROM finance_bus_fee_schedules
             WHERE academic_year_id = ? AND legacy_zone_key <=> ?
             ORDER BY version_number DESC FOR UPDATE'
        );
        $lock->execute([$fields['academic_year_id'], $fields['legacy_zone_key']]);
        $versions = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN));
        $version = $versions === [] ? 1 : max($versions) + 1;
        $this->db->prepare(
            'INSERT INTO finance_bus_fee_schedules
                (academic_year_id, transport_subscription_key, legacy_zone_key, zone_name,
                 version_number, amount, installments_json, notes, effective_from,
                 effective_to, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fields['academic_year_id'],
            $fields['transport_subscription_key'],
            $fields['legacy_zone_key'],
            $fields['zone_name'],
            $version,
            $fields['amount'],
            $fields['installments_json'],
            $fields['notes'],
            $fields['effective_from'],
            $fields['effective_to'],
            'draft',
            $fields['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function activate(int $scheduleId): void
    {
        $stmt = $this->db->prepare(
            'SELECT academic_year_id, legacy_zone_key, status
             FROM finance_bus_fee_schedules WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$scheduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) $row['status'] !== 'draft') {
            throw new RuntimeException('A draft bus fee schedule is required.');
        }
        $this->db->prepare(
            "UPDATE finance_bus_fee_schedules
             SET status = 'superseded'
             WHERE academic_year_id = ? AND legacy_zone_key <=> ? AND status = 'active'"
        )->execute([(int) $row['academic_year_id'], $row['legacy_zone_key']]);
        $this->db->prepare("UPDATE finance_bus_fee_schedules SET status = 'active' WHERE id = ?")
            ->execute([$scheduleId]);
    }

    public function archiveByLegacyKey(int $academicYearId, string $legacyZoneKey): void
    {
        $stmt = $this->db->prepare(
            "UPDATE finance_bus_fee_schedules
             SET status = 'archived'
             WHERE academic_year_id = ? AND legacy_zone_key = ? AND status IN ('draft','active')"
        );
        $stmt->execute([$academicYearId, $legacyZoneKey]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Active bus fee schedule was not found.');
        }
    }
}
