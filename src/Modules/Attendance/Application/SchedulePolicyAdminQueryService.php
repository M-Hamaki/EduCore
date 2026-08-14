<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use EduCore\Modules\Attendance\Contracts\SchedulePolicyReadRepository;

/** Produces presentation-safe DTOs without command hashes or idempotency keys. */
final class SchedulePolicyAdminQueryService
{
    private SchedulePolicyReadRepository $repository;

    public function __construct(SchedulePolicyReadRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return list<array<string,mixed>> */
    public function listPolicies(array $filters = []): array
    {
        return array_map(function (array $row): array {
            $scopeType = (string) ($row['scope_type'] ?? '');
            $scopeId = (int) ($row['scope_id'] ?? 0);

            return [
                'id' => (int) ($row['id'] ?? $row['policy_id'] ?? 0),
                'policy_id' => (int) ($row['id'] ?? $row['policy_id'] ?? 0),
                'code' => (string) ($row['code'] ?? $row['policy_code'] ?? ''),
                'name' => (string) ($row['name'] ?? $row['policy_name'] ?? ''),
                'description' => $row['description'] ?? $row['policy_description'] ?? null,
                'status' => (string) ($row['status'] ?? $row['policy_status'] ?? ''),
                'version_id' => isset($row['version_id']) ? (int) $row['version_id'] : null,
                'version_no' => isset($row['version_no']) ? (int) $row['version_no'] : null,
                'state' => $row['state'] ?? null,
                'valid_from' => $row['valid_from'] ?? null,
                'valid_to' => $row['valid_to'] ?? null,
                'timezone' => $row['timezone'] ?? null,
                'rounding_rule' => $row['rounding_rule'] ?? null,
                'season_start_mmdd' => $row['season_start_mmdd'] ?? null,
                'season_end_mmdd' => $row['season_end_mmdd'] ?? null,
                'supersedes_id' => isset($row['supersedes_id']) ? (int) $row['supersedes_id'] : null,
                'lock_version' => isset($row['lock_version']) ? (int) $row['lock_version'] : null,
                'published_by' => isset($row['published_by']) ? (int) $row['published_by'] : null,
                'published_at' => $row['published_at'] ?? null,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'scope_priority' => (int) ($row['scope_priority'] ?? $row['priority'] ?? 0),
                'scope_label' => $this->scopeLabel($scopeType, $scopeId),
                'version_count' => (int) ($row['version_count'] ?? 0),
            ];
        }, $this->repository->listPolicies($filters));
    }

    /** @return array<string,mixed>|null */
    public function findVersion(int $versionId): ?array
    {
        $row = $this->repository->findVersion($versionId);
        if ($row === null) {
            return null;
        }
        $scopes = [];
        foreach ((array) ($row['scopes'] ?? []) as $scope) {
            if (!is_array($scope)) {
                continue;
            }
            $scopeType = (string) ($scope['scope_type'] ?? '');
            $scopeId = (int) ($scope['scope_id'] ?? 0);
            $scopes[] = [
                'id' => isset($scope['id']) ? (int) $scope['id'] : null,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'scope_label' => $this->scopeLabel($scopeType, $scopeId),
                'priority' => (int) ($scope['priority'] ?? 0),
                'valid_from' => $scope['valid_from'] ?? null,
                'valid_to' => $scope['valid_to'] ?? null,
                'status' => (string) ($scope['status'] ?? ''),
            ];
        }

        return [
            'policy_id' => (int) ($row['policy_id'] ?? 0),
            'code' => (string) ($row['policy_code'] ?? $row['code'] ?? ''),
            'name' => (string) ($row['policy_name'] ?? $row['name'] ?? ''),
            'description' => $row['policy_description'] ?? $row['description'] ?? null,
            'status' => (string) ($row['policy_status'] ?? ''),
            'version_id' => (int) ($row['version_id'] ?? $row['id'] ?? 0),
            'version_no' => (int) ($row['version_no'] ?? 0),
            'state' => (string) ($row['state'] ?? ''),
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
            'timezone' => (string) ($row['timezone'] ?? 'Africa/Cairo'),
            'rounding_rule' => $row['rounding_rule'] ?? null,
            'season_start_mmdd' => $row['season_start_mmdd'] ?? null,
            'season_end_mmdd' => $row['season_end_mmdd'] ?? null,
            'supersedes_id' => isset($row['supersedes_id']) ? (int) $row['supersedes_id'] : null,
            'lock_version' => (int) ($row['lock_version'] ?? 0),
            'published_by' => isset($row['published_by']) ? (int) $row['published_by'] : null,
            'published_at' => $row['published_at'] ?? null,
            'schedule' => (array) ($row['schedule'] ?? []),
            'scopes' => $scopes,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listCalendarExceptions(array $filters = []): array
    {
        return array_map(function (array $row): array {
            $scopeType = (string) ($row['scope_type'] ?? '');
            $scopeId = (int) ($row['scope_id'] ?? 0);

            return [
                'id' => (int) ($row['id'] ?? 0),
                'calendar_date' => (string) ($row['calendar_date'] ?? ''),
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'scope_label' => $this->scopeLabel($scopeType, $scopeId),
                'priority' => (int) ($row['priority'] ?? 0),
                'exception_type' => (string) ($row['exception_type'] ?? ''),
                'schedule_policy_version_id' => isset($row['schedule_policy_version_id'])
                    ? (int) $row['schedule_policy_version_id']
                    : null,
                'override_json' => $row['override_json'] ?? null,
                'reason' => (string) ($row['reason'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'supersedes_id' => isset($row['supersedes_id']) ? (int) $row['supersedes_id'] : null,
                'lock_version' => (int) ($row['lock_version'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $this->repository->listCalendarExceptions($filters));
    }

    private function scopeLabel(string $scopeType, int $scopeId): string
    {
        return match ($scopeType) {
            'global' => 'عام',
            'org_unit' => 'الوحدة التنظيمية #' . $scopeId,
            'job_title' => 'المسمى الوظيفي #' . $scopeId,
            'group' => 'المجموعة #' . $scopeId,
            'staff' => 'العامل #' . $scopeId,
            default => 'غير محدد',
        };
    }
}
