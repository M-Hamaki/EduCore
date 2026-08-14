<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\LegacyStaffShiftRepository;
use EduCore\Modules\Staff\Contracts\LegacyStaffDirectoryQuery;
use PDO;

final class PdoLegacyStaffShiftRepository implements LegacyStaffShiftRepository
{
    private PDO $db;

    public function __construct(PDO $db, private LegacyStaffDirectoryQuery $staffDirectory)
    {
        $this->db = $db;
    }

    public function viewData(): array
    {
        $settings = [];
        $settingsStmt = $this->db->query(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('staff_shift_start','staff_shift_end','staff_shift_grace_minutes')"
        );
        foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        $staff = $this->staffDirectory->listActiveStaff();
        $overrides = $this->db->query(
            'SELECT * FROM staff_shift_overrides ORDER BY user_id, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $names = $this->staffDirectory->namesByIds(array_map('intval', array_column($overrides, 'user_id')));
        foreach ($overrides as &$override) {
            $staffId = (int) ($override['user_id'] ?? 0);
            $override['staff_name'] = $names[$staffId] ?? ('عامل #' . $staffId);
        }
        unset($override);
        usort(
            $overrides,
            static fn (array $left, array $right): int => strnatcasecmp(
                (string) ($left['staff_name'] ?? ''),
                (string) ($right['staff_name'] ?? '')
            )
        );

        return [
            'defaultShiftStart' => $settings['staff_shift_start'] ?? '07:30',
            'defaultShiftEnd' => $settings['staff_shift_end'] ?? '14:30',
            'defaultGrace' => isset($settings['staff_shift_grace_minutes']) ? (int) $settings['staff_shift_grace_minutes'] : 15,
            'staffList' => $staff,
            'overrides' => $overrides,
        ];
    }

    public function lockDefaultSettings(): array
    {
        $result = [];
        $stmt = $this->db->query(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('staff_shift_start','staff_shift_end','staff_shift_grace_minutes') FOR UPDATE"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $result;
    }

    public function upsertDefaultSetting(string $key, string $value, string $description): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)'
        );
        $stmt->execute([$key, $value, $description]);
    }

    public function isEligibleActiveStaff(int $userId): bool
    {
        return $this->staffDirectory->isEligibleActiveStaff($userId);
    }

    public function lockOverrideByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_shift_overrides WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function storeOverride(array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO staff_shift_overrides (user_id, shift_start, shift_end, grace_minutes, is_active, notes)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE shift_start = VALUES(shift_start), shift_end = VALUES(shift_end),
                 grace_minutes = VALUES(grace_minutes), is_active = VALUES(is_active), notes = VALUES(notes)'
        );
        $stmt->execute([
            $values['user_id'], $values['shift_start'], $values['shift_end'],
            $values['grace_minutes'], $values['is_active'], $values['notes'],
        ]);
    }

    public function findOverrideByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_shift_overrides WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $names = $this->staffDirectory->namesByIds([(int) $row['user_id']]);
        $row['staff_name'] = $names[(int) $row['user_id']] ?? ('عامل #' . (int) $row['user_id']);
        return $row;
    }

    public function lockOverrideById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_shift_overrides WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $names = $this->staffDirectory->namesByIds([(int) $row['user_id']]);
        $row['staff_name'] = $names[(int) $row['user_id']] ?? ('عامل #' . (int) $row['user_id']);
        return $row;
    }

    public function deleteOverride(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM staff_shift_overrides WHERE id = ?');
        $stmt->execute([$id]);
    }
}
