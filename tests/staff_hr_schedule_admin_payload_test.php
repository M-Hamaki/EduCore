<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Presentation\SchedulePolicyAdminRequestMapper;

$failures = [];

function schedulePayloadCheck(string $name, bool $passed, array &$failures): void
{
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
}

$basePayload = [
    'policy' => ['code' => 'SHIFT-GLOBAL', 'name' => 'الدوام العام'],
    'version' => ['valid_from' => '2026-09-01', 'valid_to' => null],
    'days' => [],
    'scopes' => [],
];
$normalizedPayload = SchedulePolicyAdminRequestMapper::attachVersionLineage($basePayload, 42, 17);

$commandSpy = new class {
    /** @var array<string,mixed> */
    public array $payload = [];

    /** @param array<string,mixed> $payload */
    public function createDraft(int $actorId, array $payload, string $idempotencyKey): array
    {
        $this->payload = $payload;
        return ['actor_id' => $actorId, 'idempotency_key' => $idempotencyKey];
    }

    /** @param array<string,mixed> $payload */
    public function updateDraft(
        int $versionId,
        int $actorId,
        array $payload,
        int $expectedLockVersion,
        string $idempotencyKey
    ): array {
        $this->payload = $payload;
        return [
            'version_id' => $versionId,
            'actor_id' => $actorId,
            'expected_lock_version' => $expectedLockVersion,
            'idempotency_key' => $idempotencyKey,
        ];
    }
};
$commandSpy->createDraft(9, $normalizedPayload, str_repeat('a', 32));

schedulePayloadCheck(
    'clone_payload.command_receives_existing_policy_id',
    ($commandSpy->payload['policy_id'] ?? null) === 42,
    $failures
);
schedulePayloadCheck(
    'clone_payload.command_receives_version_supersedes_id',
    ($commandSpy->payload['version']['supersedes_id'] ?? null) === 17,
    $failures
);
schedulePayloadCheck(
    'clone_payload.keeps_form_lineage_for_diagnostics',
    ($commandSpy->payload['supersedes_version_id'] ?? null) === 17,
    $failures
);
schedulePayloadCheck(
    'clone_payload.does_not_mutate_source',
    !array_key_exists('policy_id', $basePayload)
        && !array_key_exists('supersedes_id', $basePayload['version']),
    $failures
);

$freshPayload = SchedulePolicyAdminRequestMapper::attachVersionLineage($basePayload, 0, 0);
schedulePayloadCheck(
    'new_policy_payload_has_no_lineage',
    ($freshPayload['policy_id'] ?? null) === null
        && ($freshPayload['version']['supersedes_id'] ?? null) === null,
    $failures
);

$updateResult = $commandSpy->updateDraft(31, 9, $freshPayload, 4, str_repeat('b', 32));
schedulePayloadCheck(
    'draft_update.command_receives_expected_lock_version',
    ($updateResult['version_id'] ?? null) === 31
        && ($updateResult['expected_lock_version'] ?? null) === 4,
    $failures
);
schedulePayloadCheck(
    'draft_update.remains_separate_from_clone_lineage',
    ($commandSpy->payload['policy_id'] ?? null) === null
        && ($commandSpy->payload['version']['supersedes_id'] ?? null) === null,
    $failures
);

$successorUpdatePayload = SchedulePolicyAdminRequestMapper::attachVersionLineage($basePayload, 0, 17);
$commandSpy->updateDraft(31, 9, $successorUpdatePayload, 5, str_repeat('c', 32));
schedulePayloadCheck(
    'draft_update.preserves_successor_lineage',
    ($commandSpy->payload['policy_id'] ?? null) === null
        && ($commandSpy->payload['version']['supersedes_id'] ?? null) === 17,
    $failures
);

exit($failures === [] ? 0 : 1);
