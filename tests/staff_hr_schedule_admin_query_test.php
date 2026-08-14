<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\SchedulePolicyAdminQueryService;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyReadRepository;

$repository = new class implements SchedulePolicyReadRepository {
    public array $lastFilters = [];
    public function listPolicies(array $filters = []): array
    {
        $this->lastFilters = $filters;
        return [[
            'id' => 3, 'code' => 'MORNING', 'name' => 'Morning', 'description' => 'Default',
            'status' => 'active', 'version_id' => 7, 'version_no' => 2, 'state' => 'draft',
            'valid_from' => '2026-07-01 00:00:00', 'valid_to' => null, 'timezone' => 'Africa/Cairo',
            'scope_type' => 'global', 'scope_id' => 0, 'scope_priority' => 0, 'lock_version' => 1,
            'create_idempotency_key' => 'secret', 'create_payload_hash' => str_repeat('a', 64),
        ]];
    }
    public function findPolicy(int $policyId): ?array { return null; }
    public function findVersion(int $versionId): ?array
    {
        return [
            'policy_id' => 3, 'policy_code' => 'MORNING', 'policy_name' => 'Morning',
            'policy_description' => 'Default', 'policy_status' => 'active', 'version_id' => $versionId,
            'version_no' => 2, 'state' => 'draft', 'valid_from' => '2026-07-01 00:00:00',
            'valid_to' => null, 'timezone' => 'Africa/Cairo', 'rounding_rule' => null,
            'season_start_mmdd' => null, 'season_end_mmdd' => null, 'supersedes_id' => 6,
            'lock_version' => 1, 'schedule' => ['timezone' => 'Africa/Cairo', 'days' => []],
            'scopes' => [['id' => 8, 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0,
                'valid_from' => '2026-07-01 00:00:00', 'valid_to' => null, 'status' => 'active']],
            'last_command_key' => 'secret', 'last_command_payload_hash' => str_repeat('b', 64),
        ];
    }
    public function candidateVersionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $at): array { return []; }
    public function calendarExceptionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $date): array { return []; }
    public function approvedChangesFor(int $staffId, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array { return []; }
    public function listCalendarExceptions(array $filters = []): array
    {
        return [[
            'id' => 9, 'calendar_date' => '2026-07-23', 'scope_type' => 'global', 'scope_id' => 0,
            'priority' => 0, 'exception_type' => 'holiday', 'reason' => 'Holiday', 'status' => 'active',
            'supersedes_id' => null, 'lock_version' => 1, 'created_at' => '2026-06-01 00:00:00',
            'idempotency_key' => 'secret', 'payload_hash' => str_repeat('c', 64),
        ]];
    }
};

$service = new SchedulePolicyAdminQueryService($repository);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};

$policies = $service->listPolicies(['state' => 'draft']);
$assert($repository->lastFilters === ['state' => 'draft'], 'filters pass through the read contract');
$assert($policies[0]['version_id'] === 7 && $policies[0]['scope_label'] === 'عام', 'policy list is a UI-safe latest-version DTO');
$assert(!isset($policies[0]['create_idempotency_key'], $policies[0]['create_payload_hash']), 'policy DTO redacts command identity fields');
$version = $service->findVersion(7);
$assert($version !== null && $version['schedule']['timezone'] === 'Africa/Cairo', 'version DTO preserves cloneable schedule data');
$assert(!isset($version['last_command_key'], $version['last_command_payload_hash']), 'version DTO redacts command identity fields');
$exceptions = $service->listCalendarExceptions();
$assert($exceptions[0]['scope_label'] === 'عام', 'calendar DTO has a readable scope label');
$assert(!isset($exceptions[0]['idempotency_key'], $exceptions[0]['payload_hash']), 'calendar DTO redacts idempotency data');

exit($failures === 0 ? 0 : 1);
