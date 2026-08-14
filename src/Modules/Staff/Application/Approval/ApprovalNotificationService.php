<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DomainException;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use InvalidArgumentException;

/**
 * Produces neutral, retryable inbox/outbox intents for approval assignees.
 * It contains no delivery transport and never includes request reasons,
 * attachments, personal data, or raw workflow snapshots in a notification.
 */
final class ApprovalNotificationService
{
    private const EVENT_TYPES = ['assigned', 'reassigned', 'escalated'];

    public function __construct(
        private StaffNotificationPort $notifications,
        private string $managerInboxRoute = 'admin/hr_center.php?tab=approvals'
    ) {
        $this->managerInboxRoute = $this->internalRoute($this->managerInboxRoute);
    }

    /**
     * @param array<string,mixed> $instance
     * @param list<array<string,mixed>> $assignees
     * @return array{accepted:bool,status:string,receipt_id:?string,inbox_count:int,outbox_count:int}|null
     */
    public function notifyAssignees(
        array $instance,
        int $stepId,
        array $assignees,
        string $eventType = 'assigned',
        ?int $eventIdentity = null
    ): ?array {
        $instanceId = $this->positiveId($instance['id'] ?? $instance['instance_id'] ?? null, 'APPROVAL_NOTIFICATION_INSTANCE_INVALID');
        $stepId = $this->positiveId($stepId, 'APPROVAL_NOTIFICATION_STEP_INVALID');
        $eventType = strtolower(trim($eventType));
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('APPROVAL_NOTIFICATION_EVENT_INVALID');
        }
        $recipients = $this->recipientIds($assignees);
        if ($recipients === []) {
            return null;
        }
        $identity = $eventIdentity === null
            ? $stepId
            : $this->positiveId($eventIdentity, 'APPROVAL_NOTIFICATION_EVENT_INVALID');
        $resourceType = $this->resourceType($instance['resource_type'] ?? null);
        $resourceId = $this->positiveId($instance['resource_id'] ?? null, 'APPROVAL_NOTIFICATION_INSTANCE_INVALID');
        $workflowVersionId = $this->positiveId(
            $instance['workflow_version_id'] ?? null,
            'APPROVAL_NOTIFICATION_INSTANCE_INVALID'
        );
        $eventKey = 'staff-approval:' . $instanceId . ':step:' . $stepId . ':' . $eventType . ':' . $identity;

        return $this->notifications->notifyRecipients(
            $eventKey,
            $recipients,
            $this->managerInboxRoute,
            'لديك اعتماد جديد يحتاج إلى قرار.',
            [
                'schema_version' => 1,
                'event_type' => $eventType,
                'approval_instance_id' => $instanceId,
                'approval_step_id' => $stepId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'workflow_version_id' => $workflowVersionId,
            ],
            'staff-approval-notification:' . $eventKey
        );
    }

    /** @param list<array<string,mixed>> $assignees @return list<int> */
    private function recipientIds(array $assignees): array
    {
        $recipients = [];
        foreach ($assignees as $assignee) {
            if (!is_array($assignee)) {
                throw new DomainException('APPROVAL_NOTIFICATION_ASSIGNEE_INVALID');
            }
            $userId = $this->positiveId(
                $assignee['assignee_user_id'] ?? $assignee['user_id'] ?? null,
                'APPROVAL_NOTIFICATION_ASSIGNEE_INVALID'
            );
            if (isset($recipients[$userId])) {
                continue;
            }
            $recipients[$userId] = true;
        }
        ksort($recipients, SORT_NUMERIC);

        return array_keys($recipients);
    }

    private function internalRoute(string $route): string
    {
        $route = trim($route);
        if ($route === '' || str_starts_with($route, '//') || str_contains($route, '\\')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $route) === 1) {
            throw new InvalidArgumentException('APPROVAL_NOTIFICATION_ROUTE_INVALID');
        }
        $path = parse_url($route, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains(rawurldecode($path), '..')) {
            throw new InvalidArgumentException('APPROVAL_NOTIFICATION_ROUTE_INVALID');
        }

        return $route;
    }

    private function resourceType(mixed $value): string
    {
        $type = trim((string) $value);
        if ($type === '' || mb_strlen($type) > 80 || preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException('APPROVAL_NOTIFICATION_INSTANCE_INVALID');
        }

        return $type;
    }

    private function positiveId(mixed $value, string $errorCode): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }
}
