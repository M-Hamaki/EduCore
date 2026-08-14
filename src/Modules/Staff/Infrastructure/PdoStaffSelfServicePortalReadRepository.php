<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\StaffSelfServicePortalReadRepository;
use PDO;

final class PdoStaffSelfServicePortalReadRepository implements StaffSelfServicePortalReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function activeLeaveTypes(): array
    {
        $statement = $this->db->query(
            "SELECT id, name, unit, requires_reason, requires_attachment, requires_medical_document
             FROM staff_leave_types
             WHERE status = 'active'
             ORDER BY name, id"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaveRequestsForStaff(int $staffUserId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->db->prepare(
            "SELECT request_row.id, request_row.lock_version, request_row.from_at, request_row.to_at,
                    request_row.requested_units, request_row.requested_minutes, request_row.status,
                    request_row.workflow_instance_id, request_row.supporting_document_ref,
                    type_row.name AS type_name, type_row.requires_attachment,
                    type_row.requires_medical_document
             FROM staff_leave_requests request_row
             JOIN staff_leave_types type_row ON type_row.id = request_row.leave_type_id
             WHERE request_row.staff_user_id = :staff_user_id
             ORDER BY request_row.created_at DESC, request_row.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['staff_user_id' => $staffUserId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaveBalanceAccountsForStaff(int $staffUserId): array
    {
        $statement = $this->db->prepare(
            "SELECT type_row.name AS type_name, account_row.entitlement_period_key,
                    account_row.available_units, account_row.reserved_units,
                    account_row.consumed_units
             FROM staff_leave_balance_accounts account_row
             JOIN staff_leave_types type_row ON type_row.id = account_row.leave_type_id
             WHERE account_row.staff_user_id = :staff_user_id
               AND account_row.status = 'open'
             ORDER BY account_row.period_from DESC, type_row.name, account_row.id"
        );
        $statement->execute(['staff_user_id' => $staffUserId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activePermissionTypes(): array
    {
        $statement = $this->db->query(
            "SELECT id, name, requires_reason, requires_custom_label, requires_attachment
             FROM staff_permission_types
             WHERE status = 'active'
             ORDER BY name, id"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function permissionRequestsForStaff(int $staffUserId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->db->prepare(
            "SELECT request_row.id, request_row.lock_version, request_row.custom_label,
                    request_row.from_at, request_row.to_at, request_row.requested_minutes,
                    request_row.status, request_row.workflow_instance_id, type_row.name AS type_name
             FROM staff_permission_requests request_row
             JOIN staff_permission_types type_row ON type_row.id = request_row.permission_type_id
             WHERE request_row.staff_user_id = :staff_user_id
             ORDER BY request_row.created_at DESC, request_row.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['staff_user_id' => $staffUserId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function permissionQuotaAccountsForStaff(int $staffUserId, string $periodKey): array
    {
        $statement = $this->db->prepare(
            "SELECT type_row.name AS type_name,
                    account_row.reserved_count, account_row.consumed_count,
                    account_row.reserved_minutes, account_row.consumed_minutes,
                    policy_row.max_requests_per_month, policy_row.max_minutes_per_month
             FROM staff_permission_quota_accounts account_row
             JOIN staff_permission_types type_row ON type_row.id = account_row.permission_type_id
             LEFT JOIN staff_permission_requests request_row
               ON request_row.staff_user_id = account_row.staff_user_id
              AND request_row.permission_type_id = account_row.permission_type_id
              AND request_row.policy_version_id IS NOT NULL
             LEFT JOIN staff_permission_policy_versions policy_row ON policy_row.id = request_row.policy_version_id
             WHERE account_row.staff_user_id = :staff_user_id
               AND account_row.period_key = :period_key
             GROUP BY account_row.id, type_row.name, account_row.reserved_count,
                      account_row.consumed_count, account_row.reserved_minutes,
                      account_row.consumed_minutes, policy_row.max_requests_per_month,
                      policy_row.max_minutes_per_month
             ORDER BY type_row.name, account_row.id"
        );
        $statement->execute(['staff_user_id' => $staffUserId, 'period_key' => $periodKey]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
