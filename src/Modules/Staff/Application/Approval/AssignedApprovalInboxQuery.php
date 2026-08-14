<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Approval;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Staff\Contracts\AssignedApprovalInboxReadRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Shapes only the actionable approvals assigned to the authenticated manager.
 * Authorization belongs to the caller, while this query makes accidental
 * cross-user or non-current-step exposure impossible at the data boundary.
 */
final class AssignedApprovalInboxQuery
{
    public function __construct(
        private AssignedApprovalInboxReadRepository $repository,
        ?DateTimeZone $clockZone = null
    ) {
        $this->clockZone = $clockZone ?? new DateTimeZone('Africa/Cairo');
    }

    private DateTimeZone $clockZone;

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function forAssignee(int $assigneeUserId, array $filters = [], ?DateTimeImmutable $now = null): array
    {
        if ($assigneeUserId <= 0) {
            throw new InvalidArgumentException('APPROVAL_INBOX_ASSIGNEE_INVALID');
        }
        $resourceType = $this->nullableResourceType($filters['resource_type'] ?? null);
        $page = $this->positiveInt($filters['page'] ?? 1, 'APPROVAL_INBOX_PAGE_INVALID', 1000000);
        $perPage = $this->positiveInt($filters['per_page'] ?? 25, 'APPROVAL_INBOX_PER_PAGE_INVALID', 100);
        $offset = ($page - 1) * $perPage;
        $currentTime = $now ?? new DateTimeImmutable('now', $this->clockZone);

        $total = $this->repository->countActiveForAssignee($assigneeUserId, $resourceType);
        $rows = $this->repository->activeForAssignee($assigneeUserId, $resourceType, $perPage, $offset);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['assignee_user_id'] ?? 0) !== $assigneeUserId) {
                throw new DomainException('APPROVAL_INBOX_ROW_INVALID');
            }
            $items[] = $this->presentRow($row, $currentTime);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentRow(array $row, DateTimeImmutable $now): array
    {
        $stage = $this->decodeObject($row['step_snapshot_json'] ?? null, 'APPROVAL_INBOX_STAGE_SNAPSHOT_INVALID');
        $instance = $this->decodeObject($row['instance_snapshot_json'] ?? null, 'APPROVAL_INBOX_INSTANCE_SNAPSHOT_INVALID');
        $assignment = $this->decodeObject($row['assignment_snapshot'] ?? null, 'APPROVAL_INBOX_ASSIGNEE_SNAPSHOT_INVALID');
        $context = $instance['context'] ?? null;
        if (!is_array($context)) {
            throw new DomainException('APPROVAL_INBOX_INSTANCE_SNAPSHOT_INVALID');
        }
        $dueAt = $this->nullableDate($row['due_at'] ?? null, 'APPROVAL_INBOX_DUE_INVALID');

        return [
            'instance_id' => $this->positiveId($row['instance_id'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'step_id' => $this->positiveId($row['step_id'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'step_lock_version' => $this->positiveId($row['step_lock_version'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'resource_type' => $this->resourceType($row['resource_type'] ?? null),
            'resource_id' => $this->positiveId($row['resource_id'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'workflow_version_id' => $this->positiveId($row['workflow_version_id'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'sequence_no' => $this->positiveId($row['sequence_no'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'stage_id' => $this->positiveId($row['stage_id'] ?? null, 'APPROVAL_INBOX_ROW_INVALID'),
            'stage_name' => $this->requiredText($stage['name'] ?? null, 'APPROVAL_INBOX_STAGE_SNAPSHOT_INVALID', 200),
            'decision_mode' => $this->requiredText($stage['decision_mode'] ?? null, 'APPROVAL_INBOX_STAGE_SNAPSHOT_INVALID', 40),
            'due_at' => $dueAt === null ? null : $this->databaseInstant($dueAt),
            'due_state' => $dueAt === null ? 'no_due_date' : ($dueAt <= $now ? 'overdue' : 'open'),
            'activated_at' => $this->requiredText($row['activated_at'] ?? null, 'APPROVAL_INBOX_ROW_INVALID', 40),
            'started_at' => $this->requiredText($row['started_at'] ?? null, 'APPROVAL_INBOX_ROW_INVALID', 40),
            'relationship_kind' => $this->requiredText($row['relationship_kind'] ?? null, 'APPROVAL_INBOX_ROW_INVALID', 80),
            'acting_for_user_id' => $this->nullablePositiveId($assignment['acting_for_user_id'] ?? null),
            'staff_user_id' => $this->nullablePositiveId($context['staff_user_id'] ?? null),
            'permission_type_id' => $this->nullablePositiveId($context['permission_type_id'] ?? null),
            'request_id' => $this->nullablePositiveId($context['request_id'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value, string $errorCode): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new DomainException($errorCode);
        }
        try {
            $decoded = json_decode($value, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException($errorCode);
        }
        if (!is_array($decoded)) {
            throw new DomainException($errorCode);
        }

        return $decoded;
    }

    private function nullableResourceType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->resourceType($value);
    }

    private function resourceType(mixed $value): string
    {
        $type = trim((string) $value);
        if ($type === '' || mb_strlen($type) > 80 || preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException('APPROVAL_INBOX_RESOURCE_TYPE_INVALID');
        }

        return $type;
    }

    private function positiveInt(mixed $value, string $errorCode, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0 || (int) $value > $maximum) {
            throw new InvalidArgumentException($errorCode);
        }

        return (int) $value;
    }

    private function positiveId(mixed $value, string $errorCode): int
    {
        return $this->positiveInt($value, $errorCode, PHP_INT_MAX);
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'APPROVAL_INBOX_SNAPSHOT_INVALID');
    }

    private function requiredText(mixed $value, string $errorCode, int $maximum): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $maximum) {
            throw new DomainException($errorCode);
        }

        return $text;
    }

    private function nullableDate(mixed $value, string $errorCode): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $value, $this->clockZone);
        } catch (\Throwable) {
            throw new DomainException($errorCode);
        }
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
