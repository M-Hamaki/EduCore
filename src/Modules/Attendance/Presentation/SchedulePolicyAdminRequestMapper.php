<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Presentation;

/**
 * Keeps the HTTP form's version-lineage names separate from the command payload contract.
 */
final class SchedulePolicyAdminRequestMapper
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function attachVersionLineage(
        array $payload,
        int $policyId,
        int $supersedesVersionId
    ): array {
        $payload['policy_id'] = $policyId > 0 ? $policyId : null;
        $payload['supersedes_version_id'] = $supersedesVersionId > 0 ? $supersedesVersionId : null;

        $version = is_array($payload['version'] ?? null) ? $payload['version'] : [];
        $version['supersedes_id'] = $supersedesVersionId > 0 ? $supersedesVersionId : null;
        $payload['version'] = $version;

        return $payload;
    }
}
