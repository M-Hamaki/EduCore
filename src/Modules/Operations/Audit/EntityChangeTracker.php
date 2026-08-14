<?php

declare(strict_types=1);

namespace EduCore\Modules\Operations\Audit;

final class EntityChangeTracker
{
    public static function diff(array $before, array $after, ?string $entityType = null): array
    {
        $safeBefore = AuditPolicyRegistry::redact($before, $entityType);
        $safeAfter = AuditPolicyRegistry::redact($after, $entityType);
        $changes = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            $oldValue = $before[$field] ?? null;
            $newValue = $after[$field] ?? null;
            if (self::equivalent($oldValue, $newValue)) {
                continue;
            }

            $changes[$field] = [
                'from' => $safeBefore[$field] ?? null,
                'to' => $safeAfter[$field] ?? null,
            ];
        }

        return $changes;
    }

    private static function equivalent($left, $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $left === (string) $right;
    }
}
