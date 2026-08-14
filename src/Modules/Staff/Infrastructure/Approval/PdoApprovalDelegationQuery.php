<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Approval;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\ApprovalDelegationQuery;
use InvalidArgumentException;
use JsonException;
use PDO;

/** Resolves one effective delegation without following a delegation chain. */
final class PdoApprovalDelegationQuery implements ApprovalDelegationQuery
{
    private const SCOPE_PRIORITY = [
        'global' => 1,
        'org_unit' => 2,
        'group' => 3,
        'request_type' => 4,
        'staff' => 5,
    ];

    public function __construct(private PDO $db)
    {
    }

    public function resolve(
        int $delegatorUserId,
        int $staffUserId,
        ?int $orgUnitId,
        array $groupIds,
        string $resourceType,
        ?int $requestTypeId,
        DateTimeImmutable $atDate
    ): array {
        if ($delegatorUserId <= 0 || $staffUserId <= 0 || trim($resourceType) === '') {
            throw new InvalidArgumentException('Approval delegation resolution requires valid identifiers.');
        }
        foreach ($groupIds as $groupId) {
            if (filter_var($groupId, FILTER_VALIDATE_INT) === false || (int) $groupId <= 0) {
                throw new InvalidArgumentException('Approval delegation group identifiers must be positive.');
            }
        }
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));

        $timestamp = $atDate->format('Y-m-d H:i:s.u');
        $statement = $this->db->prepare(
            "SELECT id, delegate_user_id, scope_type, scope_id, request_types, valid_from, valid_to
             FROM staff_delegations
             WHERE delegator_user_id = :delegator_user_id
               AND status = 'active'
               AND valid_from <= :effective_at
               AND valid_to > :effective_at_again
             ORDER BY valid_from DESC, id DESC"
        );
        $statement->execute([
            ':delegator_user_id' => $delegatorUserId,
            ':effective_at' => $timestamp,
            ':effective_at_again' => $timestamp,
        ]);

        $candidates = [];
        $invalidDelegationIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->scopeMatches($row, $staffUserId, $orgUnitId, $groupIds, $requestTypeId)) {
                continue;
            }
            try {
                $matchesResource = $this->requestTypesMatch($row['request_types'] ?? null, $resourceType);
            } catch (JsonException) {
                $invalidDelegationIds[] = (int) $row['id'];
                continue;
            }
            if (!$matchesResource) {
                continue;
            }
            $scopeType = (string) $row['scope_type'];
            $row['scope_priority'] = self::SCOPE_PRIORITY[$scopeType];
            $candidates[] = $row;
        }
        if ($invalidDelegationIds !== []) {
            sort($invalidDelegationIds, SORT_NUMERIC);

            return [
                'delegation' => null,
                'conflicts' => [[
                    'reason' => 'invalid_delegation_request_types',
                    'delegation_ids' => $invalidDelegationIds,
                ]],
            ];
        }
        if ($candidates === []) {
            return ['delegation' => null, 'conflicts' => []];
        }

        $highestScope = max(array_column($candidates, 'scope_priority'));
        $winningRows = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => (int) $candidate['scope_priority'] === $highestScope
        ));
        $delegateIds = array_values(array_unique(array_map(
            static fn(array $candidate): int => (int) $candidate['delegate_user_id'],
            $winningRows
        )));
        sort($delegateIds, SORT_NUMERIC);
        if (count($delegateIds) !== 1) {
            return [
                'delegation' => null,
                'conflicts' => [[
                    'reason' => 'ambiguous_delegation',
                    'acting_for_user_id' => $delegatorUserId,
                    'staff_id' => $staffUserId,
                    'delegation_ids' => array_map(static fn(array $row): int => (int) $row['id'], $winningRows),
                    'delegate_user_ids' => $delegateIds,
                ]],
            ];
        }

        $winner = $winningRows[0];

        return [
            'delegation' => [
                'delegation_id' => (int) $winner['id'],
                'acting_for_user_id' => $delegatorUserId,
                'delegate_user_id' => $delegateIds[0],
                'valid_from' => (string) $winner['valid_from'],
                'valid_to' => $winner['valid_to'] !== null ? (string) $winner['valid_to'] : null,
            ],
            'conflicts' => [],
        ];
    }

    /** @param array<string,mixed> $row @param list<int> $groupIds */
    private function scopeMatches(
        array $row,
        int $staffUserId,
        ?int $orgUnitId,
        array $groupIds,
        ?int $requestTypeId
    ): bool {
        $scopeType = (string) ($row['scope_type'] ?? '');
        $scopeId = (int) ($row['scope_id'] ?? -1);
        if (!array_key_exists($scopeType, self::SCOPE_PRIORITY)) {
            return false;
        }

        return match ($scopeType) {
            'global' => $scopeId === 0,
            'staff' => $scopeId === $staffUserId,
            'org_unit' => $orgUnitId !== null && $scopeId === $orgUnitId,
            'group' => in_array($scopeId, $groupIds, true),
            'request_type' => $requestTypeId !== null && $scopeId === $requestTypeId,
            default => false,
        };
    }

    private function requestTypesMatch(mixed $requestTypes, string $resourceType): bool
    {
        if ($requestTypes === null) {
            return true;
        }
        if (!is_string($requestTypes)) {
            throw new JsonException('Approval delegation request-types payload is invalid.');
        }
        $trimmed = trim($requestTypes);
        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return true;
        }
        $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) === false) {
            throw new JsonException('Approval delegation request-types payload is invalid.');
        }

        return in_array($resourceType, array_map('strval', $decoded), true);
    }
}
