<?php

declare(strict_types=1);

/** Isolated command, audit, expiry, and notification proof for Staff credential evidence. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Timeline\StaffDocumentExpiryService;
use EduCore\Modules\Staff\Contracts\StaffCredentialRepository;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;

final class StaffCredentialFixtureAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $failNext = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('AUDIT_WRITE_FAILED');
        }

        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class StaffCredentialFixtureNotifications implements StaffNotificationPort
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    /** @var list<int> */
    public array $rejectCredentialIds = [];
    /** @var list<int> */
    public array $throwCredentialIds = [];

    public function notifyRecipients(
        string $eventKey,
        array $recipientIds,
        string $secureRoute,
        string $neutralText,
        array $metadata,
        string $idempotencyKey
    ): array {
        $this->calls[] = compact('eventKey', 'recipientIds', 'secureRoute', 'neutralText', 'metadata', 'idempotencyKey');
        $credentialId = (int) ($metadata['credential_id'] ?? 0);
        if (in_array($credentialId, $this->throwCredentialIds, true)) {
            throw new RuntimeException('OUTBOX_PRIVATE_FAILURE');
        }
        if (in_array($credentialId, $this->rejectCredentialIds, true)) {
            return [
                'accepted' => false,
                'status' => 'rejected',
                'receipt_id' => null,
                'inbox_count' => 0,
                'outbox_count' => 0,
            ];
        }

        return [
            'accepted' => true,
            'status' => 'accepted',
            'receipt_id' => 'credential-outbox-' . $credentialId,
            'inbox_count' => 1,
            'outbox_count' => 1,
        ];
    }
}

final class StaffCredentialFixtureRepository implements StaffCredentialRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $records = [];
    public bool $managerAllowed = true;
    private int $nextId = 100;

    public function transactional(callable $work): mixed
    {
        $snapshot = [$this->records, $this->nextId];
        try {
            return $work();
        } catch (Throwable $exception) {
            [$this->records, $this->nextId] = $snapshot;
            throw $exception;
        }
    }

    public function actorCanManageCredentials(int $actorUserId): bool
    {
        return $this->managerAllowed && $actorUserId === 9001;
    }

    public function isStaffUser(int $staffUserId): bool
    {
        return in_array($staffUserId, [101, 102, 103], true);
    }

    public function createCredential(array $credential): array
    {
        foreach ($this->records as $existing) {
            if (($existing['idempotency_key'] ?? null) !== $credential['idempotency_key']) {
                continue;
            }
            if (!hash_equals((string) $existing['payload_hash'], (string) $credential['payload_hash'])) {
                throw new DomainException('Credential idempotency key conflicts with a different payload.');
            }

            return ['record' => $existing, 'replayed' => true];
        }

        $previous = null;
        foreach ($this->records as $existing) {
            if ((int) $existing['staff_user_id'] === (int) $credential['staff_user_id']
                && $existing['credential_kind'] === $credential['credential_kind']
                && $existing['credential_key'] === $credential['credential_key']
                && ($previous === null || (int) $existing['version'] > (int) $previous['version'])
            ) {
                $previous = $existing;
            }
        }
        if ($previous !== null && hash_equals((string) $previous['payload_hash'], (string) $credential['payload_hash'])) {
            return ['record' => $previous, 'replayed' => true];
        }

        $id = ++$this->nextId;
        $record = array_merge($credential, [
            'id' => $id,
            'lifecycle_status' => 'active',
            'supersedes_id' => $previous === null ? null : (int) $previous['id'],
            'version' => $previous === null ? 1 : ((int) $previous['version'] + 1),
        ]);
        $this->records[$id] = $record;

        return ['record' => $record, 'replayed' => false];
    }

    /** @param array<string,mixed> $record */
    public function seed(array $record): int
    {
        $id = isset($record['id']) ? (int) $record['id'] : ++$this->nextId;
        $this->nextId = max($this->nextId, $id);
        $this->records[$id] = array_merge([
            'id' => $id,
            'credential_kind' => 'document',
            'verification_status' => 'verified',
            'lifecycle_status' => 'active',
            'version' => 1,
            'supersedes_id' => null,
            'expires_on' => null,
        ], $record, ['id' => $id]);

        return $id;
    }

    public function expiringCredentials(DateTimeImmutable $asOf, DateTimeImmutable $through, int $limit): array
    {
        $supersededIds = array_flip(array_map(
            static fn (array $record): int => (int) $record['supersedes_id'],
            array_filter($this->records, static fn (array $record): bool => $record['supersedes_id'] !== null)
        ));
        $eligible = array_filter($this->records, static function (array $record) use ($through, $supersededIds): bool {
            return ($record['lifecycle_status'] ?? null) === 'active'
                && in_array($record['verification_status'] ?? null, ['unverified', 'verified'], true)
                && ($record['expires_on'] ?? null) !== null
                && (string) $record['expires_on'] <= $through->format('Y-m-d')
                && !isset($supersededIds[(int) $record['id']]);
        });
        usort($eligible, static fn (array $left, array $right): int => [(string) $left['expires_on'], (int) $left['id']]
            <=> [(string) $right['expires_on'], (int) $right['id']]);

        return array_map(
            static fn (array $record): array => [
                'id' => (int) $record['id'],
                'staff_user_id' => (int) $record['staff_user_id'],
                'credential_kind' => (string) $record['credential_kind'],
                'expires_on' => (string) $record['expires_on'],
                'verification_status' => (string) $record['verification_status'],
                'version' => (int) $record['version'],
            ],
            array_slice($eligible, 0, $limit)
        );
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $work, string $expectedClass, string $message) use ($assert): void {
    try {
        $work();
        $assert(false, $message . ' (no exception)');
    } catch (Throwable $exception) {
        $assert($exception instanceof $expectedClass, $message . ' (' . $exception::class . ')');
    }
};

