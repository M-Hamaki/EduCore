<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Portal;

use EduCore\Modules\Staff\Contracts\StaffSelfServicePortalReadRepository;
use InvalidArgumentException;

/** Shapes a worker-only permission portal without exposing reasons to another user. */
final class StaffSelfServicePortalQuery
{
    public function __construct(private StaffSelfServicePortalReadRepository $repository)
    {
    }

    /** @return array{permission_types:list<array<string,mixed>>,quota_rows:list<array<string,mixed>>,requests:list<array<string,mixed>>} */
    public function forStaff(int $staffUserId, string $periodKey): array
    {
        if ($staffUserId <= 0 || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $periodKey) !== 1) {
            throw new InvalidArgumentException('STAFF_SELF_SERVICE_QUERY_INVALID');
        }

        $types = array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'requires_reason' => (bool) ($row['requires_reason'] ?? false),
            'requires_custom_label' => (bool) ($row['requires_custom_label'] ?? false),
            'requires_attachment' => (bool) ($row['requires_attachment'] ?? false),
        ], $this->repository->activePermissionTypes());

        $quotaRows = array_map(static function (array $row): array {
            $maxCount = isset($row['max_requests_per_month']) ? (int) $row['max_requests_per_month'] : null;
            $maxMinutes = isset($row['max_minutes_per_month']) ? (int) $row['max_minutes_per_month'] : null;
            $heldCount = (int) ($row['reserved_count'] ?? 0);
            $heldMinutes = (int) ($row['reserved_minutes'] ?? 0);
            $usedCount = (int) ($row['consumed_count'] ?? 0);
            $usedMinutes = (int) ($row['consumed_minutes'] ?? 0);

            return [
                'type_name' => (string) ($row['type_name'] ?? ''),
                'available_count' => $maxCount === null ? null : max(0, $maxCount - $heldCount - $usedCount),
                'available_minutes' => $maxMinutes === null ? null : max(0, $maxMinutes - $heldMinutes - $usedMinutes),
                'held_count' => $heldCount,
                'held_minutes' => $heldMinutes,
                'used_count' => $usedCount,
                'used_minutes' => $usedMinutes,
            ];
        }, $this->repository->permissionQuotaAccountsForStaff($staffUserId, $periodKey));

        $requests = array_map(static function (array $row) use ($staffUserId): array {
            $requestId = (int) ($row['id'] ?? 0);
            $lockVersion = (int) ($row['lock_version'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            $actions = $status === 'draft' && $requestId > 0 && $lockVersion > 0
                ? [
                    'submit' => ['idempotency_key' => "permission-submit:{$staffUserId}:{$requestId}:{$lockVersion}"],
                    'withdraw' => true,
                ]
                : [];

            return [
                'id' => $requestId,
                'lock_version' => $lockVersion,
                'type_name' => (string) ($row['type_name'] ?? ''),
                'custom_label' => (string) ($row['custom_label'] ?? ''),
                'from_at' => (string) ($row['from_at'] ?? ''),
                'to_at' => (string) ($row['to_at'] ?? ''),
                'requested_minutes' => (int) ($row['requested_minutes'] ?? 0),
                'status' => $status,
                'workflow_label' => empty($row['workflow_instance_id'])
                    ? 'لم يبدأ مسار الاعتماد'
                    : 'مسار الاعتماد #' . (int) $row['workflow_instance_id'],
                'actions' => $actions,
            ];
        }, $this->repository->permissionRequestsForStaff($staffUserId, 100));

        return ['permission_types' => $types, 'quota_rows' => $quotaRows, 'requests' => $requests];
    }

    /** @return array{leave_types:list<array<string,mixed>>,balance_rows:list<array<string,mixed>>,requests:list<array<string,mixed>>} */
    public function leaveForStaff(int $staffUserId): array
    {
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('STAFF_SELF_SERVICE_QUERY_INVALID');
        }

        $types = array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'unit' => (string) ($row['unit'] ?? ''),
            'requires_reason' => (bool) ($row['requires_reason'] ?? false),
            'requires_attachment' => (bool) ($row['requires_attachment'] ?? false),
            'requires_medical_document' => (bool) ($row['requires_medical_document'] ?? false),
        ], $this->repository->activeLeaveTypes());

        $balances = array_map(static function (array $row): array {
            $available = (float) ($row['available_units'] ?? 0);
            $reserved = (float) ($row['reserved_units'] ?? 0);
            $consumed = (float) ($row['consumed_units'] ?? 0);

            return [
                'type_name' => (string) ($row['type_name'] ?? ''),
                'period_key' => (string) ($row['entitlement_period_key'] ?? ''),
                'available_units' => number_format($available, 3, '.', ''),
                'held_units' => number_format($reserved, 3, '.', ''),
                'used_units' => number_format($consumed, 3, '.', ''),
            ];
        }, $this->repository->leaveBalanceAccountsForStaff($staffUserId));

        $requests = array_map(static function (array $row) use ($staffUserId): array {
            $status = (string) ($row['status'] ?? '');
            $requestId = (int) ($row['id'] ?? 0);
            $lockVersion = (int) ($row['lock_version'] ?? 0);
            $attachmentRequired = (bool) ($row['requires_attachment'] ?? false)
                || (bool) ($row['requires_medical_document'] ?? false);
            $hasAttachment = !empty($row['supporting_document_ref']);
            $actions = [];
            if ($status === 'draft' && $requestId > 0 && $lockVersion > 0) {
                $actions = [
                    'submit' => ['idempotency_key' => "leave-submit:{$staffUserId}:{$requestId}:{$lockVersion}"],
                    'withdraw' => true,
                ];
                if ($attachmentRequired) {
                    $actions['attach_medical'] = true;
                }
            }

            return [
                'id' => $requestId,
                'lock_version' => $lockVersion,
                'type_name' => (string) ($row['type_name'] ?? ''),
                'from_at' => (string) ($row['from_at'] ?? ''),
                'to_at' => (string) ($row['to_at'] ?? ''),
                'requested_units' => (string) ($row['requested_units'] ?? '0.000'),
                'requested_minutes' => (int) ($row['requested_minutes'] ?? 0),
                'status' => $status,
                'attachment_status' => $hasAttachment ? 'attached' : ($attachmentRequired ? 'required' : 'not_required'),
                'workflow_label' => empty($row['workflow_instance_id'])
                    ? 'لم يبدأ مسار الاعتماد'
                    : 'مسار الاعتماد #' . (int) $row['workflow_instance_id'],
                'actions' => $actions,
            ];
        }, $this->repository->leaveRequestsForStaff($staffUserId, 100));

        return ['leave_types' => $types, 'balance_rows' => $balances, 'requests' => $requests];
    }
}
