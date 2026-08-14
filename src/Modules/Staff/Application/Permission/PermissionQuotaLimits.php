<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use InvalidArgumentException;

/**
 * Single, snapshot-safe translation from a permission policy to ledger limits.
 *
 * Submission, approval, rejection, and later reversal all consume the same
 * frozen policy values instead of re-resolving a mutable policy version.
 */
final class PermissionQuotaLimits
{
    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public static function fromPolicy(array $policy, bool $overrideAuthorized = false): array
    {
        return [
            'max_requests_per_month' => self::nullableInteger(
                $policy['max_requests_per_month'] ?? null
            ),
            'max_minutes_per_month' => self::nullableInteger(
                $policy['max_minutes_per_month'] ?? null
            ),
            'allow_quota_override' => (bool) ($policy['allow_quota_override'] ?? false),
            'quota_override_max_minutes' => self::nullableInteger(
                $policy['quota_override_max_minutes'] ?? null
            ),
            'override_authorized' => $overrideAuthorized,
        ];
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('PERMISSION_REQUEST_POLICY_LIMIT_INVALID');
    }
}