try {
    $repository = new StaffCredentialFixtureRepository();
    $audit = new StaffCredentialFixtureAudit();
    $notifications = new StaffCredentialFixtureNotifications();
    $service = new StaffDocumentExpiryService($repository, $notifications, $audit);
    $input = static fn (string $idempotencyKey, string $expiresOn = '2026-08-14'): array => [
        'staff_user_id' => 101,
        'credential_kind' => 'document',
        'credential_key' => 'teaching-license',
        'title' => 'رخصة تدريس',
        'issuer' => 'جهة الاعتماد',
        'effective_on' => '2026-01-01',
        'issued_on' => '2026-01-01',
        'expires_on' => $expiresOn,
        'attachment_id' => 77,
        'verification_status' => 'verified',
        'idempotency_key' => $idempotencyKey,
    ];

    $receipt = $service->registerCredential(9001, $input(str_repeat('a', 64)));
    $credentialId = (int) $receipt['credential_id'];
    $assert(
        $credentialId > 0 && ($receipt['replayed'] ?? true) === false
        && !array_key_exists('title', $receipt)
        && !array_key_exists('issuer', $receipt)
        && !array_key_exists('attachment_id', $receipt),
        'a credential write returns a safe receipt without private evidence metadata'
    );
    $auditDetails = $audit->events[0]['details'] ?? [];
    $assert(
        count($audit->events) === 1
        && ($auditDetails['credential_kind'] ?? null) === 'document'
        && !array_key_exists('title', $auditDetails)
        && !array_key_exists('attachment_id', $auditDetails),
        'a new credential is audit-recorded in the same command without evidence details'
    );

    $replay = $service->registerCredential(9001, $input(str_repeat('a', 64)));
    $assert(
        ($replay['credential_id'] ?? null) === $credentialId
        && ($replay['replayed'] ?? false) === true
        && count($repository->records) === 1
        && count($audit->events) === 1,
        'same idempotency evidence is replay-safe and does not duplicate the audit record'
    );
    $conflicting = $input(str_repeat('a', 64), '2026-08-15');
    $assertThrows(
        static fn () => $service->registerCredential(9001, $conflicting),
        DomainException::class,
        'an idempotency key cannot be reused for different evidence'
    );
    $invalidDates = $input(str_repeat('b', 64), '2025-12-31');
    $assertThrows(
        static fn () => $service->registerCredential(9001, $invalidDates),
        InvalidArgumentException::class,
        'expiry before the effective date is rejected before persistence'
    );
    $assertThrows(
        static fn () => $service->registerCredential(9002, $input(str_repeat('c', 64)),),
        DomainException::class,
        'an unprivileged actor cannot register another worker credential'
    );
    $audit->failNext = true;
    $assertThrows(
        static fn () => $service->registerCredential(9001, $input(str_repeat('d', 64), '2026-10-01')),
        RuntimeException::class,
        'audit failure fails closed'
    );
    $assert(count($repository->records) === 1, 'audit failure rolls the credential write back atomically');

    $expiredId = $repository->seed([
        'staff_user_id' => 102,
        'credential_kind' => 'training',
        'expires_on' => '2026-08-08',
    ]);
    $todayId = $repository->seed([
        'staff_user_id' => 103,
        'credential_kind' => 'qualification',
        'expires_on' => '2026-08-09',
    ]);
    $repository->seed([
        'staff_user_id' => 101,
        'credential_kind' => 'document',
        'verification_status' => 'rejected',
        'expires_on' => '2026-08-10',
    ]);
    $repository->seed([
        'staff_user_id' => 101,
        'credential_kind' => 'document',
        'lifecycle_status' => 'revoked',
        'expires_on' => '2026-08-11',
    ]);
    $alerts = $service->expiryAlerts(new DateTimeImmutable('2026-08-09 10:00:00+03:00'), 7);
    $alertsById = array_column($alerts, null, 'credential_id');
    $assert(
        array_keys($alertsById) === [$expiredId, $todayId, $credentialId]
        && ($alertsById[$expiredId]['expiry_state'] ?? null) === 'expired'
        && ($alertsById[$todayId]['expiry_state'] ?? null) === 'expires_today'
        && ($alertsById[$credentialId]['expiry_state'] ?? null) === 'expires_soon',
        'expiry alerts include expired, today, and upcoming active verified evidence only'
    );
    $assert(
        !array_key_exists('title', $alertsById[$credentialId] ?? [])
        && !array_key_exists('attachment_id', $alertsById[$credentialId] ?? []),
        'expiry projection does not disclose credential evidence or attachment metadata'
    );

    $notifications->rejectCredentialIds = [$todayId];
    $notifications->throwCredentialIds = [$credentialId];
    $notificationResult = $service->notifyExpiryAlerts(new DateTimeImmutable('2026-08-09 10:00:00+03:00'), 7);
    $firstNotification = $notifications->calls[0] ?? [];
    $assert(
        ($notificationResult['notified_credential_ids'] ?? []) === [$expiredId]
        && ($notificationResult['failed_credential_ids'] ?? []) === [$todayId, $credentialId],
        'one failed notification cannot prevent independent credential expiry alerts'
    );
    $assert(
        ($firstNotification['secureRoute'] ?? null) === 'admin/hr_center.php?tab=credentials&credential_id=' . $expiredId
        && ($firstNotification['neutralText'] ?? null) === 'لديك مؤهل أو تدريب أو وثيقة تحتاج إلى مراجعة.'
        && !array_key_exists('title', $firstNotification['metadata'] ?? [])
        && !array_key_exists('attachment_id', $firstNotification['metadata'] ?? []),
        'expiry notifications use an authorized relative route and neutral metadata only'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR credential expiry service: PASS\n";
