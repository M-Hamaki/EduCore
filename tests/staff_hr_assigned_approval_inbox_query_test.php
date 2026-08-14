<?php

declare(strict_types=1);

/** Isolated least-privilege proof for the manager approval inbox query. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Application\Approval\AssignedApprovalInboxQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoAssignedApprovalInboxReadRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertError = static function (callable $callback, string $expectedCode, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (no error)');
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expectedCode, $message . ' (' . $exception->getMessage() . ')');
    }
};

$newDatabase = static function (): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE staff_approval_instances (
        id INTEGER PRIMARY KEY,
        resource_type TEXT NOT NULL,
        resource_id INTEGER NOT NULL,
        workflow_version_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        current_sequence INTEGER NOT NULL,
        started_at TEXT NOT NULL,
        snapshot_json TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE staff_approval_steps (
        id INTEGER PRIMARY KEY,
        instance_id INTEGER NOT NULL,
        stage_id INTEGER NOT NULL,
        sequence_no INTEGER NOT NULL,
        status TEXT NOT NULL,
        due_at TEXT NULL,
        activated_at TEXT NULL,
        lock_version INTEGER NOT NULL,
        snapshot_json TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE staff_approval_assignees (
        id INTEGER PRIMARY KEY,
        step_id INTEGER NOT NULL,
        assignee_user_id INTEGER NOT NULL,
        relationship_kind TEXT NOT NULL,
        assignment_snapshot TEXT NOT NULL,
        status TEXT NOT NULL
    )');

    return $db;
};

$insert = static function (
    PDO $db,
    int $instanceId,
    int $stepId,
    int $assigneeId,
    int $userId,
    string $resourceType = 'permission_request',
    string $instanceStatus = 'pending',
    int $currentSequence = 1,
    int $stepSequence = 1,
    string $stepStatus = 'active',
    string|null $dueAt = null,
    string $assigneeStatus = 'eligible',
    ?string $instanceSnapshot = null
): void {
    $instanceSnapshot ??= json_encode([
        'context' => ['staff_user_id' => 3001, 'permission_type_id' => 7, 'request_id' => 8001],
    ], JSON_THROW_ON_ERROR);
    $stepSnapshot = json_encode(['name' => 'المدير المباشر', 'decision_mode' => 'sequential'], JSON_THROW_ON_ERROR);
    $assigneeSnapshot = json_encode(['acting_for_user_id' => 4001], JSON_THROW_ON_ERROR);
    $db->prepare(
        'INSERT INTO staff_approval_instances
         (id, resource_type, resource_id, workflow_version_id, status, current_sequence, started_at, snapshot_json)
         VALUES (?, ?, ?, 900, ?, ?, ?, ?)'
    )->execute([$instanceId, $resourceType, $instanceId + 5000, $instanceStatus, $currentSequence, '2026-10-01 08:00:00.000000', $instanceSnapshot]);
    $db->prepare(
        'INSERT INTO staff_approval_steps
         (id, instance_id, stage_id, sequence_no, status, due_at, activated_at, lock_version, snapshot_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, 4, ?)'
    )->execute([$stepId, $instanceId, $instanceId + 100, $stepSequence, $stepStatus, $dueAt, '2026-10-01 08:00:00.000000', $stepSnapshot]);
    $db->prepare(
        'INSERT INTO staff_approval_assignees
         (id, step_id, assignee_user_id, relationship_kind, assignment_snapshot, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$assigneeId, $stepId, $userId, 'delegated_direct_manager', $assigneeSnapshot, $assigneeStatus]);
};

try {
    $db = $newDatabase();
    $insert($db, 1, 11, 111, 501, 'permission_request', 'pending', 1, 1, 'active', '2026-10-01 08:30:00.000000');
    $insert($db, 2, 12, 112, 501, 'staff_schedule_change_request', 'pending', 1, 1, 'active', null);
    $insert($db, 3, 13, 113, 501, 'permission_request', 'pending', 1, 2, 'waiting');
    $insert($db, 4, 14, 114, 501, 'permission_request', 'pending', 2, 1, 'active');
    $insert($db, 5, 15, 115, 501, 'permission_request', 'approved', 1, 1, 'active');
    $insert($db, 6, 16, 116, 501, 'permission_request', 'pending', 1, 1, 'active', null, 'decided');
    $insert($db, 7, 17, 117, 777, 'permission_request', 'pending', 1, 1, 'active');

    $query = new AssignedApprovalInboxQuery(
        new PdoAssignedApprovalInboxReadRepository($db),
        new DateTimeZone('Africa/Cairo')
    );
    $inbox = $query->forAssignee(501, ['per_page' => 25], new DateTimeImmutable('2026-10-01 09:00:00.000000', new DateTimeZone('Africa/Cairo')));
    $assert($inbox['total'] === 2 && count($inbox['items']) === 2, 'only eligible assignees on active current pending steps appear');
    $assert($inbox['items'][0]['instance_id'] === 1 && $inbox['items'][0]['due_state'] === 'overdue', 'due work is ordered first and marked overdue');
    $assert($inbox['items'][0]['step_lock_version'] === 4 && $inbox['items'][0]['acting_for_user_id'] === 4001, 'inbox preserves the decision lock and delegated acting-for evidence');
    $assert($inbox['items'][0]['staff_user_id'] === 3001 && $inbox['items'][0]['request_id'] === 8001, 'inbox exposes only the frozen resource identifiers required for a safe action');
    $assert(!array_key_exists('reason', $inbox['items'][0]) && !array_key_exists('snapshot_json', $inbox['items'][0]), 'inbox does not leak private request content or raw snapshots');

    $permissionOnly = $query->forAssignee(501, ['resource_type' => 'permission_request', 'per_page' => 25]);
    $assert($permissionOnly['total'] === 1 && $permissionOnly['items'][0]['instance_id'] === 1, 'resource type filter remains scoped to assigned active work');
    $otherUser = $query->forAssignee(777, ['per_page' => 25]);
    $assert($otherUser['total'] === 1 && $otherUser['items'][0]['instance_id'] === 7, 'another account cannot see the manager inbox rows');
    $assertError(
        static fn (): array => $query->forAssignee(501, ['resource_type' => 'permission request']),
        'APPROVAL_INBOX_RESOURCE_TYPE_INVALID',
        'invalid resource filters fail before the query boundary'
    );

    $corruptDb = $newDatabase();
    $insert($corruptDb, 21, 211, 2111, 501, 'permission_request', 'pending', 1, 1, 'active', null, 'eligible', '{invalid-json');
    $corruptQuery = new AssignedApprovalInboxQuery(new PdoAssignedApprovalInboxReadRepository($corruptDb));
    $assertError(
        static fn (): array => $corruptQuery->forAssignee(501),
        'APPROVAL_INBOX_INSTANCE_SNAPSHOT_INVALID',
        'corrupt frozen evidence fails closed instead of silently exposing a partial item'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: assigned inbox query exercise failed: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assigned inbox query failure(s).\n");
    exit(1);
}

echo "Staff-HR assigned approval inbox query test passed.\n";
